<?php
/**
 * Redirecciones gestionadas por la ability premiero/redirect-manage.
 *
 * Si el plugin Redirection está activo se usan sus funciones. Si no, las
 * redirecciones se guardan en una opción propia y se aplican aquí en
 * template_redirect (301/302, coincidencia exacta de ruta).
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Redirects {

	const OPTION     = 'premiero_redirects';
	const MAX_CHAIN  = 20;
	const LIST_LIMIT = 500;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'apply' ), 1 );
	}

	/* =========================================================
	 *  Almacenamiento propio
	 * ========================================================= */

	public static function own_items() {
		$items = get_option( self::OPTION, array() );
		return is_array( $items ) ? array_values( $items ) : array();
	}

	private static function save_own_items( $items ) {
		update_option( self::OPTION, array_values( $items ), true );
	}

	public static function apply() {
		$items = self::own_items();
		if ( ! $items || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = self::normalize_path( wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $items as $item ) {
			if ( isset( $item['source'], $item['target'] ) && $item['source'] === $path ) {
				// Los destinos internos se guardan como ruta absoluta desde la raíz del dominio.
				wp_redirect( $item['target'], 302 === (int) $item['code'] ? 302 : 301, 'WordPress' ); // phpcs:ignore WordPress.Security.SafeRedirect
				exit;
			}
		}
	}

	/* =========================================================
	 *  Normalización y validación
	 * ========================================================= */

	/** Ruta absoluta desde la raíz del dominio, sin barra final (salvo "/"). */
	public static function normalize_path( $path ) {
		$path = '/' . ltrim( (string) $path, '/' );
		$path = preg_replace( '#/+#', '/', $path );
		return '/' === $path ? '/' : untrailingslashit( $path );
	}

	private static function site_hosts() {
		return array_unique( array_filter( array(
			strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
		) ) );
	}

	/**
	 * Ruta interna de una URL o ruta, o null si apunta a otro dominio.
	 */
	private static function internal_path( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( '/' === $value[0] && ( ! isset( $value[1] ) || '/' !== $value[1] ) ) {
			return self::normalize_path( wp_parse_url( $value, PHP_URL_PATH ) );
		}
		$parts = wp_parse_url( $value );
		if ( ! $parts || empty( $parts['host'] ) || ! in_array( strtolower( $parts['host'] ), self::site_hosts(), true ) ) {
			return null;
		}
		return self::normalize_path( isset( $parts['path'] ) ? $parts['path'] : '/' );
	}

	/** @return string|WP_Error Ruta de origen normalizada. */
	private static function validate_source( $source ) {
		$path = self::internal_path( $source );
		if ( null === $path ) {
			return new WP_Error( 'premiero_redirect_source', 'El origen debe ser una ruta de este sitio (por ejemplo /pagina-antigua/) o una URL de ' . implode( ', ', self::site_hosts() ) . '.' );
		}
		if ( '/' === $path ) {
			return new WP_Error( 'premiero_redirect_source', 'No se permite redirigir la portada.' );
		}
		if ( preg_match( '#^/(wp-admin|wp-login\.php|wp-json)(/|$)#', $path ) ) {
			return new WP_Error( 'premiero_redirect_source', 'No se permite redirigir rutas de administración, login o REST API.' );
		}
		return $path;
	}

	/** @return string|WP_Error Destino: ruta interna o URL absoluta http(s). */
	private static function validate_target( $target ) {
		$target = trim( (string) $target );
		if ( '' !== $target && '/' === $target[0] && ( ! isset( $target[1] ) || '/' !== $target[1] ) ) {
			// Se conserva tal cual (con o sin barra final); solo se normaliza para comparar rutas.
			$clean = esc_url_raw( $target );
			return '' !== $clean ? $clean : new WP_Error( 'premiero_redirect_target', 'El destino no es una ruta válida.' );
		}
		if ( preg_match( '#^https?://#i', $target ) && filter_var( $target, FILTER_VALIDATE_URL ) ) {
			return esc_url_raw( $target );
		}
		return new WP_Error( 'premiero_redirect_target', 'El destino debe ser una ruta que empiece por / o una URL absoluta http(s).' );
	}

	/**
	 * Detecta bucles: origen = destino o cadena de redirecciones que vuelve al origen.
	 *
	 * @param array $map Ruta origen => destino de las redirecciones existentes.
	 */
	private static function detect_loop( $source, $target, $map ) {
		$chain = array( $source );
		$next  = self::internal_path( $target );
		for ( $i = 0; null !== $next && $i < self::MAX_CHAIN; $i++ ) {
			$chain[] = $next;
			if ( $next === $source ) {
				return new WP_Error( 'premiero_redirect_loop', 'La redirección crearía un bucle: ' . implode( ' → ', $chain ) . '.' );
			}
			if ( ! isset( $map[ $next ] ) ) {
				return true;
			}
			$next = self::internal_path( $map[ $next ] );
		}
		if ( null !== $next ) {
			return new WP_Error( 'premiero_redirect_loop', 'La cadena de redirecciones supera ' . self::MAX_CHAIN . ' saltos: ' . implode( ' → ', $chain ) . '.' );
		}
		return true;
	}

	/* =========================================================
	 *  Plugin Redirection
	 * ========================================================= */

	public static function redirection_active() {
		return class_exists( 'Red_Item' ) && defined( 'REDIRECTION_VERSION' );
	}

	/** Redirection crea sus tablas al completar su asistente; antes no se puede usar. */
	private static function redirection_ready() {
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_items';
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB
	}

	private static function redirection_items() {
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_items';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, url, action_type, action_code, action_data, match_type, regex, status, group_id FROM {$table} ORDER BY id DESC LIMIT %d", self::LIST_LIMIT ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$items = array();
		foreach ( (array) $rows as $row ) {
			$target = maybe_unserialize( $row['action_data'] );
			if ( is_array( $target ) ) {
				$target = isset( $target['url'] ) ? $target['url'] : wp_json_encode( $target );
			}
			$items[] = array(
				'id'         => (int) $row['id'],
				'source'     => (string) $row['url'],
				'target'     => (string) $target,
				'code'       => (int) $row['action_code'],
				'match_type' => (string) $row['match_type'],
				'regex'      => (bool) $row['regex'],
				'status'     => (string) $row['status'],
				'group_id'   => (int) $row['group_id'],
			);
		}
		return $items;
	}

	private static function redirection_group_id() {
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_groups';
		return (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE module_id = 1 AND status = 'enabled' ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB
	}

	/* =========================================================
	 *  Ability
	 * ========================================================= */

	public static function register_ability() {
		$list = array( 'type' => 'array', 'items' => array( 'type' => 'object' ) );

		Premiero_Abilities::add( 'redirect-manage', array(
			'label'       => 'Gestionar redirecciones',
			'description' => 'Lista, crea o borra redirecciones 301/302 de rutas de este sitio. Si el plugin Redirection está activo usa sus funciones; si no, las guarda en el Toolkit y las aplica con coincidencia exacta de ruta. Valida que el origen sea de este sitio y rechaza bucles. Devuelve el estado antes y después.',
			'annotations' => Premiero_Abilities::writes( true, false ),
			'execute'     => array( __CLASS__, 'execute' ),
			'input'       => Premiero_Abilities::obj( array(
				'action' => array( 'type' => 'string', 'enum' => array( 'list', 'create', 'delete' ) ),
				'source' => array( 'type' => 'string', 'description' => 'create: ruta de origen de este sitio (/pagina-antigua/).' ),
				'target' => array( 'type' => 'string', 'description' => 'create: ruta interna o URL absoluta de destino.' ),
				'code'   => array( 'type' => 'integer', 'enum' => array( 301, 302 ), 'default' => 301 ),
				'id'     => array( 'type' => array( 'string', 'integer' ), 'description' => 'delete: ID de la redirección (de list).' ),
			), array( 'action' ), true ),
			'output'      => Premiero_Abilities::obj( array(
				'backend'        => array( 'type' => 'string', 'enum' => array( 'redirection', 'premiero' ) ),
				'action'         => array( 'type' => 'string' ),
				'message'        => array( 'type' => 'string' ),
				'item'           => array( 'type' => array( 'object', 'null' ) ),
				'before'         => $list,
				'after'          => $list,
				'premiero_items' => $list,
			) ),
		) );
	}

	public static function execute( $input ) {
		$backend = self::redirection_active() ? 'redirection' : 'premiero';
		$action  = $input['action'];
		if ( 'redirection' === $backend && ! self::redirection_ready() ) {
			return new WP_Error( 'premiero_redirection_setup', 'El plugin Redirection está activo pero no está configurado (no existen sus tablas). Completa su asistente en Herramientas > Redirection o desactívalo para que el Toolkit guarde las redirecciones.' );
		}
		$before  = 'redirection' === $backend ? self::redirection_items() : self::own_items();

		$result = array(
			'backend'        => $backend,
			'action'         => $action,
			'message'        => '',
			'item'           => null,
			'before'         => $before,
			'after'          => $before,
			'premiero_items' => 'redirection' === $backend ? self::own_items() : array(),
		);

		if ( 'list' === $action ) {
			$result['message'] = count( $before ) . ' redirecciones en ' . $backend . ( count( $before ) >= self::LIST_LIMIT && 'redirection' === $backend ? ' (se muestran las ' . self::LIST_LIMIT . ' más recientes)' : '' ) . '.';
			if ( $result['premiero_items'] ) {
				$result['message'] .= ' Además hay ' . count( $result['premiero_items'] ) . ' redirecciones guardadas en el Toolkit que se siguen aplicando.';
			}
			return $result;
		}

		$item = 'create' === $action ? self::create( $backend, $input ) : self::delete( $backend, $input );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$result['item']    = $item;
		$result['after']   = 'redirection' === $backend ? self::redirection_items() : self::own_items();
		$result['message'] = 'create' === $action
			? 'Redirección ' . $item['code'] . ' creada: ' . $item['source'] . ' → ' . $item['target'] . '.'
			: 'Redirección ' . $item['source'] . ' → ' . $item['target'] . ' eliminada.';
		return $result;
	}

	/** Mapa ruta => destino de todas las redirecciones exactas activas. */
	private static function current_map() {
		$map = array();
		foreach ( self::own_items() as $item ) {
			$map[ $item['source'] ] = $item['target'];
		}
		if ( self::redirection_active() && self::redirection_ready() ) {
			foreach ( self::redirection_items() as $item ) {
				if ( 'enabled' === $item['status'] && ! $item['regex'] && 'url' === $item['match_type'] ) {
					$path = self::internal_path( $item['source'] );
					if ( null !== $path ) {
						$map[ $path ] = $item['target'];
					}
				}
			}
		}
		return $map;
	}

	private static function create( $backend, $input ) {
		if ( empty( $input['source'] ) || empty( $input['target'] ) ) {
			return new WP_Error( 'premiero_redirect_input', 'Para crear una redirección hacen falta source y target.' );
		}
		$source = self::validate_source( $input['source'] );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$target = self::validate_target( $input['target'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$code = isset( $input['code'] ) && 302 === (int) $input['code'] ? 302 : 301;

		$map = self::current_map();
		if ( isset( $map[ $source ] ) ) {
			return new WP_Error( 'premiero_redirect_exists', 'Ya existe una redirección desde ' . $source . ' hacia ' . $map[ $source ] . '. Bórrala antes de crear otra.' );
		}
		$loop = self::detect_loop( $source, $target, $map );
		if ( is_wp_error( $loop ) ) {
			return $loop;
		}

		if ( 'redirection' === $backend ) {
			$group = self::redirection_group_id();
			if ( ! $group ) {
				return new WP_Error( 'premiero_redirect_group', 'Redirection no tiene ningún grupo de WordPress activo donde crear la redirección.' );
			}
			if ( ! method_exists( 'Red_Item', 'create' ) ) {
				return new WP_Error( 'premiero_redirect_api', 'La versión instalada de Redirection no ofrece Red_Item::create().' );
			}
			$created = Red_Item::create( array(
				'url'         => $source,
				'action_data' => array( 'url' => $target ),
				'action_type' => 'url',
				'action_code' => $code,
				'match_type'  => 'url',
				'group_id'    => $group,
				'regex'       => false,
				'title'       => 'Creada por Premiero Admin Toolkit (MCP)',
			) );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			if ( ! is_object( $created ) || ! method_exists( $created, 'get_id' ) ) {
				return new WP_Error( 'premiero_redirect_api', 'Redirection no confirmó la creación de la redirección.' );
			}
			return array( 'id' => (int) $created->get_id(), 'source' => $source, 'target' => $target, 'code' => $code );
		}

		$item    = array(
			'id'      => 'prm_' . wp_generate_password( 10, false, false ),
			'source'  => $source,
			'target'  => $target,
			'code'    => $code,
			'created' => time(),
		);
		$items   = self::own_items();
		$items[] = $item;
		self::save_own_items( $items );
		return $item;
	}

	private static function delete( $backend, $input ) {
		if ( ! isset( $input['id'] ) || '' === (string) $input['id'] ) {
			return new WP_Error( 'premiero_redirect_input', 'Para borrar una redirección hace falta su id (usa action=list).' );
		}
		$id = (string) $input['id'];

		// Las redirecciones propias se pueden borrar siempre, aunque Redirection esté activo.
		$items = self::own_items();
		foreach ( $items as $index => $item ) {
			if ( isset( $item['id'] ) && $item['id'] === $id ) {
				unset( $items[ $index ] );
				self::save_own_items( $items );
				return $item;
			}
		}

		if ( 'redirection' === $backend && ctype_digit( $id ) ) {
			if ( ! method_exists( 'Red_Item', 'get_by_id' ) ) {
				return new WP_Error( 'premiero_redirect_api', 'La versión instalada de Redirection no ofrece Red_Item::get_by_id().' );
			}
			$red = Red_Item::get_by_id( (int) $id );
			if ( ! $red ) {
				return new WP_Error( 'premiero_redirect_missing', 'No existe la redirección ' . $id . ' en Redirection.' );
			}
			$found = null;
			foreach ( self::redirection_items() as $row ) {
				if ( (int) $id === $row['id'] ) {
					$found = $row;
				}
			}
			$red->delete();
			return $found ? $found : array( 'id' => (int) $id, 'source' => method_exists( $red, 'get_url' ) ? $red->get_url() : '', 'target' => '', 'code' => 0 );
		}

		return new WP_Error( 'premiero_redirect_missing', 'No existe ninguna redirección con id ' . $id . '.' );
	}
}

Premiero_Redirects::init();
