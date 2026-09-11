<?php
/**
 * Diagnóstico, depuración y reparación avanzada.
 *
 * Biblioteca desacoplada de la interfaz: registro de herramientas,
 * ejecución, historial, generación de informes y redacción de datos sensibles.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Diagnostics {

	const OPT_ENABLED        = 'premiero_diag_enabled';
	const OPT_HISTORY        = 'premiero_diag_history';
	const OPT_TEMP_LOG       = 'premiero_diag_temp_log';
	const NONCE_ACTION       = 'premiero_diag_action';
	const AJAX_RUN_DIAG      = 'premiero_diag_run';
	const AJAX_RUN_REPAIR    = 'premiero_diag_repair';
	const AJAX_RUN_PHP       = 'premiero_diag_php';
	const AJAX_CLEAR_HISTORY = 'premiero_diag_clear_history';
	const AJAX_READ_LOG      = 'premiero_diag_read_log';
	const AJAX_CLEAR_LOG     = 'premiero_diag_clear_log';
	const AJAX_TOGGLE_LOG    = 'premiero_diag_toggle_log';
	const AJAX_TEST_LOG      = 'premiero_diag_test_log';
	const MAX_HISTORY        = 50;
	const MAX_REPORT_ACTIONS = 10;
	const MAX_LOG_VIEW_BYTES = 2097152;

	public static function init() {
		add_action( 'wp_ajax_' . self::AJAX_RUN_DIAG, array( __CLASS__, 'ajax_run_diag' ) );
		add_action( 'wp_ajax_' . self::AJAX_RUN_REPAIR, array( __CLASS__, 'ajax_run_repair' ) );
		add_action( 'wp_ajax_' . self::AJAX_RUN_PHP, array( __CLASS__, 'ajax_run_php' ) );
		add_action( 'wp_ajax_' . self::AJAX_CLEAR_HISTORY, array( __CLASS__, 'ajax_clear_history' ) );
		add_action( 'wp_ajax_' . self::AJAX_READ_LOG, array( __CLASS__, 'ajax_read_log' ) );
		add_action( 'wp_ajax_' . self::AJAX_CLEAR_LOG, array( __CLASS__, 'ajax_clear_log' ) );
		add_action( 'wp_ajax_' . self::AJAX_TOGGLE_LOG, array( __CLASS__, 'ajax_toggle_log' ) );
		add_action( 'wp_ajax_' . self::AJAX_TEST_LOG, array( __CLASS__, 'ajax_test_log' ) );

		// Si el registro temporal está activo se aplica en CADA petición, desde
		// que el plugin se carga y no sólo en la pantalla Diagnóstico.
		self::bootstrap_temp_log();
	}

	public static function is_enabled() {
		return (bool) get_option( self::OPT_ENABLED, false );
	}

	/* ====================== Registro temporal de errores ====================== */

	/**
	 * Preferencia del usuario para el registro temporal de errores.
	 */
	public static function is_temp_log_enabled() {
		return (bool) get_option( self::OPT_TEMP_LOG, false );
	}

	/**
	 * Ruta del archivo de registro del Toolkit.
	 */
	public static function temp_log_path() {
		return WP_CONTENT_DIR . '/debug.log';
	}

	/**
	 * Aplica el registro temporal de errores.
	 *
	 * Se ejecuta desde init() (carga del plugin) en CADA petición de WordPress
	 * mientras la opción esté activa, de modo que no depende de la pantalla
	 * Diagnóstico. No escribe wp-config.php y no activa display_errors: sólo
	 * desvía los errores al archivo para que no se impriman en pantalla.
	 *
	 * Los errores ocurridos antes de que WordPress cargue los plugins (bootstrap
	 * previo) quedan fuera de este mecanismo.
	 */
	public static function bootstrap_temp_log() {
		if ( ! self::is_enabled() || ! self::is_temp_log_enabled() ) {
			return;
		}
		if ( ! function_exists( 'ini_set' ) ) {
			return;
		}
		@ini_set( 'log_errors', '1' );
		@ini_set( 'display_errors', '0' );
		@ini_set( 'error_log', self::temp_log_path() );
	}

	/**
	 * Comprueba que wp-content sea escribible y crea debug.log si no existe.
	 *
	 * @return array{ok:bool,message:string,path:string}
	 */
	public static function ensure_temp_log_file() {
		$path = self::temp_log_path();
		if ( file_exists( $path ) ) {
			return is_writable( $path )
				? array( 'ok' => true, 'message' => '', 'path' => $path )
				: array( 'ok' => false, 'message' => 'debug.log existe pero no es escribible por PHP.', 'path' => $path );
		}
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			return array( 'ok' => false, 'message' => 'El directorio wp-content no existe.', 'path' => $path );
		}
		if ( ! is_writable( WP_CONTENT_DIR ) ) {
			return array( 'ok' => false, 'message' => 'wp-content no es escribible; no se puede crear debug.log.', 'path' => $path );
		}
		if ( false === @file_put_contents( $path, '' ) ) {
			return array( 'ok' => false, 'message' => 'No se pudo crear debug.log dentro de wp-content.', 'path' => $path );
		}
		return array( 'ok' => true, 'message' => '', 'path' => $path );
	}

	/**
	 * Escribe una entrada propia en el registro temporal mediante error_log().
	 *
	 * Si error_log() no llega a wp-content/debug.log (destino distinto o
	 * bloqueado) se escribe directamente en el archivo para garantizar que la
	 * entrada queda registrada.
	 *
	 * @return array{line:string,written:bool,path:string}
	 */
	public static function write_temp_log_entry( $message ) {
		$path = self::temp_log_path();
		$stamp = function_exists( 'current_time' ) ? current_time( 'd/m/Y H:i:s' ) : gmdate( 'd/m/Y H:i:s' );
		$line  = '[Premiero Diagnostics] ' . trim( (string) $message ) . ' - ' . $stamp;

		clearstatcache( true, $path );
		$before = file_exists( $path ) ? (int) @filesize( $path ) : -1;

		if ( function_exists( 'error_log' ) ) {
			@error_log( $line );
		}

		clearstatcache( true, $path );
		$after   = file_exists( $path ) ? (int) @filesize( $path ) : -1;
		$written = ( $after > $before );

		if ( ! $written ) {
			$written = ( false !== @file_put_contents( $path, $line . "\n", FILE_APPEND ) );
		}

		return array( 'line' => $line, 'written' => (bool) $written, 'path' => $path );
	}

	/**
	 * Estado REAL del registro temporal.
	 *
	 * No se basa únicamente en la opción guardada: comprueba también los
	 * valores efectivos de ini_get() y la situación del archivo.
	 */
	public static function temp_log_status() {
		$target        = self::temp_log_path();
		$current       = (string) ini_get( 'error_log' );
		$log_errors    = (string) ini_get( 'log_errors' );
		$enabled       = self::is_temp_log_enabled();
		$log_errors_on = in_array( strtolower( $log_errors ), array( '1', 'on', 'yes', 'true' ), true );
		$exists        = file_exists( $target );
		$points_here   = ( $current === $target );
		$active        = ( $enabled && self::is_enabled() && $log_errors_on && $points_here );

		if ( $active ) {
			$state = 'on';
			$label = 'Activado';
		} elseif ( $enabled && self::is_enabled() ) {
			$state = 'warn';
			$label = 'Activado (sin efecto)';
		} else {
			$state = 'off';
			$label = 'Desactivado';
		}

		return array(
			'enabled'       => $enabled,
			'active'        => $active,
			'state'         => $state,
			'label'         => $label,
			'log_errors'    => $log_errors_on,
			'error_path'    => $current,
			'target'        => $target,
			'points_here'   => $points_here,
			'file_exists'   => $exists,
			'file_writable' => ( $exists ? is_writable( $target ) : is_writable( WP_CONTENT_DIR ) ),
			'dir_writable'  => is_writable( WP_CONTENT_DIR ),
			'wp_debug'      => ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
			'wp_debug_log'  => ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ),
		);
	}

	/**
	 * Estado del registro temporal listo para enviarse por AJAX.
	 */
	private static function temp_log_payload( $extra = array() ) {
		return array_merge( self::temp_log_status(), (array) $extra );
	}
	/**
	 * Catálogo de herramientas. Añadir un nuevo diagnóstico o reparación
	 * consiste en registrar aquí una entrada y crear su callback.
	 */
	public static function tools() {
		return array(
			'info'             => array(
				'id'          => 'info',
				'name'        => 'Información WordPress/PHP',
				'category'    => 'Sistema',
				'description' => 'Versiones, memoria, constantes de depuración y rutas importantes.',
				'type'        => 'diagnostic',
				'writes'      => false,
				'risk'        => 'none',
				'callback'    => array( __CLASS__, 'diag_info' ),
			),
			'wp_config'        => array(
				'id'          => 'wp_config',
				'name'        => 'Revisar wp-config.php',
				'category'    => 'Sistema',
				'description' => 'Analiza el archivo de configuración ocultando cualquier valor sensible.',
				'type'        => 'diagnostic',
				'writes'      => false,
				'risk'        => 'none',
				'callback'    => array( __CLASS__, 'diag_wp_config' ),
			),
			'htaccess'         => array(
				'id'          => 'htaccess',
				'name'        => 'Revisar .htaccess',
				'category'    => 'Sistema',
				'description' => 'Detecta directivas inusuales o redirecciones externas.',
				'type'        => 'diagnostic',
				'writes'      => false,
				'risk'        => 'none',
				'callback'    => array( __CLASS__, 'diag_htaccess' ),
			),
			'admin_users'      => array(
				'id'          => 'admin_users',
				'name'        => 'Usuarios administradores',
				'category'    => 'Seguridad',
				'description' => 'Lista los administradores y detecta anomalías en sus cuentas.',
				'type'        => 'diagnostic',
				'writes'      => false,
				'risk'        => 'none',
				'callback'    => array( __CLASS__, 'diag_admin_users' ),
			),
			'active_plugins'   => array(
				'id'          => 'active_plugins',
				'name'        => 'Plugins activos',
				'category'    => 'WordPress',
				'description' => 'Compara los plugins activos con los archivos realmente presentes.',
				'type'        => 'diagnostic',
				'writes'      => false,
				'risk'        => 'none',
				'callback'    => array( __CLASS__, 'diag_active_plugins' ),
			),
			'mu_plugins'       => array(
				'id'          => 'mu_plugins',
				'name'        => 'MU-plugins y drop-ins',
				'category'    => 'WordPress',
				'description' => 'Lista los must-use plugins y drop-ins instalados.',
				'type'        => 'diagnostic',
				'writes'      => false,
				'risk'        => 'none',
				'callback'    => array( __CLASS__, 'diag_mu_plugins' ),
			),
			'cron'             => array(
				'id'          => 'cron',
				'name'        => 'WP-Cron y tareas sospechosas',
				'category'    => 'Seguridad',
				'description' => 'Revisa las tareas programadas y marca nombres potencialmente maliciosos.',
				'type'        => 'diagnostic',
				'writes'      => false,
				'risk'        => 'none',
				'callback'    => array( __CLASS__, 'diag_cron' ),
			),
			'debug_log'        => array(
				'id'          => 'debug_log',
				'name'        => 'debug.log / errores recientes',
				'category'    => 'Sistema',
				'description' => 'Muestra las últimas líneas del registro de depuración si existe.',
				'type'        => 'diagnostic',
				'writes'      => false,
				'risk'        => 'none',
				'callback'    => array( __CLASS__, 'diag_debug_log' ),
			),
			'recent_php'       => array(
				'id'          => 'recent_php',
				'name'        => 'Archivos PHP modificados recientemente',
				'category'    => 'Seguridad',
				'description' => 'Localiza archivos PHP cambiados en los últimos días, priorizando el núcleo.',
				'type'        => 'diagnostic',
				'writes'      => false,
				'risk'        => 'none',
				'callback'    => array( __CLASS__, 'diag_recent_php' ),
			),
			'malware_scan'     => array(
				'id'          => 'malware_scan',
				'name'        => 'Búsqueda heurística de patrones sospechosos',
				'category'    => 'Seguridad',
				'description' => 'Busca patrones habituales en código ofuscado. Resultado orientativo, no definitivo.',
				'type'        => 'diagnostic',
				'writes'      => false,
				'risk'        => 'none',
				'callback'    => array( __CLASS__, 'diag_malware_scan' ),
			),
			'repair_rewrite'   => array(
				'id'          => 'repair_rewrite',
				'name'        => 'Regenerar rewrite rules',
				'category'    => 'Reparación',
				'description' => 'Regenera las reglas de enlaces permanentes.',
				'type'        => 'repair',
				'writes'      => true,
				'risk'        => 'low',
				'callback'    => array( __CLASS__, 'repair_rewrite' ),
			),
			'repair_cron'      => array(
				'id'          => 'repair_cron',
				'name'        => 'Eliminar una tarea WP-Cron seleccionada',
				'category'    => 'Reparación',
				'description' => 'Elimina una tarea programada concreta elegida de la lista.',
				'type'        => 'repair',
				'writes'      => true,
				'risk'        => 'medium',
				'callback'    => array( __CLASS__, 'repair_cron' ),
			),
			'repair_transient' => array(
				'id'          => 'repair_transient',
				'name'        => 'Eliminar un transient seleccionado',
				'category'    => 'Reparación',
				'description' => 'Elimina un transient concreto elegido de la lista.',
				'type'        => 'repair',
				'writes'      => true,
				'risk'        => 'low',
				'callback'    => array( __CLASS__, 'repair_transient' ),
			),
		);
	}

	public static function get_tool( $id ) {
		$tools = self::tools();
		return isset( $tools[ $id ] ) ? $tools[ $id ] : null;
	}
	/* ====================== Utilidades ====================== */

	private static function summarize( $items ) {
		$ok = $warning = $problem = 0;
		foreach ( $items as $item ) {
			if ( 'problem' === $item['status'] ) {
				++$problem;
			} elseif ( 'warning' === $item['status'] ) {
				++$warning;
			} else {
				++$ok;
			}
		}
		return array( $ok, $warning, $problem );
	}

	private static function overall_status( $items ) {
		list( , $warning, $problem ) = self::summarize( $items );
		if ( $problem > 0 ) {
			return 'problem';
		}
		if ( $warning > 0 ) {
			return 'warning';
		}
		return 'ok';
	}

	private static function is_sensitive_key( $key ) {
		return (bool) preg_match( '/(PASSWORD|SECRET|TOKEN|API_?KEY|_KEY$|_SALT$|AUTH|NONCE)/i', (string) $key );
	}

	private static function locate_wp_config() {
		$candidates = array(
			ABSPATH . 'wp-config.php',
			dirname( ABSPATH ) . '/wp-config.php',
		);
		foreach ( $candidates as $path ) {
			if ( $path && file_exists( $path ) && is_readable( $path ) ) {
				return $path;
			}
		}
		return false;
	}

	private static function collect_secrets() {
		$path = self::locate_wp_config();
		if ( ! $path ) {
			return array();
		}
		$content = @file_get_contents( $path );
		if ( false === $content ) {
			return array();
		}
		$secrets = array();
		preg_match_all( "/define\s*\(\s*['\"]([A-Z0-9_]+)['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)/", $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			if ( self::is_sensitive_key( $match[1] ) && '' !== $match[2] && strlen( $match[2] ) > 2 ) {
				$secrets[] = $match[2];
			}
		}
		return array_values( array_unique( $secrets ) );
	}

	public static function redact_text( $text ) {
		$secrets = self::collect_secrets();
		foreach ( $secrets as $secret ) {
			$text = str_replace( $secret, '[redactado]', $text );
		}
		return $text;
	}

	private static function cron_signature( $timestamp, $hook, $args ) {
		return $timestamp . '|' . $hook . '|' . md5( serialize( $args ) );
	}

	private static function bytes( $bytes ) {
		$bytes = (int) $bytes;
		if ( $bytes >= 1048576 ) {
			return round( $bytes / 1048576, 1 ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return round( $bytes / 1024, 1 ) . ' KB';
		}
		return $bytes . ' B';
	}

	/**
	 * Recorre archivos PHP con límites de tiempo y cantidad para evitar
	 * agotar memoria o superar el timeout en instalaciones grandes.
	 */
	private static function walk_php_files( $dir, $max_files = 3000, $max_seconds = 10 ) {
		$start    = microtime( true );
		$files    = array();
		$stack    = array( untrailingslashit( $dir ) );
		$excluded = array( 'wp-content/cache', 'wp-content/upgrade', 'wp-content/uploads' );

		while ( $stack ) {
			$current = array_pop( $stack );
			if ( ! is_dir( $current ) || ! is_readable( $current ) ) {
				continue;
			}
			$entries = @scandir( $current );
			if ( false === $entries ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				if ( microtime( true ) - $start > $max_seconds || count( $files ) >= $max_files ) {
					break 2;
				}
				$full = $current . '/' . $entry;
				if ( is_dir( $full ) ) {
					$norm = str_replace( '\\', '/', $full );
					$skip = false;
					foreach ( $excluded as $part ) {
						if ( false !== strpos( $norm, $part ) ) {
							$skip = true;
							break;
						}
					}
					if ( ! $skip ) {
						$stack[] = $full;
					}
				} elseif ( is_file( $full ) && '.php' === substr( $entry, -4 ) ) {
					$files[] = $full;
				}
			}
		}
		return $files;
	}
	/* ====================== Diagnósticos ====================== */

	public static function diag_info() {
		global $wp_version;
		$items = array(
			array( 'status' => 'info', 'label' => 'WordPress', 'detail' => $wp_version ),
			array( 'status' => 'info', 'label' => 'PHP', 'detail' => PHP_VERSION ),
			array( 'status' => 'info', 'label' => 'Entorno', 'detail' => wp_get_environment_type() ),
			array( 'status' => 'info', 'label' => 'memory_limit', 'detail' => ini_get( 'memory_limit' ) ),
			array( 'status' => 'info', 'label' => 'WP_MEMORY_LIMIT', 'detail' => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '—' ),
			array( 'status' => 'info', 'label' => 'WP_MAX_MEMORY_LIMIT', 'detail' => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '—' ),
			array( 'status' => 'info', 'label' => 'WP_DEBUG', 'detail' => defined( 'WP_DEBUG' ) && WP_DEBUG ? 'ON' : 'OFF' ),
			array( 'status' => 'info', 'label' => 'WP_DEBUG_LOG', 'detail' => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ? 'ON' : 'OFF' ),
			array( 'status' => 'info', 'label' => 'WP_DEBUG_DISPLAY', 'detail' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ? 'ON' : 'OFF' ),
			array( 'status' => 'info', 'label' => 'WP_CACHE', 'detail' => defined( 'WP_CACHE' ) && WP_CACHE ? 'ON' : 'OFF' ),
			array( 'status' => 'info', 'label' => 'ABSPATH', 'detail' => ABSPATH ),
			array( 'status' => 'info', 'label' => 'WP_CONTENT_DIR', 'detail' => WP_CONTENT_DIR ),
			array( 'status' => 'info', 'label' => 'WP_PLUGIN_DIR', 'detail' => WP_PLUGIN_DIR ),
		);
		if ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) {
			$items[] = array( 'status' => 'problem', 'label' => 'WP_DEBUG_DISPLAY', 'detail' => 'Los errores se muestran en pantalla; recomendable desactivarlo en producción.' );
		}
		return array(
			'items'  => $items,
			'env'    => array(
				'wordpress' => $wp_version,
				'php'       => PHP_VERSION,
			),
			'report' => 'WordPress ' . $wp_version . ' · PHP ' . PHP_VERSION,
			'status' => self::overall_status( $items ),
		);
	}

	public static function diag_wp_config() {
		$path = self::locate_wp_config();
		if ( ! $path ) {
			return array(
				'items'  => array( array( 'status' => 'problem', 'label' => 'wp-config.php', 'detail' => 'No se localizó el archivo de configuración.' ) ),
				'report' => 'wp-config: no localizado',
				'status' => 'problem',
			);
		}
		$content = @file_get_contents( $path );
		if ( false === $content ) {
			return array(
				'items'  => array( array( 'status' => 'problem', 'label' => 'wp-config.php', 'detail' => 'El archivo no es legible.' ) ),
				'report' => 'wp-config: no legible',
				'status' => 'problem',
			);
		}

		$items   = array();
		$defined = array();
		preg_match_all( "/define\s*\(\s*['\"]([A-Z0-9_]+)['\"]\s*,\s*(.+?)\s*\)\s*;/s", $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$name  = $match[1];
			$value = trim( $match[2] );
			$defined[ $name ] = true;
			if ( self::is_sensitive_key( $name ) ) {
				$items[] = array( 'status' => 'info', 'label' => $name, 'detail' => '[redactado]' );
			} else {
				$items[] = array( 'status' => 'info', 'label' => $name, 'detail' => $value );
			}
		}

		if ( ! empty( $defined['WP_DEBUG_DISPLAY'] ) && preg_match( "/define\s*\(\s*'WP_DEBUG_DISPLAY'\s*,\s*(true|1)/i", $content ) ) {
			$items[] = array( 'status' => 'problem', 'label' => 'WP_DEBUG_DISPLAY', 'detail' => 'Está activo: los errores se muestran en pantalla.' );
		}
		if ( ! isset( $defined['DISALLOW_FILE_EDIT'] ) ) {
			$items[] = array( 'status' => 'warning', 'label' => 'DISALLOW_FILE_EDIT', 'detail' => 'No definido: el editor de archivos de wp-admin está disponible.' );
		}
		if ( ! isset( $defined['DISALLOW_FILE_MODS'] ) ) {
			$items[] = array( 'status' => 'info', 'label' => 'DISALLOW_FILE_MODS', 'detail' => 'No definido: las actualizaciones de plugins/temas están permitidas.' );
		}
		if ( preg_match( '/(require|include)\s*(_once)?\s*[\(\s]*[\'"]http/i', $content ) ) {
			$items[] = array( 'status' => 'problem', 'label' => 'Inclusión externa', 'detail' => 'Se detectó un require/include de una URL remota.' );
		}

		list( $ok, $warning, $problem ) = self::summarize( $items );
		return array(
			'items'   => $items,
			'report'  => 'wp-config: ' . ( $problem ? $problem . ' problema(s)' : ( $warning ? $warning . ' aviso(s)' : 'OK' ) ),
			'status'  => self::overall_status( $items ),
			'summary' => array( $ok, $warning, $problem ),
		);
	}
	public static function diag_htaccess() {
		$path = ABSPATH . '.htaccess';
		if ( ! file_exists( $path ) ) {
			return array(
				'items'  => array( array( 'status' => 'info', 'label' => '.htaccess', 'detail' => 'No existe en la raíz de la instalación.' ) ),
				'report' => '.htaccess: no existe',
				'status' => 'ok',
			);
		}
		$content = @file_get_contents( $path );
		if ( false === $content ) {
			return array(
				'items'  => array( array( 'status' => 'problem', 'label' => '.htaccess', 'detail' => 'El archivo no es legible.' ) ),
				'report' => '.htaccess: no legible',
				'status' => 'problem',
			);
		}

		$items   = array();
		$lines   = preg_split( '/\R/', $content );
		$items[] = array( 'status' => 'info', 'label' => 'Tamaño', 'detail' => self::bytes( strlen( $content ) ) );
		$items[] = array( 'status' => 'info', 'label' => 'Última modificación', 'detail' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) @filemtime( $path ) ), 'd/m/Y H:i' ) );

		$suspicious = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			if ( preg_match( '/RewriteRule\s+.*https?:\/\//i', $line ) ) {
				$suspicious[] = 'Redirección externa: ' . substr( $line, 0, 90 );
			} elseif ( preg_match( '/^\s*(php_value|php_flag|AddType\s+.*php|SetHandler)/i', $line ) ) {
				$suspicious[] = 'Directiva PHP inusual: ' . substr( $line, 0, 90 );
			}
		}
		foreach ( $suspicious as $s ) {
			$items[] = array( 'status' => 'warning', 'label' => 'Revisar', 'detail' => $s );
		}
		if ( ! $suspicious ) {
			$items[] = array( 'status' => 'ok', 'label' => 'Directivas', 'detail' => 'Sin redirecciones externas ni directivas PHP anómalas detectadas.' );
		}
		return array(
			'items'  => $items,
			'report' => '.htaccess: ' . ( $suspicious ? count( $suspicious ) . ' elemento(s) a revisar' : 'OK' ),
			'status' => self::overall_status( $items ),
		);
	}

	public static function diag_admin_users() {
		$admins = get_users( array( 'role' => 'administrator', 'orderby' => 'user_login' ) );
		$items  = array();
		if ( empty( $admins ) ) {
			$items[] = array( 'status' => 'problem', 'label' => 'Administradores', 'detail' => 'No se encontró ningún administrador.' );
		}
		foreach ( $admins as $user ) {
			$detail = $user->user_email;
			if ( empty( $user->user_email ) ) {
				$detail = 'Sin correo asignado';
				$items[] = array( 'status' => 'problem', 'label' => $user->user_login, 'detail' => $detail );
			} else {
				$items[] = array( 'status' => 'info', 'label' => $user->user_login, 'detail' => $detail );
			}
		}
		$count = count( $admins );
		if ( $count > 3 ) {
			$items[] = array( 'status' => 'warning', 'label' => 'Número de administradores', 'detail' => $count . ' cuentas con permisos máximos; revisa si todas son necesarias.' );
		}
		return array(
			'items'  => $items,
			'report' => 'Administradores: ' . $count,
			'status' => self::overall_status( $items ),
		);
	}
	public static function diag_active_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active   = (array) get_option( 'active_plugins', array() );
		$all      = get_plugins();
		$items    = array();
		$orphaned = 0;
		foreach ( $active as $plugin_file ) {
			if ( ! isset( $all[ $plugin_file ] ) ) {
				++$orphaned;
				$items[] = array( 'status' => 'warning', 'label' => $plugin_file, 'detail' => 'Activo pero el archivo no existe en el sistema.' );
			}
		}
		$items[] = array( 'status' => 'info', 'label' => 'Total activos', 'detail' => count( $active ) );
		if ( $orphaned ) {
			$items[] = array( 'status' => 'warning', 'label' => 'Huérfanos', 'detail' => $orphaned . ' plugin(s) activo(s) sin archivo.' );
		} else {
			$items[] = array( 'status' => 'ok', 'label' => 'Coherencia', 'detail' => 'Todos los plugins activos existen en el sistema.' );
		}
		return array(
			'items'  => $items,
			'report' => 'Plugins activos: ' . count( $active ),
			'status' => self::overall_status( $items ),
		);
	}

	public static function diag_mu_plugins() {
		if ( ! function_exists( 'get_mu_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$mu_plugins = get_mu_plugins();
		$dropins    = get_dropins();
		$items      = array();
		foreach ( $mu_plugins as $file => $data ) {
			$items[] = array( 'status' => 'info', 'label' => $file, 'detail' => isset( $data['Name'] ) ? $data['Name'] : '' );
		}
		foreach ( $dropins as $file => $data ) {
			$items[] = array( 'status' => 'info', 'label' => 'drop-in: ' . $file, 'detail' => isset( $data['Name'] ) ? $data['Name'] : '' );
		}
		if ( ! $mu_plugins && ! $dropins ) {
			$items[] = array( 'status' => 'ok', 'label' => 'Sin MU-plugins/drop-ins', 'detail' => 'No hay must-use plugins ni drop-ins instalados.' );
		}
		return array(
			'items'  => $items,
			'report' => 'MU-plugins: ' . count( $mu_plugins ) . ' · drop-ins: ' . count( $dropins ),
			'status' => self::overall_status( $items ),
		);
	}

	public static function diag_cron() {
		$crons      = _get_cron_array();
		$items      = array();
		$total      = 0;
		$suspicious = 0;
		if ( is_array( $crons ) ) {
			foreach ( $crons as $timestamp => $events ) {
				foreach ( $events as $hook => $args_array ) {
					$total += count( $args_array );
					if ( preg_match( '/(base64|eval\s*\(|system\s*\(|exec\s*\(|shell_exec|passthru|curl|wget|gzinflate|str_rot13)/i', (string) $hook ) ) {
						++$suspicious;
						$items[] = array( 'status' => 'warning', 'label' => $hook, 'detail' => 'Nombre de tarea potencialmente sospechoso.' );
					}
				}
			}
		}
		$items[] = array( 'status' => 'info', 'label' => 'Total de tareas', 'detail' => $total );
		if ( $suspicious ) {
			$items[] = array( 'status' => 'warning', 'label' => 'Tareas a revisar', 'detail' => $suspicious . ' tarea(s) con nombre potencialmente sospechoso.' );
		} else {
			$items[] = array( 'status' => 'ok', 'label' => 'Revisión', 'detail' => 'No se detectaron nombres de tareas sospechosos.' );
		}
		return array(
			'items'  => $items,
			'report' => 'Cron: ' . $total . ' tarea(s)' . ( $suspicious ? ', ' . $suspicious . ' a revisar' : '' ),
			'status' => self::overall_status( $items ),
		);
	}
	public static function diag_debug_log() {
		$path = self::temp_log_path();
		if ( ! file_exists( $path ) ) {
			$wp_debug_log = ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG )
				? 'WP_DEBUG_LOG está definido en wp-config.php; es un mecanismo independiente del registro temporal del Toolkit.'
				: 'WP_DEBUG_LOG no está definido; el registro temporal del Toolkit no depende de él.';
			return array(
				'items'  => array(
					array( 'status' => 'info', 'label' => 'debug.log', 'detail' => 'El archivo todavía no existe: se creará al activar el registro temporal, al generar una entrada de prueba o cuando PHP registre un error.' ),
					array( 'status' => 'info', 'label' => 'WP_DEBUG_LOG', 'detail' => $wp_debug_log ),
				),
				'report' => 'debug.log: no existe todavía',
				'status' => 'ok',
			);
		}
		$content = @file_get_contents( $path );
		if ( false === $content ) {
			return array(
				'items'  => array( array( 'status' => 'problem', 'label' => 'debug.log', 'detail' => 'El archivo no es legible.' ) ),
				'report' => 'debug.log: no legible',
				'status' => 'problem',
			);
		}

		$lines = preg_split( '/\R/', $content );
		$last  = array_slice( $lines, -40 );
		$last  = array_map( array( __CLASS__, 'redact_text' ), $last );

		$fatal = preg_match_all( '/(Fatal error|Parse error|Uncaught|Allowed memory size|Maximum execution time)/i', $content );

		$items = array(
			array( 'status' => 'info', 'label' => 'Tamaño', 'detail' => self::bytes( strlen( $content ) ) ),
			array( 'status' => 'info', 'label' => 'Última modificación', 'detail' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) @filemtime( $path ) ), 'd/m/Y H:i' ) ),
			array( 'status' => $fatal ? 'warning' : 'info', 'label' => 'Errores graves', 'detail' => $fatal . ' coincidencia(s) de errores fatales detectadas.' ),
		);
		foreach ( $last as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$items[] = array( 'status' => 'info', 'label' => 'Línea', 'detail' => $line );
			}
		}
		return array(
			'items'  => $items,
			'report' => 'debug.log: ' . self::bytes( strlen( $content ) ) . ( $fatal ? ', ' . $fatal . ' errores graves' : '' ),
			'status' => self::overall_status( $items ),
		);
	}
	public static function diag_recent_php() {
		$files = self::walk_php_files( ABSPATH );
		$since = time() - ( 7 * DAY_IN_SECONDS );
		$items = array();
		$core  = 0;
		$found = 0;
		foreach ( $files as $file ) {
			$mtime = (int) @filemtime( $file );
			if ( $mtime < $since ) {
				continue;
			}
			++$found;
			$norm  = str_replace( '\\', '/', $file );
			$label = str_replace( ABSPATH, '', $norm );
			if ( preg_match( '#wp-(admin|includes)/#', $norm ) ) {
				++$core;
				$items[] = array( 'status' => 'problem', 'label' => $label, 'detail' => 'Archivo del núcleo modificado recientemente.' );
			} else {
				$items[] = array( 'status' => 'info', 'label' => $label, 'detail' => 'Modificado el ' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $mtime ), 'd/m/Y H:i' ) );
			}
			if ( count( $items ) >= 30 ) {
				break;
			}
		}
		if ( ! $found ) {
			$items[] = array( 'status' => 'ok', 'label' => 'Sin cambios', 'detail' => 'No se encontraron archivos PHP modificados en los últimos 7 días.' );
		}
		return array(
			'items'  => $items,
			'report' => 'PHP reciente: ' . $found . ' archivo(s)' . ( $core ? ', ' . $core . ' del núcleo' : '' ),
			'status' => self::overall_status( $items ),
		);
	}

	public static function diag_malware_scan() {
		$files    = self::walk_php_files( ABSPATH );
		$patterns = array(
			'eval'            => '/\beval\s*\(/i',
			'base64_decode'   => '/\bbase64_decode\s*\(/i',
			'gzinflate'       => '/\bgzinflate\s*\(/i',
			'str_rot13'       => '/\bstr_rot13\s*\(/i',
			'assert'          => '/\bassert\s*\(/i',
			'create_function' => '/\bcreate_function\s*\(/i',
			'shell_exec'      => '/\bshell_exec\s*\(/i',
			'system'          => '/\bsystem\s*\(/i',
			'exec'            => '/\bexec\s*\(/i',
			'passthru'        => '/\bpassthru\s*\(/i',
			'hex2bin'         => '/\bhex2bin\s*\(/i',
			'move_uploaded'   => '/\bmove_uploaded_file\s*\(/i',
		);
		$matches = array();
		foreach ( $files as $file ) {
			$content = @file_get_contents( $file );
			if ( false === $content ) {
				continue;
			}
			foreach ( $patterns as $name => $pattern ) {
				if ( preg_match( $pattern, $content ) ) {
					$matches[] = array(
						'file'    => str_replace( ABSPATH, '', str_replace( '\\', '/', $file ) ),
						'pattern' => $name,
					);
				}
			}
			if ( count( $matches ) >= 40 ) {
				break;
			}
		}
		$items = array();
		if ( ! $matches ) {
			$items[] = array( 'status' => 'ok', 'label' => 'Sin coincidencias', 'detail' => 'No se detectaron patrones sospechosos en los archivos analizados.' );
		} else {
			$items[] = array( 'status' => 'warning', 'label' => 'Coincidencias a revisar', 'detail' => count( $matches ) . ' coincidencia(s) en archivos PHP. No son malware por sí mismas: revisa cada caso.' );
			foreach ( $matches as $match ) {
				$items[] = array( 'status' => 'warning', 'label' => $match['file'], 'detail' => 'Patrón: ' . $match['pattern'] );
			}
		}
		return array(
			'items'  => $items,
			'report' => 'Patrones sospechosos: ' . count( $matches ) . ' coincidencia(s) a revisar',
			'status' => self::overall_status( $items ),
		);
	}
	/* ====================== Reparaciones ====================== */

	public static function repair_rewrite( $params, $run ) {
		if ( ! $run ) {
			return array(
				'title' => 'Regenerar rewrite rules',
				'items' => array(
					array(
						'id'     => 'rewrite',
						'label'  => 'flush_rewrite_rules()',
						'detail' => 'Regenerará las reglas de enlaces permanentes. Acción reversible y sin pérdida de datos.',
						'params' => array(),
					),
				),
			);
		}
		flush_rewrite_rules( true );
		$structure = (string) get_option( 'permalink_structure' );
		$rules     = get_option( 'rewrite_rules' );
		$count     = is_array( $rules ) ? count( $rules ) : 0;
		if ( '' === $structure ) {
			return array(
				'success'  => true,
				'message'  => 'Rewrite rules regeneradas (enlaces permanentes en modo plano: no se almacenan reglas).',
				'target'   => 'rewrite_rules',
				'verified' => true,
			);
		}
		if ( $count > 0 ) {
			return array(
				'success'  => true,
				'message'  => 'Rewrite rules regeneradas y verificadas (' . $count . ' reglas).',
				'target'   => 'rewrite_rules',
				'verified' => true,
			);
		}
		return array(
			'success'  => false,
			'message'  => '✕ flush_rewrite_rules() se ejecutó pero no se generaron reglas de reescritura.',
			'target'   => 'rewrite_rules',
			'verified' => false,
		);
	}

	/**
	 * Eventos reales de WP-Cron identificados por timestamp + hook + args.
	 */
	private static function cron_events() {
		$crons  = _get_cron_array();
		$events = array();
		if ( ! is_array( $crons ) ) {
			return $events;
		}
		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $hook_events ) {
				foreach ( $hook_events as $event ) {
					$args     = ( isset( $event['args'] ) && is_array( $event['args'] ) ) ? $event['args'] : array();
					$events[] = array(
						'timestamp' => (int) $timestamp,
						'hook'      => (string) $hook,
						'args'      => $args,
						'signature' => self::cron_signature( $timestamp, $hook, $args ),
					);
				}
			}
		}
		return $events;
	}

	/**
	 * Comprueba si un evento concreto (timestamp + hook + args) sigue existiendo.
	 */
	private static function cron_event_exists( $timestamp, $hook, $args ) {
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return false;
		}
		$key = md5( serialize( $args ) );
		return isset( $crons[ (int) $timestamp ][ $hook ][ $key ] );
	}

	/**
	 * Número de eventos programados que comparten hook y args.
	 */
	private static function cron_occurrences( $hook, $args ) {
		$count = 0;
		foreach ( self::cron_events() as $event ) {
			if ( $event['hook'] === $hook && $event['args'] === $args ) {
				++$count;
			}
		}
		return $count;
	}

	public static function repair_cron( $params, $run ) {
		$events = self::cron_events();
		if ( ! $run ) {
			$items = array();
			foreach ( $events as $event ) {
				$args_label = empty( $event['args'] ) ? 'sin args' : 'args: ' . wp_json_encode( $event['args'] );
				$items[]    = array(
					'id'     => $event['signature'],
					'label'  => $event['hook'],
					'detail' => 'timestamp ' . $event['timestamp'] . ' (' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $event['timestamp'] ), 'd/m/Y H:i' ) . ') · ' . $args_label,
					'params' => array( 'signature' => $event['signature'] ),
				);
			}
			if ( ! $items ) {
				$items[] = array( 'id' => '', 'label' => 'Sin tareas programadas', 'detail' => 'No hay tareas WP-Cron que eliminar.', 'params' => array() );
			}
			return array( 'title' => 'Eliminar una tarea WP-Cron', 'items' => $items );
		}

		$signature = isset( $params['signature'] ) ? sanitize_text_field( $params['signature'] ) : '';
		if ( '' === $signature ) {
			return array( 'success' => false, 'message' => 'No se indicó ninguna tarea que eliminar.' );
		}

		$target = null;
		foreach ( $events as $event ) {
			if ( $event['signature'] === $signature ) {
				$target = $event;
				break;
			}
		}
		if ( ! $target ) {
			return array( 'success' => false, 'message' => '✕ La tarea seleccionada ya no existe en la lista de WP-Cron.' );
		}

		$label = 'Cron ' . $target['hook'] . ' (timestamp ' . $target['timestamp'] . ')';

		// wp_unschedule_event() espera los args reales del evento, no la entrada completa.
		$result = wp_unschedule_event( $target['timestamp'], $target['hook'], $target['args'] );

		// Verificación posterior: el evento concreto debe haber desaparecido.
		$still = self::cron_event_exists( $target['timestamp'], $target['hook'], $target['args'] );
		if ( ! $still ) {
			$message = '✓ Tarea eliminada correctamente: ' . $label . '.';
			$others  = self::cron_occurrences( $target['hook'], $target['args'] );
			if ( $others > 0 ) {
				$message .= ' Quedan ' . $others . ' evento(s) con el mismo hook y args.';
			} elseif ( false === wp_next_scheduled( $target['hook'], $target['args'] ) ) {
				$message .= ' wp_next_scheduled() ya no devuelve esta tarea.';
			}
			return array(
				'success'  => true,
				'message'  => $message,
				'target'   => $label,
				'verified' => true,
			);
		}

		$message = '✕ No se pudo eliminar la tarea: ' . $label . '.';
		if ( false === $result ) {
			$message .= ' wp_unschedule_event() devolvió false.';
		}
		$message .= ' El evento sigue programado.';
		return array(
			'success'  => false,
			'message'  => $message,
			'target'   => $label,
			'verified' => false,
		);
	}

	public static function repair_transient( $params, $run ) {
		global $wpdb;
		if ( ! $run ) {
			$items = array();
			$rows  = $wpdb->get_results(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_transient\\_timeout\\_%' ORDER BY option_name LIMIT 200"
			);
			foreach ( $rows as $row ) {
				$name    = substr( $row->option_name, strlen( '_transient_' ) );
				$items[] = array(
					'id'     => $name,
					'label'  => $name,
					'detail' => 'Transient almacenado en la tabla de opciones.',
					'params' => array( 'name' => $name ),
				);
			}
			if ( ! $items ) {
				$items[] = array( 'id' => '', 'label' => 'Sin transients', 'detail' => 'No se encontraron transients que eliminar.', 'params' => array() );
			}
			return array( 'title' => 'Eliminar un transient', 'items' => $items );
		}

		$name = isset( $params['name'] ) ? sanitize_key( $params['name'] ) : '';
		if ( '' === $name ) {
			return array( 'success' => false, 'message' => 'No se indicó ningún transient.' );
		}

		$scope   = 'Transient';
		$deleted = delete_transient( $name );
		if ( ! $deleted ) {
			$deleted = delete_site_transient( $name );
			$scope   = 'Site transient';
		}

		// Verificación posterior: no quedan filas asociadas al transient.
		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s OR option_name = %s",
				'_transient_' . $name,
				'_site_transient_' . $name
			)
		);

		if ( $deleted && 0 === $remaining ) {
			return array(
				'success'  => true,
				'message'  => '✓ ' . $scope . ' ' . $name . ' eliminado correctamente (verificado).',
				'target'   => $scope . ' ' . $name,
				'verified' => true,
			);
		}
		if ( $deleted ) {
			return array(
				'success'  => true,
				'message'  => $scope . ' ' . $name . ' eliminado, pero todavía quedan ' . $remaining . ' fila(s) asociadas en la tabla de opciones.',
				'target'   => $scope . ' ' . $name,
				'verified' => false,
			);
		}
		return array(
			'success'  => false,
			'message'  => '✕ No se pudo eliminar el transient ' . $name . ' (delete_transient() y delete_site_transient() devolvieron false).',
			'target'   => 'Transient ' . $name,
			'verified' => false,
		);
	}
	/* ====================== Historial ====================== */

	private static function current_user_label() {
		$user = wp_get_current_user();
		return $user && $user->exists() ? $user->user_login : 'desconocido';
	}

	public static function add_history( $entry ) {
		$history   = self::get_history();
		$entry     = wp_parse_args( $entry, array(
			'time'    => time(),
			'user'    => self::current_user_label(),
			'tool_id' => '',
			'tool'    => '',
			'type'    => 'diagnostic',
			'status'  => 'info',
			'summary' => '',
			'changes' => '',
		) );
		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, self::MAX_HISTORY );
		update_option( self::OPT_HISTORY, $history, false );
		return $entry;
	}

	public static function get_history() {
		$history = get_option( self::OPT_HISTORY, array() );
		return is_array( $history ) ? $history : array();
	}

	public static function clear_history() {
		delete_option( self::OPT_HISTORY );
	}

	/**
	 * Tipos de historial que representan una acción sobre la instalación.
	 */
	private static function is_action_type( $type ) {
		return in_array( (string) $type, array( 'repair', 'php' ), true );
	}

	/**
	 * Formatea una entrada del historial como línea del informe.
	 *
	 * Nunca incluye código PHP, contraseñas, tokens ni valores sensibles.
	 */
	public static function report_action_line( $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['time'] ) ) {
			return '';
		}
		$time = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ), 'd/m/Y H:i' );
		$text = ( isset( $entry['changes'] ) && '' !== trim( (string) $entry['changes'] ) )
			? (string) $entry['changes']
			: ( isset( $entry['summary'] ) ? (string) $entry['summary'] : '' );
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		$text = self::redact_text( $text );
		if ( strlen( $text ) > 200 ) {
			$text = wp_html_excerpt( $text, 200, '…' );
		}
		$ok = ( isset( $entry['status'] ) && 'ok' === $entry['status'] );
		return $time . ' — ' . $text . ' — ' . ( $ok ? 'OK' : 'ERROR' );
	}

	/**
	 * Últimas acciones que modificaron (o intentaron modificar) la instalación.
	 *
	 * @return string[] Líneas en orden cronológico (máximo $limit).
	 */
	public static function report_action_lines( $limit = self::MAX_REPORT_ACTIONS ) {
		$limit = max( 1, (int) $limit );
		$lines = array();
		foreach ( self::get_history() as $entry ) {
			if ( ! self::is_action_type( isset( $entry['type'] ) ? $entry['type'] : '' ) ) {
				continue;
			}
			$line = self::report_action_line( $entry );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
			if ( count( $lines ) >= $limit ) {
				break;
			}
		}
		// El historial se guarda del más reciente al más antiguo.
		return array_reverse( $lines );
	}

	/**
	 * Construye el texto del informe a partir del historial más reciente.
	 */
	public static function build_report() {
		$history = self::get_history();
		$lines   = array( 'PREMIERO DIAGNÓSTICO', '' );
		$seen    = array();
		foreach ( $history as $entry ) {
			if ( empty( $entry['tool_id'] ) || isset( $seen[ $entry['tool_id'] ] ) ) {
				continue;
			}
			$seen[ $entry['tool_id'] ] = true;
			$lines[] = ( isset( $entry['summary'] ) && '' !== $entry['summary'] )
				? $entry['summary']
				: $entry['tool'];
		}
		if ( empty( $seen ) ) {
			$lines[] = 'Sin resultados. Ejecuta algún diagnóstico desde la pestaña Diagnóstico.';
		}

		$actions = self::report_action_lines();
		$lines[] = '';
		$lines[] = 'ACCIONES / REPARACIONES RECIENTES';
		$lines[] = '';
		if ( $actions ) {
			$lines = array_merge( $lines, $actions );
		} else {
			$lines[] = '(sin acciones registradas)';
		}
		return implode( "\n", $lines );
	}
	/* ====================== Renderizado de resultados ====================== */

	private static function render_items( $items ) {
		if ( empty( $items ) ) {
			return '<p>Sin elementos.</p>';
		}
		$html = '<ul class="premiero-diag-items">';
		foreach ( $items as $item ) {
			$status = isset( $item['status'] ) ? $item['status'] : 'info';
			$label  = isset( $item['label'] ) ? $item['label'] : '';
			$detail = isset( $item['detail'] ) ? $item['detail'] : '';
			$html  .= '<li class="premiero-diag-status-' . esc_attr( $status ) . '">';
			$html  .= '<strong>' . esc_html( $label ) . '</strong>';
			if ( '' !== $detail ) {
				$html .= ' — <span>' . esc_html( $detail ) . '</span>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
		return $html;
	}

	public static function render_diagnostic_html( $tool, $result ) {
		list( $ok, $warning, $problem ) = self::summarize( isset( $result['items'] ) ? $result['items'] : array() );
		$html  = '<div class="premiero-diag-result">';
		$html .= '<p class="premiero-diag-summary">';
		$html .= '<span class="premiero-diag-count ok">' . $ok . ' correctos</span> · ';
		$html .= '<span class="premiero-diag-count warning">' . $warning . ' a revisar</span> · ';
		$html .= '<span class="premiero-diag-count problem">' . $problem . ' problemas</span>';
		$html .= '</p>';
		$html .= self::render_items( isset( $result['items'] ) ? $result['items'] : array() );
		$html .= '</div>';
		return $html;
	}

	public static function render_repair_preview_html( $result ) {
		$title = isset( $result['title'] ) ? $result['title'] : 'Reparación';
		$html  = '<div class="premiero-diag-result">';
		$html .= '<p class="premiero-diag-summary">Selecciona una opción y confirma para ejecutar. No se aplica nada automáticamente.</p>';
		$html .= '<ul class="premiero-diag-items">';
		foreach ( $result['items'] as $item ) {
			$html .= '<li class="premiero-diag-status-info">';
			$html .= '<label><input type="radio" name="premiero-repair-option" value="' . esc_attr( wp_json_encode( $item['params'] ) ) . '" data-id="' . esc_attr( $item['id'] ) . '"> ';
			$html .= '<strong>' . esc_html( $item['label'] ) . '</strong>';
			if ( ! empty( $item['detail'] ) ) {
				$html .= ' — <span>' . esc_html( $item['detail'] ) . '</span>';
			}
			$html .= '</label></li>';
		}
		$html .= '</ul></div>';
		return $html;
	}

	public static function render_message_html( $success, $message ) {
		$class = $success ? 'notice notice-success inline' : 'notice notice-error inline';
		return '<div class="' . $class . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	public static function render_php_html( $output ) {
		$output = self::redact_text( (string) $output );
		if ( '' === trim( $output ) ) {
			$output = '(sin salida)';
		}
		return '<pre class="premiero-diag-php">' . esc_html( $output ) . '</pre>';
	}
	/* ====================== AJAX ====================== */

	private static function ajax_guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permisos insuficientes.' ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! self::is_enabled() ) {
			wp_send_json_error( array( 'message' => 'El modo diagnóstico avanzado está desactivado.' ), 403 );
		}
	}

	public static function ajax_run_diag() {
		self::ajax_guard();
		$tool_id = isset( $_POST['tool'] ) ? sanitize_key( $_POST['tool'] ) : '';
		$tool    = self::get_tool( $tool_id );
		if ( ! $tool || 'diagnostic' !== $tool['type'] ) {
			wp_send_json_error( array( 'message' => 'Diagnóstico no válido.' ) );
		}
		$result = call_user_func( $tool['callback'] );
		$html   = self::render_diagnostic_html( $tool, $result );
		$entry  = self::add_history( array(
			'tool_id' => $tool['id'],
			'tool'    => $tool['name'],
			'type'    => 'diagnostic',
			'status'  => isset( $result['status'] ) ? $result['status'] : 'info',
			'summary' => isset( $result['report'] ) ? $result['report'] : $tool['name'],
		) );
		wp_send_json_success( array(
			'html'    => $html,
			'status'  => isset( $result['status'] ) ? $result['status'] : 'info',
			'summary' => isset( $result['report'] ) ? $result['report'] : $tool['name'],
			'env'     => isset( $result['env'] ) ? $result['env'] : null,
			'history' => $entry,
		) );
	}

	public static function ajax_run_repair() {
		self::ajax_guard();
		$tool_id = isset( $_POST['tool'] ) ? sanitize_key( $_POST['tool'] ) : '';
		$tool    = self::get_tool( $tool_id );
		if ( ! $tool || 'repair' !== $tool['type'] ) {
			wp_send_json_error( array( 'message' => 'Reparación no válida.' ) );
		}
		$run    = ! empty( $_POST['run'] );
		$params = isset( $_POST['params'] ) ? json_decode( wp_unslash( $_POST['params'] ), true ) : array();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$result = call_user_func( $tool['callback'], $params, $run );

		if ( ! $run ) {
			wp_send_json_success( array(
				'html' => self::render_repair_preview_html( $result ),
				'mode' => 'preview',
			) );
		}

		$success = ! empty( $result['success'] );
		$message = isset( $result['message'] ) ? $result['message'] : '';
		$entry   = self::add_history( array(
			'tool_id' => $tool['id'],
			'tool'    => $tool['name'],
			'type'    => 'repair',
			'status'  => $success ? 'ok' : 'problem',
			'summary' => $tool['name'] . ': ' . ( $success ? 'OK' : 'fallo' ),
			'changes' => $message,
			'target'  => isset( $result['target'] ) ? $result['target'] : $tool['name'],
			'verified' => ! empty( $result['verified'] ),
		) );
		wp_send_json_success( array(
			'html'        => self::render_message_html( $success, $message ),
			'mode'        => 'result',
			'success'     => $success,
			'history'     => $entry,
			'action_line' => self::report_action_line( $entry ),
		) );
	}

	public static function ajax_run_php() {
		self::ajax_guard();
		if ( empty( $_POST['confirm'] ) ) {
			wp_send_json_error( array( 'message' => 'Debes confirmar la ejecución.' ), 403 );
		}
		$code = (string) wp_unslash( $_POST['code'] ?? '' );
		if ( '' === trim( $code ) ) {
			wp_send_json_error( array( 'message' => 'No hay código que ejecutar.' ) );
		}
		$code = preg_replace( '/^\s*<\?php\s*/i', '', $code );
		$code = preg_replace( '/^\s*<\?\s*/', '', $code );
		$code = preg_replace( '/\?>\s*$/', '', $code );

		$output = '';
		ob_start();
		set_error_handler( function ( $errno, $errstr, $errfile, $errline ) use ( &$output ) {
			$output .= '[PHP ' . $errno . '] ' . $errstr . ' en ' . $errfile . ':' . $errline . "\n";
			return true;
		} );
		try {
			$return = eval( $code );
			if ( is_scalar( $return ) && '' !== (string) $return ) {
				$output .= (string) $return . "\n";
			}
		} catch ( \Throwable $e ) {
			$output .= '[Excepción] ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine() . "\n";
		}
		restore_error_handler();
		$output .= ob_get_clean();

		$has_error = (bool) preg_match( '/(\[Excepción\]|\[PHP \d|Fatal error|Parse error|Uncaught)/i', $output );
		$entry     = self::add_history( array(
			'tool_id' => '',
			'tool'    => 'Consola PHP',
			'type'    => 'php',
			'status'  => $has_error ? 'problem' : 'ok',
			'summary' => 'Consola PHP personalizada ejecutada — ' . ( $has_error ? 'ERROR' : 'OK' ),
			'changes' => 'Consola PHP personalizada ejecutada',
			'target'  => 'Consola PHP personalizada',
		) );

		wp_send_json_success( array(
			'html'        => self::render_php_html( $output ),
			'status'      => 'info',
			'history'     => $entry,
			'action_line' => self::report_action_line( $entry ),
		) );
	}

	public static function ajax_clear_history() {
		self::ajax_guard();
		self::clear_history();
		wp_send_json_success( array( 'message' => 'Historial limpiado.' ) );
	}

	public static function ajax_toggle_log() {
		self::ajax_guard();
		$enabled = ! empty( $_POST['enabled'] );
		update_option( self::OPT_TEMP_LOG, $enabled ? 1 : 0, false );

		$details = array();
		if ( $enabled ) {
			// Aplica el registro en esta petición y comprueba el destino real.
			self::bootstrap_temp_log();
			$check = self::ensure_temp_log_file();
			if ( $check['ok'] ) {
				$write     = self::write_temp_log_entry( 'Registro temporal de errores activado.' );
				$details[] = $write['written']
					? 'Archivo de registro listo: ' . $write['path']
					: 'No se pudo escribir la entrada inicial en ' . $write['path'];
			} else {
				$details[] = $check['message'];
			}
		}

		$status  = self::temp_log_status();
		$message = 'Registro temporal de errores ' . ( $enabled ? 'activado' : 'desactivado' ) . '.';
		if ( $enabled && ! $status['active'] ) {
			$message .= ' Aviso: no se pudo aplicar el destino real (revisa log_errors y la ruta actual).';
		}

		self::add_history( array(
			'tool_id' => 'temp_log',
			'tool'    => 'Registro temporal de errores',
			'type'    => 'maintenance',
			'status'  => $enabled ? ( $status['active'] ? 'ok' : 'warning' ) : 'info',
			'summary' => $message . ( $details ? ' ' . implode( ' ', $details ) : '' ),
		) );

		wp_send_json_success( self::temp_log_payload( array(
			'message' => $message,
			'details' => $details,
			'log_html' => self::render_diagnostic_html( self::get_tool( 'debug_log' ), self::diag_debug_log() ),
		) ) );
	}

	/**
	 * Escribe una entrada de prueba mediante error_log() y devuelve el visor
	 * actualizado. Requiere manage_options, nonce, modo diagnóstico y registro
	 * temporal activos.
	 */
	public static function ajax_test_log() {
		self::ajax_guard();
		if ( ! self::is_temp_log_enabled() ) {
			wp_send_json_error( array( 'message' => 'El registro temporal de errores está desactivado.' ), 403 );
		}
		self::bootstrap_temp_log();
		$check = self::ensure_temp_log_file();
		if ( ! $check['ok'] ) {
			wp_send_json_error( array( 'message' => $check['message'] ) );
		}
		$write = self::write_temp_log_entry( 'Entrada de prueba' );
		wp_send_json_success( self::temp_log_payload( array(
			'written'  => (bool) $write['written'],
			'line'     => $write['line'],
			'message'  => $write['written']
				? 'Entrada de prueba escrita en ' . $write['path'] . '.'
				: 'No se pudo escribir la entrada de prueba en ' . $write['path'] . '.',
			'log_html' => self::render_diagnostic_html( self::get_tool( 'debug_log' ), self::diag_debug_log() ),
		) ) );
	}

	public static function ajax_read_log() {
		self::ajax_guard();
		$result = self::diag_debug_log();
		wp_send_json_success( array(
			'html' => self::render_diagnostic_html( self::get_tool( 'debug_log' ), $result ),
		) );
	}

	public static function ajax_clear_log() {
		self::ajax_guard();
		$path = self::temp_log_path();
		if ( ! file_exists( $path ) ) {
			wp_send_json_success( array( 'message' => 'No existe debug.log; no hay nada que vaciar.' ) );
		}
		if ( ! is_writable( $path ) ) {
			wp_send_json_error( array( 'message' => 'debug.log no es escribible.' ) );
		}
		if ( false === @file_put_contents( $path, '' ) ) {
			wp_send_json_error( array( 'message' => 'No se pudo vaciar debug.log.' ) );
		}
		self::add_history( array(
			'tool_id' => 'debug_log_clear',
			'tool'    => 'Vaciar debug.log',
			'type'    => 'maintenance',
			'status'  => 'ok',
			'summary' => 'debug.log vaciado',
		) );
		wp_send_json_success( array( 'message' => 'debug.log vaciado.' ) );
	}
}
