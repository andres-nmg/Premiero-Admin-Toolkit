<?php
/** Adaptador versionado y fail-closed para UpdraftPlus Free. */

defined( 'ABSPATH' ) || exit;

final class Premiero_Updraft_Free_Adapter {

	const PLUGIN_FILE = 'updraftplus/updraftplus.php';

	/** Comprueba que está activo y dentro de la rama interna probada. */
	public static function availability() {
		if ( is_multisite() ) {
			return new WP_Error( 'premiero_multisite_unsupported', 'Las actualizaciones remotas no admiten Multisite.' );
		}
		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active( self::PLUGIN_FILE ) ) {
			return new WP_Error( 'premiero_updraft_inactive', 'UpdraftPlus Free no está activo.' );
		}
		$plugins = get_plugins();
		$version = isset( $plugins[ self::PLUGIN_FILE ]['Version'] ) ? (string) $plugins[ self::PLUGIN_FILE ]['Version'] : '';
		if ( '' === $version || version_compare( $version, '1.26.0', '<' ) || version_compare( $version, '1.27.0', '>=' ) ) {
			return new WP_Error( 'premiero_updraft_version_unverified', 'La versión instalada de UpdraftPlus no está validada por este adaptador.' );
		}
		return array( 'version' => $version );
	}

	/** Lee y normaliza updraft_last_backup sin aceptar estados ambiguos. */
	public static function status() {
		$available = self::availability();
		if ( is_wp_error( $available ) ) {
			return array( 'status' => 'unknown', 'error_code' => $available->get_error_code(), 'message' => $available->get_error_message() );
		}
		try {
			$last = class_exists( 'UpdraftPlus_Options' ) && method_exists( 'UpdraftPlus_Options', 'get_updraft_option' )
				? UpdraftPlus_Options::get_updraft_option( 'updraft_last_backup', false )
				: get_option( 'updraft_last_backup', false );
		} catch ( \Throwable $error ) {
			return array( 'status' => 'unknown', 'error_code' => 'premiero_updraft_read_failed', 'message' => 'No se pudo leer el estado de UpdraftPlus.' );
		}
		if ( ! is_array( $last ) || empty( $last['backup_time'] ) || empty( $last['backup_nonce'] ) ) {
			return array( 'status' => 'unknown', 'error_code' => 'premiero_backup_missing', 'message' => 'No existe una copia verificable.' );
		}

		$errors   = isset( $last['errors'] ) && is_array( $last['errors'] ) ? $last['errors'] : array();
		$warnings = 0;
		$fatal    = 0;
		foreach ( $errors as $error ) {
			if ( is_array( $error ) && isset( $error['level'] ) && 'warning' === $error['level'] ) {
				++$warnings;
			} else {
				++$fatal;
			}
		}
		$status = ! empty( $last['success'] ) && 0 === $fatal && 0 === $warnings ? 'success' : ( ! empty( $last['success'] ) ? 'partial' : 'failed' );
		return array(
			'status'      => $status,
			'backup_id'   => substr( sanitize_text_field( (string) $last['backup_nonce'] ), 0, 64 ),
			'backup_time' => (int) $last['backup_time'],
			'backup_at'   => gmdate( 'Y-m-d\TH:i:s\Z', (int) $last['backup_time'] ),
			'warnings'    => $warnings,
			'errors'      => $fatal,
		);
	}

	/** Devuelve la copia reciente o un WP_Error de cierre seguro. */
	public static function recent_success( $max_age_hours ) {
		$status = self::status();
		if ( 'success' !== $status['status'] ) {
			return new WP_Error( 'premiero_backup_not_successful', 'No hay una copia completa y sin errores confirmada.', $status );
		}
		$max_age = max( 1, min( 168, absint( $max_age_hours ) ) ) * HOUR_IN_SECONDS;
		if ( $status['backup_time'] < time() - $max_age || $status['backup_time'] > time() + 300 ) {
			return new WP_Error( 'premiero_backup_not_recent', 'La última copia correcta no es suficientemente reciente.', $status );
		}
		return $status;
	}

	/** Inicia una copia completa con almacenamiento remoto habilitado. */
	public static function start() {
		$available = self::availability();
		if ( is_wp_error( $available ) ) {
			return $available;
		}
		if ( ! has_action( 'updraft_backupnow_backup_all' ) ) {
			return new WP_Error( 'premiero_updraft_action_missing', 'UpdraftPlus no ha registrado la acción de copia.' );
		}
		if ( self::has_active_backup() ) {
			return new WP_Error( 'premiero_backup_already_running', 'UpdraftPlus ya tiene una copia en curso; no se iniciará otra simultáneamente.' );
		}
		do_action( 'updraft_backupnow_backup_all', array( 'nocloud' => 0 ) );
		return true;
	}

	/**
	 * Detecta trabajos reanudables de la rama soportada antes de iniciar otro.
	 * Esta lectura interna queda encapsulada por el control de versión.
	 */
	private static function has_active_backup() {
		if ( get_site_option( 'updraft_oneshotnonce', false ) ) {
			return true;
		}
		$cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		foreach ( is_array( $cron ) ? $cron : array() as $timestamp => $hooks ) {
			if ( isset( $hooks['updraft_backup_resume'] ) && (int) $timestamp >= time() - 12 * HOUR_IN_SECONDS ) {
				return true;
			}
		}
		return false;
	}
}
