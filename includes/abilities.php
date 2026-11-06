<?php
/**
 * Abilities de Premiero Admin Toolkit (Abilities API del núcleo, WP 6.9+).
 *
 * Cada ability registrada aquí la convierte en herramienta MCP cualquier
 * plugin que exponga la Abilities API (por ejemplo Easy MCP AI, como
 * wp_ability_premiero_<nombre>). En WordPress anterior a 6.9 los hooks no se
 * disparan y este archivo no hace nada, salvo las redirecciones propias.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/abilities/class-premiero-abilities-web.php';
require_once __DIR__ . '/abilities/class-premiero-abilities-seo.php';
require_once __DIR__ . '/class-premiero-redirects.php';

final class Premiero_Abilities {

	const CATEGORY = 'premiero';
	const ORIGIN   = ' (MCP)';

	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'Premiero Admin Toolkit',
				'description' => 'Herramientas de administración de Premiero: Telegram, código personalizado, monitorización, diagnóstico, SEO y redirecciones.',
			)
		);
	}

	/* =========================================================
	 *  Utilidades de registro
	 * ========================================================= */

	/**
	 * Registra una ability con los valores comunes del Toolkit.
	 *
	 * @param string $name        Nombre sin namespace.
	 * @param array  $args        label, description, execute, input, output, annotations, permission.
	 */
	public static function add( $name, $args ) {
		$input = isset( $args['input'] ) ? $args['input'] : array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
			'default'              => array(),
		);

		wp_register_ability(
			'premiero/' . $name,
			array(
				'label'               => $args['label'],
				'description'         => $args['description'],
				'category'            => self::CATEGORY,
				'input_schema'        => $input,
				'output_schema'       => $args['output'],
				'execute_callback'    => $args['execute'],
				'permission_callback' => isset( $args['permission'] ) ? $args['permission'] : array( __CLASS__, 'can_manage' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => $args['annotations'],
				),
			)
		);
	}

	/** Anotaciones de solo lectura. */
	public static function readonly() {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/** Anotaciones de escritura. */
	public static function writes( $destructive, $idempotent ) {
		return array( 'readonly' => false, 'destructive' => (bool) $destructive, 'idempotent' => (bool) $idempotent );
	}

	/** Esquema de objeto. */
	public static function obj( $properties, $required = array(), $strict = false ) {
		$schema = array(
			'type'       => 'object',
			'properties' => $properties,
		);
		if ( $required ) {
			$schema['required'] = $required;
		}
		if ( $strict ) {
			$schema['additionalProperties'] = false;
		}
		return $schema;
	}

	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/*
	 * Los interruptores del Toolkit se comprueban dentro de la ejecución (no en
	 * permission_callback) para que el agente reciba el motivo concreto: la API
	 * sustituye los errores de permisos por un mensaje genérico.
	 */
	public static function diagnostics_guard() {
		if ( ! Premiero_Diagnostics::is_enabled() ) {
			return new WP_Error( 'premiero_diag_disabled', 'El modo diagnóstico avanzado está desactivado. Actívalo en Premiero > Acerca de.' );
		}
		return true;
	}

	public static function mcp_actions_guard() {
		$base = self::diagnostics_guard();
		if ( true !== $base ) {
			return $base;
		}
		if ( ! Premiero_Diagnostics::is_mcp_php_enabled() ) {
			return new WP_Error( 'premiero_mcp_actions_disabled', 'La consola PHP y las reparaciones por MCP están desactivadas. Actívalas en Premiero > Acerca de > «Permitir consola PHP y reparaciones por MCP».' );
		}
		return true;
	}

	/* =========================================================
	 *  Registro
	 * ========================================================= */

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_telegram();
		self::register_code();
		self::register_monitoring();
		self::register_diagnostics();
		Premiero_Abilities_Web::register();
		Premiero_Abilities_Seo::register();
		Premiero_Redirects::register_ability();
	}

	/* ---------------- Telegram ---------------- */

	private static function register_telegram() {
		self::add( 'telegram-status', array(
			'label'       => 'Estado de los avisos de Telegram',
			'description' => 'Indica si los avisos de Telegram de esta web están activos, si las credenciales son válidas y qué avisos y supresiones de email están marcados. Nunca devuelve el token.',
			'annotations' => self::readonly(),
			'execute'     => array( __CLASS__, 'telegram_status' ),
			'output'      => self::obj( array(
				'enabled'           => array( 'type' => 'boolean' ),
				'credentials_valid' => array( 'type' => 'boolean' ),
				'active'            => array( 'type' => 'boolean' ),
				'chat_id'           => array( 'type' => 'string', 'description' => 'Chat ID parcialmente oculto.' ),
				'events'            => array( 'type' => 'object', 'description' => 'Aviso => marcado.' ),
				'emails_suppressed' => array( 'type' => 'object', 'description' => 'Email de WordPress => suprimido ahora mismo.' ),
			) ),
		) );

		self::add( 'telegram-send', array(
			'label'       => 'Enviar mensaje por Telegram',
			'description' => 'Envía un mensaje de texto plano al chat de Telegram configurado en esta web. El nombre de la web se añade automáticamente como primera línea. Úsalo para avisos o informes referentes a esta web.',
			'annotations' => self::writes( false, false ),
			'execute'     => array( __CLASS__, 'telegram_send' ),
			'input'       => self::obj( array(
				'title' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 300, 'description' => 'Primera línea del mensaje.' ),
				'lines' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 100, 'description' => 'Líneas siguientes.' ),
			), array( 'title' ), true ),
			'output'      => self::obj( array(
				'ok'          => array( 'type' => 'boolean' ),
				'code'        => array( 'type' => 'integer' ),
				'description' => array( 'type' => 'string' ),
			) ),
		) );
	}

	public static function telegram_status() {
		$settings    = Premiero_Telegram::settings();
		$credentials = Premiero_Telegram::credentials();
		$chat_id     = (string) $credentials['chat_id'];

		$events = array();
		$emails = array();
		foreach ( $settings as $key => $value ) {
			if ( 0 === strpos( $key, 'event_' ) ) {
				$events[ $key ] = ! empty( $value );
			} elseif ( 0 === strpos( $key, 'email_' ) ) {
				$emails[ $key ] = Premiero_Telegram::email_is_suppressed( $key );
			}
		}

		return array(
			'enabled'           => ! empty( $settings['enabled'] ),
			'credentials_valid' => Premiero_Telegram::has_valid_credentials(),
			'active'            => Premiero_Telegram::is_active(),
			'chat_id'           => strlen( $chat_id ) > 4 ? str_repeat( '•', strlen( $chat_id ) - 4 ) . substr( $chat_id, -4 ) : $chat_id,
			'events'            => $events,
			'emails_suppressed' => $emails,
		);
	}

	public static function telegram_send( $input ) {
		if ( ! Premiero_Telegram::is_active() ) {
			return new WP_Error( 'premiero_telegram_inactive', 'Telegram no está activo en esta web o sus credenciales no son válidas. Configúralo en Premiero > Telegram.' );
		}
		$lines = isset( $input['lines'] ) ? array_map( 'sanitize_textarea_field', (array) $input['lines'] ) : array();
		return Premiero_Telegram_Sender::send( sanitize_text_field( $input['title'] ), $lines );
	}

	/* ---------------- Código ---------------- */

	private static function code_targets() {
		return array(
			'php'       => PREMIERO_OPT_SNIPPETS,
			'css'       => PREMIERO_OPT_CSS,
			'head_html' => PREMIERO_OPT_HEAD_HTML,
			'body_html' => PREMIERO_OPT_BODY_HTML,
		);
	}

	private static function register_code() {
		$code_props = array(
			'php'       => array( 'type' => 'string', 'description' => 'Snippets PHP (MU-plugin premiero-snippets.php), sin cabecera.' ),
			'css'       => array( 'type' => 'string', 'description' => 'CSS cargado en el frontend.' ),
			'head_html' => array( 'type' => 'string', 'description' => 'HTML inyectado en <head>.' ),
			'body_html' => array( 'type' => 'string', 'description' => 'HTML inyectado al inicio de <body>.' ),
		);

		self::add( 'code-get', array(
			'label'       => 'Ver código personalizado',
			'description' => 'Devuelve el código personalizado de la pestaña Código: snippets PHP, HTML de <head>, HTML de <body> y CSS.',
			'annotations' => self::readonly(),
			'execute'     => array( __CLASS__, 'code_get' ),
			'output'      => self::obj( $code_props ),
		) );

		self::add( 'code-update', array(
			'label'       => 'Escribir código personalizado',
			'description' => 'Escribe en uno de los bloques de la pestaña Código. Por defecto AÑADE el contenido al final (mode=append). Usa mode=replace_fragment para sustituir un fragmento exacto (search, que debe aparecer una sola vez) y mode=replace solo para reemplazar el bloque entero. El PHP se valida antes de escribirse y se guarda copia .bak del MU-plugin anterior. Devuelve el contenido previo para poder restaurarlo.',
			'annotations' => self::writes( true, false ),
			'execute'     => array( __CLASS__, 'code_update' ),
			'input'       => self::obj( array(
				'target'  => array( 'type' => 'string', 'enum' => array_keys( self::code_targets() ) ),
				'mode'    => array( 'type' => 'string', 'enum' => array( 'append', 'replace_fragment', 'replace' ), 'default' => 'append' ),
				'content' => array( 'type' => 'string', 'description' => 'Código a añadir, texto de sustitución o bloque completo según mode.' ),
				'search'  => array( 'type' => 'string', 'description' => 'Fragmento exacto a sustituir (solo mode=replace_fragment).' ),
			), array( 'target', 'content' ), true ),
			'output'      => self::obj( array(
				'target'   => array( 'type' => 'string' ),
				'mode'     => array( 'type' => 'string' ),
				'previous' => array( 'type' => 'string' ),
				'current'  => array( 'type' => 'string' ),
				'message'  => array( 'type' => 'string' ),
			) ),
		) );
	}

	public static function code_get() {
		$out = array();
		foreach ( self::code_targets() as $key => $option ) {
			$out[ $key ] = (string) get_option( $option, '' );
		}
		return $out;
	}

	public static function code_update( $input ) {
		$targets = self::code_targets();
		$target  = $input['target'];
		$mode    = isset( $input['mode'] ) ? $input['mode'] : 'append';
		$content = (string) $input['content'];
		$option  = $targets[ $target ];
		$before  = (string) get_option( $option, '' );

		switch ( $mode ) {
			case 'replace':
				$after = $content;
				break;
			case 'replace_fragment':
				$search = isset( $input['search'] ) ? (string) $input['search'] : '';
				if ( '' === $search ) {
					return new WP_Error( 'premiero_code_search', 'mode=replace_fragment necesita el fragmento exacto en search.' );
				}
				$count = substr_count( $before, $search );
				if ( 1 !== $count ) {
					return new WP_Error( 'premiero_code_search', 0 === $count ? 'El fragmento indicado en search no aparece en el bloque.' : 'El fragmento indicado en search aparece ' . $count . ' veces; amplíalo para que sea único.' );
				}
				$after = str_replace( $search, $content, $before );
				break;
			default:
				$after = '' === trim( $before ) ? $content : rtrim( $before ) . "\n\n" . $content;
		}

		if ( 'php' === $target ) {
			$result = premiero_write_mu_snippets( $after );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		update_option( $option, $after );

		return array(
			'target'   => $target,
			'mode'     => $mode,
			'previous' => $before,
			'current'  => $after,
			'message'  => 'php' === $target
				? 'Snippets guardados en wp-content/mu-plugins/premiero-snippets.php (copia anterior en premiero-snippets.php.bak).'
				: 'Bloque ' . $target . ' guardado.',
		);
	}

	/* ---------------- Monitorización ---------------- */

	private static function register_monitoring() {
		self::add( 'monitoring-status', array(
			'label'       => 'Estado de la monitorización',
			'description' => 'Devuelve el estado de la conexión de esta web con Premiero Maintenance Console: si está conectada, último envío, último código HTTP y error, fallos, próximos envíos y tamaños medidos. No envía nada.',
			'annotations' => self::readonly(),
			'execute'     => array( 'Premiero_Console_Client', 'status_summary' ),
			'output'      => self::obj( array(
				'connected'              => array( 'type' => 'boolean' ),
				'enabled'                => array( 'type' => 'boolean' ),
				'secret_readable'        => array( 'type' => 'boolean' ),
				'api_base'               => array( 'type' => 'string' ),
				'installation_id'        => array( 'type' => 'string' ),
				'remote_installation_id' => array( 'type' => 'string' ),
				'last_sent'              => array( 'type' => array( 'string', 'null' ) ),
				'last_http_code'         => array( 'type' => 'integer' ),
				'last_error'             => array( 'type' => array( 'object', 'string' ) ),
				'failures'               => array( 'type' => 'integer' ),
				'next_attempt'           => array( 'type' => array( 'string', 'null' ) ),
				'next_sync'              => array( 'type' => array( 'string', 'null' ) ),
				'sizes'                  => array( 'type' => 'object' ),
			) ),
		) );
	}

	/* ---------------- Diagnóstico ---------------- */

	private static function tool_ids( $type ) {
		$ids = array();
		foreach ( Premiero_Diagnostics::tools() as $id => $tool ) {
			if ( $type === $tool['type'] ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	private static function register_diagnostics() {
		self::add( 'diagnostics-list', array(
			'label'       => 'Listar herramientas de diagnóstico',
			'description' => 'Lista las comprobaciones y reparaciones de la pestaña Diagnóstico, si el modo diagnóstico está activo, si la consola PHP y las reparaciones por MCP están permitidas, y las últimas entradas del historial.',
			'annotations' => self::readonly(),
			'execute'     => array( __CLASS__, 'diagnostics_list' ),
			'output'      => self::obj( array(
				'diagnostics_enabled' => array( 'type' => 'boolean' ),
				'mcp_actions_enabled' => array( 'type' => 'boolean' ),
				'tools'               => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'history'             => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
			) ),
		) );

		self::add( 'diagnostics-run', array(
			'label'       => 'Ejecutar una comprobación de diagnóstico',
			'description' => 'Ejecuta una comprobación de solo lectura de la pestaña Diagnóstico (info, wp_config, htaccess, admin_users, active_plugins, mu_plugins, cron, debug_log, recent_php, malware_scan) y la anota en el historial. Requiere el modo diagnóstico activo.',
			'annotations' => self::readonly(),
			'execute'     => array( __CLASS__, 'diagnostics_run' ),
			'input'       => self::obj( array(
				'tool' => array( 'type' => 'string', 'enum' => self::tool_ids( 'diagnostic' ) ),
			), array( 'tool' ), true ),
			'output'      => self::obj( array(
				'tool'    => array( 'type' => 'string' ),
				'status'  => array( 'type' => 'string' ),
				'summary' => array( 'type' => 'string' ),
				'result'  => array( 'type' => 'object' ),
			) ),
		) );

		self::add( 'diagnostics-repair', array(
			'label'       => 'Reparación de diagnóstico',
			'description' => 'Reparaciones de la pestaña Diagnóstico (repair_rewrite, repair_cron, repair_transient). Con run=false (por defecto) devuelve la vista previa con los elementos posibles y sus params. Con run=true y los params de un elemento, aplica la reparación. Requiere el modo diagnóstico y el interruptor «Permitir consola PHP y reparaciones por MCP».',
			'annotations' => self::writes( true, false ),
			'execute'     => array( __CLASS__, 'diagnostics_repair' ),
			'input'       => self::obj( array(
				'tool'   => array( 'type' => 'string', 'enum' => self::tool_ids( 'repair' ) ),
				'run'    => array( 'type' => 'boolean', 'default' => false ),
				'params' => array( 'type' => 'object', 'default' => array(), 'description' => 'params del elemento elegido en la vista previa.' ),
			), array( 'tool' ), true ),
			'output'      => self::obj( array(
				'tool'   => array( 'type' => 'string' ),
				'mode'   => array( 'type' => 'string', 'enum' => array( 'preview', 'result' ) ),
				'result' => array( 'type' => 'object' ),
			) ),
		) );

		self::add( 'php-run', array(
			'label'       => 'Ejecutar código en la consola PHP',
			'description' => 'Ejecuta código PHP en la consola PHP personalizada de la pestaña Diagnóstico (eval dentro de WordPress) y devuelve la salida, errores incluidos. Úsalo solo cuando ninguna otra herramienta sirva. Requiere el modo diagnóstico y el interruptor «Permitir consola PHP y reparaciones por MCP». Queda anotado en el historial.',
			'annotations' => self::writes( true, false ),
			'execute'     => array( __CLASS__, 'php_run' ),
			'input'       => self::obj( array(
				'code' => array( 'type' => 'string', 'minLength' => 1, 'description' => 'Código PHP. Puede usar echo o return para devolver resultados.' ),
			), array( 'code' ), true ),
			'output'      => self::obj( array(
				'output'    => array( 'type' => 'string' ),
				'has_error' => array( 'type' => 'boolean' ),
			) ),
		) );
	}

	public static function diagnostics_list() {
		$tools = array();
		foreach ( Premiero_Diagnostics::tools() as $tool ) {
			unset( $tool['callback'] );
			$tools[] = $tool;
		}
		return array(
			'diagnostics_enabled' => Premiero_Diagnostics::is_enabled(),
			'mcp_actions_enabled' => Premiero_Diagnostics::is_mcp_php_enabled(),
			'tools'               => $tools,
			'history'             => array_slice( Premiero_Diagnostics::get_history(), 0, 10 ),
		);
	}

	public static function diagnostics_run( $input ) {
		$guard = self::diagnostics_guard();
		if ( true !== $guard ) {
			return $guard;
		}
		$tool = Premiero_Diagnostics::get_tool( $input['tool'] );
		if ( ! $tool || 'diagnostic' !== $tool['type'] ) {
			return new WP_Error( 'premiero_diag_tool', 'Diagnóstico no válido.' );
		}
		$result  = (array) call_user_func( $tool['callback'] );
		$status  = isset( $result['status'] ) ? (string) $result['status'] : 'info';
		$summary = isset( $result['report'] ) ? (string) $result['report'] : $tool['name'];
		Premiero_Diagnostics::add_history( array(
			'tool_id' => $tool['id'],
			'tool'    => $tool['name'] . self::ORIGIN,
			'type'    => 'diagnostic',
			'status'  => $status,
			'summary' => $summary,
		) );
		return array(
			'tool'    => $tool['id'],
			'status'  => $status,
			'summary' => $summary,
			'result'  => $result,
		);
	}

	public static function diagnostics_repair( $input ) {
		$guard = self::mcp_actions_guard();
		if ( true !== $guard ) {
			return $guard;
		}
		$tool = Premiero_Diagnostics::get_tool( $input['tool'] );
		if ( ! $tool || 'repair' !== $tool['type'] ) {
			return new WP_Error( 'premiero_diag_tool', 'Reparación no válida.' );
		}
		$run    = ! empty( $input['run'] );
		$params = isset( $input['params'] ) && is_array( $input['params'] ) ? $input['params'] : array();
		$result = (array) call_user_func( $tool['callback'], $params, $run );

		if ( $run ) {
			$success = ! empty( $result['success'] );
			Premiero_Diagnostics::add_history( array(
				'tool_id'  => $tool['id'],
				'tool'     => $tool['name'] . self::ORIGIN,
				'type'     => 'repair',
				'status'   => $success ? 'ok' : 'problem',
				'summary'  => $tool['name'] . self::ORIGIN . ': ' . ( $success ? 'OK' : 'fallo' ),
				'changes'  => isset( $result['message'] ) ? $result['message'] : '',
				'target'   => isset( $result['target'] ) ? $result['target'] : $tool['name'],
				'verified' => ! empty( $result['verified'] ),
			) );
		}

		return array(
			'tool'   => $tool['id'],
			'mode'   => $run ? 'result' : 'preview',
			'result' => $result,
		);
	}

	public static function php_run( $input ) {
		$guard = self::mcp_actions_guard();
		if ( true !== $guard ) {
			return $guard;
		}
		$run = Premiero_Diagnostics::run_php( $input['code'], self::ORIGIN );
		return array(
			'output'    => Premiero_Diagnostics::redact_text( $run['output'] ),
			'has_error' => $run['has_error'],
		);
	}
}

Premiero_Abilities::init();
