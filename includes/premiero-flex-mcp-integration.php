<?php
/**
 * Integración de StifLi Flex MCP dentro de Premiero Admin Toolkit.
 *
 * Incrusta la interfaz completa de administración del plugin "StifLi Flex MCP"
 * como sub-pestaña "Servidor MCP", sin modificar su código y preservando su
 * ruta normal de actualización.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Páginas del submenú de StifLi Flex MCP (slug => etiqueta), derivadas del
 * registro real del menú con un listado de respaldo.
 */
function premiero_mcp_get_pages() {
    $fallback = [
        'sflmcp-server'     => 'MCP Server',
        'stifli-flex-mcp'   => 'AI Chat Agent',
        'sflmcp-multimedia' => 'Multimedia',
        'sflmcp-seo'        => 'SEO',
        'sflmcp-automation' => 'Automation Tasks',
        'sflmcp-events'     => 'Event Automations',
        'sflmcp-copilot'    => 'AI Copilot',
        'sflmcp-logs'       => 'Logs & Roll Back',
    ];

    global $submenu;
    if ( empty( $submenu['stifli-flex-mcp'] ) || ! is_array( $submenu['stifli-flex-mcp'] ) ) {
        return $fallback;
    }

    $pages = [];
    foreach ( $submenu['stifli-flex-mcp'] as $item ) {
        if ( empty( $item[2] ) || empty( $item[0] ) ) continue;
        $slug  = (string) $item[2];
        $label = trim( wp_strip_all_tags( (string) $item[0] ) );
        if ( '' === $label ) continue;
        $pages[ $slug ] = $label;
    }

    // "MCP Server" siempre primero como vista por defecto.
    if ( isset( $pages['sflmcp-server'] ) ) {
        $label = $pages['sflmcp-server'];
        unset( $pages['sflmcp-server'] );
        $pages = array_merge( [ 'sflmcp-server' => $label ], $pages );
    }

    return $pages ?: $fallback;
}

/**
 * Resuelve la página MCP activa desde la URL (mcp_page), con validación.
 */
function premiero_mcp_resolve_page() {
    $pages = premiero_mcp_get_pages();
    $slug  = isset( $_GET['mcp_page'] ) ? sanitize_key( wp_unslash( $_GET['mcp_page'] ) ) : '';

    if ( $slug && isset( $pages[ $slug ] ) ) {
        return $slug;
    }
    if ( isset( $pages['sflmcp-server'] ) ) {
        return 'sflmcp-server';
    }
    $keys = array_keys( $pages );
    return $keys ? $keys[0] : '';
}

/**
 * Reescribe los enlaces internos del plugin Flex MCP hacia la pestaña embebida.
 */
function premiero_mcp_rewrite_links( $html ) {
    $amp  = '(?:&#038;|&#38;|&amp;|&)';
    $slug = '(sflmcp-[a-z0-9\-]+|stifli-flex-mcp)';

    // admin.php?page=<slug>&tab=X -> mcp_page + mcp_tab
    $html = preg_replace_callback(
        '~admin\.php\?page=' . $slug . $amp . 'tab=([a-z0-9_\-]+)~i',
        function ( $m ) {
            return 'admin.php?page=' . PREMIERO_ATK_SLUG . '&amp;tab=mcp&amp;mcp_page=' . $m[1] . '&amp;mcp_tab=' . $m[2];
        },
        $html
    );

    // admin.php?page=<slug> (sin tab)
    $html = preg_replace_callback(
        '~admin\.php\?page=' . $slug . '([^a-z0-9_\-]|$)~i',
        function ( $m ) {
            return 'admin.php?page=' . PREMIERO_ATK_SLUG . '&amp;tab=mcp&amp;mcp_page=' . $m[1] . $m[2];
        },
        $html
    );

    // ?page=<slug>&tab=X (relativos)
    $html = preg_replace_callback(
        '~(?<![a-z0-9_\-])\?page=' . $slug . $amp . 'tab=([a-z0-9_\-]+)~i',
        function ( $m ) {
            return '?page=' . PREMIERO_ATK_SLUG . '&amp;tab=mcp&amp;mcp_page=' . $m[1] . '&amp;mcp_tab=' . $m[2];
        },
        $html
    );

    // ?page=<slug> (relativos, sin tab)
    $html = preg_replace_callback(
        '~(?<![a-z0-9_\-])\?page=' . $slug . '([^a-z0-9_\-]|$)~i',
        function ( $m ) {
            return '?page=' . PREMIERO_ATK_SLUG . '&amp;tab=mcp&amp;mcp_page=' . $m[1] . $m[2];
        },
        $html
    );

    return $html;
}

/**
 * Pestaña "Servidor MCP": renderiza la página Flex MCP elegida dentro de Premiero.
 */
