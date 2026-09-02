<?php
/** Ejecuta un único comando remoto cada vez y permite reanudar esperas. */

defined( 'ABSPATH' ) || exit;

final class Premiero_Command_Runner {
	const CRON_RESUME = 'premiero_console_command_resume';
	const OPT_ACTIVE  = 'premiero_console_active_command';
	const OPT_LOCK    = 'premiero_console_command_lock';
	const OPT_EXCLUSIONS = 'premiero_console_update_exclusions';

	public static function init() {
		add_action( self::CRON_RESUME, array( __CLASS__, 'resume' ) );
	}

	public static function has_active() {
		$state = get_option( self::OPT_ACTIVE, array() );
		return is_array( $state ) && ! empty( $state['command']['id'] );
	}

	public static function accept( $command ) {
		if ( self::has_active() ) {
			return new WP_Error( 'premiero_command_busy', 'Ya hay un comando en curso.' );
		}
		$initial_result = array( 'stage' => 'accepted' );
		if ( ! empty( $command['payload']['plugin_file'] ) ) {
			$initial_result['plugin_file'] = $command['payload']['plugin_file'];
		}
		$state = array(
			'command'    => $command,
			'phase'      => 'accepted',
			'started_at' => time(),
			'result'     => $initial_result,
		);
		update_option( self::OPT_ACTIVE, $state, false );
		self::report( $state, 'running' );
		self::resume();
		return true;
	}

