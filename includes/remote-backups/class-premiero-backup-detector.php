<?php
/**
 * Detecta archivos terminados de UpdraftPlus sin modificar su historial.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Backup_Detector {

	const OPT_STABILITY = 'premiero_remote_backups_stability';
	const OPT_CANDIDATE = 'premiero_remote_backups_candidate';
	const OPT_CONFIRMED = 'premiero_remote_backups_confirmed_files';
	const STABLE_SECONDS = 60;

	public static function init() {
		add_filter( 'updraftplus_save_last_backup', array( __CLASS__, 'capture_last_backup' ), 500, 1 );
		add_filter( 'updraftplus_backup_complete', array( __CLASS__, 'backup_completed' ), 500, 1 );
	}

	/**
	 * Captura los nombres del conjunto sin alterar el valor que guarda UpdraftPlus.
	 *
	 * @param array $last_backup Datos de UpdraftPlus.
	 * @return array
	 */
	public static function capture_last_backup( $last_backup ) {
		if ( Premiero_Remote_Backup_Settings::is_enabled() && is_array( $last_backup ) ) {
			$backup_array = isset( $last_backup['backup_array'] ) && is_array( $last_backup['backup_array'] )
				? $last_backup['backup_array']
				: array();
			$files = self::extract_filenames( $backup_array );
			update_option(
				self::OPT_CANDIDATE,
				array(
					'backup_time' => isset( $last_backup['backup_time'] ) ? (int) $last_backup['backup_time'] : time(),
					'success'     => ! empty( $last_backup['success'] ),
					'files'       => array_values( $files ),
				),
				false
			);
			if ( ! empty( $last_backup['success'] ) ) {
				self::observe_files( $files );
			}
		}
		return $last_backup;
	}

	/**
	 * @param mixed $delete_jobdata Valor del filtro de UpdraftPlus.
	 * @return mixed
	 */
	public static function backup_completed( $delete_jobdata ) {
		if ( Premiero_Remote_Backup_Settings::is_enabled() ) {
			$candidate = get_option( self::OPT_CANDIDATE, array() );
			if ( is_array( $candidate ) && ! empty( $candidate['success'] ) && ! empty( $candidate['files'] ) ) {
				self::confirm_files( $candidate['files'] );
			}
			Premiero_Remote_Backups::schedule_scan_soon( self::STABLE_SECONDS );
		}
		return $delete_jobdata;
	}

	/**
	 * Escanea archivos que pertenecen al historial reconocido de UpdraftPlus.
	 *
	 * @param bool $force Permite escanear desde una accion administrativa.
	 * @return int Numero de archivos nuevos encolados.
	 */
	public static function scan( $force = false ) {
		if ( ! $force && ! Premiero_Remote_Backup_Settings::is_enabled() ) {
			return 0;
		}

		Premiero_Backup_Sync_Queue::maybe_install();
		$directory = self::backup_directory();
		if ( ! is_dir( $directory ) || ! is_readable( $directory ) ) {
			return 0;
		}

		$eligible = self::completed_filenames();
		$candidate = get_option( self::OPT_CANDIDATE, array() );
		if ( is_array( $candidate ) && ! empty( $candidate['success'] ) && ! empty( $candidate['files'] ) ) {
			foreach ( (array) $candidate['files'] as $filename ) {
				if ( self::is_updraft_filename( $filename ) ) {
					$eligible[ basename( $filename ) ] = basename( $filename );
				}
			}
		}

		$stability = get_option( self::OPT_STABILITY, array() );
		$stability = is_array( $stability ) ? $stability : array();
		$next      = array();
		$enqueued  = 0;
		$now       = time();

		foreach ( $eligible as $filename ) {
			$filename = basename( (string) $filename );
			$path     = trailingslashit( $directory ) . $filename;
			if ( ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$size  = filesize( $path );
			$mtime = filemtime( $path );
			if ( false === $size || false === $mtime || $size < 1 ) {
				continue;
			}

			$previous = isset( $stability[ $filename ] ) && is_array( $stability[ $filename ] )
				? $stability[ $filename ]
				: array();
			$same = isset( $previous['size'], $previous['mtime'], $previous['observed_at'] )
				&& (int) $previous['size'] === (int) $size
				&& (int) $previous['mtime'] === (int) $mtime;
			$observed_at = $same ? (int) $previous['observed_at'] : $now;
			$next[ $filename ] = array( 'size' => (int) $size, 'mtime' => (int) $mtime, 'observed_at' => $observed_at );

			if ( ! $same || $now - $observed_at < self::STABLE_SECONDS || $now - (int) $mtime < self::STABLE_SECONDS ) {
				continue;
			}

			$identity = self::file_identity( $filename );
			$file = array(
				'fingerprint' => hash( 'sha256', $filename . "\0" . (int) $size . "\0" . (int) $mtime ),
				'backup_id'   => $identity,
				'filename'    => $filename,
				'size'        => (int) $size,
				'mtime'       => (int) $mtime,
			);
			if ( Premiero_Backup_Sync_Queue::enqueue( $file ) ) {
				++$enqueued;
			}
		}

		if ( count( $next ) > 500 ) {
			$next = array_slice( $next, -500, null, true );
		}
		update_option( self::OPT_STABILITY, $next, false );
		return $enqueued;
	}

	public static function backup_directory() {
		global $updraftplus;
		if ( is_object( $updraftplus ) && method_exists( $updraftplus, 'backups_dir_location' ) ) {
			return wp_normalize_path( $updraftplus->backups_dir_location() );
		}

		$configured = '';
		if ( class_exists( 'UpdraftPlus_Options' ) && method_exists( 'UpdraftPlus_Options', 'get_updraft_option' ) ) {
			$configured = UpdraftPlus_Options::get_updraft_option( 'updraft_dir', '' );
		} else {
			$configured = get_option( 'updraft_dir', '' );
		}
		$configured = is_string( $configured ) ? trim( $configured ) : '';
		if ( '' === $configured ) {
			return wp_normalize_path( WP_CONTENT_DIR . '/updraft' );
		}
		if ( '/' === substr( $configured, 0, 1 ) || '\\' === substr( $configured, 0, 1 ) || preg_match( '/^[a-zA-Z]:/', $configured ) ) {
			return wp_normalize_path( $configured );
		}
		return wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . preg_replace( '#^wp-content/#', '', $configured ) );
	}

	/**
	 * @return array basename => basename
	 */
	private static function completed_filenames() {
		$confirmed = get_option( self::OPT_CONFIRMED, array() );
		$out       = array();
		foreach ( is_array( $confirmed ) ? $confirmed : array() as $filename => $confirmed_at ) {
			if ( self::is_updraft_filename( $filename ) ) {
				$out[ basename( $filename ) ] = basename( $filename );
			}
		}

		if ( class_exists( 'UpdraftPlus_Options' ) && method_exists( 'UpdraftPlus_Options', 'get_updraft_option' ) ) {
			$last_backup = UpdraftPlus_Options::get_updraft_option( 'updraft_last_backup', array() );
		} else {
			$last_backup = get_option( 'updraft_last_backup', array() );
		}
		if ( is_array( $last_backup ) && ! empty( $last_backup['success'] ) && ! empty( $last_backup['backup_array'] ) ) {
			$out = array_merge( $out, self::extract_filenames( $last_backup['backup_array'] ) );
		}
		return $out;
	}

	/**
	 * Identificadores de los conjuntos que UpdraftPlus conserva en su historial.
	 * El reconciliador usa este historial como fuente de verdad para la retencion.
	 *
	 * @return array|WP_Error
	 */
	public static function retained_backup_ids() {
		if ( class_exists( 'UpdraftPlus_Backup_History' ) && method_exists( 'UpdraftPlus_Backup_History', 'get_history' ) ) {
			$history = UpdraftPlus_Backup_History::get_history();
		} elseif ( class_exists( 'UpdraftPlus_Options' ) && method_exists( 'UpdraftPlus_Options', 'get_updraft_option' ) ) {
			$history = UpdraftPlus_Options::get_updraft_option( 'updraft_backup_history', array() );
		} else {
			return new WP_Error( 'premiero_updraft_history_unavailable', 'No se pudo consultar el historial de UpdraftPlus.' );
		}

		if ( ! is_array( $history ) ) {
			return new WP_Error( 'premiero_updraft_history_invalid', 'El historial de UpdraftPlus no tiene un formato valido.' );
		}

		$ids = array();
		foreach ( $history as $backup ) {
			foreach ( self::extract_filenames( $backup ) as $filename ) {
				$identity = self::file_identity( $filename );
				$ids[ $identity ] = true;
			}
		}
		return $ids;
	}

	private static function confirm_files( $files ) {
		$confirmed = get_option( self::OPT_CONFIRMED, array() );
		$confirmed = is_array( $confirmed ) ? $confirmed : array();
		foreach ( (array) $files as $filename ) {
			if ( self::is_updraft_filename( $filename ) ) {
				$confirmed[ basename( $filename ) ] = time();
			}
		}
		if ( count( $confirmed ) > 1000 ) {
			asort( $confirmed, SORT_NUMERIC );
			$confirmed = array_slice( $confirmed, -1000, null, true );
		}
		update_option( self::OPT_CONFIRMED, $confirmed, false );
	}

	private static function extract_filenames( $value ) {
		$out = array();
		self::walk_filenames( $value, $out );
		return $out;
	}

	private static function walk_filenames( $value, &$out ) {
		if ( is_string( $value ) && self::is_updraft_filename( $value ) ) {
			$name = basename( $value );
			$out[ $name ] = $name;
			return;
		}
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $item ) {
			self::walk_filenames( $item, $out );
		}
	}

	private static function observe_files( $files ) {
		$directory = self::backup_directory();
		$state     = get_option( self::OPT_STABILITY, array() );
		$state     = is_array( $state ) ? $state : array();
		foreach ( $files as $filename ) {
			$filename = basename( $filename );
			$path     = trailingslashit( $directory ) . $filename;
			if ( is_file( $path ) && ! is_link( $path ) ) {
				$size  = filesize( $path );
				$mtime = filemtime( $path );
				if ( false !== $size && false !== $mtime ) {
					$state[ $filename ] = array( 'size' => (int) $size, 'mtime' => (int) $mtime, 'observed_at' => time() );
				}
			}
		}
		update_option( self::OPT_STABILITY, $state, false );
	}

	public static function is_updraft_filename( $filename ) {
		$filename = basename( (string) $filename );
		return (bool) preg_match( '/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-[\-a-z]+([0-9]+)?\.(zip|gz|gz\.crypt)$/i', $filename );
	}

	public static function file_identity( $filename ) {
		if ( preg_match( '/^backup_([\-0-9]{15})_.*_([0-9a-f]{12})-/i', $filename, $matches ) ) {
			return $matches[1] . '_' . strtolower( $matches[2] );
		}
		return substr( hash( 'sha256', $filename ), 0, 32 );
	}
}
