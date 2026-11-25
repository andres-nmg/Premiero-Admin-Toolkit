<?php
/**
 * Pestaña "Restricciones de acceso": oculta menús y plugins a los
 * administradores que no tienen acceso total.
 *
 * Nada se escribe a mano: los elementos del menú se leen de $menu y $submenu
 * y los plugins de get_plugins(). En los ajustes solo se guardan slugs.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Access_Restrictions {

	const OPTION         = 'premiero_access_restrictions';
	const TAB            = 'access';
	const ACTION_SAVE    = 'premiero_access_save';
	const ACTION_RESCAN  = 'premiero_access_rescan';
	const SCAN_TRANSIENT = 'premiero_access_menu_scan';
	/** Mapa del menú (solo slugs) para resolver enlaces fuera de las pantallas del admin. */
	const MAP_OPTION     = 'premiero_access_menu_map';
	const NOTICES        = 'premiero_access_notices';
	const SCAN_TTL       = 600;
	const DENY_MESSAGE   = 'No tienes permisos para acceder a esta página.';

	/** Separador interno que añade la agrupación del menú. */
	const GROUP_SEPARATOR = 'premiero-separator';

	/** Capacidades de plugins que se retiran con "Bloquear plugins". */
	const PLUGIN_CAPS = array( 'activate_plugins', 'install_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins' );

	private static $settings    = null;
	private static $full_access = null;
	private static $restricted  = array();
	private static $structure   = null;
	private static $plugin_info = null;

	public static function init() {
		// La barra superior también se muestra viendo la web: se filtra en ambos sitios.
		add_action( 'wp_before_admin_bar_render', array( __CLASS__, 'filter_admin_bar' ), PHP_INT_MAX );

		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_RESCAN, array( __CLASS__, 'handle_rescan' ) );

		// Lectura del menú antes de aplicar las restricciones y aplicación al final.
		add_action( 'admin_menu', array( __CLASS__, 'capture_menu' ), PHP_INT_MAX - 1 );
		add_action( 'admin_menu', array( __CLASS__, 'apply_menu' ), PHP_INT_MAX );

		// Bloqueo por URL antes que el resto de manejadores de admin_init.
		add_action( 'admin_init', array( __CLASS__, 'guard' ), -1000 );

		add_filter( 'user_has_cap', array( __CLASS__, 'filter_caps' ), PHP_INT_MAX, 4 );
		add_filter( 'all_plugins', array( __CLASS__, 'filter_plugins' ), PHP_INT_MAX );
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'filter_plugin_updates' ), PHP_INT_MAX );

		// El escaneo guardado deja de ser válido si cambia el menú.
		add_action( 'activated_plugin', array( __CLASS__, 'flush_scan' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'flush_scan' ) );
		add_action( 'update_option_' . PREMIERO_OPT_MENU_GROUP, array( __CLASS__, 'flush_scan' ) );
		add_action( 'update_option_' . PREMIERO_OPT_MENU_LABELS, array( __CLASS__, 'flush_scan' ) );
	}

	/* ====================== Ajustes y usuarios ====================== */

	public static function settings() {
		if ( null !== self::$settings ) {
			return self::$settings;
		}
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		self::$settings = array(
			'enabled'      => empty( $raw['enabled'] ) ? 0 : 1,
			'users'        => array_values( array_unique( array_filter( array_map( 'absint', (array) ( $raw['users'] ?? array() ) ) ) ) ),
			'menu'         => self::clean_string_list( $raw['menu'] ?? array() ),
			'plugins'      => self::clean_string_list( $raw['plugins'] ?? array() ),
			'lock_plugins' => empty( $raw['lock_plugins'] ) ? 0 : 1,
			'extra'        => self::clean_string_list( $raw['extra'] ?? array() ),
		);
		return self::$settings;
	}

	private static function reset_cache() {
		self::$settings    = null;
		self::$full_access = null;
		self::$restricted  = array();
	}

	private static function clean_string_list( $list ) {
		$out = array();
		foreach ( (array) $list as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$item = trim( sanitize_text_field( (string) $item ) );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Slugs que nunca se pueden ocultar: el Escritorio, que es la pantalla de
	 * entrada tras iniciar sesión. El menú del toolkit sí se puede ocultar: las
	 * restricciones nunca afectan a los usuarios con acceso total, que siempre
	 * conservan esta pestaña para deshacer los cambios.
	 */
	private static function protected_slugs() {
		return array( 'index.php' );
	}

	/**
	 * Si el menú del toolkit está oculto para el usuario actual. En ese caso la
	 * agrupación no se aplica para él y lo agrupado vuelve a su sitio original.
	 */
	public static function toolkit_hidden_for_current_user() {
		return self::applies() && self::is_restricted_user() && isset( self::hidden_slugs()[ PREMIERO_ATK_SLUG ] );
	}

	private static function is_protected( $slug ) {
		return in_array( (string) $slug, self::protected_slugs(), true );
	}

	private static function is_administrator( $user ) {
		return $user instanceof WP_User && in_array( 'administrator', (array) $user->roles, true );
	}

	/** Usuarios seleccionados que siguen existiendo y siendo administradores. */
	public static function full_access_ids() {
		if ( null !== self::$full_access ) {
			return self::$full_access;
		}
		$ids = array();
		foreach ( self::settings()['users'] as $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user && self::is_administrator( $user ) ) {
				$ids[] = (int) $user->ID;
			}
		}
		self::$full_access = $ids;
		return $ids;
	}

	/**
	 * Las restricciones solo se aplican si están activadas y queda al menos un
	 * usuario con acceso total válido. Así nadie puede quedarse fuera.
	 */
	public static function is_active() {
		return ! empty( self::settings()['enabled'] ) && ! empty( self::full_access_ids() );
	}

	/** Solo en el administrador del sitio: nunca en cron, WP-CLI, REST ni red. */
	private static function applies() {
		if ( ! is_admin() || is_network_admin() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		return true;
	}

	public static function is_restricted_user( $user = null ) {
		if ( ! self::is_active() ) {
			return false;
		}
		if ( ! $user instanceof WP_User ) {
			$user = wp_get_current_user();
		}
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}
		$user_id = (int) $user->ID;
		if ( isset( self::$restricted[ $user_id ] ) ) {
			return self::$restricted[ $user_id ];
		}

		$restricted = self::is_administrator( $user ) && ! in_array( $user_id, self::full_access_ids(), true );
		// En multisitio los superadministradores siempre conservan el acceso.
		if ( $restricted && is_multisite() && is_super_admin( $user_id ) ) {
			$restricted = false;
		}
		self::$restricted[ $user_id ] = $restricted;
		return $restricted;
	}

	/** Quién puede ver y guardar la pestaña. */
	public static function can_manage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( ! self::is_active() ) {
			return true;
		}
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}
		return in_array( get_current_user_id(), self::full_access_ids(), true );
	}

	private static function is_access_screen( $tab = self::TAB ) {
		global $pagenow;
		if ( 'admin.php' !== $pagenow || ! isset( $_GET['page'], $_GET['tab'] ) ) {
			return false;
		}
		return PREMIERO_ATK_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) )
			&& $tab === sanitize_key( wp_unslash( $_GET['tab'] ) );
	}

	private static function deny() {
		wp_die(
			esc_html( self::DENY_MESSAGE ),
			esc_html( self::DENY_MESSAGE ),
			array(
				'response'  => 403,
				'back_link' => true,
			)
		);
	}

	/* ====================== Lectura del menú ====================== */

	public static function capture_menu() {
		// La pestaña Menú usa el mismo escaneo.
		$on_screen  = ( self::is_access_screen() && self::can_manage() )
			|| ( self::is_access_screen( Premiero_Menu_Groups::TAB ) && current_user_can( 'manage_options' ) );
		$restricted = self::applies() && self::is_restricted_user();
		if ( ! $on_screen && ! $restricted ) {
			return;
		}

		self::$structure = self::read_structure();
		self::persist_map( self::$structure );

		if ( $on_screen && ! is_array( get_transient( self::SCAN_TRANSIENT ) ) ) {
			set_transient( self::SCAN_TRANSIENT, self::describe_structure( self::$structure ), self::SCAN_TTL );
		}
	}

	public static function flush_scan() {
		delete_transient( self::SCAN_TRANSIENT );
	}

	/**
	 * Guarda los slugs del menú (padres e hijos), ordenados para que el valor
	 * sea estable: update_option no escribe si no ha cambiado.
	 */
	private static function persist_map( $structure ) {
		$map = array();
		foreach ( (array) $structure as $parent => $data ) {
			$children = array_fill_keys( array_map( 'strval', array_keys( (array) $data['children'] ) ), true );
			ksort( $children );
			$map[ (string) $parent ] = array( 'children' => $children );
		}
		ksort( $map );
		update_option( self::MAP_OPTION, $map, false );
	}

	/** Menú escaneado con su origen (lo usan esta pestaña y la de Menú). */
	public static function menu_scan() {
		$scan = get_transient( self::SCAN_TRANSIENT );
		if ( is_array( $scan ) ) {
			return $scan;
		}
		return is_array( self::$structure ) ? self::describe_structure( self::$structure ) : array();
	}

	/** El mismo escaneo sin los elementos ocultos para un administrador restringido. */
	public static function menu_scan_for_current_user() {
		$scan = self::menu_scan();
		if ( ! self::applies() || ! self::is_restricted_user() ) {
			return $scan;
		}
		$hidden = self::hidden_slugs();
		foreach ( $scan as $slug => $row ) {
			if ( isset( $hidden[ $slug ] ) ) {
				unset( $scan[ $slug ] );
				continue;
			}
			foreach ( array_keys( $row['children'] ) as $child ) {
				if ( isset( $hidden[ $child ] ) ) {
					unset( $scan[ $slug ]['children'][ $child ] );
				}
			}
		}
		return $scan;
	}

	/** Nombre original del elemento, antes de aplicar los nombres personalizados. */
	private static function original_title( $slug, $parent, $current ) {
		$original = class_exists( 'Premiero_Menu_Groups' ) ? Premiero_Menu_Groups::original_title( $slug, $parent ) : null;
		return null !== $original ? $original : $current;
	}

	/**
	 * Estructura del menú tal y como está antes de aplicar las restricciones:
	 * slug padre => título, si está agrupado y sus subelementos.
	 */
	private static function read_structure() {
		global $menu, $submenu;

		$grouped = array_map( 'strval', (array) get_option( PREMIERO_OPT_MENU_GROUP, array() ) );
		$parents = array();
		$top     = is_array( $menu ) ? $menu : array();
		ksort( $top, SORT_NUMERIC );

		foreach ( $top as $item ) {
			if ( empty( $item[2] ) || self::is_separator( $item ) ) {
				continue;
			}
			$slug = (string) $item[2];
			if ( isset( $parents[ $slug ] ) ) {
				continue;
			}
			$parents[ $slug ] = array(
				'title'    => self::clean_title( self::original_title( $slug, '', $item[0] ?? '' ), $slug ),
				'grouped'  => false,
				'children' => self::read_children( $slug, $grouped ),
			);
		}

		// Elementos que la agrupación ha movido dentro del menú del toolkit.
		if ( ! empty( $submenu[ PREMIERO_ATK_SLUG ] ) && is_array( $submenu[ PREMIERO_ATK_SLUG ] ) ) {
			foreach ( $submenu[ PREMIERO_ATK_SLUG ] as $item ) {
				$slug = isset( $item[2] ) ? (string) $item[2] : '';
				// Las copias de subelementos agrupados sueltos siguen listadas bajo su padre original.
				if ( '' === $slug || isset( $item['premiero_source'] ) || PREMIERO_ATK_SLUG === $slug || self::GROUP_SEPARATOR === $slug || ! in_array( $slug, $grouped, true ) ) {
					continue;
				}
				if ( isset( $parents[ $slug ] ) ) {
					$parents[ $slug ]['grouped'] = true;
					continue;
				}
				$parents[ $slug ] = array(
					'title'    => self::clean_title( self::original_title( $slug, '', $item[0] ?? '' ), $slug ),
					'grouped'  => true,
					'children' => self::read_children( $slug, $grouped ),
				);
			}
		}

		return $parents;
	}

	private static function read_children( $parent, $grouped ) {
		global $submenu;
		$children = array();
		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return $children;
		}
		foreach ( $submenu[ $parent ] as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( '' === $slug || isset( $children[ $slug ] ) || isset( $item['premiero_source'] ) ) {
				continue;
			}
			// Dentro del toolkit, los agrupados se listan como elementos propios.
			if ( PREMIERO_ATK_SLUG === $parent && ( self::GROUP_SEPARATOR === $slug || in_array( $slug, $grouped, true ) ) ) {
				continue;
			}
			$children[ $slug ] = self::clean_title( self::original_title( $slug, $parent, $item[0] ?? '' ), $slug );
		}
		return $children;
	}

	private static function is_separator( $item ) {
		$slug = (string) $item[2];
		if ( 0 === strpos( $slug, 'separator' ) ) {
			return true;
		}
		return isset( $item[4] ) && false !== strpos( (string) $item[4], 'wp-menu-separator' );
	}

	/** Nombre visible sin HTML, insignias ni contadores. */
	private static function clean_title( $raw, $fallback ) {
		$raw = (string) $raw;
		$raw = preg_replace( '#<span[^>]*screen-reader-text[^>]*>.*?</span>#is', '', $raw );
		$cut = preg_replace( '#<[a-z0-9]+\b[^>]*\bclass\s*=\s*["\'][^"\']*(?:count|update|awaiting|badge|bubble|pending|notification|menu-counter)[^"\']*["\'][^>]*>.*$#is', '', $raw );

		$title = self::plain_text( (string) $cut );
		if ( '' === $title ) {
			$title = self::plain_text( $raw );
		}
		return '' !== $title ? $title : (string) $fallback;
	}

	private static function plain_text( $html ) {
		$text = wp_strip_all_tags( (string) $html, true );
		$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/** Añade el origen de cada elemento (solo para la pantalla de la pestaña). */
	private static function describe_structure( $structure ) {
		$rows    = array();
		$grouped = array_map( 'strval', (array) get_option( PREMIERO_OPT_MENU_GROUP, array() ) );
		foreach ( (array) $structure as $slug => $data ) {
			$parent_origin = self::detect_origin( $slug, '' );
			$children      = array();
			$fallback      = '';

			foreach ( $data['children'] as $child_slug => $child_title ) {
				$origin = $child_slug === $slug ? $parent_origin : self::detect_origin( $child_slug, $slug );
				if ( '' === $fallback && '' !== $origin && 'WordPress' !== $origin ) {
					$fallback = $origin;
				}
				$children[ $child_slug ] = array(
					'title'   => $child_title,
					'origin'  => $origin,
					'grouped' => $child_slug !== $slug && in_array( (string) $child_slug, $grouped, true ),
				);
			}

			if ( '' === $parent_origin ) {
				$parent_origin = $fallback;
			}
			foreach ( $children as $child_slug => $child ) {
				if ( '' === $child['origin'] ) {
					$children[ $child_slug ]['origin'] = $parent_origin;
				}
			}

			$rows[ $slug ] = array(
				'title'    => $data['title'],
				'grouped'  => ! empty( $data['grouped'] ),
				'origin'   => $parent_origin,
				'children' => $children,
			);
		}
		return $rows;
	}

	/* ====================== Origen de cada elemento ====================== */

	private static function detect_origin( $slug, $parent ) {
		$route  = self::parse_slug( $slug );
		$params = $route['params'];

		if ( isset( $params['page'] ) ) {
			$origin = self::origin_from_hooks( $params['page'], '' !== $parent ? $parent : ( $route['file'] ?? '' ) );
			return '' !== $origin ? $origin : self::origin_from_prefix( $params['page'] );
		}

		if ( isset( $params['post_type'] ) ) {
			$object = get_post_type_object( $params['post_type'] );
			if ( $object && ! empty( $object->_builtin ) ) {
				return 'WordPress';
			}
			return self::origin_from_prefix( $params['post_type'] );
		}

		if ( isset( $params['taxonomy'] ) ) {
			$object = get_taxonomy( $params['taxonomy'] );
			if ( $object && ! empty( $object->_builtin ) ) {
				return 'WordPress';
			}
			return self::origin_from_prefix( $params['taxonomy'] );
		}

		if ( null !== $route['file'] ) {
			if ( file_exists( ABSPATH . 'wp-admin/' . $route['file'] ) ) {
				return 'WordPress';
			}
			return self::origin_from_prefix( basename( $route['file'], '.php' ) );
		}
		return '';
	}

	/** Busca el archivo que pinta la página y lo relaciona con su plugin. */
	private static function origin_from_hooks( $page, $parent ) {
		global $wp_filter;
		if ( ! function_exists( 'get_plugin_page_hookname' ) ) {
			return '';
		}
		$hooks = array_unique( array( get_plugin_page_hookname( $page, $parent ), get_plugin_page_hookname( $page, '' ) ) );
		foreach ( $hooks as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$origin = self::origin_from_file( self::callback_file( $callback['function'] ?? null ) );
					if ( '' !== $origin ) {
						return $origin;
					}
				}
			}
		}
		return '';
	}

	private static function callback_file( $callback ) {
		try {
			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$callback = explode( '::', $callback, 2 );
			}
			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$reflection = new ReflectionMethod( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0], (string) $callback[1] );
			} elseif ( $callback instanceof Closure || ( is_string( $callback ) && function_exists( $callback ) ) ) {
				$reflection = new ReflectionFunction( $callback );
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$reflection = new ReflectionMethod( $callback, '__invoke' );
			} else {
				return '';
			}
			return (string) $reflection->getFileName();
		} catch ( ReflectionException $e ) {
			return '';
		}
	}

	private static function origin_from_file( $file ) {
		if ( '' === (string) $file ) {
			return '';
		}
		$file = wp_normalize_path( $file );

		foreach ( array( ABSPATH . 'wp-admin/', ABSPATH . WPINC . '/' ) as $core_dir ) {
			if ( 0 === strpos( $file, wp_normalize_path( $core_dir ) ) ) {
				return 'WordPress';
			}
		}

		$plugins_dir = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		if ( 0 === strpos( $file, $plugins_dir ) ) {
			$relative = substr( $file, strlen( $plugins_dir ) );
			$segment  = explode( '/', $relative )[0];
			foreach ( self::plugin_info() as $info ) {
				if ( $info['dir'] === $segment || $info['file'] === $relative ) {
					return $info['name'];
				}
			}
			return $segment;
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) && 0 === strpos( $file, trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ) ) ) {
			return 'MU-plugin';
		}

		$theme_root = trailingslashit( wp_normalize_path( get_theme_root() ) );
		if ( 0 === strpos( $file, $theme_root ) ) {
			$segment = explode( '/', substr( $file, strlen( $theme_root ) ) )[0];
			$theme   = wp_get_theme( $segment );
			return 'Tema: ' . ( $theme->exists() ? $theme->get( 'Name' ) : $segment );
		}
		return '';
	}

	/** Relaciona un slug, tipo de contenido o taxonomía con la carpeta o el text domain de un plugin. */
	private static function origin_from_prefix( $name ) {
		$name = strtolower( str_replace( '_', '-', (string) $name ) );
		if ( '' === $name ) {
			return '';
		}
		$best        = '';
		$best_length = 0;
		foreach ( self::plugin_info() as $info ) {
			foreach ( $info['keys'] as $key ) {
				$length = strlen( $key );
				if ( $length < 3 || $length <= $best_length ) {
					continue;
				}
				if ( $name === $key || 0 === strpos( $name, $key . '-' ) || ( $length >= 5 && 0 === strpos( $name, $key ) ) ) {
					$best        = $info['name'];
					$best_length = $length;
				}
			}
		}
		return $best;
	}

	private static function get_all_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return get_plugins();
	}

	private static function own_plugin_file() {
		return plugin_basename( PREMIERO_ATK_FILE );
	}

	private static function plugin_info() {
		if ( null !== self::$plugin_info ) {
			return self::$plugin_info;
		}
		self::$plugin_info = array();
		foreach ( self::get_all_plugins() as $file => $data ) {
			$dir  = dirname( $file );
			$dir  = '.' === $dir ? '' : $dir;
			$name = $file === self::own_plugin_file() ? premiero_get_toolkit_name() : (string) ( $data['Name'] ?? $file );
			$keys = array();
			foreach ( array( $dir, basename( $file, '.php' ), (string) ( $data['TextDomain'] ?? '' ) ) as $key ) {
				$key = strtolower( str_replace( '_', '-', $key ) );
				if ( '' !== $key ) {
					$keys[] = $key;
				}
			}
			self::$plugin_info[] = array(
				'file' => $file,
				'dir'  => '' !== $dir ? $dir : $file,
				'name' => $name,
				'keys' => array_unique( $keys ),
			);
		}
		return self::$plugin_info;
	}

	/* ====================== Rutas ====================== */

	/**
	 * Interpreta un slug: si es un archivo .php (con o sin query) compara el
	 * archivo y sus parámetros; si no, es el parámetro "page" (y lo que siga
	 * tras "&", por ejemplo "wc-admin&path=/customers").
	 */
	private static function parse_slug( $slug ) {
		$slug   = str_replace( '&amp;', '&', trim( (string) $slug ) );
		$file   = null;
		$params = array();
		$query  = '';

		if ( preg_match( '#^([^?/&]+\.php)(?:\?(.*))?$#i', $slug, $match ) ) {
			$file  = strtolower( $match[1] );
			$query = $match[2] ?? '';
		} else {
			$parts          = explode( '&', $slug, 2 );
			$params['page'] = $parts[0];
			$query          = $parts[1] ?? '';
		}

		if ( '' !== $query ) {
			parse_str( $query, $parsed );
			foreach ( $parsed as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$params[ (string) $key ] = (string) $value;
				}
			}
		}

		// Las páginas de plugins se abren desde cualquier archivo padre.
		if ( 'admin.php' === $file && isset( $params['page'] ) ) {
			$file = null;
		}
		return self::normalize_route( $file, $params );
	}

	private static function normalize_route( $file, $params ) {
		if ( in_array( $file, array( 'edit.php', 'post-new.php' ), true ) && isset( $params['post_type'] ) && 'post' === $params['post_type'] ) {
			unset( $params['post_type'] );
		}
		return array(
			'file'   => $file,
			'params' => $params,
		);
	}

	private static function current_requests( $routes ) {
		global $pagenow;
		$params = array();
		foreach ( $_GET as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$params[ (string) $key ] = (string) wp_unslash( $value );
			}
		}
		return self::requests_for( strtolower( (string) $pagenow ), $params, $routes );
	}

	/** Peticiones equivalentes a un archivo del admin con sus parámetros. */
	private static function requests_for( $file, $params, $routes ) {
		$requests = array( self::normalize_route( $file, $params ) );

		// Editar o crear contenido cuenta como la pantalla de su tipo de contenido.
		$post_type = '';
		if ( 'post.php' === $file && isset( $params['post'] ) ) {
			$post_type = (string) get_post_type( absint( $params['post'] ) );
		} elseif ( 'post-new.php' === $file ) {
			$post_type = isset( $params['post_type'] ) ? sanitize_key( $params['post_type'] ) : 'post';
		}
		if ( '' !== $post_type ) {
			$virtual = self::normalize_route( 'edit.php', array( 'post_type' => $post_type ) );
			foreach ( $routes as $route ) {
				if ( $route['file'] === $virtual['file'] && $route['params'] === $virtual['params'] ) {
					$requests[] = $virtual;
					break;
				}
			}
		}
		return $requests;
	}

	/** Puntuación de coincidencia (más parámetros = más específica) o -1. */
	private static function route_score( $route, $request ) {
		if ( null !== $route['file'] && $route['file'] !== $request['file'] ) {
			return -1;
		}
		foreach ( $route['params'] as $key => $value ) {
			if ( ! isset( $request['params'][ $key ] ) ) {
				return -1;
			}
			$current = (string) $request['params'][ $key ];
			if ( 'path' === $key ) {
				$expected = untrailingslashit( $value );
				$current  = untrailingslashit( $current );
				if ( $current !== $expected && 0 !== strpos( $current, $expected . '/' ) ) {
					return -1;
				}
			} elseif ( $current !== (string) $value ) {
				return -1;
			}
		}
		return count( $route['params'] ) + ( null !== $route['file'] ? 1 : 0 );
	}

	/** Slugs ocultos (menú + adicionales), sin los protegidos. */
	private static function hidden_slugs() {
		$settings = self::settings();
		$hidden   = array();
		foreach ( array_merge( $settings['menu'], $settings['extra'] ) as $slug ) {
			if ( ! self::is_protected( $slug ) ) {
				$hidden[ $slug ] = true;
			}
		}
		return $hidden;
	}

	private static function structure_for_routes() {
		if ( is_array( self::$structure ) ) {
			return self::$structure;
		}
		// Sin menú cargado (admin-post.php o viendo la web): último escaneo o mapa guardado.
		$scan = get_transient( self::SCAN_TRANSIENT );
		if ( is_array( $scan ) ) {
			return $scan;
		}
		$map = get_option( self::MAP_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/** Rutas bloqueadas y visibles. Marcar un padre bloquea todos sus subelementos. */
	private static function build_routes() {
		$hidden  = self::hidden_slugs();
		$blocked = array();
		$visible = array();

		foreach ( self::structure_for_routes() as $parent => $data ) {
			$parent_hidden = isset( $hidden[ $parent ] );
			if ( $parent_hidden ) {
				$blocked[ $parent ] = true;
			} else {
				$visible[ $parent ] = true;
			}
			foreach ( array_keys( (array) $data['children'] ) as $child ) {
				if ( $parent_hidden || isset( $hidden[ $child ] ) ) {
					$blocked[ $child ] = true;
				} else {
					$visible[ $child ] = true;
				}
			}
		}
		foreach ( array_keys( $hidden ) as $slug ) {
			$blocked[ $slug ] = true;
		}
		foreach ( self::protected_slugs() as $slug ) {
			unset( $blocked[ $slug ] );
			$visible[ $slug ] = true;
		}

		return array(
			'blocked' => array_map( array( __CLASS__, 'parse_slug' ), array_keys( $blocked ) ),
			'visible' => array_map( array( __CLASS__, 'parse_slug' ), array_keys( $visible ) ),
		);
	}

	/** Si un enlace del administrador lleva a una pantalla bloqueada para el usuario actual. */
	public static function url_is_blocked_for_current_user( $url ) {
		static $routes = null;
		if ( ! self::is_restricted_user() ) {
			return false;
		}
		$url   = html_entity_decode( (string) $url, ENT_QUOTES );
		$admin = admin_url();
		if ( 0 === strpos( $url, $admin ) ) {
			$relative = substr( $url, strlen( $admin ) );
		} elseif ( ! preg_match( '#^[a-z][a-z0-9+.-]*:|^//|^/#i', $url ) && preg_match( '#^[^?/]+\.php(\?|$)#', $url ) ) {
			$relative = $url; // Enlace relativo del admin (admin.php?page=…).
		} else {
			return false;
		}
		$parts  = wp_parse_url( '/' . ltrim( $relative, '/' ) );
		$file   = strtolower( basename( isset( $parts['path'] ) ? $parts['path'] : '' ) );
		$file   = ( '' === $file || false === strpos( $file, '.php' ) ) ? 'index.php' : $file;
		$params = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			foreach ( $query as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$params[ (string) $key ] = (string) $value;
				}
			}
		}
		if ( null === $routes ) {
			$routes = self::build_routes();
		}
		if ( empty( $routes['blocked'] ) ) {
			return false;
		}
		foreach ( self::requests_for( $file, $params, array_merge( $routes['blocked'], $routes['visible'] ) ) as $request ) {
			if ( self::request_is_blocked( $request, $routes ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Quita de la barra superior lo que lleva a pantallas bloqueadas para un
	 * administrador restringido (accesos de plugins ocultos, enlaces propios…),
	 * en el admin y viendo la web.
	 */
	public static function filter_admin_bar() {
		global $wp_admin_bar;
		if ( ! $wp_admin_bar instanceof WP_Admin_Bar || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! self::is_restricted_user() ) {
			return;
		}
		$nodes = (array) $wp_admin_bar->get_nodes();
		if ( empty( $nodes ) ) {
			return;
		}

		$children = array();
		foreach ( $nodes as $node ) {
			if ( ! empty( $node->parent ) ) {
				$children[ $node->parent ][] = $node->id;
			}
		}
		$removed = array();
		foreach ( $nodes as $node ) {
			if ( empty( $node->group ) && ! empty( $node->href ) && self::url_is_blocked_for_current_user( $node->href ) ) {
				$removed[ $node->id ] = true;
			}
		}
		// Hijos de lo quitado, y elementos sin enlace propio que se han quedado sin hijos.
		do {
			$changed = false;
			foreach ( $nodes as $node ) {
				if ( isset( $removed[ $node->id ] ) ) {
					continue;
				}
				if ( ! empty( $node->parent ) && isset( $removed[ $node->parent ] ) ) {
					$removed[ $node->id ] = true;
					$changed              = true;
					continue;
				}
				$own = isset( $children[ $node->id ] ) ? $children[ $node->id ] : array();
				if ( empty( $node->group ) && ( empty( $node->href ) || '#' === $node->href ) && $own && ! array_diff( $own, array_keys( $removed ) ) ) {
					$removed[ $node->id ] = true;
					$changed              = true;
				}
			}
		} while ( $changed );

		foreach ( array_keys( $removed ) as $id ) {
			$wp_admin_bar->remove_node( $id );
		}
	}

	/** Gana la ruta más específica; en empate, gana el bloqueo. */
	private static function request_is_blocked( $request, $routes ) {
		$best_blocked = -1;
		$best_visible = -1;
		foreach ( $routes['blocked'] as $route ) {
			$best_blocked = max( $best_blocked, self::route_score( $route, $request ) );
		}
		if ( $best_blocked < 0 ) {
			return false;
		}
		foreach ( $routes['visible'] as $route ) {
			$best_visible = max( $best_visible, self::route_score( $route, $request ) );
		}
		return $best_blocked >= $best_visible;
	}

	/* ====================== Aplicación ====================== */

	public static function apply_menu() {
		if ( ! self::applies() || ! self::is_restricted_user() ) {
			return;
		}
		$hidden = self::hidden_slugs();
		if ( empty( $hidden ) ) {
			return;
		}
		global $submenu;

		foreach ( (array) self::$structure as $parent => $data ) {
			if ( isset( $hidden[ $parent ] ) ) {
				if ( ! empty( $data['grouped'] ) ) {
					remove_submenu_page( PREMIERO_ATK_SLUG, $parent );
				}
				remove_menu_page( $parent );
				continue;
			}

			$same_slug_hidden = false;
			foreach ( array_keys( $data['children'] ) as $child ) {
				if ( isset( $hidden[ $child ] ) ) {
					remove_submenu_page( $parent, $child );
					$same_slug_hidden = $same_slug_hidden || $child === $parent;
				}
			}
			// Si la pantalla del padre está oculta y no le quedan subelementos, se retira entero.
			if ( $same_slug_hidden && empty( $submenu[ $parent ] ) ) {
				if ( ! empty( $data['grouped'] ) ) {
					remove_submenu_page( PREMIERO_ATK_SLUG, $parent );
				}
				remove_menu_page( $parent );
			}
		}

		// Slugs adicionales o que no estaban en la estructura leída.
		foreach ( array_keys( $hidden ) as $slug ) {
			remove_menu_page( $slug );
			foreach ( (array) $submenu as $parent => $items ) {
				if ( PREMIERO_ATK_SLUG === $parent && self::GROUP_SEPARATOR === $slug ) {
					continue;
				}
				foreach ( (array) $items as $item ) {
					if ( isset( $item[2] ) && (string) $item[2] === $slug ) {
						remove_submenu_page( $parent, $slug );
						break;
					}
				}
			}
		}

		// Copias de subelementos agrupados sueltos cuyo original o padre está oculto.
		if ( ! empty( $submenu[ PREMIERO_ATK_SLUG ] ) ) {
			foreach ( $submenu[ PREMIERO_ATK_SLUG ] as $key => $item ) {
				if ( isset( $item['premiero_source'] ) && ( isset( $hidden[ $item['premiero_source'] ] ) || isset( $hidden[ $item['premiero_parent'] ?? '' ] ) ) ) {
					unset( $submenu[ PREMIERO_ATK_SLUG ][ $key ] );
				}
			}
		}

		// Si ya no queda ningún elemento agrupado visible, sobra el separador.
		if ( ! empty( $submenu[ PREMIERO_ATK_SLUG ] ) ) {
			$grouped_left = false;
			foreach ( $submenu[ PREMIERO_ATK_SLUG ] as $item ) {
				$slug = isset( $item[2] ) ? (string) $item[2] : '';
				if ( '' !== $slug && PREMIERO_ATK_SLUG !== $slug && self::GROUP_SEPARATOR !== $slug ) {
					$grouped_left = true;
					break;
				}
			}
			if ( ! $grouped_left ) {
				remove_submenu_page( PREMIERO_ATK_SLUG, self::GROUP_SEPARATOR );
			}
		}
	}

	public static function guard() {
		if ( ! self::applies() ) {
			return;
		}
		if ( self::is_access_screen() && ! self::can_manage() ) {
			self::deny();
		}
		if ( ! self::is_restricted_user() ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			self::guard_plugin_ajax();
			return;
		}

		self::guard_plugin_actions();

		$routes = self::build_routes();
		if ( empty( $routes['blocked'] ) ) {
			return;
		}
		foreach ( self::current_requests( array_merge( $routes['blocked'], $routes['visible'] ) ) as $request ) {
			if ( self::request_is_blocked( $request, $routes ) ) {
				self::deny();
			}
		}
	}

	private static function hidden_plugins() {
		return self::settings()['plugins'];
	}

	/** Activar, desactivar, borrar, actualizar o editar un plugin oculto por URL. */
	private static function guard_plugin_actions() {
		global $pagenow;
		$hidden = self::hidden_plugins();
		if ( empty( $hidden ) || ! in_array( $pagenow, array( 'plugins.php', 'update.php', 'plugin-editor.php' ), true ) ) {
			return;
		}

		$targets = array();
		foreach ( array( 'plugin', 'checked', 'plugins' ) as $key ) {
			if ( ! isset( $_REQUEST[ $key ] ) ) {
				continue;
			}
			$value = wp_unslash( $_REQUEST[ $key ] );
			if ( 'plugins' === $key && is_string( $value ) ) {
				$value = explode( ',', $value );
			}
			foreach ( (array) $value as $item ) {
				if ( is_string( $item ) ) {
					$targets[] = trim( $item );
				}
			}
		}
		if ( array_intersect( $targets, $hidden ) ) {
			self::deny();
		}

		if ( 'plugin-editor.php' === $pagenow && isset( $_REQUEST['file'] ) && is_string( $_REQUEST['file'] ) ) {
			$file = wp_unslash( $_REQUEST['file'] );
			foreach ( $hidden as $plugin ) {
				$dir = dirname( $plugin );
				if ( $file === $plugin || ( '.' !== $dir && 0 === strpos( $file, $dir . '/' ) ) ) {
					self::deny();
				}
			}
		}
	}

	private static function guard_plugin_ajax() {
		$hidden = self::hidden_plugins();
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( empty( $hidden ) || '' === $action ) {
			return;
		}
		$target = '';
		if ( in_array( $action, array( 'update-plugin', 'delete-plugin' ), true ) && isset( $_POST['plugin'] ) && is_string( $_POST['plugin'] ) ) {
			$target = wp_unslash( $_POST['plugin'] );
		} elseif ( 'toggle-auto-updates' === $action && isset( $_POST['type'], $_POST['asset'] ) && 'plugin' === $_POST['type'] && is_string( $_POST['asset'] ) ) {
			$target = wp_unslash( $_POST['asset'] );
		}
		if ( '' !== $target && in_array( $target, $hidden, true ) ) {
			wp_send_json_error( array( 'errorMessage' => self::DENY_MESSAGE ), 403 );
		}
	}

	public static function filter_caps( $allcaps, $caps, $args, $user ) {
		if ( ! self::applies() || empty( self::settings()['lock_plugins'] ) ) {
			return $allcaps;
		}
		if ( ! $user instanceof WP_User || ! self::is_restricted_user( $user ) ) {
			return $allcaps;
		}
		foreach ( self::PLUGIN_CAPS as $cap ) {
			$allcaps[ $cap ] = false;
		}
		return $allcaps;
	}

	public static function filter_plugins( $plugins ) {
		if ( ! is_array( $plugins ) || ! self::applies() || ! self::is_restricted_user() ) {
			return $plugins;
		}
		foreach ( self::hidden_plugins() as $file ) {
			unset( $plugins[ $file ] );
		}
		return $plugins;
	}

	/** Evita que el contador de actualizaciones delate los plugins ocultos. */
	public static function filter_plugin_updates( $value ) {
		if ( ! is_object( $value ) || empty( $value->response ) || ! self::applies() || ! self::is_restricted_user() ) {
			return $value;
		}
		$hidden = self::hidden_plugins();
		if ( empty( $hidden ) || ! array_intersect( array_keys( (array) $value->response ), $hidden ) ) {
			return $value;
		}
		$value           = clone $value;
		$value->response = array_diff_key( (array) $value->response, array_flip( $hidden ) );
		return $value;
	}

	/* ====================== Guardado ====================== */

	public static function handle_save() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html( self::DENY_MESSAGE ), esc_html( self::DENY_MESSAGE ), array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_SAVE );

		$old = self::settings();
		$new = array(
			'enabled'      => isset( $_POST['enabled'] ) ? 1 : 0,
			'lock_plugins' => isset( $_POST['lock_plugins'] ) ? 1 : 0,
			'users'        => array(),
			'menu'         => array(),
			'plugins'      => array(),
			'extra'        => array(),
		);

		// Usuarios: solo administradores existentes.
		foreach ( (array) ( $_POST['users'] ?? array() ) as $user_id ) {
			$user = get_userdata( absint( $user_id ) );
			if ( $user && self::is_administrator( $user ) ) {
				$new['users'][] = (int) $user->ID;
			}
		}
		$new['users'] = array_values( array_unique( $new['users'] ) );

		// Menú y plugins: se conservan los guardados que ya no aparecen en la pantalla.
		$new['menu']    = self::merge_checked( $old['menu'], wp_unslash( $_POST['menu'] ?? array() ), wp_unslash( $_POST['menu_known'] ?? array() ) );
		$new['plugins'] = self::merge_checked( $old['plugins'], wp_unslash( $_POST['plugins'] ?? array() ), wp_unslash( $_POST['plugins_known'] ?? array() ) );
		$new['menu']    = array_values( array_filter( $new['menu'], function ( $slug ) {
			return ! self::is_protected( $slug );
		} ) );

		$extra        = isset( $_POST['extra'] ) && is_string( $_POST['extra'] ) ? wp_unslash( $_POST['extra'] ) : '';
		$new['extra'] = array_values( array_filter( self::clean_string_list( preg_split( '/\r\n|\r|\n/', $extra ) ), function ( $slug ) {
			return ! self::is_protected( $slug );
		} ) );

		if ( $new['enabled'] ) {
			if ( empty( $new['users'] ) ) {
				self::add_notice( 'error', 'Selecciona al menos un usuario con acceso total antes de activar las restricciones. No se ha guardado ningún cambio.' );
				self::redirect();
			}
			if ( ! in_array( get_current_user_id(), $new['users'], true ) && ! ( is_multisite() && is_super_admin() ) ) {
				self::add_notice( 'error', 'Tu usuario debe seguir en "Usuarios con acceso total" para no perder el acceso a esta pestaña. No se ha guardado ningún cambio.' );
				self::redirect();
			}
		}

		update_option( self::OPTION, $new, false );
		self::reset_cache();

		self::add_notice( 'success', $new['enabled'] ? 'Restricciones de acceso guardadas y activas.' : 'Restricciones de acceso guardadas. Están desactivadas.' );
		self::redirect();
	}

	private static function merge_checked( $old, $checked, $known ) {
		$checked = self::clean_string_list( $checked );
		$known   = self::clean_string_list( $known );
		$kept    = array_diff( $old, $known );
		return array_values( array_unique( array_merge( $kept, array_intersect( $checked, $known ) ) ) );
	}

	public static function handle_rescan() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html( self::DENY_MESSAGE ), esc_html( self::DENY_MESSAGE ), array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_RESCAN );
		self::flush_scan();
		self::add_notice( 'success', 'Menú reescaneado.' );
		self::redirect();
	}

	private static function add_notice( $type, $message ) {
		$notices = get_transient( self::NOTICES );
		$notices = is_array( $notices ) ? $notices : array();

		$notices[ get_current_user_id() ][] = array(
			'type'    => $type,
			'message' => sanitize_text_field( $message ),
		);
		set_transient( self::NOTICES, $notices, 5 * MINUTE_IN_SECONDS );
	}

	private static function render_notices() {
		$notices = get_transient( self::NOTICES );
		$user_id = get_current_user_id();
		if ( ! is_array( $notices ) || empty( $notices[ $user_id ] ) ) {
			return;
		}
		foreach ( $notices[ $user_id ] as $notice ) {
			echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . ' is-dismissible premiero-capture-ignore"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
		unset( $notices[ $user_id ] );
		if ( $notices ) {
			set_transient( self::NOTICES, $notices, 5 * MINUTE_IN_SECONDS );
		} else {
			delete_transient( self::NOTICES );
		}
	}

	private static function redirect() {
		wp_safe_redirect( admin_url( 'admin.php?page=' . PREMIERO_ATK_SLUG . '&tab=' . self::TAB ) );
		exit;
	}

	/* ====================== Render ====================== */

	public static function render_tab() {
		if ( ! self::can_manage() ) {
			return;
		}

		$settings = self::settings();
		$scan     = get_transient( self::SCAN_TRANSIENT );
		if ( ! is_array( $scan ) ) {
			$scan = is_array( self::$structure ) ? self::describe_structure( self::$structure ) : array();
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);
		// Al activarlas por primera vez se preselecciona el usuario actual.
		$selected_users = $settings['users'] ? $settings['users'] : array( get_current_user_id() );

		$plugins      = self::get_all_plugins();
		$own_plugin   = self::own_plugin_file();
		$hidden_menu  = $settings['menu'];
		$hidden_plugs = $settings['plugins'];
		$active       = self::is_active();

		// Slugs guardados que ya no aparecen en el menú (plugin desinstalado, etc.).
		$known_slugs = array();
		foreach ( $scan as $slug => $row ) {
			$known_slugs[ $slug ] = true;
			foreach ( array_keys( $row['children'] ) as $child ) {
				$known_slugs[ $child ] = true;
			}
		}
		$missing_slugs = array_values( array_diff( $hidden_menu, array_keys( $known_slugs ) ) );
		$missing_plugs = array_values( array_diff( $hidden_plugs, array_keys( $plugins ) ) );

		if ( $active ) {
			$status = 'Restricciones activas';
		} elseif ( $settings['enabled'] ) {
			$status = 'Sin usuarios válidos';
		} else {
			$status = 'Restricciones desactivadas';
		}

		self::render_notices();
		?>
		<div class="premiero-access">
			<header class="premiero-remote-header">
				<div>
					<h2>Restricciones de acceso</h2>
					<p>Decide qué menús y plugins dejan de ver los administradores que no tienen acceso total.</p>
				</div>
				<span class="premiero-remote-status <?php echo $active ? 'is-active' : 'is-off'; ?>"><span aria-hidden="true"></span><?php echo esc_html( $status ); ?></span>
			</header>

			<?php if ( $settings['enabled'] && ! $active ) : ?>
				<div class="notice notice-warning inline premiero-capture-ignore"><p>Ninguno de los usuarios seleccionados sigue siendo administrador, así que las restricciones no se aplican. Selecciona al menos uno y guarda.</p></div>
			<?php endif; ?>

			<div class="premiero-overview premiero-notices-overview">
				<div class="<?php echo $active ? '' : 'is-hidden'; ?>">
					<strong>Estado</strong>
					<span><?php echo $active ? 'Activas' : 'Desactivadas'; ?></span>
				</div>
				<div>
					<strong>Acceso total</strong>
					<span><?php echo esc_html( count( self::full_access_ids() ) . ' de ' . count( $admins ) . ' administradores' ); ?></span>
				</div>
				<div class="<?php echo $hidden_menu || $settings['extra'] ? 'is-attention' : ''; ?>">
					<strong>Menú oculto</strong>
					<span><?php echo esc_html( ( count( $hidden_menu ) + count( $settings['extra'] ) ) . ' slugs' ); ?></span>
				</div>
				<div class="<?php echo $hidden_plugs || $settings['lock_plugins'] ? 'is-attention' : ''; ?>">
					<strong>Plugins</strong>
					<span><?php echo esc_html( count( $hidden_plugs ) . ' ocultos' . ( $settings['lock_plugins'] ? ' · gestión bloqueada' : '' ) ); ?></span>
				</div>
			</div>

			<form method="post" id="premiero-access-rescan-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RESCAN ); ?>">
				<?php wp_nonce_field( self::ACTION_RESCAN ); ?>
			</form>

			<form method="post" class="premiero-appearance-form" id="premiero-access-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>

				<section class="premiero-appearance-section">
					<h2>Restricciones</h2>
					<p>Solo afectan a los administradores sin acceso total. Los demás roles, el cron, WP-CLI y la API REST no cambian.</p>
					<label class="premiero-appearance-toggle">
						<input type="checkbox" name="enabled" value="1" id="premiero-access-enabled" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
						<span>
							<strong>Activar restricciones</strong>
							<small>Al desactivarlas, todos los administradores vuelven a verlo todo al instante. Tus selecciones se conservan.</small>
						</span>
					</label>
				</section>

				<section class="premiero-appearance-section">
					<h2>Usuarios con acceso total</h2>
					<p>Lo ven todo y son los únicos que pueden abrir esta pestaña mientras las restricciones estén activas. Tu usuario debe seguir marcado.</p>
					<?php if ( empty( $admins ) ) : ?>
						<p class="description">No hay usuarios con rol de administrador.</p>
					<?php else : ?>
						<div class="premiero-access-users">
							<?php foreach ( $admins as $admin ) : ?>
								<label>
									<input type="checkbox" name="users[]" value="<?php echo esc_attr( (string) $admin->ID ); ?>" <?php checked( in_array( (int) $admin->ID, $selected_users, true ) ); ?>>
									<span>
										<strong><?php echo esc_html( $admin->display_name ); ?></strong>
										<small><?php echo esc_html( $admin->user_login ); ?><?php echo get_current_user_id() === (int) $admin->ID ? ' · tú' : ''; ?></small>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</section>

				<section class="premiero-appearance-section">
					<h2>Menú de administración</h2>
					<p>Marcar un elemento oculta también sus subelementos y bloquea sus URLs. Los subelementos se pueden marcar por separado. Se guarda el slug, no el nombre visible.</p>
					<div class="premiero-notices-toolbar">
						<label class="screen-reader-text" for="premiero-access-search">Buscar elementos</label>
						<input type="search" id="premiero-access-search" placeholder="Buscar por nombre, slug u origen">
						<button type="button" class="button" id="premiero-access-toggle-all">Marcar todos</button>
						<button type="submit" class="button" form="premiero-access-rescan-form">Reescanear</button>
						<span class="premiero-menu-count" id="premiero-access-count" aria-live="polite"></span>
					</div>
					<?php if ( empty( $scan ) ) : ?>
						<p class="description">No se ha podido leer el menú. Pulsa "Reescanear".</p>
					<?php else : ?>
						<div class="premiero-remote-table-wrap">
							<table class="widefat premiero-remote-table premiero-access-table" id="premiero-access-menu">
								<thead><tr><th>Elemento</th><th>Slug</th><th>Origen</th><th>Agrupado</th><th>Ocultar</th></tr></thead>
								<tbody>
								<?php
								$group = 0;
								foreach ( $scan as $slug => $row ) :
									++$group;
									$slug = (string) $slug;
									self::render_menu_row( 'parent', $group, $slug, $row['title'], $row['origin'], ! empty( $row['grouped'] ), self::is_protected( $slug ), in_array( $slug, $hidden_menu, true ), '' );
									foreach ( $row['children'] as $child_slug => $child ) :
										$child_slug = (string) $child_slug;
										$note       = $child_slug === $slug ? 'Misma pantalla que el elemento padre' : '';
										self::render_menu_row( 'child', $group, $child_slug, $child['title'], $child['origin'], ! empty( $child['grouped'] ), self::is_protected( $child_slug ), in_array( $child_slug, $hidden_menu, true ), $note );
									endforeach;
								endforeach;
								foreach ( $missing_slugs as $slug ) :
									++$group;
									self::render_menu_row( 'parent', $group, $slug, $slug, 'No detectado', false, false, true, '' );
								endforeach;
								?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</section>

				<section class="premiero-appearance-section">
					<h2>Plugins instalados</h2>
					<p>Los plugins marcados desaparecen de la lista de Plugins y no se pueden activar, desactivar, borrar ni actualizar por URL.</p>
					<div class="premiero-remote-table-wrap">
						<table class="widefat premiero-remote-table premiero-access-table premiero-access-plugins">
							<thead><tr><th>Plugin</th><th>Versión</th><th>Estado</th><th>Ocultar en la lista de Plugins</th></tr></thead>
							<tbody>
							<?php foreach ( $plugins as $file => $data ) : ?>
								<?php
								$name      = $file === $own_plugin ? premiero_get_toolkit_name() : (string) ( $data['Name'] ?? $file );
								$is_active = is_plugin_active( $file );
								?>
								<tr>
									<td><strong><?php echo esc_html( $name ); ?></strong><small><?php echo esc_html( $file ); ?></small></td>
									<td><?php echo esc_html( (string) ( $data['Version'] ?? '' ) ); ?></td>
									<td><span class="premiero-notice-state <?php echo $is_active ? 'is-visible' : 'is-hidden'; ?>"><?php echo $is_active ? 'Activo' : 'Inactivo'; ?></span></td>
									<td>
										<input type="hidden" name="plugins_known[]" value="<?php echo esc_attr( $file ); ?>">
										<label><input type="checkbox" name="plugins[]" value="<?php echo esc_attr( $file ); ?>" <?php checked( in_array( $file, $hidden_plugs, true ) ); ?>> Ocultar</label>
									</td>
								</tr>
							<?php endforeach; ?>
							<?php foreach ( $missing_plugs as $file ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $file ); ?></strong><small>Ya no está instalado. Se conserva por si vuelve.</small></td>
									<td>—</td>
									<td><span class="premiero-notice-state is-hidden">No instalado</span></td>
									<td>
										<input type="hidden" name="plugins_known[]" value="<?php echo esc_attr( $file ); ?>">
										<label><input type="checkbox" name="plugins[]" value="<?php echo esc_attr( $file ); ?>" checked> Ocultar</label>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<label class="premiero-appearance-toggle premiero-access-lock">
						<input type="checkbox" name="lock_plugins" value="1" <?php checked( ! empty( $settings['lock_plugins'] ) ); ?>>
						<span>
							<strong>Bloquear instalar, activar, actualizar, borrar y editar plugins</strong>
							<small>Retira solo esas capacidades a los administradores restringidos. Los temas, Elementor y WooCommerce conservan sus permisos.</small>
						</span>
					</label>
				</section>

				<section class="premiero-appearance-section">
					<h2>Slugs adicionales a ocultar</h2>
					<p>Opcional, uno por línea. Un archivo <code>.php</code> con o sin parámetros (<code>edit.php?post_type=acf-field-group</code>, <code>options-general.php?page=ejemplo</code>) o el valor de <code>page</code> (<code>wc-admin&amp;path=/analytics/overview</code>).</p>
					<textarea name="extra" rows="5" class="large-text code" spellcheck="false"><?php echo esc_textarea( implode( "\n", $settings['extra'] ) ); ?></textarea>
				</section>

				<div class="premiero-sticky-actions">
					<span class="description">Los cambios se aplicarán al guardar.</span>
					<?php submit_button( 'Guardar cambios', 'primary', 'premiero_access_submit', false ); ?>
				</div>
			</form>
		</div>
		<script>
		(function(){
			var form = document.getElementById('premiero-access-form');
			if ( form ) {
				form.addEventListener('submit', function(event){
					var enabled = document.getElementById('premiero-access-enabled');
					if ( enabled && enabled.checked && ! form.querySelector('input[name="users[]"]:checked') ) {
						event.preventDefault();
						window.alert('Selecciona al menos un usuario con acceso total antes de activar las restricciones.');
					}
				});
			}

			var table = document.getElementById('premiero-access-menu');
			if ( ! table ) return;
			var rows   = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
			var search = document.getElementById('premiero-access-search');
			var toggle = document.getElementById('premiero-access-toggle-all');
			var count  = document.getElementById('premiero-access-count');
			var parents = {};
			rows.forEach(function(row){ if ( row.dataset.kind === 'parent' ) parents[row.dataset.group] = row; });

			function box(row){ return row.querySelector('input.premiero-access-hide'); }
			function visibleBoxes(){
				return rows.filter(function(row){ return row.style.display !== 'none'; }).map(box).filter(Boolean);
			}
			function sync(){
				var marked = 0, visible = 0;
				rows.forEach(function(row){
					if ( row.style.display !== 'none' ) visible++;
					var own = box(row);
					if ( own && own.checked ) marked++;
					if ( row.dataset.kind !== 'child' ) return;
					var parentBox = parents[row.dataset.group] ? box(parents[row.dataset.group]) : null;
					row.classList.toggle('is-inherited', !! ( parentBox && parentBox.checked ));
				});
				count.textContent = visible + ' visibles · ' + marked + ' marcados';
				var boxes = visibleBoxes();
				toggle.textContent = boxes.length && boxes.every(function(item){ return item.checked; }) ? 'Desmarcar todos' : 'Marcar todos';
			}
			function filter(){
				var term = ( search.value || '' ).toLowerCase().trim();
				var groups = {};
				rows.forEach(function(row){
					row._match = ! term || row.dataset.search.indexOf(term) !== -1;
					if ( row._match ) groups[row.dataset.group] = true;
				});
				rows.forEach(function(row){
					var show;
					if ( row.dataset.kind === 'child' ) {
						var parent = parents[row.dataset.group];
						show = ! term || row._match || ( parent && parent._match );
					} else {
						show = ! term || !! groups[row.dataset.group];
					}
					row.style.display = show ? '' : 'none';
				});
				sync();
			}
			search.addEventListener('input', filter);
			toggle.addEventListener('click', function(){
				var boxes = visibleBoxes();
				var check = ! ( boxes.length && boxes.every(function(item){ return item.checked; }) );
				boxes.forEach(function(item){ item.checked = check; });
				sync();
			});
			table.addEventListener('change', sync);
			filter();
		})();
		</script>
		<?php
	}

	private static function render_menu_row( $kind, $group, $slug, $title, $origin, $grouped, $is_protected, $checked, $note ) {
		$origin = '' !== (string) $origin ? (string) $origin : '—';
		$search = strtolower( $title . ' ' . $slug . ' ' . $origin );
		?>
		<tr class="premiero-access-<?php echo esc_attr( $kind ); ?>" data-kind="<?php echo esc_attr( $kind ); ?>" data-group="<?php echo esc_attr( (string) $group ); ?>" data-search="<?php echo esc_attr( $search ); ?>">
			<td><?php echo esc_html( $title ); ?></td>
			<td><code><?php echo esc_html( $slug ); ?></code></td>
			<td><?php echo esc_html( $origin ); ?></td>
			<td><?php echo $grouped ? '<span class="premiero-notice-state is-visible">Sí</span>' : '—'; ?></td>
			<td>
				<?php if ( $is_protected ) : ?>
					<span class="premiero-notice-state">Protegido</span>
				<?php elseif ( '' !== $note ) : ?>
					<small class="premiero-access-note"><?php echo esc_html( $note ); ?></small>
				<?php else : ?>
					<input type="hidden" name="menu_known[]" value="<?php echo esc_attr( $slug ); ?>">
					<label><input type="checkbox" class="premiero-access-hide" name="menu[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $checked ); ?>> Ocultar</label>
					<?php if ( 'child' === $kind ) : ?>
						<small class="premiero-access-inherited">Oculto con el padre</small>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