	public static function resume() {
		$state = get_option( self::OPT_ACTIVE, array() );
		if ( ! is_array( $state ) || empty( $state['command']['id'] ) ) {
			return;
		}
		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			self::advance( $state );
		} catch ( \Throwable $error ) {
			self::finish( $state, false, 'premiero_command_exception', 'El proceso encontró un error inesperado. Revisa el registro local del servidor.' );
		} finally {
			delete_option( self::OPT_LOCK );
		}
	}

	private static function advance( $state ) {
		if ( 'report_pending' === $state['phase'] ) {
			self::deliver_final_report( $state );
			return;
		}

		$action  = $state['command']['action'];
		$payload = $state['command']['payload'];
		if ( 'set_exclusion' === $action ) {
			self::apply_exclusion( $state, $payload );
			return;
		}
		if ( 'check_backup_status' === $action ) {
			$status = Premiero_Updraft_Free_Adapter::status();
			$status['backup_status'] = $status['status'];
			self::finish_with_result( $state, 'unknown' !== $status['status'], $status );
			return;
		}
		if ( 'run_backup' === $action ) {
			self::advance_backup_only( $state );
			return;
		}
		if ( 'update_plugin' === $action ) {
			self::advance_update( $state );
			return;
		}
		self::finish( $state, false, 'premiero_command_action_denied', 'La acción no está permitida.' );
	}

	private static function advance_update( $state ) {
		$plugin_file = $state['command']['payload']['plugin_file'];
		if ( 'accepted' === $state['phase'] ) {
			$exclusions = get_option( self::OPT_EXCLUSIONS, array() );
			if ( is_array( $exclusions ) && ! empty( $exclusions[ $plugin_file ] ) ) {
				self::finish( $state, false, 'premiero_plugin_excluded', 'El plugin está marcado como no actualizable.' );
				return;
			}
			$preflight = Premiero_Plugin_Updater::preflight( $plugin_file );
			if ( is_wp_error( $preflight ) ) {
				self::finish_error( $state, $preflight );
				return;
			}
			$state['preflight'] = $preflight;
			$state['result'] = array_merge(
				$state['result'],
				array(
					'previous_version' => $preflight['previous_version'],
					'target_version'   => $preflight['target_version'],
					'was_active'       => $preflight['was_active'],
				)
			);
			$recent = Premiero_Updraft_Free_Adapter::recent_success( Premiero_Command_Client::backup_max_age_hours( $state['command'] ) );
			if ( ! is_wp_error( $recent ) ) {
				$state['backup'] = $recent;
				self::perform_update( $state );
				return;
			}
			self::start_backup_wait( $state );
			return;
		}
		if ( 'waiting_backup' === $state['phase'] ) {
			$backup = self::new_backup_status( $state );
			if ( 'waiting' === $backup['status'] ) {
				self::schedule_resume();
				return;
			}
			if ( 'success' !== $backup['status'] ) {
				self::finish_with_result( $state, false, array_merge( $state['result'], $backup ) );
				return;
			}
			$state['backup'] = $backup;
			self::perform_update( $state );
			return;
		}
		if ( 'upgrading' === $state['phase'] ) {
			self::reconcile_interrupted_update( $state );
		}
	}

	private static function start_backup_wait( $state ) {
		$baseline = Premiero_Updraft_Free_Adapter::status();
		$state['phase']             = 'waiting_backup';
		$state['backup_started_at'] = time();
		$state['backup_deadline']   = time() + Premiero_Command_Client::backup_timeout_seconds( $state['command'] );
		$state['baseline_backup_id']= isset( $baseline['backup_id'] ) ? $baseline['backup_id'] : '';
		$state['result']['stage']   = 'backup_running';
		update_option( self::OPT_ACTIVE, $state, false );
		self::report( $state, 'running' );
		self::schedule_resume();
		$started = Premiero_Updraft_Free_Adapter::start();
		if ( is_wp_error( $started ) ) {
			self::finish_error( $state, $started );
			return;
		}
		self::resume_after_backup_return( $state );
	}

	private static function advance_backup_only( $state ) {
		if ( 'accepted' === $state['phase'] ) {
			self::start_backup_wait( $state );
			return;
		}
		$backup = self::new_backup_status( $state );
		if ( 'waiting' === $backup['status'] ) {
			self::schedule_resume();
			return;
		}
		self::finish_with_result( $state, 'success' === $backup['status'], $backup );
	}

	private static function new_backup_status( $state ) {
		if ( time() > (int) $state['backup_deadline'] ) {
			return array( 'status' => 'timeout', 'backup_status' => 'timeout', 'error_code' => 'premiero_backup_timeout', 'message' => 'No se pudo confirmar la copia dentro del tiempo máximo.' );
		}
		$status = Premiero_Updraft_Free_Adapter::status();
		$is_new = ! empty( $status['backup_id'] )
			&& ! hash_equals( (string) $state['baseline_backup_id'], (string) $status['backup_id'] )
			&& ! empty( $status['backup_time'] )
			&& (int) $status['backup_time'] >= (int) $state['backup_started_at'] - 5;
		if ( ! $is_new ) {
			return array( 'status' => 'waiting', 'backup_status' => 'running', 'stage' => 'backup_running' );
		}
		$status['backup_status'] = $status['status'];
		return $status;
	}

	private static function perform_update( $state ) {
		$state['phase'] = 'upgrading';
		$state['result'] = array_merge(
			$state['result'],
			array(
				'stage'            => 'updating',
				'plugin_file'      => $state['preflight']['plugin_file'],
				'previous_version' => $state['preflight']['previous_version'],
				'target_version'   => $state['preflight']['target_version'],
				'was_active'       => $state['preflight']['was_active'],
				'backup_id'        => $state['backup']['backup_id'],
				'backup_at'        => $state['backup']['backup_at'],
				'backup_status'    => 'success',
			)
		);
		update_option( self::OPT_ACTIVE, $state, false );
		self::report( $state, 'running' );

		$updated = Premiero_Plugin_Updater::upgrade( $state['preflight'] );
		if ( is_wp_error( $updated ) ) {
			self::finish_error( $state, $updated );
			return;
		}
		self::finish_with_result( $state, true, array_merge( $state['result'], $updated, array( 'stage' => 'completed' ) ) );
	}

	private static function reconcile_interrupted_update( $state ) {
		$check = Premiero_Plugin_Updater::preflight( $state['preflight']['plugin_file'] );
		if ( is_wp_error( $check ) && 'premiero_plugin_update_missing' === $check->get_error_code() ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$plugins = get_plugins();
			$file    = $state['preflight']['plugin_file'];
			if ( isset( $plugins[ $file ] ) && version_compare( (string) $plugins[ $file ]['Version'], $state['preflight']['target_version'], '>=' ) ) {
				$active = is_plugin_active( $file );
				if ( empty( $state['preflight']['was_active'] ) || $active ) {
					self::finish_with_result( $state, true, array_merge( $state['result'], array( 'stage' => 'completed', 'installed_version' => (string) $plugins[ $file ]['Version'], 'is_active' => $active ) ) );
					return;
				}
			}
		}
		self::finish( $state, false, 'premiero_update_interrupted', 'La actualización se interrumpió y no pudo confirmarse automáticamente.' );
	}

	private static function apply_exclusion( $state, $payload ) {
		$items = get_option( self::OPT_EXCLUSIONS, array() );
		$items = is_array( $items ) ? $items : array();
		if ( ! empty( $payload['excluded'] ) ) {
			$items[ $payload['plugin_file'] ] = array( 'reason' => (string) $payload['reason'], 'updated_at' => time() );
		} else {
			unset( $items[ $payload['plugin_file'] ] );
		}
		update_option( self::OPT_EXCLUSIONS, $items, false );
		self::finish_with_result( $state, true, array( 'stage' => 'completed', 'plugin_file' => $payload['plugin_file'], 'message' => ! empty( $payload['excluded'] ) ? 'Exclusión aplicada.' : 'Exclusión revocada.' ) );
	}

	private static function finish_error( $state, WP_Error $error ) {
		$code = sanitize_key( (string) $error->get_error_code() );
		self::finish( $state, false, $code, 'WordPress devolvió el error ' . $code . '. Revisa el registro local si necesitas más detalle.' );
	}

	private static function finish( $state, $success, $code, $message ) {
		$result = array_merge( $state['result'], array( 'stage' => $success ? 'completed' : 'failed', 'error_code' => $success ? '' : $code, 'message' => $message ) );
		self::finish_with_result( $state, $success, $result );
	}

	private static function finish_with_result( $state, $success, $result ) {
		$state['phase']  = 'report_pending';
		$state['status'] = $success ? 'success' : 'failed';
		$state['result'] = array_merge( is_array( $result ) ? $result : array(), array( 'duration_seconds' => max( 0, time() - (int) $state['started_at'] ) ) );
		update_option( self::OPT_ACTIVE, $state, false );
		self::deliver_final_report( $state );
	}

	private static function deliver_final_report( $state ) {
		$sent = self::report( $state, $state['status'] );
		if ( is_wp_error( $sent ) ) {
			self::schedule_resume( 120 );
			return;
		}
		Premiero_Command_Client::remember_processed( $state['command']['id'] );
		delete_option( self::OPT_ACTIVE );
		wp_clear_scheduled_hook( self::CRON_RESUME );
		Premiero_Command_Client::schedule_poll( 5 );
		$refresh_now = 'success' === $state['status']
			&& in_array( $state['command']['action'], array( 'update_plugin', 'run_backup' ), true );
		if ( ! $refresh_now || is_wp_error( Premiero_Console_Client::send_snapshot( true ) ) ) {
			Premiero_Console_Client::mark_dirty();
		}
	}

	private static function report( $state, $status ) {
		return Premiero_Console_Client::signed_command_request(
			Premiero_Console_Client::COMMAND_REPORT_CANONICAL,
			array( 'command_id' => $state['command']['id'], 'status' => $status, 'result' => $state['result'] )
		);
	}

	private static function schedule_resume( $delay = 60 ) {
		if ( ! wp_next_scheduled( self::CRON_RESUME ) ) {
			wp_schedule_single_event( time() + max( 5, absint( $delay ) ), self::CRON_RESUME );
		}
	}

	/** Recupera el lock si el proceso PHP anterior murió hace más de 15 minutos. */
	private static function acquire_lock() {
		$existing = (int) get_option( self::OPT_LOCK, 0 );
		if ( $existing && $existing > time() - 15 * MINUTE_IN_SECONDS ) {
			return false;
		}
		if ( $existing ) {
			delete_option( self::OPT_LOCK );
		}
		return add_option( self::OPT_LOCK, time(), '', false );
	}

	private static function resume_after_backup_return( $state ) {
		$current = get_option( self::OPT_ACTIVE, array() );
		if (
			! is_array( $current )
			|| empty( $current['command']['id'] )
			|| empty( $state['command']['id'] )
			|| ! hash_equals( (string) $state['command']['id'], (string) $current['command']['id'] )
		) {
			return;
		}

		$backup = self::new_backup_status( $current );
		if ( 'waiting' === $backup['status'] ) {
			self::schedule_resume( 5 );
			return;
		}

		if ( 'run_backup' === $current['command']['action'] ) {
			self::finish_with_result( $current, 'success' === $backup['status'], $backup );
			return;
		}

		if ( 'update_plugin' !== $current['command']['action'] || 'success' !== $backup['status'] ) {
			self::finish_with_result( $current, false, array_merge( $current['result'], $backup ) );
			return;
		}

		$current['backup'] = $backup;
		self::perform_update( $current );
	}
}
