<?php
/** Polling saliente y validación del catálogo de comandos. */

defined( 'ABSPATH' ) || exit;

final class Premiero_Command_Client {
	const CRON_POLL = 'premiero_console_command_poll';
	const SCHEDULE  = 'premiero_console_one_minute';
	const OPT_NONCES = 'premiero_console_command_nonces';
	const OPT_PROCESSED = 'premiero_console_processed_commands';
	const OPT_ADMIN_TICK = 'premiero_console_last_admin_tick';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_POLL, array( __CLASS__, 'poll' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_schedule' ), 35 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_resume_active' ), 40 );
	}

	public static function activate() {
		self::ensure_schedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_POLL );
		wp_clear_scheduled_hook( Premiero_Command_Runner::CRON_RESUME );
		delete_option( Premiero_Command_Runner::OPT_LOCK );
	}

	/** Elimina estado sensible/reanudable al romper expresamente el emparejamiento. */
	public static function reset_on_disconnect() {
		self::deactivate();
		foreach ( array( self::OPT_NONCES, Premiero_Console_Client::OPT_RESPONSE_NONCES ) as $index_option ) {
			$items = get_option( $index_option, array() );
			foreach ( is_array( $items ) ? array_keys( $items ) : array() as $option_name ) {
				if ( 0 === strpos( $option_name, 'premiero_console_' ) ) {
					delete_option( $option_name );
				}
			}
			delete_option( $index_option );
		}
		delete_option( self::OPT_PROCESSED );
		delete_option( Premiero_Command_Runner::OPT_ACTIVE );
		delete_option( Premiero_Command_Runner::OPT_LOCK );
		delete_option( Premiero_Command_Runner::OPT_EXCLUSIONS );
	}

	public static function cron_schedules( $schedules ) {
		$schedules[ self::SCHEDULE ] = array( 'interval' => MINUTE_IN_SECONDS, 'display' => 'Cada minuto (Premiero Commands)' );
		return $schedules;
	}

	public static function ensure_schedule() {
		$event = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( self::CRON_POLL ) : false;
		if ( $event && self::SCHEDULE !== $event->schedule ) {
			wp_clear_scheduled_hook( self::CRON_POLL );
			$event = false;
		}
		if ( ! $event && ! wp_next_scheduled( self::CRON_POLL ) ) {
			wp_schedule_event( time() + 10, self::SCHEDULE, self::CRON_POLL );
		}
	}

	public static function schedule_poll( $delay = 5 ) {
		wp_schedule_single_event( time() + max( 1, absint( $delay ) ), self::CRON_POLL );
	}

	/** Procesa la cola durante visitas administrativas si WP-Cron se retrasa. */
	public static function maybe_resume_active() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active = Premiero_Command_Runner::has_active();
		$last   = (int) get_option( self::OPT_ADMIN_TICK, 0 );
		if ( ! $active && $last > time() - 30 ) {
			return;
		}

		update_option( self::OPT_ADMIN_TICK, time(), false );
		self::tick();
	}

	/** Procesa el trabajo activo y, si termina, recoge el siguiente comando de la cola. */
	public static function tick() {
		if ( Premiero_Command_Runner::has_active() ) {
			Premiero_Command_Runner::resume();
		}
		if ( ! Premiero_Command_Runner::has_active() ) {
			self::poll();
		}
	}

	public static function poll() {
		if ( Premiero_Command_Runner::has_active() ) {
			Premiero_Command_Runner::resume();
			return;
		}
		$response = Premiero_Console_Client::signed_command_request( Premiero_Console_Client::COMMAND_POLL_CANONICAL, array() );
		if ( is_wp_error( $response ) || empty( $response['command'] ) || ! is_array( $response['command'] ) ) {
			return;
		}
		$command = self::validate_command( $response['command'] );
		if ( is_wp_error( $command ) ) {
			return;
		}
		if ( self::was_processed( $command['id'] ) ) {
			return;
		}
		$policy = isset( $response['policy'] ) && is_array( $response['policy'] ) ? $response['policy'] : array();
		$command['policy'] = array(
			'backup_max_age_hours' => max( 1, min( 168, absint( isset( $policy['backup_max_age_hours'] ) ? $policy['backup_max_age_hours'] : 168 ) ) ),
			'backup_timeout_minutes' => max( 5, min( 180, absint( isset( $policy['backup_timeout_minutes'] ) ? $policy['backup_timeout_minutes'] : 60 ) ) ),
		);
		Premiero_Command_Runner::accept( $command );
	}

	private static function validate_command( $command ) {
		$id      = isset( $command['id'] ) ? strtolower( sanitize_text_field( (string) $command['id'] ) ) : '';
		$action  = isset( $command['action'] ) ? sanitize_key( $command['action'] ) : '';
		$nonce   = isset( $command['nonce'] ) ? (string) $command['nonce'] : '';
		$expires = isset( $command['expires_at'] ) ? strtotime( (string) $command['expires_at'] ) : false;
		$issued  = isset( $command['issued_at'] ) ? strtotime( (string) $command['issued_at'] ) : false;
		$allowed = array( 'update_plugin', 'run_backup', 'check_backup_status', 'set_exclusion' );
		if ( ! wp_is_uuid( $id ) || ! in_array( $action, $allowed, true ) || ! preg_match( '/^[a-f0-9]{48}$/', $nonce ) || ! $expires || ! $issued || $expires < time() || $issued > time() + 300 ) {
			return new WP_Error( 'premiero_command_invalid', 'La consola devolvió un comando inválido o caducado.' );
		}
		$payload = isset( $command['payload'] ) && is_array( $command['payload'] ) ? $command['payload'] : array();
		if ( in_array( $action, array( 'update_plugin', 'set_exclusion' ), true ) ) {
			$file = Premiero_Plugin_Updater::validate_plugin_file( isset( $payload['plugin_file'] ) ? $payload['plugin_file'] : '' );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			$payload = array( 'plugin_file' => $file );
			if ( 'set_exclusion' === $action ) {
				$payload['excluded'] = ! empty( $command['payload']['excluded'] );
				$payload['reason']   = substr( sanitize_text_field( isset( $command['payload']['reason'] ) ? $command['payload']['reason'] : '' ), 0, 255 );
				if ( $payload['excluded'] && '' === $payload['reason'] ) {
					return new WP_Error( 'premiero_exclusion_reason_missing', 'La exclusión no tiene motivo.' );
				}
			}
		} else {
			$payload = array();
		}

		if ( ! self::remember_nonce( $nonce, $id ) ) {
			return new WP_Error( 'premiero_command_replay', 'El nonce del comando ya fue utilizado.' );
		}
		return array( 'id' => $id, 'action' => $action, 'payload' => $payload, 'nonce' => $nonce, 'expires_at' => $expires );
	}

	private static function remember_nonce( $nonce, $id ) {
		$items = get_option( self::OPT_NONCES, array() );
		$items = is_array( $items ) ? $items : array();
		$option_name = 'premiero_console_command_nonce_' . hash( 'sha256', $nonce );
		if ( ! add_option( $option_name, $id, '', false ) ) {
			return hash_equals( (string) get_option( $option_name, '' ), (string) $id );
		}
		$items[ $option_name ] = time();
		if ( count( $items ) > 2000 ) {
			asort( $items, SORT_NUMERIC );
			$remove = array_slice( $items, 0, count( $items ) - 2000, true );
			foreach ( array_keys( $remove ) as $old_option ) {
				delete_option( $old_option );
				unset( $items[ $old_option ] );
			}
		}
		update_option( self::OPT_NONCES, $items, false );
		return true;
	}

	private static function was_processed( $id ) {
		$items = get_option( self::OPT_PROCESSED, array() );
		return is_array( $items ) && isset( $items[ $id ] );
	}

	public static function remember_processed( $id ) {
		$items = get_option( self::OPT_PROCESSED, array() );
		$items = is_array( $items ) ? $items : array();
		$items[ $id ] = time();
		if ( count( $items ) > 2000 ) {
			asort( $items, SORT_NUMERIC );
			$items = array_slice( $items, -2000, null, true );
		}
		update_option( self::OPT_PROCESSED, $items, false );
	}

	public static function backup_max_age_hours( $command = array() ) {
		$value = isset( $command['policy']['backup_max_age_hours'] ) ? $command['policy']['backup_max_age_hours'] : 168;
		return max( 1, min( 168, absint( $value ) ) );
	}

	public static function backup_timeout_seconds( $command = array() ) {
		$value   = isset( $command['policy']['backup_timeout_minutes'] ) ? $command['policy']['backup_timeout_minutes'] : 60;
		$minutes = max( 5, min( 180, absint( $value ) ) );
		return $minutes * MINUTE_IN_SECONDS;
	}
}
