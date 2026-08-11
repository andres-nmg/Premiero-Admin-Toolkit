<?php
/**
 * Plugin Name: Premiero Admin Toolkit
 * Plugin URI:  https://github.com/andres-nmg/premiero-admin-toolkit/
 * Description: Personalización y soporte personalizado.
 * Version:     3.4.3
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:      Premiero
 * Author URI:  https://premiero.es
 * License:     GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI:  https://github.com/andres-nmg/premiero-admin-toolkit/
 * Text Domain: premiero-admin
 */

if ( ! defined('ABSPATH') ) exit;

/*
 * Puente de activación para instalaciones que todavía usan
 * tecnoderecho-admin-toolkit. Ese plugin comparte los identificadores
 * internos históricos, así que debe desactivarse antes de cargar esta base.
 */
if ( defined('PREMIERO_ATK_DIR') ) {
    $premiero_loaded_plugin_dir = basename( rtrim( wp_normalize_path( PREMIERO_ATK_DIR ), '/' ) );
    if ( 'tecnoderecho-admin-toolkit' === $premiero_loaded_plugin_dir ) {
        if ( ! function_exists('deactivate_plugins') ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $premiero_legacy_plugin = 'tecnoderecho-admin-toolkit/tecnoderecho-admin-toolkit.php';
        $premiero_network_wide  = is_multisite() && is_plugin_active_for_network( $premiero_legacy_plugin );
        deactivate_plugins( $premiero_legacy_plugin, true, $premiero_network_wide );
        update_option( 'premiero_atk_legacy_migration_pending', 1, true );
        update_option( 'premiero_atk_legacy_migration_notice', '', true );
        return;
    }
}

define('PREMIERO_ATK_VER', '3.4.3');
define('PREMIERO_ATK_SLUG', 'premiero-admin');
define('PREMIERO_ATK_DIR', plugin_dir_path(__FILE__));
define('PREMIERO_ATK_URL', plugin_dir_url(__FILE__));
define('PREMIERO_ATK_ASSETS', trailingslashit(PREMIERO_ATK_URL.'assets'));
define('PREMIERO_ATK_PLUGIN_SLUG', 'premiero-admin-toolkit');
define('PREMIERO_ATK_UPDATE_URI', 'https://github.com/andres-nmg/premiero-admin-toolkit/');
define('PREMIERO_ATK_RELEASE_API', 'https://api.github.com/repos/andres-nmg/premiero-admin-toolkit/releases/latest');
define('PREMIERO_ATK_RELEASE_ASSET', 'premiero-admin-toolkit.zip');
define('PREMIERO_ATK_LEGACY_PLUGIN', 'tecnoderecho-admin-toolkit/tecnoderecho-admin-toolkit.php');
define('PREMIERO_ATK_LEGACY_LOGO', WP_PLUGIN_DIR . '/tecnoderecho-admin-toolkit/assets/tecnoderecho-logo.png');

/** Opciones principales */
const PREMIERO_OPT_CSS            = 'premiero_custom_css';
const PREMIERO_OPT_HEAD_HTML      = 'premiero_head_html';
const PREMIERO_OPT_BODY_HTML      = 'premiero_body_html';
const PREMIERO_OPT_SNIPPETS       = 'premiero_php_snippets';

/** Menú WP */
const PREMIERO_OPT_MENU_GROUP     = 'premiero_menu_group';   // array de slugs agrupados bajo Premiero
const PREMIERO_OPT_MENU_LABELS    = 'premiero_menu_labels';  // array slug => label personalizado

/** Login UI */
const PREMIERO_OPT_LOGIN_BG       = 'premiero_login_bg';        // string hex, solo login
const PREMIERO_OPT_LOGIN_CREDIT   = 'premiero_login_credit';    // bool
const PREMIERO_OPT_LOGIN_LOGO_ID  = 'premiero_login_logo_id';   // attachment id (int)
const PREMIERO_OPT_LOGIN_LOGO_W   = 'premiero_login_logo_w';    // ancho px (int)

/** Identidad personalizada */
const PREMIERO_OPT_WHITE_LABEL_ENABLED = 'premiero_white_label_enabled';
const PREMIERO_OPT_WHITE_LABEL_NAME    = 'premiero_white_label_name';
const PREMIERO_OPT_WHITE_LABEL_LOGO_ID = 'premiero_white_label_logo_id';

require_once PREMIERO_ATK_DIR . 'includes/class-premiero-console-client.php';
Premiero_Console_Client::init();
register_activation_hook( __FILE__, [ 'Premiero_Console_Client', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Premiero_Console_Client', 'deactivate' ] );

require_once PREMIERO_ATK_DIR . 'includes/class-premiero-admin-notices.php';
Premiero_Admin_Notices::init();

$premiero_composer_autoload = PREMIERO_ATK_DIR . 'vendor/autoload.php';
if ( file_exists( $premiero_composer_autoload ) ) {
    require_once $premiero_composer_autoload;
}
require_once PREMIERO_ATK_DIR . 'includes/remote-backups/class-premiero-sftp-client.php';
require_once PREMIERO_ATK_DIR . 'includes/remote-backups/class-premiero-backup-sync-queue.php';
require_once PREMIERO_ATK_DIR . 'includes/remote-backups/class-premiero-backup-detector.php';
require_once PREMIERO_ATK_DIR . 'includes/remote-backups/class-premiero-backup-verifier.php';
require_once PREMIERO_ATK_DIR . 'includes/remote-backups/class-premiero-backup-worker.php';
require_once PREMIERO_ATK_DIR . 'includes/remote-backups/class-premiero-remote-backup-settings.php';
require_once PREMIERO_ATK_DIR . 'includes/remote-backups/class-premiero-backup-reconciler.php';
require_once PREMIERO_ATK_DIR . 'includes/remote-backups/class-premiero-remote-backups.php';
Premiero_Remote_Backups::init();
register_activation_hook( __FILE__, [ 'Premiero_Remote_Backups', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Premiero_Remote_Backups', 'deactivate' ] );

function premiero_is_white_label() {
    return (bool) get_option( PREMIERO_OPT_WHITE_LABEL_ENABLED, false )
        && '' !== trim( (string) get_option( PREMIERO_OPT_WHITE_LABEL_NAME, '' ) );
}

function premiero_get_brand_name() {
    if ( ! premiero_is_white_label() ) {
        return 'Premiero';
    }
    return sanitize_text_field( get_option( PREMIERO_OPT_WHITE_LABEL_NAME, '' ) );
}

function premiero_get_toolkit_name() {
    return premiero_get_brand_name() . ' Admin Toolkit';
}

function premiero_get_brand_logo_url( $size = 'full' ) {
    if ( premiero_is_white_label() ) {
        $logo_id = (int) get_option( PREMIERO_OPT_WHITE_LABEL_LOGO_ID, 0 );
        if ( $logo_id ) {
            $src = wp_get_attachment_image_src( $logo_id, $size );
            if ( ! empty( $src[0] ) ) {
                return $src[0];
            }
        }
        return '';
    }
    return PREMIERO_ATK_ASSETS . 'premiero-logo.png';
}

function premiero_schedule_legacy_migration() {
    $legacy_file = WP_PLUGIN_DIR . '/' . PREMIERO_ATK_LEGACY_PLUGIN;
    update_option( 'premiero_atk_legacy_migration_pending', 0, true );
    update_option( 'premiero_atk_legacy_migration_notice', '', true );

    if (
        file_exists( $legacy_file )
        && ! get_option( PREMIERO_OPT_WHITE_LABEL_ENABLED, false )
        && '' === trim( (string) get_option( PREMIERO_OPT_WHITE_LABEL_NAME, '' ) )
    ) {
        update_option( 'premiero_atk_legacy_migration_pending', 1, true );
    }
}
register_activation_hook( __FILE__, 'premiero_schedule_legacy_migration' );

function premiero_initialize_runtime_state() {
    if ( null === get_option( 'premiero_atk_legacy_migration_pending', null ) ) {
        add_option( 'premiero_atk_legacy_migration_pending', 0, '', true );
    }
    if ( null === get_option( 'premiero_atk_legacy_migration_notice', null ) ) {
        add_option( 'premiero_atk_legacy_migration_notice', '', '', true );
    }
}
add_action( 'admin_init', 'premiero_initialize_runtime_state', 0 );

add_filter( 'all_plugins', function( $plugins ) {
    if ( ! premiero_is_white_label() ) {
        return $plugins;
    }

    $plugin_file = plugin_basename( __FILE__ );
    if ( isset( $plugins[$plugin_file] ) ) {
        $plugins[$plugin_file]['Name']        = premiero_get_toolkit_name();
        $plugins[$plugin_file]['Title']       = premiero_get_toolkit_name();
        $plugins[$plugin_file]['Description'] = 'Herramientas de administración y personalización adaptadas para ' . premiero_get_brand_name() . '.';
    }
    return $plugins;
} );

/* ====================== Actualizaciones desde GitHub ====================== */
function premiero_atk_get_latest_release() {
    $cache_key = 'premiero_atk_github_release';
    $cached    = get_site_transient( $cache_key );
    if ( false !== $cached && is_array( $cached ) ) {
        if ( ! empty( $cached['_premiero_error'] ) ) {
            return new WP_Error(
                sanitize_key( $cached['code'] ?? 'premiero_github_cached' ),
                sanitize_text_field( $cached['message'] ?? 'No se pudo consultar GitHub.' )
            );
        }
        return $cached;
    }

    $response = wp_remote_get( PREMIERO_ATK_RELEASE_API, [
        'timeout' => 10,
        'headers' => [
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent'           => 'Premiero-Admin-Toolkit/' . PREMIERO_ATK_VER,
        ],
    ] );

    if ( is_wp_error( $response ) ) {
        set_site_transient( $cache_key, [
            '_premiero_error' => true,
            'code'             => $response->get_error_code(),
            'message'          => $response->get_error_message(),
        ], 10 * MINUTE_IN_SECONDS );
        return $response;
    }

    if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
        $error = new WP_Error( 'premiero_github_http', 'GitHub no devolvió una versión estable disponible.' );
        set_site_transient( $cache_key, [
            '_premiero_error' => true,
            'code'             => $error->get_error_code(),
            'message'          => $error->get_error_message(),
        ], 10 * MINUTE_IN_SECONDS );
        return $error;
    }

    $release = json_decode( wp_remote_retrieve_body( $response ), true );
    if (
        ! is_array( $release )
        || empty( $release['tag_name'] )
        || ! empty( $release['draft'] )
        || ! empty( $release['prerelease'] )
    ) {
        $error = new WP_Error( 'premiero_github_release', 'La respuesta de GitHub no contiene una versión estable válida.' );
        set_site_transient( $cache_key, [
            '_premiero_error' => true,
            'code'             => $error->get_error_code(),
            'message'          => $error->get_error_message(),
        ], 10 * MINUTE_IN_SECONDS );
        return $error;
    }

    set_site_transient( $cache_key, $release, 30 * MINUTE_IN_SECONDS );
    return $release;
}

function premiero_atk_release_version( $release ) {
    $version = isset( $release['tag_name'] ) ? ltrim( (string) $release['tag_name'], "vV \t\n\r\0\x0B" ) : '';
    return preg_match( '/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ? $version : '';
}

function premiero_atk_release_package( $release ) {
    foreach ( (array) ( $release['assets'] ?? [] ) as $asset ) {
        if (
            isset( $asset['name'], $asset['browser_download_url'] )
            && PREMIERO_ATK_RELEASE_ASSET === $asset['name']
        ) {
            return esc_url_raw( $asset['browser_download_url'] );
        }
    }
    return '';
}

add_filter( 'update_plugins_github.com', function( $update, $plugin_data, $plugin_file, $locales ) {
    if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
        return $update;
    }

    $update_uri = isset( $plugin_data['UpdateURI'] ) ? trailingslashit( $plugin_data['UpdateURI'] ) : '';
    if ( PREMIERO_ATK_UPDATE_URI !== $update_uri ) {
        return $update;
    }

    $release = premiero_atk_get_latest_release();
    if ( is_wp_error( $release ) ) {
        return false;
    }

    $version = premiero_atk_release_version( $release );
    $package = premiero_atk_release_package( $release );
    if ( ! $version || ! $package ) {
        return false;
    }

    return [
        'version'      => $version,
        'slug'         => PREMIERO_ATK_PLUGIN_SLUG,
        'url'          => esc_url_raw( $release['html_url'] ?? PREMIERO_ATK_UPDATE_URI ),
        'package'      => $package,
        'requires_php' => '7.4',
    ];
}, 10, 4 );

add_filter( 'plugins_api', function( $result, $action, $args ) {
    if ( 'plugin_information' !== $action || empty( $args->slug ) || PREMIERO_ATK_PLUGIN_SLUG !== $args->slug ) {
        return $result;
    }

    $release = premiero_atk_get_latest_release();
    if ( is_wp_error( $release ) ) {
        return $result;
    }

    $version = premiero_atk_release_version( $release );
    $package = premiero_atk_release_package( $release );
    if ( ! $version ) {
        return $result;
    }

    $notes = ! empty( $release['body'] )
        ? wpautop( esc_html( $release['body'] ) )
        : '<p>Consulta los cambios de esta versión en GitHub.</p>';

    return (object) [
        'name'          => premiero_get_toolkit_name(),
        'slug'          => PREMIERO_ATK_PLUGIN_SLUG,
        'version'       => $version,
        'author'        => '<a href="https://premiero.es">Premiero</a>',
        'homepage'      => PREMIERO_ATK_UPDATE_URI,
        'download_link' => $package,
        'requires'      => '5.8',
        'requires_php'  => '7.4',
        'sections'      => [
            'description' => '<p>Herramientas de administración y personalización para WordPress.</p>',
            'changelog'   => $notes,
        ],
    ];
}, 10, 3 );

/* ====================== Repositorio e instalador ====================== */
class Premiero_Admin_Toolkit_Repository {

    const NONCE_ACTION = 'premiero_repository_action';

    /** Almacena mensajes por ítem para mostrarlos en la UI */
    private $run_messages = [];

    public function __construct() {
        add_action( 'admin_init', [ $this, 'handle_form_submit' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    /* =========================
     *   CONFIGURACIÓN
     * ========================= */

    /** Catálogo WP.org — por URL pública + nombre visible */
    protected function get_wporg_plugins_catalog() {
        return [
            [ 'name' => 'Elementor Website Builder', 'url' => 'https://es.wordpress.org/plugins/elementor/' ],
            [ 'name' => 'Backup',                    'url' => 'https://es.wordpress.org/plugins/updraftplus/' ],
            [ 'name' => 'WooCommerce',               'url' => 'https://es.wordpress.org/plugins/woocommerce/' ],
            [ 'name' => 'Pods',                      'url' => 'https://es.wordpress.org/plugins/pods/' ],
            [ 'name' => 'Optimización',              'url' => 'https://es.wordpress.org/plugins/wp-optimize/' ],
            [ 'name' => 'Advanced Custom Fields',    'url' => 'https://es.wordpress.org/plugins/advanced-custom-fields/' ],
            [ 'name' => 'Joinchat (WhatsApp)',       'url' => 'https://es.wordpress.org/plugins/creame-whatsapp-me/' ],
            [ 'name' => 'Template Kit Import',       'url' => 'https://es.wordpress.org/plugins/template-kit-import/' ],
            [ 'name' => 'reSmush.it',                'url' => 'https://es.wordpress.org/plugins/resmushit-image-optimizer/' ],
            [ 'name' => 'Wordfence Security',        'url' => 'https://es.wordpress.org/plugins/wordfence/' ],
        ];
    }

    /** Catálogo de temas disponibles en WordPress.org */
    protected function get_wporg_themes_catalog() {
        return [
            [ 'name' => 'Hello Elementor', 'slug' => 'hello-elementor' ],
        ];
    }

    /** Mapeo de nombres visibles para ZIPs de plugins */
    protected function get_local_plugin_labels() {
        return [
            'all-in-one-wp-migration-with-import-master.zip' => 'Importador / Exportador',
            'pro-elements.zip'                               => 'Pro Elements',
        ];
    }

    /** Mapeo de nombres visibles para ZIPs de temas */
    protected function get_local_theme_labels() {
        return [
            'hello-theme-child-master.zip' => 'Hello Elementor Child',
        ];
    }

    /** Directorios de assets */
    protected function get_assets_dirs() {
        $base = trailingslashit( PREMIERO_ATK_DIR . 'assets' );
        return [
            'base'    => $base,
            'plugins' => $base . 'plugins/',
            'themes'  => $base . 'themes/',
        ];
    }

    /** Listado (no recursivo) de ZIPs en una carpeta */
    protected function list_zip_files( $dir ) {
        if ( ! is_dir( $dir ) ) return [];
        $files = glob( rtrim( $dir, '/\\' ) . '/*.zip' );
        if ( ! $files ) return [];
        return array_map( function( $path ) {
            return [
                'path' => $path,
                'name' => basename( $path ),
                'size' => @filesize( $path ),
            ];
        }, $files );
    }

    protected function get_local_plugin_slug( $filename ) {
        $map = [
            'all-in-one-wp-migration-with-import-master.zip' => 'all-in-one-wp-migration-with-import-master',
            'pro-elements.zip'                               => 'pro-elements',
        ];
        return $map[$filename] ?? '';
    }

    protected function get_plugin_status( $slug ) {
        if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $file = $slug ? $this->locate_plugin_main_file_by_slug( $slug ) : false;
        if ( ! $file ) return [ 'state' => 'available', 'label' => 'Disponible', 'version' => '' ];

        $plugins = get_plugins();
        return [
            'state'   => is_plugin_active( $file ) ? 'active' : 'installed',
            'label'   => is_plugin_active( $file ) ? 'Activo' : 'Instalado',
            'version' => $plugins[$file]['Version'] ?? '',
        ];
    }

    protected function get_theme_status( $slug ) {
        $theme = $slug ? wp_get_theme( $slug ) : false;
        if ( ! $theme || ! $theme->exists() ) return [ 'state' => 'available', 'label' => 'Disponible', 'version' => '' ];

        return [
            'state'   => get_stylesheet() === $slug ? 'active' : 'installed',
            'label'   => get_stylesheet() === $slug ? 'Activo' : 'Instalado',
            'version' => $theme->get('Version'),
        ];
    }

    protected function render_status( $status ) {
        $version = ! empty($status['version']) ? ' · ' . $status['version'] : '';
        return '<span class="premiero-status premiero-status-' . esc_attr($status['state']) . '">'
            . esc_html($status['label'] . $version)
            . '</span>';
    }

    protected function render_action_select( $name, $label ) {
        return '<label><span class="screen-reader-text">' . esc_html($label) . '</span>'
            . '<select class="premiero-action-select" name="' . esc_attr($name) . '">'
            . '<option value="">Sin cambios</option>'
            . '<option value="install">Instalar</option>'
            . '<option value="activate">Instalar y activar</option>'
            . '</select></label>';
    }

    /* =========================
     *   UI
     * ========================= */

    public function enqueue_admin_assets( $hook ) {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
        if ( $hook !== 'toplevel_page_premiero-admin' || $tab !== 'repository' ) return;

        $custom_css = '
        .premiero-two-cols { display:grid; grid-template-columns:minmax(0,1fr); gap:16px; }
        @media(min-width:1400px){ .premiero-two-cols { grid-template-columns:minmax(0,1.35fr) minmax(0,1fr); } }
        .premiero-box { box-sizing:border-box;background:#fff;border:1px solid #e2e4e7;border-radius:8px;padding:18px;min-width:0; }
        .premiero-box h3 { margin-top:0; }
        .premiero-muted { color:#6c7781; }
        .premiero-top-note { margin:12px 0 20px; }
        .premiero-table{width:100%;table-layout:auto}
        .premiero-table th,.premiero-table td{vertical-align:middle;overflow-wrap:anywhere}
        .premiero-table code{white-space:normal;overflow-wrap:anywhere}
        .premiero-table label{display:inline-flex;align-items:flex-start;gap:6px}
        .premiero-repo-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 16px}
        .premiero-repo-toolbar .description{margin-right:auto}
        .premiero-action-select{width:100%;min-width:150px}
        .premiero-status{display:inline-flex;align-items:center;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap}
        .premiero-status-active{background:#edfaef;color:#116329}
        .premiero-status-installed{background:#eaf2fa;color:#135e96}
        .premiero-status-available{background:#f0f0f1;color:#50575e}
        .premiero-actions{position:sticky;bottom:0;z-index:6;display:flex;align-items:center;justify-content:space-between;gap:12px;margin:18px -24px -24px;padding:12px 24px;background:rgba(255,255,255,.96);border-top:1px solid #dcdcde;box-shadow:0 -2px 8px rgba(0,0,0,.06);backdrop-filter:blur(4px)}
        .premiero-actions .submit{margin:0;padding:0}
        .premiero-small { font-size:12px; color:#6c7781; }
        .premiero-result { margin-top:16px; }
        .premiero-installing{display:none;align-items:center;gap:8px;font-weight:600;color:#135e96}
        .premiero-installing.is-active{display:flex}
        .premiero-installing .spinner{float:none;margin:0;visibility:visible}
        @media(max-width:782px){
            .premiero-box{padding:14px}
            .premiero-repo-toolbar{align-items:stretch}
            .premiero-repo-toolbar .description{width:100%}
            .premiero-actions{margin:18px -16px -16px;padding:10px 16px}
            .premiero-table,.premiero-table tbody,.premiero-table tr,.premiero-table td{display:block;width:100%;box-sizing:border-box}
            .premiero-table thead{display:none}
            .premiero-table tr{margin:0 0 12px;border:1px solid #dcdcde;border-radius:6px;padding:8px;background:#fff}
            .premiero-table td{display:grid;grid-template-columns:minmax(100px,34%) minmax(0,1fr);gap:10px;align-items:center;padding:8px;border:0}
            .premiero-table td:before{content:attr(data-label);font-weight:600;color:#50575e}
            .premiero-table td:first-child{grid-template-columns:minmax(100px,34%) minmax(0,1fr)}
        }
        ';
        wp_add_inline_style( 'wp-admin', $custom_css );
    }

    public function render_embedded_page() {
        if ( ! current_user_can( 'install_plugins' ) ) {
            echo '<div class="notice notice-error"><p>No tienes permisos para instalar plugins o temas.</p></div>';
            return;
        }

        $dirs = $this->get_assets_dirs();

        $local_plugins = $this->list_zip_files( $dirs['plugins'] );
        $local_themes  = $this->list_zip_files( $dirs['themes'] );
        $wporg_plugins = $this->get_wporg_plugins_catalog();
        $wporg_themes  = $this->get_wporg_themes_catalog();

        $plugin_labels = $this->get_local_plugin_labels();
        $theme_labels  = $this->get_local_theme_labels();

        echo '<h2>Repositorio e instalador</h2>';
        $this->render_installer_tab( $local_plugins, $local_themes, $wporg_plugins, $wporg_themes, $plugin_labels, $theme_labels );
    }

    protected function render_installer_tab( $local_plugins, $local_themes, $wporg_plugins, $wporg_themes, $plugin_labels, $theme_labels ) {
        echo '<p class="premiero-top-note premiero-muted">Selecciona los plugins y temas que quieras instalar.</p>';

        echo '<form method="post">';
        wp_nonce_field( self::NONCE_ACTION );

        if ( !empty( $this->run_messages ) ) {
            echo '<div class="premiero-result" aria-live="polite">';
            foreach ( $this->run_messages as $msg ) {
                printf(
                    '<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
                    esc_attr( $msg['type'] ),
                    wp_kses_post( $msg['text'] )
                );
            }
            echo '</div>';
        }

        echo '<div class="premiero-repo-toolbar">';
        echo '<span class="description">Elige una acción por elemento. Puedes preparar todos y ajustar después las excepciones.</span>';
        echo '<button type="button" class="button" data-repo-set="install">Marcar todos para instalar</button>';
        echo '<button type="button" class="button" data-repo-set="">Limpiar selección</button>';
        echo '</div>';

        echo '<div class="premiero-two-cols">';

        // Columna A: Plugins (wp.org + locales)
        echo '<div class="premiero-box">';
        echo '<h3>Plugins</h3>';

        // WP.org (por URL pública)
        echo '<h4>WordPress.org</h4>';
        if ( empty( $wporg_plugins ) ) {
            echo '<p class="premiero-muted">No hay elementos configurados.</p>';
        } else {
            echo '<table class="widefat striped premiero-table"><thead><tr>';
            echo '<th>Nombre</th><th>Detalle</th><th>Estado</th><th>Acción</th></tr></thead><tbody>';
            foreach ( $wporg_plugins as $item ) {
                $name = $item['name'];
                $url  = $item['url'];
                $slug = $this->wporg_slug_from_input( $url );
                $status = $this->get_plugin_status( $slug );
                echo '<tr>';
                echo '<td data-label="Nombre">' . esc_html( $name ) . '</td>';
                echo '<td data-label="Detalle" class="premiero-small"><code>' . esc_html( $slug ) . '</code></td>';
                echo '<td data-label="Estado">' . $this->render_status( $status ) . '</td>';
                echo '<td data-label="Acción">' . $this->render_action_select( 'wporg_actions[' . $slug . ']', $name ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Plugins locales
        echo '<h4 style="margin-top:16px;">Locales (ZIP en assets/plugins)</h4>';
        if ( empty( $local_plugins ) ) {
            echo '<p class="premiero-muted">No se han encontrado ZIPs de plugins en la carpeta indicada.</p>';
        } else {
            echo '<table class="widefat striped premiero-table"><thead><tr>';
            echo '<th>Nombre</th><th>Archivo</th><th>Tamaño</th><th>Estado</th><th>Acción</th></tr></thead><tbody>';
            foreach ( $local_plugins as $file ) {
                $display = $plugin_labels[$file['name']] ?? $file['name'];
                $status  = $this->get_plugin_status( $this->get_local_plugin_slug( $file['name'] ) );
                echo '<tr>';
                echo '<td data-label="Nombre">' . esc_html( $display ) . '</td>';
                echo '<td data-label="Archivo"><code>' . esc_html( $file['name'] ) . '</code></td>';
                echo '<td data-label="Tamaño">' . esc_html( $file['size'] ? size_format( $file['size'] ) : '—' ) . '</td>';
                echo '<td data-label="Estado">' . $this->render_status( $status ) . '</td>';
                echo '<td data-label="Acción">' . $this->render_action_select( 'local_plugin_actions[' . $file['name'] . ']', $display ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        // Columna B: Temas (wp.org + locales)
        echo '<div class="premiero-box">';
        echo '<h3>Temas</h3>';

        echo '<h4>WordPress.org</h4>';
        if ( empty( $wporg_themes ) ) {
            echo '<p class="premiero-muted">No hay elementos configurados.</p>';
        } else {
            echo '<table class="widefat striped premiero-table"><thead><tr>';
            echo '<th>Nombre</th><th>Detalle</th><th>Estado</th><th>Acción</th></tr></thead><tbody>';
            foreach ( $wporg_themes as $item ) {
                $name = $item['name'];
                $slug = $item['slug'];
                $status = $this->get_theme_status( $slug );
                echo '<tr>';
                echo '<td data-label="Nombre">' . esc_html( $name ) . '</td>';
                echo '<td data-label="Detalle" class="premiero-small"><code>' . esc_html( $slug ) . '</code></td>';
                echo '<td data-label="Estado">' . $this->render_status( $status ) . '</td>';
                echo '<td data-label="Acción">' . $this->render_action_select( 'wporg_theme_actions[' . $slug . ']', $name ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h4 style="margin-top:16px;">Locales (ZIP en assets/themes)</h4>';
        if ( empty( $local_themes ) ) {
            echo '<p class="premiero-muted">No se han encontrado ZIPs de temas en la carpeta indicada.</p>';
        } else {
            echo '<table class="widefat striped premiero-table"><thead><tr>';
            echo '<th>Nombre</th><th>Archivo</th><th>Tamaño</th><th>Estado</th><th>Acción</th></tr></thead><tbody>';
            foreach ( $local_themes as $file ) {
                $display = $theme_labels[$file['name']] ?? $file['name'];
                $theme_slug = $this->infer_theme_stylesheet_from_zip( $file['path'] );
                $status = $this->get_theme_status( $theme_slug );
                echo '<tr>';
                echo '<td data-label="Nombre">' . esc_html( $display ) . '</td>';
                echo '<td data-label="Archivo"><code>' . esc_html( $file['name'] ) . '</code></td>';
                echo '<td data-label="Tamaño">' . esc_html( $file['size'] ? size_format( $file['size'] ) : '—' ) . '</td>';
                echo '<td data-label="Estado">' . $this->render_status( $status ) . '</td>';
                echo '<td data-label="Acción">' . $this->render_action_select( 'local_theme_actions[' . $file['name'] . ']', $display ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>'; // temas

        echo '</div>'; // two-cols

        echo '<div class="premiero-actions">';
        echo '<div class="premiero-installing" aria-live="polite"><span class="spinner"></span>Procesando instalaciones… No cierres esta página.</div>';
        submit_button( 'Instalar seleccionados', 'primary', 'premiero_run_install' );
        echo '</div>';

        echo '</form>';
        echo '<script>
        (function(){
            var form = document.currentScript.previousElementSibling;
            if ( ! form || form.tagName !== "FORM" ) return;
            var selects = form.querySelectorAll(".premiero-action-select");
            form.querySelectorAll("[data-repo-set]").forEach(function(button){
                button.addEventListener("click", function(){
                    selects.forEach(function(select){ select.value = button.dataset.repoSet; });
                });
            });
            form.addEventListener("submit", function(event){
                var selected = Array.prototype.some.call(selects, function(select){ return select.value !== ""; });
                if ( ! selected ) {
                    event.preventDefault();
                    window.alert("Selecciona al menos una acción antes de continuar.");
                    return;
                }
                var button = form.querySelector("#premiero_run_install");
                if ( button ) {
                    var action = document.createElement("input");
                    action.type = "hidden";
                    action.name = button.name;
                    action.value = button.value;
                    form.appendChild(action);
                    button.disabled = true;
                    button.value = "Procesando…";
                }
                var progress = form.querySelector(".premiero-installing");
                if ( progress ) progress.classList.add("is-active");
            });
        })();
        </script>';
    }

    /* =========================
     *   INSTALACIÓN
     * ========================= */

    public function handle_form_submit() {
        if ( ! isset( $_POST['premiero_run_install'] ) ) return;
        if ( ! current_user_can( 'install_plugins' ) ) return;
        check_admin_referer( self::NONCE_ACTION );

        // Includes imprescindibles
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php'; // <-- NECESARIO para plugins_api()

        // Preparar Filesystem (puede pedir credenciales)
        $url = wp_nonce_url( admin_url( 'admin.php?page=premiero-admin&tab=repository' ), 'premiero_fs' );
        if ( false === ( $creds = request_filesystem_credentials( $url ) ) ) {
            $this->run_messages[] = [ 'type' => 'warning', 'text' => 'Se requieren credenciales del sistema de archivos para continuar.' ];
            return;
        }
        if ( ! WP_Filesystem( $creds ) ) {
            request_filesystem_credentials( $url, '', true );
            $this->run_messages[] = [ 'type' => 'error', 'text' => 'No se pudo inicializar el sistema de archivos.' ];
            return;
        }

        $skin            = new Automatic_Upgrader_Skin();
        $plugin_upgrader = new Plugin_Upgrader( $skin );
        $theme_upgrader  = new Theme_Upgrader( $skin );

        // Convertir las acciones elegidas en las listas que usa el instalador.
        // Solo se aceptan entradas presentes en los catálogos o ZIP actuales.
        $wporg_actions       = isset( $_POST['wporg_actions'] ) ? (array) wp_unslash( $_POST['wporg_actions'] ) : [];
        $local_plugin_actions = isset( $_POST['local_plugin_actions'] ) ? (array) wp_unslash( $_POST['local_plugin_actions'] ) : [];
        $wporg_theme_actions = isset( $_POST['wporg_theme_actions'] ) ? (array) wp_unslash( $_POST['wporg_theme_actions'] ) : [];
        $local_theme_actions = isset( $_POST['local_theme_actions'] ) ? (array) wp_unslash( $_POST['local_theme_actions'] ) : [];

        $wporg_install  = [];
        $wporg_activate = [];
        foreach ( $this->get_wporg_plugins_catalog() as $item ) {
            $slug   = $this->wporg_slug_from_input( $item['url'] );
            $action = $slug && isset( $wporg_actions[$slug] ) ? sanitize_key( $wporg_actions[$slug] ) : '';
            if ( in_array( $action, [ 'install', 'activate' ], true ) ) {
                $wporg_install[] = $item['url'];
                if ( 'activate' === $action ) {
                    $wporg_activate[] = $item['url'];
                }
            }
        }

        $dirs          = $this->get_assets_dirs();
        $local_plugins = [];
        $lp_activate   = [];
        foreach ( $this->list_zip_files( $dirs['plugins'] ) as $file ) {
            $action = isset( $local_plugin_actions[$file['name']] ) ? sanitize_key( $local_plugin_actions[$file['name']] ) : '';
            if ( in_array( $action, [ 'install', 'activate' ], true ) ) {
                $local_plugins[] = $file['path'];
                if ( 'activate' === $action ) {
                    $lp_activate[] = $file['path'];
                }
            }
        }

        $wporg_themes = [];
        $wt_activate  = [];
        foreach ( $this->get_wporg_themes_catalog() as $item ) {
            $slug   = sanitize_key( $item['slug'] );
            $action = isset( $wporg_theme_actions[$slug] ) ? sanitize_key( $wporg_theme_actions[$slug] ) : '';
            if ( in_array( $action, [ 'install', 'activate' ], true ) ) {
                $wporg_themes[] = $slug;
                if ( 'activate' === $action ) {
                    $wt_activate[] = $slug;
                }
            }
        }

        $local_themes = [];
        $lt_activate  = [];
        foreach ( $this->list_zip_files( $dirs['themes'] ) as $file ) {
            $action = isset( $local_theme_actions[$file['name']] ) ? sanitize_key( $local_theme_actions[$file['name']] ) : '';
            if ( in_array( $action, [ 'install', 'activate' ], true ) ) {
                $local_themes[] = $file['path'];
                if ( 'activate' === $action ) {
                    $lt_activate[] = $file['path'];
                }
            }
        }

        // 1) WP.org (por URL -> slug)
        foreach ( $wporg_install as $input ) {
            $input = sanitize_text_field( $input );
            $slug  = $this->wporg_slug_from_input( $input );
            if ( ! $slug ) {
                $this->run_messages[] = [ 'type' => 'error', 'text' => 'No se pudo extraer el slug del elemento: <code>' . esc_html( $input ) . '</code>' ];
                continue;
            }

            try {
                $already = $this->locate_plugin_main_file_by_slug( $slug );
                if ( $already ) {
                    $this->run_messages[] = [ 'type' => 'info', 'text' => 'El plugin <code>' . esc_html( $slug ) . '</code> ya estaba instalado.' ];
                } else {
                    $this->install_from_wporg( $plugin_upgrader, $slug );
                    $this->run_messages[] = [ 'type' => 'success', 'text' => 'Instalado desde WordPress.org: <code>' . esc_html( $slug ) . '</code>.' ];
                }

                $plugin_file = $this->locate_plugin_main_file_by_slug( $slug );
                if ( $plugin_file && in_array( $input, $wporg_activate, true ) ) {
                    $this->ensure_activated( $plugin_file );
                    $this->run_messages[] = [ 'type' => 'success', 'text' => 'Activado: <code>' . esc_html( $slug ) . '</code>.' ];
                }
            } catch ( \Throwable $e ) {
                $this->run_messages[] = [ 'type' => 'error', 'text' => 'Error instalando <code>' . esc_html( $slug ) . '</code>: ' . esc_html( $e->getMessage() ) ];
                error_log( '[Premiero Repository] WP.org ' . $slug . ': ' . $e->getMessage() );
            }
        }

        // 2) Plugins ZIP locales
        $before_plugins = array_keys( get_plugins() );
        foreach ( $local_plugins as $zip_path ) {
            $zip_path = sanitize_text_field( $zip_path );
            try {
                $this->install_from_zip( $plugin_upgrader, $zip_path );

                // Detectar recién instalado comparando listado
                $after_plugins  = array_keys( get_plugins() );
                $new_entries    = array_values( array_diff( $after_plugins, $before_plugins ) );
                $plugin_file    = !empty($new_entries) ? $new_entries[0] : false;
                $before_plugins = $after_plugins; // actualizar baseline

                $this->run_messages[] = [ 'type' => 'success', 'text' => 'Instalado plugin local: <code>' . esc_html( basename( $zip_path ) ) . '</code>.' ];

                if ( $plugin_file && in_array( $zip_path, $lp_activate, true ) ) {
                    $this->ensure_activated( $plugin_file );
                    $this->run_messages[] = [ 'type' => 'success', 'text' => 'Activado plugin local: <code>' . esc_html( $plugin_file ) . '</code>.' ];
                }
            } catch ( \Throwable $e ) {
                $this->run_messages[] = [ 'type' => 'error', 'text' => 'Error instalando plugin local <code>' . esc_html( basename( $zip_path ) ) . '</code>: ' . esc_html( $e->getMessage() ) ];
                error_log( '[Premiero Repository] ZIP plugin ' . $zip_path . ': ' . $e->getMessage() );
            }
        }

        // 3) Temas de WordPress.org
        foreach ( $wporg_themes as $slug ) {
            $slug = sanitize_title( $slug );
            if ( ! $slug ) {
                $this->run_messages[] = [ 'type' => 'error', 'text' => 'No se pudo identificar uno de los temas seleccionados.' ];
                continue;
            }

            try {
                if ( wp_get_theme( $slug )->exists() ) {
                    $this->run_messages[] = [ 'type' => 'info', 'text' => 'El tema <code>' . esc_html( $slug ) . '</code> ya estaba instalado.' ];
                } else {
                    $this->install_theme_from_wporg( $theme_upgrader, $slug );
                    $this->run_messages[] = [ 'type' => 'success', 'text' => 'Instalado desde WordPress.org: <code>' . esc_html( $slug ) . '</code>.' ];
                }

                if ( in_array( $slug, $wt_activate, true ) && wp_get_theme( $slug )->exists() ) {
                    switch_theme( $slug );
                    $this->run_messages[] = [ 'type' => 'success', 'text' => 'Tema activado: <code>' . esc_html( $slug ) . '</code>.' ];
                }
            } catch ( \Throwable $e ) {
                $this->run_messages[] = [ 'type' => 'error', 'text' => 'Error instalando el tema <code>' . esc_html( $slug ) . '</code>: ' . esc_html( $e->getMessage() ) ];
                error_log( '[Premiero Repository] Tema WP.org ' . $slug . ': ' . $e->getMessage() );
            }
        }

        // 4) Temas ZIP locales
        foreach ( $local_themes as $zip_path ) {
            $zip_path = sanitize_text_field( $zip_path );
            try {
                $res = $theme_upgrader->install( $zip_path );
                if ( is_wp_error( $res ) || ! $res ) {
                    throw new \Exception( 'Fallo instalando tema desde ZIP' );
                }
                $this->run_messages[] = [ 'type' => 'success', 'text' => 'Instalado tema local: <code>' . esc_html( basename( $zip_path ) ) . '</code>.' ];

                if ( in_array( $zip_path, $lt_activate, true ) ) {
                    $stylesheet = $this->infer_theme_stylesheet_from_zip( $zip_path );
                    if ( $stylesheet && wp_get_theme( $stylesheet )->exists() ) {
                        switch_theme( $stylesheet );
                        $this->run_messages[] = [ 'type' => 'success', 'text' => 'Tema activado: <code>' . esc_html( $stylesheet ) . '</code>.' ];
                    } else {
                        $this->run_messages[] = [ 'type' => 'warning', 'text' => 'No se pudo inferir/activar el tema desde <code>' . esc_html( basename( $zip_path ) ) . '</code>.' ];
                    }
                }
            } catch ( \Throwable $e ) {
                $this->run_messages[] = [ 'type' => 'error', 'text' => 'Error instalando tema local <code>' . esc_html( basename( $zip_path ) ) . '</code>: ' . esc_html( $e->getMessage() ) ];
                error_log( '[Premiero Repository] ZIP tema ' . $zip_path . ': ' . $e->getMessage() );
            }
        }

        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-info is-dismissible"><p>Proceso finalizado. Revisa el detalle más abajo.</p></div>';
        } );
    }

    /** Obtener slug WP.org desde URL pública o slug directo */
    protected function wporg_slug_from_input( $input ) {
        $input = trim( (string) $input );
        // Si es URL, extraer /plugins/{slug}/
        if ( filter_var( $input, FILTER_VALIDATE_URL ) ) {
            $path = wp_parse_url( $input, PHP_URL_PATH );
            if ( $path ) {
                if ( preg_match( '#/plugins/([^/]+)/?#i', $path, $m ) ) {
                    return sanitize_title( $m[1] );
                }
            }
            return false;
        }
        // si ya es slug
        return sanitize_title( $input );
    }

    /** Instala un plugin desde WordPress.org por slug */
    protected function install_from_wporg( Plugin_Upgrader $upgrader, $slug ) {
        // ¿ya está instalado?
        $plugin_file = $this->locate_plugin_main_file_by_slug( $slug );
        if ( $plugin_file ) return true;

        // Importante: plugins_api requiere plugin-install.php (ya incluido arriba)
        $api = plugins_api( 'plugin_information', [
            'slug'   => $slug,
            'fields' => [ 'sections' => false ],
        ] );

        if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
            // Fallback defensivo: intentar URL de descarga directa del JSON de api.wordpress.org (no imprescindible)
            throw new \Exception( 'No se pudo obtener el plugin desde WP.org (slug: ' . $slug . ').' );
        }

        $res = $upgrader->install( $api->download_link );
        if ( is_wp_error( $res ) || ! $res ) {
            throw new \Exception( 'Fallo instalando ' . $slug );
        }
        return true;
    }

    /** Instala un tema desde WordPress.org por slug */
    protected function install_theme_from_wporg( Theme_Upgrader $upgrader, $slug ) {
        if ( wp_get_theme( $slug )->exists() ) return true;

        $api = themes_api( 'theme_information', [
            'slug'   => $slug,
            'fields' => [ 'sections' => false ],
        ] );

        if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
            throw new \Exception( 'No se pudo obtener el tema desde WordPress.org (slug: ' . $slug . ').' );
        }

        $res = $upgrader->install( $api->download_link );
        if ( is_wp_error( $res ) || ! $res ) {
            throw new \Exception( 'Fallo instalando el tema ' . $slug );
        }
        return true;
    }

    /** Instala un plugin desde ZIP local */
    protected function install_from_zip( Plugin_Upgrader $upgrader, $zip_path ) {
        $res = $upgrader->install( $zip_path );
        if ( is_wp_error( $res ) || ! $res ) {
            throw new \Exception( 'Fallo instalando ZIP: ' . $zip_path );
        }
        return true;
    }

    /** Activa un plugin si no lo está */
    protected function ensure_activated( $plugin_file ) {
        if ( is_plugin_active( $plugin_file ) ) return true;
        $result = activate_plugin( $plugin_file );
        if ( is_wp_error( $result ) ) {
            throw new \Exception( 'No se pudo activar: ' . $plugin_file . ' → ' . $result->get_error_message() );
        }
        return true;
    }

    /** Localiza el main file por slug de carpeta */
    protected function locate_plugin_main_file_by_slug( $slug ) {
        $all = get_plugins();
        foreach ( $all as $file => $data ) {
            if ( preg_match( '#^' . preg_quote( $slug, '#' ) . '/[^/]+\.php$#', $file ) ) {
                return $file;
            }
        }
        return false;
    }

    /** Inferir stylesheet del tema a partir de su ZIP (carpeta que contiene style.css) */
    protected function infer_theme_stylesheet_from_zip( $zip_path ) {
        if ( ! class_exists( 'ZipArchive' ) ) return false;
        $zip = new ZipArchive();
        if ( true !== $zip->open( $zip_path ) ) return false;

        $candidate = false;
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $stat = $zip->statIndex( $i );
            if ( ! $stat || empty( $stat['name'] ) ) continue;
            $name = $stat['name'];
            if ( preg_match( '#^([^/]+)/style\.css$#i', $name, $m ) ) {
                $candidate = $m[1];
                break;
            }
        }
        $zip->close();
        return $candidate ?: false;
    }
}

$GLOBALS['premiero_repository'] = new Premiero_Admin_Toolkit_Repository();

function premiero_render_repository() {
    $repo = $GLOBALS['premiero_repository'] ?? null;
    if ( $repo instanceof Premiero_Admin_Toolkit_Repository ) {
        $repo->render_embedded_page();
    }
}

/* ====================== Snippets como MU ====================== */
function premiero_ensure_mu_dir() {
    $mu_dir = WP_CONTENT_DIR . '/mu-plugins';
    if ( ! is_dir($mu_dir) ) wp_mkdir_p($mu_dir);
    return is_dir($mu_dir) && is_writable($mu_dir);
}

function premiero_validate_php_snippets( $content ) {
    try {
        token_get_all( $content, TOKEN_PARSE );
    } catch ( \Throwable $error ) {
        return new WP_Error(
            'php_syntax',
            sprintf(
                'El código PHP contiene un error de sintaxis: %s',
                $error->getMessage()
            )
        );
    }
    return true;
}

function premiero_write_mu_snippets( $php_code ) {
    if ( ! premiero_ensure_mu_dir() ) return new WP_Error('mu_dir', 'No se pudo crear/escribir en /wp-content/mu-plugins');
    $file = WP_CONTENT_DIR . '/mu-plugins/premiero-snippets.php';
    $brand_name  = str_replace( '*/', '', premiero_get_brand_name() );
    $toolkit_name = str_replace( '*/', '', premiero_get_toolkit_name() );
    $header = <<<PHP
<?php
/**
 * Plugin Name: {$brand_name} Snippets (MU)
 * Description: Snippets PHP gestionados desde {$toolkit_name}.
 * Author: Premiero
 * Version: 1.0
 */
if ( ! defined('ABSPATH') ) { exit; }
/* --- Inicio de tus snippets --- */

PHP;
    $content = $header . rtrim($php_code) . "\n";
    $validation = premiero_validate_php_snippets( $content );
    if ( is_wp_error( $validation ) ) {
        return $validation;
    }

    $temp_file = $file . '.tmp-' . wp_generate_password( 8, false, false );
    if ( false === @file_put_contents( $temp_file, $content, LOCK_EX ) ) {
        return new WP_Error( 'write_fail', 'No fue posible preparar el archivo MU: ' . $temp_file );
    }

    if ( file_exists( $file ) && ! @copy( $file, $file . '.bak' ) ) {
        wp_delete_file( $temp_file );
        return new WP_Error( 'backup_fail', 'No fue posible crear la copia de seguridad del MU-plugin actual.' );
    }

    if ( ! @rename( $temp_file, $file ) ) {
        wp_delete_file( $temp_file );
        return new WP_Error( 'replace_fail', 'No fue posible sustituir el archivo MU de forma segura.' );
    }

    return true;
}

/* ====================== Helpers ====================== */
function premiero_get_login_logo_url() {
    $id = (int) get_option(PREMIERO_OPT_LOGIN_LOGO_ID, 0);
    if ( $id ) {
        $src = wp_get_attachment_image_src($id, 'full');
        if ( ! empty($src[0]) ) return $src[0];
    }
    $brand_logo = premiero_get_brand_logo_url();
    if ( $brand_logo ) return $brand_logo;
    $logo_id = get_theme_mod('custom_logo');
    if ( $logo_id ) {
        $src = wp_get_attachment_image_src($logo_id, 'full');
        if ( ! empty($src[0]) ) return $src[0];
    }
    return PREMIERO_ATK_ASSETS . 'premiero-logo.png';
}

function premiero_import_legacy_brand_logo() {
    if ( ! is_readable( PREMIERO_ATK_LEGACY_LOGO ) ) {
        return new WP_Error( 'premiero_legacy_logo_missing', 'No se encontró el logo anterior de TecnoDerecho.' );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $temp_file = wp_tempnam( basename( PREMIERO_ATK_LEGACY_LOGO ) );
    if ( ! $temp_file || ! copy( PREMIERO_ATK_LEGACY_LOGO, $temp_file ) ) {
        return new WP_Error( 'premiero_legacy_logo_copy', 'No se pudo preparar el logo anterior para importarlo.' );
    }

    $attachment_id = media_handle_sideload( [
        'name'     => 'tecnoderecho-logo.png',
        'tmp_name' => $temp_file,
    ], 0, 'Logo de TecnoDerecho' );

    if ( is_wp_error( $attachment_id ) ) {
        wp_delete_file( $temp_file );
    }
    return $attachment_id;
}

function premiero_run_legacy_migration() {
    if ( ! get_option( 'premiero_atk_legacy_migration_pending', false ) || ! current_user_can('manage_options') ) {
        return;
    }

    update_option( PREMIERO_OPT_WHITE_LABEL_NAME, 'TecnoDerecho' );
    update_option( PREMIERO_OPT_WHITE_LABEL_ENABLED, 1 );

    $logo_warning = false;
    if ( ! get_option( PREMIERO_OPT_WHITE_LABEL_LOGO_ID, 0 ) ) {
        $attachment_id = premiero_import_legacy_brand_logo();
        if ( is_wp_error( $attachment_id ) ) {
            $logo_warning = true;
        } else {
            update_option( PREMIERO_OPT_WHITE_LABEL_LOGO_ID, (int) $attachment_id );
        }
    }

    $mu_migrated = true;
    $legacy_mu   = WP_CONTENT_DIR . '/mu-plugins/tecnoderecho-snippets.php';
    if ( file_exists( $legacy_mu ) ) {
        $mu_result = premiero_write_mu_snippets( get_option(PREMIERO_OPT_SNIPPETS, '') );
        if ( is_wp_error( $mu_result ) ) {
            $mu_migrated = false;
        } else {
            wp_delete_file( $legacy_mu );
            $mu_migrated = ! file_exists( $legacy_mu );
        }
    }

    if ( $mu_migrated ) {
        update_option( 'premiero_atk_legacy_migration_pending', 0, true );
        update_option( 'premiero_atk_legacy_migration_notice', $logo_warning ? 'logo-warning' : 'success', true );
    } else {
        update_option( 'premiero_atk_legacy_migration_notice', 'mu-warning', true );
    }
}
add_action('admin_init', 'premiero_run_legacy_migration', 1);

add_action('admin_notices', function() {
    if ( ! current_user_can('manage_options') ) {
        return;
    }
    $notice = get_option( 'premiero_atk_legacy_migration_notice', '' );
    if ( ! $notice ) {
        return;
    }
    update_option( 'premiero_atk_legacy_migration_notice', '', true );

    if ( 'success' === $notice ) {
        echo '<div class="notice notice-success is-dismissible"><p><strong>TecnoDerecho se ha migrado correctamente.</strong> Premiero Admin Toolkit conserva su configuración y ahora utiliza la identidad TecnoDerecho. El plugin antiguo ha quedado inactivo y ya puedes eliminarlo.</p></div>';
    } elseif ( 'logo-warning' === $notice ) {
        echo '<div class="notice notice-warning is-dismissible"><p>La configuración de TecnoDerecho se ha migrado, pero no se pudo importar su logo. Puedes seleccionarlo desde la pestaña <strong>Identidad</strong>. El plugin antiguo ha quedado inactivo.</p></div>';
    } elseif ( 'mu-warning' === $notice ) {
        echo '<div class="notice notice-error"><p>No se pudo completar la migración del MU-plugin de snippets de TecnoDerecho. El plugin antiguo está inactivo, pero todavía no debes eliminarlo.</p></div>';
    }
});

function premiero_handle_branding_submit() {
    if (
        ! isset($_POST['premiero_branding_submit'])
        || ! current_user_can('manage_options')
        || PREMIERO_ATK_SLUG !== sanitize_key($_GET['page'] ?? '')
        || 'branding' !== sanitize_key($_GET['tab'] ?? '')
    ) {
        return;
    }

    check_admin_referer('premiero_branding_nonce');

    $enabled = isset($_POST[PREMIERO_OPT_WHITE_LABEL_ENABLED]) ? 1 : 0;
    $name    = sanitize_text_field( wp_unslash( $_POST[PREMIERO_OPT_WHITE_LABEL_NAME] ?? '' ) );
    $logo_id = absint( $_POST[PREMIERO_OPT_WHITE_LABEL_LOGO_ID] ?? 0 );
    $status  = 'updated';

    update_option(PREMIERO_OPT_WHITE_LABEL_NAME, $name);
    update_option(PREMIERO_OPT_WHITE_LABEL_LOGO_ID, $logo_id);

    if ( $enabled && '' === $name ) {
        update_option(PREMIERO_OPT_WHITE_LABEL_ENABLED, 0);
        $status = 'missing-name';
    } else {
        update_option(PREMIERO_OPT_WHITE_LABEL_ENABLED, $enabled);
    }

    $mu_file = WP_CONTENT_DIR . '/mu-plugins/premiero-snippets.php';
    if ( file_exists($mu_file) ) {
        $mu_result = premiero_write_mu_snippets( get_option(PREMIERO_OPT_SNIPPETS, '') );
        if ( is_wp_error($mu_result) && 'updated' === $status ) {
            $status = 'mu-warning';
        }
    }

    wp_safe_redirect( add_query_arg( [
        'page'            => PREMIERO_ATK_SLUG,
        'tab'             => 'branding',
        'branding-status' => $status,
    ], admin_url('admin.php') ) );
    exit;
}
add_action('admin_init', 'premiero_handle_branding_submit');

/* ====================== Menú Premiero ====================== */
add_action('admin_menu', function() {
    $brand_name = premiero_get_brand_name();
    add_menu_page(
        $brand_name,
        $brand_name,
        'manage_options',
        PREMIERO_ATK_SLUG,
        'premiero_render_settings_page',
        'dashicons-admin-tools',
        81
    );
    add_submenu_page(PREMIERO_ATK_SLUG,'Ajustes','Ajustes','manage_options',PREMIERO_ATK_SLUG,'premiero_render_settings_page');
}, 20);

/* ====================== Registrar opciones ====================== */
add_action('admin_init', function() {

    register_setting('premiero_code_settings_group', PREMIERO_OPT_CSS);
    register_setting('premiero_code_settings_group', PREMIERO_OPT_HEAD_HTML);
    register_setting('premiero_code_settings_group', PREMIERO_OPT_BODY_HTML);
    register_setting('premiero_snippets_settings_group', PREMIERO_OPT_SNIPPETS);

    register_setting('premiero_menu_settings_group', PREMIERO_OPT_MENU_GROUP, [
        'type'              => 'array',
        'default'           => [],
        'sanitize_callback' => function($value){
            $out = [];
            if ( is_array($value) ) {
                foreach ($value as $slug) {
                    $slug = sanitize_text_field($slug);
                    if ($slug && $slug !== PREMIERO_ATK_SLUG) $out[] = $slug;
                }
            }
            return array_values(array_unique($out));
        }
    ]);

    register_setting('premiero_menu_settings_group', PREMIERO_OPT_MENU_LABELS, [
        'type'              => 'array',
        'default'           => [],
        'sanitize_callback' => function($value){
            $out = [];
            if ( is_array($value) ) {
                foreach ($value as $slug => $label) {
                    $slug  = sanitize_text_field($slug);
                    $label = wp_kses_post( $label );
                    if ($slug) $out[$slug] = $label;
                }
            }
            return $out;
        }
    ]);

    register_setting('premiero_login_settings_group', PREMIERO_OPT_LOGIN_BG,     ['type'=>'string','default'=>'']);
    register_setting('premiero_login_settings_group', PREMIERO_OPT_LOGIN_CREDIT, ['type'=>'boolean','default'=>true]);
    register_setting('premiero_login_settings_group', PREMIERO_OPT_LOGIN_LOGO_ID,['type'=>'integer','default'=>0]);
    register_setting('premiero_login_settings_group', PREMIERO_OPT_LOGIN_LOGO_W, ['type'=>'integer','default'=>260]);

    register_setting('premiero_branding_settings_group', PREMIERO_OPT_WHITE_LABEL_ENABLED, ['type'=>'boolean','default'=>false]);
    register_setting('premiero_branding_settings_group', PREMIERO_OPT_WHITE_LABEL_NAME, [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('premiero_branding_settings_group', PREMIERO_OPT_WHITE_LABEL_LOGO_ID, ['type'=>'integer','default'=>0]);
});

/* ====================== Aplicar CSS/HTML en frontend ====================== */
add_action('wp_head', function() {
    $head = trim((string) get_option(PREMIERO_OPT_HEAD_HTML, ''));
    if ( $head ) echo "\n<!-- Premiero head -->\n{$head}\n";
    $css = trim((string) get_option(PREMIERO_OPT_CSS, ''));
    if ( $css ) echo "\n<style id='premiero-custom-css'>\n{$css}\n</style>\n";
}, 99);

add_action('wp_body_open', function() {
    $GLOBALS['premiero_body_open_ran'] = true;
    $body = trim((string) get_option(PREMIERO_OPT_BODY_HTML, ''));
    if ( $body ) echo "\n<!-- Premiero body_open -->\n{$body}\n";
}, 1);

add_action('wp_footer', function() {
    if ( ! empty( $GLOBALS['premiero_body_open_ran'] ) ) return;
    $body = trim((string) get_option(PREMIERO_OPT_BODY_HTML, ''));
    if ( $body ) echo "\n<!-- Premiero body (fallback) -->\n{$body}\n";
}, 1);

/* ====================== RENOMBRAR TOP-LEVEL NO AGRUPADOS ====================== */
add_action('admin_menu', function() {
    $labels  = (array) get_option(PREMIERO_OPT_MENU_LABELS, []);
    if ( empty($labels) ) return;

    $grouped = (array) get_option(PREMIERO_OPT_MENU_GROUP, []);

    global $menu;
    if ( ! is_array($menu) ) return;

    foreach ( $menu as $idx => $m ) {
        if ( empty($m[2]) ) continue;
        $slug = (string) $m[2];
        if ( $slug === PREMIERO_ATK_SLUG || in_array($slug, $grouped, true) ) continue;

        if ( isset($labels[$slug]) && $labels[$slug] !== '' ) {
            $plain = wp_strip_all_tags( $labels[$slug] );
            $menu[$idx][0] = $plain;
            if ( isset($menu[$idx][3]) ) $menu[$idx][3] = $plain;
        }
    }
}, 998);

/* ====================== AGRUPACIÓN DE MENÚS BAJO PREMIERO ====================== */
add_action('admin_menu', function() {

    $grouped = (array) get_option(PREMIERO_OPT_MENU_GROUP, []);
    $labels  = (array) get_option(PREMIERO_OPT_MENU_LABELS, []);

    if ( empty($grouped) ) return;

    // Mapa actual de títulos/capacidades
    global $menu;
    $map = []; // slug => [title, cap]
    foreach ( (array) $menu as $m ) {
        if ( !empty($m[2]) ) {
            $map[$m[2]] = [
                'title' => wp_strip_all_tags($m[0]),
                'cap'   => !empty($m[1]) ? $m[1] : 'manage_options'
            ];
        }
    }

    // 1) Separador visual bajo "Ajustes" (espacio sin línea ni texto)
    add_submenu_page(
        PREMIERO_ATK_SLUG,
        '',
        '',
        'manage_options',
        'premiero-separator',
        function(){ /* vacío */ }
    );

    // 2) Mover seleccionados bajo Premiero
    foreach ( $grouped as $slug ) {
        if ( ! $slug || $slug === PREMIERO_ATK_SLUG ) continue;

        // Oculta del menú principal
        remove_menu_page($slug);

        // Título/capacidad
        $orig_title = isset($map[$slug]['title']) ? $map[$slug]['title'] : $slug;
        $cap        = isset($map[$slug]['cap'])   ? $map[$slug]['cap']   : 'manage_options';

        // Label personalizado si existe (permitimos HTML básico dentro de Premiero)
        $menu_title = isset($labels[$slug]) && $labels[$slug] !== '' ? $labels[$slug] : $orig_title;

        add_submenu_page(
            PREMIERO_ATK_SLUG,
            wp_strip_all_tags( $menu_title ), // page title
            $menu_title,                       // menu title
            $cap,
            $slug
        );
    }

}, 999);

/* CSS del separador (sin texto ni línea: solo espacio) */
add_action('admin_head', function(){
    echo '<style>
    #adminmenu .wp-submenu a[href="admin.php?page=premiero-separator"]{
        cursor: default !important;
        pointer-events: none !important;
        height:10px;
        display:block;
        content:"";
    }
    </style>';
});

/* ====================== UI: Cabecera y Tabs ====================== */
function premiero_admin_header($active_tab = 'info') {
    $brand_name = premiero_get_brand_name();
    $logo       = premiero_get_brand_logo_url('medium');
    $sections = [
        'info'       => ['Acerca de', 'Autoría, licencia, actualizaciones, soporte y acceso rápido.'],
        'code'       => ['Código', 'Gestiona PHP, HTML y CSS personalizado desde un único lugar.'],
        'menuwp'     => ['Menú', 'Agrupa y renombra elementos del menú de administración.'],
        'repository' => ['Repositorio', 'Instala plugins y temas locales o desde WordPress.org.'],
        'adminui'    => ['Login', 'Personaliza la pantalla de acceso y su crédito.'],
        'branding'   => ['Identidad', 'Adapta el nombre y el logo del plugin para un cliente.'],
        'monitoring' => ['Monitorización', 'Conecta esta instalación con la consola privada de mantenimiento.'],
        'remote-backups' => ['Copias de Seguridad', 'Sincroniza automáticamente las copias terminadas de UpdraftPlus por SFTP.'],
        'notices'    => ['Avisos', 'Registra y controla los avisos mostrados en la administración de WordPress.'],
    ];
    $section = $sections[$active_tab] ?? $sections['info'];
    ?>
    <div class="premiero-head" style="display:flex;align-items:center;justify-content:space-between;margin:12px 0 20px;padding-right:20px;">
        <div class="premiero-head-copy">
            <h1 style="margin:0;font-size:20px;"><?php echo esc_html($brand_name); ?> · <?php echo esc_html($section[0]); ?></h1>
            <p><?php echo esc_html($section[1]); ?></p>
        </div>
        <?php if ( $logo ): ?>
            <?php if ( premiero_is_white_label() ): ?>
                <img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($brand_name); ?>" style="height:36px;max-width:100%;object-fit:contain;">
            <?php else: ?>
                <a href="https://premiero.es" target="_blank" rel="noopener">
                    <img src="<?php echo esc_url($logo); ?>" alt="Premiero" style="height:36px;max-width:100%;object-fit:contain;">
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

function premiero_tabs_nav($active) {
    $tabs = [
        'info'       => 'Acerca de',
        'menuwp'     => 'Menú',
        'notices'    => 'Avisos',
        'code'       => 'Código',
        'remote-backups' => 'Copias de Seguridad',
        'repository' => 'Repositorio',
        'adminui'    => 'Login',
        'branding'   => 'Identidad',
        'monitoring' => 'Monitorización',
    ];
    echo '<h2 class="nav-tab-wrapper" id="premiero-tabs-nav">';
    foreach ($tabs as $slug => $label) {
        $class = $active === $slug ? ' nav-tab nav-tab-active' : ' nav-tab';
        $url   = admin_url('admin.php?page='.PREMIERO_ATK_SLUG.'&tab='.$slug);
        $current = $active === $slug ? ' aria-current="page"' : '';
        echo '<a class="'.esc_attr($class).'" href="'.esc_url($url).'"'.$current.'>'.$label.'</a>';
    }
    echo '</h2>';
    echo '<script>(function(){function revealActiveTab(){var nav=document.getElementById("premiero-tabs-nav");var active=nav&&nav.querySelector(".nav-tab-active");if(!nav||!active||nav.scrollWidth<=nav.clientWidth){return;}nav.scrollLeft=Math.max(0,active.offsetLeft-(nav.clientWidth-active.offsetWidth)/2);}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",revealActiveTab);}else{revealActiveTab();}}());</script>';
}

/* ====================== Estilos mínimos ====================== */
add_action('admin_enqueue_scripts', function($hook){
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if ( PREMIERO_ATK_SLUG !== $page ) return;

    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'info';
    if ( in_array($tab, ['code', 'php', 'head', 'body', 'css'], true) ) {
        $GLOBALS['premiero_code_editor_settings'] = [
            'php'  => wp_enqueue_code_editor(['type' => 'text/x-php']),
            'html' => wp_enqueue_code_editor(['type' => 'text/html']),
            'css'  => wp_enqueue_code_editor(['type' => 'text/css']),
        ];
    }
    if ( 'adminui' === $tab ) {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }

    echo '<style>
    .premiero-head{box-sizing:border-box;width:100%;}
    .premiero-head-copy{min-width:0}
    .premiero-head-copy p{margin:4px 0 0;color:#646970}
    .nav-tab-wrapper{display:flex;flex-wrap:nowrap;gap:6px;overflow-x:auto;overflow-y:hidden;padding:0 20px 0 0!important;scroll-behavior:smooth;scrollbar-color:#c3c4c7 transparent;scrollbar-width:thin;-webkit-overflow-scrolling:touch}
    .nav-tab-wrapper .nav-tab{float:none;flex:0 0 auto;margin:0;white-space:nowrap}
    .nav-tab-wrapper::-webkit-scrollbar{height:4px}
    .nav-tab-wrapper::-webkit-scrollbar-thumb{border-radius:999px;background:#c3c4c7}
    .nav-tab-wrapper .nav-tab:focus-visible{outline:2px solid #2271b1;outline-offset:2px;box-shadow:none}
    .premiero-card{box-sizing:border-box;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:24px;margin:12px 20px 0 0;width:auto;max-width:none;}
    .premiero-card .premiero-card{margin-right:0;}
    .premiero-overview{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-top:18px}
    .premiero-overview a{display:block;border:1px solid #dcdcde;border-radius:6px;padding:16px;text-decoration:none;background:#fff}
    .premiero-overview a:hover{border-color:#2271b1;box-shadow:0 1px 3px rgba(0,0,0,.08)}
    .premiero-overview strong{display:block;margin-bottom:5px;color:#1d2327}
    .premiero-overview span{color:#646970}
    .premiero-remote-backups{max-width:1180px;padding-right:20px;color:#1d2327}
    .premiero-remote-header{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin:12px 0 20px}
    .premiero-remote-header h2{margin:0;font-size:20px;line-height:1.4}
    .premiero-remote-header p:not(.premiero-remote-eyebrow){margin:4px 0 0;color:#646970;font-size:13px}
    .premiero-remote-eyebrow{margin:0 0 6px;color:#646970;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase}
    .premiero-remote-status{display:inline-flex;align-items:center;gap:8px;flex:0 0 auto;padding:7px 11px;border:1px solid #c3e6cb;border-radius:999px;background:#edfaef;color:#116329;font-size:12px;font-weight:600}
    .premiero-remote-status span{width:7px;height:7px;border-radius:50%;background:#00a32a}
    .premiero-remote-status.is-off{border-color:#dcdcde;background:#f6f7f7;color:#646970}
    .premiero-remote-status.is-off span{background:#8c8f94}
    .premiero-remote-daily{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:22px 24px;border:1px solid #c3d4e8;border-left:4px solid #2271b1;border-radius:8px;background:linear-gradient(135deg,#f7fbff,#fff);box-shadow:0 2px 7px rgba(34,113,177,.08)}
    .premiero-remote-daily h3{margin:0 0 5px;font-size:18px}
    .premiero-remote-daily p:not(.premiero-remote-eyebrow){max-width:650px;margin:0;color:#50575e;line-height:1.5}
    .premiero-remote-quick-form{display:flex;flex:0 0 auto;flex-direction:column;align-items:center;gap:7px;margin:0}
    .premiero-remote-quick-form .button-hero{min-height:40px;padding:7px 18px;line-height:1.3;white-space:nowrap}
    .premiero-remote-quick-form span{color:#646970;font-size:11px}
    .premiero-remote-backups .premiero-overview{grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:16px 0 26px}
    .premiero-remote-backups .premiero-overview a{position:relative;padding:14px 15px;border-radius:7px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
    .premiero-remote-backups .premiero-overview a:before{position:absolute;top:0;bottom:0;left:0;width:3px;border-radius:7px 0 0 7px;background:#8c8f94;content:""}
    .premiero-remote-backups .premiero-overview a.is-success:before{background:#00a32a}
    .premiero-remote-backups .premiero-overview a.is-warning:before{background:#dba617}
    .premiero-remote-backups .premiero-overview a.is-danger:before{background:#d63638}
    .premiero-remote-backups .premiero-overview strong{font-size:12px}
    .premiero-remote-backups .premiero-overview span{font-size:12px}
    .premiero-remote-queue{max-width:none!important;margin-top:0!important;padding:22px 24px;border:1px solid #dcdcde;border-radius:8px;background:#fff;box-shadow:0 2px 7px rgba(0,0,0,.04)}
    .premiero-remote-queue h3{margin:0 0 6px;font-size:18px}
    .premiero-remote-queue>p{margin:0 0 16px;color:#646970;line-height:1.5}
    .premiero-remote-table-wrap{width:100%;overflow-x:auto;border:1px solid #dcdcde;border-radius:5px}
    .premiero-remote-table{min-width:820px;border:0!important;margin:0!important}
    .premiero-remote-table th{white-space:nowrap;background:#f6f7f7;color:#50575e;font-size:11px;text-transform:uppercase;letter-spacing:.03em}
    .premiero-remote-table td,.premiero-remote-table th{padding:11px 12px;vertical-align:top}
    .premiero-remote-table td:nth-child(3){min-width:180px}
    .premiero-remote-settings-panel{margin:22px 0 0;border:1px solid #dcdcde;border-radius:8px;background:#fff;box-shadow:0 2px 7px rgba(0,0,0,.03)}
    .premiero-remote-settings-panel>summary{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:17px 20px;cursor:pointer;list-style:none}
    .premiero-remote-settings-panel>summary::-webkit-details-marker{display:none}
    .premiero-remote-settings-panel>summary strong{display:block;font-size:14px}
    .premiero-remote-settings-panel>summary small{display:block;margin-top:3px;color:#646970;font-size:12px;font-weight:400}
    .premiero-remote-settings-chevron{width:8px;height:8px;border-right:2px solid #646970;border-bottom:2px solid #646970;transform:rotate(45deg);transition:transform .15s ease}
    .premiero-remote-settings-panel[open]>summary{border-bottom:1px solid #dcdcde;background:#f6f7f7;border-radius:8px 8px 0 0}
    .premiero-remote-settings-panel[open] .premiero-remote-settings-chevron{transform:rotate(225deg)}
    .premiero-remote-settings-body{padding:20px 24px}
    .premiero-remote-help{margin-bottom:18px;padding:13px 15px;border-left:3px solid #8c8f94;background:#f6f7f7;color:#50575e;font-size:12px;line-height:1.5}
    .premiero-remote-help strong{color:#1d2327}
    .premiero-remote-help p{margin:4px 0 0}
    .premiero-remote-settings-form{max-width:920px;margin:0}
    .premiero-remote-settings-form .form-table{margin-top:0}
    .premiero-remote-settings-actions{display:flex;align-items:center;flex-wrap:wrap;gap:9px;margin-top:12px}
    .premiero-remote-settings-actions .description{margin-left:4px}
    .premiero-remote-fingerprint{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:12px;max-width:920px;margin-top:22px;padding:13px 15px;border:1px solid #dcdcde;border-radius:6px;background:#f6f7f7;font-size:12px}
    .premiero-remote-fingerprint strong,.premiero-remote-fingerprint span{display:block}
    .premiero-remote-fingerprint span{margin-top:2px;color:#646970}
    .premiero-remote-fingerprint code{overflow-wrap:anywhere;color:#50575e}
    .premiero-remote-fingerprint form{margin:0}
    .premiero-remote-settings-body section{max-width:920px!important}
    .premiero-notices-console{max-width:1180px;padding-right:20px;color:#1d2327}
    .premiero-notices-console>h2{margin:12px 0 4px;font-size:20px}
    .premiero-notices-lead{margin:0 0 18px;color:#646970}
    .premiero-notices-overview{grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:16px 0}
    .premiero-notices-overview>div{position:relative;padding:14px 15px;border:1px solid #dcdcde;border-radius:7px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.03)}
    .premiero-notices-overview>div:before{position:absolute;top:0;bottom:0;left:0;width:3px;border-radius:7px 0 0 7px;background:#2271b1;content:""}
    .premiero-notices-overview>div.is-hidden:before{background:#646970}
    .premiero-notices-overview>div.is-attention:before{background:#dba617}
    .premiero-notices-overview strong,.premiero-notices-overview span{display:block}
    .premiero-notices-overview strong{margin-bottom:5px;font-size:12px}
    .premiero-notices-overview span{color:#646970;font-size:12px}
    .premiero-notices-guidance{margin:16px 0;padding:14px 16px;border:1px solid #c3d4e8;border-left:4px solid #2271b1;border-radius:7px;background:#f7fbff}
    .premiero-notices-guidance p{margin:5px 0 0;color:#50575e;line-height:1.5}
    .premiero-notices-form{padding:18px;border:1px solid #dcdcde;border-radius:8px;background:#fff;box-shadow:0 2px 7px rgba(0,0,0,.04)}
    .premiero-notices-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:14px}
    .premiero-notices-toolbar input[type=search]{box-sizing:border-box;flex:0 1 320px;width:320px;min-width:220px;max-width:100%}
    .premiero-notices-toolbar select{box-sizing:border-box;flex:0 1 190px;width:190px;min-width:160px;max-width:100%}
    .premiero-notices-toolbar .button{flex:0 0 auto}
    .premiero-notices-table-wrap{width:100%;overflow-x:auto;border:1px solid #dcdcde;border-radius:5px}
    .premiero-notices-table{min-width:940px;border:0!important;margin:0!important}
    .premiero-notices-table th,.premiero-notices-table td{padding:10px 12px;vertical-align:top}
    .premiero-notices-table thead th{white-space:nowrap;background:#f6f7f7;color:#50575e;font-size:11px;text-transform:uppercase;letter-spacing:.03em}
    .premiero-notices-table td:nth-child(2){min-width:320px;max-width:480px}
    .premiero-notices-table td strong,.premiero-notices-table td span,.premiero-notices-table td small{display:block}
    .premiero-notice-message{margin-top:7px}
    .premiero-notice-message summary{width:max-content;max-width:100%;cursor:pointer;color:#2271b1;font-size:12px;font-weight:600}
    .premiero-notice-message span{margin-top:7px;padding:8px 10px;border-left:3px solid #c3c4c7;background:#f6f7f7;color:#50575e;font-size:12px;line-height:1.5}
    .premiero-notices-table td code{overflow-wrap:anywhere}
    .premiero-notices-table td small{margin-top:4px;color:#8c8f94}
    .premiero-notice-type,.premiero-notice-state{display:inline-block!important;width:max-content;padding:4px 8px;border-radius:999px;background:#f0f0f1;color:#50575e;font-size:11px;font-weight:600}
    .premiero-notice-type.is-error{background:#fcf0f1;color:#8a2424}
    .premiero-notice-type.is-warning{background:#fcf9e8;color:#6e4f00}
    .premiero-notice-type.is-success,.premiero-notice-state.is-visible{background:#edfaef;color:#116329}
    .premiero-notice-type.is-info{background:#f0f6fc;color:#135e96}
    .premiero-notice-state.is-hidden{background:#f0f0f1;color:#50575e}
    .premiero-notices-footer-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:14px}
    .premiero-notices-empty{display:flex;flex-direction:column;gap:5px;padding:28px;border:1px dashed #c3c4c7;border-radius:6px;text-align:center;background:#f6f7f7}
    .premiero-notices-empty span{color:#646970}
    .premiero-info-layout{display:grid;grid-template-columns:minmax(340px,1.05fr) minmax(420px,.95fr);gap:28px;align-items:start}
    .premiero-info-brand,.premiero-info-panel{box-sizing:border-box;border:1px solid #dcdcde;border-radius:8px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.05)}
    .premiero-info-brand{padding:36px;border-top:5px solid #6b1c00}
    .premiero-info-wordmark{display:inline-block;text-decoration:none}
    .premiero-info-wordmark img{display:block;width:min(100%,170px);height:auto;max-height:100px;object-fit:contain;object-position:left center}
    .premiero-info-wordmark span{display:block;color:#6b1c00;font-size:36px;font-weight:800;line-height:1}
    .premiero-info-wordmark:hover img,.premiero-info-wordmark:focus img{opacity:.82}
    .premiero-info-eyebrow{margin:5px 0 30px;color:#64748b;font-size:12px;font-weight:700;letter-spacing:.14em;text-transform:uppercase}
    .premiero-info-brand h2{margin:0 0 14px;font-size:26px}
    .premiero-info-lead{max-width:680px;font-size:16px;line-height:1.65}
    .premiero-info-warning{margin-top:24px;padding:14px 16px;border-left:4px solid #6b1c00;background:#fff7f4;color:#3b241d}
    .premiero-info-warning p{margin:0;line-height:1.55}
    .premiero-info-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:24px}
    .premiero-info-layout a:not(.button){color:#6b1c00}
    .premiero-info-layout .button-primary{border-color:#6b1c00;background:#6b1c00}
    .premiero-info-layout .button-primary:hover,.premiero-info-layout .button-primary:focus{border-color:#4a1300;background:#4a1300}
    .premiero-info-details{display:grid;gap:16px}
    .premiero-info-primary{display:grid;gap:16px}
    .premiero-info-panel{padding:22px 24px}
    .premiero-info-panel h2,.premiero-info-panel h3{margin-top:0}
    .premiero-info-tools{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    .premiero-info-tools a{display:block;padding:14px;border:1px solid #dcdcde;border-radius:6px;background:#fff;text-decoration:none}
    .premiero-info-tools a:hover,.premiero-info-tools a:focus{border-color:#6b1c00;box-shadow:0 1px 4px rgba(107,28,0,.14)}
    .premiero-info-tools strong{display:block;margin-bottom:4px;color:#1d2327}
    .premiero-info-tools span{display:block;color:#646970;line-height:1.4}
    .premiero-menu-table{border-collapse:collapse;width:100%;table-layout:auto}
    .premiero-menu-table th,.premiero-menu-table td{padding:8px 10px;border-bottom:1px solid #eee;vertical-align:middle}
    .premiero-menu-table th{background:#fafafa;text-align:left}
    .premiero-label-input{box-sizing:border-box;width:100%;max-width:none}
    .premiero-menu-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:18px 0}
    .premiero-menu-toolbar .search-box{margin:0 auto 0 0}
    .premiero-menu-toolbar input[type=search]{min-width:260px}
    .premiero-menu-count{color:#646970}
    .premiero-sticky-actions{position:sticky;bottom:0;z-index:5;display:flex;align-items:center;justify-content:space-between;gap:12px;margin:18px -24px -24px;padding:12px 24px;background:rgba(255,255,255,.96);border-top:1px solid #dcdcde;box-shadow:0 -2px 8px rgba(0,0,0,.06);backdrop-filter:blur(4px)}
    .premiero-sticky-actions .submit{margin:0;padding:0}
    .premiero-code-stack{display:grid;gap:14px}
    .premiero-code-settings-form{display:grid;gap:14px}
    .premiero-code-panel{box-sizing:border-box;border:1px solid #dcdcde;border-radius:6px;min-width:0;background:#fff}
    .premiero-code-panel summary{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 18px;cursor:pointer;font-size:16px;font-weight:600;background:#f6f7f7;border-radius:6px;list-style:none}
    .premiero-code-panel summary::-webkit-details-marker{display:none}
    .premiero-code-panel summary:after{content:"+";font-size:20px;font-weight:400}
    .premiero-code-panel[open] summary{border-bottom:1px solid #dcdcde;border-radius:6px 6px 0 0}
    .premiero-code-panel[open] summary:after{content:"−"}
    .premiero-code-panel-body{padding:18px}
    .premiero-code-panel form{margin:0}
    .premiero-code-panel textarea{box-sizing:border-box;width:100%;min-height:300px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
    .premiero-code-save{display:flex;align-items:center;justify-content:space-between;gap:12px}
    .premiero-code-save .submit{margin:0;padding:0}
    .CodeMirror{border:1px solid #8c8f94;height:360px}
    .premiero-login-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,.7fr);gap:24px;align-items:start}
    .premiero-login-preview{position:sticky;top:46px;border:1px solid #dcdcde;border-radius:8px;padding:28px;background:#f0f0f1;text-align:center;min-height:360px;box-sizing:border-box}
    .premiero-login-preview-logo{display:flex;align-items:center;justify-content:center;min-height:90px;margin:18px auto}
    .premiero-login-preview-logo img{display:block;max-width:100%;height:auto}
    .premiero-login-preview-box{max-width:320px;margin:0 auto;padding:24px;background:#fff;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.13)}
    .premiero-login-preview-credit{margin-top:18px;color:#50575e}
    .premiero-branding-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,.75fr);gap:24px;align-items:start}
    .premiero-branding-preview{position:sticky;top:46px;border:1px solid #dcdcde;border-radius:8px;padding:24px;background:#f6f7f7;box-sizing:border-box}
    .premiero-branding-preview.is-disabled{opacity:.58}
    .premiero-branding-preview-bar{display:flex;align-items:center;gap:14px;margin:16px 0;padding:18px;background:#1d2327;color:#fff;border-radius:6px}
    .premiero-branding-preview-bar img{display:block;width:72px;max-height:42px;object-fit:contain;object-position:left center}
    .premiero-branding-logo-preview img{display:block;max-width:240px;max-height:90px;width:auto;height:auto;margin:0 0 12px}
    .premiero-branding-status{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:#f0f0f1;font-weight:600}
    .premiero-branding-status.is-active{background:#edfaef;color:#116329}
    @media (max-width:1100px){
        .premiero-info-layout{grid-template-columns:1fr}
        .premiero-notices-console{max-width:none}
        .premiero-notices-overview{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
        .premiero-notices-form{padding:16px}
        .premiero-notices-table-wrap{overflow:visible;border:0;background:transparent}
        .premiero-notices-table{min-width:0;border:0!important;background:transparent;box-shadow:none}
        .premiero-notices-table thead{display:none}
        .premiero-notices-table tbody{display:grid;gap:12px;width:100%}
        .premiero-notices-table tr{position:relative;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px 20px;box-sizing:border-box;width:100%;padding:16px 16px 16px 50px;border:1px solid #dcdcde;border-radius:7px;background:#fff!important;box-shadow:0 1px 3px rgba(0,0,0,.04)}
        .premiero-notices-table th,.premiero-notices-table td{display:block;box-sizing:border-box;width:100%}
        .premiero-notices-table th.check-column{position:absolute;top:17px;left:16px;width:24px;padding:0}
        .premiero-notices-table td{padding:6px 0;border:0;overflow-wrap:anywhere}
        .premiero-notices-table td:nth-child(2),.premiero-notices-table td[data-label="Aviso"]{grid-column:1/-1;min-width:0;max-width:none;padding-top:0;padding-bottom:12px;border-bottom:1px solid #f0f0f1}
        .premiero-notices-table td:before{display:block;margin-bottom:3px;color:#8c8f94;content:attr(data-label);font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
    }
    @media (max-width:782px){
        .premiero-head{align-items:flex-start!important;gap:16px;padding-right:12px!important}
        .premiero-head img{height:30px!important}
        .nav-tab-wrapper{gap:4px;padding-right:10px!important}
        .nav-tab-wrapper .nav-tab{padding:7px 10px;font-size:13px}
        .premiero-card{padding:16px;margin-right:10px}
        .premiero-overview{grid-template-columns:1fr}
        .premiero-remote-backups{padding-right:10px}
        .premiero-remote-header{align-items:flex-start;gap:12px;margin-bottom:16px}
        .premiero-remote-header h2{font-size:20px}
        .premiero-remote-status{padding:6px 9px;font-size:11px}
        .premiero-remote-daily{display:block;padding:18px 16px}
        .premiero-remote-daily h3{font-size:17px}
        .premiero-remote-quick-form{align-items:flex-start;margin-top:16px}
        .premiero-remote-quick-form .button-hero{width:100%}
        .premiero-remote-backups .premiero-overview{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:18px}
        .premiero-remote-backups .premiero-overview a{padding:12px}
        .premiero-remote-queue{padding:16px;margin-right:0!important}
        .premiero-remote-table-wrap{margin:0 -1px;width:calc(100% + 2px)}
        .premiero-remote-settings-panel{margin-right:0}
        .premiero-remote-settings-body{padding:16px}
        .premiero-remote-settings-form .form-table,.premiero-remote-settings-form .form-table tbody,.premiero-remote-settings-form .form-table tr,.premiero-remote-settings-form .form-table th,.premiero-remote-settings-form .form-table td{display:block;width:100%;box-sizing:border-box}
        .premiero-remote-settings-form .form-table tr{padding:10px 0;border-bottom:1px solid #f0f0f1}
        .premiero-remote-settings-form .form-table th{padding:0 0 5px}
        .premiero-remote-settings-form .form-table td{padding:0}
        .premiero-remote-settings-form .regular-text{max-width:100%;width:100%}
        .premiero-remote-settings-actions{align-items:stretch;flex-direction:column}
        .premiero-remote-settings-actions .button{width:100%;text-align:center}
        .premiero-remote-settings-actions .description{margin:2px 0 0}
        .premiero-remote-fingerprint{grid-template-columns:1fr;gap:8px}
        .premiero-remote-fingerprint .button{width:100%;text-align:center}
        .premiero-notices-console{padding-right:10px}
        .premiero-notices-overview{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
        .premiero-notices-form{padding:14px}
        .premiero-notices-toolbar{align-items:stretch;flex-direction:column}
        .premiero-notices-toolbar input[type=search],.premiero-notices-toolbar select,.premiero-notices-toolbar .button{box-sizing:border-box;flex:0 0 auto;width:100%;min-width:0;max-width:none}
        .premiero-notices-toolbar input[type=search],.premiero-notices-toolbar select{text-align:left}
        .premiero-notices-toolbar .button{text-align:center}
        .premiero-notices-table tr{grid-template-columns:1fr;gap:10px;padding:14px 12px 14px 44px}
        .premiero-notices-table th.check-column{top:15px;left:12px}
        .premiero-notices-table td{grid-column:1/-1}
        .premiero-notices-footer-actions{align-items:stretch;flex-direction:column}
        .premiero-notices-footer-actions .button{width:100%;text-align:center}
        .premiero-info-layout{grid-template-columns:1fr}
        .premiero-info-brand{padding:26px}
        .premiero-info-tools{grid-template-columns:1fr}
        .premiero-menu-toolbar{align-items:stretch}
        .premiero-menu-toolbar .search-box{width:100%}
        .premiero-menu-toolbar input[type=search]{box-sizing:border-box;width:100%;min-width:0}
        .premiero-sticky-actions{margin:18px -16px -16px;padding:10px 16px}
        .premiero-code-panel summary{padding:14px}
        .premiero-code-panel-body{padding:14px}
        .premiero-code-panel textarea{min-height:220px}
        .CodeMirror{height:280px}
        .premiero-login-layout{grid-template-columns:1fr}
        .premiero-login-preview{position:static;min-height:300px}
        .premiero-branding-layout{grid-template-columns:1fr}
        .premiero-branding-preview{position:static}
        .premiero-menu-table,.premiero-menu-table tbody,.premiero-menu-table tr,.premiero-menu-table td{display:block;width:100%;box-sizing:border-box}
        .premiero-menu-table thead{display:none}
        .premiero-menu-table tr{margin-bottom:12px;border:1px solid #dcdcde;border-radius:6px;padding:8px;background:#fff}
        .premiero-menu-table td{display:grid;grid-template-columns:minmax(110px,35%) minmax(0,1fr);gap:10px;align-items:center;padding:8px;border:0}
        .premiero-menu-table td:before{content:attr(data-label);font-weight:600;color:#50575e}
        .premiero-menu-table code{overflow-wrap:anywhere}
    }
    @media (max-width:480px){
        .premiero-notices-console{padding-right:8px}
        .premiero-notices-overview{grid-template-columns:1fr}
        .premiero-notices-guidance{padding:12px}
        .premiero-notices-form{padding:10px}
        .premiero-notices-table tr{padding:13px 10px 13px 40px}
        .premiero-notices-table th.check-column{top:14px;left:10px}
        .premiero-notice-message span{padding:7px 8px}
    }
    </style>';
    if ( in_array( $tab, [ 'adminui', 'branding' ], true ) ) {
        wp_enqueue_media();
    }
});

/* ====================== Render: Ajustes ====================== */
function premiero_render_settings_page() {
    if ( ! current_user_can('manage_options') ) return;

    $active = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'info';
    if ( in_array($active, ['php', 'head', 'body', 'css'], true) ) {
        $active = 'code';
    }

    if ( 'branding' === $active && isset($_GET['branding-status']) ) {
        $branding_status = sanitize_key($_GET['branding-status']);
        if ( 'updated' === $branding_status ) {
            echo '<div class="notice notice-success is-dismissible"><p>Configuración de identidad guardada.</p></div>';
        } elseif ( 'missing-name' === $branding_status ) {
            echo '<div class="notice notice-error"><p>Introduce el nombre de la organización antes de activar la identidad personalizada.</p></div>';
        } elseif ( 'mu-warning' === $branding_status ) {
            echo '<div class="notice notice-warning"><p>La identidad se guardó, pero no se pudo actualizar el nombre visible del MU-plugin de snippets.</p></div>';
        }
    }

    /* Guardado de snippets MU */
    if ( 'code' === $active && isset($_POST['premiero_snippets_submit']) && check_admin_referer('premiero_snippets_nonce') ) {
        $code = wp_unslash( $_POST[PREMIERO_OPT_SNIPPETS] ?? '' );
        $result = premiero_write_mu_snippets($code);
        if ( ! is_wp_error($result) ) {
            update_option(PREMIERO_OPT_SNIPPETS, $code);
        }
        echo is_wp_error($result)
            ? '<div class="notice notice-error"><p><strong>Error:</strong> '.esc_html($result->get_error_message()).'</p></div>'
            : '<div class="notice notice-success is-dismissible"><p>Snippets guardados en <code>wp-content/mu-plugins/premiero-snippets.php</code>.</p></div>';
    }

    /* Guardado simple Login */
    if ( 'adminui' === $active && isset($_POST['premiero_adminui_submit']) && check_admin_referer('premiero_adminui_nonce') ) {
        update_option(PREMIERO_OPT_LOGIN_BG,     sanitize_text_field($_POST[PREMIERO_OPT_LOGIN_BG] ?? ''));
        update_option(PREMIERO_OPT_LOGIN_CREDIT, isset($_POST[PREMIERO_OPT_LOGIN_CREDIT]) ? 1 : 0);
        update_option(PREMIERO_OPT_LOGIN_LOGO_ID, intval($_POST[PREMIERO_OPT_LOGIN_LOGO_ID] ?? 0));
        $w = max(50, intval($_POST[PREMIERO_OPT_LOGIN_LOGO_W] ?? 260));
        update_option(PREMIERO_OPT_LOGIN_LOGO_W, $w);
        echo '<div class="notice notice-success is-dismissible"><p>Cambios guardados.</p></div>';
    }

    /* Guardado Menú WP */
    if ( 'menuwp' === $active && isset($_POST['premiero_menuwp_submit']) && check_admin_referer('premiero_menuwp_nonce') ) {
        // Slugs marcados
        $group = isset($_POST[PREMIERO_OPT_MENU_GROUP]) ? (array) $_POST[PREMIERO_OPT_MENU_GROUP] : [];
        $group = array_values(array_unique(array_map('sanitize_text_field', $group)));
        $group = array_filter($group, function($slug){ return $slug && $slug !== PREMIERO_ATK_SLUG; });
        update_option(PREMIERO_OPT_MENU_GROUP, $group);

        // Labels
        $labels = isset($_POST[PREMIERO_OPT_MENU_LABELS]) ? (array) $_POST[PREMIERO_OPT_MENU_LABELS] : [];
        $clean  = [];
        foreach ($labels as $slug => $label) {
            $slug  = sanitize_text_field($slug);
            $label = wp_kses_post($label);
            if ($slug) $clean[$slug] = $label;
        }
        update_option(PREMIERO_OPT_MENU_LABELS, $clean);

        echo '<div class="notice notice-success is-dismissible"><p>Menú actualizado.</p></div>';
    }

    premiero_admin_header($active);
    premiero_tabs_nav($active);

    echo '<div class="premiero-card">';

    switch ($active) {

        case 'info':
            $info_brand_name  = premiero_get_brand_name();
            $info_toolkit_name = premiero_get_toolkit_name();
            $info_logo        = premiero_get_brand_logo_url( 'full' );
            ?>
            <div class="premiero-info-layout">
                <div class="premiero-info-primary">
                    <section class="premiero-info-brand">
                        <?php if ( $info_logo ) : ?>
                            <?php if ( premiero_is_white_label() ) : ?>
                                <span class="premiero-info-wordmark">
                                    <img src="<?php echo esc_url( $info_logo ); ?>" alt="<?php echo esc_attr( $info_brand_name ); ?>">
                                </span>
                            <?php else : ?>
                                <a class="premiero-info-wordmark" href="https://premiero.es" target="_blank" rel="noopener">
                                    <img src="<?php echo esc_url( $info_logo ); ?>" alt="Premiero">
                                </a>
                            <?php endif; ?>
                        <?php else : ?>
                            <a class="premiero-info-wordmark" href="https://premiero.es" target="_blank" rel="noopener">
                                <span><?php echo esc_html( $info_brand_name ); ?></span>
                            </a>
                        <?php endif; ?>

                        <p class="premiero-info-eyebrow">Administración WordPress</p>
                        <h2><?php echo esc_html( $info_toolkit_name ); ?></h2>
                        <p class="premiero-info-lead">
                            <?php if ( premiero_is_white_label() ) : ?>
                                Plugin desarrollado por <strong>Premiero para <?php echo esc_html( $info_brand_name ); ?></strong> para centralizar herramientas habituales de administración y personalización.
                            <?php else : ?>
                                Plugin desarrollado por <strong>Premiero</strong> para centralizar herramientas habituales de administración y personalización.
                            <?php endif; ?>
                        </p>

                        <div class="premiero-info-warning">
                            <p>Este plugin está orientado a desarrolladores y tareas de administración de WordPress. <strong>No se recomienda realizar cambios si no se tiene claro lo que se está haciendo.</strong></p>
                        </div>

                        <div class="premiero-info-actions">
                            <a class="button button-primary" href="https://premiero.es" target="_blank" rel="noopener">Visitar premiero.es</a>
                        </div>
                    </section>

                    <section class="premiero-info-panel">
                        <h2>Acceso rápido</h2>
                        <div class="premiero-info-tools">
                            <a href="<?php echo esc_url(admin_url('admin.php?page='.PREMIERO_ATK_SLUG.'&tab=code')); ?>">
                                <strong>Código</strong><span>PHP, HTML y CSS personalizado.</span>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page='.PREMIERO_ATK_SLUG.'&tab=menuwp')); ?>">
                                <strong>Menú</strong><span>Organización del panel de WordPress.</span>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page='.PREMIERO_ATK_SLUG.'&tab=repository')); ?>">
                                <strong>Repositorio</strong><span>Plugins y temas disponibles.</span>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page='.PREMIERO_ATK_SLUG.'&tab=adminui')); ?>">
                                <strong>Login</strong><span>Logo, fondo y crédito.</span>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page='.PREMIERO_ATK_SLUG.'&tab=branding')); ?>">
                                <strong>Identidad</strong><span>Nombre y logo para clientes.</span>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page='.PREMIERO_ATK_SLUG.'&tab=monitoring')); ?>">
                                <strong>Monitorización</strong><span>Conexión de solo lectura con la consola.</span>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page='.PREMIERO_ATK_SLUG.'&tab=remote-backups')); ?>">
                                <strong>Copias de Seguridad</strong><span>Sincronización SFTP con cualquier servidor compatible.</span>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page='.PREMIERO_ATK_SLUG.'&tab=notices')); ?>">
                                <strong>Avisos</strong><span>Registro y control de avisos del administrador.</span>
                            </a>
                        </div>
                    </section>
                </div>

                <div class="premiero-info-details">
                    <section class="premiero-info-panel">
                        <h2>Proyecto abierto</h2>
                        <p>El código se distribuye bajo licencia GPL v3 o posterior. Puedes estudiarlo, modificarlo y redistribuirlo respetando la licencia y los avisos de autoría.</p>
                        <a href="<?php echo esc_url( PREMIERO_ATK_UPDATE_URI ); ?>" target="_blank" rel="noopener">Ver repositorio en GitHub</a>
                    </section>

                    <section class="premiero-info-panel">
                        <h2>Actualizaciones</h2>
                        <p>Las versiones estables se reciben desde GitHub Releases mediante el actualizador normal de WordPress.</p>
                        <p><strong>Versión instalada:</strong> <?php echo esc_html( PREMIERO_ATK_VER ); ?></p>
                    </section>

                    <section class="premiero-info-panel">
                        <h2>Soporte</h2>
                        <?php premiero_render_support_inner(); ?>
                    </section>
                </div>
            </div>
        <?php break;

        case 'code':
            $editor_settings = $GLOBALS['premiero_code_editor_settings'] ?? [];
            ?>
            <div class="premiero-code-stack">
                <details class="premiero-code-panel" open>
                    <summary>Snippets PHP</summary>
                    <div class="premiero-code-panel-body">
                        <div class="notice notice-warning inline"><p><strong>Atención:</strong> El código se guarda como <code>MU-plugin</code>. Prueba primero en staging.</p></div>
                        <form method="post" class="premiero-watch-form">
                            <?php wp_nonce_field('premiero_snippets_nonce'); ?>
                            <p><label for="<?php echo PREMIERO_OPT_SNIPPETS; ?>"><strong>PHP personalizado</strong></label></p>
                            <textarea name="<?php echo PREMIERO_OPT_SNIPPETS; ?>" id="<?php echo PREMIERO_OPT_SNIPPETS; ?>" rows="18"><?php echo esc_textarea( get_option(PREMIERO_OPT_SNIPPETS, '') ); ?></textarea>
                            <?php submit_button('Guardar snippets', 'primary', 'premiero_snippets_submit'); ?>
                        </form>
                    </div>
                </details>

                <form method="post" action="options.php" class="premiero-code-settings-form premiero-watch-form">
                    <?php settings_fields('premiero_code_settings_group'); ?>
                    <details class="premiero-code-panel">
                        <summary>HTML en &lt;head&gt;</summary>
                        <div class="premiero-code-panel-body">
                            <p><label for="<?php echo PREMIERO_OPT_HEAD_HTML; ?>"><strong>Contenido HTML insertado antes de cerrar &lt;head&gt;</strong></label></p>
                            <textarea name="<?php echo PREMIERO_OPT_HEAD_HTML; ?>" id="<?php echo PREMIERO_OPT_HEAD_HTML; ?>" rows="12"><?php echo esc_textarea( get_option(PREMIERO_OPT_HEAD_HTML, '') ); ?></textarea>
                        </div>
                    </details>

                    <details class="premiero-code-panel">
                        <summary>HTML al inicio de &lt;body&gt;</summary>
                        <div class="premiero-code-panel-body">
                            <p><label for="<?php echo PREMIERO_OPT_BODY_HTML; ?>"><strong>Contenido insertado después de abrir &lt;body&gt;</strong></label></p>
                            <textarea name="<?php echo PREMIERO_OPT_BODY_HTML; ?>" id="<?php echo PREMIERO_OPT_BODY_HTML; ?>" rows="12"><?php echo esc_textarea( get_option(PREMIERO_OPT_BODY_HTML, '') ); ?></textarea>
                        </div>
                    </details>

                    <details class="premiero-code-panel">
                        <summary>CSS personalizado</summary>
                        <div class="premiero-code-panel-body">
                            <p><label for="<?php echo PREMIERO_OPT_CSS; ?>"><strong>Estilos cargados en el frontend</strong></label></p>
                            <textarea name="<?php echo PREMIERO_OPT_CSS; ?>" id="<?php echo PREMIERO_OPT_CSS; ?>" rows="14"><?php echo esc_textarea( get_option(PREMIERO_OPT_CSS, '') ); ?></textarea>
                        </div>
                    </details>

                    <div class="premiero-code-save">
                        <span class="description">Guarda conjuntamente el HTML y CSS para mantener todos los valores.</span>
                        <?php submit_button('Guardar HTML y CSS'); ?>
                    </div>
                </form>
            </div>
            <script>
            (function(){
                var dirty = false;
                var settings = <?php echo wp_json_encode($editor_settings); ?>;
                var editors = [
                    ['<?php echo esc_js(PREMIERO_OPT_SNIPPETS); ?>', 'php'],
                    ['<?php echo esc_js(PREMIERO_OPT_HEAD_HTML); ?>', 'html'],
                    ['<?php echo esc_js(PREMIERO_OPT_BODY_HTML); ?>', 'html'],
                    ['<?php echo esc_js(PREMIERO_OPT_CSS); ?>', 'css']
                ];

                function markDirty(){ dirty = true; }
                function initializeEditor(item){
                    var field = document.getElementById(item[0]);
                    if ( ! field || field.dataset.editorReady === '1' ) return;
                    var panel = field.closest('details');
                    if ( panel && ! panel.open ) return;
                    field.dataset.editorReady = '1';
                    if ( window.wp && wp.codeEditor && settings[item[1]] ) {
                        var instance = wp.codeEditor.initialize(field, settings[item[1]]);
                        if ( instance && instance.codemirror ) {
                            instance.codemirror.on('change', markDirty);
                        }
                    }
                    field.addEventListener('input', markDirty);
                }

                editors.forEach(function(item){
                    initializeEditor(item);
                    var field = document.getElementById(item[0]);
                    var panel = field ? field.closest('details') : null;
                    if ( panel ) {
                        panel.addEventListener('toggle', function(){
                            if ( panel.open ) initializeEditor(item);
                        });
                    }
                });

                document.querySelectorAll('.premiero-watch-form').forEach(function(form){
                    form.addEventListener('change', markDirty);
                    form.addEventListener('submit', function(){ dirty = false; });
                });
                window.addEventListener('beforeunload', function(event){
                    if ( ! dirty ) return;
                    event.preventDefault();
                    event.returnValue = '';
                });
            })();
            </script>
            <?php
        break;

        case 'menuwp':
            $brand_name = premiero_get_brand_name();
            // Construir lista de top-level actuales
            global $menu;
            $items = [];
            if ( is_array($menu) ) {
                foreach ( $menu as $m ) {
                    if ( empty($m[2]) ) continue;
                    $slug  = (string) $m[2];
                    $title = wp_strip_all_tags($m[0]);
                    $items[$slug] = $title ?: $slug;
                }
            }
            // Opción actual
            $selected = (array) get_option(PREMIERO_OPT_MENU_GROUP, []);
            $labels   = (array) get_option(PREMIERO_OPT_MENU_LABELS, []);

            // Asegura que aparezcan también los ya agrupados
            foreach ($selected as $slug) {
                if ( ! isset($items[$slug]) ) {
                    $items[$slug] = isset($labels[$slug]) && $labels[$slug] !== '' ? wp_strip_all_tags($labels[$slug]) : $slug;
                }
            }

            ksort($items);
            ?>
            <form method="post">
                <?php wp_nonce_field('premiero_menuwp_nonce'); ?>
                <p><strong>Menú</strong> — Marca los elementos que quieras <em>agrupar bajo <?php echo esc_html($brand_name); ?></em>. Se añadirá un separador visual entre Ajustes y los plugins agrupados. Puedes <strong>renombrar</strong> los elementos (aplica tanto dentro de <?php echo esc_html($brand_name); ?> como fuera, si no están agrupados).</p>
                <div class="premiero-menu-toolbar">
                    <p class="search-box">
                        <label class="screen-reader-text" for="premiero-menu-search">Buscar elementos</label>
                        <input type="search" id="premiero-menu-search" placeholder="Buscar por nombre o slug">
                    </p>
                    <button type="button" class="button premiero-menu-filter button-primary" data-filter="all">Todos</button>
                    <button type="button" class="button premiero-menu-filter" data-filter="grouped">Agrupados</button>
                    <button type="button" class="button premiero-menu-filter" data-filter="ungrouped">Sin agrupar</button>
                    <button type="button" class="button" id="premiero-menu-reset-labels">Restaurar nombres</button>
                    <span class="premiero-menu-count" aria-live="polite"></span>
                </div>
                <table class="premiero-menu-table">
                    <thead><tr><th>Elemento</th><th>Slug</th><th>En <?php echo esc_html($brand_name); ?></th><th>Nombre personalizado</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $slug=>$title):
                        $is_grouped = in_array($slug, $selected, true);
                    ?>
                        <tr data-search="<?php echo esc_attr(strtolower($title.' '.$slug)); ?>" data-grouped="<?php echo $is_grouped ? '1' : '0'; ?>">
                            <td data-label="Elemento"><?php echo esc_html($title); ?></td>
                            <td data-label="Slug"><code><?php echo esc_html($slug); ?></code></td>
                            <td data-label="En <?php echo esc_attr($brand_name); ?>">
                                <?php if ($slug === PREMIERO_ATK_SLUG): ?>
                                    <em>(Siempre en <?php echo esc_html($brand_name); ?>)</em>
                                <?php else: ?>
                                    <label>
                                        <input type="checkbox" name="<?php echo PREMIERO_OPT_MENU_GROUP; ?>[]"
                                               value="<?php echo esc_attr($slug); ?>"
                                            <?php checked( $is_grouped ); ?>>
                                        Agrupar
                                    </label>
                                <?php endif; ?>
                            </td>
                            <td data-label="Nombre">
                                <input class="premiero-label-input" type="text"
                                       name="<?php echo PREMIERO_OPT_MENU_LABELS; ?>[<?php echo esc_attr($slug); ?>]"
                                       value="<?php echo esc_attr( $labels[$slug] ?? '' ); ?>"
                                       placeholder="(Opcional) Nombre personalizado">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="premiero-sticky-actions">
                    <span class="description">Los cambios se aplicarán al guardar.</span>
                    <?php submit_button('Guardar cambios','primary','premiero_menuwp_submit'); ?>
                </div>
            </form>
            <script>
            (function(){
                var search = document.getElementById('premiero-menu-search');
                var rows = Array.prototype.slice.call(document.querySelectorAll('.premiero-menu-table tbody tr'));
                var filters = document.querySelectorAll('.premiero-menu-filter');
                var count = document.querySelector('.premiero-menu-count');
                var currentFilter = 'all';

                function refresh(){
                    var term = (search.value || '').toLowerCase().trim();
                    var visible = 0;
                    var grouped = 0;
                    rows.forEach(function(row){
                        var checkbox = row.querySelector('input[type="checkbox"]');
                        var isGrouped = checkbox ? checkbox.checked : row.dataset.grouped === '1';
                        row.dataset.grouped = isGrouped ? '1' : '0';
                        if ( isGrouped ) grouped++;
                        var filterMatch = currentFilter === 'all'
                            || (currentFilter === 'grouped' && isGrouped)
                            || (currentFilter === 'ungrouped' && !isGrouped);
                        var show = filterMatch && (!term || row.dataset.search.indexOf(term) !== -1);
                        row.style.display = show ? '' : 'none';
                        if ( show ) visible++;
                    });
                    count.textContent = visible + ' visibles · ' + grouped + ' agrupados';
                }

                search.addEventListener('input', refresh);
                filters.forEach(function(button){
                    button.addEventListener('click', function(){
                        currentFilter = button.dataset.filter;
                        filters.forEach(function(item){ item.classList.remove('button-primary'); });
                        button.classList.add('button-primary');
                        refresh();
                    });
                });
                rows.forEach(function(row){
                    var checkbox = row.querySelector('input[type="checkbox"]');
                    if ( checkbox ) checkbox.addEventListener('change', refresh);
                });
                document.getElementById('premiero-menu-reset-labels').addEventListener('click', function(){
                    document.querySelectorAll('.premiero-label-input').forEach(function(input){ input.value = ''; });
                });
                refresh();
            })();
            </script>
            <?php
        break;

        case 'branding':
            $white_label_enabled = (bool) get_option(PREMIERO_OPT_WHITE_LABEL_ENABLED, false);
            $white_label_name    = sanitize_text_field( get_option(PREMIERO_OPT_WHITE_LABEL_NAME, '') );
            $white_label_logo_id = (int) get_option(PREMIERO_OPT_WHITE_LABEL_LOGO_ID, 0);
            $white_label_logo    = $white_label_logo_id ? wp_get_attachment_image_src($white_label_logo_id, 'medium') : false;
            ?>
            <div class="premiero-branding-layout">
                <form method="post">
                    <?php wp_nonce_field('premiero_branding_nonce'); ?>
                    <h2>Identidad del cliente</h2>
                    <p>Activa esta opción cuando instales el plugin para un cliente externo. La configuración se guarda en WordPress y se mantiene después de actualizar el plugin.</p>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Identidad personalizada</th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           name="<?php echo PREMIERO_OPT_WHITE_LABEL_ENABLED; ?>"
                                           id="<?php echo PREMIERO_OPT_WHITE_LABEL_ENABLED; ?>"
                                           value="1"
                                           <?php checked($white_label_enabled); ?>>
                                    Usar identidad personalizada
                                </label>
                                <p class="description">Al desactivarla, el plugin vuelve a mostrar la identidad de Premiero sin borrar los datos del cliente.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="<?php echo PREMIERO_OPT_WHITE_LABEL_NAME; ?>">Nombre de la organización</label></th>
                            <td>
                                <input type="text"
                                       class="regular-text"
                                       name="<?php echo PREMIERO_OPT_WHITE_LABEL_NAME; ?>"
                                       id="<?php echo PREMIERO_OPT_WHITE_LABEL_NAME; ?>"
                                       value="<?php echo esc_attr($white_label_name); ?>"
                                       placeholder="Ej. TecnoDerecho">
                                <p class="description">Se utilizará en el menú, la cabecera, los textos, el login y la ficha del plugin.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Logo de la marca</th>
                            <td>
                                <div class="premiero-branding-logo-preview" id="premiero-branding-logo-preview">
                                    <?php if ( $white_label_logo ): ?>
                                        <img src="<?php echo esc_url($white_label_logo[0]); ?>" alt="">
                                    <?php endif; ?>
                                </div>
                                <input type="hidden"
                                       name="<?php echo PREMIERO_OPT_WHITE_LABEL_LOGO_ID; ?>"
                                       id="<?php echo PREMIERO_OPT_WHITE_LABEL_LOGO_ID; ?>"
                                       value="<?php echo esc_attr($white_label_logo_id); ?>">
                                <button type="button" class="button" id="premiero-branding-logo-select">Seleccionar logo</button>
                                <button type="button" class="button" id="premiero-branding-logo-clear">Quitar</button>
                                <p class="description">Recomendado: PNG horizontal con fondo transparente.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Guardar identidad', 'primary', 'premiero_branding_submit'); ?>
                </form>

                <aside class="premiero-branding-preview<?php echo $white_label_enabled ? '' : ' is-disabled'; ?>" id="premiero-branding-preview">
                    <span class="premiero-branding-status<?php echo $white_label_enabled ? ' is-active' : ''; ?>" id="premiero-branding-status">
                        <?php echo $white_label_enabled ? 'Identidad personalizada activa' : 'Identidad personalizada desactivada'; ?>
                    </span>
                    <h3>Vista previa</h3>
                    <div class="premiero-branding-preview-bar">
                        <img id="premiero-branding-preview-image"
                             src="<?php echo $white_label_logo ? esc_url($white_label_logo[0]) : ''; ?>"
                             alt=""
                             <?php echo $white_label_logo ? '' : 'style="display:none"'; ?>>
                        <strong id="premiero-branding-preview-name"><?php echo esc_html($white_label_name ?: 'Nombre del cliente'); ?></strong>
                    </div>
                    <p><strong id="premiero-branding-preview-plugin"><?php echo esc_html(($white_label_name ?: 'Nombre del cliente') . ' Admin Toolkit'); ?></strong></p>
                    <p class="description">Desarrollado por Premiero para <span id="premiero-branding-preview-credit"><?php echo esc_html($white_label_name ?: 'el cliente'); ?></span>.</p>
                </aside>
            </div>
            <script>
            jQuery(function($){
                var frame;
                var enabled = $('#<?php echo PREMIERO_OPT_WHITE_LABEL_ENABLED; ?>');
                var name = $('#<?php echo PREMIERO_OPT_WHITE_LABEL_NAME; ?>');
                var preview = $('#premiero-branding-preview');
                var status = $('#premiero-branding-status');
                var image = $('#premiero-branding-preview-image');

                function refreshBrandingPreview(){
                    var active = enabled.is(':checked');
                    var brand = $.trim(name.val()) || 'Nombre del cliente';
                    preview.toggleClass('is-disabled', !active);
                    status.toggleClass('is-active', active).text(active ? 'Identidad personalizada activa' : 'Identidad personalizada desactivada');
                    $('#premiero-branding-preview-name').text(brand);
                    $('#premiero-branding-preview-plugin').text(brand + ' Admin Toolkit');
                    $('#premiero-branding-preview-credit').text(brand === 'Nombre del cliente' ? 'el cliente' : brand);
                }

                enabled.on('change', refreshBrandingPreview);
                name.on('input', refreshBrandingPreview);

                $('#premiero-branding-logo-select').on('click', function(event){
                    event.preventDefault();
                    if ( ! window.wp || ! wp.media ) {
                        window.alert('No se pudo cargar la biblioteca multimedia de WordPress. Recarga la página e inténtalo de nuevo.');
                        return;
                    }
                    if ( frame ) {
                        frame.open();
                        return;
                    }
                    frame = wp.media({
                        title: 'Selecciona el logo de la marca',
                        button: { text: 'Usar este logo' },
                        library: { type: 'image' },
                        multiple: false
                    });
                    frame.on('select', function(){
                        var attachment = frame.state().get('selection').first().toJSON();
                        $('#<?php echo PREMIERO_OPT_WHITE_LABEL_LOGO_ID; ?>').val(attachment.id);
                        $('#premiero-branding-logo-preview').html('<img src="' + attachment.url + '" alt="">');
                        image.attr('src', attachment.url).show();
                    });
                    frame.open();
                });

                $('#premiero-branding-logo-clear').on('click', function(event){
                    event.preventDefault();
                    $('#<?php echo PREMIERO_OPT_WHITE_LABEL_LOGO_ID; ?>').val('0');
                    $('#premiero-branding-logo-preview').empty();
                    image.attr('src', '').hide();
                });

                refreshBrandingPreview();
            });
            </script>
        <?php break;

        case 'repository':
            premiero_render_repository();
        break;

        case 'monitoring':
            Premiero_Console_Client::render_tab();
        break;

        case 'remote-backups':
            Premiero_Remote_Backup_Settings::render_tab();
        break;

        case 'notices':
            Premiero_Admin_Notices::render_tab();
        break;

        /* ====================== PESTAÑA ADMIN UI ====================== */
        case 'adminui':
            $brand_name   = premiero_get_brand_name();
            $logo_id      = (int) get_option(PREMIERO_OPT_LOGIN_LOGO_ID, 0);
            $src          = $logo_id ? wp_get_attachment_image_src($logo_id,'medium') : false;
            $preview_logo = $src ? $src[0] : premiero_get_login_logo_url();
            $login_bg     = trim((string) get_option(PREMIERO_OPT_LOGIN_BG, ''));
            $login_w      = max(50, (int) get_option(PREMIERO_OPT_LOGIN_LOGO_W, 260));
            $show_credit  = (bool) get_option(PREMIERO_OPT_LOGIN_CREDIT, true);
            ?>
            <div class="premiero-login-layout">
            <form method="post">
                <?php wp_nonce_field('premiero_adminui_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo PREMIERO_OPT_LOGIN_BG; ?>">Color de fondo (solo login)</label></th>
                        <td>
                            <input type="text" name="<?php echo PREMIERO_OPT_LOGIN_BG; ?>" id="<?php echo PREMIERO_OPT_LOGIN_BG; ?>"
                                   value="<?php echo esc_attr($login_bg); ?>"
                                   placeholder="#RRGGBB o #RGB" class="regular-text premiero-color-field">
                            <p class="description">Introduce un color hexadecimal (ej. <code>#999999</code>). No afecta a <code>wp-admin</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Logo de la pantalla de login</th>
                        <td>
                            <div id="premiero-login-logo-preview" style="margin-bottom:10px;">
                                <?php if($src){ echo '<img src="'.esc_url($src[0]).'" style="max-width:220px;height:auto;">'; } ?>
                            </div>
                            <input type="hidden" name="<?php echo PREMIERO_OPT_LOGIN_LOGO_ID; ?>" id="<?php echo PREMIERO_OPT_LOGIN_LOGO_ID; ?>" value="<?php echo esc_attr($logo_id); ?>">
                            <button type="button" class="button" id="premiero-login-logo-select">Seleccionar logo</button>
                            <button type="button" class="button" id="premiero-login-logo-clear">Quitar</button>
                            <p class="description">
                                <?php if ( premiero_is_white_label() ): ?>
                                    Si no seleccionas nada, se usará el logo configurado para <?php echo esc_html($brand_name); ?> o el logo del sitio.
                                <?php else: ?>
                                    Si no seleccionas nada, se usará el logo del sitio (o el de Premiero como último recurso).
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo PREMIERO_OPT_LOGIN_LOGO_W; ?>">Ancho del logo (px)</label></th>
                        <td>
                            <input type="number" min="50" step="10" name="<?php echo PREMIERO_OPT_LOGIN_LOGO_W; ?>" id="<?php echo PREMIERO_OPT_LOGIN_LOGO_W; ?>"
                                   value="<?php echo esc_attr($login_w); ?>">
                            <p class="description">La altura se ajusta automáticamente. Recomendado: entre 180 y 320 px.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Crédito en login</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo PREMIERO_OPT_LOGIN_CREDIT; ?>" value="1" <?php checked($show_credit); ?>>
                                Mostrar "Desarrollado por <?php echo esc_html($brand_name); ?>" debajo del selector de idioma.
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Guardar cambios','primary','premiero_adminui_submit'); ?>
            </form>
            <aside class="premiero-login-preview" id="premiero-login-live-preview" style="background:<?php echo esc_attr($login_bg ?: '#f0f0f1'); ?>">
                <strong>Vista previa</strong>
                <div class="premiero-login-preview-logo">
                    <img src="<?php echo esc_url($preview_logo); ?>" alt="" style="width:<?php echo esc_attr($login_w); ?>px">
                </div>
                <div class="premiero-login-preview-box">
                    <p><label>Usuario o correo electrónico</label></p>
                    <input type="text" disabled class="regular-text" style="width:100%">
                    <p><label>Contraseña</label></p>
                    <input type="password" disabled class="regular-text" style="width:100%">
                    <p><button type="button" class="button button-primary" disabled>Acceder</button></p>
                </div>
                <div class="premiero-login-preview-credit"<?php echo $show_credit ? '' : ' style="display:none"'; ?>>Desarrollado por <?php echo esc_html($brand_name); ?></div>
            </aside>
            </div>
            <script>
            jQuery(function($){
                var frame;
                var fallbackLogo = <?php echo wp_json_encode(premiero_get_login_logo_url()); ?>;
                var preview = $('#premiero-login-live-preview');
                var previewImg = preview.find('.premiero-login-preview-logo img');
                var previewCredit = preview.find('.premiero-login-preview-credit');

                if ( $.fn.wpColorPicker ) {
                    $('.premiero-color-field').wpColorPicker({
                        change: function(event, ui){ preview.css('background', ui.color.toString()); },
                        clear: function(){ preview.css('background', '#f0f0f1'); }
                    });
                }
                $('#<?php echo PREMIERO_OPT_LOGIN_LOGO_W; ?>').on('input', function(){
                    previewImg.css('width', Math.max(50, parseInt(this.value, 10) || 260) + 'px');
                });
                $('input[name="<?php echo PREMIERO_OPT_LOGIN_CREDIT; ?>"]').on('change', function(){
                    previewCredit.toggle(this.checked);
                });
                $('#premiero-login-logo-select').on('click', function(e){
                    e.preventDefault();
                    if ( ! window.wp || ! wp.media ) {
                        window.alert('No se pudo cargar la biblioteca multimedia de WordPress. Recarga la página e inténtalo de nuevo.');
                        return;
                    }
                    if (frame){ frame.open(); return; }
                    frame = wp.media({ title:'Selecciona el logo', button:{ text:'Usar este logo' }, multiple:false });
                    frame.on('select', function(){
                        var att = frame.state().get('selection').first().toJSON();
                        $('#<?php echo PREMIERO_OPT_LOGIN_LOGO_ID; ?>').val(att.id);
                        $('#premiero-login-logo-preview').html('<img src="'+att.url+'" style="max-width:220px;height:auto;">');
                        previewImg.attr('src', att.url);
                    });
                    frame.open();
                });
                $('#premiero-login-logo-clear').on('click', function(e){
                    e.preventDefault();
                    $('#<?php echo PREMIERO_OPT_LOGIN_LOGO_ID; ?>').val('0');
                    $('#premiero-login-logo-preview').empty();
                    previewImg.attr('src', fallbackLogo);
                });
            });
            </script>
        <?php break;
    }

    echo '</div>';
}

/* ====================== Soporte (reutilizado dentro de Acerca de) ====================== */
function premiero_render_support_inner() { ?>
    <p>¿Necesitas adaptar el plugin, integrarlo con otro sistema o desarrollar una solución a medida?</p>
    <div class="premiero-info-actions">
        <a class="button button-primary" href="mailto:hola@premiero.es">Enviar un correo</a>
        <a class="button" href="https://wa.me/34684774365" target="_blank" rel="noopener">Contactar por WhatsApp</a>
    </div>
<?php }

/* ====================== LOGIN: aplicar color/logo ====================== */
add_action('login_enqueue_scripts', function() {
    $logo = esc_url( premiero_get_login_logo_url() );
    $bg   = trim((string) get_option(PREMIERO_OPT_LOGIN_BG, ''));
    $w    = max(50, (int) get_option(PREMIERO_OPT_LOGIN_LOGO_W, 260));

    echo '<style id="premiero-login-style">';
    if ( $bg ) echo 'body.login{background:'.esc_attr($bg).' !important;}';
    echo '#login h1 a{background:none !important; text-indent:0; width:auto; height:auto;}';
    echo '#premiero-login-img{display:block; margin:0 auto; max-width:100%; width:'.$w.'px; height:auto;}';
    echo '</style>';

    echo '<script>document.addEventListener("DOMContentLoaded",function(){var a=document.querySelector("#login h1 a"); if(a){a.innerHTML=""; var img=new Image(); img.id="premiero-login-img"; img.alt=document.title||"Logo"; img.src="'.esc_js($logo).'"; a.appendChild(img);} });</script>';
}, 20);

/* Crédito bajo selector de idioma */
add_action('login_footer', function() {
    if ( ! get_option(PREMIERO_OPT_LOGIN_CREDIT, true) ) return; ?>
    <div id="premiero-login-credit" style="margin:12px auto 24px; text-align:center; opacity:.9;">
        <?php if ( premiero_is_white_label() ): ?>
            <small>Desarrollado por <?php echo esc_html( premiero_get_brand_name() ); ?></small>
        <?php else: ?>
            <small>Desarrollado por <a href="https://premiero.es" target="_blank" rel="noopener" style="text-decoration:none;">Premiero</a></small>
        <?php endif; ?>
    </div>
    <script>(function(){
        var credit=document.getElementById('premiero-login-credit');
        var sw=document.getElementById('language-switcher');
        if(sw && sw.parentNode){ sw.parentNode.insertBefore(credit, sw.nextSibling); }
    })();</script>
<?php });
