<?php
/**
 * Registro y control de avisos del administrador.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Admin_Notices {

	const OPT_REGISTRY = 'premiero_admin_notices_registry';
	const NONCE_ACTION = 'premiero_admin_notices_action';
	const AJAX_ACTION  = 'premiero_capture_admin_notices';
	const DISMISS_ACTION = 'premiero_dismiss_admin_notice';
	const DEMO_UPDATE_ID = 'd3a0000000000001';
	const DEMO_PRO_ID    = 'd3a0000000000002';
	const MAX_RECORDS  = 250;

	private static $capture_depth = 0;

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'seed_demo_notices' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'process_actions' ), 5 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'capture_ajax' ) );
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, array( __CLASS__, 'dismiss_ajax' ) );
		add_action( 'admin_head', array( __CLASS__, 'render_capture_style' ), -999999 );
		add_action( 'admin_footer', array( __CLASS__, 'render_capture_script' ), PHP_INT_MAX );

		foreach ( array( 'all_admin_notices', 'admin_notices', 'network_admin_notices', 'user_admin_notices' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'start_capture_region' ), -999999 );
			add_action( $hook, array( __CLASS__, 'end_capture_region' ), 999999 );
		}

		add_action( 'admin_notices', array( __CLASS__, 'render_demo_notices' ), 20 );
	}

	/**
	 * Mantiene el aspecto original aunque los avisos queden delimitados para su captura.
	 */
	public static function render_capture_style() {
		echo '<style id="premiero-notice-capture-style">.premiero-notice-capture{display:contents}.premiero-demo-admin-notice{padding:0}.premiero-demo-notice-layout{display:flex;align-items:center;gap:18px;min-height:92px;padding:18px 48px 18px 20px}.premiero-demo-notice-mark{display:flex;align-items:center;justify-content:center;flex:0 0 58px;width:58px;height:58px;border-radius:10px;background:#1d2327;color:#fff;font-size:18px;font-weight:800;letter-spacing:-.04em}.premiero-demo-notice-mark.is-update{background:#2271b1}.premiero-demo-notice-mark.is-pro{background:linear-gradient(135deg,#6b1c00,#b32d2e);font-size:14px;letter-spacing:.04em}.premiero-demo-notice-content{min-width:0}.premiero-demo-notice-content h3{margin:2px 0 5px;font-size:17px;line-height:1.35}.premiero-demo-notice-content p{margin:0;color:#50575e;line-height:1.5}.premiero-demo-notice-eyebrow{color:#646970;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase}.premiero-demo-notice-actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-top:12px}.premiero-demo-notice-actions .description{color:#646970}@media(max-width:782px){.premiero-demo-notice-layout{align-items:flex-start;gap:12px;padding:15px 42px 15px 14px}.premiero-demo-notice-mark{flex-basis:44px;width:44px;height:44px;border-radius:8px;font-size:14px}.premiero-demo-notice-mark.is-pro{font-size:11px}.premiero-demo-notice-content h3{font-size:15px}}</style>';
	}

	public static function start_capture_region() {
		++self::$capture_depth;
		echo '<div class="premiero-notice-capture" data-premiero-notice-region="1">';
	}

	public static function end_capture_region() {
		if ( self::$capture_depth < 1 ) {
			return;
		}
		--self::$capture_depth;
		echo '</div>';
	}

	/**
	 * Registra las demostraciones para que ya estén disponibles en la primera carga.
	 */
	public static function seed_demo_notices() {
		if ( ! is_admin() || wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$registry = self::get_registry();
		$changed  = false;
		$now      = time();
		foreach ( self::demo_definitions() as $fingerprint => $demo ) {
			if ( isset( $registry[ $fingerprint ] ) ) {
				continue;
			}
			$registry[ $fingerprint ] = array(
				'fingerprint' => $fingerprint,
				'text'        => $demo['text'],
				'source'      => $demo['source'],
				'severity'    => $demo['severity'],
				'screen'      => 'administracion',
				'first_seen'  => $now,
				'last_seen'   => $now,
				'appearances' => 1,
				'hidden'      => false,
			);
			$changed = true;
		}

		if ( $changed ) {
			update_option( self::OPT_REGISTRY, self::limit_registry( $registry ), false );
		}
	}

	/**
	 * Dos avisos persistentes para validar el flujo completo de registro y ocultación.
	 */
	public static function render_demo_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$registry = self::get_registry();
		?>
		<?php if ( empty( $registry[ self::DEMO_UPDATE_ID ]['hidden'] ) ) : ?>
			<div class="notice notice-warning is-dismissible premiero-demo-admin-notice" data-premiero-notice-id="<?php echo esc_attr( self::DEMO_UPDATE_ID ); ?>" data-premiero-notice-source="example-builder-demo">
				<div class="premiero-demo-notice-layout">
					<div class="premiero-demo-notice-mark is-update" aria-hidden="true">EB</div>
					<div class="premiero-demo-notice-content">
						<span class="premiero-demo-notice-eyebrow">Actualización disponible · Aviso de prueba</span>
						<h3>Example Builder 4.2.0 ya está disponible</h3>
						<p>Esta versión ficticia incluye mejoras de rendimiento, compatibilidad y seguridad.</p>
						<div class="premiero-demo-notice-actions"><a class="button button-primary" href="<?php echo esc_url( admin_url( 'plugins.php?plugin_status=upgrade' ) ); ?>">Revisar actualización</a><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PREMIERO_ATK_SLUG . '&tab=notices' ) ); ?>">Ver detalles</a></div>
					</div>
				</div>
			</div>
		<?php endif; ?>
		<?php if ( empty( $registry[ self::DEMO_PRO_ID ]['hidden'] ) ) : ?>
			<div class="notice notice-info is-dismissible premiero-demo-admin-notice" data-premiero-notice-id="<?php echo esc_attr( self::DEMO_PRO_ID ); ?>" data-premiero-notice-source="example-builder-demo">
				<div class="premiero-demo-notice-layout">
					<div class="premiero-demo-notice-mark is-pro" aria-hidden="true">PRO</div>
					<div class="premiero-demo-notice-content">
						<span class="premiero-demo-notice-eyebrow">Oferta especial · Aviso de prueba</span>
						<h3>Descubre Example Builder Pro</h3>
						<p>Desbloquea plantillas premium, herramientas avanzadas y soporte prioritario con la versión Pro ficticia.</p>
						<div class="premiero-demo-notice-actions"><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PREMIERO_ATK_SLUG . '&tab=notices' ) ); ?>">Conocer Pro</a><span class="description">Promoción de prueba generada por Premiero.</span></div>
					</div>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}

	public static function process_actions() {
		if (
			! is_admin()
			|| 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '' )
			|| empty( $_POST['premiero_notices_action'] )
		) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permisos para gestionar los avisos.' );
		}
		check_admin_referer( self::NONCE_ACTION );

		$action   = sanitize_key( wp_unslash( $_POST['premiero_notices_action'] ) );
		$selected = isset( $_POST['premiero_notice_ids'] ) ? (array) wp_unslash( $_POST['premiero_notice_ids'] ) : array();
		$selected = array_values(
			array_filter(
				array_map( 'sanitize_key', $selected ),
				static function ( $fingerprint ) {
					return 1 === preg_match( '/^[a-f0-9]{16}$/', $fingerprint );
				}
			)
		);

		$registry = self::get_registry();
		$changed  = 0;
		if ( 'clear' === $action ) {
			$changed  = count( $registry );
			$registry = array();
		} elseif ( in_array( $action, array( 'hide', 'show', 'delete' ), true ) ) {
			foreach ( $selected as $fingerprint ) {
				if ( ! isset( $registry[ $fingerprint ] ) ) {
					continue;
				}
				if ( 'delete' === $action ) {
					unset( $registry[ $fingerprint ] );
				} else {
					$registry[ $fingerprint ]['hidden'] = 'hide' === $action;
				}
				++$changed;
			}
		}

		update_option( self::OPT_REGISTRY, $registry, false );
		$url = add_query_arg(
			array(
				'page'                    => PREMIERO_ATK_SLUG,
				'tab'                     => 'notices',
				'premiero_notices_updated'=> $changed,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public static function capture_ajax() {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'No autorizado.' ), 403 );
		}

		$payload = isset( $_POST['notices'] ) ? json_decode( wp_unslash( $_POST['notices'] ), true ) : array();
		if ( ! is_array( $payload ) ) {
			wp_send_json_error( array( 'message' => 'Formato no valido.' ), 400 );
		}

		$registry = self::get_registry();
		$now      = time();
		foreach ( array_slice( $payload, 0, 50 ) as $notice ) {
			if ( ! is_array( $notice ) ) {
				continue;
			}
			$fingerprint = isset( $notice['fingerprint'] ) ? sanitize_key( $notice['fingerprint'] ) : '';
			if ( 1 !== preg_match( '/^[a-f0-9]{16}$/', $fingerprint ) ) {
				continue;
			}
			$text = isset( $notice['text'] ) ? trim( sanitize_textarea_field( $notice['text'] ) ) : '';
			if ( '' === $text ) {
				continue;
			}
			$existing = isset( $registry[ $fingerprint ] ) && is_array( $registry[ $fingerprint ] )
				? $registry[ $fingerprint ]
				: array();
			$severity = isset( $notice['severity'] ) ? sanitize_key( $notice['severity'] ) : 'custom';
			if ( ! in_array( $severity, array( 'error', 'warning', 'success', 'info', 'custom' ), true ) ) {
				$severity = 'custom';
			}
			$registry[ $fingerprint ] = array(
				'fingerprint' => $fingerprint,
				'text'        => substr( $text, 0, 1000 ),
				'source'      => substr( sanitize_text_field( isset( $notice['source'] ) ? $notice['source'] : 'No identificado' ), 0, 100 ),
				'severity'    => $severity,
				'screen'      => substr( sanitize_key( isset( $notice['screen'] ) ? $notice['screen'] : '' ), 0, 100 ),
				'first_seen'  => isset( $existing['first_seen'] ) ? (int) $existing['first_seen'] : $now,
				'last_seen'   => $now,
				'appearances' => isset( $existing['appearances'] ) ? (int) $existing['appearances'] + 1 : 1,
				'hidden'      => ! empty( $existing['hidden'] ),
			);
		}

		$registry = self::limit_registry( $registry );
		update_option( self::OPT_REGISTRY, $registry, false );
		wp_send_json_success( array( 'registered' => count( $registry ) ) );
	}

	/**
	 * Convierte la X nativa de WordPress en una ocultación persistente.
	 */
	public static function dismiss_ajax() {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'No autorizado.' ), 403 );
		}

		$fingerprint = isset( $_POST['fingerprint'] ) ? sanitize_key( wp_unslash( $_POST['fingerprint'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{16}$/', $fingerprint ) ) {
			wp_send_json_error( array( 'message' => 'Identificador no válido.' ), 400 );
		}

		$registry = self::get_registry();
		$existing = isset( $registry[ $fingerprint ] ) && is_array( $registry[ $fingerprint ] )
			? $registry[ $fingerprint ]
			: array();
		$notice = isset( $_POST['notice'] ) ? json_decode( wp_unslash( $_POST['notice'] ), true ) : array();
		if ( ! is_array( $notice ) ) {
			$notice = array();
		}

		$text = isset( $existing['text'] ) ? (string) $existing['text'] : '';
		if ( '' === $text && isset( $notice['text'] ) ) {
			$text = trim( sanitize_textarea_field( $notice['text'] ) );
		}
		if ( '' === $text ) {
			wp_send_json_error( array( 'message' => 'El aviso todavía no está registrado.' ), 409 );
		}

		$severity = isset( $existing['severity'] ) ? sanitize_key( $existing['severity'] ) : sanitize_key( isset( $notice['severity'] ) ? $notice['severity'] : 'custom' );
		if ( ! in_array( $severity, array( 'error', 'warning', 'success', 'info', 'custom' ), true ) ) {
			$severity = 'custom';
		}
		$now = time();
		$registry[ $fingerprint ] = array(
			'fingerprint' => $fingerprint,
			'text'        => substr( $text, 0, 1000 ),
			'source'      => substr( sanitize_text_field( isset( $existing['source'] ) ? $existing['source'] : ( isset( $notice['source'] ) ? $notice['source'] : 'No identificado' ) ), 0, 100 ),
			'severity'    => $severity,
			'screen'      => substr( sanitize_key( isset( $existing['screen'] ) ? $existing['screen'] : ( isset( $notice['screen'] ) ? $notice['screen'] : '' ) ), 0, 100 ),
			'first_seen'  => isset( $existing['first_seen'] ) ? (int) $existing['first_seen'] : $now,
			'last_seen'   => $now,
			'appearances' => isset( $existing['appearances'] ) ? max( 1, (int) $existing['appearances'] ) : 1,
			'hidden'      => true,
		);

		update_option( self::OPT_REGISTRY, self::limit_registry( $registry ), false );
		wp_send_json_success( array( 'hidden' => $fingerprint ) );
	}

	public static function render_capture_script() {
		if ( ! is_admin() ) {
			return;
		}
		$hidden = array();
		foreach ( self::get_registry() as $fingerprint => $notice ) {
			if ( ! empty( $notice['hidden'] ) ) {
				$hidden[] = $fingerprint;
			}
		}
		$data = array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'action'        => self::AJAX_ACTION,
			'dismissAction' => self::DISMISS_ACTION,
			'nonce'         => wp_create_nonce( self::AJAX_ACTION ),
			'canCapture'    => current_user_can( 'manage_options' ),
			'hidden'        => $hidden,
			'screen'        => function_exists( 'get_current_screen' ) && get_current_screen() ? get_current_screen()->id : '',
		);
		?>
		<script>
		(function () {
			'use strict';
			var config = <?php echo wp_json_encode( $data ); ?>;
			var hidden = new Set(config.hidden || []);

			function normalize(text) {
				return String(text || '').replace(/\s+/g, ' ').trim().toLowerCase().slice(0, 2000);
			}

			function fingerprint(text) {
				var normalized = normalize(text);
				var first = 2166136261;
				var second = 2246822507;
				for (var i = 0; i < normalized.length; i++) {
					var code = normalized.charCodeAt(i);
					first = Math.imul(first ^ code, 16777619);
					second = Math.imul(second ^ code, 3266489917);
				}
				return ('00000000' + (first >>> 0).toString(16)).slice(-8) + ('00000000' + (second >>> 0).toString(16)).slice(-8);
			}

			function sourceFor(node) {
				var declaredSource = node.getAttribute('data-premiero-notice-source');
				if (declaredSource) return declaredSource;
				var html = node.innerHTML || '';
				var pluginPath = html.match(/\/wp-content\/plugins\/([^/"'?#]+)/i);
				if (pluginPath && pluginPath[1]) {
					try {
						return decodeURIComponent(pluginPath[1]);
					} catch (error) {
						return pluginPath[1];
					}
				}
				var link = node.querySelector('a[href*="page="]');
				if (link) {
					try {
						var page = new URL(link.href, window.location.href).searchParams.get('page');
						if (page) return page;
					} catch (error) {}
				}
				if (node.classList.contains('premiero-demo-admin-notice')) return 'premiero-demo';
				return 'No identificado';
			}

			function severityFor(node) {
				if (node.matches('.notice-error, .error')) return 'error';
				if (node.matches('.notice-warning')) return 'warning';
				if (node.matches('.notice-success, .updated')) return 'success';
				if (node.matches('.notice-info')) return 'info';
				return 'custom';
			}

			function candidates() {
				var selector = '.premiero-notice-capture > *';
				var found = Array.prototype.slice.call(document.querySelectorAll(selector));
				return found.filter(function (node, index) {
					if (!node || !node.textContent || node.closest('#premiero-notices-console') || node.classList.contains('premiero-capture-ignore')) return false;
					if (['SCRIPT', 'STYLE', 'LINK'].indexOf(node.tagName) !== -1) return false;
					if (normalize(node.textContent).length < 8) return false;
					return found.findIndex(function (candidate) {
						return candidate !== node && candidate.contains(node) && normalize(candidate.textContent) === normalize(node.textContent);
					}) === -1 && found.indexOf(node) === index;
				});
			}

			var records = [];
			var recordById = {};
			candidates().forEach(function (node) {
				var text = String(node.textContent || '').replace(/\s+/g, ' ').trim();
				var source = sourceFor(node);
				var declaredId = String(node.getAttribute('data-premiero-notice-id') || '').toLowerCase();
				var id = /^[a-f0-9]{16}$/.test(declaredId) ? declaredId : fingerprint(source + '|' + text);
				node.setAttribute('data-premiero-notice-id', id);
				if (hidden.has(id)) {
					node.style.setProperty('display', 'none', 'important');
					node.setAttribute('aria-hidden', 'true');
				}
				var record = {fingerprint:id, text:text.slice(0, 1000), source:source, severity:severityFor(node), screen:config.screen};
				records.push(record);
				recordById[id] = record;
			});

			function post(body) {
				if (!window.fetch) return;
				window.fetch(config.ajaxUrl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:body.toString()}).catch(function () {});
			}

			if (config.canCapture) {
				document.addEventListener('click', function (event) {
					var dismiss = event.target && event.target.closest ? event.target.closest('.notice-dismiss') : null;
					var node = dismiss && dismiss.closest ? dismiss.closest('[data-premiero-notice-id]') : null;
					if (!node || node.closest('#premiero-notices-console')) return;
					var id = String(node.getAttribute('data-premiero-notice-id') || '');
					var record = recordById[id];
					if (!record) return;
					var dismissBody = new URLSearchParams();
					dismissBody.set('action', config.dismissAction);
					dismissBody.set('nonce', config.nonce);
					dismissBody.set('fingerprint', id);
					dismissBody.set('notice', JSON.stringify(record));
					post(dismissBody);
				}, true);
			}

			if (!config.canCapture || !records.length) return;
			var captureBody = new URLSearchParams();
			captureBody.set('action', config.action);
			captureBody.set('nonce', config.nonce);
			captureBody.set('notices', JSON.stringify(records));
			post(captureBody);
		}());
		</script>
		<?php
	}

	public static function render_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$registry = self::get_registry();
		uasort(
			$registry,
			static function ( $left, $right ) {
				return (int) $right['last_seen'] <=> (int) $left['last_seen'];
			}
		);
		$total  = count( $registry );
		$hidden = count( array_filter( $registry, static function ( $notice ) { return ! empty( $notice['hidden'] ); } ) );
		$errors = count( array_filter( $registry, static function ( $notice ) { return in_array( $notice['severity'], array( 'error', 'warning' ), true ); } ) );
		$recent = count( array_filter( $registry, static function ( $notice ) { return (int) $notice['last_seen'] >= time() - DAY_IN_SECONDS; } ) );
		?>
		<div class="premiero-notices-console" id="premiero-notices-console">
			<h2>Gestor de avisos</h2>
			<p class="premiero-notices-lead">Registra los avisos mostrados en la administración y permite ocultar los que no aportan valor. Ningún aviso se bloquea automáticamente.</p>

			<?php if ( isset( $_GET['premiero_notices_updated'] ) ) : ?>
				<div class="notice notice-success inline premiero-capture-ignore"><p>Cambios aplicados a <?php echo esc_html( (string) absint( $_GET['premiero_notices_updated'] ) ); ?> avisos.</p></div>
			<?php endif; ?>

			<div class="premiero-overview premiero-notices-overview">
				<div><strong>Registrados</strong><span><?php echo esc_html( (string) $total ); ?> avisos</span></div>
				<div class="is-hidden"><strong>Ocultos</strong><span><?php echo esc_html( (string) $hidden ); ?> avisos</span></div>
				<div class="is-attention"><strong>Error o advertencia</strong><span><?php echo esc_html( (string) $errors ); ?> avisos</span></div>
				<div><strong>Últimas 24 horas</strong><span><?php echo esc_html( (string) $recent ); ?> avisos</span></div>
			</div>

			<div class="premiero-notices-guidance">
				<strong>Control manual y reversible</strong>
				<p>Oculta promociones, peticiones de reseña o avisos repetitivos. Conserva visibles los errores de seguridad, compatibilidad, actualizaciones fallidas y copias de seguridad.</p>
				<p>Los dos avisos de prueba se muestran en toda la administración como los de un plugin real. Puedes cerrarlos con su X o seleccionarlos aquí y pulsar <strong>Ocultar seleccionados</strong>; en ambos casos permanecerán ocultos hasta que elijas <strong>Volver a mostrar</strong>.</p>
			</div>

			<form method="post" class="premiero-notices-form">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<div class="premiero-notices-toolbar">
					<input type="search" id="premiero-notices-search" placeholder="Buscar por texto u origen" aria-label="Buscar avisos">
					<select id="premiero-notices-filter" aria-label="Filtrar avisos">
						<option value="all">Todos los estados</option>
						<option value="visible">Visibles</option>
						<option value="hidden">Ocultos</option>
						<option value="attention">Errores y advertencias</option>
					</select>
					<button class="button" type="submit" name="premiero_notices_action" value="hide">Ocultar seleccionados</button>
					<button class="button" type="submit" name="premiero_notices_action" value="show">Volver a mostrar</button>
				</div>

				<?php if ( empty( $registry ) ) : ?>
					<div class="premiero-notices-empty"><strong>Todavía no hay avisos registrados.</strong><span>Premiero comenzará a registrarlos mientras navegas por la administración.</span></div>
				<?php else : ?>
					<div class="premiero-notices-table-wrap">
					<table class="widefat striped premiero-notices-table">
						<thead><tr><td class="check-column"><input type="checkbox" id="premiero-notices-select-all" aria-label="Seleccionar todos"></td><th>Aviso</th><th>Origen</th><th>Tipo</th><th>Apariciones</th><th>Última vez</th><th>Estado</th></tr></thead>
						<tbody>
						<?php foreach ( $registry as $fingerprint => $notice ) : ?>
							<?php
							$notice_text  = trim( preg_replace( '/\s+/', ' ', (string) $notice['text'] ) );
							$notice_title = self::notice_title( $notice_text );
							?>
							<tr data-notice-row data-state="<?php echo ! empty( $notice['hidden'] ) ? 'hidden' : 'visible'; ?>" data-severity="<?php echo esc_attr( $notice['severity'] ); ?>">
								<th class="check-column"><input type="checkbox" name="premiero_notice_ids[]" value="<?php echo esc_attr( $fingerprint ); ?>" aria-label="Seleccionar aviso"></th>
								<td data-label="Aviso">
									<strong><?php echo esc_html( $notice_title ); ?></strong>
									<?php if ( $notice_title !== $notice_text ) : ?>
										<details class="premiero-notice-message"><summary>Ver mensaje completo</summary><span><?php echo esc_html( $notice_text ); ?></span></details>
									<?php endif; ?>
								</td>
								<td data-label="Origen"><code><?php echo esc_html( $notice['source'] ); ?></code><?php if ( ! empty( $notice['screen'] ) ) : ?><small><?php echo esc_html( $notice['screen'] ); ?></small><?php endif; ?></td>
								<td data-label="Tipo"><span class="premiero-notice-type is-<?php echo esc_attr( $notice['severity'] ); ?>"><?php echo esc_html( self::severity_label( $notice['severity'] ) ); ?></span></td>
								<td data-label="Apariciones"><?php echo esc_html( (string) $notice['appearances'] ); ?></td>
								<td data-label="Última vez"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $notice['last_seen'] ) ); ?></td>
								<td data-label="Estado"><span class="premiero-notice-state <?php echo ! empty( $notice['hidden'] ) ? 'is-hidden' : 'is-visible'; ?>"><?php echo ! empty( $notice['hidden'] ) ? 'Oculto' : 'Visible'; ?></span></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
					<div class="premiero-notices-footer-actions">
						<button class="button" type="submit" name="premiero_notices_action" value="delete">Eliminar del registro</button>
						<button class="button button-link-delete" type="submit" name="premiero_notices_action" value="clear" onclick="return window.confirm('¿Vaciar todo el registro y volver a mostrar los avisos ocultos?');">Vaciar registro</button>
					</div>
				<?php endif; ?>
			</form>
		</div>
		<script>
		document.addEventListener('DOMContentLoaded', function () {
			var search = document.getElementById('premiero-notices-search');
			var filter = document.getElementById('premiero-notices-filter');
			var selectAll = document.getElementById('premiero-notices-select-all');
			var rows = Array.prototype.slice.call(document.querySelectorAll('[data-notice-row]'));
			function refresh() {
				var term = search ? search.value.toLowerCase().trim() : '';
				var state = filter ? filter.value : 'all';
				rows.forEach(function (row) {
					var matchesText = !term || row.textContent.toLowerCase().indexOf(term) !== -1;
					var matchesState = state === 'all' || row.dataset.state === state || (state === 'attention' && ['error','warning'].indexOf(row.dataset.severity) !== -1);
					row.hidden = !(matchesText && matchesState);
				});
			}
			if (search) search.addEventListener('input', refresh);
			if (filter) filter.addEventListener('change', refresh);
			if (selectAll) selectAll.addEventListener('change', function () {
				rows.forEach(function (row) { if (!row.hidden) row.querySelector('input[type="checkbox"]').checked = selectAll.checked; });
			});
		});
		</script>
		<?php
	}

	private static function demo_definitions() {
		return array(
			self::DEMO_UPDATE_ID => array(
				'text'     => 'Example Builder: hay una actualización disponible. La versión 4.2.0 ficticia incluye mejoras de rendimiento, compatibilidad y seguridad.',
				'source'   => 'example-builder-demo',
				'severity' => 'warning',
			),
			self::DEMO_PRO_ID => array(
				'text'     => 'Descubre Example Builder Pro. Desbloquea plantillas premium, herramientas avanzadas y soporte prioritario con la versión Pro ficticia.',
				'source'   => 'example-builder-demo',
				'severity' => 'info',
			),
		);
	}

	private static function get_registry() {
		$registry = get_option( self::OPT_REGISTRY, array() );
		return is_array( $registry ) ? $registry : array();
	}

	private static function limit_registry( $registry ) {
		if ( count( $registry ) <= self::MAX_RECORDS ) {
			return $registry;
		}
		uasort(
			$registry,
			static function ( $left, $right ) {
				if ( ! empty( $left['hidden'] ) !== ! empty( $right['hidden'] ) ) {
					return ! empty( $left['hidden'] ) ? 1 : -1;
				}
				return (int) $left['last_seen'] <=> (int) $right['last_seen'];
			}
		);
		while ( count( $registry ) > self::MAX_RECORDS ) {
			array_shift( $registry );
		}
		return $registry;
	}

	private static function notice_title( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( strlen( $text ) <= 100 ) {
			return $text;
		}
		return substr( $text, 0, 97 ) . '...';
	}

	private static function severity_label( $severity ) {
		$labels = array(
			'error'   => 'Error',
			'warning' => 'Advertencia',
			'success' => 'Correcto',
			'info'    => 'Informativo',
			'custom'  => 'Personalizado',
		);
		return isset( $labels[ $severity ] ) ? $labels[ $severity ] : 'Personalizado';
	}
}
