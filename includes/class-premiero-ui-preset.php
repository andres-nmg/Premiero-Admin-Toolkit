<?php
/**
 * Preset "Hub" para el administrador y la pantalla de acceso.
 *
 * Panel de gestión claro: barra superior limpia, menú lateral con tarjetas,
 * avatares con inicial, escritorio sin noticias de WordPress y un acceso de dos
 * columnas. Se activa por separado desde las pestañas Apariencia y Login.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_UI_Preset {

	const OPTION = 'premiero_ui_preset';
	/** Elementos de la barra superior detectados (id => nombre), para elegir cuáles se muestran. */
	const SEEN_OPTION = 'premiero_ui_preset_bar_seen';
	const ACCENT = '#78290f';

	private static $settings = null;

	/* ====================== Ajustes ====================== */

	public static function settings() {
		if ( null === self::$settings ) {
			$stored         = get_option( self::OPTION, array() );
			$stored         = is_array( $stored ) ? $stored : array();
			self::$settings = array(
				'admin'     => empty( $stored['admin'] ) ? 0 : 1,
				'login'     => empty( $stored['login'] ) ? 0 : 1,
				// null = nunca configurado: se usan las opciones por defecto.
				'new_items' => isset( $stored['new_items'] ) && is_array( $stored['new_items'] ) ? array_values( array_filter( array_map( 'strval', $stored['new_items'] ) ) ) : null,
				'site_url'  => isset( $stored['site_url'] ) ? esc_url_raw( (string) $stored['site_url'] ) : '',
				'bar_hidden' => isset( $stored['bar_hidden'] ) && is_array( $stored['bar_hidden'] ) ? array_values( array_filter( array_map( 'strval', $stored['bar_hidden'] ) ) ) : array(),
				'links'      => isset( $stored['links'] ) && is_array( $stored['links'] ) ? self::clean_links( $stored['links'] ) : array(),
			);
		}
		return self::$settings;
	}

	/** Opciones del botón "Añadir" si nunca se ha configurado. */
	private static function default_new_items() {
		return array( 'pt:pcont_contact', 'pt:plcrm_lead', 'pt:pkanban_board', 'pt:post' );
	}

	/** Opciones elegidas para el botón "Añadir", en orden. */
	public static function selected_new_items() {
		$items = self::settings()['new_items'];
		return null === $items ? self::default_new_items() : $items;
	}

	/** URL del botón "Ver web": la portada salvo que se personalice. */
	public static function site_url() {
		$url = self::settings()['site_url'];
		return '' !== $url ? $url : home_url( '/' );
	}

	/** Guarda las opciones de la barra superior del preset. */
	public static function save_admin_bar( $new_items, $site_url, $bar_hidden = null, $links = null ) {
		$settings              = self::settings();
		$settings['new_items'] = array_values( array_unique( array_filter( array_map( 'strval', (array) $new_items ) ) ) );
		$settings['site_url']  = esc_url_raw( trim( (string) $site_url ) );
		if ( null !== $bar_hidden ) {
			$settings['bar_hidden'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $bar_hidden ) ) ) );
		}
		if ( null !== $links ) {
			$settings['links'] = self::clean_links( $links );
		}
		update_option( self::OPTION, $settings, true );
		self::$settings = $settings;
	}

	/** Enlaces personalizados válidos: texto, URL y si se abren en pestaña nueva. */
	public static function clean_links( $links ) {
		$out = array();
		foreach ( (array) $links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$label = trim( sanitize_text_field( (string) ( $link['label'] ?? '' ) ) );
			$url   = esc_url_raw( trim( (string) ( $link['url'] ?? '' ) ) );
			if ( '' === $label || '' === $url ) {
				continue;
			}
			$out[] = array(
				'label' => $label,
				'url'   => $url,
				'new'   => empty( $link['new'] ) ? 0 : 1,
			);
		}
		return $out;
	}

	/** Elementos de la barra que no se pueden ocultar desde la lista: los propios del Hub y la cuenta. */
	private static function fixed_bar_node( $id ) {
		return in_array( $id, array( 'menu-toggle', 'top-secondary', 'root-default', 'my-account', 'new-content' ), true ) || 0 === strpos( $id, 'hubui-' );
	}

	/** Elementos de la barra detectados hasta ahora (id => nombre). */
	public static function seen_bar_nodes() {
		$seen = get_option( self::SEEN_OPTION, array() );
		return is_array( $seen ) ? $seen : array();
	}

	/**
	 * Anota los elementos de primer nivel de la barra (los que añaden WordPress y
	 * los plugins). Solo con un administrador con acceso total, para que la lista
	 * esté completa, y solo se escribe si aparece alguno nuevo o cambia de nombre.
	 */
	public static function record_bar_nodes() {
		global $wp_admin_bar;
		if ( ! $wp_admin_bar instanceof WP_Admin_Bar || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( class_exists( 'Premiero_Access_Restrictions' ) && Premiero_Access_Restrictions::is_restricted_user() ) {
			return;
		}
		$seen    = self::seen_bar_nodes();
		$changed = false;
		foreach ( (array) $wp_admin_bar->get_nodes() as $node ) {
			if ( ! empty( $node->group ) || ! in_array( (string) $node->parent, array( '', 'top-secondary' ), true ) || self::fixed_bar_node( $node->id ) ) {
				continue;
			}
			$title = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $node->title, true ) ) );
			$title = '' !== $title ? $title : (string) $node->id;
			if ( ! isset( $seen[ $node->id ] ) || $seen[ $node->id ] !== $title ) {
				$seen[ $node->id ] = $title;
				$changed           = true;
			}
		}
		if ( $changed ) {
			update_option( self::SEEN_OPTION, $seen, false );
		}
	}

	/** Quita de la barra los elementos desmarcados en Apariencia. */
	public static function apply_bar_visibility() {
		global $wp_admin_bar;
		if ( ! $wp_admin_bar instanceof WP_Admin_Bar ) {
			return;
		}
		foreach ( self::settings()['bar_hidden'] as $id ) {
			if ( ! self::fixed_bar_node( $id ) ) {
				$wp_admin_bar->remove_node( $id );
			}
		}
	}

	/** Enlaces personalizados, a continuación de los botones del Hub. */
	public static function bar_custom_links( $bar ) {
		foreach ( self::settings()['links'] as $index => $link ) {
			$meta = array( 'class' => 'hubui-custom-link' );
			if ( ! empty( $link['new'] ) ) {
				$meta['target'] = '_blank';
				$meta['rel']    = 'noopener';
			}
			$bar->add_node(
				array(
					'id'    => 'hubui-link-' . $index,
					'title' => esc_html( $link['label'] ),
					'href'  => esc_url( $link['url'] ),
					'meta'  => $meta,
				)
			);
		}
	}

	/**
	 * Lo que se puede crear desde "Añadir", detectado de lo instalado: cada tipo
	 * de contenido con pantalla de administración, más medios y usuarios.
	 * Algunos módulos crean sus elementos desde una pantalla propia.
	 *
	 * @return array<string, array{label:string, detail:string, url:string, cap:string}>
	 */
	public static function new_item_candidates() {
		$own_screens = array(
			'pcont_contact' => 'admin.php?page=premiero-contactos&nuevo=1',
			'pkanban_board' => 'admin.php?page=premiero-kanban-templates',
		);
		$items = array();
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			// Tipos con pantalla de WordPress, o con pantalla propia del módulo.
			if ( ( empty( $type->show_ui ) && ! isset( $own_screens[ $type->name ] ) ) || 'attachment' === $type->name || 0 === strpos( $type->name, 'wp_' ) ) {
				continue;
			}
			if ( 'pcont_contact' === $type->name && function_exists( 'pcont_capability' ) ) {
				$cap = pcont_capability();
			} elseif ( 'pkanban_board' === $type->name ) {
				$cap = 'manage_options';
			} else {
				$cap = isset( $type->cap->create_posts ) ? $type->cap->create_posts : 'edit_posts';
			}
			$label = ! empty( $type->labels->singular_name ) ? $type->labels->singular_name : $type->label;
			$items[ 'pt:' . $type->name ] = array(
				'label'  => (string) $label,
				'detail' => $type->name,
				'url'    => isset( $own_screens[ $type->name ] ) ? $own_screens[ $type->name ] : ( 'post' === $type->name ? 'post-new.php' : 'post-new.php?post_type=' . $type->name ),
				'cap'    => (string) $cap,
			);
		}
		$items['media'] = array(
			'label'  => 'Medio',
			'detail' => 'media-new.php',
			'url'    => 'media-new.php',
			'cap'    => 'upload_files',
		);
		$items['user'] = array(
			'label'  => 'Usuario',
			'detail' => 'user-new.php',
			'url'    => 'user-new.php',
			'cap'    => 'create_users',
		);
		return $items;
	}

	public static function admin_enabled() {
		return (bool) self::settings()['admin'];
	}

	public static function login_enabled() {
		return (bool) self::settings()['login'];
	}

	/** Guarda uno de los dos interruptores ("admin" o "login"). */
	public static function save( $key, $enabled ) {
		$settings = self::settings();
		if ( ! array_key_exists( $key, $settings ) ) {
			return;
		}
		$settings[ $key ] = $enabled ? 1 : 0;
		// Autoload activo: se consulta en cada carga del admin y del acceso.
		update_option( self::OPTION, $settings, true );
		self::$settings = $settings;
	}

	public static function init() {
		if ( self::admin_enabled() ) {
			add_filter( 'get_avatar', array( __CLASS__, 'avatar' ), 10, 3 );
			add_action( 'admin_bar_menu', array( __CLASS__, 'bar_brand' ), 1 );
			add_action( 'admin_bar_menu', array( __CLASS__, 'bar_collapse' ), 2 );
			add_action( 'admin_bar_menu', array( __CLASS__, 'bar_cleanup' ), 999 );
			add_action( 'admin_bar_menu', array( __CLASS__, 'bar_new_items' ), 999 );
			add_action( 'admin_bar_menu', array( __CLASS__, 'bar_view_site' ), 1000 );
			add_action( 'admin_bar_menu', array( __CLASS__, 'bar_custom_links' ), 1001 );
			add_action( 'wp_before_admin_bar_render', array( __CLASS__, 'record_bar_nodes' ), PHP_INT_MAX - 20 );
			add_action( 'wp_before_admin_bar_render', array( __CLASS__, 'apply_bar_visibility' ), PHP_INT_MAX - 10 );
			add_action( 'admin_footer', array( __CLASS__, 'collapse_script' ), 99 );
			add_filter( 'get_user_option_admin_color', array( __CLASS__, 'admin_color' ) );
			add_action( 'admin_init', array( __CLASS__, 'remove_color_picker' ) );
			add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard' ) );
			add_action( 'admin_head', array( __CLASS__, 'remove_help_tabs' ) );
			add_filter( 'admin_footer_text', array( __CLASS__, 'footer_text' ), 11 );
			add_filter( 'update_footer', '__return_empty_string', 11 );
			add_filter( 'admin_title', array( __CLASS__, 'admin_title' ), 10, 2 );
			add_action( 'wp_head', array( __CLASS__, 'front_styles' ), 99 );
			add_action( 'admin_head', array( __CLASS__, 'admin_styles' ), 99 );
		}

		if ( self::login_enabled() ) {
			add_filter( 'login_headerurl', array( __CLASS__, 'home_url' ) );
			add_filter( 'login_headertext', array( __CLASS__, 'name' ) );
			add_filter( 'login_display_language_dropdown', '__return_false' );
			add_filter( 'login_title', array( __CLASS__, 'login_title' ) );
			add_action( 'login_header', array( __CLASS__, 'login_brand' ) );
			add_filter( 'login_message', array( __CLASS__, 'login_intro' ) );
			add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_styles' ) );
		} elseif ( get_option( PREMIERO_OPT_LOGIN_MODERN, false ) ) {
			// Login normal con el formulario del Hub y el botón personalizado.
			add_action( 'login_enqueue_scripts', array( __CLASS__, 'classic_login_styles' ), 30 );
			add_action( 'login_footer', array( __CLASS__, 'classic_login_label' ) );
		}
	}

	/* ====================== Ayudas ====================== */

	public static function name() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	public static function home_url() {
		return home_url( '/' );
	}

	private static function logo_url() {
		$icon = get_site_icon_url( 64 );
		return $icon ? $icon : '';
	}

	private static function shade( $hex, $factor ) {
		list( $r, $g, $b ) = self::rgb( $hex );
		return sprintf( '#%02x%02x%02x', round( $r * $factor ), round( $g * $factor ), round( $b * $factor ) );
	}

	private static function rgb( $hex ) {
		$r = 0;
		$g = 0;
		$b = 0;
		sscanf( ltrim( $hex, '#' ), '%02x%02x%02x', $r, $g, $b );
		return array( (int) $r, (int) $g, (int) $b );
	}

	/** Crédito con el nombre de la pestaña Identidad (enlazado salvo en marca blanca). */
	private static function credit_html() {
		$brand = premiero_get_brand_name();
		if ( premiero_is_white_label() ) {
			return esc_html( $brand );
		}
		return '<a href="https://premiero.es" target="_blank" rel="noopener noreferrer">' . esc_html( $brand ) . '</a>';
	}

	/** Las fuentes van incluidas en el plugin; su carpeta solo responde con sesión iniciada. */
	private static function font_faces() {
		$base = PREMIERO_ATK_ASSETS . 'fonts/';
		return '@font-face{font-family:"Maven Pro";src:url("' . esc_url_raw( $base . 'maven-pro/MavenPro-Variable.ttf' ) . '") format("truetype");font-weight:400 900;font-style:normal;font-display:swap}'
			. '@font-face{font-family:"IBM Plex Mono";src:url("' . esc_url_raw( $base . 'ibm-plex-mono/IBMPlexMono-Regular.woff2' ) . '") format("woff2");font-weight:400 500;font-style:normal;font-display:swap}'
			. '@font-face{font-family:"IBM Plex Mono";src:url("' . esc_url_raw( $base . 'ibm-plex-mono/IBMPlexMono-SemiBold.woff2' ) . '") format("woff2");font-weight:600 700;font-style:normal;font-display:swap}';
	}

	/** Variables CSS comunes a acceso, barra superior y administrador. */
	private static function tokens_css( $with_fonts, $accent = self::ACCENT, $on_accent = '' ) {
		list( $r, $g, $b ) = self::rgb( $accent );
		$rgb           = $r . ',' . $g . ',' . $b;
		// Rojo de la marca: tonos oscuros fijados; otros colores se oscurecen en proporción.
		$is_brand = self::ACCENT === strtolower( $accent );
		$dark1    = $is_brand ? '#5c1f0d' : self::shade( $accent, 0.77 );
		$dark2    = $is_brand ? '#4a190a' : self::shade( $accent, 0.62 );

		return ( $with_fonts ? self::font_faces() : '' )
			. ':root{'
			. '--hubui-bg:#F0EFEC;'
			. '--hubui-surface:#FFFFFF;'
			. '--hubui-surface-2:#F8F7F5;'
			. '--hubui-text:#383633;'
			. '--hubui-muted:#6F6C68;'
			. '--hubui-border:#DEDCD8;'
			. '--hubui-border-strong:#C9C6C0;'
			. '--hubui-accent:' . $accent . ';'
			. '--hubui-accent-dark:' . $dark1 . ';'
			. ( '' !== $on_accent ? '--hubui-on-accent:' . $on_accent . ';' : '' )
			. '--hubui-accent-rgb:' . $rgb . ';'
			. '--hubui-tint:rgba(' . $rgb . ',.09);'
			. '--hubui-tint-solid:#F6EBE4;'
			. '--hubui-tint-border:#E7D5CA;'
			. '--hubui-track:#EBDDD3;'
			. '--hubui-ring:rgba(' . $rgb . ',.28);'
			. '--hubui-olive:#4F8A5B;--hubui-olive-bg:rgba(79,138,91,.13);'
			. '--hubui-ochre:#D99A2B;--hubui-ochre-bg:rgba(217,154,43,.14);'
			. '--hubui-teal:#2F7F86;--hubui-teal-bg:rgba(47,127,134,.12);'
			. '--hubui-plum:#8A4B7A;--hubui-plum-bg:rgba(138,75,122,.12);'
			. '--hubui-taupe:#8C857B;--hubui-taupe-bg:rgba(140,133,123,.16);'
			. '--hubui-danger:#C62E1F;--hubui-danger-bg:rgba(198,46,31,.10);'
			. '--hubui-radius-card:14px;'
			. '--hubui-radius-control:8px;'
			. '--hubui-shadow:0 1px 2px rgba(56,54,51,.05),0 6px 20px rgba(56,54,51,.07);'
			. '--hubui-shadow-hover:0 2px 4px rgba(56,54,51,.06),0 10px 28px rgba(56,54,51,.11);'
			. '--hubui-font-body:"Maven Pro",ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;'
			. '--hubui-font-heading:"IBM Plex Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;'
			. '--hubui-bar-h:56px;'
			// Variables propias de WordPress: controles, foco, casillas y botones heredan el acento.
			. '--wp-admin-theme-color:' . $accent . ';'
			. '--wp-admin-theme-color--rgb:' . $rgb . ';'
			. '--wp-admin-theme-color-darker-10:' . $dark1 . ';'
			. '--wp-admin-theme-color-darker-20:' . $dark2 . ';'
			. '}'
			. '@media (min-width:783px){:root{--wp-admin--admin-bar--height:56px}}';
	}

	private static function print_style( $id, $css ) {
		echo '<style id="' . esc_attr( $id ) . '">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS propio con valores fijos.
	}

	/* ====================== Avatar local (sin Gravatar) ====================== */

	public static function avatar( $avatar, $id_or_email, $size ) {
		$user = false;
		if ( $id_or_email instanceof WP_User ) {
			$user = $id_or_email;
		} elseif ( is_numeric( $id_or_email ) ) {
			$user = get_userdata( (int) $id_or_email );
		} elseif ( $id_or_email instanceof WP_Post ) {
			$user = get_userdata( (int) $id_or_email->post_author );
		} elseif ( $id_or_email instanceof WP_Comment ) {
			$user = $id_or_email->user_id ? get_userdata( (int) $id_or_email->user_id ) : false;
		} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
		}

		$name   = $user && $user->display_name ? $user->display_name : '?';
		$letter = function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( trim( $name ), 0, 1 ) ) : strtoupper( substr( trim( $name ), 0, 1 ) );
		$letter = htmlspecialchars( '' !== $letter ? $letter : '?', ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		$size   = max( 16, (int) $size );

		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="' . self::ACCENT . '"/>'
			. '<text x="32" y="32" dy=".35em" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="28" font-weight="600" fill="#fff">' . $letter . '</text></svg>';

		return '<img alt="" src="data:image/svg+xml;base64,' . base64_encode( $svg ) . '" class="avatar avatar-' . $size . ' photo" height="' . $size . '" width="' . $size . '" loading="lazy" decoding="async">';
	}

	/* ====================== Barra superior ====================== */

	/** Marca a la izquierda (justo después del botón de menú móvil). */
	public static function bar_brand( $bar ) {
		$logo  = self::logo_url();
		$name  = self::name();
		$title = '';
		if ( '' !== $logo ) {
			$title .= '<img class="hubui-ab-logo" src="' . esc_url( $logo ) . '" alt="">';
		}
		$title .= '<span class="hubui-ab-name">' . esc_html( $name ) . '</span>';

		$bar->add_node(
			array(
				'id'    => 'hubui-brand',
				'title' => $title,
				'href'  => admin_url(),
				'meta'  => array(
					'title' => $name,
					'class' => 'hubui-ab-brand',
				),
			)
		);
	}

	/** Flecha para plegar o desplegar el menú lateral (el script del pie acciona el botón nativo). */
	public static function bar_collapse( $bar ) {
		if ( ! is_admin() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'hubui-collapse',
				'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="screen-reader-text">Plegar o desplegar menú</span>',
				'href'  => '#',
				'meta'  => array( 'title' => 'Plegar o desplegar menú' ),
			)
		);
	}

	public static function collapse_script() {
		?>
		<script>
		document.addEventListener('click', function (e) {
			var link = e.target.closest ? e.target.closest('#wp-admin-bar-hubui-collapse > a') : null;
			if (!link) { return; }
			e.preventDefault();
			var btn = document.getElementById('collapse-button');
			if (btn) { btn.click(); }
		});
		</script>
		<?php
	}

	/** Quita lo de WordPress, deja Nuevo, Actualizaciones y Cuenta, y limpia el saludo. */
	public static function bar_cleanup( $bar ) {
		$drop = array( 'wp-logo', 'site-name', 'comments', 'customize', 'search', 'archive', 'view', 'edit', 'view-site', 'command-palette' );

		do {
			$added = false;
			foreach ( (array) $bar->get_nodes() as $node ) {
				if ( ! in_array( $node->id, $drop, true ) && in_array( $node->parent, $drop, true ) ) {
					$drop[] = $node->id;
					$added  = true;
				}
			}
		} while ( $added );

		foreach ( $drop as $id ) {
			$bar->remove_node( $id );
		}

		$user = wp_get_current_user();
		if ( $user->exists() && $bar->get_node( 'my-account' ) ) {
			$bar->add_node(
				array(
					'id'    => 'my-account',
					'title' => '<span class="display-name">' . esc_html( $user->display_name ) . '</span>' . get_avatar( $user->ID, 32 ),
				)
			);
		}
	}

	/**
	 * Botón "Añadir" con las opciones elegidas en la pestaña Apariencia.
	 * Cada opción aparece solo si su tipo de contenido existe y el usuario puede usarla.
	 */
	public static function bar_new_items( $bar ) {
		if ( ! $bar->get_node( 'new-content' ) ) {
			return;
		}
		// Viendo la web no se muestra: solo dentro del administrador.
		if ( ! is_admin() ) {
			$bar->remove_node( 'new-content' );
			return;
		}
		$candidates = self::new_item_candidates();
		$items      = array();
		foreach ( self::selected_new_items() as $key ) {
			if ( isset( $candidates[ $key ] ) && current_user_can( $candidates[ $key ]['cap'] ) ) {
				$items[ 'hubui-new-' . sanitize_key( str_replace( ':', '-', $key ) ) ] = $candidates[ $key ];
			}
		}
		foreach ( (array) $bar->get_nodes() as $node ) {
			if ( 'new-content' === $node->parent ) {
				$bar->remove_node( $node->id );
			}
		}
		// Sin opciones disponibles, el botón no tiene sentido.
		if ( empty( $items ) ) {
			$bar->remove_node( 'new-content' );
			return;
		}
		// El botón principal lleva a la primera opción elegida.
		$first = reset( $items );
		$bar->add_node(
			array(
				'id'   => 'new-content',
				'href' => admin_url( $first['url'] ),
			)
		);
		foreach ( $items as $id => $item ) {
			$bar->add_node(
				array(
					'parent' => 'new-content',
					'id'     => $id,
					'title'  => esc_html( $item['label'] ),
					'href'   => admin_url( $item['url'] ),
				)
			);
		}
	}

	/** Botón "Ver web", al lado de "Añadir", que abre la web en otra pestaña. */
	public static function bar_view_site( $bar ) {
		if ( ! is_admin() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'hubui-view',
				'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">Ver web</span>',
				'href'  => esc_url( self::site_url() ),
				'meta'  => array(
					'target' => '_blank',
					'rel'    => 'noopener',
					'title'  => 'Ver la web en una pestaña nueva',
				),
			)
		);
	}

	/* ====================== Administrador: limpieza ====================== */

	/** Siempre el esquema por defecto: el resto de esquemas rompería la paleta. */
	public static function admin_color() {
		return 'fresh';
	}

	public static function remove_color_picker() {
		remove_action( 'admin_color_scheme_picker', 'admin_color_scheme_picker' );
	}

	/** Escritorio sin noticias de WordPress ni peticiones externas. */
	public static function dashboard() {
		remove_action( 'welcome_panel', 'wp_welcome_panel' );
		foreach (
			array(
				array( 'dashboard_primary', 'side' ),
				array( 'dashboard_secondary', 'side' ),
				array( 'dashboard_quick_press', 'side' ),
				array( 'dashboard_site_health', 'normal' ),
				array( 'dashboard_right_now', 'normal' ),
				array( 'dashboard_activity', 'normal' ),
			) as $box
		) {
			remove_meta_box( $box[0], 'dashboard', $box[1] );
		}

		self::migrate_dashboard_layout();

		// Tarjeta provisional hasta que los módulos añadan sus propios widgets.
		wp_add_dashboard_widget(
			'hubui_welcome',
			'Bienvenido/a',
			function () {
				$user = wp_get_current_user();
				echo '<p class="hubui-welcome-title">' . esc_html( sprintf( 'Hola, %s', $user->display_name ) ) . '</p>';
				echo '<p>Desde el menú lateral accedes a los módulos activos de este panel.</p>';
			}
		);
	}

	/**
	 * Las webs que usaban la versión en snippet guardan, por usuario, el orden y el
	 * estado de la tarjeta de bienvenida con su identificador anterior. Se traslada
	 * al nuevo para que la tarjeta siga en su sitio.
	 */
	private static function migrate_dashboard_layout() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$old = 'pmh_ui_welcome';
		$new = 'hubui_welcome';
		foreach ( array( 'meta-box-order_dashboard', 'closedpostboxes_dashboard', 'metaboxhidden_dashboard' ) as $key ) {
			$value = get_user_meta( $user_id, $key, true );
			if ( ! is_array( $value ) || false === strpos( wp_json_encode( $value ), $old ) ) {
				continue;
			}
			foreach ( $value as $index => $item ) {
				if ( is_string( $item ) ) {
					$ids             = explode( ',', $item );
					$value[ $index ] = implode( ',', array_map( function ( $id ) use ( $old, $new ) {
						return $id === $old ? $new : $id;
					}, $ids ) );
				}
			}
			update_user_meta( $user_id, $key, $value );
		}
	}

	/** Sin pestaña de ayuda contextual. */
	public static function remove_help_tabs() {
		$screen = get_current_screen();
		if ( $screen ) {
			$screen->remove_help_tabs();
		}
	}

	/** Pie y título de pestaña sin referencias a WordPress. */
	public static function footer_text() {
		return 'Panel gestionado con ' . self::credit_html();
	}

	public static function admin_title( $admin_title, $title ) {
		return '' !== trim( (string) $title ) ? $title . ' ‹ ' . self::name() : self::name();
	}

	/* ====================== CSS: barra superior y administrador ====================== */

	private static function adminbar_css() {
		return <<<'CSS'
/* ---------- Barra superior clara ---------- */
#wpadminbar{background:#fff;color:var(--hubui-text);border-bottom:1px solid var(--hubui-border);box-shadow:none;font-family:var(--hubui-font-body)}
#wpadminbar .ab-item,#wpadminbar a.ab-item,#wpadminbar>#wp-toolbar span.ab-label,#wpadminbar>#wp-toolbar span.noticon,#wpadminbar .ab-empty-item{color:var(--hubui-text);font-family:var(--hubui-font-body);font-size:14px}
#wpadminbar .ab-icon:before,#wpadminbar .ab-item:before,#wpadminbar #adminbarsearch:before{color:var(--hubui-muted);top:0;padding:0}
#wpadminbar .ab-icon{float:none;padding:0;top:0}
#wpadminbar .ab-top-menu>li:hover>.ab-item,#wpadminbar .ab-top-menu>li.hover>.ab-item,#wpadminbar .ab-top-menu>li>.ab-item:focus,
#wpadminbar:not(.mobile) .ab-top-menu>li:hover>.ab-item,#wpadminbar:not(.mobile) .ab-top-menu>li>.ab-item:focus,
#wpadminbar .ab-top-secondary>li:hover>.ab-item,#wpadminbar .ab-top-secondary>li.hover>.ab-item{background:var(--hubui-tint);color:var(--hubui-text)}
#wpadminbar li:hover .ab-icon:before,#wpadminbar li:hover>.ab-item:before,#wpadminbar li.hover .ab-icon:before,#wpadminbar li.hover>.ab-item:before{color:var(--hubui-text)}

/* Desplegables */
#wpadminbar .menupop .ab-sub-wrapper,#wpadminbar .shortlink-input{background:#fff;border:1px solid var(--hubui-border);border-radius:12px;box-shadow:var(--hubui-shadow);overflow:hidden}
#wpadminbar .quicklinks .menupop ul li a,#wpadminbar .quicklinks .menupop ul li a strong,#wpadminbar .quicklinks .menupop.hover ul li a,#wpadminbar.nojs .quicklinks .menupop:hover ul li a{color:var(--hubui-text)}
#wpadminbar .quicklinks .menupop ul li a:hover,#wpadminbar .quicklinks .menupop ul li a:focus,#wpadminbar .quicklinks .menupop ul li:hover>a,#wpadminbar .quicklinks .menupop.hover ul li a:hover{background:var(--hubui-tint);color:var(--hubui-text)}
#wpadminbar .ab-submenu .ab-item,#wpadminbar .quicklinks .menupop ul.ab-sub-secondary,#wpadminbar .quicklinks .menupop ul.ab-sub-secondary .ab-submenu{background:transparent}
#wpadminbar #wp-admin-bar-user-info .display-name{color:var(--hubui-text);font-weight:600}
#wpadminbar #wp-admin-bar-user-info .username{color:var(--hubui-muted)}
#wpadminbar #wp-admin-bar-user-info .avatar{border-radius:50%}

/* Marca */
#wpadminbar #wp-admin-bar-hubui-brand>.ab-item{display:flex;align-items:center;gap:10px;padding:0 16px;font-family:var(--hubui-font-heading);font-weight:700;font-size:15px;letter-spacing:-.01em}
#wpadminbar .hubui-ab-logo{display:block;height:26px;width:auto;max-width:140px;border-radius:6px}
#wpadminbar .hubui-ab-name{font-family:var(--hubui-font-heading);font-weight:700;font-size:15px;line-height:1.2;color:var(--hubui-text)}

/* Flecha de plegar menú (solo escritorio) */
#wpadminbar #wp-admin-bar-hubui-collapse{display:none}

/* Cuenta: nombre + avatar redondo */
#wpadminbar #wp-admin-bar-my-account.with-avatar>a.ab-item{display:flex;align-items:center;flex-direction:row}
#wpadminbar #wp-admin-bar-my-account.with-avatar>a.ab-item img{order:2;position:static;float:none;width:34px;height:34px;margin:0 0 0 10px;padding:0;border:0;border-radius:50%;background:var(--hubui-accent)}
#wpadminbar #wp-admin-bar-my-account .display-name{font-weight:600}

/* Botón «Nuevo» */
#wpadminbar #wp-admin-bar-new-content{margin:0 8px}
#wpadminbar #wp-admin-bar-new-content>.ab-item{display:flex;align-items:center;box-sizing:border-box;margin:10px 0 0;height:36px;line-height:34px;padding:0 14px 0 6px;background:#fff;border:1px solid var(--hubui-border);border-radius:10px;box-shadow:none;font-weight:600}
#wpadminbar #wp-admin-bar-new-content .ab-icon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 24px;box-sizing:border-box;width:24px;min-width:24px;height:24px;min-height:24px;max-height:24px;margin:0 8px 0 0;padding:0;border-radius:7px;background:var(--hubui-accent);color:#fff;line-height:24px;overflow:visible}
#wpadminbar #wp-admin-bar-new-content .ab-icon:not(.svg-icon):before{content:"\f132";display:block;float:none;width:24px;height:24px;margin:0;padding:0;color:#fff;font-size:16px;line-height:24px;text-align:center;top:0}
#wpadminbar #wp-admin-bar-new-content .ab-icon.svg-icon:before{display:none}
#wpadminbar #wp-admin-bar-new-content .ab-icon svg{display:block;width:16px;height:16px;fill:none;stroke:#fff;color:#fff}
#wpadminbar #wp-admin-bar-new-content .ab-icon svg path{stroke:#fff}
#wpadminbar #wp-admin-bar-new-content:hover>.ab-item,#wpadminbar #wp-admin-bar-new-content.hover>.ab-item{background:var(--hubui-tint)}
#wpadminbar #wp-admin-bar-new-content:hover .ab-icon,#wpadminbar #wp-admin-bar-new-content.hover .ab-icon{background:var(--hubui-accent-dark)}
#wpadminbar>#wp-toolbar li:hover span.ab-label,#wpadminbar>#wp-toolbar li.hover span.ab-label,#wpadminbar li:hover>.ab-item .ab-label,#wpadminbar li.hover>.ab-item .ab-label{color:var(--hubui-text)}

/* Actualizaciones pendientes */
#wpadminbar #wp-admin-bar-updates .ab-icon:before{color:var(--hubui-muted)}

/* ---------- Escritorio: barra de 56 px ---------- */
@media (min-width:783px){
	html.wp-toolbar{padding-top:var(--hubui-bar-h)}
	#wpadminbar{height:var(--hubui-bar-h)}
	#wpadminbar .ab-top-menu>li>.ab-item,#wpadminbar .ab-top-secondary>li>.ab-item,#wpadminbar #wp-admin-bar-my-account>.ab-item{height:var(--hubui-bar-h);line-height:var(--hubui-bar-h);padding:0 14px}
	#wpadminbar #wp-admin-bar-my-account.with-avatar>a.ab-item{display:flex}
	#wpadminbar #wp-admin-bar-hubui-brand>.ab-item{height:var(--hubui-bar-h)}
	#wpadminbar #wp-admin-bar-new-content{height:var(--hubui-bar-h)}
	#wpadminbar #wp-admin-bar-new-content>.ab-item{height:36px;line-height:34px;padding:0 14px 0 6px}
	#wpadminbar #wp-admin-bar-new-content>.ab-sub-wrapper{margin-top:8px}
	#wpadminbar #wp-admin-bar-updates>.ab-item{display:flex;align-items:center}

	#wpadminbar #wp-admin-bar-hubui-collapse{display:block;height:var(--hubui-bar-h)}
	#wpadminbar #wp-admin-bar-hubui-collapse>.ab-item{display:flex;align-items:center;justify-content:center;box-sizing:border-box;width:36px;height:36px;margin:10px 0 0 10px;padding:0;line-height:1;border-radius:10px;background:transparent}
	#wpadminbar #wp-admin-bar-hubui-collapse>.ab-item:hover,#wpadminbar #wp-admin-bar-hubui-collapse>.ab-item:focus{background:var(--hubui-tint)}
	#wpadminbar #wp-admin-bar-hubui-collapse .ab-icon{display:block;width:20px;height:20px;margin:0;padding:0}
	#wpadminbar #wp-admin-bar-hubui-collapse .ab-icon:before{content:"\f341";display:block;width:20px;height:20px;margin:0;padding:0;top:0;font:normal 20px/20px dashicons;color:var(--hubui-muted);text-align:center}
	#wpadminbar #wp-admin-bar-hubui-collapse:hover .ab-icon:before{color:var(--hubui-text)}
	body.folded #wpadminbar #wp-admin-bar-hubui-collapse .ab-icon:before{content:"\f345"}
}
@media (min-width:961px){
	body:not(.folded) #wpadminbar #wp-admin-bar-hubui-brand{width:232px;border-right:1px solid var(--hubui-border)}
}

/* ---------- Móvil ---------- */
@media (max-width:782px){
	#wpadminbar .display-name,#wpadminbar .hubui-ab-name{display:none}
	#wpadminbar #wp-admin-bar-new-content>.ab-item{margin-top:5px;height:36px}
}
CSS;
	}

	private static function admin_css() {
		return <<<'CSS'
/* ---------- Base ---------- */
html.wp-toolbar,body.wp-admin,#wpwrap{background:var(--hubui-bg)}
body.wp-admin{color:var(--hubui-text);font-family:var(--hubui-font-body);font-size:14px}
body.wp-admin a{color:var(--hubui-accent)}
body.wp-admin a:hover,body.wp-admin a:focus{color:var(--hubui-accent-dark)}
body.wp-admin :focus-visible{outline:3px solid var(--hubui-ring);outline-offset:2px}

/* ---------- Menú lateral: blanco, con elemento activo en tarjeta ---------- */
body #adminmenuback,body #adminmenuwrap{background:#fff}
body #adminmenuback{border-right:1px solid var(--hubui-border)}
body #adminmenu{background:transparent;margin:8px 0 0;font-family:var(--hubui-font-body)}
body #adminmenu li.menu-top{border-radius:10px;margin:0 0 2px}
body #adminmenu li.menu-top>a{border-radius:10px;font-size:15px}
body #adminmenu a,body #adminmenu div.wp-menu-image:before{color:var(--hubui-muted)}
body #adminmenu li.menu-top:hover{background:transparent}
body #adminmenu li.menu-top:hover>a.menu-top,body #adminmenu li.opensub>a.menu-top,body #adminmenu li>a.menu-top:focus{background:var(--hubui-tint);color:var(--hubui-text)}
body #adminmenu li.menu-top:hover>a,body #adminmenu li.menu-top:hover div.wp-menu-image:before,body #adminmenu li.opensub>a.menu-top div.wp-menu-image:before,body #adminmenu li>a.menu-top:focus div.wp-menu-image:before{color:var(--hubui-text)}
body #adminmenu li.current a.menu-top,body #adminmenu li.wp-has-current-submenu a.wp-has-current-submenu,body #adminmenu li.wp-has-current-submenu.opensub a.wp-has-current-submenu{background:var(--hubui-tint);color:var(--hubui-accent);font-weight:600}
body #adminmenu li.current div.wp-menu-image:before,body #adminmenu li.wp-has-current-submenu div.wp-menu-image:before,body #adminmenu a.current:hover div.wp-menu-image:before{color:var(--hubui-accent)}
body #adminmenu div.wp-menu-image{color:var(--hubui-muted)}
body #adminmenu li.menu-top:hover div.wp-menu-image,body #adminmenu li.opensub div.wp-menu-image,body #adminmenu li>a.menu-top:focus div.wp-menu-image{color:var(--hubui-text)}
body #adminmenu li.current div.wp-menu-image,body #adminmenu li.wp-has-current-submenu div.wp-menu-image{color:var(--hubui-accent)}
body #adminmenu li.wp-has-submenu.wp-not-current-submenu.opensub:hover::after,body #adminmenu li.wp-has-submenu.wp-not-current-submenu:focus-within::after{display:none}
body #adminmenu .wp-menu-arrow,body #adminmenu .wp-menu-arrow div{display:none}
body ul#adminmenu a.wp-has-current-submenu:after,body ul#adminmenu>li.current>a.current:after{display:none}
body #adminmenu .wp-menu-separator .separator{background:var(--hubui-border)}
body #adminmenu .awaiting-mod,body #adminmenu span.update-plugins,body #adminmenu li .update-plugins{background:var(--hubui-accent);color:#fff}

/* Submenús (desplegable lateral) */
body #adminmenu .wp-submenu{background:#fff;border:1px solid var(--hubui-border);border-radius:12px;box-shadow:var(--hubui-shadow)}
body #adminmenu .wp-submenu a{color:var(--hubui-muted);font-size:14px;border-radius:8px}
body #adminmenu .wp-submenu a:hover,body #adminmenu .wp-submenu a:focus{color:var(--hubui-text);background:var(--hubui-tint)}
body #adminmenu .wp-submenu li.current>a,body #adminmenu .wp-submenu li.current>a:hover{color:var(--hubui-accent);font-weight:600;background:var(--hubui-tint)}
body #adminmenu .wp-submenu .wp-submenu-head,body #adminmenu .wp-has-current-submenu .wp-submenu .wp-submenu-head{background:transparent!important;color:var(--hubui-text)!important;font-family:var(--hubui-font-heading);font-weight:600}

/* Desplegable (menú plegado o elemento no activo): márgenes iguales en título y opciones */
body #adminmenu li.menu-top>.wp-submenu{padding:8px!important;margin:0}
body #adminmenu li.menu-top>.wp-submenu .wp-submenu-head{padding:8px 12px 6px!important;margin:0}
body #adminmenu li.menu-top>.wp-submenu li{margin:0!important;padding:0!important}
body #adminmenu li.menu-top>.wp-submenu li a{padding:8px 12px!important;margin:0}

/* Sin barras ni sombras interiores */
body #adminmenu a,body #adminmenu a:hover,body #adminmenu a:focus,body #adminmenu li,body #adminmenu li:hover{box-shadow:none!important}
body #adminmenu .wp-submenu li a::before,body #adminmenu .wp-submenu li a::after{content:none!important}
body #adminmenu a:focus-visible{outline:3px solid var(--hubui-ring);outline-offset:-3px}

/* ---------- Contenido ---------- */
#wpcontent{padding-left:32px}
#wpbody-content{padding-bottom:40px}
.wrap{margin:28px 32px 0 0}
#wpfooter{color:var(--hubui-muted)}
#wpfooter a{color:var(--hubui-text);font-weight:600}
#contextual-help-link-wrap{display:none}

body.wp-admin .wrap h1,body.wp-admin .wrap h1.wp-heading-inline{font-family:var(--hubui-font-heading);font-size:30px;font-weight:700;line-height:1.2;letter-spacing:-.02em;color:var(--hubui-text);padding:0;margin:0 12px 20px 0}
body.wp-admin .wrap h2,body.wp-admin .wrap h3{font-family:var(--hubui-font-heading);font-weight:600;color:var(--hubui-text)}
.wrap .page-title-action,.wrap .add-new-h2{top:-2px;padding:7px 14px;border:1px solid var(--hubui-border-strong);border-radius:var(--hubui-radius-control);background:#fff;color:var(--hubui-text);font-weight:600;line-height:1.2}
.wrap .page-title-action:hover,.wrap .page-title-action:focus{background:var(--hubui-tint);border-color:var(--hubui-border-strong);color:var(--hubui-text)}

/* ---------- Tarjetas ---------- */
.postbox,.card,.stuffbox,.notice-large{background:#fff;border:1px solid var(--hubui-border);border-radius:var(--hubui-radius-card);box-shadow:var(--hubui-shadow)}
.postbox .postbox-header{border-bottom:1px solid var(--hubui-border);border-radius:var(--hubui-radius-card) var(--hubui-radius-card) 0 0}
.postbox .hndle,.postbox-header .hndle{font-family:var(--hubui-font-heading);font-size:15px;font-weight:600;padding:14px 18px;color:var(--hubui-text)}
.postbox .inside{padding:0 18px 16px}
.postbox .handlediv,.postbox .handlediv:focus{color:var(--hubui-muted)}
#dashboard-widgets .postbox{margin-bottom:20px}
body.wp-admin img.avatar{border-radius:50%}
.hubui-welcome-title{font-family:var(--hubui-font-heading);font-size:20px;font-weight:700;margin:6px 0 8px}

/* ---------- Tablas ---------- */
table.widefat,.wp-list-table{border:1px solid var(--hubui-border);border-radius:12px;border-collapse:separate;border-spacing:0;box-shadow:var(--hubui-shadow);background:#fff;overflow:hidden}
table.widefat thead th,table.widefat thead td,.wp-list-table thead th,.wp-list-table thead td{background:var(--hubui-surface-2);border-bottom:1px solid var(--hubui-border);color:var(--hubui-text);font-weight:600}
table.widefat tfoot th,table.widefat tfoot td{background:var(--hubui-surface-2);border-top:1px solid var(--hubui-border)}
.striped>tbody>:nth-child(odd),ul.striped>:nth-child(odd),.alternate{background:#FBFAF9}
table.widefat td,table.widefat th{border-bottom-color:var(--hubui-border)}
.tablenav .tablenav-pages .current-page{min-height:32px}

/* ---------- Pestañas ---------- */
.nav-tab-wrapper,.wrap h2.nav-tab-wrapper{border-bottom:1px solid var(--hubui-border)}
.nav-tab{border:1px solid var(--hubui-border);border-bottom:0;border-radius:var(--hubui-radius-control) var(--hubui-radius-control) 0 0;background:transparent;color:var(--hubui-muted);font-weight:600}
.nav-tab:hover,.nav-tab:focus{background:var(--hubui-tint);color:var(--hubui-text)}
.nav-tab-active,.nav-tab-active:hover,.nav-tab-active:focus{background:#fff;color:var(--hubui-text);border-bottom:1px solid #fff}

/* ---------- Botones ---------- */
.wp-core-ui .button,.wp-core-ui .button-secondary{min-height:36px;padding:0 16px;line-height:34px;border:1px solid var(--hubui-border-strong);border-radius:var(--hubui-radius-control);background:#fff;color:var(--hubui-text);font-weight:600;box-shadow:none}
.wp-core-ui .button:hover,.wp-core-ui .button:focus,.wp-core-ui .button-secondary:hover{background:var(--hubui-tint);border-color:var(--hubui-border-strong);color:var(--hubui-text)}
.wp-core-ui .button-primary{background:var(--hubui-accent);border-color:var(--hubui-accent);color:#fff;text-shadow:none;box-shadow:none}
.wp-core-ui .button-primary:hover,.wp-core-ui .button-primary:focus{background:var(--hubui-accent-dark);border-color:var(--hubui-accent-dark);color:#fff}
.wp-core-ui .button-link-delete,.wp-core-ui .button.delete{color:var(--hubui-danger)}
.wp-core-ui .button.button-small,.wp-core-ui .button.button-small:hover{min-height:30px;line-height:28px;padding:0 10px}

/* ---------- Campos ---------- */
body.wp-admin input[type=text],body.wp-admin input[type=email],body.wp-admin input[type=url],body.wp-admin input[type=password],body.wp-admin input[type=search],body.wp-admin input[type=number],body.wp-admin input[type=date],body.wp-admin input[type=tel],body.wp-admin select,body.wp-admin textarea{min-height:38px;border:1px solid var(--hubui-border-strong);border-radius:var(--hubui-radius-control);background-color:#fff;color:var(--hubui-text);box-shadow:none;font-family:var(--hubui-font-body)}
body.wp-admin textarea{min-height:90px}
body.wp-admin input[type=text]:focus,body.wp-admin input[type=email]:focus,body.wp-admin input[type=url]:focus,body.wp-admin input[type=password]:focus,body.wp-admin input[type=search]:focus,body.wp-admin input[type=number]:focus,body.wp-admin input[type=date]:focus,body.wp-admin input[type=tel]:focus,body.wp-admin select:focus,body.wp-admin textarea:focus{border-color:var(--hubui-accent);box-shadow:0 0 0 3px var(--hubui-ring);outline:0}
body.wp-admin input[type=checkbox],body.wp-admin input[type=radio]{border-color:var(--hubui-border-strong);accent-color:var(--hubui-accent)}
.form-table th{color:var(--hubui-text);font-weight:600}
.description,p.description{color:var(--hubui-muted)}

/* ---------- Avisos (conservan su color de estado en el borde izquierdo) ---------- */
.notice,div.updated,div.error{background:#fff;border-top-color:var(--hubui-border);border-right-color:var(--hubui-border);border-bottom-color:var(--hubui-border);border-radius:10px;box-shadow:none}
.notice-info,.notice-warning{border-left-width:4px}
.notice-title,.notice h2{font-family:var(--hubui-font-heading)}

/* ---------- Escritorio: el botón nativo de plegar se sustituye por la flecha de la barra ---------- */
@media (min-width:783px){
	body #collapse-menu{display:none}
}

/* ---------- Escritorio ancho: menú de 232 px y elemento activo desplegado ---------- */
@media (min-width:961px){
	body:not(.folded) #adminmenuback,body:not(.folded) #adminmenuwrap,body:not(.folded) #adminmenu{width:232px}
	body:not(.folded) #adminmenu{box-sizing:border-box;padding:0 12px}
	body:not(.folded) #wpcontent,body:not(.folded) #wpfooter{margin-left:232px}
	body:not(.folded) #adminmenu .wp-submenu{left:232px}
	body:not(.folded) #adminmenu li.menu-top>a{padding-top:2px;padding-bottom:2px}

	/* Submenú del elemento activo: ancho completo, con sangría a la izquierda y sin título */
	body:not(.folded) #adminmenu li.wp-has-current-submenu>.wp-submenu{position:static;left:0;width:auto;min-width:0;margin:0;border:0;box-shadow:none;border-radius:0;background:transparent;padding:2px 0 6px!important}
	body:not(.folded) #adminmenu li.wp-has-current-submenu>.wp-submenu .wp-submenu-head{display:none!important}
	body:not(.folded) #adminmenu li.wp-has-current-submenu>.wp-submenu li{display:block;width:auto;margin:0 0 2px 16px!important;padding:0!important}
	body:not(.folded) #adminmenu li.wp-has-current-submenu>.wp-submenu li a{display:block;box-sizing:border-box;width:auto;margin:0;padding:8px 14px!important;line-height:1.35;white-space:normal}
}

/* ---------- Móvil ---------- */
@media (max-width:782px){
	#wpcontent{padding-left:16px}
	.wrap{margin-right:16px}
	body.wp-admin .wrap h1,body.wp-admin .wrap h1.wp-heading-inline{font-size:24px}
}
CSS;
	}

	public static function front_styles() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		self::print_style( 'hubui-adminbar', self::tokens_css( true ) . self::adminbar_css() . self::bar_fixes_css() . self::view_site_css() . '@media (min-width:783px){html{margin-top:var(--hubui-bar-h)!important}}' );
	}

	public static function admin_styles() {
		self::print_style( 'hubui-admin', self::tokens_css( true ) . self::adminbar_css() . self::admin_css() . self::bar_fixes_css() . self::view_site_css() );
	}

	/**
	 * Ajustes de la barra superior, en el admin y viendo la web:
	 * el "+" de Añadir dibujado con CSS (centrado exacto) y los iconos de los
	 * botones que añaden WordPress u otros plugins (Editar, Editar con Elementor…)
	 * alineados con su texto en la barra de 56 px.
	 */
	private static function bar_fixes_css() {
		// El "+" va en un pseudo-elemento propio: WordPress anula con !important el fondo de los iconos de la barra.
		$plus = 'background:linear-gradient(#fff,#fff) center/12px 2px no-repeat,linear-gradient(#fff,#fff) center/2px 12px no-repeat';
		// Estados de pasar el ratón, foco y clic: WordPress los pinta oscuros con reglas más específicas.
		$tint  = '{background:var(--hubui-tint)!important;color:var(--hubui-text)!important}';
		$icons = '{color:var(--hubui-text)!important}';
		return '#wpadminbar #wp-admin-bar-new-content .ab-icon{position:relative}'
			. '#wpadminbar #wp-admin-bar-new-content .ab-icon:before,#wpadminbar #wp-admin-bar-new-content .ab-icon:not(.svg-icon):before,#wpadminbar #wp-admin-bar-new-content .ab-icon svg{display:none!important}'
			. '#wpadminbar #wp-admin-bar-new-content .ab-icon:after{content:"";position:absolute;top:0;right:0;bottom:0;left:0;' . $plus . '}'
			. '#wpadminbar:not(.mobile) #wp-toolbar .ab-top-menu>li:hover>.ab-item,#wpadminbar #wp-toolbar .ab-top-menu>li.hover>.ab-item,'
			. '#wpadminbar #wp-toolbar .ab-top-menu>li>.ab-item:focus,#wpadminbar #wp-toolbar .ab-top-menu>li>.ab-item:active,'
			. '#wpadminbar.nojq #wp-toolbar .quicklinks .ab-top-menu>li>.ab-item:focus,'
			. '#wpadminbar:not(.mobile) #wp-toolbar .ab-top-secondary>li:hover>.ab-item,#wpadminbar #wp-toolbar .ab-top-secondary>li.hover>.ab-item,'
			. '#wpadminbar #wp-toolbar .ab-top-secondary>li>.ab-item:focus,#wpadminbar #wp-toolbar .ab-top-secondary>li>.ab-item:active,'
			. '#wpadminbar #wp-toolbar .quicklinks .menupop ul li a:hover,#wpadminbar #wp-toolbar .quicklinks .menupop ul li a:focus,#wpadminbar #wp-toolbar .quicklinks .menupop ul li a:active,'
			. '#wpadminbar #wp-toolbar .quicklinks .menupop ul li:hover>a,#wpadminbar #wp-toolbar .quicklinks .menupop.hover ul li a:hover' . $tint
			. '#wpadminbar #wp-toolbar li:hover .ab-icon:before,#wpadminbar #wp-toolbar li.hover .ab-icon:before,#wpadminbar #wp-toolbar li .ab-item:focus .ab-icon:before,#wpadminbar #wp-toolbar li .ab-item:active .ab-icon:before,'
			. '#wpadminbar #wp-toolbar li:hover>.ab-item:before,#wpadminbar #wp-toolbar li.hover>.ab-item:before,#wpadminbar #wp-toolbar li>.ab-item:focus:before,#wpadminbar #wp-toolbar li>.ab-item:active:before,'
			. '#wpadminbar #wp-toolbar li .ab-item:focus .ab-label,#wpadminbar #wp-toolbar li .ab-item:active .ab-label,#wpadminbar #wp-toolbar li:hover .ab-label,#wpadminbar #wp-toolbar li.hover .ab-label' . $icons
			. '#wpadminbar #wp-toolbar #wp-admin-bar-new-content:hover .ab-icon,#wpadminbar #wp-toolbar #wp-admin-bar-new-content.hover .ab-icon{background:var(--hubui-accent-dark)!important}'
			. '#wpadminbar #wp-toolbar #wp-admin-bar-new-content .ab-icon:before{color:#fff!important}'
			. '#wpadminbar .ab-top-menu>li:not(#wp-admin-bar-hubui-brand):not(#wp-admin-bar-hubui-collapse):not(#wp-admin-bar-new-content):not(#wp-admin-bar-hubui-view)>.ab-item{display:flex;align-items:center;gap:6px}'
			. '#wpadminbar .ab-top-menu>li:not(#wp-admin-bar-hubui-brand):not(#wp-admin-bar-hubui-collapse):not(#wp-admin-bar-new-content):not(#wp-admin-bar-hubui-view)>.ab-item:before,'
			. '#wpadminbar .ab-top-menu>li:not(#wp-admin-bar-hubui-brand):not(#wp-admin-bar-hubui-collapse):not(#wp-admin-bar-new-content):not(#wp-admin-bar-hubui-view)>.ab-item>.ab-icon{float:none;position:static;margin:0;padding:0;top:auto;line-height:1}'
			. '#wpadminbar .ab-top-menu>li:not(#wp-admin-bar-hubui-brand):not(#wp-admin-bar-hubui-collapse):not(#wp-admin-bar-new-content):not(#wp-admin-bar-hubui-view)>.ab-item>.ab-icon:before{position:static;top:auto;padding:0}';
	}

	/** Botón "Ver web": mismo formato que "Añadir", con icono de enlace externo. */
	private static function view_site_css() {
		// WordPress tiñe de azul la etiqueta al pasar el ratón con una regla más específica que la del preset.
		return '#wpadminbar:not(.mobile)>#wp-toolbar li:hover span.ab-label,#wpadminbar:not(.mobile)>#wp-toolbar li.hover span.ab-label,#wpadminbar:not(.mobile)>#wp-toolbar a:focus span.ab-label{color:var(--hubui-text)}'
			. '#wpadminbar #wp-admin-bar-hubui-view>.ab-item{display:flex;align-items:center;gap:8px;box-sizing:border-box;margin:10px 0 0;height:36px;line-height:34px;padding:0 14px 0 10px;background:#fff;border:1px solid var(--hubui-border);border-radius:10px;font-weight:600}'
			. '#wpadminbar #wp-admin-bar-hubui-view:hover>.ab-item,#wpadminbar #wp-admin-bar-hubui-view>.ab-item:focus{background:var(--hubui-tint)}'
			. '#wpadminbar #wp-admin-bar-hubui-view .ab-icon{display:block;width:16px;height:16px;margin:0;padding:0}'
			. '#wpadminbar #wp-admin-bar-hubui-view .ab-icon:before{content:"\f504";display:block;width:16px;height:16px;top:0;font:normal 16px/16px dashicons;color:var(--hubui-muted)}'
			. '#wpadminbar #wp-admin-bar-hubui-view:hover .ab-icon:before{color:var(--hubui-text)}'
			. '#wpadminbar #wp-admin-bar-hubui-view{margin-left:10px}'
			// Enlaces personalizados: pastilla con separación, como los botones, para que el resaltado no quede pegado.
			. '#wpadminbar .ab-top-menu>li.hubui-custom-link{margin-left:6px}'
			. '#wpadminbar .ab-top-menu>li.hubui-custom-link>.ab-item{box-sizing:border-box;margin:10px 0 0;height:36px;line-height:36px;padding:0 12px;border-radius:10px}'
			. '@media (min-width:783px){#wpadminbar .ab-top-menu>li.hubui-custom-link{height:var(--hubui-bar-h)}#wpadminbar .ab-top-menu>li.hubui-custom-link>.ab-item{height:36px;line-height:36px;padding:0 12px}}'
			. '@media (max-width:782px){#wpadminbar .ab-top-menu>li.hubui-custom-link>.ab-item{margin-top:5px}}'
			. '@media (min-width:783px){#wpadminbar #wp-admin-bar-hubui-view{height:var(--hubui-bar-h)}#wpadminbar #wp-admin-bar-hubui-view>.ab-item{height:36px;line-height:34px}}'
			. '@media (max-width:782px){#wpadminbar #wp-admin-bar-hubui-view{display:block}#wpadminbar #wp-admin-bar-hubui-view>.ab-item{margin-top:5px;padding:0 10px}#wpadminbar #wp-admin-bar-hubui-view .ab-label{display:none}}';
	}

	/* ====================== Acceso (login) ====================== */

	public static function login_title() {
		return 'Acceso · ' . self::name();
	}

	/** Panel de marca: columna izquierda en escritorio, cabecera en móvil. */
	public static function login_brand() {
		$name = self::name();
		?>
		<aside class="hubui-brand">
			<div class="hubui-brand__logo">
				<span class="hubui-brand__name"><?php echo esc_html( $name ); ?></span>
			</div>
			<div class="hubui-brand__body">
				<p class="hubui-brand__eyebrow">Área privada</p>
				<h2 class="hubui-brand__title">Panel de gestión</h2>
				<p class="hubui-brand__text"><?php echo esc_html( sprintf( 'Acceso privado al panel de gestión de %s.', $name ) ); ?></p>
			</div>
			<div class="hubui-brand__foot">
				<span>© <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php echo esc_html( $name ); ?></span>
				<?php if ( get_option( PREMIERO_OPT_LOGIN_CREDIT, true ) ) : ?>
					<span><?php echo wp_kses( self::credit_html(), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></span>
				<?php endif; ?>
			</div>
		</aside>
		<?php
	}

	/** Encabezado del formulario (solo en la pantalla de acceso normal). */
	public static function login_intro( $message ) {
		if ( ! empty( $_GET['action'] ) && 'login' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $message;
		}
		return '<div class="hubui-login-intro"><h2>Accede al panel</h2><p>Introduce tus credenciales para continuar.</p></div>' . $message;
	}

	public static function login_styles() {
		$css = <<<'CSS'
/* ---------- Layout ---------- */
html{background:var(--hubui-bg)}
body.login{margin:0;height:auto;min-height:100vh;min-height:100dvh;display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);grid-template-rows:1fr auto;background:var(--hubui-bg);color:var(--hubui-text);font-family:var(--hubui-font-body);-webkit-font-smoothing:antialiased}

/* ---------- Panel de marca (neutro, oscuro) ---------- */
.hubui-brand{grid-column:1;grid-row:1 / span 2;position:relative;overflow:hidden;display:flex;flex-direction:column;justify-content:space-between;gap:48px;padding:clamp(32px,5vw,72px);color:#F0EFEC;background-color:#2F2E2C;background-image:radial-gradient(70% 60% at 100% 0%,rgba(240,239,236,.07),transparent 60%),linear-gradient(rgba(240,239,236,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(240,239,236,.04) 1px,transparent 1px);background-size:auto,44px 44px,44px 44px}
.hubui-brand::after{content:"";position:absolute;right:-120px;bottom:-120px;width:360px;height:360px;border:2px solid rgba(240,239,236,.09);border-radius:50%;box-shadow:0 0 0 48px rgba(240,239,236,.03),0 0 0 96px rgba(240,239,236,.02);pointer-events:none}
.hubui-brand>*{position:relative;z-index:1}
.hubui-brand__logo{line-height:0}
.hubui-brand__logo img{display:block;max-width:180px;max-height:56px;width:auto;height:auto}
.hubui-brand__name{display:inline-block;font-family:var(--hubui-font-heading);font-weight:700;font-size:20px;line-height:1.2;letter-spacing:-.01em;color:#F0EFEC}
.hubui-brand__body{max-width:520px}
.hubui-brand__eyebrow{font-family:var(--hubui-font-heading);font-weight:500;font-size:14px;letter-spacing:.04em;color:rgba(240,239,236,.72);margin:0 0 16px}
.hubui-brand__title{font-family:var(--hubui-font-heading);font-weight:700;font-size:clamp(30px,3.4vw,48px);line-height:1.2;color:#fff;margin:0 0 20px}
.hubui-brand__text{font-size:17px;line-height:1.65;color:rgba(240,239,236,.86);margin:0}
.hubui-brand__foot{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px 24px;font-size:14px;color:rgba(240,239,236,.7);border-top:1px solid rgba(240,239,236,.16);padding-top:20px}
.hubui-brand__foot a{color:#F0EFEC;text-decoration:none;font-weight:600}
.hubui-brand__foot a:hover{text-decoration:underline}

/* ---------- Formulario ---------- */
#login{grid-column:2;grid-row:1;align-self:center;justify-self:center;width:100%;max-width:400px;padding:56px 24px;margin:0;box-sizing:border-box}
.login h1.wp-login-logo,.login #backtoblog,.login .language-switcher{display:none!important}
.hubui-login-intro{margin-bottom:28px}
.hubui-login-intro h2{font-family:var(--hubui-font-heading);font-weight:700;font-size:28px;line-height:1.2;color:var(--hubui-text);margin:0 0 10px}
.hubui-login-intro p{margin:0;font-size:16px;line-height:1.55;color:var(--hubui-muted)}
CSS;
		$responsive = <<<'CSS'
/* ---------- Responsive: cabecera compacta, sin grid ---------- */
@media (max-width:960px){
	body.login{display:block;height:auto;min-height:100vh}
	.hubui-brand{flex-direction:row;align-items:center;justify-content:space-between;gap:16px;min-height:0;height:auto;padding:16px 20px;background-size:auto,32px 32px,32px 32px}
	.hubui-brand__body,.hubui-brand__foot{display:none}
	.hubui-brand::after{width:160px;height:160px;right:-70px;bottom:-100px}
	.hubui-brand__logo img{max-width:140px;max-height:36px}
	#login{max-width:440px;margin:0 auto;padding:32px 20px 24px}
	.hubui-login-intro{margin-bottom:24px}
	.hubui-login-intro h2{font-size:24px}
	.login form{padding:24px 20px}
}
@media (prefers-reduced-motion:reduce){*{transition:none!important}}
CSS;
		// Sin fuentes propias: la carpeta del plugin no responde a quien no ha iniciado sesión.
		wp_add_inline_style( 'login', self::tokens_css( false ) . $css . self::login_form_css() . $responsive );
	}

	/** Login normal con formulario moderno: conserva su logo y su fondo; el botón usa los colores elegidos. */
	public static function classic_login_styles() {
		$button = sanitize_hex_color( (string) get_option( PREMIERO_OPT_LOGIN_BTN_BG, '' ) );
		$text   = sanitize_hex_color( (string) get_option( PREMIERO_OPT_LOGIN_BTN_TEXT, '' ) );
		$layout = 'body.login{font-family:var(--hubui-font-body);color:var(--hubui-text)}'
			. '#login{width:100%;max-width:400px;padding:6% 24px 24px;box-sizing:border-box}'
			. '#login h1{margin-bottom:24px}'
			. '.login #backtoblog{margin:16px 0 0;padding:0;text-align:center;font-size:15px}'
			. '.login #backtoblog a{color:var(--hubui-text)}'
			. '.login .language-switcher select,.login .language-switcher .button{min-height:36px;border:1px solid var(--hubui-border-strong);border-radius:var(--hubui-radius-control);background-color:#fff;color:var(--hubui-text);box-shadow:none}';
		wp_add_inline_style( 'login', self::tokens_css( false, $button ? $button : self::ACCENT, $text ? $text : '#ffffff' ) . $layout . self::login_form_css() );
	}

	/** Texto propio del botón de acceso (solo en el formulario de inicio de sesión). */
	public static function classic_login_label() {
		$label = trim( (string) get_option( PREMIERO_OPT_LOGIN_BTN_LABEL, '' ) );
		if ( '' === $label ) {
			return;
		}
		echo '<script>(function(){var b=document.querySelector("#loginform #wp-submit");if(b){b.value=' . wp_json_encode( $label ) . ';}})();</script>';
	}

	/** Formulario de acceso (tarjeta, campos, botón, avisos y enlaces), común al Hub y al formulario moderno. */
	private static function login_form_css() {
		return <<<'CSS'
.login form{background:#fff;border:1px solid var(--hubui-border);border-radius:var(--hubui-radius-card);box-shadow:var(--hubui-shadow);padding:28px 26px;margin:0;overflow:visible}
#login form p,#login form .user-pass-wrap{margin:0 0 20px}
.login label{display:block;font-size:15px;font-weight:600;color:var(--hubui-text);margin-bottom:8px;line-height:1.4}
.login form .input,.login input[type=text],.login input[type=password],.login input[type=email]{width:100%;background:#fff;border:1px solid var(--hubui-border-strong);border-radius:var(--hubui-radius-control);color:var(--hubui-text);font-family:var(--hubui-font-body);font-size:16px;min-height:48px;padding:10px 14px;margin:0;box-shadow:none;transition:border-color .2s,box-shadow .2s}
.login form .input:focus,.login input[type=text]:focus,.login input[type=password]:focus{border-color:var(--hubui-accent);box-shadow:0 0 0 3px rgba(var(--hubui-accent-rgb),.18);outline:none}
.login input:-webkit-autofill{-webkit-box-shadow:0 0 0 1000px #fff inset;-webkit-text-fill-color:var(--hubui-text)}
.login .wp-pwd{position:relative}
.login .wp-pwd .input{padding-right:48px}
.login .button.wp-hide-pw{position:absolute;top:0;right:0;height:48px;width:48px;min-height:0;border:0;background:none;color:var(--hubui-muted)}
.login .button.wp-hide-pw:focus{box-shadow:none}
.login .button.wp-hide-pw .dashicons{color:var(--hubui-muted);width:20px;height:20px;font-size:20px;top:0}

/* Recuérdame */
#login form p.forgetmenot{float:none;display:flex;align-items:center;gap:10px;margin:0 0 24px}
.login .forgetmenot label{display:inline;font-weight:400;color:var(--hubui-text);margin:0}
.login input[type=checkbox]{appearance:auto;-webkit-appearance:auto;accent-color:var(--hubui-accent);width:18px;height:18px;min-width:18px;margin:0;border:0;box-shadow:none;cursor:pointer}
.login input[type=checkbox]::before{display:none}

/* Botón principal */
#login form p.submit{margin:0}
.login .button-primary,.wp-core-ui .button-primary{float:none;display:block;width:100%;height:50px;min-height:50px;padding:8px 24px;background:var(--hubui-accent);border:1px solid var(--hubui-accent);border-radius:var(--hubui-radius-control);color:var(--hubui-on-accent,#fff);font-family:var(--hubui-font-body);font-size:16.8px;font-weight:700;text-shadow:none;box-shadow:none;transition:background .2s}
.wp-core-ui .button-primary:hover,.wp-core-ui .button-primary:focus{background:var(--hubui-accent-dark);border-color:var(--hubui-accent-dark);color:var(--hubui-on-accent,#fff);box-shadow:none}
.wp-core-ui .button-primary:focus-visible{outline:2px solid var(--hubui-accent);outline-offset:3px}

/* Avisos y errores */
.login .message,.login .notice,.login .success,.login #login_error{background:#fff;border:1px solid var(--hubui-border);border-left:4px solid var(--hubui-accent);border-radius:var(--hubui-radius-control);color:var(--hubui-text);box-shadow:none;padding:14px 16px;margin:0 0 24px;font-size:15px}
.login #login_error{border-left-color:#B42318}
.login .message a,.login #login_error a{color:var(--hubui-accent);font-weight:600}

/* Enlaces inferiores */
.login #nav{margin:24px 0 0;padding:0;text-align:center;font-size:15px}
.login #nav a{color:var(--hubui-text);text-decoration:underline;text-underline-offset:4px}
.login #nav a:hover{color:var(--hubui-accent-dark)}
.login .privacy-policy-page-link{text-align:center;margin:16px 0 0}
.login .privacy-policy-page-link a{color:var(--hubui-text)}

CSS;
	}
}
