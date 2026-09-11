<?php
/**
 * Interfaz de usuario de la pestaña Diagnóstico.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Diagnostics_UI {

	public static function render_tab() {
		if ( ! current_user_can( 'manage_options' ) || ! Premiero_Diagnostics::is_enabled() ) {
			return;
		}
		$nonce    = wp_create_nonce( Premiero_Diagnostics::NONCE_ACTION );
		$history  = Premiero_Diagnostics::get_history();
		$temp_log = Premiero_Diagnostics::temp_log_status();
		$wp_ver   = get_bloginfo( 'version' );
		$php_ver  = PHP_VERSION;

		// Resumen de entorno para "Información básica del sistema".
		// Sólo APIs/constantes nativas: sin consultas pesadas ni operaciones costosas.
		$env_type  = wp_get_environment_type();
		$is_prod   = ( 'production' === $env_type );
		$is_multi  = is_multisite();
		$is_https  = is_ssl();
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $site_host ) {
			$site_host = wp_parse_url( site_url(), PHP_URL_HOST );
		}

		$php_memory = ini_get( 'memory_limit' );
		$max_exec   = ini_get( 'max_execution_time' );
		$upload_max = ini_get( 'upload_max_filesize' );
		$post_max   = ini_get( 'post_max_size' );

		$wp_mem     = defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '';
		$wp_max_mem = defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '';
		$wp_memory  = ( '' !== $wp_mem || '' !== $wp_max_mem )
			? ( ( '' !== $wp_mem ? $wp_mem : 'N/D' ) . ' / ' . ( '' !== $wp_max_mem ? $wp_max_mem : 'N/D' ) )
			: 'N/D';

		$debug         = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$debug_log     = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
		$debug_display = defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;
		$wp_cache      = defined( 'WP_CACHE' ) && WP_CACHE;

		global $wpdb;
		$db_version = ( $wpdb instanceof wpdb ) ? (string) $wpdb->db_version() : '';
		$db_label   = ( false !== stripos( $db_version, 'mariadb' ) ) ? 'MariaDB' : 'MySQL';
		$db_value   = preg_replace( '/-.*$/', '', $db_version );
		$db_value   = ( '' !== $db_value ) ? $db_value : 'N/D';

		$server_name = 'N/D';
		if ( isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] ) ) {
			$server_soft = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
			if ( '' !== $server_soft ) {
				$server_map = array(
					'litespeed' => 'LiteSpeed',
					'nginx'     => 'nginx',
					'apache'    => 'Apache',
					'iis'       => 'IIS',
					'caddy'     => 'Caddy',
				);
				foreach ( $server_map as $needle => $label ) {
					if ( false !== stripos( $server_soft, $needle ) ) {
						$server_name = $label;
						break;
					}
				}
				if ( 'N/D' === $server_name ) {
					$server_token = preg_split( '/[\s\/]+/', $server_soft );
					$server_name  = ! empty( $server_token[0] ) ? $server_token[0] : 'N/D';
				}
			}
		}

		// El visor lee siempre wp-content/debug.log (independiente de WP_DEBUG_LOG).
		$log_path = Premiero_Diagnostics::temp_log_path();
		$log_view = '';
		if ( file_exists( $log_path ) && (int) @filesize( $log_path ) <= Premiero_Diagnostics::MAX_LOG_VIEW_BYTES ) {
			$log_view = Premiero_Diagnostics::render_diagnostic_html(
				Premiero_Diagnostics::get_tool( 'debug_log' ),
				Premiero_Diagnostics::diag_debug_log()
			);
		}
		?>
		<div class="premiero-diagnostics" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<div class="notice notice-warning inline">
				<p><strong>Herramientas avanzadas.</strong> Esta sección permite ejecutar comprobaciones y reparaciones que pueden modificar la instalación. Úsala únicamente si sabes lo que estás haciendo. Ningún escaneo ni reparación se ejecuta de forma automática.</p>
			</div>

			<style>
			.premiero-diagnostics .premiero-diag-2col{display:grid;grid-template-columns:minmax(0,35%) minmax(0,1fr);gap:16px;align-items:start;margin:14px 0}
			.premiero-diagnostics .premiero-diag-2col-even{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
			.premiero-diagnostics .premiero-diag-2col-repair{grid-template-columns:minmax(0,42%) minmax(0,1fr)}
			.premiero-diagnostics .premiero-diag-col{min-width:0}
			.premiero-diagnostics .premiero-diag-col-tools{display:flex;flex-direction:column;gap:8px;padding-right:2px}
			.premiero-diagnostics .premiero-tool-item{display:flex;flex-direction:column;gap:4px;width:100%;text-align:left;padding:10px 12px;border:1px solid #dcdcde;border-left:3px solid #dcdcde;border-radius:6px;background:#fff;cursor:pointer;box-sizing:border-box}
			.premiero-diagnostics .premiero-tool-item strong{color:#1d2327;font-size:13px}
			.premiero-diagnostics .premiero-tool-item span{color:#646970;font-size:12px}
			.premiero-diagnostics .premiero-tool-item:hover{border-color:#8c8f94;background:#f6f7f7}
			.premiero-diagnostics .premiero-tool-item:focus{outline:2px solid var(--premiero-admin-accent,#8a2c0d);outline-offset:-2px}
			.premiero-diagnostics .premiero-tool-item.is-active{border-left-color:var(--premiero-admin-accent,#8a2c0d)}
			.premiero-diagnostics .premiero-tool-item.is-active strong{color:var(--premiero-admin-accent,#8a2c0d)}
			.premiero-diagnostics .premiero-tool-item.is-loading{opacity:.6}
			.premiero-diagnostics .premiero-diag-panel{border:1px solid #dcdcde;border-radius:6px;background:#fff;padding:12px 14px;max-height:min(72vh,640px);overflow:auto}
			.premiero-diagnostics .premiero-diag-panel-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px}
			.premiero-diagnostics .premiero-diag-placeholder{margin:0;color:#646970;font-style:italic}
			.premiero-diagnostics h3{margin:22px 0 8px}
			.premiero-diagnostics .premiero-diag-preview,.premiero-diagnostics .premiero-diag-php-out{margin-top:10px}
			.premiero-diagnostics #premiero-php-out{max-height:min(60vh,480px);overflow:auto}
			.premiero-diagnostics #premiero-php-out .premiero-diag-php{max-height:none;overflow:visible}
			.premiero-diagnostics .premiero-diag-hint{margin:8px 0 10px;padding:0;border:0;background:none;color:#646970;font-size:12px;line-height:1.5}
			.premiero-diagnostics .premiero-repair-accordion{display:flex;flex-direction:column;gap:8px}
			.premiero-diagnostics .premiero-repair-item{border:1px solid #dcdcde;border-radius:6px;background:#fff;overflow:hidden}
			.premiero-diagnostics .premiero-repair-item .premiero-tool-item{border:0;border-left:3px solid #dcdcde;border-radius:0}
			.premiero-diagnostics .premiero-repair-item.is-open .premiero-tool-item{border-left-color:var(--premiero-admin-accent,#8a2c0d);background:#f6f7f7}
			.premiero-diagnostics .premiero-repair-body{border-top:1px solid #f0f0f1;padding:12px 14px}
			.premiero-diagnostics .premiero-repair-body .premiero-diag-preview{margin-top:0}
			.premiero-diagnostics .premiero-diag-result{border:1px solid #dcdcde;border-radius:6px;padding:12px 14px;background:#fff;margin:10px 0}
			.premiero-diagnostics .premiero-diag-panel .premiero-diag-result{border:0;padding:0;margin:0}
			.premiero-diagnostics .premiero-diag-summary{margin:0 0 8px;font-weight:600}
			.premiero-diagnostics .premiero-diag-items{list-style:none;margin:0;padding:0}
			.premiero-diagnostics .premiero-diag-items li{padding:6px 0;border-bottom:1px solid #f0f0f1;font-size:13px;overflow-wrap:anywhere}
			.premiero-diagnostics .premiero-diag-items li:last-child{border-bottom:0}
			.premiero-diagnostics .premiero-diag-status-ok::before{content:"✓ ";color:#00a32a;font-weight:700}
			.premiero-diagnostics .premiero-diag-status-warning::before{content:"⚠ ";color:#dba617;font-weight:700}
			.premiero-diagnostics .premiero-diag-status-problem::before{content:"✕ ";color:#d63638;font-weight:700}
			.premiero-diagnostics .premiero-diag-status-info::before{content:"ℹ ";color:#2271b1;font-weight:700}
			.premiero-diagnostics .premiero-diag-count.ok{color:#00a32a}
			.premiero-diagnostics .premiero-diag-count.warning{color:#b26f00}
			.premiero-diagnostics .premiero-diag-count.problem{color:#d63638}
			.premiero-diagnostics .premiero-diag-php{background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:12px;max-height:min(72vh,640px);overflow:auto;white-space:pre-wrap;font-family:monospace;margin:0}
			.premiero-diagnostics .premiero-hist-panel{border:1px solid #dcdcde;border-radius:8px;background:#fff;padding:12px 14px;min-width:0}
			.premiero-diagnostics .premiero-hist-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 4px}
			.premiero-diagnostics .premiero-hist-title{font-size:14px;font-weight:600;color:#1d2327;letter-spacing:.01em}
			.premiero-diagnostics .premiero-hist-desc{margin:0 0 10px;font-size:11px;line-height:1.5;color:#646970}
			.premiero-diagnostics .premiero-diag-history{list-style:none;margin:0;padding:0;border-top:1px solid #f0f0f1}
			.premiero-diagnostics .premiero-diag-history li{display:flex;flex-direction:column;gap:2px;padding:8px 2px;border-bottom:1px solid #f0f0f1}
			.premiero-diagnostics .premiero-diag-history li:last-child{border-bottom:0}
			.premiero-diagnostics .premiero-history-tool{font-size:12px;font-weight:600;color:#1d2327;overflow-wrap:anywhere}
			.premiero-diagnostics .premiero-history-summary{font-size:12px;color:#3c434a;overflow-wrap:anywhere}
			.premiero-diagnostics .premiero-history-meta{font-family:"IBM Plex Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11px;color:#646970}
			.premiero-diagnostics .premiero-report-wrap{display:flex;flex-direction:column;gap:6px}
			.premiero-diagnostics .premiero-report-tag{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#646970}
			.premiero-diagnostics #premiero-report-text{margin:0;min-height:min(42vh,240px);max-height:min(60vh,440px);padding:10px 12px;border:1px solid #dcdcde;border-radius:6px;background:#f6f7f7;color:#1d2327;font-family:"IBM Plex Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px;line-height:1.6;box-shadow:none;resize:vertical}
			.premiero-diagnostics #premiero-report-text:focus{border-color:var(--premiero-admin-accent,#8a2c0d);box-shadow:0 0 0 1px var(--premiero-admin-accent,#8a2c0d);outline:0}
			.premiero-diagnostics .premiero-scroll{max-height:min(60vh,420px);overflow:auto}
			.premiero-diagnostics .premiero-log-controls{border:1px solid #dcdcde;border-radius:8px;background:#fff;padding:14px 16px;margin:10px 0}
			.premiero-diagnostics .premiero-log-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:0 0 12px}
			.premiero-diagnostics .premiero-log-title{font-size:15px;font-weight:600;color:#1d2327;letter-spacing:.01em}
			.premiero-diagnostics .premiero-log-state{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600}
			.premiero-diagnostics .premiero-log-state.is-on{background:#edfaef;color:#007017;border:1px solid #b8e6bf}
			.premiero-diagnostics .premiero-log-state.is-off{background:#f0f0f1;color:#646970;border:1px solid #dcdcde}
			.premiero-diagnostics .premiero-log-state.is-warn{background:#fcf9e8;color:#8a5b00;border:1px solid #f0d9a8}
			.premiero-diagnostics .premiero-log-actions{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}
			.premiero-diagnostics .premiero-log-status{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:8px;margin:0}
			.premiero-diagnostics .premiero-log-status-item{display:flex;flex-direction:column;gap:3px;min-width:0;padding:8px 10px;border:1px solid #f0f0f1;border-radius:6px;background:#f6f7f7}
			.premiero-diagnostics .premiero-log-status-item.is-wide{grid-column:1/-1}
			.premiero-diagnostics .premiero-log-status-label{font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#646970}
			.premiero-diagnostics .premiero-log-status-value{font-size:13px;font-weight:600;color:#1d2327;letter-spacing:.02em;text-transform:uppercase;overflow-wrap:anywhere}
			.premiero-diagnostics .premiero-log-status-value.is-ok{color:#007017}
			.premiero-diagnostics .premiero-log-status-value.is-bad{color:#8a5b00}
			.premiero-diagnostics code.premiero-log-status-value{font-family:"IBM Plex Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;font-weight:600;letter-spacing:0;text-transform:none}
			.premiero-diagnostics .premiero-log-note{margin:8px 0 0;font-size:11px;color:#646970}
			.premiero-diagnostics .premiero-log-view{margin-top:10px;max-height:min(72vh,640px);overflow:auto}
			.premiero-diagnostics .premiero-term{border:1px solid #1d2327;border-radius:8px;overflow:hidden;background:#0f1115}
			.premiero-diagnostics .premiero-term-bar{display:flex;align-items:center;gap:8px;padding:7px 12px;background:#1d2327;border-bottom:1px solid #2c3338}
			.premiero-diagnostics .premiero-term-dots{display:inline-flex;gap:6px}
			.premiero-diagnostics .premiero-term-dots i{display:block;width:10px;height:10px;border-radius:50%;background:#5c6570}
			.premiero-diagnostics .premiero-term-dots i:nth-child(1){background:#ff5f57}
			.premiero-diagnostics .premiero-term-dots i:nth-child(2){background:#febc2e}
			.premiero-diagnostics .premiero-term-dots i:nth-child(3){background:#28c840}
			.premiero-diagnostics .premiero-term-title{font-family:"IBM Plex Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#c3c4c7;font-size:11px;font-weight:600;letter-spacing:.04em}
			.premiero-diagnostics .premiero-term-body{display:flex;align-items:flex-start;gap:8px;padding:12px 14px;background:#0f1115}
			.premiero-diagnostics .premiero-term-prompt{flex:none;padding-top:1px;color:#4ade80;font-family:"IBM Plex Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;font-weight:600;line-height:1.6;user-select:none}
			.premiero-diagnostics .premiero-term-body #premiero-php-code{flex:1 1 auto;width:100%;min-width:0;min-height:180px;max-height:min(60vh,480px);margin:0;padding:0;border:0;border-radius:0;background:transparent;color:#e6edf3;box-shadow:none;outline:none;resize:vertical;font-family:"IBM Plex Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;line-height:1.6;caret-color:#4ade80;tab-size:4}
			.premiero-diagnostics .premiero-term-body #premiero-php-code:focus{border:0;box-shadow:none;outline:none;color:#e6edf3}
			.premiero-diagnostics .premiero-term-body #premiero-php-code::placeholder{color:#6b7480}
			.premiero-diagnostics .premiero-term-body #premiero-php-code::selection{background:#264f78;color:#fff}
			.premiero-diagnostics #premiero-php-out:not(:empty)::before{content:"SALIDA";display:block;margin:0 0 4px;color:#646970;font-size:10px;font-weight:600;letter-spacing:.08em}
			.premiero-diagnostics #premiero-php-out .premiero-diag-php{background:#0f1115;border:1px solid #1d2327;border-radius:8px;padding:12px 14px;color:#e6edf3;font-family:"IBM Plex Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px;line-height:1.6;white-space:pre-wrap}
			.premiero-diagnostics textarea{width:100%;font-family:monospace}
			.premiero-diagnostics .premiero-term-actions{margin-left:auto;display:inline-flex;gap:6px}
			.premiero-diagnostics .premiero-term-btn{appearance:none;-webkit-appearance:none;margin:0;padding:3px 9px;border:1px solid #2c3338;border-radius:7px;background:#171b22;color:#9aa4b2;font-family:"IBM Plex Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11px;font-weight:600;line-height:1.6;letter-spacing:.02em;cursor:pointer}
			.premiero-diagnostics .premiero-term-btn:hover,.premiero-diagnostics .premiero-term-btn:focus{border-color:#3f4954;background:#20262e;color:#e6edf3;outline:0;box-shadow:none}
			.premiero-diagnostics .premiero-term-btn:focus-visible{outline:2px solid #4ade80;outline-offset:1px}
			.premiero-diagnostics .premiero-diag-info{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:0;align-content:start}
			.premiero-diagnostics .premiero-diag-info .premiero-log-status-value{text-transform:none}
			.premiero-diagnostics .premiero-diag-info .premiero-log-status-item{min-width:0}
			.premiero-diagnostics .premiero-diag-group-label{grid-column:1/-1;margin:6px 0 0;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#646970}
			.premiero-diagnostics .premiero-diag-group-label:first-child{margin-top:0}
			.premiero-diagnostics .premiero-diag-span-2{grid-column:span 2}
			.premiero-diagnostics .premiero-diag-span-all{grid-column:1/-1}
			.premiero-diagnostics .premiero-diag-meta{font-size:11px;font-weight:600;color:#646970;overflow-wrap:anywhere}
			.premiero-diagnostics .premiero-diag-info code.premiero-log-status-value{font-size:12px;line-height:1.5;white-space:normal;overflow-wrap:anywhere;word-break:break-word}
			@media screen and (max-width:1180px){
				.premiero-diagnostics .premiero-diag-info{grid-template-columns:repeat(2,minmax(0,1fr))}
				.premiero-diagnostics .premiero-diag-span-2{grid-column:1/-1}
			}
			@media screen and (max-width:640px){
				.premiero-diagnostics .premiero-diag-info{grid-template-columns:1fr}
			}
			@media screen and (max-width:782px){
				.premiero-diagnostics .premiero-diag-2col,.premiero-diagnostics .premiero-diag-2col-even,.premiero-diagnostics .premiero-diag-2col-repair{grid-template-columns:1fr}
				.premiero-diagnostics .premiero-diag-panel{max-height:none}
			}
			/* Modo oscuro del Toolkit: reutiliza body.premiero-admin-dark y sus variables. */
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-panel,
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-result,
			body.premiero-admin-dark .premiero-diagnostics .premiero-tool-item,
			body.premiero-admin-dark .premiero-diagnostics .premiero-repair-item,
			body.premiero-admin-dark .premiero-diagnostics .premiero-log-controls{border-color:var(--premiero-admin-border,#374151);background:var(--premiero-admin-surface,#1f2937);color:var(--premiero-admin-text,#f3f4f6)}
			body.premiero-admin-dark .premiero-diagnostics .premiero-tool-item:hover,
			body.premiero-admin-dark .premiero-diagnostics .premiero-repair-item.is-open .premiero-tool-item{border-color:var(--premiero-admin-border,#374151);background:var(--premiero-admin-canvas,#111827)}
			body.premiero-admin-dark .premiero-diagnostics .premiero-log-status-item{border-color:var(--premiero-admin-border,#374151);background:var(--premiero-admin-canvas,#111827)}
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-php{border-color:var(--premiero-admin-border,#374151);background:var(--premiero-admin-canvas,#111827);color:var(--premiero-admin-text,#f3f4f6)}
			body.premiero-admin-dark .premiero-diagnostics .premiero-repair-body,
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-items li,
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-history li{border-color:var(--premiero-admin-border,#374151)}
			body.premiero-admin-dark .premiero-diagnostics .premiero-tool-item strong,
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-items strong,
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-summary,
			body.premiero-admin-dark .premiero-diagnostics .premiero-log-title,
			body.premiero-admin-dark .premiero-diagnostics .premiero-log-status-value{color:var(--premiero-admin-text,#f3f4f6)}
			body.premiero-admin-dark .premiero-diagnostics .premiero-tool-item span,
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-placeholder,
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-hint,
			body.premiero-admin-dark .premiero-diagnostics .premiero-log-note,
			body.premiero-admin-dark .premiero-diagnostics .premiero-log-status-label{color:var(--premiero-admin-muted,#b6c0ce)}
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-group-label,
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-meta{color:var(--premiero-admin-muted,#b6c0ce)}
			body.premiero-admin-dark .premiero-diagnostics .premiero-log-state.is-on{background:rgba(74,222,128,.14);border-color:rgba(74,222,128,.4);color:#4ade80}
			body.premiero-admin-dark .premiero-diagnostics .premiero-log-state.is-off{background:var(--premiero-admin-canvas,#111827);border-color:var(--premiero-admin-border,#374151);color:var(--premiero-admin-muted,#b6c0ce)}
			body.premiero-admin-dark .premiero-diagnostics .premiero-log-state.is-warn{background:rgba(251,191,36,.14);border-color:rgba(251,191,36,.4);color:#fbbf24}
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-status-ok::before,
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-count.ok,
			body.premiero-admin-dark .premiero-diagnostics .premiero-log-status-value.is-ok{color:#4ade80}
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-status-warning::before,
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-count.warning,
			body.premiero-admin-dark .premiero-diagnostics .premiero-log-status-value.is-bad{color:#fbbf24}
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-status-problem::before,
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-count.problem{color:#f87171}
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-status-info::before{color:#60a5fa}
			body.premiero-admin-dark .premiero-diagnostics .premiero-hist-panel{border-color:var(--premiero-admin-border,#374151);background:var(--premiero-admin-surface,#1f2937);color:var(--premiero-admin-text,#f3f4f6)}
			body.premiero-admin-dark .premiero-diagnostics .premiero-hist-title{color:var(--premiero-admin-text,#f3f4f6)}
			body.premiero-admin-dark .premiero-diagnostics .premiero-hist-desc,
			body.premiero-admin-dark .premiero-diagnostics .premiero-report-tag{color:var(--premiero-admin-muted,#b6c0ce)}
			body.premiero-admin-dark .premiero-diagnostics .premiero-diag-history{border-color:var(--premiero-admin-border,#374151)}
			body.premiero-admin-dark .premiero-diagnostics .premiero-history-tool,
			body.premiero-admin-dark .premiero-diagnostics .premiero-history-summary{color:var(--premiero-admin-text,#f3f4f6)}
			body.premiero-admin-dark .premiero-diagnostics .premiero-history-meta{color:var(--premiero-admin-muted,#b6c0ce)}
			body.premiero-admin-dark .premiero-diagnostics #premiero-report-text{border-color:var(--premiero-admin-border,#374151);background:var(--premiero-admin-canvas,#111827);color:var(--premiero-admin-text,#f3f4f6)}
			</style>

			<div class="premiero-diag-2col premiero-diag-2col-even">
			<section class="premiero-diag-col">
				<h3>Información básica del sistema</h3>
				<?php
				$info_groups = array(
					'Sistema'  => array(
						array(
							'label'      => 'WordPress',
							'value'      => $wp_ver,
							'meta'       => $env_type . ' · ' . ( $is_multi ? 'multisite' : 'single site' ),
							'card_class' => 'premiero-diag-span-2',
						),
						array(
							'label' => 'Dominio',
							'value' => $site_host ? $site_host : 'N/D',
						),
					),
					'Servidor' => array(
						array( 'label' => 'PHP', 'value' => $php_ver ),
						array( 'label' => $db_label, 'value' => $db_value ),
						array( 'label' => 'Servidor web', 'value' => $server_name ),
						array( 'label' => 'HTTPS', 'value' => $is_https ? 'Sí' : 'No', 'value_class' => $is_https ? 'is-ok' : '' ),
					),
					'Recursos' => array(
						array( 'label' => 'Memoria PHP', 'value' => $php_memory ? $php_memory : 'N/D' ),
						array( 'label' => 'WP Memory', 'value' => $wp_memory ),
						array( 'label' => 'Max execution', 'value' => ( '' !== $max_exec ? $max_exec . ' s' : 'N/D' ) ),
						array( 'label' => 'Upload max', 'value' => $upload_max ? $upload_max : 'N/D' ),
						array( 'label' => 'Post max', 'value' => $post_max ? $post_max : 'N/D' ),
					),
					'Debug'    => array(
						array( 'label' => 'WP_DEBUG', 'value' => $debug ? 'ON' : 'OFF', 'value_class' => ( $debug && $is_prod ) ? 'is-bad' : '' ),
						array( 'label' => 'Debug log', 'value' => $debug_log ? 'ON' : 'OFF', 'value_class' => $debug_log ? 'is-ok' : '' ),
						array( 'label' => 'Debug display', 'value' => $debug_display ? 'ON' : 'OFF', 'value_class' => ( $debug_display && $is_prod ) ? 'is-bad' : '' ),
						array( 'label' => 'WP_CACHE', 'value' => $wp_cache ? 'ON' : 'OFF', 'value_class' => $wp_cache ? 'is-ok' : '' ),
					),
				);
				$info_paths = array(
					array( 'label' => 'ABSPATH', 'value' => ABSPATH ),
					array( 'label' => 'WP_CONTENT_DIR', 'value' => WP_CONTENT_DIR ),
				);
				?>
				<div class="premiero-diag-info">
					<?php foreach ( $info_groups as $group_name => $group_cards ) : ?>
						<span class="premiero-diag-group-label"><?php echo esc_html( $group_name ); ?></span>
						<?php foreach ( $group_cards as $card ) : ?>
							<div class="premiero-log-status-item<?php echo ! empty( $card['card_class'] ) ? ' ' . esc_attr( $card['card_class'] ) : ''; ?>">
								<span class="premiero-log-status-label"><?php echo esc_html( $card['label'] ); ?></span>
								<span class="premiero-log-status-value<?php echo ! empty( $card['value_class'] ) ? ' ' . esc_attr( $card['value_class'] ) : ''; ?>"><?php echo esc_html( $card['value'] ); ?></span>
								<?php if ( ! empty( $card['meta'] ) ) : ?>
									<span class="premiero-diag-meta"><?php echo esc_html( $card['meta'] ); ?></span>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					<?php endforeach; ?>
					<span class="premiero-diag-group-label">Rutas</span>
					<?php foreach ( $info_paths as $path_card ) : ?>
						<div class="premiero-log-status-item premiero-diag-span-all">
							<span class="premiero-log-status-label"><?php echo esc_html( $path_card['label'] ); ?></span>
							<code class="premiero-log-status-value"><?php echo esc_html( $path_card['value'] ); ?></code>
						</div>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="premiero-diag-col">
				<h3>Visor de logs</h3>
				<div class="premiero-log-controls">
					<div class="premiero-log-head">
						<span class="premiero-log-title">Registro temporal de errores</span>
						<span id="premiero-log-state" class="premiero-log-state is-<?php echo esc_attr( $temp_log['state'] ); ?>"><?php echo esc_html( $temp_log['label'] ); ?></span>
					</div>
					<div class="premiero-log-actions">
						<button type="button" class="button" id="premiero-log-toggle" data-enabled="<?php echo $temp_log['enabled'] ? '1' : '0'; ?>"><?php echo esc_html( $temp_log['enabled'] ? 'Desactivar' : 'Activar' ); ?></button>
						<button type="button" class="button" id="premiero-log-refresh">Actualizar log</button>
						<button type="button" class="button" id="premiero-log-test"<?php echo $temp_log['enabled'] ? '' : ' disabled'; ?>>Generar entrada de prueba</button>
						<button type="button" class="button" id="premiero-log-clear">Vaciar log</button>
					</div>
					<div class="premiero-log-status">
						<div class="premiero-log-status-item">
							<span class="premiero-log-status-label">Estado</span>
							<span id="premiero-tl-enabled" class="premiero-log-status-value<?php echo $temp_log['active'] ? ' is-ok' : ( $temp_log['enabled'] ? ' is-bad' : '' ); ?>"><?php echo esc_html( $temp_log['enabled'] ? 'Activado' : 'Desactivado' ); ?></span>
						</div>
						<div class="premiero-log-status-item">
							<span class="premiero-log-status-label">log_errors</span>
							<span id="premiero-tl-logerrors" class="premiero-log-status-value<?php echo $temp_log['log_errors'] ? ' is-ok' : ' is-bad'; ?>"><?php echo esc_html( $temp_log['log_errors'] ? 'activo' : 'inactivo' ); ?></span>
						</div>
						<div class="premiero-log-status-item">
							<span class="premiero-log-status-label">Archivo</span>
							<span id="premiero-tl-file" class="premiero-log-status-value<?php echo $temp_log['file_exists'] ? ' is-ok' : ' is-bad'; ?>"><?php echo esc_html( $temp_log['file_exists'] ? 'existe' : 'no existe' ); ?></span>
						</div>
						<div class="premiero-log-status-item">
							<span class="premiero-log-status-label">Escritura</span>
							<span id="premiero-tl-writable" class="premiero-log-status-value<?php echo $temp_log['file_writable'] ? ' is-ok' : ' is-bad'; ?>"><?php echo esc_html( $temp_log['file_writable'] ? 'sí' : 'no' ); ?></span>
						</div>
						<div class="premiero-log-status-item is-wide">
							<span class="premiero-log-status-label">Destino</span>
							<code id="premiero-tl-dest" class="premiero-log-status-value">wp-content/debug.log</code>
						</div>
					</div>
					<?php if ( $temp_log['wp_debug_log'] ) : ?>
					<p class="premiero-log-note">WP_DEBUG_LOG ya está definido en <code>wp-config.php</code> (mecanismo independiente).</p>
					<?php endif; ?>
				</div>
				<div id="premiero-log-notice"></div>
				<div id="premiero-log-view" class="premiero-log-view"><?php echo $log_view; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML ya escapado en render_diagnostic_html(). ?></div>
			</section>
			</div>
			<section>
				<h3>Diagnósticos</h3>
				<p class="description">Ejecuta cada comprobación bajo demanda. Los escaneos no se lanzan automáticamente y el resultado se muestra a la derecha.</p>
				<?php $diag_tools = array_filter( Premiero_Diagnostics::tools(), function ( $t ) { return 'diagnostic' === $t['type']; } ); ?>
				<div class="premiero-diag-2col">
					<div class="premiero-diag-col premiero-diag-col-tools">
						<?php foreach ( $diag_tools as $tool ) : ?>
							<button type="button" class="premiero-tool-item premiero-diag-run" data-tool="<?php echo esc_attr( $tool['id'] ); ?>">
								<strong><?php echo esc_html( $tool['name'] ); ?></strong>
								<span><?php echo esc_html( $tool['description'] ); ?></span>
							</button>
						<?php endforeach; ?>
					</div>
					<div class="premiero-diag-col">
						<div id="premiero-diag-result-panel" class="premiero-diag-panel">
							<p class="premiero-diag-placeholder">Selecciona un diagnóstico de la izquierda para ver aquí su resultado.</p>
						</div>
					</div>
				</div>
			</section>

			<section>
				<div class="premiero-diag-2col premiero-diag-2col-repair">
					<div class="premiero-diag-col">
						<h3>Reparaciones</h3>
						<p class="description">Analiza primero, revisa la vista previa y confirma explícitamente antes de aplicar cualquier cambio. Nada se ejecuta de forma destructiva automática.</p>
						<?php $repair_tools = array_filter( Premiero_Diagnostics::tools(), function ( $t ) { return 'repair' === $t['type']; } ); ?>
						<div class="premiero-repair-accordion">
							<?php foreach ( $repair_tools as $tool ) : ?>
								<div class="premiero-repair-item" data-tool="<?php echo esc_attr( $tool['id'] ); ?>">
									<button type="button" class="premiero-tool-item premiero-repair-analyze" data-tool="<?php echo esc_attr( $tool['id'] ); ?>" aria-expanded="false">
										<strong><?php echo esc_html( $tool['name'] ); ?></strong>
										<span><?php echo esc_html( $tool['description'] ); ?></span>
									</button>
									<div class="premiero-repair-body" style="display:none;">
										<div class="premiero-repair-preview premiero-diag-preview"></div>
										<div class="premiero-repair-actions" style="margin-top:10px;display:none;">
											<button type="button" class="button button-primary premiero-repair-apply" disabled>Confirmar y ejecutar</button>
										</div>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="premiero-diag-col">
						<h3>Consola PHP personalizada</h3>
						<div class="premiero-term">
							<div class="premiero-term-bar">
								<span class="premiero-term-dots" aria-hidden="true"><i></i><i></i><i></i></span>
								<span class="premiero-term-title">Premiero PHP Console</span>
								<span class="premiero-term-actions">
									<button type="button" class="premiero-term-btn" id="premiero-php-clear-code">Limpiar editor</button>
									<button type="button" class="premiero-term-btn" id="premiero-php-clear-out">Limpiar salida</button>
								</span>
							</div>
							<div class="premiero-term-body">
								<span class="premiero-term-prompt" aria-hidden="true">&gt;_</span>
								<textarea id="premiero-php-code" rows="14" spellcheck="false" placeholder="// Escribe aquí PHP, por ejemplo:&#10;echo get_bloginfo( 'name' );"><?php echo esc_textarea( "// Ejemplo:\necho 'Hola desde la consola';" ); ?></textarea>
							</div>
						</div>
						<p class="premiero-diag-hint">⚠ PHP avanzado · Sin sandbox · El código puede modificar la instalación y no se guarda.</p>
						<p>
							<label>
								<input type="checkbox" id="premiero-php-confirm" value="1">
								Entiendo que no existe sandbox y que el código se ejecutará una única vez.
							</label>
						</p>
						<button type="button" class="button button-primary" id="premiero-php-run" disabled>Ejecutar una vez</button>
						<div id="premiero-php-out" class="premiero-diag-php-out"></div>
					</div>
				</div>
			</section>

			<section>
				<h3>Historial e informe</h3>
				<div class="premiero-diag-2col premiero-diag-2col-even">
					<div class="premiero-diag-col">
						<div class="premiero-hist-panel">
							<div class="premiero-hist-head">
								<span class="premiero-hist-title">Historial</span>
								<button type="button" class="button" id="premiero-history-clear">Limpiar historial</button>
							</div>
							<p class="premiero-hist-desc">Últimas ejecuciones registradas.</p>
							<ul id="premiero-diag-history" class="premiero-diag-history premiero-scroll">
								<?php foreach ( $history as $entry ) : ?>
									<li>
										<strong class="premiero-history-tool"><?php echo esc_html( isset( $entry['tool'] ) ? $entry['tool'] : '' ); ?></strong>
										<span class="premiero-history-summary"><?php echo esc_html( isset( $entry['summary'] ) ? $entry['summary'] : '' ); ?></span>
										<small class="premiero-history-meta"><?php echo esc_html( isset( $entry['user'] ) ? $entry['user'] : '' ); ?> · <?php echo esc_html( isset( $entry['time'] ) ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ), 'd/m/Y H:i' ) : '' ); ?></small>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					</div>
					<div class="premiero-diag-col">
						<div class="premiero-hist-panel">
							<div class="premiero-hist-head">
								<span class="premiero-hist-title">Informe</span>
								<button type="button" class="button button-primary" id="premiero-report-copy">Copiar informe</button>
							</div>
							<p class="premiero-hist-desc">Resumen copiable del estado y acciones recientes.</p>
							<div class="premiero-report-wrap">
								<span class="premiero-report-tag">Informe generado</span>
								<textarea id="premiero-report-text" rows="14" readonly placeholder="Pulsa «Copiar informe» para generar el resumen más reciente."></textarea>
							</div>
						</div>
					</div>
				</div>
			</section>
			<script>
			jQuery(function($){
				var root = $('.premiero-diagnostics');
				var nonce = root.data('nonce');
				var reportEnv = { wordpress: <?php echo wp_json_encode( $wp_ver ); ?>, php: <?php echo wp_json_encode( $php_ver ); ?> };
				var reportLines = {};
				var reportActions = <?php echo wp_json_encode( Premiero_Diagnostics::report_action_lines() ); ?>;
				var currentRepair = '';

				function escapeHtml(s){ return $('<div>').text(s == null ? '' : String(s)).html(); }

				function request(data, cb){
					data.nonce = nonce;
					$.post(ajaxurl, data).done(function(res){
						if (res && res.success) { cb(res.data, null); }
						else { cb(null, (res && res.data && res.data.message) || 'Error desconocido'); }
					}).fail(function(xhr){ cb(null, 'Error de red (' + xhr.status + ')'); });
				}

				function setResult(html){ $('#premiero-diag-result-panel').html(html); }
				function setActiveTool(scope, btn){ $(scope).removeClass('is-active'); if (btn) btn.addClass('is-active'); }

				function prependHistory(entry){
					if (!entry || !entry.time) return;
					var line = '<li>'
						+ '<strong class="premiero-history-tool">' + escapeHtml(entry.tool || '') + '</strong>'
						+ '<span class="premiero-history-summary">' + escapeHtml(entry.summary || '') + '</span>'
						+ '<small class="premiero-history-meta">' + escapeHtml(entry.user || '') + ' · ' + new Date(entry.time * 1000).toLocaleString() + '</small>'
						+ '</li>';
					$('#premiero-diag-history').prepend(line);
				}

				// Sección "ACCIONES / REPARACIONES RECIENTES" del informe: sólo las
				// últimas 10 acciones que modificaron o intentaron modificar el sitio.
				function pushActionLine(line){
					if (!line) return;
					reportActions.push(line);
					while (reportActions.length > 10) { reportActions.shift(); }
				}

				$('.premiero-diag-run').on('click', function(){
					var btn = $(this);
					var tool = btn.data('tool');
					setActiveTool('.premiero-diag-run', btn);
					btn.prop('disabled', true).addClass('is-loading');
					request({ action: '<?php echo esc_js( Premiero_Diagnostics::AJAX_RUN_DIAG ); ?>', tool: tool }, function(data, err){
						btn.prop('disabled', false).removeClass('is-loading');
						if (err) { setResult('<div class="notice notice-error inline"><p>' + escapeHtml(err) + '</p></div>'); return; }
						setResult(data.html);
						if (data.summary) reportLines[tool] = data.summary;
						if (data.env && data.env.wordpress) reportEnv.wordpress = data.env.wordpress;
						if (data.env && data.env.php) reportEnv.php = data.env.php;
						prependHistory(data.history);
					});
				});

				function closeRepairItem(item){
					if (!item || !item.length) { return; }
					item.removeClass('is-open');
					item.find('.premiero-repair-analyze').attr('aria-expanded', 'false');
					item.find('.premiero-repair-body').stop(true, true).slideUp(120, function(){
						$(this).find('.premiero-repair-preview').empty();
						$(this).find('.premiero-repair-actions').hide();
					});
				}

				$('.premiero-repair-analyze').on('click', function(){
					var btn = $(this);
					var item = btn.closest('.premiero-repair-item');
					if (item.hasClass('is-open')) { closeRepairItem(item); return; }
					$('.premiero-repair-item.is-open').each(function(){ closeRepairItem($(this)); });
					currentRepair = btn.data('tool');
					setActiveTool('.premiero-repair-analyze', btn);
					btn.prop('disabled', true).addClass('is-loading');
					request({ action: '<?php echo esc_js( Premiero_Diagnostics::AJAX_RUN_REPAIR ); ?>', tool: currentRepair, run: 0 }, function(data, err){
						btn.prop('disabled', false).removeClass('is-loading');
						if (err) { alert(err); return; }
						item.addClass('is-open');
						btn.attr('aria-expanded', 'true');
						item.find('.premiero-repair-preview').html(data.html);
						item.find('.premiero-repair-actions').show().find('.premiero-repair-apply').prop('disabled', true);
						item.find('.premiero-repair-body').stop(true, true).slideDown(120);
					});
				});

				$('.premiero-repair-accordion').on('change', 'input[name="premiero-repair-option"]', function(){
					$(this).closest('.premiero-repair-item').find('.premiero-repair-apply').prop('disabled', false);
				});

				$('.premiero-repair-apply').on('click', function(){
					var btn = $(this);
					var item = btn.closest('.premiero-repair-item');
					var selected = item.find('input[name="premiero-repair-option"]:checked');
					if (!selected.length) { alert('Selecciona una opción primero.'); return; }
					btn.prop('disabled', true).text('Ejecutando…');
					request({ action: '<?php echo esc_js( Premiero_Diagnostics::AJAX_RUN_REPAIR ); ?>', tool: item.data('tool'), run: 1, params: selected.val() }, function(data, err){
						btn.prop('disabled', false).text('Confirmar y ejecutar');
						if (err) { alert(err); return; }
						item.find('.premiero-repair-preview').html(data.html);
						item.find('.premiero-repair-actions').hide();
						prependHistory(data.history);
						pushActionLine(data.action_line);
					});
				});

				var phpConfirm = $('#premiero-php-confirm');
				var phpRun = $('#premiero-php-run');
				phpConfirm.on('change', function(){ phpRun.prop('disabled', !phpConfirm.is(':checked')); });
				$('#premiero-php-clear-code').on('click', function(){
					$('#premiero-php-code').val('').trigger('focus');
				});
				$('#premiero-php-clear-out').on('click', function(){
					$('#premiero-php-out').empty();
				});
				phpRun.on('click', function(){
					if (!phpConfirm.is(':checked')) { alert('Marca la casilla de confirmación.'); return; }
					phpRun.prop('disabled', true).text('Ejecutando…');
					request({ action: '<?php echo esc_js( Premiero_Diagnostics::AJAX_RUN_PHP ); ?>', code: $('#premiero-php-code').val(), confirm: 1 }, function(data, err){
						phpRun.prop('disabled', false).text('Ejecutar una vez');
						if (err) { alert(err); return; }
						$('#premiero-php-out').html(data.html);
						prependHistory(data.history);
						pushActionLine(data.action_line);
					});
				});

				var logToggle = $('#premiero-log-toggle');
				var logState = $('#premiero-log-state');
				var logTest = $('#premiero-log-test');

				function logNotice(ok, text){
					var cls = ok ? 'notice notice-success inline' : 'notice notice-error inline';
					$('#premiero-log-notice').html('<div class="' + cls + '"><p>' + escapeHtml(text) + '</p></div>');
				}

				function applyLogStatus(data){
					if (!data) { return; }
					var on = !!data.enabled;
					var active = !!data.active;
					var state = data.state || (on ? 'on' : 'off');
					logState.text(data.label || (on ? 'Activado' : 'Desactivado')).removeClass('is-on is-off is-warn').addClass('is-' + state);
					logToggle.data('enabled', on ? 1 : 0).text(on ? 'Desactivar' : 'Activar');
					logTest.prop('disabled', !on);
					$('#premiero-tl-enabled').text(on ? 'Activado' : 'Desactivado').toggleClass('is-ok', active).toggleClass('is-bad', on && !active);
					$('#premiero-tl-logerrors').text(data.log_errors ? 'activo' : 'inactivo').toggleClass('is-ok', !!data.log_errors).toggleClass('is-bad', !data.log_errors);
					$('#premiero-tl-dest').text(data.error_path ? 'wp-content/debug.log' : '(no definido)');
					$('#premiero-tl-file').text(data.file_exists ? 'existe' : 'no existe').toggleClass('is-ok', !!data.file_exists).toggleClass('is-bad', !data.file_exists);
					$('#premiero-tl-writable').text(data.file_writable ? 'sí' : 'no').toggleClass('is-ok', !!data.file_writable).toggleClass('is-bad', !data.file_writable);
				}

				logToggle.on('click', function(){
					var target = logToggle.data('enabled') ? 0 : 1;
					logToggle.prop('disabled', true);
					request({ action: '<?php echo esc_js( Premiero_Diagnostics::AJAX_TOGGLE_LOG ); ?>', enabled: target }, function(data, err){
						logToggle.prop('disabled', false);
						if (err) { logNotice(false, err); return; }
						applyLogStatus(data);
						if (data.log_html) { $('#premiero-log-view').html(data.log_html); }
						logNotice(!!data.active, data.message + (data.details && data.details.length ? ' ' + data.details.join(' ') : ''));
					});
				});

				logTest.on('click', function(){
					logTest.prop('disabled', true);
					request({ action: '<?php echo esc_js( Premiero_Diagnostics::AJAX_TEST_LOG ); ?>' }, function(data, err){
						logTest.prop('disabled', false);
						if (err) { logNotice(false, err); return; }
						applyLogStatus(data);
						if (data.log_html) { $('#premiero-log-view').html(data.log_html); }
						logNotice(!!data.written, data.message || '');
					});
				});

				$('#premiero-log-refresh').on('click', function(){
					var btn = $(this);
					btn.prop('disabled', true);
					request({ action: '<?php echo esc_js( Premiero_Diagnostics::AJAX_READ_LOG ); ?>' }, function(data, err){
						btn.prop('disabled', false);
						if (err) { $('#premiero-log-view').html('<div class="notice notice-error inline"><p>' + escapeHtml(err) + '</p></div>'); return; }
						$('#premiero-log-view').html(data.html);
					});
				});

				$('#premiero-log-clear').on('click', function(){
					if (!confirm('¿Vaciar wp-content/debug.log? Se perderá su contenido actual. No se tocan otros logs.')) { return; }
					var btn = $(this);
					btn.prop('disabled', true);
					request({ action: '<?php echo esc_js( Premiero_Diagnostics::AJAX_CLEAR_LOG ); ?>' }, function(data, err){
						btn.prop('disabled', false);
						if (err) { logNotice(false, err); return; }
						$('#premiero-log-view').empty();
						logNotice(true, data.message || 'debug.log vaciado.');
					});
				});

				$('#premiero-history-clear').on('click', function(){
					if (!confirm('¿Limpiar el historial de ejecuciones?')) { return; }
					request({ action: '<?php echo esc_js( Premiero_Diagnostics::AJAX_CLEAR_HISTORY ); ?>' }, function(data, err){
						if (err) { alert(err); return; }
						$('#premiero-diag-history').empty();
					});
				});

				function buildReport(){
					var lines = ['PREMIERO DIAGNÓSTICO', ''];
					lines.push('WordPress: ' + reportEnv.wordpress);
					lines.push('PHP: ' + reportEnv.php);
					lines.push('');
					var order = ['wp_config','admin_users','mu_plugins','cron','recent_php','malware_scan','debug_log','active_plugins','htaccess','info'];
					var has = false;
					order.forEach(function(id){ if (reportLines[id]) { lines.push(reportLines[id]); has = true; } });
					if (!has) { lines.push('Sin resultados. Ejecuta algún diagnóstico.'); }
					lines.push('');
					lines.push('ACCIONES / REPARACIONES RECIENTES');
					lines.push('');
					if (reportActions.length) {
						reportActions.forEach(function(l){ lines.push(l); });
					} else {
						lines.push('(sin acciones registradas)');
					}
					return lines.join('\n');
				}

				$('#premiero-report-copy').on('click', function(){
					var text = buildReport();
					$('#premiero-report-text').val(text);
					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(text).then(function(){ alert('Informe copiado al portapapeles.'); });
					} else {
						$('#premiero-report-text').select();
						document.execCommand('copy');
					}
				});
			});
			</script>
		</div>
		<?php
	}
}
