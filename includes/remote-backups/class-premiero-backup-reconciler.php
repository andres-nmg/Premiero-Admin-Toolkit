<?php
/**
 * Mantiene el destino SFTP alineado con la retencion de UpdraftPlus.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Backup_Reconciler {

	const DELETION_GRACE = 30 * MINUTE_IN_SECONDS;
	const OPT_LAST_REPORT = 'premiero_remote_backups_last_reconcile';

	/**
	 * Repara archivos remotos ausentes y, si se autoriza, replica la retencion.
	 *
	 * @return array Resumen de acciones.
	 */
	public static function run() {
		$summary = array(
			'requeued'          => 0,
			'completed'         => 0,
			'pruned'            => 0,
			'review'            => 0,
			'waiting_retention' => 0,
			'unmanaged_files'   => 0,
			'unmanaged_sets'    => 0,
			'inventory_checked' => false,
			'checked_at'        => time(),
		);
		if ( ! Premiero_Remote_Backup_Settings::is_enabled() ) {
			return $summary;
		}

		Premiero_Backup_Sync_Queue::maybe_install();
		$retained = Premiero_Backup_Detector::retained_backup_ids();
		if ( is_wp_error( $retained ) ) {
			return $summary;
		}
		$config = Premiero_Remote_Backup_Settings::runtime_config();
		if ( is_wp_error( $config ) ) {
			return $summary;
		}

		$target_key     = Premiero_Remote_Backup_Settings::target_key( $config );
		$sync_deletions = Premiero_Remote_Backup_Settings::sync_deletions_enabled();
		$directory      = Premiero_Backup_Detector::backup_directory();
		$verifier       = new Premiero_Backup_Verifier();
		$client         = null;
		$worker_needed  = false;
		$absent_groups  = array();

		try {
			/*
			 * Si PHP termino despues de publicar el archivo pero antes de actualizar
			 * la fila, el definitivo ya es una prueba suficiente: existe y su tamano
			 * coincide. Esto evita dejar para siempre el estado "Subiendo".
			 */
			foreach ( Premiero_Backup_Sync_Queue::uploading_items() as $uploading ) {
				$opened = self::ensure_client( $client, $config );
				if ( is_wp_error( $opened ) ) {
					Premiero_Backup_Sync_Queue::set_reconcile_error( $uploading->id, $opened->get_error_message() );
					continue;
				}
				$remote      = self::remote_path( $config['remote_path'], $uploading->filename );
				$remote_size = $client->remote_size( $remote );
				if ( false !== $remote_size && (int) $remote_size === (int) $uploading->local_size ) {
					Premiero_Backup_Sync_Queue::mark_synced( $uploading->id, $remote, $remote_size, $target_key );
					++$summary['completed'];
					continue;
				}
				if ( ! Premiero_Backup_Worker::has_active_lock() ) {
					$local = trailingslashit( $directory ) . basename( (string) $uploading->filename );
					if ( is_file( $local ) && ! is_link( $local ) ) {
						Premiero_Backup_Sync_Queue::mark_pending( $uploading->id, 'La transferencia anterior se interrumpio; se reanudara automaticamente.' );
						++$summary['requeued'];
						$worker_needed = true;
					} else {
						Premiero_Backup_Sync_Queue::mark_missing( $uploading->id, 'La transferencia se interrumpio y el archivo local ya no existe.' );
						++$summary['review'];
					}
				}
			}

			$items     = Premiero_Backup_Sync_Queue::retention_items();
			$unfinished = Premiero_Backup_Sync_Queue::has_unfinished_items();
			$groups    = self::group_items( $items );

			foreach ( $groups as $backup_id => $group ) {
				$local = self::local_state( $group, $directory, $verifier );
				if ( ! $local['all_absent'] ) {
					Premiero_Backup_Sync_Queue::clear_group_missing( $backup_id );
					foreach ( $group as $item ) {
						if ( 'synced' !== (string) $item->status ) {
							continue;
						}
						if ( empty( $local['valid'][ (int) $item->id ] ) ) {
							continue;
						}
						if ( ! hash_equals( $target_key, (string) $item->remote_target ) ) {
							Premiero_Backup_Sync_Queue::mark_pending( $item->id, 'El destino SFTP ha cambiado; se comprobara de nuevo este archivo.' );
							++$summary['requeued'];
							$worker_needed = true;
							continue;
						}

						$opened = self::ensure_client( $client, $config );
						if ( is_wp_error( $opened ) ) {
							Premiero_Backup_Sync_Queue::set_reconcile_error( $item->id, $opened->get_error_message() );
							continue;
						}
						$remote = self::remote_path( $config['remote_path'], $item->filename );
						if ( ! $client->remote_exists( $remote ) ) {
							Premiero_Backup_Sync_Queue::mark_pending( $item->id, 'El archivo ya no existe en el servidor SFTP; se subira de nuevo.' );
							++$summary['requeued'];
							$worker_needed = true;
							continue;
						}

						$remote_size = $client->remote_size( $remote );
						if ( false !== $remote_size && (int) $remote_size === (int) $item->local_size ) {
							continue;
						}
						if ( ! $sync_deletions ) {
							Premiero_Backup_Sync_Queue::set_reconcile_error( $item->id, 'El archivo remoto tiene un tamano distinto. Activa la retencion remota para permitir su reparacion automatica.' );
							++$summary['review'];
							continue;
						}

						$deleted = $client->delete_managed_backup_file( $remote );
						if ( is_wp_error( $deleted ) ) {
							Premiero_Backup_Sync_Queue::set_reconcile_error( $item->id, $deleted->get_error_message() );
							++$summary['review'];
							continue;
						}
						Premiero_Backup_Sync_Queue::mark_pending( $item->id, 'Se reparara el archivo remoto cuyo tamano no coincidia.' );
						++$summary['requeued'];
						$worker_needed = true;
					}
					continue;
				}

				$absent_groups[ $backup_id ] = $group;
			}

			/*
			 * Los conjuntos ausentes se procesan al final. Asi cualquier archivo
			 * remoto perdido se vuelve a encolar antes de considerar un borrado.
			 */
			foreach ( $absent_groups as $backup_id => $group ) {
				if ( ! $sync_deletions ) {
					Premiero_Backup_Sync_Queue::clear_group_missing( $backup_id );
					continue;
				}
				if ( isset( $retained[ $backup_id ] ) ) {
					Premiero_Backup_Sync_Queue::clear_group_missing( $backup_id );
					continue;
				}

				/*
				 * Si los archivos ya no existen en el destino no hay ningun borrado que
				 * proteger con el margen de 30 minutos. Cerramos el historial de forma
				 * inmediata, pero solo tras validar destino, ruta y conjunto completos.
				 */
				$already_absent = self::prune_group_if_remote_absent( $group, $client, $config, $target_key );
				if ( false !== $already_absent ) {
					$summary['pruned'] += $already_absent;
					continue;
				}

				$missing_since = self::group_missing_since( $group );
				if ( 0 === $missing_since ) {
					Premiero_Backup_Sync_Queue::mark_group_missing( $backup_id, time() );
					Premiero_Remote_Backups::schedule_scan_soon( self::DELETION_GRACE + 5 );
					++$summary['waiting_retention'];
					continue;
				}
				if ( $unfinished || $worker_needed ) {
					++$summary['waiting_retention'];
					continue;
				}
				if ( time() - $missing_since < self::DELETION_GRACE ) {
					Premiero_Remote_Backups::schedule_scan_soon( self::DELETION_GRACE - ( time() - $missing_since ) + 5 );
					++$summary['waiting_retention'];
					continue;
				}

				$opened = self::ensure_client( $client, $config );
				if ( is_wp_error( $opened ) ) {
					foreach ( $group as $item ) {
						Premiero_Backup_Sync_Queue::set_reconcile_error( $item->id, $opened->get_error_message() );
					}
					continue;
				}

				$safe_files = array();
				$blocked    = false;
				foreach ( $group as $item ) {
					if ( ! hash_equals( $target_key, (string) $item->remote_target ) ) {
						Premiero_Backup_Sync_Queue::mark_orphaned( $item->id, 'No se elimino: el archivo pertenece a una configuracion SFTP anterior.' );
						++$summary['review'];
						$blocked = true;
						continue;
					}
					$remote = self::remote_path( $config['remote_path'], $item->filename );
					if ( (string) $item->remote_file !== $remote ) {
						Premiero_Backup_Sync_Queue::mark_orphaned( $item->id, 'No se elimino: la ruta registrada no coincide con la ruta SFTP actual.' );
						++$summary['review'];
						$blocked = true;
						continue;
					}
					if ( ! $client->remote_exists( $remote ) ) {
						$safe_files[] = array( 'item' => $item, 'remote' => $remote, 'exists' => false );
						continue;
					}
					$remote_size = $client->remote_size( $remote );
					if ( false === $remote_size || (int) $remote_size !== (int) $item->remote_size || (int) $remote_size !== (int) $item->local_size ) {
						Premiero_Backup_Sync_Queue::mark_orphaned( $item->id, 'No se elimino el archivo remoto porque su tamano cambio desde la sincronizacion.' );
						++$summary['review'];
						$blocked = true;
						continue;
					}
					$safe_files[] = array( 'item' => $item, 'remote' => $remote, 'exists' => true );
				}
				if ( $blocked ) {
					continue;
				}

				foreach ( $safe_files as $safe_file ) {
					$item = $safe_file['item'];
					if ( empty( $safe_file['exists'] ) ) {
							Premiero_Backup_Sync_Queue::mark_pruned( $item->id, 'El conjunto ya no existe en UpdraftPlus y el archivo tampoco estaba en el servidor SFTP.' );
						++$summary['pruned'];
						continue;
					}
					$deleted = $client->delete_managed_backup_file( $safe_file['remote'] );
					if ( is_wp_error( $deleted ) ) {
						Premiero_Backup_Sync_Queue::set_reconcile_error( $item->id, $deleted->get_error_message() );
						++$summary['review'];
						continue;
					}
					Premiero_Backup_Sync_Queue::mark_pruned( $item->id );
					++$summary['pruned'];
				}
			}

			$opened = self::ensure_client( $client, $config );
			if ( ! is_wp_error( $opened ) ) {
				$remote_files = $client->list_backup_files( $config['remote_path'] );
				if ( ! is_wp_error( $remote_files ) ) {
					$managed = Premiero_Backup_Sync_Queue::managed_filenames( $target_key );
					$unmanaged_sets = array();
					foreach ( $remote_files as $remote_filename ) {
						if ( isset( $managed[ $remote_filename ] ) ) {
							continue;
						}
						++$summary['unmanaged_files'];
						$unmanaged_sets[ Premiero_Backup_Detector::file_identity( $remote_filename ) ] = true;
					}
					$summary['unmanaged_sets']    = count( $unmanaged_sets );
					$summary['inventory_checked'] = true;
				}
			}
		} finally {
			if ( $client ) {
				$client->close();
			}
		}

		update_option( self::OPT_LAST_REPORT, $summary, false );

		if ( $worker_needed ) {
			Premiero_Remote_Backups::schedule_worker( 5 );
		}
		return $summary;
	}

	private static function group_items( $items ) {
		$groups = array();
		foreach ( $items as $item ) {
			$backup_id = trim( (string) $item->backup_id );
			if ( '' === $backup_id || ! Premiero_Backup_Detector::is_updraft_filename( $item->filename ) ) {
				Premiero_Backup_Sync_Queue::mark_orphaned( $item->id, 'El registro no tiene un identificador de conjunto seguro.' );
				continue;
			}
			if ( ! isset( $groups[ $backup_id ] ) ) {
				$groups[ $backup_id ] = array();
			}
			$groups[ $backup_id ][] = $item;
		}
		return $groups;
	}

	private static function local_state( $group, $directory, $verifier ) {
		$valid      = array();
		$all_absent = true;
		foreach ( $group as $item ) {
			$filename = basename( (string) $item->filename );
			$path     = trailingslashit( $directory ) . $filename;
			if ( is_file( $path ) && ! is_link( $path ) ) {
				$all_absent = false;
			}
			$valid[ (int) $item->id ] = true === $verifier->verify_local( $path, $item->local_size, $item->local_mtime );
		}
		return array( 'valid' => $valid, 'all_absent' => $all_absent );
	}

	private static function group_missing_since( $group ) {
		$since = array();
		foreach ( $group as $item ) {
			if ( empty( $item->local_missing_since ) ) {
				return 0;
			}
			$since[] = (int) $item->local_missing_since;
		}
		return empty( $since ) ? 0 : min( $since );
	}

	/**
	 * Cierra un conjunto retirado cuando ya no queda ningun archivo remoto.
	 * No elimina nada y devuelve false si no puede demostrarlo con seguridad.
	 *
	 * @return int|false Numero de registros cerrados.
	 */
	private static function prune_group_if_remote_absent( $group, &$client, $config, $target_key ) {
		$opened = self::ensure_client( $client, $config );
		if ( is_wp_error( $opened ) ) {
			return false;
		}

		foreach ( $group as $item ) {
			$remote = self::remote_path( $config['remote_path'], $item->filename );
			if (
				! hash_equals( (string) $target_key, (string) $item->remote_target )
				|| (string) $item->remote_file !== $remote
				|| $client->remote_exists( $remote )
			) {
				return false;
			}
		}

		foreach ( $group as $item ) {
			Premiero_Backup_Sync_Queue::mark_pruned( $item->id, 'La copia ya no existe en UpdraftPlus y el archivo remoto ya habia sido eliminado.' );
		}
		return count( $group );
	}

	private static function ensure_client( &$client, $config ) {
		if ( $client ) {
			return true;
		}
		$client = new Premiero_SFTP_Client( $config );
		$opened = $client->open();
		if ( is_wp_error( $opened ) ) {
			$client = null;
			return $opened;
		}
		return true;
	}

	private static function remote_path( $directory, $filename ) {
		return ( '/' === $directory ? '/' : rtrim( $directory, '/' ) . '/' ) . basename( (string) $filename );
	}
}
