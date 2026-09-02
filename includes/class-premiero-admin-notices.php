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
	const OPT_SCHEMA   = 'premiero_admin_notices_schema';
	const NONCE_ACTION = 'premiero_admin_notices_action';
	const AJAX_ACTION  = 'premiero_capture_admin_notices';
	const DISMISS_ACTION = 'premiero_dismiss_admin_notice';
	const SCHEMA_VERSION = 1;
	const MAX_RECORDS  = 250;

	private static $capture_depth = 0;

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'migrate_registry' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'process_actions' ), 5 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'capture_ajax' ) );
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, array( __CLASS__, 'dismiss_ajax' ) );
		add_action( 'admin_head', array( __CLASS__, 'render_capture_style' ), -999999 );
		add_action( 'admin_footer', array( __CLASS__, 'render_capture_script' ), PHP_INT_MAX );

		foreach ( array( 'all_admin_notices', 'admin_notices', 'network_admin_notices', 'user_admin_notices' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'start_capture_region' ), -999999 );
			add_action( $hook, array( __CLASS__, 'end_capture_region' ), 999999 );
		}
	}

	/**
	 * Mantiene el aspecto original aunque los avisos queden delimitados para su captura.
	 */
	public static function render_capture_style() {
		echo '<style id="premiero-notice-capture-style">.premiero-notice-capture{display:contents}</style>';
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
	 * Limpia los avisos de demostración de 3.4.2 y consolida duplicados.
	 */
	public static function migrate_registry() {
		if ( ! is_admin() || (int) get_option( self::OPT_SCHEMA, 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}

		$registry = self::consolidate_registry( self::get_registry(), true );
		update_option( self::OPT_REGISTRY, self::limit_registry( $registry ), false );
		update_option( self::OPT_SCHEMA, self::SCHEMA_VERSION, false );
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
			$match_key = isset( $notice['matchKey'] ) ? sanitize_key( $notice['matchKey'] ) : '';
			if ( 1 !== preg_match( '/^[a-f0-9]{16}$/', $match_key ) ) {
				$match_key = self::match_key( $text );
			}
			$record_key = self::find_record_key( $registry, $fingerprint, $match_key );
			$existing   = isset( $registry[ $record_key ] ) && is_array( $registry[ $record_key ] )
				? $registry[ $record_key ]
				: array();
			$severity = isset( $notice['severity'] ) ? sanitize_key( $notice['severity'] ) : 'custom';
			if ( ! in_array( $severity, array( 'error', 'warning', 'success', 'info', 'custom' ), true ) ) {
				$severity = 'custom';
			}
			$source = substr( sanitize_text_field( isset( $notice['source'] ) ? $notice['source'] : 'No identificado' ), 0, 100 );
			if ( 'No identificado' === $source && ! empty( $existing['source'] ) ) {
				$source = (string) $existing['source'];
			}
			$registry[ $record_key ] = array(
				'fingerprint' => $record_key,
				'match_key'   => $match_key,
				'text'        => substr( $text, 0, 1000 ),
				'source'      => $source,
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
		$notice = isset( $_POST['notice'] ) ? json_decode( wp_unslash( $_POST['notice'] ), true ) : array();
		if ( ! is_array( $notice ) ) {
			$notice = array();
		}
		$match_key = isset( $notice['matchKey'] ) ? sanitize_key( $notice['matchKey'] ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{16}$/', $match_key ) && ! empty( $notice['text'] ) ) {
			$match_key = self::match_key( $notice['text'] );
		}
		$record_key = self::find_record_key( $registry, $fingerprint, $match_key );
		$existing   = isset( $registry[ $record_key ] ) && is_array( $registry[ $record_key ] )
			? $registry[ $record_key ]
			: array();

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
		if ( 1 !== preg_match( '/^[a-f0-9]{16}$/', $match_key ) ) {
			$match_key = self::match_key( $text );
		}
		$registry[ $record_key ] = array(
			'fingerprint' => $record_key,
			'match_key'   => $match_key,
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
		wp_send_json_success( array( 'hidden' => $record_key ) );
	}

	public static function render_capture_script() {
		if ( ! is_admin() ) {
			return;
		}
		$hidden         = array();
		$hidden_matches = array();
		foreach ( self::get_registry() as $fingerprint => $notice ) {
			if ( ! empty( $notice['hidden'] ) ) {
				$hidden[] = $fingerprint;
				$hidden_matches[] = ! empty( $notice['match_key'] ) ? $notice['match_key'] : self::match_key( isset( $notice['text'] ) ? $notice['text'] : '' );
			}
		}
		$data = array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'action'        => self::AJAX_ACTION,
			'dismissAction' => self::DISMISS_ACTION,
			'nonce'         => wp_create_nonce( self::AJAX_ACTION ),
			'canCapture'    => current_user_can( 'manage_options' ),
			'hidden'        => $hidden,
			'hiddenMatches' => array_values( array_unique( array_filter( $hidden_matches ) ) ),
			'screen'        => function_exists( 'get_current_screen' ) && get_current_screen() ? get_current_screen()->id : '',
		);
		?>
		<script>
		(function () {
			'use strict';
			var config = <?php echo wp_json_encode( $data ); ?>;
			var hiddenIds = new Set(config.hidden || []);
			var hiddenMatches = new Set(config.hiddenMatches || []);
			var recordById = {};
			var sentRecords = new Set();
			var scanTimer = null;

			function normalize(text) {
				return String(text || '').replace(/\s+/g, ' ').trim().toLowerCase().slice(0, 2000);
			}

			function canonicalMatch(text) {
				var value = String(text || '');
				if (value.normalize) value = value.normalize('NFD');
				return value.replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim().slice(0, 220);
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

			function matchKey(text) {
				var normalized = canonicalMatch(text);
				var first = 5381;
				var second = 0;
				for (var i = 0; i < normalized.length; i++) {
					var code = normalized.charCodeAt(i);
					first = (Math.imul(first, 33) ^ code) >>> 0;
					second = (Math.imul(second, 65599) + code) >>> 0;
				}
				return ('00000000' + first.toString(16)).slice(-8) + ('00000000' + second.toString(16)).slice(-8);
			}

			function textFor(node) {
				var clone = node.cloneNode(true);
				Array.prototype.forEach.call(clone.querySelectorAll('script, style, .notice-dismiss, [class*="notice-dismiss"], [class*="dismiss-notice"], [data-dismiss], .screen-reader-text, [hidden], [aria-hidden="true"]'), function (item) {
					item.remove();
				});
				return String(clone.textContent || '').replace(/\s+/g, ' ').trim();
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
				if (node.id && ['message', 'setting-error-tgmpa'].indexOf(node.id) === -1) return 'id:' + node.id;
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
				var selector = [
					'.premiero-notice-capture > *',
					'#wpbody-content > .notice', '#wpbody-content > .updated', '#wpbody-content > .error', '#wpbody-content > [role="alert"]',
					'#wpbody-content > [class*="admin-notice"]', '#wpbody-content > [class*="notification"]', '#wpbody-content > [class*="promo-banner"]',
					'#wpbody-content > .wrap > .notice', '#wpbody-content > .wrap > .updated', '#wpbody-content > .wrap > .error', '#wpbody-content > .wrap > [role="alert"]',
					'#wpbody-content > .wrap > [class*="admin-notice"]', '#wpbody-content > .wrap > [class*="notification"]', '#wpbody-content > .wrap > [class*="promo-banner"]',
					'#wpbody-content .notice', '#wpbody-content .update-nag', '#wpbody-content [class*="admin-notice"]', '#wpbody-content [class*="promo-banner"]'
				].join(',');
				var found = Array.prototype.slice.call(document.querySelectorAll(selector));
				return found.filter(function (node, index) {
					if (!node || !node.textContent || node.closest('#premiero-notices-console') || node.classList.contains('premiero-capture-ignore') || node.classList.contains('premiero-notice-capture')) return false;
					if (node.closest('.components-snackbar-list, .components-notice-list, [class*="snackbar"]')) return false;
					if (['SCRIPT', 'STYLE', 'LINK'].indexOf(node.tagName) !== -1) return false;
					if (normalize(textFor(node)).length < 8) return false;
					return found.findIndex(function (candidate) {
						return candidate !== node && candidate.contains(node) && normalize(textFor(candidate)) === normalize(textFor(node));
					}) === -1 && found.indexOf(node) === index;
				});
			}

			function inspect(node) {
				var text = textFor(node);
				var source = sourceFor(node);
				var id = fingerprint(source + '|' + text);
				var stableMatch = matchKey(text);
				node.setAttribute('data-premiero-notice-id', id);
				node.setAttribute('data-premiero-notice-match', stableMatch);
				if (hiddenIds.has(id) || hiddenMatches.has(stableMatch)) {
					node.style.setProperty('display', 'none', 'important');
					node.setAttribute('aria-hidden', 'true');
					node.setAttribute('data-premiero-notice-hidden', '1');
				} else if (node.getAttribute('data-premiero-notice-hidden') === '1') {
					node.style.removeProperty('display');
					node.removeAttribute('aria-hidden');
					node.removeAttribute('data-premiero-notice-hidden');
				}
				var record = {fingerprint:id, matchKey:stableMatch, text:text.slice(0, 1000), source:source, severity:severityFor(node), screen:config.screen};
				recordById[id] = record;
				return record;
			}

			function post(body) {
				if (!window.fetch) return;
				window.fetch(config.ajaxUrl, {method:'POST', credentials:'same-origin', keepalive:true, headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:body.toString()}).catch(function () {});
			}

			function scanAndCapture() {
				var fresh = [];
				candidates().forEach(function (node) {
					var record = inspect(node);
					var sentKey = record.matchKey;
					if (!sentRecords.has(sentKey)) {
						sentRecords.add(sentKey);
						fresh.push(record);
					}
				});
				if (!config.canCapture || !fresh.length) return;
				var captureBody = new URLSearchParams();
				captureBody.set('action', config.action);
				captureBody.set('nonce', config.nonce);
				captureBody.set('notices', JSON.stringify(fresh));
				post(captureBody);
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

			scanAndCapture();
			var root = document.getElementById('wpbody-content');
			if (root && window.MutationObserver) {
				new MutationObserver(function () {
					window.clearTimeout(scanTimer);
					scanTimer = window.setTimeout(scanAndCapture, 80);
				}).observe(root, {childList:true, subtree:true});
			}
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
				<p>Premiero registra los avisos persistentes cuando cada plugin los muestra. Una vez ocultado un aviso, la regla se aplica también si el mismo mensaje aparece en otra sección o se añade después de cargar la página. Las confirmaciones temporales del editor, como «Guardado» o «Publicado», no se registran.</p>
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

	private static function get_registry() {
		$registry = get_option( self::OPT_REGISTRY, array() );
		return is_array( $registry ) ? $registry : array();
	}

	private static function find_record_key( $registry, $fingerprint, $match_key ) {
		if ( isset( $registry[ $fingerprint ] ) ) {
			return $fingerprint;
		}
		if ( 1 === preg_match( '/^[a-f0-9]{16}$/', (string) $match_key ) ) {
			foreach ( $registry as $record_key => $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}
				$record_match = ! empty( $record['match_key'] ) ? sanitize_key( $record['match_key'] ) : self::match_key( isset( $record['text'] ) ? $record['text'] : '' );
				if ( hash_equals( $record_match, $match_key ) ) {
					return $record_key;
				}
			}
		}
		return $fingerprint;
	}

	private static function match_key( $text ) {
		$text = function_exists( 'remove_accents' ) ? remove_accents( wp_strip_all_tags( (string) $text ) ) : wp_strip_all_tags( (string) $text );
		$text = strtolower( trim( preg_replace( '/\s+/', ' ', $text ) ) );
		$text = substr( $text, 0, 220 );
		$first  = 5381;
		$second = 0;
		$length = strlen( $text );
		for ( $index = 0; $index < $length; ++$index ) {
			$code   = ord( $text[ $index ] );
			$first  = ( ( ( $first * 33 ) & 0xffffffff ) ^ $code ) & 0xffffffff;
			$second = ( $code + ( ( $second * 65599 ) & 0xffffffff ) ) & 0xffffffff;
		}
		return sprintf( '%08x%08x', $first, $second );
	}

	private static function consolidate_registry( $registry, $remove_demos = false ) {
		$consolidated = array();
		foreach ( (array) $registry as $fingerprint => $notice ) {
			if ( ! is_array( $notice ) || ( $remove_demos && self::is_demo_record( $fingerprint, $notice ) ) ) {
				continue;
			}
			$text = trim( sanitize_textarea_field( isset( $notice['text'] ) ? $notice['text'] : '' ) );
			if ( '' === $text ) {
				continue;
			}
			$fingerprint = sanitize_key( isset( $notice['fingerprint'] ) ? $notice['fingerprint'] : $fingerprint );
			$match_key   = ! empty( $notice['match_key'] ) ? sanitize_key( $notice['match_key'] ) : self::match_key( $text );
			if ( 1 !== preg_match( '/^[a-f0-9]{16}$/', $match_key ) ) {
				$match_key = self::match_key( $text );
			}
			if ( 1 !== preg_match( '/^[a-f0-9]{16}$/', $fingerprint ) ) {
				$fingerprint = $match_key;
			}
			$record_key = self::find_record_key( $consolidated, $fingerprint, $match_key );
			$severity   = isset( $notice['severity'] ) ? sanitize_key( $notice['severity'] ) : 'custom';
			if ( ! in_array( $severity, array( 'error', 'warning', 'success', 'info', 'custom' ), true ) ) {
				$severity = 'custom';
			}
			$record = array(
				'fingerprint' => $record_key,
				'match_key'   => $match_key,
				'text'        => substr( $text, 0, 1000 ),
				'source'      => substr( sanitize_text_field( isset( $notice['source'] ) ? $notice['source'] : 'No identificado' ), 0, 100 ),
				'severity'    => $severity,
				'screen'      => substr( sanitize_key( isset( $notice['screen'] ) ? $notice['screen'] : '' ), 0, 100 ),
				'first_seen'  => isset( $notice['first_seen'] ) ? (int) $notice['first_seen'] : time(),
				'last_seen'   => isset( $notice['last_seen'] ) ? (int) $notice['last_seen'] : time(),
				'appearances' => max( 1, isset( $notice['appearances'] ) ? (int) $notice['appearances'] : 1 ),
				'hidden'      => ! empty( $notice['hidden'] ),
			);
			if ( ! isset( $consolidated[ $record_key ] ) ) {
				$consolidated[ $record_key ] = $record;
				continue;
			}

			$existing = $consolidated[ $record_key ];
			$existing['first_seen']  = min( (int) $existing['first_seen'], (int) $record['first_seen'] );
			$existing['appearances'] = (int) $existing['appearances'] + (int) $record['appearances'];
			$existing['hidden']      = ! empty( $existing['hidden'] ) || ! empty( $record['hidden'] );
			if ( 'No identificado' === $existing['source'] && 'No identificado' !== $record['source'] ) {
				$existing['source'] = $record['source'];
			}
			if ( (int) $record['last_seen'] >= (int) $existing['last_seen'] ) {
				$existing['text']       = $record['text'];
				$existing['severity']   = $record['severity'];
				$existing['screen']     = $record['screen'];
				$existing['last_seen']  = $record['last_seen'];
			}
			$consolidated[ $record_key ] = $existing;
		}
		return $consolidated;
	}

	private static function is_demo_record( $fingerprint, $notice ) {
		$demo_ids = array( 'd3a0000000000001', 'd3a0000000000002', '3319c5f2a7da4340', '8f2b3dfa244e1324' );
		$source   = isset( $notice['source'] ) ? sanitize_key( $notice['source'] ) : '';
		return in_array( (string) $fingerprint, $demo_ids, true )
			|| in_array( $source, array( 'premiero-demo', 'example-builder-demo' ), true );
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
