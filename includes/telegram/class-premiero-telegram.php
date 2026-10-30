<?php
/**
 * Coordinador del modulo de avisos por Telegram: ajustes, credenciales y hooks.
 *
 * Este archivo tambien lo carga el mu-plugin antes que el resto de plugins, asi
 * que no puede depender de otras clases del Toolkit al definirse.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Telegram {

	const OPTION = 'premiero_telegram_settings';
	const OPT_DIGEST_HASH = 'premiero_telegram_digest_hash';
	const CRON_DIGEST = 'premiero_telegram_daily_digest';
	const CRON_FAILED_LOGINS = 'premiero_telegram_failed_logins_summary';
	const TRANSIENT_FAILED_LOGINS = 'premiero_telegram_failed_logins';
	const TRANSIENT_FATALS = 'premiero_telegram_fatals_sent';
	const TRANSIENT_NOTICES = 'premiero_telegram_notices';
	const TOKEN_PATTERN = '/^\d+:[A-Za-z0-9_-]{30,}$/';
	const CHAT_ID_PATTERN = '/^-?\d+$/';
	const MU_FILE = '00-premiero-telegram-recovery.php';

	/** Vacios a proposito: el repositorio es publico y cada instalacion guarda las suyas. */
	const DEFAULT_TOKEN = '';
	const DEFAULT_CHAT_ID = '';

	private static $initialized = false;
	private static $credentials = null;

	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		Premiero_Telegram_Recovery::init();
		Premiero_Telegram_Settings::init();
		// wp_salt() no existe hasta cargar pluggable.php, justo antes de plugins_loaded.
		add_action( 'plugins_loaded', array( __CLASS__, 'register_hooks' ), 1 );
		add_action( 'init', array( __CLASS__, 'sync_schedules' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_sync_mu_plugin' ), 35 );
	}

	/**
	 * Limpieza al desactivar el plugin. Conserva los ajustes y las credenciales.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_DIGEST );
		wp_clear_scheduled_hook( self::CRON_FAILED_LOGINS );
		delete_option( self::OPT_DIGEST_HASH );
		delete_transient( self::TRANSIENT_FAILED_LOGINS );
		delete_transient( self::TRANSIENT_FATALS );
		delete_transient( self::TRANSIENT_NOTICES );
		self::remove_mu_plugin();
	}

	public static function defaults() {
		return array(
			'enabled'                => 1,
			'token'                  => '',
			'chat_id'                => '',
			'event_cf7'              => 1,
			'event_elementor'        => 1,
			'event_updates'          => 1,
			'event_update_failures'  => 1,
			'event_digest'           => 1,
			'event_fatal_recovery'   => 1,
			'event_fatal_frontend'   => 1,
			'event_admin_login'      => 0,
			'event_failed_login'     => 0,
			'event_new_user'         => 1,
			'event_admin_role'       => 1,
			'event_password_reset'   => 1,
			'event_site_urls'        => 1,
			'event_plugins'          => 1,
			'event_theme'            => 1,
			'event_comment'          => 0,
			'email_auto_plugin'      => 0,
			'email_auto_theme'       => 0,
			'email_auto_core'        => 0,
			'email_core_available'   => 0,
			'email_new_user'         => 0,
			'email_password_reset'   => 0,
			'email_moderation'       => 0,
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Emails de WordPress que se pueden sustituir por un aviso de Telegram.
	 *
	 * Los filtros de actualizaciones automaticas suprimen tanto los exitos como
	 * los fallos, por eso dependen de los dos avisos.
	 */
	public static function email_rules() {
		$update_events = array( 'event_updates', 'event_update_failures' );
		return array(
			'email_auto_plugin'    => array(
				'label'  => 'Emails de actualizaciones automáticas de plugins',
				'filter' => 'auto_plugin_update_send_email',
				'events' => $update_events,
			),
			'email_auto_theme'     => array(
				'label'  => 'Emails de actualizaciones automáticas de temas',
				'filter' => 'auto_theme_update_send_email',
				'events' => $update_events,
			),
			'email_auto_core'      => array(
				'label'  => 'Emails de actualizaciones automáticas del core',
				'filter' => 'auto_core_update_send_email',
				'events' => $update_events,
			),
			'email_core_available' => array(
				'label'  => 'Email de «hay una actualización de WordPress disponible»',
				'filter' => 'send_core_update_notification_email',
				'events' => array( 'event_digest' ),
			),
			'email_new_user'       => array(
				'label'  => 'Email al admin cuando se registra un usuario nuevo',
				'filter' => 'wp_send_new_user_notification_to_admin',
				'events' => array( 'event_new_user' ),
				'min_wp' => '6.1',
			),
			'email_password_reset' => array(
				'label'  => 'Email al admin cuando un administrador restablece su contraseña',
				'filter' => '',
				'events' => array( 'event_password_reset' ),
				'note'   => 'Solo se omite si el usuario es administrador y Telegram ha confirmado la entrega. Si otro usuario restablece su contraseña, o si el envío falla, el email se envía.',
			),
			'email_moderation'     => array(
				'label'  => 'Emails de comentarios pendientes de moderación',
				'filter' => 'notify_moderator',
				'events' => array( 'event_comment' ),
			),
		);
	}

	public static function reset_credentials_cache() {
		self::$credentials = null;
	}

	/**
	 * Prioridad: constantes de wp-config.php > opcion guardada > constantes de clase.
	 *
	 * @return array{token:string,chat_id:string,token_from_config:bool,chat_from_config:bool,token_unreadable:bool}
	 */
	public static function credentials() {
		if ( null !== self::$credentials ) {
			return self::$credentials;
		}

		$settings          = self::settings();
		$token_from_config = defined( 'PRM_TG_TOKEN' ) && '' !== trim( (string) PRM_TG_TOKEN );
		$chat_from_config  = defined( 'PRM_TG_CHAT_ID' ) && '' !== trim( (string) PRM_TG_CHAT_ID );
		$token_unreadable  = false;

		if ( $token_from_config ) {
			$token = trim( (string) PRM_TG_TOKEN );
		} else {
			$token = '';
			if ( '' !== (string) $settings['token'] ) {
				$token            = self::decrypt_token( (string) $settings['token'] );
				$token_unreadable = '' === $token;
			}
			if ( '' === $token ) {
				$token = self::DEFAULT_TOKEN;
			}
		}

		if ( $chat_from_config ) {
			$chat_id = trim( (string) PRM_TG_CHAT_ID );
		} else {
			$chat_id = '' !== (string) $settings['chat_id'] ? (string) $settings['chat_id'] : self::DEFAULT_CHAT_ID;
		}

		self::$credentials = array(
			'token'             => $token,
			'chat_id'           => $chat_id,
			'token_from_config' => $token_from_config,
			'chat_from_config'  => $chat_from_config,
			'token_unreadable'  => $token_unreadable,
		);
		return self::$credentials;
	}

	public static function has_valid_credentials() {
		$credentials = self::credentials();
		return 1 === preg_match( self::TOKEN_PATTERN, $credentials['token'] )
			&& 1 === preg_match( self::CHAT_ID_PATTERN, $credentials['chat_id'] );
	}

	public static function is_active() {
		$settings = self::settings();
		return ! empty( $settings['enabled'] ) && self::has_valid_credentials();
	}

	public static function event_enabled( $key ) {
		$settings = self::settings();
		return self::is_active() && ! empty( $settings[ $key ] );
	}

	/**
	 * Un email solo se suprime si Telegram esta activo, hay credenciales validas
	 * y estan marcados todos los avisos de los que depende.
	 */
	public static function email_is_suppressed( $key ) {
		$rules    = self::email_rules();
		$settings = self::settings();
		if ( ! isset( $rules[ $key ] ) || empty( $settings[ $key ] ) || ! self::is_active() ) {
			return false;
		}
		if ( ! self::email_rule_is_supported( $rules[ $key ] ) ) {
			return false;
		}
		foreach ( $rules[ $key ]['events'] as $event ) {
			if ( empty( $settings[ $event ] ) ) {
				return false;
			}
		}
		return true;
	}

	public static function email_rule_is_supported( $rule ) {
		return empty( $rule['min_wp'] ) || version_compare( get_bloginfo( 'version' ), $rule['min_wp'], '>=' );
	}

	public static function register_hooks() {
		if ( ! self::is_active() ) {
			return;
		}
		Premiero_Telegram_Events::register( self::settings() );
		foreach ( self::email_rules() as $key => $rule ) {
			if ( '' !== $rule['filter'] && self::email_is_suppressed( $key ) ) {
				add_filter( $rule['filter'], '__return_false' );
			}
		}
	}

	public static function sync_schedules() {
		if ( self::event_enabled( 'event_digest' ) ) {
			if ( ! wp_next_scheduled( self::CRON_DIGEST ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_DIGEST );
			}
		} elseif ( wp_next_scheduled( self::CRON_DIGEST ) ) {
			wp_clear_scheduled_hook( self::CRON_DIGEST );
		}

		if ( ! self::event_enabled( 'event_failed_login' ) && wp_next_scheduled( self::CRON_FAILED_LOGINS ) ) {
			wp_clear_scheduled_hook( self::CRON_FAILED_LOGINS );
			delete_transient( self::TRANSIENT_FAILED_LOGINS );
		}
	}

	/* ---------- Cifrado del token ---------- */

	/**
	 * @param string $token Token en claro.
	 * @return string|WP_Error
	 */
	public static function encrypt_token( $token ) {
		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true ) ) {
			return new WP_Error( 'premiero_telegram_crypto_unavailable', 'OpenSSL con AES-256-GCM es necesario para guardar el token de forma segura.' );
		}
		try {
			$iv  = random_bytes( 12 );
			$tag = '';
			$ct  = openssl_encrypt( (string) $token, 'aes-256-gcm', self::master_key(), OPENSSL_RAW_DATA, $iv, $tag );
		} catch ( \Throwable $error ) {
			$ct = false;
		}
		if ( false === $ct || '' === $tag ) {
			return new WP_Error( 'premiero_telegram_encrypt_failed', 'No se pudo cifrar el token.' );
		}
		return base64_encode(
			wp_json_encode(
				array(
					'iv'  => base64_encode( $iv ),
					'tag' => base64_encode( $tag ),
					'ct'  => base64_encode( $ct ),
				)
			)
		);
	}

	/**
	 * @param string $stored Valor cifrado.
	 * @return string Token en claro, o vacio si no se puede descifrar.
	 */
	private static function decrypt_token( $stored ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$decoded  = base64_decode( $stored, true );
		$envelope = $decoded ? json_decode( $decoded, true ) : null;
		if ( ! is_array( $envelope ) || ! isset( $envelope['iv'], $envelope['tag'], $envelope['ct'] ) ) {
			return '';
		}
		$iv  = base64_decode( (string) $envelope['iv'], true );
		$tag = base64_decode( (string) $envelope['tag'], true );
		$ct  = base64_decode( (string) $envelope['ct'], true );
		if ( false === $iv || false === $tag || false === $ct ) {
			return '';
		}
		$token = openssl_decrypt( $ct, 'aes-256-gcm', self::master_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $token ? '' : $token;
	}

	private static function master_key() {
		// Un fatal puede llegar antes de que WordPress cargue pluggable.php.
		if ( ! function_exists( 'wp_salt' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}
		$material = wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|' . wp_salt( 'logged_in' );
		return hash( 'sha256', 'premiero-telegram|' . $material, true );
	}

	/* ---------- Mu-plugin de errores fatales ---------- */

	public static function mu_plugin_path() {
		return WP_CONTENT_DIR . '/mu-plugins/' . self::MU_FILE;
	}

	public static function mu_plugin_is_wanted() {
		return self::event_enabled( 'event_fatal_recovery' ) || self::event_enabled( 'event_fatal_frontend' );
	}

	public static function maybe_sync_mu_plugin() {
		if ( current_user_can( 'manage_options' ) ) {
			self::sync_mu_plugin();
		}
	}

	/**
	 * Crea, actualiza o elimina el mu-plugin segun los ajustes.
	 *
	 * @return bool False si hace falta y no se pudo escribir.
	 */
	public static function sync_mu_plugin() {
		if ( ! self::mu_plugin_is_wanted() ) {
			self::remove_mu_plugin();
			return true;
		}

		$file    = self::mu_plugin_path();
		$content = self::mu_plugin_contents();
		if ( file_exists( $file ) && hash_equals( md5( $content ), (string) md5_file( $file ) ) ) {
			return true;
		}
		if ( ! premiero_ensure_mu_dir() ) {
			return false;
		}

		$temp_file = $file . '.tmp-' . wp_generate_password( 8, false, false );
		if ( false === @file_put_contents( $temp_file, $content, LOCK_EX ) ) {
			return false;
		}
		if ( ! @rename( $temp_file, $file ) ) {
			wp_delete_file( $temp_file );
			return false;
		}
		return true;
	}

	private static function remove_mu_plugin() {
		$file = self::mu_plugin_path();
		if ( file_exists( $file ) ) {
			@unlink( $file );
		}
	}

	private static function mu_plugin_contents() {
		$brand       = str_replace( '*/', '', premiero_get_brand_name() );
		$plugin      = plugin_basename( PREMIERO_ATK_FILE );
		$export      = var_export( $plugin, true );
		$export_file = var_export( '/' . dirname( $plugin ) . '/includes/telegram/class-premiero-telegram-recovery.php', true );
		return <<<PHP
<?php
/**
 * Plugin Name: {$brand} Telegram: errores fatales (MU)
 * Description: Carga los avisos de errores fatales antes que el resto de plugins. Lo gestiona el Admin Toolkit.
 * Author: Premiero
 * Version: 1.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

\$premiero_telegram_plugin = {$export};
\$premiero_telegram_file   = WP_PLUGIN_DIR . {$export_file};
\$premiero_telegram_active = in_array( \$premiero_telegram_plugin, (array) get_option( 'active_plugins', array() ), true );
if ( ! \$premiero_telegram_active && is_multisite() ) {
	\$premiero_telegram_active = isset( get_site_option( 'active_sitewide_plugins', array() )[ \$premiero_telegram_plugin ] );
}
if ( \$premiero_telegram_active && file_exists( \$premiero_telegram_file ) ) {
	require_once \$premiero_telegram_file;
	Premiero_Telegram_Recovery::init();
}
unset( \$premiero_telegram_plugin, \$premiero_telegram_file, \$premiero_telegram_active );

PHP;
	}
}