function premiero_render_embedded_mcp() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $active = is_plugin_active( 'stifli-flex-mcp/stifli-flex-mcp.php' );

    if ( ! $active || ! isset( $GLOBALS['stifli_flex_mcp_instance'] ) ) {
        echo '<div class="notice notice-warning"><p>El plugin <strong>StifLi Flex MCP</strong> no está instalado o activo. Puedes instalarlo desde la pestaña <a href="' . esc_url( admin_url( 'admin.php?page=' . PREMIERO_ATK_SLUG . '&tab=repository' ) ) . '">Repositorio</a>.</p></div>';
        return;
    }

    $pages    = premiero_mcp_get_pages();
    $mcp_page = premiero_mcp_resolve_page();
    $mcp_tab  = isset( $_GET['mcp_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['mcp_tab'] ) ) : '';

    // Barra de sub-pestañas del plugin.
    echo '<div class="premiero-mcp-subnav" style="display:flex;flex-wrap:wrap;gap:4px;margin:0 0 16px;">';
    foreach ( $pages as $slug => $label ) {
        $url   = admin_url( 'admin.php?page=' . PREMIERO_ATK_SLUG . '&tab=mcp&mcp_page=' . $slug );
        $class = $mcp_page === $slug ? 'nav-tab nav-tab-active' : 'nav-tab';
        echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
    }
    echo '</div>';

    if ( ! function_exists( 'get_plugin_page_hookname' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $hookname = get_plugin_page_hookname( $mcp_page, 'stifli-flex-mcp' );

    // Sobrescribir temporalmente $_GET para que el plugin renderice su página/pestaña correcta.
    $orig_page = isset( $_GET['page'] ) ? $_GET['page'] : null;
    $orig_tab  = isset( $_GET['tab'] ) ? $_GET['tab'] : null;
    $_GET['page'] = $mcp_page;
    if ( '' !== $mcp_tab ) {
        $_GET['tab'] = $mcp_tab;
    } else {
        unset( $_GET['tab'] );
    }

    ob_start();
    do_action( $hookname );
    $html = ob_get_clean();

    if ( null === $orig_page ) { unset( $_GET['page'] ); } else { $_GET['page'] = $orig_page; }
    if ( null === $orig_tab )  { unset( $_GET['tab'] ); }  else { $_GET['tab'] = $orig_tab; }

    // Eliminar el <h1> del plugin para no duplicar el título de Premiero.
    $html = preg_replace( '#<h1[^>]*>.*?</h1>#is', '', $html, 1 );

    // Reescribir enlaces internos hacia la pestaña embebida.
    $html = premiero_mcp_rewrite_links( $html );

    echo '<style>.premiero-mcp-embed .wrap{margin:0;padding:0;border:0;background:transparent;box-shadow:none;}.premiero-mcp-embed h2.nav-tab-wrapper{margin-top:0;}</style>';
    echo '<div class="premiero-mcp-embed">' . $html . '</div>';
}

/**
 * Callback del submenú "Servidor MCP": redirige a la pestaña embebida.
 */
function premiero_render_mcp_submenu() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'premiero-admin' ) );
    }
    wp_safe_redirect( admin_url( 'admin.php?page=' . PREMIERO_ATK_SLUG . '&tab=mcp' ) );
    exit;
}

/**
 * Carga los assets de StifLi Flex MCP únicamente para la pestaña embebida,
 * invocando solo sus callbacks con la página/pestaña sobrescritas.
 */
