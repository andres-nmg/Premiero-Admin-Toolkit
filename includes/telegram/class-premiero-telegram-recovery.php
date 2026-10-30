<?php
/**
 * Avisos de errores fatales.
 *
 * Lo carga el mu-plugin para estar disponible aunque el fatal ocurra al cargar
 * otro plugin. Sin mu-plugin lo carga el Toolkit y funciona igual, pero solo
 * para los fatales posteriores a su carga.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-premiero-telegram.php';
require_once __DIR__ . '/class-premiero-telegram-sender.php';

final class Premiero_Telegram_Recovery {

	const FATAL_INTERVAL = HOUR_IN_SECONDS;

	private static $initialized = false;
	private static $frontend_handled = false;

	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		$settings = Premiero_Telegram::settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		if ( ! empty( $settings['event_fatal_recovery'] ) ) {
			add_filter( 'recovery_mode_email', array( __CLASS__, 'on_recovery_email' ), 10, 2 );
		}
		if ( ! empty( $settings['event_fatal_frontend'] ) ) {
			// wp_die() termina la peticion dentro del manejador de WordPress, asi que
			// el shutdown propio solo corre si WordPress no muestra su plantilla.
			add_filter( 'wp_php_error_message', array( __CLASS__, 'on_php_error_message' ), 10, 2 );
			register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
		}
	}

	/**
	 * @param array  $email Datos del email. Se devuelve sin modificar.
	 * @param string $url   Enlace de recuperacion.
	 * @return array
	 */
	public static function on_recovery_email( $email, $url ) {
		try {
			Premiero_Telegram_Sender::send(
				'ERROR FATAL: enlace de recuperación',
				array(
					'',
					(string) $url,
					'',
					wp_strip_all_tags( isset( $email['message'] ) ? (string) $email['message'] : '' ),
				)
			);
		} catch ( \Throwable $error ) {
			unset( $error );
		}
		return $email;
	}

	public static function on_php_error_message( $message, $error ) {
		self::notify_frontend( $error );
		return $message;
	}

	public static function on_shutdown() {
		$error = error_get_last();
		$types = array( E_ERROR, E_PARSE, E_USER_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR );
		if ( is_array( $error ) && in_array( $error['type'], $types, true ) ) {
			self::notify_frontend( $error );
		}
	}

	/**
	 * Avisa de un fatal fuera de wp-admin y del login, donde WordPress no envia
	 * el email de recuperacion. Cada error se avisa como maximo una vez por hora.
	 */
	private static function notify_frontend( $error ) {
		if ( self::$frontend_handled || ! is_array( $error ) ) {
			return;
		}
		self::$frontend_handled = true;

		if (
			( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'WP_SANDBOX_SCRAPING' ) && WP_SANDBOX_SCRAPING )
			|| wp_is_maintenance_mode()
			|| wp_installing()
			|| is_protected_endpoint()
		) {
			return;
		}

		try {
			$message = strtok( isset( $error['message'] ) ? (string) $error['message'] : '', "\n" );
			$file    = isset( $error['file'] ) ? str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( (string) $error['file'] ) ) : '';
			$line    = isset( $error['line'] ) ? (int) $error['line'] : 0;
			$key     = md5( $file . '|' . $line . '|' . $message );

			$sent = get_transient( Premiero_Telegram::TRANSIENT_FATALS );
			$sent = is_array( $sent ) ? $sent : array();
			$now  = time();
			foreach ( $sent as $sent_key => $sent_at ) {
				if ( (int) $sent_at <= $now - self::FATAL_INTERVAL ) {
					unset( $sent[ $sent_key ] );
				}
			}
			if ( isset( $sent[ $key ] ) ) {
				return;
			}
			$sent[ $key ] = $now;
			// Se guarda antes de enviar para que las visitas simultaneas no repitan el aviso.
			set_transient( Premiero_Telegram::TRANSIENT_FATALS, $sent, self::FATAL_INTERVAL );

			$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( home_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) ) : home_url();

			$is_cron = wp_doing_cron();

			Premiero_Telegram_Sender::send(
				$is_cron ? 'ERROR FATAL en una tarea programada (WP-Cron)' : 'ERROR FATAL en la web (fuera del admin)',
				array(
					'Error: ' . mb_substr( (string) $message, 0, 500, 'UTF-8' ),
					'Archivo: ' . $file . ':' . $line,
					'URL: ' . mb_substr( $uri, 0, 300, 'UTF-8' ),
					$is_cron
						? 'Ha fallado una tarea en segundo plano: las páginas de la web pueden seguir funcionando con normalidad.'
						: 'Sin enlace de recuperación: WordPress solo lo genera si el error se repite en wp-admin o en el login.',
				)
			);
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
	}
}
