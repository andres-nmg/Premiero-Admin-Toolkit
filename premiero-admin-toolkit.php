<?php
/**
 * Plugin Name: Premiero Admin Toolkit
 * Description: Personalización y soporte personalizado.
 * Version:     1.7.1
 * Author:      Premiero
 * Author URI:  https://premiero.es
 * Text Domain: premiero-admin
 */

if ( ! defined('ABSPATH') ) exit;

define('PREMIERO_ATK_VER', '1.6.4');
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
const PREMIERO_OPT_MENU_GROUP     = 'premiero_menu_group';   // array de slugs agrupados bajo Premiero
const PREMIERO_OPT_MENU_LABELS    = 'premiero_menu_labels';  // array slug => label personalizado

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

/* ====================== Menú Premiero (icono dashicon herramientas) ====================== */
add_action('admin_menu', function() {
    add_menu_page(
        'Premiero',
        'Premiero',
        'manage_options',
        PREMIERO_ATK_SLUG,
        'premiero_render_settings_page',
        'dashicons-admin-tools', // sin PNG para evitar logos en admin
        81
    );
    // Solo Ajustes como submenú (Soporte solo como pestaña integrada en Info)
    add_submenu_page(PREMIERO_ATK_SLUG,'Ajustes','Ajustes','manage_options',PREMIERO_ATK_SLUG,'premiero_render_settings_page');
}, 20);

/* ====================== Registrar opciones ====================== */
add_action('admin_init', function() {

    register_setting('premiero_settings_group', PREMIERO_OPT_CSS);
    register_setting('premiero_settings_group', PREMIERO_OPT_HEAD_HTML);
    register_setting('premiero_settings_group', PREMIERO_OPT_BODY_HTML);
    register_setting('premiero_settings_group', PREMIERO_OPT_SNIPPETS);

    // Menú WP: slugs agrupados
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

    // Menú WP: etiquetas personalizadas
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

    // Login
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
        height:10px; /* altura de separación */
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

/* ====================== Estilos mínimos (nuestra página) ====================== */
add_action('admin_enqueue_scripts', function($hook){
    if ( $hook !== 'toplevel_page_'.PREMIERO_ATK_SLUG ) return;
    echo '<style>
    .premiero-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-top:12px;max-width:1100px;}
    .premiero-menu-table{border-collapse:collapse;width:100%;max-width:1100px}
    .premiero-menu-table th,.premiero-menu-table td{padding:8px 10px;border-bottom:1px solid #eee;vertical-align:middle}
    .premiero-menu-table th{background:#fafafa;text-align:left}
    .premiero-label-input{width:100%;max-width:360px}
    </style>';
    // Media (para logo del login)
    wp_enqueue_media();
});

/* ====================== Render: Ajustes ====================== */
function premiero_render_settings_page() {
    if ( ! current_user_can('manage_options') ) return;

    // Por defecto, abrir en la nueva pestaña "info"
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

        echo '<div class="notice notice-success is-dismissible"><p>Menú WP actualizado.</p></div>';
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

        case 'menuwp':
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
                <p><strong>Menú WP</strong> — Marca los elementos que quieras <em>agrupar bajo Premiero</em>. Se añadirá un separador visual entre Ajustes y los plugins agrupados. Puedes <strong>renombrar</strong> los elementos (aplica tanto dentro de Premiero como fuera, si no están agrupados).</p>
                <table class="premiero-menu-table" role="presentation">
                    <thead><tr><th>Elemento</th><th>Slug</th><th>En Premiero</th><th>Nombre personalizado</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $slug=>$title): ?>
                        <tr>
                            <td><?php echo esc_html($title); ?></td>
                            <td><code><?php echo esc_html($slug); ?></code></td>
                            <td>
                                <?php if ($slug === PREMIERO_ATK_SLUG): ?>
                                    <em>(Siempre en Premiero)</em>
                                <?php else: ?>
                                    <label>
                                        <input type="checkbox" name="<?php echo PREMIERO_OPT_MENU_GROUP; ?>[]"
                                               value="<?php echo esc_attr($slug); ?>"
                                            <?php checked( in_array($slug, $selected, true) ); ?>>
                                        Agrupar
                                    </label>
                                <?php endif; ?>
                            </td>
                            <td>
                                <input class="premiero-label-input" type="text"
                                       name="<?php echo PREMIERO_OPT_MENU_LABELS; ?>[<?php echo esc_attr($slug); ?>]"
                                       value="<?php echo esc_attr( $labels[$slug] ?? '' ); ?>"
                                       placeholder="(Opcional) Nombre personalizado">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button('Guardar cambios','primary','premiero_menuwp_submit'); ?>
            </form>
            <?php
        break;

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
                                Mostrar “Desarrollado por Premiero” debajo del selector de idioma.
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

/* ====================== LOGIN: aplicar color/logo y tamaño (solo login) ====================== */
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
