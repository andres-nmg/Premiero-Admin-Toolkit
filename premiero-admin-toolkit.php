<?php
/**
 * Plugin Name: Premiero Admin Toolkit
 * Description: Personalización y soporte personalizado.
 * Version:     2.0.3
 * Author:      Premiero
 * Author URI:  https://premiero.es
 * Text Domain: premiero-admin
 */

if ( ! defined('ABSPATH') ) exit;

define('PREMIERO_ATK_VER', '1.7.2');
define('PREMIERO_ATK_SLUG', 'premiero-admin');
define('PREMIERO_ATK_DIR', plugin_dir_path(__FILE__));
define('PREMIERO_ATK_URL', plugin_dir_url(__FILE__));
define('PREMIERO_ATK_ASSETS', trailingslashit(PREMIERO_ATK_URL.'assets'));

/** Opciones principales */
const PREMIERO_OPT_CSS            = 'premiero_custom_css';
const PREMIERO_OPT_HEAD_HTML      = 'premiero_head_html';
const PREMIERO_OPT_BODY_HTML      = 'premiero_body_html';
const PREMIERO_OPT_SNIPPETS       = 'premiero_php_snippets';

/** Menú WP */
const PREMIERO_OPT_MENU_GROUP     = 'premiero_menu_group';    // array de slugs agrupados bajo Premiero (legado)
const PREMIERO_OPT_MENU_LABELS    = 'premiero_menu_labels';   // array slug => label personalizado (legado)
const PREMIERO_OPT_MENU_CUSTOM    = 'premiero_menu_custom';   // JSON con la estructura completa editada

/** Login UI */
const PREMIERO_OPT_LOGIN_BG       = 'premiero_login_bg';        // string hex, solo login
const PREMIERO_OPT_LOGIN_CREDIT   = 'premiero_login_credit';    // bool
const PREMIERO_OPT_LOGIN_LOGO_ID  = 'premiero_login_logo_id';   // attachment id (int)
const PREMIERO_OPT_LOGIN_LOGO_W   = 'premiero_login_logo_w';    // ancho px (int)

if ( ! function_exists('is_plugin_active') ) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

/* ====================== Snippets como MU ====================== */
function premiero_ensure_mu_dir() {
    $mu_dir = WP_CONTENT_DIR . '/mu-plugins';
    if ( ! is_dir($mu_dir) ) wp_mkdir_p($mu_dir);
    return is_dir($mu_dir) && is_writable($mu_dir);
}
function premiero_write_mu_snippets( $php_code ) {
    if ( ! premiero_ensure_mu_dir() ) return new WP_Error('mu_dir', 'No se pudo crear/escribir en /wp-content/mu-plugins');
    $file = WP_CONTENT_DIR . '/mu-plugins/premiero-snippets.php';
    $header = <<<PHP
<?php
/**
 * Plugin Name: Premiero Snippets (MU)
 * Description: Snippets PHP gestionados desde Premiero Admin Toolkit.
 * Author: Premiero
 * Version: 1.0
 */
if ( ! defined('ABSPATH') ) { exit; }
/* --- Inicio de tus snippets --- */

PHP;
    $content = $header . rtrim($php_code) . "\n";
    return ( false === @file_put_contents($file, $content) )
        ? new WP_Error('write_fail', 'No fue posible escribir el archivo MU: '.$file)
        : true;
}

/* ====================== Helpers ====================== */
function premiero_get_login_logo_url() {
    $id = (int) get_option(PREMIERO_OPT_LOGIN_LOGO_ID, 0);
    if ( $id ) {
        $src = wp_get_attachment_image_src($id, 'full');
        if ( ! empty($src[0]) ) return $src[0];
    }
    $logo_id = get_theme_mod('custom_logo');
    if ( $logo_id ) {
        $src = wp_get_attachment_image_src($logo_id, 'full');
        if ( ! empty($src[0]) ) return $src[0];
    }
    return PREMIERO_ATK_ASSETS . 'premiero-logo.png';
}

function premiero_menu_clean_label( $value ) {
    $value = (string) $value;
    $value = wp_check_invalid_utf8( $value );
    $value = wp_strip_all_tags( $value );
    $value = preg_replace('/[\r\n\t]+/u', ' ', $value);
    $value = preg_replace('/\s{2,}/u', ' ', $value);
    return trim( (string) $value );
}

function premiero_menu_clean_title_from_markup( $value, $fallback = '' ) {
    $title = preg_replace('/<span[^>]*>.*?<\/span>/is', '', (string) $value);
    $title = html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );
    $title = premiero_menu_clean_label( $title );
    return $title !== '' ? $title : (string) $fallback;
}

function premiero_sanitize_menu_structure( $decoded ) {
    $clean = [];

    foreach ( (array) $decoded as $item ) {
        if ( empty($item['slug']) ) continue;

        $slug = sanitize_text_field( (string) $item['slug'] );
        if ( $slug === '' ) continue;

        $entry = [
            'slug'        => $slug,
            'label'       => premiero_menu_clean_label( $item['label'] ?? '' ),
            'hidden'      => ! empty($item['hidden']),
            'external'    => ! empty($item['external']),
            'isSeparator' => ! empty($item['isSeparator']),
            'children'    => [],
        ];

        if ( ! empty($item['url']) ) {
            $entry['url'] = esc_url_raw( $item['url'] );
        }

        foreach ( (array) ($item['children'] ?? []) as $ch ) {
            if ( empty($ch['slug']) ) continue;
            $child_slug = sanitize_text_field( (string) $ch['slug'] );
            if ( $child_slug === '' ) continue;
            $entry['children'][] = [
                'slug'   => $child_slug,
                'label'  => premiero_menu_clean_label( $ch['label'] ?? '' ),
                'hidden' => ! empty($ch['hidden']),
            ];
        }

        $clean[] = $entry;
    }

    return $clean;
}

