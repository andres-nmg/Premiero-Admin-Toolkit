<?php
/**
 * Procesa de forma secuencial la cola SFTP.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Backup_Worker {

	const OPT_LOCK = 'premiero_remote_backups_worker_lock';
	const LOCK_TTL = 2 * HOUR_IN_SECONDS;

	public static function run() {
		if ( ! Premiero_Remote_Backup_Settings::is_enabled() || ! self::acquire_lock() ) {
			return;
		}

		$retry_delay = 0;
		$client      = null;
		try {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			}
			Premiero_Backup_Sync_Queue::maybe_install();
			$item = Premiero_Backup_Sync_Queue::claim_next();
			if ( ! $item ) {
				return;
			}

			$filename = basename( (string) $item->filename );
			if ( $filename !== (string) $item->filename ) {
				Premiero_Backup_Sync_Queue::mark_missing( $item->id, 'El nombre local guardado no es seguro.' );
				return;
			}
			$local = trailingslashit( Premiero_Backup_Detector::backup_directory() ) . $filename;
			$verifier = new Premiero_Backup_Verifier();
			$local_ok  = $verifier->verify_local( $local, $item->local_size, $item->local_mtime );
			if ( is_wp_error( $local_ok ) ) {
				Premiero_Backup_Sync_Queue::mark_missing( $item->id, $local_ok->get_error_message() );
				return;
			}

			$config = Premiero_Remote_Backup_Settings::runtime_config();
			if ( is_wp_error( $config ) ) {
				$retry_delay = Premiero_Backup_Sync_Queue::mark_retry( $item->id, $config->get_error_message() );
				return;
			}

			$remote_dir = (string) $config['remote_path'];
			$remote      = self::join_remote( $remote_dir, $filename );
			$partial     = $remote . '.part';
			$target_key  = Premiero_Remote_Backup_Settings::target_key( $config );
			$client      = new Premiero_SFTP_Client( $config );
			$opened      = $client->open();
			if ( is_wp_error( $opened ) ) {
				$retry_delay = Premiero_Backup_Sync_Queue::mark_retry( $item->id, $opened->get_error_message() );
				return;
			}
			if ( ! $client->ensure_directory( $remote_dir ) ) {
				$retry_delay = Premiero_Backup_Sync_Queue::mark_retry( $item->id, 'No se pudo acceder ni crear la ruta remota.' );
				return;
			}

			if ( $client->remote_exists( $remote ) ) {
				$remote_ok = $verifier->verify_remote( $client, $remote, $item->local_size );
				if ( is_wp_error( $remote_ok ) ) {
					$retry_delay = Premiero_Backup_Sync_Queue::mark_retry( $item->id, 'Ya existe un archivo remoto con el mismo nombre y un tamano diferente.' );
					return;
				}
				Premiero_Backup_Sync_Queue::mark_synced( $item->id, $remote, $item->local_size, $target_key );
				return;
			}

			$partial_size = $client->remote_size( $partial );
			if ( false === $partial_size || (int) $partial_size !== (int) $item->local_size ) {
				$resume = false !== $partial_size && (int) $partial_size > 0 && (int) $partial_size < (int) $item->local_size;
				if ( ! $client->upload_local_file( $local, $partial, $resume ) ) {
					$retry_delay = Premiero_Backup_Sync_Queue::mark_retry( $item->id, 'La transferencia SFTP no se completo.' );
					return;
				}
			}

			$remote_ok = $verifier->verify_remote( $client, $partial, $item->local_size );
			$local_ok  = $verifier->verify_local( $local, $item->local_size, $item->local_mtime );
			if ( is_wp_error( $remote_ok ) || is_wp_error( $local_ok ) ) {
				$message = is_wp_error( $remote_ok ) ? $remote_ok->get_error_message() : $local_ok->get_error_message();
				$retry_delay = Premiero_Backup_Sync_Queue::mark_retry( $item->id, $message );
				return;
			}

			if ( ! $client->rename_remote( $partial, $remote ) ) {
				if ( $client->remote_exists( $remote ) && ! is_wp_error( $verifier->verify_remote( $client, $remote, $item->local_size ) ) ) {
					$client->delete_partial( $partial );
					Premiero_Backup_Sync_Queue::mark_synced( $item->id, $remote, $item->local_size, $target_key );
					return;
				}
				$retry_delay = Premiero_Backup_Sync_Queue::mark_retry( $item->id, 'El archivo se subio, pero no se pudo publicar con su nombre definitivo.' );
				return;
			}

			$final_ok = $verifier->verify_remote( $client, $remote, $item->local_size );
			if ( is_wp_error( $final_ok ) ) {
				$retry_delay = Premiero_Backup_Sync_Queue::mark_retry( $item->id, $final_ok->get_error_message() );
				return;
			}
			Premiero_Backup_Sync_Queue::mark_synced( $item->id, $remote, $item->local_size, $target_key );
		} catch ( \Throwable $error ) {
			if ( isset( $item ) && $item ) {
				$retry_delay = Premiero_Backup_Sync_Queue::mark_retry( $item->id, sanitize_text_field( $error->getMessage() ) );
			}
		} finally {
			if ( $client ) {
				$client->close();
			}
			self::release_lock();
			if ( $retry_delay > 0 ) {
				Premiero_Remote_Backups::schedule_worker( $retry_delay );
			} elseif ( Premiero_Remote_Backup_Settings::is_enabled() && Premiero_Backup_Sync_Queue::has_ready_items() ) {
				Premiero_Remote_Backups::schedule_worker( 30 );
			} elseif ( Premiero_Remote_Backup_Settings::is_enabled() && ! Premiero_Backup_Sync_Queue::has_unfinished_items() ) {
				/*
				 * La exploracion que encontro la copia pudo ejecutarse mientras habia
				 * archivos en cola. Al terminar el ultimo, repetimos la reconciliacion
				 * para iniciar o completar la retencion sin esperar otros 15 minutos.
				 */
				try {
					Premiero_Backup_Reconciler::run();
				} catch ( \Throwable $error ) {
					Premiero_Remote_Backups::schedule_scan_soon( 5 * MINUTE_IN_SECONDS );
				}
			}
		}
	}

	private static function acquire_lock() {
		$existing = (int) get_option( self::OPT_LOCK, 0 );
		if ( $existing && $existing > time() - self::LOCK_TTL ) {
			return false;
		}
		if ( $existing ) {
			delete_option( self::OPT_LOCK );
		}
		return add_option( self::OPT_LOCK, time(), '', false );
	}

	public static function release_lock() {
		delete_option( self::OPT_LOCK );
	}

	public static function has_active_lock() {
		$existing = (int) get_option( self::OPT_LOCK, 0 );
		return $existing > time() - self::LOCK_TTL;
	}

	private static function join_remote( $directory, $filename ) {
		return ( '/' === $directory ? '/' : rtrim( $directory, '/' ) . '/' ) . ltrim( $filename, '/' );
	}
}
