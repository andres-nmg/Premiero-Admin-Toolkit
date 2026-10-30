<?php
/**
 * Avisos de Telegram: solo se registran los hooks de los avisos marcados.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Telegram_Events {

	const FIELD_LENGTH = 500;
	const FAILED_LOGIN_WINDOW = HOUR_IN_SECONDS;
	const FAILED_LOGIN_MAX_KEYS = 100;

	/** Versiones previas a cada actualizacion, para no avisar de las que fallan. */
	private static $versions_before = array();

	public static function register( $settings ) {
		$hooks = array(
			'event_cf7'             => array( array( 'wpcf7_submit', 'on_cf7_submit', 10, 2 ) ),
			'event_elementor'       => array( array( 'elementor_pro/forms/new_record', 'on_elementor_record', 10, 1 ) ),
			'event_updates'         => array(
				array( 'upgrader_process_complete', 'on_upgrader_complete', 10, 2 ),
				// Con prioridad 1: en la pantalla de actualizaciones WordPress redirige y termina en este mismo hook.
				array( '_core_updated_successfully', 'on_core_updated', 1, 1 ),
			),
			'event_update_failures' => array( array( 'automatic_updates_complete', 'on_automatic_updates_complete', 10, 1 ) ),
			'event_digest'          => array( array( Premiero_Telegram::CRON_DIGEST, 'send_digest', 10, 0 ) ),
			'event_admin_login'     => array( array( 'wp_login', 'on_login', 10, 2 ) ),
			'event_failed_login'    => array(
				array( 'wp_login_failed', 'on_login_failed', 10, 1 ),
				array( Premiero_Telegram::CRON_FAILED_LOGINS, 'send_failed_logins', 10, 0 ),
			),
			'event_new_user'        => array( array( 'user_register', 'on_user_register', 10, 1 ) ),
			'event_admin_role'      => array( array( 'set_user_role', 'on_set_user_role', 10, 3 ) ),
			// Prioridad 5: el email de WordPress va en la 10 y depende del resultado de este envio.
			'event_password_reset'  => array( array( 'after_password_reset', 'on_password_reset', 5, 1 ) ),
			'event_site_urls'       => array(
				array( 'update_option_admin_email', 'on_site_option_changed', 10, 3 ),
				array( 'update_option_siteurl', 'on_site_option_changed', 10, 3 ),
				array( 'update_option_home', 'on_site_option_changed', 10, 3 ),
			),
			'event_plugins'         => array(
				array( 'activated_plugin', 'on_plugin_activated', 10, 1 ),
				array( 'deactivated_plugin', 'on_plugin_deactivated', 10, 1 ),
				array( 'deleted_plugin', 'on_plugin_deleted', 10, 2 ),
			),
			'event_theme'           => array( array( 'switch_theme', 'on_switch_theme', 10, 3 ) ),
			'event_comment'         => array( array( 'comment_post', 'on_comment_post', 10, 2 ) ),
		);

		foreach ( $hooks as $key => $entries ) {
			if ( empty( $settings[ $key ] ) ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				add_action( $entry[0], array( __CLASS__, $entry[1] ), $entry[2], $entry[3] );
			}
		}

		if ( ! empty( $settings['event_updates'] ) ) {
			add_filter( 'upgrader_package_options', array( __CLASS__, 'remember_version_before' ) );
		}
	}

	/* ---------- Formularios ---------- */

	public static function on_cf7_submit( $contact_form, $result ) {
		$status = is_array( $result ) && isset( $result['status'] ) ? $result['status'] : '';
		if ( ! in_array( $status, array( 'mail_sent', 'mail_failed' ), true ) || ! is_object( $contact_form ) ) {
			return;
		}

		$lines = array( 'Formulario: ' . $contact_form->title() );
		if ( 'mail_failed' === $status ) {
			$lines[] = 'EMAIL NO ENVIADO';
		}

		$submission = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
		if ( $submission ) {
			$lines = array_merge( $lines, self::format_fields( (array) $submission->get_posted_data() ) );
			$url   = (string) $submission->get_meta( 'url' );
			if ( '' !== $url ) {
				$lines[] = 'URL: ' . $url;
			}
		}

		Premiero_Telegram_Sender::send( 'Nuevo envío de formulario (Contact Form 7)', $lines );
	}

	public static function on_elementor_record( $record ) {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}

		$fields = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			$label = ! empty( $field['title'] ) ? $field['title'] : $id;
			if ( isset( $fields[ $label ] ) ) {
				$label .= ' (' . $id . ')';
			}
			$fields[ $label ] = isset( $field['value'] ) ? $field['value'] : '';
		}

		Premiero_Telegram_Sender::send(
			'Nuevo envío de formulario (Elementor)',
			array_merge(
				array( 'Formulario: ' . (string) $record->get_form_settings( 'form_name' ) ),
				self::format_fields( $fields )
			)
		);
	}

	/**
	 * @return string[] Lineas "campo: valor". Omite los campos que empiezan por "_".
	 */
	private static function format_fields( $fields ) {
		$lines = array();
		foreach ( $fields as $name => $value ) {
			$name = (string) $name;
			if ( '' === $name || '_' === $name[0] ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( array( __CLASS__, 'scalar_to_string' ), $value ) );
			}
			$lines[] = $name . ': ' . mb_substr( self::scalar_to_string( $value ), 0, self::FIELD_LENGTH, 'UTF-8' );
		}
		return $lines;
	}

	private static function scalar_to_string( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/* ---------- Actualizaciones ---------- */

	/**
	 * Guarda la version instalada antes de cada actualizacion. El hook de fin de
	 * actualizacion tambien se dispara si falla y, en lote, no da el resultado de
	 * cada elemento; comparar versiones cubre todos los casos.
	 */
	public static function remember_version_before( $options ) {
		$extra = isset( $options['hook_extra'] ) ? (array) $options['hook_extra'] : array();
		if ( ! empty( $extra['plugin'] ) ) {
			$header = self::plugin_header( $extra['plugin'] );
			self::$versions_before[ 'plugin:' . $extra['plugin'] ] = $header['version'];
		}
		if ( ! empty( $extra['theme'] ) ) {
			$header = self::theme_header( $extra['theme'] );
			self::$versions_before[ 'theme:' . $extra['theme'] ] = $header['version'];
		}
		return $options;
	}

	public static function on_upgrader_complete( $upgrader, $extra ) {
		$extra = (array) $extra;
		$type  = isset( $extra['type'] ) ? $extra['type'] : '';
		if ( ! isset( $extra['action'] ) || 'update' !== $extra['action'] || ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return;
		}

		$items = array();
		if ( ! empty( $extra[ $type . 's' ] ) ) {
			$items = (array) $extra[ $type . 's' ];
		} elseif ( ! empty( $extra[ $type ] ) ) {
			$items = array( $extra[ $type ] );
		}

		$lines = array();
		foreach ( $items as $item ) {
			$header = 'plugin' === $type ? self::plugin_header( $item ) : self::theme_header( $item );
			$before = isset( self::$versions_before[ $type . ':' . $item ] ) ? self::$versions_before[ $type . ':' . $item ] : '';
			if ( '' === $header['version'] || $before === $header['version'] ) {
				continue;
			}
			$lines[] = '- ' . $header['name'] . ': ' . ( '' !== $before ? $before . ' → ' : '' ) . $header['version'];
		}

		if ( $lines ) {
			Premiero_Telegram_Sender::send( 'plugin' === $type ? 'Plugins actualizados' : 'Temas actualizados', $lines );
		}
	}

	public static function on_core_updated( $version ) {
		Premiero_Telegram_Sender::send( 'WordPress actualizado', array( 'Nueva versión: ' . (string) $version ) );
	}

	public static function on_automatic_updates_complete( $results ) {
		$labels = array(
			'plugin' => 'Plugin',
			'theme'  => 'Tema',
			'core'   => 'WordPress',
		);

		$lines = array();
		foreach ( $labels as $type => $label ) {
			foreach ( isset( $results[ $type ] ) ? (array) $results[ $type ] : array() as $item ) {
				if ( ! is_object( $item ) || ! ( empty( $item->result ) || is_wp_error( $item->result ) ) ) {
					continue;
				}
				$name  = ! empty( $item->name ) ? $item->name : $label;
				$error = is_wp_error( $item->result ) ? $item->result->get_error_message() : 'La actualización no devolvió ningún resultado.';
				$lines[] = '- ' . $label . ' ' . wp_strip_all_tags( $name ) . ': ' . wp_strip_all_tags( $error );
			}
		}

		if ( $lines ) {
			Premiero_Telegram_Sender::send( 'Fallos en actualizaciones automáticas', $lines );
		}
	}

	public static function send_digest() {
		$lines = self::pending_update_lines();
		if ( null === $lines ) {
			return;
		}

		sort( $lines );
		$hash = hash( 'sha256', implode( "\n", $lines ) );
		if ( hash_equals( (string) get_option( Premiero_Telegram::OPT_DIGEST_HASH, '' ), $hash ) ) {
			return;
		}

		// Una lista vacia no se avisa, pero guarda el hash para volver a avisar si reaparece.
		if ( $lines ) {
			$result = Premiero_Telegram_Sender::send( 'Actualizaciones pendientes', $lines );
			if ( ! $result['ok'] ) {
				return;
			}
		}
		update_option( Premiero_Telegram::OPT_DIGEST_HASH, $hash, false );
	}

	/**
	 * @return string[]|null Null si WordPress aun no tiene datos de actualizaciones.
	 */
	private static function pending_update_lines() {
		$plugins = get_site_transient( 'update_plugins' );
		$themes  = get_site_transient( 'update_themes' );
		$core    = get_site_transient( 'update_core' );
		if ( false === $plugins && false === $themes && false === $core ) {
			return null;
		}

		$lines = array();
		if ( is_object( $plugins ) && ! empty( $plugins->response ) ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$installed = get_plugins();
			foreach ( (array) $plugins->response as $file => $update ) {
				$name    = isset( $installed[ $file ]['Name'] ) ? $installed[ $file ]['Name'] : $file;
				$version = is_object( $update ) && isset( $update->new_version ) ? $update->new_version : '';
				$lines[] = '- Plugin ' . $name . ': ' . $version;
			}
		}
		if ( is_object( $themes ) && ! empty( $themes->response ) ) {
			foreach ( (array) $themes->response as $slug => $update ) {
				$header  = self::theme_header( $slug );
				$version = is_array( $update ) && isset( $update['new_version'] ) ? $update['new_version'] : '';
				$lines[] = '- Tema ' . $header['name'] . ': ' . $version;
			}
		}
		if ( is_object( $core ) && ! empty( $core->updates ) ) {
			foreach ( (array) $core->updates as $update ) {
				if ( is_object( $update ) && isset( $update->response ) && 'upgrade' === $update->response ) {
					$lines[] = '- WordPress: ' . ( isset( $update->current ) ? $update->current : '' );
				}
			}
		}
		return array_values( array_unique( $lines ) );
	}

	/* ---------- Seguridad ---------- */

	public static function on_login( $user_login, $user ) {
		if ( $user instanceof WP_User && user_can( $user, 'manage_options' ) ) {
			Premiero_Telegram_Sender::send( 'Login de administrador', array( 'Usuario: ' . $user_login, 'IP: ' . self::ip() ) );
		}
	}

	/**
	 * Agrupa los intentos y deja un cron de un solo uso para el resumen, de modo
	 * que llegue aunque el ataque ya haya terminado.
	 */
	public static function on_login_failed( $username ) {
		$data = get_transient( Premiero_Telegram::TRANSIENT_FAILED_LOGINS );
		$data = is_array( $data ) ? $data : array(
			'count' => 0,
			'users' => array(),
			'ips'   => array(),
		);

		++$data['count'];
		$data['users'] = self::count_key( $data['users'], mb_substr( (string) $username, 0, 60, 'UTF-8' ) );
		$data['ips']   = self::count_key( $data['ips'], self::ip() );
		set_transient( Premiero_Telegram::TRANSIENT_FAILED_LOGINS, $data, 3 * self::FAILED_LOGIN_WINDOW );

		if ( ! wp_next_scheduled( Premiero_Telegram::CRON_FAILED_LOGINS ) ) {
			wp_schedule_single_event( time() + self::FAILED_LOGIN_WINDOW, Premiero_Telegram::CRON_FAILED_LOGINS );
		}
	}

	public static function send_failed_logins() {
		$data = get_transient( Premiero_Telegram::TRANSIENT_FAILED_LOGINS );
		if ( ! is_array( $data ) || empty( $data['count'] ) ) {
			return;
		}

		$result = Premiero_Telegram_Sender::send(
			'Intentos de login fallidos',
			array(
				'Intentos en la última hora: ' . (int) $data['count'],
				'Usuarios: ' . self::top_keys( $data['users'] ),
				'IPs: ' . self::top_keys( $data['ips'] ),
			)
		);

		if ( $result['ok'] ) {
			delete_transient( Premiero_Telegram::TRANSIENT_FAILED_LOGINS );
		} else {
			wp_schedule_single_event( time() + self::FAILED_LOGIN_WINDOW, Premiero_Telegram::CRON_FAILED_LOGINS );
		}
	}

	private static function count_key( $counts, $key ) {
		if ( isset( $counts[ $key ] ) ) {
			++$counts[ $key ];
		} elseif ( count( $counts ) < self::FAILED_LOGIN_MAX_KEYS ) {
			$counts[ $key ] = 1;
		}
		return $counts;
	}

	private static function top_keys( $counts ) {
		$counts = (array) $counts;
		arsort( $counts );
		$parts = array();
		foreach ( array_slice( $counts, 0, 5, true ) as $key => $count ) {
			$parts[] = $key . ' (' . (int) $count . ')';
		}
		return implode( ', ', $parts );
	}

	public static function on_user_register( $user_id ) {
		$user = get_userdata( $user_id );
		if ( $user ) {
			Premiero_Telegram_Sender::send(
				'Nuevo usuario',
				array( 'Usuario: ' . $user->user_login, 'Email: ' . $user->user_email, 'Rol: ' . implode( ', ', (array) $user->roles ) )
			);
		}
	}

	public static function on_set_user_role( $user_id, $role, $old_roles ) {
		if ( 'administrator' !== $role || in_array( 'administrator', (array) $old_roles, true ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( $user ) {
			Premiero_Telegram_Sender::send(
				'Un usuario ahora es administrador',
				array( 'Usuario: ' . $user->user_login, 'Email: ' . $user->user_email, 'Rol anterior: ' . implode( ', ', (array) $old_roles ) )
			);
		}
	}

	/**
	 * Solo recibe el usuario: el hook tambien pasa la contrasena nueva y aqui no se acepta.
	 * El email de WordPress solo se omite para administradores y con la entrega confirmada.
	 */
	public static function on_password_reset( $user ) {
		if ( ! $user instanceof WP_User || ! user_can( $user, 'manage_options' ) ) {
			return;
		}
		$result = Premiero_Telegram_Sender::send(
			'Restablecimiento de contraseña de un administrador',
			array( 'Usuario: ' . $user->user_login, 'IP: ' . self::ip() )
		);
		if ( $result['ok'] && Premiero_Telegram::email_is_suppressed( 'email_password_reset' ) ) {
			remove_action( 'after_password_reset', 'wp_password_change_notification' );
		}
	}

	public static function on_site_option_changed( $old_value, $value, $option ) {
		$labels = array(
			'admin_email' => 'Email de administración',
			'siteurl'     => 'URL de WordPress (siteurl)',
			'home'        => 'URL de la web (home)',
		);
		Premiero_Telegram_Sender::send(
			'Cambio de ajuste crítico',
			array(
				( isset( $labels[ $option ] ) ? $labels[ $option ] : (string) $option ) . ':',
				'Antes: ' . self::scalar_to_string( $old_value ),
				'Ahora: ' . self::scalar_to_string( $value ),
			)
		);
	}

	public static function on_plugin_activated( $plugin ) {
		$header = self::plugin_header( $plugin );
		Premiero_Telegram_Sender::send( 'Plugin activado', array( $header['name'] ) );
	}

	public static function on_plugin_deactivated( $plugin ) {
		$header = self::plugin_header( $plugin );
		Premiero_Telegram_Sender::send( 'Plugin desactivado', array( $header['name'] ) );
	}

	public static function on_plugin_deleted( $plugin, $deleted ) {
		if ( $deleted ) {
			Premiero_Telegram_Sender::send( 'Plugin eliminado', array( (string) $plugin ) );
		}
	}

	public static function on_switch_theme( $new_name, $new_theme, $old_theme ) {
		$lines = array( 'Tema nuevo: ' . (string) $new_name );
		if ( $old_theme instanceof WP_Theme ) {
			$lines[] = 'Tema anterior: ' . $old_theme->get( 'Name' );
		}
		Premiero_Telegram_Sender::send( 'Cambio de tema', $lines );
	}

	/* ---------- Contenido ---------- */

	public static function on_comment_post( $comment_id, $approved ) {
		// Comparacion estricta: (int) 'spam' tambien vale 0.
		if ( 0 !== $approved && '0' !== $approved ) {
			return;
		}
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return;
		}
		Premiero_Telegram_Sender::send(
			'Comentario pendiente de moderación',
			array(
				'Entrada: ' . get_the_title( (int) $comment->comment_post_ID ),
				'Autor: ' . $comment->comment_author,
				'Comentario: ' . mb_substr( wp_strip_all_tags( $comment->comment_content ), 0, self::FIELD_LENGTH, 'UTF-8' ),
				'Moderar: ' . admin_url( 'comment.php?action=editcomment&c=' . (int) $comment_id ),
			)
		);
	}

	/* ---------- Utilidades ---------- */

	/**
	 * Lee la cabecera del disco: las funciones de WordPress devuelven datos en
	 * cache durante la misma peticion en la que se actualiza.
	 */
	private static function plugin_header( $file ) {
		$file = (string) $file;
		$path = WP_PLUGIN_DIR . '/' . $file;
		$data = file_exists( $path ) ? get_file_data( $path, array( 'name' => 'Plugin Name', 'version' => 'Version' ) ) : array();
		return array(
			'name'    => ! empty( $data['name'] ) ? $data['name'] : $file,
			'version' => isset( $data['version'] ) ? (string) $data['version'] : '',
		);
	}

	private static function theme_header( $slug ) {
		$slug = (string) $slug;
		$path = get_theme_root( $slug ) . '/' . $slug . '/style.css';
		$data = file_exists( $path ) ? get_file_data( $path, array( 'name' => 'Theme Name', 'version' => 'Version' ) ) : array();
		return array(
			'name'    => ! empty( $data['name'] ) ? $data['name'] : $slug,
			'version' => isset( $data['version'] ) ? (string) $data['version'] : '',
		);
	}

	/** Solo REMOTE_ADDR: las cabeceras de proxy las puede falsificar quien ataca. */
	private static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return '' !== $ip ? $ip : 'desconocida';
	}
}
