<?php
/**
 * Pestaña "Menú": agrupa elementos enteros o subelementos sueltos dentro del
 * menú del toolkit y renombra elementos del menú de administración.
 *
 * Un elemento agrupado entero muestra sus subelementos en un panel desplegable
 * al pasar el ratón (WordPress solo pinta dos niveles de menú). Un subelemento
 * agrupado suelto se sigue registrando en su padre original, para que
 * WordPress resuelva bien su pantalla, pero allí queda oculto.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Menu_Groups {

	const TAB          = 'menuwp';
	const NONCE        = 'premiero_menuwp_nonce';
	const SEPARATOR    = 'premiero-separator';
	const MOVED_CLASS  = 'premiero-moved-item';
	const COPY_CLASS   = 'premiero-grouped-child';
	const FLYOUT_CLASS = 'premiero-flyout-parent';

	private static $top_titles        = array();
	private static $child_titles      = array();
	private static $flyout_parents    = array();
	private static $moved             = array();
	private static $current_sub       = '';
	private static $real_submenu_file = null;

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'record_titles' ), 997 );
		add_action( 'admin_menu', array( __CLASS__, 'apply_labels' ), 998 );
		add_action( 'admin_menu', array( __CLASS__, 'apply_groups' ), 999 );
		add_action( 'admin_init', array( __CLASS__, 'handle_submit' ) );
		add_filter( 'parent_file', array( __CLASS__, 'filter_parent_file' ), PHP_INT_MAX );
		add_filter( 'submenu_file', array( __CLASS__, 'filter_submenu_file' ), PHP_INT_MAX, 2 );
		add_action( 'admin_head', array( __CLASS__, 'print_styles' ) );
		add_action( 'admin_footer', array( __CLASS__, 'print_flyouts' ) );
	}

	/* ====================== Ajustes ====================== */

	public static function grouped() {
		$out = array();
		foreach ( (array) get_option( PREMIERO_OPT_MENU_GROUP, array() ) as $slug ) {
			if ( is_scalar( $slug ) && '' !== (string) $slug && PREMIERO_ATK_SLUG !== (string) $slug ) {
				$out[] = (string) $slug;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Agrupación que se aplica al usuario actual: ninguna si tiene oculto el menú
	 * del toolkit (pestaña Usuarios); así lo agrupado vuelve a su sitio original
	 * en vez de desaparecer con él.
	 */
	private static function active_groups() {
		if ( class_exists( 'Premiero_Access_Restrictions' ) && Premiero_Access_Restrictions::toolkit_hidden_for_current_user() ) {
			return array();
		}
		return self::grouped();
	}

	public static function labels() {
		$out = array();
		foreach ( (array) get_option( PREMIERO_OPT_MENU_LABELS, array() ) as $slug => $label ) {
			if ( '' !== (string) $slug && is_scalar( $label ) && '' !== trim( (string) $label ) ) {
				$out[ (string) $slug ] = (string) $label;
			}
		}
		return $out;
	}

	/** Nombre original (antes de renombrar) de un elemento o subelemento. */
	public static function original_title( $slug, $parent = '' ) {
		$slug = (string) $slug;
		if ( '' === $parent ) {
			return self::$top_titles[ $slug ] ?? null;
		}
		return self::$child_titles[ (string) $parent ][ $slug ] ?? null;
	}

	/* ====================== Aplicación en el menú ====================== */

	public static function record_titles() {
		global $menu, $submenu;
		foreach ( (array) $menu as $item ) {
			if ( ! empty( $item[2] ) && ! isset( self::$top_titles[ (string) $item[2] ] ) ) {
				self::$top_titles[ (string) $item[2] ] = (string) $item[0];
			}
		}
		foreach ( (array) $submenu as $parent => $items ) {
			foreach ( (array) $items as $item ) {
				$slug = isset( $item[2] ) ? (string) $item[2] : '';
				if ( '' !== $slug && ! isset( self::$child_titles[ $parent ][ $slug ] ) ) {
					self::$child_titles[ $parent ][ $slug ] = (string) $item[0];
				}
			}
		}
	}

	/** Renombra en su sitio los elementos no agrupados y todos los subelementos. */
	public static function apply_labels() {
		$labels = self::labels();
		if ( empty( $labels ) ) {
			return;
		}
		$grouped = self::active_groups();
		global $menu, $submenu;

		foreach ( (array) $menu as $index => $item ) {
			if ( empty( $item[2] ) ) {
				continue;
			}
			$slug = (string) $item[2];
			if ( PREMIERO_ATK_SLUG === $slug || in_array( $slug, $grouped, true ) || ! isset( $labels[ $slug ] ) ) {
				continue;
			}
			$plain            = wp_strip_all_tags( $labels[ $slug ] );
			$menu[ $index ][0] = $plain;
			if ( isset( $menu[ $index ][3] ) ) {
				$menu[ $index ][3] = $plain;
			}
		}

		foreach ( (array) $submenu as $parent => $items ) {
			if ( PREMIERO_ATK_SLUG === $parent ) {
				continue;
			}
			foreach ( (array) $items as $index => $item ) {
				$slug = isset( $item[2] ) ? (string) $item[2] : '';
				// El subelemento que comparte slug con su padre es la misma pantalla: conserva su nombre.
				if ( '' === $slug || $slug === (string) $parent || ! isset( $labels[ $slug ] ) ) {
					continue;
				}
				$submenu[ $parent ][ $index ][0] = wp_strip_all_tags( $labels[ $slug ] );
			}
		}
	}

	public static function apply_groups() {
		$grouped = self::active_groups();
		if ( empty( $grouped ) ) {
			return;
		}
		$labels = self::labels();
		global $menu, $submenu;

		$top = array();
		foreach ( (array) $menu as $item ) {
			if ( ! empty( $item[2] ) ) {
				$top[ (string) $item[2] ] = array(
					'title' => wp_strip_all_tags( $item[0] ),
					'cap'   => ! empty( $item[1] ) ? $item[1] : 'manage_options',
				);
			}
		}

		$children = array();
		foreach ( (array) $submenu as $parent => $items ) {
			if ( PREMIERO_ATK_SLUG === $parent ) {
				continue;
			}
			foreach ( (array) $items as $index => $item ) {
				$slug = isset( $item[2] ) ? (string) $item[2] : '';
				if ( '' === $slug || $slug === (string) $parent || isset( $children[ $slug ] ) ) {
					continue;
				}
				$children[ $slug ] = array(
					'parent' => (string) $parent,
					'index'  => $index,
					'title'  => wp_strip_all_tags( $item[0] ),
					'cap'    => ! empty( $item[1] ) ? $item[1] : 'manage_options',
				);
			}
		}

		// Los slugs que ya no están en el menú (plugin desactivado) se conservan sin hacer nada.
		$entries = array();
		foreach ( $grouped as $slug ) {
			if ( isset( $top[ $slug ] ) ) {
				$entries[] = array( 'parent', $slug );
			} elseif ( isset( $children[ $slug ] ) && ! in_array( $children[ $slug ]['parent'], $grouped, true ) ) {
				// Si su padre ya está agrupado entero, el subelemento va con él.
				$entries[] = array( 'child', $slug );
			}
		}
		if ( empty( $entries ) ) {
			return;
		}

		// Separador visual bajo "Ajustes" (espacio sin línea ni texto).
		add_submenu_page( PREMIERO_ATK_SLUG, '', '', 'manage_options', self::SEPARATOR, function () {} );

		foreach ( $entries as $entry ) {
			list( $type, $slug ) = $entry;
			$custom              = isset( $labels[ $slug ] ) ? $labels[ $slug ] : '';

			if ( 'parent' === $type ) {
				remove_menu_page( $slug );
				$menu_title = '' !== $custom ? $custom : $top[ $slug ]['title'];
				add_submenu_page( PREMIERO_ATK_SLUG, wp_strip_all_tags( $menu_title ), $menu_title, $top[ $slug ]['cap'], $slug );

				$keys = array_keys( (array) ( $submenu[ PREMIERO_ATK_SLUG ] ?? array() ) );
				$last = end( $keys );
				if ( false !== $last && isset( $submenu[ PREMIERO_ATK_SLUG ][ $last ][2] ) && $slug === (string) $submenu[ PREMIERO_ATK_SLUG ][ $last ][2] ) {
					$submenu[ PREMIERO_ATK_SLUG ][ $last ][4] = self::FLYOUT_CLASS . ' ' . self::flyout_class( $slug );
					self::$flyout_parents[ $slug ] = true;
				}
				continue;
			}

			$child = $children[ $slug ];
			if ( ! current_user_can( $child['cap'] ) ) {
				continue;
			}
			$url        = self::item_url( $slug, $child['parent'] );
			$menu_title = '' !== $custom ? $custom : $child['title'];

			$submenu[ PREMIERO_ATK_SLUG ][] = array(
				0                 => $menu_title,
				1                 => $child['cap'],
				2                 => $url,
				3                 => wp_strip_all_tags( $menu_title ),
				4                 => self::COPY_CLASS,
				'premiero_source' => $slug,
				'premiero_parent' => $child['parent'],
			);

			// Se queda registrado en su padre para que WordPress abra bien la pantalla, pero oculto.
			$original =& $submenu[ $child['parent'] ][ $child['index'] ];
			if ( ! isset( $original[3] ) ) {
				$original[3] = wp_strip_all_tags( $original[0] );
			}
			$original[4] = trim( ( isset( $original[4] ) ? $original[4] : '' ) . ' ' . self::MOVED_CLASS );
			unset( $original );

			self::$moved[ $slug ] = $url;
		}
	}

	private static function flyout_class( $slug ) {
		return 'premiero-flyout-' . substr( md5( (string) $slug ), 0, 10 );
	}

	/** Misma URL que pinta WordPress para un subelemento (wp-admin/menu-header.php). */
	public static function item_url( $slug, $parent ) {
		$slug     = (string) $slug;
		$parent   = (string) $parent;
		$sub_file = $slug;
		$pos      = strpos( $sub_file, '?' );
		if ( false !== $pos ) {
			$sub_file = substr( $sub_file, 0, $pos );
		}

		$hook = get_plugin_page_hook( $slug, $parent );
		if ( ! empty( $hook ) || ( 'index.php' !== $slug && file_exists( WP_PLUGIN_DIR . "/$sub_file" ) && ! file_exists( ABSPATH . "wp-admin/$sub_file" ) ) ) {
			$parent_exists = ( file_exists( WP_PLUGIN_DIR . "/$parent" ) && ! is_dir( WP_PLUGIN_DIR . "/$parent" ) ) || file_exists( ABSPATH . "wp-admin/$parent" );
			$base          = ( $parent_exists && 'admin.php' !== $parent ) ? $parent : 'admin.php';
			return add_query_arg( array( 'page' => $slug ), $base );
		}
		return $slug;
	}

	/** Al abrir algo agrupado, se resalta dentro del menú del toolkit. */
	public static function filter_parent_file( $parent_file ) {
		global $plugin_page, $submenu_file;
		self::$current_sub = '';

		if ( is_string( $parent_file ) && isset( self::$flyout_parents[ $parent_file ] ) ) {
			self::$current_sub = $parent_file;
			self::redirect_parent( $parent_file );
			return PREMIERO_ATK_SLUG;
		}
		foreach ( self::current_screen_keys() as $key ) {
			if ( isset( self::$moved[ $key ] ) ) {
				self::$current_sub = self::$moved[ $key ];
				if ( is_string( $parent_file ) && '' !== $parent_file ) {
					self::redirect_parent( $parent_file );
				}
				return PREMIERO_ATK_SLUG;
			}
		}
		return $parent_file;
	}

	/**
	 * Formas en que WordPress identifica la pantalla actual en el menú: página de
	 * plugin, subelemento indicado por la pantalla o, en las del núcleo como
	 * site-health.php, el propio archivo (con su tipo de contenido o taxonomía).
	 */
	private static function current_screen_keys() {
		global $plugin_page, $submenu_file, $self, $pagenow, $typenow, $taxnow;
		// Si WordPress ya indica la pantalla exacta, solo cuenta esa.
		if ( ! empty( $plugin_page ) ) {
			return array( (string) $plugin_page );
		}
		if ( ! empty( $submenu_file ) ) {
			return array( (string) $submenu_file );
		}
		$file = ! empty( $self ) ? (string) $self : (string) $pagenow;
		if ( '' === $file ) {
			return array();
		}
		$keys = array();
		if ( ! empty( $taxnow ) ) {
			$keys[] = $file . '?taxonomy=' . $taxnow;
		}
		if ( ! empty( $typenow ) && 'post' !== $typenow ) {
			$keys[] = $file . '?post_type=' . $typenow;
		}
		// El archivo a secas solo si no hay un tipo de contenido o taxonomía más concretos.
		if ( empty( $keys ) ) {
			$keys[] = $file;
		}
		return $keys;
	}

	/**
	 * WordPress vuelve a calcular el padre justo después del filtro
	 * (get_admin_page_parent). Mientras se pinta el menú, el padre original
	 * apunta al menú del toolkit; al terminar se restaura.
	 */
	private static function redirect_parent( $parent ) {
		global $_wp_real_parent_file;
		if ( PREMIERO_ATK_SLUG === $parent ) {
			return;
		}
		if ( ! is_array( $_wp_real_parent_file ) ) {
			$_wp_real_parent_file = array();
		}
		$previous = array_key_exists( $parent, $_wp_real_parent_file ) ? $_wp_real_parent_file[ $parent ] : null;
		$_wp_real_parent_file[ $parent ] = PREMIERO_ATK_SLUG;

		add_action(
			'adminmenu',
			function () use ( $parent, $previous ) {
				global $_wp_real_parent_file;
				if ( null === $previous ) {
					unset( $_wp_real_parent_file[ $parent ] );
				} else {
					$_wp_real_parent_file[ $parent ] = $previous;
				}
			},
			0
		);
	}

	public static function filter_submenu_file( $submenu_file, $parent_file ) {
		self::$real_submenu_file = $submenu_file;
		if ( PREMIERO_ATK_SLUG === $parent_file && '' !== self::$current_sub ) {
			return self::$current_sub;
		}
		return $submenu_file;
	}

	public static function print_styles() {
		?>
		<style>
		#adminmenu .wp-submenu a[href="admin.php?page=<?php echo esc_attr( self::SEPARATOR ); ?>"]{cursor:default!important;pointer-events:none!important;height:10px;display:block;content:""}
		#adminmenu li.<?php echo esc_attr( self::MOVED_CLASS ); ?>{display:none!important}
		#adminmenu li.premiero-has-flyout{position:relative}
		#adminmenu .wp-submenu li.premiero-has-flyout>a{position:relative;padding-right:28px}
		#adminmenu .premiero-flyout-arrow{position:absolute;top:50%;right:12px;font-size:16px;line-height:1;opacity:.6;transform:translateY(-55%);pointer-events:none}
		<?php /* Las variables --pf-* las copia el script del desplegable nativo del menú, para que el panel se vea igual. */ ?>
		#adminmenu .wp-submenu .premiero-flyout{display:none;position:absolute;top:calc(-1 * var(--pf-pad-y,7px));left:var(--pf-left,100%);z-index:10000;box-sizing:border-box;width:max-content;min-width:var(--pf-width,200px);max-width:340px;margin:0;padding:var(--pf-pad-y,7px) var(--pf-pad-x,0);background:var(--pf-bg,#2c3338);border:var(--pf-border,0);border-radius:var(--pf-radius,0);box-shadow:var(--pf-shadow,0 3px 5px rgba(0,0,0,.2))}
		<?php /* Puente invisible sobre el hueco entre el elemento y el panel, para que no se cierre al cruzarlo. */ ?>
		#adminmenu .wp-submenu .premiero-flyout:before{content:"";position:absolute;top:0;bottom:0;right:100%;width:var(--pf-gap,0)}
		body.folded #adminmenu .wp-submenu .premiero-flyout{left:100%}
		body.folded #adminmenu .wp-submenu .premiero-flyout:before{width:0}
		#adminmenu .wp-submenu li.premiero-has-flyout:hover>.premiero-flyout,#adminmenu .wp-submenu li.premiero-has-flyout:focus-within>.premiero-flyout{display:block}
		#adminmenu .wp-submenu .premiero-flyout li{margin:var(--pf-li-margin,0);padding:var(--pf-li-padding,0)}
		#adminmenu .wp-submenu .premiero-flyout a{display:block;box-sizing:border-box;white-space:nowrap;padding:var(--pf-link-padding,5px 12px);font-family:var(--pf-font,inherit);font-size:var(--pf-size,13px);font-weight:var(--pf-weight,400);line-height:var(--pf-line,1.4);border-radius:var(--pf-link-radius,0)}
		#adminmenu li.premiero-has-flyout .premiero-flyout li:not(.current)>a:not(:hover):not(:focus){color:var(--pf-color,inherit);background:transparent}
		@media screen and (max-width:782px){
			#adminmenu .wp-submenu .premiero-flyout{position:static;display:block;width:auto;min-width:0;padding:0 0 6px 14px;border:0;background:transparent!important;box-shadow:none}
			#adminmenu .wp-submenu .premiero-flyout:before,#adminmenu .premiero-flyout-arrow{display:none}
		}
		</style>
		<?php
	}

	/** Panel desplegable con los subelementos de cada elemento agrupado entero. */
	public static function print_flyouts() {
		if ( empty( self::$flyout_parents ) ) {
			return;
		}
		global $submenu, $plugin_page;
		$labels  = self::labels();
		$current = (string) self::$real_submenu_file;
		$html    = '';

		foreach ( array_keys( self::$flyout_parents ) as $parent ) {
			if ( empty( $submenu[ $parent ] ) ) {
				continue;
			}
			// Sin subelementos propios (solo la misma pantalla del padre) no hace falta panel.
			$own_children = array_filter(
				(array) $submenu[ $parent ],
				function ( $item ) use ( $parent ) {
					return isset( $item[2] ) && (string) $item[2] !== (string) $parent;
				}
			);
			if ( empty( $own_children ) ) {
				continue;
			}
			$items = '';
			foreach ( (array) $submenu[ $parent ] as $item ) {
				$slug = isset( $item[2] ) ? (string) $item[2] : '';
				if ( '' === $slug || ( ! empty( $item[1] ) && ! current_user_can( $item[1] ) ) ) {
					continue;
				}
				$title     = ( $slug !== $parent && isset( $labels[ $slug ] ) ) ? $labels[ $slug ] : (string) $item[0];
				$is_active = ( '' !== $current && $current === $slug ) || ( ! empty( $plugin_page ) && $plugin_page === $slug );
				$items    .= '<li' . ( $is_active ? ' class="current"' : '' ) . '><a href="' . esc_url( admin_url( self::item_url( $slug, $parent ) ) ) . '"' . ( $is_active ? ' class="current" aria-current="page"' : '' ) . '>' . wp_kses_post( $title ) . '</a></li>';
			}
			if ( '' !== $items ) {
				$html .= '<template data-premiero-flyout="' . esc_attr( self::flyout_class( $parent ) ) . '"><ul class="premiero-flyout">' . $items . '</ul></template>';
			}
		}
		if ( '' === $html ) {
			return;
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado al construirlo.
		?>
		<script>
		(function(){
			var templates = document.querySelectorAll('template[data-premiero-flyout]');
			if ( ! templates.length ) return;

			function isVisibleColor(value){
				return value && value !== 'transparent' && ! /^rgba\([^)]*,\s*0\)$/.test(value);
			}
			// Primer fondo no transparente subiendo desde un elemento.
			function solidBackground(element){
				while ( element && element !== document.documentElement ) {
					var background = window.getComputedStyle(element).backgroundColor;
					if ( isVisibleColor(background) ) return background;
					element = element.parentElement;
				}
				return '';
			}
			function box(style, prefix){
				return [style[prefix + 'Top'], style[prefix + 'Right'], style[prefix + 'Bottom'], style[prefix + 'Left']].join(' ');
			}

			/*
			 * Referencia: el desplegable nativo de un menú cerrado, que es justo lo que
			 * se ve al pasar el ratón. Se copia su estilo, venga de WordPress, de la
			 * pestaña Apariencia o de otro plugin.
			 */
			var reference = null, referenceLink = null;
			document.querySelectorAll('#adminmenu li.wp-not-current-submenu > .wp-submenu').forEach(function(submenu){
				if ( referenceLink ) return;
				var link = submenu.querySelector('li:not(.wp-submenu-head):not(.current) > a');
				if ( link ) { reference = submenu; referenceLink = link; }
			});

			// Borde izquierdo del desplegable nativo en la ventana: su elemento padre más su "left".
			var nativeLeft = null;
			if ( reference && reference.parentElement ) {
				var offset = parseFloat(window.getComputedStyle(reference).left);
				if ( ! isNaN(offset) ) nativeLeft = reference.parentElement.getBoundingClientRect().left + offset;
			}
			if ( nativeLeft === null || nativeLeft <= 0 ) {
				var menuWrap = document.getElementById('adminmenuwrap') || document.getElementById('adminmenu');
				nativeLeft = menuWrap ? menuWrap.getBoundingClientRect().right : null;
			}

			var vars = {};
			if ( reference ) {
				var menuStyle = window.getComputedStyle(reference);
				vars['--pf-bg'] = isVisibleColor(menuStyle.backgroundColor) ? menuStyle.backgroundColor : solidBackground(reference.parentElement);
				vars['--pf-radius'] = menuStyle.borderRadius;
				vars['--pf-shadow'] = menuStyle.boxShadow;
				vars['--pf-pad-y'] = menuStyle.paddingTop;
				vars['--pf-pad-x'] = menuStyle.paddingLeft;
				if ( parseFloat(menuStyle.borderTopWidth) > 0 ) vars['--pf-border'] = menuStyle.borderTopWidth + ' ' + menuStyle.borderTopStyle + ' ' + menuStyle.borderTopColor;
				if ( parseFloat(menuStyle.minWidth) > 0 ) vars['--pf-width'] = menuStyle.minWidth;
			}
			if ( referenceLink ) {
				var linkStyle = window.getComputedStyle(referenceLink);
				var itemStyle = window.getComputedStyle(referenceLink.parentElement);
				vars['--pf-color'] = linkStyle.color;
				vars['--pf-font'] = linkStyle.fontFamily;
				vars['--pf-size'] = linkStyle.fontSize;
				vars['--pf-weight'] = linkStyle.fontWeight;
				vars['--pf-line'] = linkStyle.lineHeight;
				vars['--pf-link-padding'] = box(linkStyle, 'padding');
				vars['--pf-link-radius'] = linkStyle.borderRadius;
				vars['--pf-li-margin'] = box(itemStyle, 'margin');
				vars['--pf-li-padding'] = box(itemStyle, 'padding');
			}

			templates.forEach(function(template){
				var item = document.querySelector('#adminmenu li.' + template.getAttribute('data-premiero-flyout'));
				var list = template.content.firstElementChild;
				if ( ! item || ! list ) return;
				list = document.importNode(list, true);
				item.appendChild(list);
				item.classList.add('premiero-has-flyout');

				var link = item.querySelector(':scope > a');
				if ( link ) {
					var arrow = document.createElement('span');
					arrow.className = 'premiero-flyout-arrow';
					arrow.setAttribute('aria-hidden', 'true');
					arrow.textContent = '›';
					link.appendChild(arrow);
				}

				// Al abrirse, si se sale por debajo de la ventana, sube lo justo para caber.
				var place = function(){
					var margin = 8;
					list.style.top = '';
					list.style.maxHeight = '';
					list.style.overflowY = '';
					var panel = list.getBoundingClientRect();
					if ( ! panel.height ) return;
					var room = window.innerHeight - margin * 2;
					// Más alto que la ventana: se limita y se desplaza por dentro.
					if ( panel.height > room ) {
						list.style.maxHeight = room + 'px';
						list.style.overflowY = 'auto';
						panel = list.getBoundingClientRect();
					}
					var overflow = panel.bottom - ( window.innerHeight - margin );
					if ( overflow > 0 ) {
						var shift = Math.min(overflow, panel.top - margin);
						if ( shift > 0 ) list.style.top = ( parseFloat(window.getComputedStyle(list).top) - shift ) + 'px';
					}
				};
				item.addEventListener('mouseenter', place);
				item.addEventListener('focusin', place);

				if ( ! vars['--pf-bg'] ) vars['--pf-bg'] = solidBackground(item);
				Object.keys(vars).forEach(function(name){
					if ( vars[name] ) list.style.setProperty(name, vars[name]);
				});

				// Misma posición horizontal que el desplegable nativo (justo fuera del menú lateral).
				// Solo con el menú abierto en el lateral; cerrado, el panel sale pegado al desplegable nativo.
				if ( nativeLeft !== null && item.closest('li.wp-has-current-submenu') ) {
					var itemBox = item.getBoundingClientRect();
					var left = nativeLeft - itemBox.left;
					if ( left > 0 ) {
						list.style.setProperty('--pf-left', left + 'px');
						list.style.setProperty('--pf-gap', Math.max(0, left - itemBox.width) + 'px');
					}
				}
			});
		})();
		</script>
		<?php
	}

	/* ====================== Guardado ====================== */

	private static function is_tab_request() {
		global $pagenow;
		return 'admin.php' === $pagenow
			&& isset( $_GET['page'], $_GET['tab'] )
			&& PREMIERO_ATK_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) )
			&& self::TAB === sanitize_key( wp_unslash( $_GET['tab'] ) );
	}

	private static function redirect( $status ) {
		wp_safe_redirect( admin_url( 'admin.php?page=' . PREMIERO_ATK_SLUG . '&tab=' . self::TAB . '&menuwp-status=' . $status ) );
		exit;
	}

	private static function clean_list( $list ) {
		$out = array();
		foreach ( (array) $list as $item ) {
			if ( is_scalar( $item ) ) {
				$item = trim( sanitize_text_field( (string) $item ) );
				if ( '' !== $item ) {
					$out[] = $item;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function handle_submit() {
		if ( ! self::is_tab_request() || ( ! isset( $_POST['premiero_menuwp_submit'] ) && ! isset( $_POST['premiero_menuwp_rescan'] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'No tienes permisos para acceder a esta página.' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );

		if ( isset( $_POST['premiero_menuwp_rescan'] ) ) {
			Premiero_Access_Restrictions::flush_scan();
			self::redirect( 'rescanned' );
		}

		// Solo cambian los elementos que se mostraron en la tabla; el resto se conserva.
		$known   = self::clean_list( wp_unslash( $_POST['menu_known'] ?? array() ) );
		$checked = self::clean_list( wp_unslash( $_POST[ PREMIERO_OPT_MENU_GROUP ] ?? array() ) );
		$group   = array_merge( array_diff( self::grouped(), $known ), array_intersect( $checked, $known ) );
		$group   = array_values( array_diff( array_unique( $group ), array( PREMIERO_ATK_SLUG ) ) );

		$labels = self::labels();
		foreach ( $known as $slug ) {
			unset( $labels[ $slug ] );
		}
		$posted = wp_unslash( $_POST[ PREMIERO_OPT_MENU_LABELS ] ?? array() );
		foreach ( (array) $posted as $slug => $label ) {
			$slug = sanitize_text_field( (string) $slug );
			if ( ! in_array( $slug, $known, true ) || ! is_scalar( $label ) ) {
				continue;
			}
			$label = trim( wp_kses_post( (string) $label ) );
			if ( '' !== $label ) {
				$labels[ $slug ] = $label;
			}
		}

		update_option( PREMIERO_OPT_MENU_GROUP, $group );
		update_option( PREMIERO_OPT_MENU_LABELS, $labels );
		Premiero_Access_Restrictions::flush_scan();
		self::redirect( 'updated' );
	}

	/* ====================== Render ====================== */

	public static function render_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$brand   = premiero_get_brand_name();
		$scan    = Premiero_Access_Restrictions::menu_scan_for_current_user();
		$grouped = self::grouped();
		$labels  = self::labels();

		$known = array();
		$total_children = 0;
		foreach ( $scan as $slug => $row ) {
			$known[ $slug ] = true;
			foreach ( array_keys( $row['children'] ) as $child ) {
				$known[ $child ] = true;
				++$total_children;
			}
		}
		$missing        = array_values( array_diff( $grouped, array_keys( $known ) ) );
		$grouped_whole  = count( array_intersect( $grouped, array_keys( $scan ) ) );
		$grouped_single = count( $grouped ) - $grouped_whole - count( $missing );

		$status = isset( $_GET['menuwp-status'] ) ? sanitize_key( wp_unslash( $_GET['menuwp-status'] ) ) : '';
		?>
		<div class="premiero-access">
			<?php if ( 'updated' === $status ) : ?>
				<div class="notice notice-success is-dismissible premiero-capture-ignore"><p>Menú actualizado.</p></div>
			<?php elseif ( 'rescanned' === $status ) : ?>
				<div class="notice notice-success is-dismissible premiero-capture-ignore"><p>Menú reescaneado.</p></div>
			<?php endif; ?>

			<header class="premiero-remote-header">
				<div>
					<h2>Menú de administración</h2>
					<p>Agrupa elementos enteros o subelementos sueltos dentro de <?php echo esc_html( $brand ); ?> y renombra lo que quieras.</p>
				</div>
				<span class="premiero-remote-status <?php echo $grouped ? 'is-active' : 'is-off'; ?>"><span aria-hidden="true"></span><?php echo esc_html( $grouped ? count( $grouped ) . ' agrupados' : 'Sin agrupar' ); ?></span>
			</header>

			<div class="premiero-overview premiero-notices-overview">
				<div>
					<strong>Elementos</strong>
					<span><?php echo esc_html( count( $scan ) . ' · ' . $total_children . ' subelementos' ); ?></span>
				</div>
				<div class="<?php echo $grouped_whole ? '' : 'is-hidden'; ?>">
					<strong>Agrupados enteros</strong>
					<span><?php echo esc_html( (string) $grouped_whole ); ?></span>
				</div>
				<div class="<?php echo $grouped_single > 0 ? '' : 'is-hidden'; ?>">
					<strong>Subelementos sueltos</strong>
					<span><?php echo esc_html( (string) max( 0, $grouped_single ) ); ?></span>
				</div>
				<div class="<?php echo $labels ? '' : 'is-hidden'; ?>">
					<strong>Nombres personalizados</strong>
					<span><?php echo esc_html( (string) count( $labels ) ); ?></span>
				</div>
			</div>

			<form method="post" class="premiero-appearance-form" id="premiero-menuwp-form">
				<?php wp_nonce_field( self::NONCE ); ?>
				<section class="premiero-appearance-section">
					<h2>Agrupar y renombrar</h2>
					<p>Un elemento agrupado entero muestra sus subelementos al pasar el ratón por encima dentro de <?php echo esc_html( $brand ); ?>. También puedes llevar solo un subelemento. Los nombres personalizados se aplican dentro y fuera de <?php echo esc_html( $brand ); ?>.</p>
					<div class="premiero-notices-toolbar">
						<label class="screen-reader-text" for="premiero-menuwp-search">Buscar elementos</label>
						<input type="search" id="premiero-menuwp-search" placeholder="Buscar por nombre, slug u origen">
						<button type="button" class="button button-primary premiero-menuwp-filter" data-filter="all">Todos</button>
						<button type="button" class="button premiero-menuwp-filter" data-filter="grouped">Agrupados</button>
						<button type="button" class="button premiero-menuwp-filter" data-filter="ungrouped">Sin agrupar</button>
						<button type="button" class="button" id="premiero-menuwp-reset-labels">Restaurar nombres</button>
						<button type="submit" class="button" name="premiero_menuwp_rescan" value="1">Reescanear</button>
						<span class="premiero-menu-count" id="premiero-menuwp-count" aria-live="polite"></span>
					</div>
					<?php if ( empty( $scan ) ) : ?>
						<p class="description">No se ha podido leer el menú. Pulsa "Reescanear".</p>
					<?php else : ?>
						<div class="premiero-remote-table-wrap">
							<table class="widefat premiero-remote-table premiero-access-table premiero-menuwp-table" id="premiero-menuwp-table">
								<thead><tr><th>Elemento</th><th>Slug</th><th>Origen</th><th>Agrupar</th><th>Nombre personalizado</th></tr></thead>
								<tbody>
								<?php
								$group = 0;
								foreach ( $scan as $slug => $row ) :
									++$group;
									$slug = (string) $slug;
									if ( PREMIERO_ATK_SLUG === $slug ) {
										self::render_row( 'parent', $group, $slug, $row['title'], $row['origin'], 'Siempre en ' . $brand, false, '' );
										continue;
									}
									self::render_row( 'parent', $group, $slug, $row['title'], $row['origin'], '', in_array( $slug, $grouped, true ), $labels[ $slug ] ?? '' );
									foreach ( $row['children'] as $child_slug => $child ) :
										$child_slug = (string) $child_slug;
										$note       = $child_slug === $slug ? 'Misma pantalla que el elemento padre' : '';
										self::render_row( 'child', $group, $child_slug, $child['title'], $child['origin'], $note, in_array( $child_slug, $grouped, true ), $labels[ $child_slug ] ?? '' );
									endforeach;
								endforeach;
								foreach ( $missing as $slug ) :
									++$group;
									self::render_row( 'parent', $group, $slug, $slug, 'No detectado', '', true, $labels[ $slug ] ?? '' );
								endforeach;
								?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</section>

				<div class="premiero-sticky-actions">
					<span class="description">Los cambios se aplicarán al guardar.</span>
					<?php submit_button( 'Guardar cambios', 'primary', 'premiero_menuwp_submit', false ); ?>
				</div>
			</form>
		</div>
		<script>
		(function(){
			var table = document.getElementById('premiero-menuwp-table');
			if ( ! table ) return;
			var rows    = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
			var search  = document.getElementById('premiero-menuwp-search');
			var count   = document.getElementById('premiero-menuwp-count');
			var filters = Array.prototype.slice.call(document.querySelectorAll('.premiero-menuwp-filter'));
			var current = 'all';
			var parents = {};
			rows.forEach(function(row){ if ( row.dataset.kind === 'parent' ) parents[row.dataset.group] = row; });

			function box(row){ return row ? row.querySelector('input.premiero-menuwp-group') : null; }
			function isGrouped(row){
				var own = box(row);
				if ( own && own.checked ) return true;
				if ( row.dataset.kind !== 'child' ) return false;
				var parentBox = box(parents[row.dataset.group]);
				return !! ( parentBox && parentBox.checked );
			}
			function refresh(){
				var term = ( search.value || '' ).toLowerCase().trim();
				var visible = 0, grouped = 0, groups = {};
				rows.forEach(function(row){
					var inherited = row.dataset.kind === 'child' && box(parents[row.dataset.group]) && box(parents[row.dataset.group]).checked;
					row.classList.toggle('is-inherited', !! inherited);
					var state = isGrouped(row);
					var own = box(row);
					if ( own && own.checked ) grouped++;
					var stateMatch = current === 'all' || ( current === 'grouped' && state ) || ( current === 'ungrouped' && ! state );
					row._match = stateMatch && ( ! term || row.dataset.search.indexOf(term) !== -1 );
					if ( row._match ) groups[row.dataset.group] = true;
				});
				rows.forEach(function(row){
					var show;
					if ( row.dataset.kind === 'child' ) {
						var parent = parents[row.dataset.group];
						show = row._match || ( !! term && current === 'all' && parent && parent._match );
					} else {
						show = !! groups[row.dataset.group];
					}
					row.style.display = show ? '' : 'none';
					if ( show ) visible++;
				});
				count.textContent = visible + ' visibles · ' + grouped + ' agrupados';
			}
			search.addEventListener('input', refresh);
			table.addEventListener('change', refresh);
			filters.forEach(function(button){
				button.addEventListener('click', function(){
					current = button.dataset.filter;
					filters.forEach(function(item){ item.classList.toggle('button-primary', item === button); });
					refresh();
				});
			});
			document.getElementById('premiero-menuwp-reset-labels').addEventListener('click', function(){
				table.querySelectorAll('.premiero-label-input').forEach(function(input){ input.value = ''; });
			});
			refresh();
		})();
		</script>
		<?php
	}

	private static function render_row( $kind, $group, $slug, $title, $origin, $note, $checked, $label ) {
		$origin = '' !== (string) $origin ? (string) $origin : '—';
		$search = strtolower( $title . ' ' . $slug . ' ' . $origin );
		?>
		<tr class="premiero-access-<?php echo esc_attr( $kind ); ?>" data-kind="<?php echo esc_attr( $kind ); ?>" data-group="<?php echo esc_attr( (string) $group ); ?>" data-search="<?php echo esc_attr( $search ); ?>">
			<td><?php echo esc_html( $title ); ?></td>
			<td><code><?php echo esc_html( $slug ); ?></code></td>
			<td><?php echo esc_html( $origin ); ?></td>
			<?php if ( '' !== $note ) : ?>
				<td colspan="2"><small class="premiero-access-note"><?php echo esc_html( $note ); ?></small></td>
			<?php else : ?>
				<td>
					<input type="hidden" name="menu_known[]" value="<?php echo esc_attr( $slug ); ?>">
					<label><input type="checkbox" class="premiero-menuwp-group" name="<?php echo esc_attr( PREMIERO_OPT_MENU_GROUP ); ?>[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $checked ); ?>> Agrupar</label>
					<?php if ( 'child' === $kind ) : ?>
						<small class="premiero-access-inherited">Va con el elemento padre</small>
					<?php endif; ?>
				</td>
				<td>
					<input class="premiero-label-input" type="text" name="<?php echo esc_attr( PREMIERO_OPT_MENU_LABELS ); ?>[<?php echo esc_attr( $slug ); ?>]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php echo esc_attr( $title ); ?>">
				</td>
			<?php endif; ?>
		</tr>
		<?php
	}
}