/* ====================== Menú Premiero ====================== */
add_action('admin_menu', function() {
    add_menu_page(
        'Premiero',
        'Premiero',
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

    register_setting('premiero_settings_group', PREMIERO_OPT_CSS);
    register_setting('premiero_settings_group', PREMIERO_OPT_HEAD_HTML);
    register_setting('premiero_settings_group', PREMIERO_OPT_BODY_HTML);
    register_setting('premiero_settings_group', PREMIERO_OPT_SNIPPETS);

    register_setting('premiero_settings_group', PREMIERO_OPT_MENU_GROUP, [
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

    register_setting('premiero_settings_group', PREMIERO_OPT_MENU_LABELS, [
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

    register_setting('premiero_settings_group', PREMIERO_OPT_MENU_CUSTOM, [
        'type'              => 'string',
        'default'           => '',
        'sanitize_callback' => function($value){
            $decoded = json_decode(wp_unslash($value), true);
            if ( ! is_array($decoded) ) return '';
            $clean = premiero_sanitize_menu_structure( $decoded );
            return wp_json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    ]);

    register_setting('premiero_settings_group', PREMIERO_OPT_LOGIN_BG,     ['type'=>'string','default'=>'']);
    register_setting('premiero_settings_group', PREMIERO_OPT_LOGIN_CREDIT, ['type'=>'boolean','default'=>true]);
    register_setting('premiero_settings_group', PREMIERO_OPT_LOGIN_LOGO_ID,['type'=>'integer','default'=>0]);
    register_setting('premiero_settings_group', PREMIERO_OPT_LOGIN_LOGO_W, ['type'=>'integer','default'=>260]);
});

/* ====================== Aplicar CSS/HTML en frontend ====================== */
add_action('wp_head', function() {
    $head = trim((string) get_option(PREMIERO_OPT_HEAD_HTML, ''));
    if ( $head ) echo "\n<!-- Premiero head -->\n{$head}\n";
    $css = trim((string) get_option(PREMIERO_OPT_CSS, ''));
    if ( $css ) echo "\n<style id='premiero-custom-css'>\n{$css}\n</style>\n";
}, 99);

add_action('wp_body_open', function() {
    $body = trim((string) get_option(PREMIERO_OPT_BODY_HTML, ''));
    if ( $body ) echo "\n<!-- Premiero body_open -->\n{$body}\n";
}, 1);

add_action('wp_footer', function() {
    if ( has_action('wp_body_open') ) return;
    $body = trim((string) get_option(PREMIERO_OPT_BODY_HTML, ''));
    if ( $body ) echo "\n<!-- Premiero body (fallback) -->\n{$body}\n";
}, 1);

/* ====================== APLICAR ESTRUCTURA PERSONALIZADA DEL MENÚ ====================== */
add_action('admin_menu', function() {
    $raw = get_option(PREMIERO_OPT_MENU_CUSTOM, '');
    if ( ! $raw ) {
        premiero_apply_legacy_menu();
        return;
    }
    $structure = json_decode($raw, true);
    if ( ! is_array($structure) || empty($structure) ) {
        premiero_apply_legacy_menu();
        return;
    }

    global $menu, $submenu;

    // Snapshot top-level: capacidades y títulos originales.
    $top_caps   = [];
    $top_titles = [];
    $top_exists = [];
    foreach ( (array) $menu as $m ) {
        if ( ! empty($m[2]) ) {
            $slug = (string) $m[2];
            $top_exists[ $slug ] = true;
            $top_caps[ $slug ]   = $m[1] ?? 'manage_options';
            $top_titles[ $slug ] = premiero_menu_clean_title_from_markup( $m[0] ?? '', $slug );
        }
    }

    // Snapshot de submenús: capacidades y títulos originales por slug.
    $sub_caps   = [];
    $sub_titles = [];
    foreach ( (array) $submenu as $parent_slug => $subs ) {
        foreach ( (array) $subs as $sm ) {
            if ( empty($sm[2]) ) continue;
            $child_slug = (string) $sm[2];
            if ( $child_slug === (string) $parent_slug ) continue;
            if ( ! isset($sub_caps[$child_slug]) ) {
                $sub_caps[$child_slug] = $sm[1] ?? 'manage_options';
            }
            if ( ! isset($sub_titles[$child_slug]) ) {
                $sub_titles[$child_slug] = premiero_menu_clean_title_from_markup( $sm[0] ?? '', $child_slug );
            }
        }
    }

    $children_by_parent = [];
    $all_child_slugs    = [];
    $hidden_parents     = [];

    foreach ( $structure as $item ) {
        if ( empty($item['slug']) ) continue;
        $slug = (string) $item['slug'];

        // Separadores visuales (no necesitan procesamiento de menú)
        if ( ! empty($item['isSeparator']) ) continue;

        // Enlace externo añadido manualmente: no existe en el menú real, no hay nada que renombrar
        if ( ! empty($item['external']) ) continue;

        // Ocultar del menú si está marcado como hidden
        if ( ! empty($item['hidden']) ) {
            $hidden_parents[$slug] = true;
            remove_menu_page($slug);
            continue;
        }

        // Renombrar top-level si tiene label personalizado
        if ( ! empty($item['label']) ) {
            foreach ( $menu as $idx => $m ) {
                if ( isset($m[2]) && $m[2] === $slug ) {
                    $plain = premiero_menu_clean_label($item['label']);
                    $menu[$idx][0] = $plain;
                    if ( isset($menu[$idx][3]) ) $menu[$idx][3] = $plain;
                    break;
                }
            }
        }

        $children_by_parent[$slug] = [];
        foreach ( (array) ($item['children'] ?? []) as $child ) {
            if ( empty($child['slug']) ) continue;
            $child_slug = (string) $child['slug'];
            if ( $child_slug === $slug ) continue;

            $children_by_parent[$slug][] = [
                'slug'   => $child_slug,
                'label'  => premiero_menu_clean_label($child['label'] ?? ''),
                'hidden' => ! empty($child['hidden']),
            ];
            $all_child_slugs[$child_slug] = true;
        }
    }

    // Limpiar todas las ocurrencias de hijos del submenú actual para poder reconstruir orden/agrupación.
    if ( ! empty($all_child_slugs) ) {
        foreach ( (array) $submenu as $parent_slug => $subs ) {
            foreach ( (array) $subs as $sidx => $sm ) {
                if ( empty($sm[2]) ) continue;
                if ( isset($all_child_slugs[ (string) $sm[2] ]) ) {
                    unset($submenu[$parent_slug][$sidx]);
                }
            }
            if ( isset($submenu[$parent_slug]) && is_array($submenu[$parent_slug]) ) {
                $submenu[$parent_slug] = array_values($submenu[$parent_slug]);
            }
        }
    }

    // Reconstruir hijos en el orden guardado, permitiendo reubicar submenús entre padres.
    foreach ( $children_by_parent as $parent_slug => $children ) {
        if ( isset($hidden_parents[$parent_slug]) ) continue;
        if ( ! isset($top_exists[$parent_slug]) ) continue;

        foreach ( $children as $child ) {
            if ( ! empty($child['hidden']) ) continue;

            $child_slug = $child['slug'];
            if ( $child_slug === $parent_slug ) continue;

            // Si el hijo era top-level, lo retiramos para anidarlo.
            if ( isset($top_exists[$child_slug]) ) {
                remove_menu_page($child_slug);
            }

            $child_label = $child['label'] !== ''
                ? $child['label']
                : ( $top_titles[$child_slug] ?? $sub_titles[$child_slug] ?? $child_slug );

            $cap = $top_caps[$child_slug]
                ?? $sub_caps[$child_slug]
                ?? $top_caps[$parent_slug]
                ?? 'manage_options';

            add_submenu_page($parent_slug, $child_label, $child_label, $cap, $child_slug);
        }

        if ( isset($submenu[$parent_slug]) && is_array($submenu[$parent_slug]) ) {
            $submenu[$parent_slug] = array_values($submenu[$parent_slug]);
        }
    }

    // Reordenar top-level según el orden guardado en la estructura del editor.
    $menu_by_slug = [];
    foreach ( (array) $menu as $m ) {
        if ( ! empty($m[2]) && ! isset($menu_by_slug[(string) $m[2]]) ) {
            $menu_by_slug[(string) $m[2]] = $m;
        }
    }

    $ordered_menu = [];
    $used_slugs   = [];

    foreach ( $structure as $item ) {
        if ( empty($item['slug']) ) continue;
        if ( ! empty($item['isSeparator']) || ! empty($item['external']) || ! empty($item['hidden']) ) continue;

        $slug = (string) $item['slug'];
        if ( isset($menu_by_slug[$slug]) && ! isset($used_slugs[$slug]) ) {
            $ordered_menu[] = $menu_by_slug[$slug];
            $used_slugs[$slug] = true;
        }
    }

    // Añadir el resto tal como estaban (separadores nativos y slugs no incluidos en estructura).
    foreach ( (array) $menu as $m ) {
        $slug = ! empty($m[2]) ? (string) $m[2] : '';
        if ( $slug !== '' ) {
            if ( isset($used_slugs[$slug]) ) continue;
            $used_slugs[$slug] = true;
        }
        $ordered_menu[] = $m;
    }

    $menu = array_values($ordered_menu);

}, 999);

/* ---- Sistema legado (compatibilidad con versiones anteriores) ---- */
function premiero_apply_legacy_menu() {
    $labels  = (array) get_option(PREMIERO_OPT_MENU_LABELS, []);
    $grouped = (array) get_option(PREMIERO_OPT_MENU_GROUP, []);

    global $menu;
    if ( ! is_array($menu) ) return;

    if ( ! empty($labels) ) {
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
    }

    if ( empty($grouped) ) return;

    $map = [];
    foreach ( (array) $menu as $m ) {
        if ( ! empty($m[2]) ) {
            $map[$m[2]] = [
                'title' => wp_strip_all_tags($m[0]),
                'cap'   => ! empty($m[1]) ? $m[1] : 'manage_options'
            ];
        }
    }

    add_submenu_page(PREMIERO_ATK_SLUG, '', '', 'manage_options', 'premiero-separator', function(){ });

    foreach ( $grouped as $slug ) {
        if ( ! $slug || $slug === PREMIERO_ATK_SLUG ) continue;
        remove_menu_page($slug);
        $orig_title = isset($map[$slug]['title']) ? $map[$slug]['title'] : $slug;
        $cap        = isset($map[$slug]['cap'])   ? $map[$slug]['cap']   : 'manage_options';
        $menu_title = isset($labels[$slug]) && $labels[$slug] !== '' ? $labels[$slug] : $orig_title;
        add_submenu_page(PREMIERO_ATK_SLUG, wp_strip_all_tags($menu_title), $menu_title, $cap, $slug);
    }
}

/* CSS del separador legado */
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
function premiero_admin_header($active_tab = 'settings') {
    $logo = PREMIERO_ATK_ASSETS . 'premiero-logo.png';
    ?>
    <div class="premiero-head" style="display:flex;align-items:center;justify-content:space-between;margin:12px 0 20px;padding-right:20px;">
        <h1 style="margin:0;font-size:20px;">Premiero · <?php echo ($active_tab==='support' ? 'Soporte' : 'Ajustes'); ?></h1>
        <a href="https://premiero.es" target="_blank" rel="noopener">
            <img src="<?php echo esc_url($logo); ?>" alt="Premiero" style="height:36px;max-width:100%;object-fit:contain;">
        </a>
    </div>
    <?php
}

function premiero_tabs_nav($active) {
    $tabs = [
        'info'    => 'Info',
        'php'     => 'Snippets PHP',
        'head'    => 'HTML &lt;head&gt;',
        'body'    => 'HTML &lt;body&gt;',
        'css'     => 'CSS',
        'menuwp'  => 'Menú WP',
        'adminui' => 'Login / Admin UI',
    ];
    echo '<h2 class="nav-tab-wrapper">';
    foreach ($tabs as $slug => $label) {
        $class = $active === $slug ? ' nav-tab nav-tab-active' : ' nav-tab';
        $url   = admin_url('admin.php?page='.PREMIERO_ATK_SLUG.'&tab='.$slug);
        echo '<a class="'.esc_attr($class).'" href="'.esc_url($url).'">'.$label.'</a>';
    }
    echo '</h2>';
}

/* ====================== Estilos mínimos ====================== */
add_action('admin_enqueue_scripts', function($hook){
    if ( $hook !== 'toplevel_page_'.PREMIERO_ATK_SLUG ) return;
    echo '<style>
    .premiero-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-top:12px;max-width:1100px;}
    .premiero-menu-table{border-collapse:collapse;width:100%;max-width:1100px}
    .premiero-menu-table th,.premiero-menu-table td{padding:8px 10px;border-bottom:1px solid #eee;vertical-align:middle}
    .premiero-menu-table th{background:#fafafa;text-align:left}
    .premiero-label-input{width:100%;max-width:360px}
    /* Editor menú WP */
    .pmwp-item{background:#fff;border:1px solid #e0e0e0;border-radius:6px;margin-bottom:6px;overflow:visible;}
    .pmwp-item.pmwp-hidden-item{opacity:0.5;}
    .pmwp-handle{display:flex;align-items:center;gap:8px;padding:8px 12px;background:#fafafa;border-bottom:1px solid #f0f0f0;}
    .pmwp-toggle{width:24px;height:24px;border:1px solid #d0d4d9;border-radius:4px;background:#fff;cursor:pointer;line-height:1;padding:0;color:#555;display:flex;align-items:center;justify-content:center;font-size:11px;}
    .pmwp-item .pmwp-children{display:none;}
    .pmwp-item.pmwp-open .pmwp-children{display:block;}
    .pmwp-children{padding:4px 8px 4px 40px;min-height:28px;background:#fdfdfd;border-top:1px dashed #eee;}
    .pmwp-children.pmwp-drop-target{background:#f0f7ff;outline:2px dashed #72aee6;}
    .pmwp-child{display:flex;align-items:center;gap:6px;padding:5px 8px;margin:3px 0;background:#fff;border:1px solid #e8e8e8;border-radius:4px;}
    .pmwp-child-drag{cursor:grab;color:#ccc;font-size:14px;user-select:none;line-height:1;}
    .pmwp-separator-item{display:flex;align-items:center;gap:8px;padding:6px 12px;background:#f5f5f5;border:1px dashed #ccc;border-radius:6px;margin-bottom:6px;cursor:grab;}
    .pmwp-drag-icon{color:#bbb;font-size:18px;line-height:1;user-select:none;cursor:grab;}
    .pmwp-slug-badge{font-size:11px;color:#888;background:#f0f0f0;padding:2px 6px;border-radius:3px;white-space:nowrap;font-family:monospace;}
    .pmwp-label{flex:1;border:1px solid #ddd!important;border-radius:4px!important;padding:4px 8px!important;font-size:13px!important;box-shadow:none!important;}
    .pmwp-child-label{flex:1;border:1px solid #ddd!important;border-radius:3px!important;padding:3px 6px!important;font-size:12px!important;box-shadow:none!important;}
    .pmwp-remove,.pmwp-child-remove{color:#c00;padding:0!important;font-size:18px;line-height:1;background:none!important;border:none!important;cursor:pointer;}
    .pmwp-drop-zone{min-height:36px;border:2px dashed #c3c4c7;border-radius:4px;margin:4px 0;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:12px;}
    .pmwp-toolbar{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center;}
    </style>';
    wp_enqueue_media();
});

/* ====================== Render: Ajustes ====================== */
function premiero_render_settings_page() {
    if ( ! current_user_can('manage_options') ) return;

    $active = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'info';

    /* Guardado de snippets MU */
    if ( 'php' === $active && isset($_POST['premiero_snippets_submit']) && check_admin_referer('premiero_snippets_nonce') ) {
        $code = wp_unslash( $_POST[PREMIERO_OPT_SNIPPETS] ?? '' );
        update_option(PREMIERO_OPT_SNIPPETS, $code);
        $result = premiero_write_mu_snippets($code);
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

    premiero_admin_header($active==='support' ? 'support' : 'settings');
    premiero_tabs_nav($active);

    echo '<div class="premiero-card">';

    switch ($active) {

        case 'info': ?>
            <div class="notice notice-info">
                <p><strong>Desarrollado por <a href="https://premiero.es" target="_blank" rel="noopener">Premiero</a></strong>. Este plugin está orientado a desarrolladores y tareas de administración de WordPress. <strong>No se recomienda realizar cambios si no se tiene claro lo que se está haciendo.</strong></p>
            </div>
            <h2>Soporte</h2>
            <div class="premiero-card">
                <?php premiero_render_support_inner(); ?>
            </div>
        <?php break;

        case 'css': ?>
            <form method="post" action="options.php">
                <?php settings_fields('premiero_settings_group'); ?>
                <p><label for="<?php echo PREMIERO_OPT_CSS; ?>"><strong>CSS personalizado (frontend)</strong></label></p>
                <textarea name="<?php echo PREMIERO_OPT_CSS; ?>" id="<?php echo PREMIERO_OPT_CSS; ?>" rows="14" style="width:100%;font-family:monospace;"><?php echo esc_textarea( get_option(PREMIERO_OPT_CSS, '') ); ?></textarea>
                <?php submit_button('Guardar cambios'); ?>
            </form>
        <?php break;

        case 'head': ?>
            <form method="post" action="options.php">
                <?php settings_fields('premiero_settings_group'); ?>
                <p><label for="<?php echo PREMIERO_OPT_HEAD_HTML; ?>"><strong>HTML en &lt;head&gt;</strong></label></p>
                <textarea name="<?php echo PREMIERO_OPT_HEAD_HTML; ?>" id="<?php echo PREMIERO_OPT_HEAD_HTML; ?>" rows="12" style="width:100%;font-family:monospace;"><?php echo esc_textarea( get_option(PREMIERO_OPT_HEAD_HTML, '') ); ?></textarea>
                <?php submit_button('Guardar cambios'); ?>
            </form>
        <?php break;

        case 'body': ?>
            <form method="post" action="options.php">
                <?php settings_fields('premiero_settings_group'); ?>
                <p><label for="<?php echo PREMIERO_OPT_BODY_HTML; ?>"><strong>HTML al inicio de &lt;body&gt;</strong></label></p>
                <textarea name="<?php echo PREMIERO_OPT_BODY_HTML; ?>" id="<?php echo PREMIERO_OPT_BODY_HTML; ?>" rows="12" style="width:100%;font-family:monospace;"><?php echo esc_textarea( get_option(PREMIERO_OPT_BODY_HTML, '') ); ?></textarea>
                <?php submit_button('Guardar cambios'); ?>
            </form>
        <?php break;

        case 'php': ?>
            <div class="notice notice-warning"><p><strong>Atención:</strong> El código aquí se guarda como <code>MU-plugin</code>. Prueba primero en staging.</p></div>
            <form method="post">
                <?php wp_nonce_field('premiero_snippets_nonce'); ?>
                <p><label for="<?php echo PREMIERO_OPT_SNIPPETS; ?>"><strong>Snippets PHP</strong></label></p>
                <textarea name="<?php echo PREMIERO_OPT_SNIPPETS; ?>" id="<?php echo PREMIERO_OPT_SNIPPETS; ?>" rows="18" style="width:100%;font-family:monospace;"><?php echo esc_textarea( get_option(PREMIERO_OPT_SNIPPETS, '') ); ?></textarea>
                <?php submit_button('Guardar snippets', 'primary', 'premiero_snippets_submit'); ?>
            </form>
        <?php break;

        /* ====================== PESTAÑA MENÚ WP ====================== */
        case 'menuwp':
            global $menu, $submenu;

            // Construir snapshot del menú actual (top-level)
            $all_top = [];
            if ( is_array($menu) ) {
                foreach ( $menu as $m ) {
                    if ( empty($m[2]) ) continue;
                    $slug  = (string) $m[2];
                    $title = premiero_menu_clean_title_from_markup( $m[0] ?? '', $slug );
                    if ( $title === '' ) continue; // separadores nativos de WP sin texto
                    $all_top[$slug] = [
                        'title' => $title,
                        'cap'   => $m[1] ?? 'manage_options',
                    ];
                }
            }

            // Sub-menús disponibles por slug padre
            $all_sub = [];
            if ( is_array($submenu) ) {
                foreach ( $submenu as $parent_slug => $subs ) {
                    foreach ( (array) $subs as $sm ) {
                        if ( empty($sm[2]) ) continue;
                        $stitle = premiero_menu_clean_title_from_markup( $sm[0] ?? '', $sm[2] );
                        // Evitar el primer submenú duplicado que WP añade igual al padre
                        if ( $sm[2] === $parent_slug ) continue;
                        $all_sub[$parent_slug][] = [
                            'slug'  => (string) $sm[2],
                            'title' => $stitle,
                        ];
                    }
                }
            }

            $saved_raw  = get_option(PREMIERO_OPT_MENU_CUSTOM, '');
            $saved      = $saved_raw ? json_decode($saved_raw, true) : null;

            $top_json   = wp_json_encode($all_top, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $sub_json   = wp_json_encode($all_sub, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $saved_json = ( $saved && is_array($saved) ) ? wp_json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null';
            $nonce      = wp_create_nonce('premiero_menuwp_ajax');
            ?>

            <div class="notice notice-info" style="margin:0 0 16px;">
                <p>
                    <strong>Editor visual del Menú WP</strong> —
                    Arrastra los elementos para reordenarlos. Puedes <strong>anidar</strong> un elemento dentro de otro arrastrándolo a su zona interior,
                    <strong>renombrarlos</strong>, <strong>ocultarlos</strong> sin eliminarlos, añadir <strong>separadores</strong> o <strong>enlaces externos</strong>.
                    Los cambios se aplican en todo el panel de administración.
                </p>
            </div>

            <div class="pmwp-toolbar">
                <button type="button" class="button" id="pmwp-add-external">+ Enlace externo</button>
                <button type="button" class="button" id="pmwp-add-separator">+ Separador</button>
                <button type="button" class="button" id="pmwp-reset" style="color:#c00;border-color:#c00;">&#8635; Restaurar original</button>
                <button type="button" class="button button-primary" id="pmwp-save" style="margin-left:auto;">Guardar cambios</button>
            </div>

            <div id="pmwp-list"></div>

            <div style="margin-top:12px;text-align:right;">
                <button type="button" class="button button-primary" id="pmwp-save2">Guardar cambios</button>
            </div>

            <div id="pmwp-msg" style="display:none;margin-top:12px;padding:10px 14px;border-radius:4px;"></div>

            <script>
            (function(){
                /* ---- Datos desde PHP ---- */
                var TOP    = <?php echo $top_json; ?>;
                var SUBS   = <?php echo $sub_json; ?>;
                var SAVED  = <?php echo $saved_json; ?>;
                var NONCE  = <?php echo wp_json_encode($nonce); ?>;
                var AJURL  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

                var list     = document.getElementById('pmwp-list');
                var sepCount = 0;
                var dragging = null;       // elemento que se arrastra
                var dragType = null;       // 'top' | 'child'
                var dragSrc  = null;       // contenedor origen (para child)

                /* ======================================================
                   CONSTRUCCIÓN DE ESTRUCTURA INICIAL
                ====================================================== */
                function buildStructure() {
                    if ( SAVED && Array.isArray(SAVED) && SAVED.length ) return SAVED;

                    var result = [];
                    Object.keys(TOP).forEach(function(slug){
                        var entry = {
                            slug:     slug,
                            label:    TOP[slug].title,
                            hidden:   false,
                            external: false,
                            children: []
                        };
                        if ( SUBS[slug] ) {
                            SUBS[slug].forEach(function(sm){
                                entry.children.push({ slug: sm.slug, label: sm.title, hidden: false });
                            });
                        }
                        result.push(entry);
                    });
                    return result;
                }

                /* ======================================================
                   RENDER ELEMENTO TOP-LEVEL
                ====================================================== */
                function makeItem(item) {
                    // Separador
                    if ( item.isSeparator ) return makeSeparator(item.slug);

                    var el = document.createElement('div');
                    el.className = 'pmwp-item' + (item.hidden ? ' pmwp-hidden-item' : '');
                    el.dataset.slug     = item.slug;
                    el.dataset.external = item.external ? '1' : '0';
                    el.dataset.url      = item.url || '';

                    /* Cabecera arrastrable */
                    var handle = document.createElement('div');
                    handle.className = 'pmwp-handle';

                    var toggleBtn = document.createElement('button');
                    toggleBtn.type = 'button';
                    toggleBtn.className = 'pmwp-toggle';
                    toggleBtn.title = 'Mostrar/Ocultar subelementos';

                    var dragIcon = document.createElement('span');
                    dragIcon.className = 'pmwp-drag-icon';
                    dragIcon.innerHTML = '&#8942;&#8942;';
                    dragIcon.title = 'Arrastrar';

                    var labelInput = document.createElement('input');
                    labelInput.type = 'text';
                    labelInput.className = 'pmwp-label';
                    labelInput.value = item.label || item.slug;
                    labelInput.placeholder = 'Nombre del elemento';

                    var badge = document.createElement('span');
                    badge.className = 'pmwp-slug-badge';
                    badge.textContent = item.slug;

                    var hiddenWrap = document.createElement('label');
                    hiddenWrap.style.cssText = 'font-size:12px;color:#666;display:flex;align-items:center;gap:4px;white-space:nowrap;cursor:pointer;';
                    var hiddenCb = document.createElement('input');
                    hiddenCb.type = 'checkbox';
                    hiddenCb.className = 'pmwp-hidden-cb';
                    hiddenCb.checked = !! item.hidden;
                    hiddenWrap.appendChild(hiddenCb);
                    hiddenWrap.appendChild(document.createTextNode(' Ocultar'));
                    hiddenCb.addEventListener('change', function(){
                        el.classList.toggle('pmwp-hidden-item', hiddenCb.checked);
                    });

                    var removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.className = 'pmwp-remove';
                    removeBtn.innerHTML = '&times;';
                    removeBtn.title = 'Eliminar del menú';
                    removeBtn.addEventListener('click', function(){ el.remove(); });

                    toggleBtn.addEventListener('click', function(e){
                        e.preventDefault();
                        e.stopPropagation();
                        toggleItemOpen(el);
                    });

                    handle.appendChild(dragIcon);
                    handle.appendChild(toggleBtn);
                    handle.appendChild(labelInput);
                    handle.appendChild(badge);
                    handle.appendChild(hiddenWrap);
                    handle.appendChild(removeBtn);

                    /* Zona de hijos */
                    var childZone = document.createElement('div');
                    childZone.className = 'pmwp-children';
                    childZone.dataset.parent = item.slug;

                    if ( item.children && item.children.length ) {
                        item.children.forEach(function(ch){
                            childZone.appendChild(makeChild(ch));
                        });
                    } else {
                        childZone.appendChild(makeDropHint());
                    }

                    el.appendChild(handle);
                    el.appendChild(childZone);

                    /* Drag top-level */
                    bindTopDrag(dragIcon, el);
                    /* Drop en zona hijos */
                    bindChildDrop(childZone);
                    /* Drop sobre cabecera del top-level */
                    bindTopHeaderDrop(handle, el);
                    setItemOpen(el, !!item.open);

                    return el;
                }

                /* ======================================================
                   RENDER HIJO
                ====================================================== */
                function makeChild(ch) {
                    var el = document.createElement('div');
                    el.className = 'pmwp-child';
                    el.dataset.slug = ch.slug;

                    var dragIcon = document.createElement('span');
                    dragIcon.className = 'pmwp-child-drag';
                    dragIcon.innerHTML = '&#8942;&#8942;';
                    dragIcon.title = 'Arrastrar';

                    var labelInput = document.createElement('input');
                    labelInput.type = 'text';
                    labelInput.className = 'pmwp-child-label';
                    labelInput.value = ch.label || ch.slug;
                    labelInput.placeholder = 'Nombre';

                    var badge = document.createElement('span');
                    badge.className = 'pmwp-slug-badge';
                    badge.textContent = ch.slug;

                    var hiddenWrap = document.createElement('label');
                    hiddenWrap.style.cssText = 'font-size:12px;color:#666;display:flex;align-items:center;gap:3px;white-space:nowrap;cursor:pointer;';
                    var hiddenCb = document.createElement('input');
                    hiddenCb.type = 'checkbox';
                    hiddenCb.className = 'pmwp-child-hidden-cb';
                    hiddenCb.checked = !! ch.hidden;
                    hiddenWrap.appendChild(hiddenCb);
                    hiddenWrap.appendChild(document.createTextNode(' Ocultar'));

                    var removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.className = 'pmwp-child-remove';
                    removeBtn.innerHTML = '&times;';
                    removeBtn.title = 'Quitar';
                    removeBtn.addEventListener('click', function(){
                        el.remove();
                        updateDropHints();
                    });

                    el.appendChild(dragIcon);
                    el.appendChild(labelInput);
                    el.appendChild(badge);
                    el.appendChild(hiddenWrap);
                    el.appendChild(removeBtn);

                    bindChildDrag(dragIcon, el);
                    return el;
                }

                /* ======================================================
                   SEPARADOR
                ====================================================== */
                function makeSeparator(slug) {
                    if ( !slug ) { sepCount++; slug = 'pmwp-sep-' + sepCount; }
                    var el = document.createElement('div');
                    el.className = 'pmwp-separator-item';
                    el.dataset.slug = slug;
                    el.innerHTML = '<span class="pmwp-drag-icon">&#8942;&#8942;</span>'
                        + '<span style="flex:1;font-size:12px;color:#aaa;font-style:italic;">&#8212; Separador &#8212;</span>';

                    var removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.className = 'pmwp-remove';
                    removeBtn.innerHTML = '&times;';
                    removeBtn.title = 'Eliminar';
                    removeBtn.addEventListener('click', function(){ el.remove(); });
                    el.appendChild(removeBtn);

                    bindTopDrag(el.querySelector('.pmwp-drag-icon'), el);
                    return el;
                }

                /* ======================================================
                   HINT ZONA DE DROP VACÍA
                ====================================================== */
                function makeDropHint() {
                    var hint = document.createElement('div');
                    hint.className = 'pmwp-drop-zone pmwp-drop-hint';
                    hint.textContent = 'Arrastra aquí submenús';
                    return hint;
                }

                function updateDropHints() {
                    list.querySelectorAll('.pmwp-children').forEach(function(zone){
                        var hasChildren = zone.querySelectorAll('.pmwp-child').length > 0;
                        var hint = zone.querySelector('.pmwp-drop-hint');
                        if ( hasChildren && hint ) hint.remove();
                        if ( !hasChildren && !hint ) zone.appendChild(makeDropHint());
                    });
                }

                function setItemOpen(el, isOpen) {
                    var open = !!isOpen;
                    el.classList.toggle('pmwp-open', open);
                    var btn = el.querySelector('.pmwp-toggle');
                    if ( btn ) {
                        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                        btn.innerHTML = open ? '&#9662;' : '&#9656;';
                    }
                }

                function toggleItemOpen(el) {
                    setItemOpen(el, !el.classList.contains('pmwp-open'));
                }

                function convertTopDraggingToChild(zone) {
                    if ( !dragging || dragType !== 'top' || dragging.classList.contains('pmwp-separator-item') ) {
                        return false;
                    }
                    var targetItem = zone.closest('.pmwp-item');
                    if ( targetItem && targetItem === dragging ) return false;

                    var slug  = dragging.dataset.slug;
                    var label = dragging.querySelector('.pmwp-label') ? dragging.querySelector('.pmwp-label').value : slug;
                    var ch = makeChild({ slug: slug, label: label, hidden: false });
                    zone.appendChild(ch);
                    dragging.style.opacity = '';
                    dragging.style.pointerEvents = '';
                    dragging.remove();
                    dragging = null;
                    dragType = null;
                    updateDropHints();
                    return true;
                }

                /* ======================================================
                   DRAG & DROP — TOP LEVEL
                ====================================================== */
                function bindTopDrag(handle, el) {
                    handle.addEventListener('mousedown', function(e){
                        if ( e.target.tagName === 'INPUT' || e.target.tagName === 'BUTTON' ) return;
                        e.preventDefault();
                        dragging  = el;
                        dragType  = 'top';
                        el.style.opacity = '0.45';
                        el.style.pointerEvents = 'none';
                    });
                }

                document.addEventListener('mousemove', function(e){
                    if ( !dragging ) return;

                    if ( dragType === 'top' ) {
                        var after = getAfterEl(list, e.clientY, '.pmwp-item,.pmwp-separator-item');
                        if ( after ) list.insertBefore(dragging, after);
                        else         list.appendChild(dragging);
                    }

                    if ( dragType === 'child' ) {
                        // Encontrar la zona de hijos bajo el cursor
                        var zones = list.querySelectorAll('.pmwp-children');
                        var targetZone = null;
                        zones.forEach(function(z){
                            var r = z.getBoundingClientRect();
                            if ( e.clientX >= r.left && e.clientX <= r.right &&
                                 e.clientY >= r.top  && e.clientY <= r.bottom ) {
                                targetZone = z;
                            }
                        });

                        if ( targetZone ) {
                            var after = getAfterEl(targetZone, e.clientY, '.pmwp-child');
                            if ( after ) targetZone.insertBefore(dragging, after);
                            else         targetZone.appendChild(dragging);
                        }
                    }
                });

                document.addEventListener('mouseup', function(){
                    if ( !dragging ) return;
                    dragging.style.opacity = '';
                    dragging.style.pointerEvents = '';
                    dragging  = null;
                    dragType  = null;
                    dragSrc   = null;
                    updateDropHints();
                });

                /* ======================================================
                   DRAG & DROP — CHILD
                ====================================================== */
                function bindChildDrag(handle, el) {
                    handle.addEventListener('mousedown', function(e){
                        if ( e.target.tagName === 'INPUT' || e.target.tagName === 'BUTTON' ) return;
                        e.preventDefault();
                        dragging = el;
                        dragType = 'child';
                        dragSrc  = el.parentNode;
                        el.style.opacity = '0.45';
                        el.style.pointerEvents = 'none';
                    });
                }

                /* ======================================================
                   DROP EN ZONA DE HIJOS (recibe top-level como hijo)
                ====================================================== */
                function bindChildDrop(zone) {
                    zone.addEventListener('mouseenter', function(){
                        if ( dragging && dragType === 'top' && !dragging.classList.contains('pmwp-separator-item') ) {
                            zone.classList.add('pmwp-drop-target');
                        }
                    });
                    zone.addEventListener('mouseleave', function(){
                        zone.classList.remove('pmwp-drop-target');
                    });
                    zone.addEventListener('mouseup', function(){
                        zone.classList.remove('pmwp-drop-target');
                        convertTopDraggingToChild(zone);
                    });
                }

                function bindTopHeaderDrop(handle, itemEl) {
                    handle.addEventListener('mouseenter', function(){
                        if ( dragging && dragType === 'top' && dragging !== itemEl && !dragging.classList.contains('pmwp-separator-item') ) {
                            setItemOpen(itemEl, true);
                            var zone = itemEl.querySelector('.pmwp-children');
                            if ( zone ) zone.classList.add('pmwp-drop-target');
                        }
                    });
                    handle.addEventListener('mouseleave', function(){
                        var zone = itemEl.querySelector('.pmwp-children');
                        if ( zone ) zone.classList.remove('pmwp-drop-target');
                    });
                    handle.addEventListener('mouseup', function(){
                        var zone = itemEl.querySelector('.pmwp-children');
                        if ( zone ) {
                            zone.classList.remove('pmwp-drop-target');
                            convertTopDraggingToChild(zone);
                        }
                    });
                }

                /* ======================================================
                   HELPER: Elemento después del cursor en un contenedor
                ====================================================== */
                function getAfterEl(container, y, selector) {
                    var els = Array.from(container.querySelectorAll(':scope > ' + selector))
                        .filter(function(el){ return el !== dragging; });
                    var res = { offset: Number.NEGATIVE_INFINITY, el: null };
                    els.forEach(function(child){
                        var box    = child.getBoundingClientRect();
                        var offset = y - box.top - box.height / 2;
                        if ( offset < 0 && offset > res.offset ) {
                            res = { offset: offset, el: child };
                        }
                    });
                    return res.el;
                }

                /* ======================================================
                   LEER ESTRUCTURA DESDE EL DOM
                ====================================================== */
                function readStructure() {
                    var result = [];
                    list.querySelectorAll(':scope > .pmwp-item, :scope > .pmwp-separator-item').forEach(function(el){
                        var slug = el.dataset.slug || '';
                        if ( !slug ) return;

                        if ( el.classList.contains('pmwp-separator-item') ) {
                            result.push({ slug: slug, isSeparator: true });
                            return;
                        }

                        var labelEl  = el.querySelector('.pmwp-label');
                        var hiddenEl = el.querySelector('.pmwp-hidden-cb');
                        var external = el.dataset.external === '1';

                        var entry = {
                            slug:     slug,
                            label:    labelEl  ? labelEl.value  : slug,
                            hidden:   hiddenEl ? hiddenEl.checked : false,
                            external: external,
                            children: []
                        };
                        if ( external && el.dataset.url ) {
                            entry.url = el.dataset.url;
                        }

                        el.querySelectorAll('.pmwp-children > .pmwp-child').forEach(function(ch){
                            var cslug   = ch.dataset.slug || '';
                            var clabel  = ch.querySelector('.pmwp-child-label');
                            var chidden = ch.querySelector('.pmwp-child-hidden-cb');
                            if ( cslug ) {
                                entry.children.push({
                                    slug:   cslug,
                                    label:  clabel  ? clabel.value   : cslug,
                                    hidden: chidden ? chidden.checked : false
                                });
                            }
                        });

                        result.push(entry);
                    });
                    return result;
                }

                /* ======================================================
                   GUARDAR VÍA AJAX
                ====================================================== */
                function saveMenu() {
                    var structure = readStructure();
                    var btn1 = document.getElementById('pmwp-save');
                    var btn2 = document.getElementById('pmwp-save2');
                    btn1.disabled = btn2.disabled = true;
                    btn1.textContent = btn2.textContent = 'Guardando…';

                    var body = new FormData();
                    body.append('action', 'premiero_save_menu');
                    body.append('nonce',  NONCE);
                    body.append('menu',   JSON.stringify(structure));

                    fetch(AJURL, { method: 'POST', body: body })
                        .then(function(r){ return r.json(); })
                        .then(function(d){
                            btn1.disabled = btn2.disabled = false;
                            btn1.textContent = btn2.textContent = 'Guardar cambios';
                            var msg = document.getElementById('pmwp-msg');
                            msg.style.display = 'block';
                            if ( d.success ) {
                                msg.style.background = '#edfaef';
                                msg.style.border = '1px solid #00a32a';
                                msg.style.color  = '#1a4731';
                                msg.textContent  = '✓ Menú guardado. Recarga la página para ver los cambios aplicados.';
                            } else {
                                msg.style.background = '#fce8e8';
                                msg.style.border = '1px solid #d63638';
                                msg.style.color  = '#8a1c1e';
                                msg.textContent  = '✗ Error: ' + (d.data || 'desconocido');
                            }
                            setTimeout(function(){ msg.style.display='none'; }, 5000);
                        })
                        .catch(function(err){
                            btn1.disabled = btn2.disabled = false;
                            btn1.textContent = btn2.textContent = 'Guardar cambios';
                            alert('Error de red al guardar: ' + err);
                        });
                }

                /* ======================================================
                   BOTONES
                ====================================================== */
                document.getElementById('pmwp-save').addEventListener('click', saveMenu);
                document.getElementById('pmwp-save2').addEventListener('click', saveMenu);

                document.getElementById('pmwp-add-external').addEventListener('click', function(){
                    var url = prompt('URL del enlace externo (ej: https://ejemplo.com):');
                    if ( url === null || url.trim() === '' ) return;
                    url = url.trim();
                    var label = prompt('Texto visible en el menú:', url);
                    if ( label === null ) return;
                    var slug  = 'pmwp-ext-' + Date.now();
                    var item = makeItem({ slug: slug, label: label || url, hidden: false, external: true, url: url, children: [] });
                    list.appendChild(item);
                });

                document.getElementById('pmwp-add-separator').addEventListener('click', function(){
                    sepCount++;
                    list.appendChild(makeSeparator('pmwp-sep-' + sepCount));
                });

                document.getElementById('pmwp-reset').addEventListener('click', function(){
                    if ( !confirm('¿Restaurar el menú al orden original de WordPress? Se borrarán todos los cambios guardados.') ) return;
                    var body = new FormData();
                    body.append('action', 'premiero_save_menu');
                    body.append('nonce',  NONCE);
                    body.append('menu',   '[]');
                    fetch(AJURL, { method:'POST', body: body })
                        .then(function(){ location.reload(); });
                });

                /* ======================================================
                   RENDER INICIAL
                ====================================================== */
                var structure = buildStructure();
                structure.forEach(function(item){ list.appendChild(makeItem(item)); });
                updateDropHints();

            })();
            </script>
            <?php
        break;

        /* ====================== PESTAÑA ADMIN UI ====================== */
        case 'adminui': ?>
            <form method="post">
                <?php wp_nonce_field('premiero_adminui_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo PREMIERO_OPT_LOGIN_BG; ?>">Color de fondo (solo login)</label></th>
                        <td>
                            <input type="text" name="<?php echo PREMIERO_OPT_LOGIN_BG; ?>" id="<?php echo PREMIERO_OPT_LOGIN_BG; ?>"
                                   value="<?php echo esc_attr( get_option(PREMIERO_OPT_LOGIN_BG, '') ); ?>"
                                   placeholder="#RRGGBB o #RGB" class="regular-text">
                            <p class="description">Introduce un color hexadecimal (ej. <code>#999999</code>). No afecta a <code>wp-admin</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Logo de la pantalla de login</th>
                        <td>
                            <?php
                            $logo_id = (int) get_option(PREMIERO_OPT_LOGIN_LOGO_ID, 0);
                            $src     = $logo_id ? wp_get_attachment_image_src($logo_id,'medium') : false;
                            ?>
                            <div id="premiero-login-logo-preview" style="margin-bottom:10px;">
                                <?php if($src){ echo '<img src="'.esc_url($src[0]).'" style="max-width:220px;height:auto;">'; } ?>
                            </div>
                            <input type="hidden" name="<?php echo PREMIERO_OPT_LOGIN_LOGO_ID; ?>" id="<?php echo PREMIERO_OPT_LOGIN_LOGO_ID; ?>" value="<?php echo esc_attr($logo_id); ?>">
                            <button type="button" class="button" id="premiero-login-logo-select">Seleccionar logo</button>
                            <button type="button" class="button" id="premiero-login-logo-clear">Quitar</button>
                            <p class="description">Si no seleccionas nada, se usará el logo del sitio (o el de Premiero como último recurso).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo PREMIERO_OPT_LOGIN_LOGO_W; ?>">Ancho del logo (px)</label></th>
                        <td>
                            <input type="number" min="50" step="10" name="<?php echo PREMIERO_OPT_LOGIN_LOGO_W; ?>" id="<?php echo PREMIERO_OPT_LOGIN_LOGO_W; ?>"
                                   value="<?php echo esc_attr( (int) get_option(PREMIERO_OPT_LOGIN_LOGO_W, 260) ); ?>">
                            <p class="description">La altura se ajusta automáticamente.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Crédito en login</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo PREMIERO_OPT_LOGIN_CREDIT; ?>" value="1" <?php checked( (bool) get_option(PREMIERO_OPT_LOGIN_CREDIT, true) ); ?>>
                                Mostrar "Desarrollado por Premiero" debajo del selector de idioma.
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Guardar cambios','primary','premiero_adminui_submit'); ?>
            </form>
            <script>
            (function($){
                var frame;
                $('#premiero-login-logo-select').on('click', function(e){
                    e.preventDefault();
                    if (frame){ frame.open(); return; }
                    frame = wp.media({ title:'Selecciona el logo', button:{ text:'Usar este logo' }, multiple:false });
                    frame.on('select', function(){
                        var att = frame.state().get('selection').first().toJSON();
                        $('#<?php echo PREMIERO_OPT_LOGIN_LOGO_ID; ?>').val(att.id);
                        $('#premiero-login-logo-preview').html('<img src="'+att.url+'" style="max-width:220px;height:auto;">');
                    });
                    frame.open();
                });
                $('#premiero-login-logo-clear').on('click', function(e){
                    e.preventDefault();
                    $('#<?php echo PREMIERO_OPT_LOGIN_LOGO_ID; ?>').val('0');
                    $('#premiero-login-logo-preview').empty();
                });
            })(jQuery);
            </script>
        <?php break;
    }

    echo '</div>';
}

/* ====================== Soporte (reutilizado dentro de Info) ====================== */
function premiero_render_support_inner() { ?>
    <p>Plugin desarrollado por <strong>Premiero</strong> para acelerar tareas de administración.</p>
    <p>Visítanos: <a href="https://premiero.es" target="_blank" rel="noopener">https://premiero.es</a></p>
    <p><strong>¿Necesitas soporte?</strong> Puedes contactarnos a través de:</p>
    <p>📧 <a href="mailto:hola@premiero.es">hola@premiero.es</a></p>
    <p>💬 <a class="button button-primary" href="https://wa.me/34684774365" target="_blank" rel="noopener">Enviar mensaje por WhatsApp</a></p>
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
        <small>Desarrollado por <a href="https://premiero.es" target="_blank" rel="noopener" style="text-decoration:none;">Premiero</a></small>
    </div>
    <script>(function(){
        var credit=document.getElementById('premiero-login-credit');
        var sw=document.getElementById('language-switcher');
        if(sw && sw.parentNode){ sw.parentNode.insertBefore(credit, sw.nextSibling); }
    })();</script>
<?php });

/* ====================== AJAX: guardar menú personalizado ====================== */
add_action('wp_ajax_premiero_save_menu', function() {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error('Sin permisos');
    }
    if ( ! check_ajax_referer('premiero_menuwp_ajax', 'nonce', false) ) {
        wp_send_json_error('Nonce inválido');
    }

    $raw = isset($_POST['menu']) ? wp_unslash($_POST['menu']) : '';

    // Restaurar: borrar personalización
    if ( $raw === '[]' || $raw === '' ) {
        delete_option(PREMIERO_OPT_MENU_CUSTOM);
        wp_send_json_success('reset');
    }

    $decoded = json_decode($raw, true);
    if ( ! is_array($decoded) ) {
        wp_send_json_error('JSON inválido');
    }

    $clean = premiero_sanitize_menu_structure( $decoded );
    update_option(PREMIERO_OPT_MENU_CUSTOM, wp_json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    wp_send_json_success('saved');
});