function premiero_mcp_enqueue_assets( $hook ) {
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( PREMIERO_ATK_SLUG !== $page ) return;

    $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'info';
    if ( 'mcp' !== $tab ) return;

    if ( ! isset( $GLOBALS['stifli_flex_mcp_instance'] ) ) return;

    $mcp_page = premiero_mcp_resolve_page();
    if ( ! $mcp_page ) return;

    if ( ! function_exists( 'get_plugin_page_hookname' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $mcp_hook = get_plugin_page_hookname( $mcp_page, 'stifli-flex-mcp' );

    // Sobrescribir $_GET para que los callbacks reconozcan su página.
    $orig_page = isset( $_GET['page'] ) ? $_GET['page'] : null;
    $orig_tab  = isset( $_GET['tab'] ) ? $_GET['tab'] : null;
    $_GET['page'] = $mcp_page;
    if ( isset( $_GET['mcp_tab'] ) ) {
        $_GET['tab'] = sanitize_text_field( wp_unslash( $_GET['mcp_tab'] ) );
    } else {
        unset( $_GET['tab'] );
    }

    // Invocar únicamente los callbacks de enqueue de las clases de StifLi Flex MCP.
    global $wp_filter;
    if ( isset( $wp_filter['admin_enqueue_scripts'] ) ) {
        foreach ( $wp_filter['admin_enqueue_scripts']->callbacks as $callbacks ) {
            foreach ( $callbacks as $cb ) {
                $fn = $cb['function'];
                if ( is_array( $fn ) && is_object( $fn[0] ) ) {
                    if ( 0 === strpos( get_class( $fn[0] ), 'StifliFlexMcp' ) ) {
                        call_user_func( $fn, $mcp_hook );
                    }
                }
            }
        }
    }

    if ( null === $orig_page ) { unset( $_GET['page'] ); } else { $_GET['page'] = $orig_page; }
    if ( null === $orig_tab )  { unset( $_GET['tab'] ); }  else { $_GET['tab'] = $orig_tab; }
}

/**
 * Intercepta accesos directos a páginas de Flex MCP y los redirige a la pestaña embebida.
 * (También reconduce los redirects posteriores a un form, p. ej. guardar add-ons.)
 */
function premiero_mcp_maybe_redirect() {
    if ( ! isset( $_GET['page'] ) ) return;
    $page = sanitize_key( wp_unslash( $_GET['page'] ) );

    $mcp_slugs = [ 'stifli-flex-mcp', 'sflmcp-server', 'sflmcp-multimedia', 'sflmcp-seo', 'sflmcp-logs', 'sflmcp-automation', 'sflmcp-events', 'sflmcp-copilot' ];
    if ( ! in_array( $page, $mcp_slugs, true ) ) return;

    $args = $_GET;
    $internal_tab = isset( $args['tab'] ) ? sanitize_text_field( wp_unslash( $args['tab'] ) ) : '';

    $args['page']     = PREMIERO_ATK_SLUG;
    $args['tab']      = 'mcp';
    $args['mcp_page'] = $page;
    if ( '' !== $internal_tab && 'mcp' !== $internal_tab ) {
        $args['mcp_tab'] = $internal_tab;
    }

    wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
    exit;
}

/**
 * Instala y activa automáticamente StifLi Flex MCP (ZIP local) si no está presente.
 */
function premiero_atk_maybe_auto_install_mcp() {
    if ( ! current_user_can( 'install_plugins' ) ) return;

    $slug = 'stifli-flex-mcp';
    $zip  = PREMIERO_ATK_DIR . 'assets/plugins/stifli-flex-mcp.zip';
    if ( ! file_exists( $zip ) ) return;

    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    // ¿Ya instalado?
    $installed_file = false;
    foreach ( array_keys( get_plugins() ) as $file ) {
        if ( 0 === strpos( $file, $slug . '/' ) ) {
            $installed_file = $file;
            break;
        }
    }

    if ( $installed_file ) {
        if ( ! is_plugin_active( $installed_file ) && current_user_can( 'activate_plugins' ) ) {
            activate_plugin( $installed_file );
            // Suprime el redirect de onboarding para no "escapar" del panel embebido.
            delete_option( 'sflmcp_addons_onboarding_pending' );
        }
        return;
    }

    // Intentar solo una vez por sesión.
    if ( get_transient( 'premiero_mcp_auto_install_attempted' ) ) return;
    set_transient( 'premiero_mcp_auto_install_attempted', 1, HOUR_IN_SECONDS );

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

    $skin     = new Automatic_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader( $skin );
    $result   = $upgrader->install( $zip );
    if ( is_wp_error( $result ) || ! $result ) return;

    foreach ( array_keys( get_plugins() ) as $file ) {
        if ( 0 === strpos( $file, $slug . '/' ) ) {
            activate_plugin( $file );
            // Suprime el redirect de onboarding para no "escapar" del panel embebido.
            delete_option( 'sflmcp_addons_onboarding_pending' );
            return;
        }
    }
}

/**
 * Suprime el onboarding standalone de Flex MCP para que no "escape" del panel
 * embebido. Debe ejecutarse antes del redirect del propio plugin (prioridad 1).
 */
function premiero_mcp_suppress_onboarding() {
    if ( get_option( 'sflmcp_addons_onboarding_pending', false ) ) {
        delete_option( 'sflmcp_addons_onboarding_pending' );
    }
}

/* ====================== Hooks de integración ====================== */

// Oculta el menú independiente "Flex MCP".
add_action( 'admin_menu', function() {
    remove_menu_page( 'stifli-flex-mcp' );
}, 999 );

// Resalta "Servidor MCP" en el submenú cuando se visita la pestaña embebida.
add_filter( 'submenu_file', function( $submenu_file ) {
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    $tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
    if ( PREMIERO_ATK_SLUG === $page && 'mcp' === $tab ) {
        return PREMIERO_ATK_SLUG . '-mcp';
    }
    return $submenu_file;
} );

// Redirige accesos directos a páginas de Flex MCP hacia la pestaña embebida.
add_action( 'admin_init', 'premiero_mcp_maybe_redirect', 1 );

// Carga los assets de Flex MCP en la pestaña embebida.
add_action( 'admin_enqueue_scripts', 'premiero_mcp_enqueue_assets', 999 );

// Auto-instalación/activación de Flex MCP.
add_action( 'admin_init', 'premiero_atk_maybe_auto_install_mcp' );

// Suprime el onboarding standalone (antes de la prioridad 1 del plugin).
add_action( 'admin_init', 'premiero_mcp_suppress_onboarding', 0 );
register_activation_hook( PREMIERO_ATK_FILE, function() {
    delete_transient( 'premiero_mcp_auto_install_attempted' );
} );
