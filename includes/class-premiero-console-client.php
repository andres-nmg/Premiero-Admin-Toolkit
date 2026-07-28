<?php
/**
 * Cliente saliente para Premiero Maintenance Console.
 *
 * Este archivo no expone endpoints en la instalación cliente y no acepta
 * órdenes remotas. Toda comunicación se inicia desde WordPress mediante
 * WP-Cron o una acción explícita de un administrador.
 *
 * La instalación se empareja una sola vez mediante /pair. A partir de ese
 * momento envía instantáneas firmadas a /telemetry con el protocolo PMC1.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Premiero_Console_Client', false ) ) {
	return;
}

final class Premiero_Console_Client {

	const SCHEMA_VERSION = 1;
	const API_NAMESPACE  = 'premiero-console/v1';
	const SIGNATURE_VERSION = 'PMC1';
	const TELEMETRY_CANONICAL = '/wp-json/premiero-console/v1/telemetry';

	const CRON_SYNC      = 'premiero_console_sync';
	const CRON_SYNC_SOON = 'premiero_console_sync_soon';
	const CRON_SIZE      = 'premiero_console_collect_sizes';
	const CRON_SIZE_SOON = 'premiero_console_collect_sizes_soon';

	const SCHEDULE_SYNC = 'premiero_console_12_hours';
	const SCHEDULE_SIZE = 'premiero_console_weekly';

	const NONCE_ACTION = 'premiero_console_admin_action';

	const OPT_ENABLED         = 'premiero_console_enabled';
	const OPT_API_BASE        = 'premiero_console_api_base';
	const OPT_INSTALLATION_ID        = 'premiero_console_installation_id';
	const OPT_REMOTE_INSTALLATION_ID = 'premiero_console_remote_installation_id';
	const OPT_KEY_ID                 = 'premiero_console_key_id';
	const OPT_SECRET                 = 'premiero_console_secret';
	const OPT_LAST_SENT       = 'premiero_console_last_sent';
	const OPT_LAST_HTTP       = 'premiero_console_last_http_code';
	const OPT_LAST_ERROR      = 'premiero_console_last_error';
	const OPT_FAILURES        = 'premiero_console_failures';
	const OPT_NEXT_ATTEMPT    = 'premiero_console_next_attempt';
	const OPT_PAYLOAD_HASH    = 'premiero_console_payload_hash';
	const OPT_SIZE_CACHE      = 'premiero_console_size_cache';
	const OPT_LOCK            = 'premiero_console_sync_lock';
	const OPT_SIZE_LOCK       = 'premiero_console_size_lock';

	/**
	 * Evita registrar dos veces los hooks si init() se llama más de una vez.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Registra únicamente hooks ligeros. No lee opciones ni recorre archivos
	 * durante las visitas al frontend.
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedules' ) );

		add_action( self::CRON_SYNC, array( __CLASS__, 'cron_send_snapshot' ) );
		add_action( self::CRON_SYNC_SOON, array( __CLASS__, 'cron_send_snapshot' ) );
		add_action( self::CRON_SIZE, array( __CLASS__, 'cron_collect_sizes' ) );
		add_action( self::CRON_SIZE_SOON, array( __CLASS__, 'cron_collect_sizes' ) );

		/*
		 * Los formularios y la reparación de la programación solo se procesan
		 * dentro del administrador. Así no se añaden consultas al frontend.
		 */
		add_action( 'admin_init', array( __CLASS__, 'process_admin_forms' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule_events' ), 30 );

		/*
		 * Los hooks de cambios nunca envían información en el mismo proceso:
		 * únicamente programan una sincronización diferida y deduplicada.
		 */
		add_action( 'upgrader_process_complete', array( __CLASS__, 'mark_dirty' ), 10, 2 );
		add_action( 'automatic_updates_complete', array( __CLASS__, 'mark_dirty' ), 10, 1 );
		add_action( '_core_updated_successfully', array( __CLASS__, 'mark_dirty' ), 10, 1 );

		$branding_options = array(
			'premiero_white_label_enabled',
			'premiero_white_label_name',
			'premiero_white_label_logo_id',
			'premiero_login_bg',
			'premiero_login_logo_id',
		);
		foreach ( $branding_options as $option_name ) {
			add_action( 'update_option_' . $option_name, array( __CLASS__, 'mark_dirty' ), 10, 3 );
			add_action( 'add_option_' . $option_name, array( __CLASS__, 'mark_dirty' ), 10, 2 );
		}

		/*
		 * UpdraftPlus ofrece un filtro al persistir el último backup. Se
		 * devuelve el valor intacto y el envío queda aplazado.
		 */
		add_filter( 'updraftplus_save_last_backup', array( __CLASS__, 'updraft_backup_saved' ), 999, 1 );
	}

	/**
	 * Inicialización idempotente para el hook de activación del plugin.
	 */
	public static function activate() {
		self::ensure_installation_id();

		if ( self::has_connection_config() ) {
			self::schedule_events();
		}
	}

	/**
	 * Retira los cron sin borrar la configuración, para que una reactivación
	 * pueda recuperar la conexión.
	 */
	public static function deactivate() {
		self::clear_scheduled_events();
		delete_option( self::OPT_LOCK );
		delete_option( self::OPT_SIZE_LOCK );
	}

	/**
	 * Añade intervalos propios sin depender de intervalos opcionales de Core.
	 *
	 * @param array $schedules Intervalos disponibles.
	 * @return array
	 */
	public static function add_cron_schedules( $schedules ) {
		$schedules[ self::SCHEDULE_SYNC ] = array(
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => 'Cada 12 horas (Premiero Console)',
		);
		$schedules[ self::SCHEDULE_SIZE ] = array(
			'interval' => 7 * DAY_IN_SECONDS,
			'display'  => 'Semanalmente (Premiero Console)',
		);
		return $schedules;
	}

	/**
	 * Repara cron eliminados únicamente durante una visita administrativa.
	 */
	public static function maybe_schedule_events() {
		if ( ! current_user_can( 'manage_options' ) || ! self::has_connection_config() ) {
			return;
		}
		self::schedule_events();
	}

	/**
	 * Callback común para cambios de plugins, temas, Core o identidad.
	 *
	 * @param mixed $arg1 Argumento ignorado.
	 * @param mixed $arg2 Argumento ignorado.
	 * @param mixed $arg3 Argumento ignorado.
	 */
	public static function mark_dirty( $arg1 = null, $arg2 = null, $arg3 = null ) {
		unset( $arg1, $arg2, $arg3 );

		if ( ! self::has_connection_config() ) {
			return;
		}
		self::schedule_sync_soon( 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Mantiene intacta la información filtrada por UpdraftPlus.
	 *
	 * @param array $last_backup Último backup.
	 * @return array
	 */
	public static function updraft_backup_saved( $last_backup ) {
		self::mark_dirty();
		return $last_backup;
	}

	/**
	 * Ejecuta un heartbeat programado.
	 */
	public static function cron_send_snapshot() {
		self::send_snapshot( false );
	}

	/**
	 * Ejecuta la recopilación de tamaños programada.
	 */
	public static function cron_collect_sizes() {
		self::collect_sizes();
	}

	/**
	 * Procesa los formularios renderizados por render_tab().
	 */
	public static function process_admin_forms() {
		if (
			! is_admin()
			|| 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '' )
			|| empty( $_POST['premiero_console_action'] )
		) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permisos para administrar la conexión de mantenimiento.' );
		}

		check_admin_referer( self::NONCE_ACTION );
		$action = sanitize_key( wp_unslash( $_POST['premiero_console_action'] ) );

		if ( 'pair' === $action ) {
			$console_url = isset( $_POST['premiero_console_url'] )
				? esc_url_raw( wp_unslash( $_POST['premiero_console_url'] ) )
				: '';
			$token = isset( $_POST['premiero_console_pairing_token'] )
				? sanitize_text_field( wp_unslash( $_POST['premiero_console_pairing_token'] ) )
				: '';

			$result = self::pair( $console_url, $token );
			self::redirect_with_status( is_wp_error( $result ) ? 'pair-error' : 'paired' );
		}

		if ( 'disconnect' === $action ) {
			self::disconnect();
			self::redirect_with_status( 'disconnected' );
		}

		if ( 'send' === $action ) {
			$result = self::send_snapshot( true );
			self::redirect_with_status( is_wp_error( $result ) ? 'send-error' : 'sent' );
		}

		if ( 'sizes' === $action ) {
			if ( self::has_connection_config() ) {
				self::schedule_size_soon();
				self::redirect_with_status( 'sizes-scheduled' );
			}
			self::redirect_with_status( 'not-connected' );
		}

		self::redirect_with_status( 'invalid-action' );
	}

	/**
	 * Dibuja la interfaz de conexión. El archivo principal únicamente necesita
	 * invocar este método desde el case de su pestaña.
	 */
	public static function render_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$enabled                = (bool) get_option( self::OPT_ENABLED, false );
		$api_base               = self::get_api_base();
		$installation_id        = self::ensure_installation_id();
		$remote_installation_id = sanitize_text_field( (string) get_option( self::OPT_REMOTE_INSTALLATION_ID, '' ) );
		$key_id                 = sanitize_text_field( (string) get_option( self::OPT_KEY_ID, '' ) );
		$last_sent              = (int) get_option( self::OPT_LAST_SENT, 0 );
		$last_http              = (int) get_option( self::OPT_LAST_HTTP, 0 );
		$last_error             = get_option( self::OPT_LAST_ERROR, array() );
		$size_cache             = get_option( self::OPT_SIZE_CACHE, array() );
		$next_sync              = wp_next_scheduled( self::CRON_SYNC );
		$secret                 = self::get_secret();
		$connected              = $enabled
			&& $api_base
			&& self::is_uuid( $remote_installation_id )
			&& self::is_uuid( $key_id )
			&& ! is_wp_error( $secret )
			&& '' !== $secret;

		self::render_status_notice();
		?>
		<div class="premiero-console-client">
			<h2>Consola de mantenimiento</h2>
			<p>
				Esta conexión envía una instantánea técnica a tu consola privada.
				No permite iniciar sesión, instalar, actualizar ni ejecutar acciones
				en este WordPress de forma remota.
			</p>

			<table class="widefat striped" style="max-width:920px;margin:18px 0;">
				<tbody>
					<tr>
						<th style="width:230px;">Estado</th>
						<td>
							<strong style="color:<?php echo $connected ? '#116329' : '#8a2424'; ?>;">
								<?php echo $connected ? 'Conectado' : 'Sin conectar'; ?>
							</strong>
							<?php if ( $enabled && is_wp_error( $secret ) ) : ?>
								<p class="description">
									El secreto guardado ya no puede descifrarse. Esto suele ocurrir
									si han cambiado las salts de WordPress; vuelve a emparejar la web.
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>ID de instalación</th>
						<td><code><?php echo esc_html( $installation_id ); ?></code></td>
					</tr>
					<?php if ( $api_base ) : ?>
						<tr>
							<th>API de la consola</th>
							<td><code><?php echo esc_html( $api_base ); ?></code></td>
						</tr>
					<?php endif; ?>
					<?php if ( $remote_installation_id ) : ?>
						<tr>
							<th>ID asignado por la consola</th>
							<td><code><?php echo esc_html( $remote_installation_id ); ?></code></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th>Último envío correcto</th>
						<td>
							<?php echo $last_sent ? esc_html( self::format_timestamp( $last_sent ) ) : 'Todavía no se ha enviado información.'; ?>
							<?php echo $last_http ? ' · HTTP ' . esc_html( (string) $last_http ) : ''; ?>
						</td>
					</tr>
					<tr>
						<th>Próxima sincronización</th>
						<td><?php echo $next_sync ? esc_html( self::format_timestamp( $next_sync ) ) : 'No programada'; ?></td>
					</tr>
					<tr>
						<th>Tamaño de la instalación</th>
						<td>
							<?php
							if ( is_array( $size_cache ) && isset( $size_cache['total_bytes'] ) && null !== $size_cache['total_bytes'] ) {
								echo esc_html( size_format( (int) $size_cache['total_bytes'], 2 ) );
								if ( ! empty( $size_cache['captured_at'] ) ) {
									echo ' · calculado ' . esc_html( self::format_timestamp( (int) $size_cache['captured_at'] ) );
								}
								if ( empty( $size_cache['complete'] ) ) {
									echo ' · resultado parcial';
								}
							} else {
								echo 'Pendiente de cálculo';
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>

			<?php if ( is_array( $last_error ) && ! empty( $last_error['code'] ) ) : ?>
				<div class="notice notice-warning inline" style="max-width:880px;">
					<p>
						<strong>Último error:</strong>
						<?php echo esc_html( (string) $last_error['code'] ); ?>
						<?php
						if ( ! empty( $last_error['message'] ) ) {
							echo ' · ' . esc_html( (string) $last_error['message'] );
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! $connected ) : ?>
				<form method="post" style="max-width:760px;margin-top:22px;">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="premiero_console_action" value="pair">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="premiero-console-url">URL de la consola</label>
							</th>
							<td>
								<input
									type="url"
									class="regular-text code"
									id="premiero-console-url"
									name="premiero_console_url"
									value="<?php echo esc_attr( self::get_configured_console_url_for_form() ); ?>"
									placeholder="https://tu-consola.example"
									required
								>
								<p class="description">
									Puedes introducir la portada de la consola o su base REST
									<code>/wp-json/<?php echo esc_html( self::API_NAMESPACE ); ?></code>.
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="premiero-console-token">Token de emparejamiento</label>
							</th>
							<td>
								<input
									type="password"
									class="regular-text"
									id="premiero-console-token"
									name="premiero_console_pairing_token"
									value=""
									autocomplete="new-password"
									required
								>
								<p class="description">
									El token es de un solo uso y no se guarda en esta instalación.
								</p>
							</td>
						</tr>
					</table>
					<?php submit_button( 'Emparejar instalación', 'primary' ); ?>
				</form>
			<?php else : ?>
				<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:22px;">
					<form method="post" style="margin:0;">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<input type="hidden" name="premiero_console_action" value="send">
						<?php submit_button( 'Enviar estado ahora', 'primary', 'submit', false ); ?>
					</form>
					<form method="post" style="margin:0;">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<input type="hidden" name="premiero_console_action" value="sizes">
						<?php submit_button( 'Recalcular tamaño', 'secondary', 'submit', false ); ?>
					</form>
					<form method="post" style="margin:0;" onsubmit="return window.confirm('¿Desconectar esta instalación? Se detendrán los envíos y se borrará la clave local. Para invalidarla también en la consola, usa allí «Revocar conexión».');">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<input type="hidden" name="premiero_console_action" value="disconnect">
						<?php submit_button( 'Desconectar', 'delete', 'submit', false ); ?>
					</form>
				</div>
			<?php endif; ?>

			<div style="max-width:920px;margin-top:28px;padding:16px 18px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;">
				<h3 style="margin-top:0;">Información enviada</h3>
				<p style="margin-bottom:8px;">
					URL y nombre del sitio, URL de acceso administrativo, versiones de
					WordPress/PHP/toolkit, actualizaciones pendientes, identidad visual,
					última copia de UpdraftPlus, estado resumido de Wordfence y tamaños
					cacheados de la instalación.
				</p>
				<p style="margin-bottom:0;">
					No se envían usuarios, contraseñas, credenciales, claves de Wordfence,
					destinos de copias, rutas de archivos, logs ni contenido de incidencias.
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Empareja la web mediante un token de un solo uso.
	 *
	 * @param string $console_url URL de la consola o base API.
	 * @param string $token       Token temporal.
	 * @return true|WP_Error
	 */
	public static function pair( $console_url, $token ) {
		$api_base = self::normalize_api_base( $console_url );
		if ( is_wp_error( $api_base ) ) {
			self::store_error( $api_base->get_error_code(), $api_base->get_error_message() );
			return $api_base;
		}

		$token = trim( (string) $token );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $token ) ) {
			$error = new WP_Error( 'invalid_pairing_token', 'El token de emparejamiento no tiene un formato válido.' );
			self::store_error( $error->get_error_code(), $error->get_error_message() );
			return $error;
		}

		$instance_id = self::ensure_installation_id();
		$payload     = array_merge(
			array(
				'token'       => $token,
				'instance_id' => $instance_id,
			),
			self::collect_pairing_site_data()
		);
		$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			$error = new WP_Error( 'pairing_json_error', 'No se pudo preparar la solicitud de emparejamiento.' );
			self::store_error( $error->get_error_code(), $error->get_error_message() );
			return $error;
		}

		$response = wp_safe_remote_post(
			$api_base . '/pair',
			array(
				'timeout'             => 10,
				'redirection'         => 2,
				'sslverify'           => true,
				'limit_response_size' => 64 * KB_IN_BYTES,
				'headers'             => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json; charset=utf-8',
					'User-Agent'   => self::user_agent(),
				),
				'body'                => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::store_error( $response->get_error_code(), $response->get_error_message() );
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$error = new WP_Error(
				'pairing_http_' . $status_code,
				'La consola rechazó el emparejamiento (HTTP ' . $status_code . ').'
			);
			self::store_error( $error->get_error_code(), $error->get_error_message(), $status_code );
			return $error;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$data = array_merge( $data, $data['data'] );
		}

		$secret                 = is_array( $data ) && isset( $data['secret'] ) && is_scalar( $data['secret'] )
			? (string) $data['secret']
			: '';
		$remote_installation_id = is_array( $data ) && isset( $data['installation_id'] ) && is_scalar( $data['installation_id'] )
			? strtolower( sanitize_text_field( (string) $data['installation_id'] ) )
			: '';
		$key_id                  = is_array( $data ) && isset( $data['key_id'] ) && is_scalar( $data['key_id'] )
			? strtolower( sanitize_text_field( (string) $data['key_id'] ) )
			: '';

		if (
			! preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $secret )
			|| ! self::is_uuid( $remote_installation_id )
			|| ! self::is_uuid( $key_id )
		) {
			$error = new WP_Error( 'pairing_credentials_missing', 'La consola no devolvió credenciales de emparejamiento válidas.' );
			self::store_error( $error->get_error_code(), $error->get_error_message(), $status_code );
			return $error;
		}

		$encrypted = self::encrypt_secret( $secret );
		if ( is_wp_error( $encrypted ) ) {
			self::store_error( $encrypted->get_error_code(), $encrypted->get_error_message() );
			return $encrypted;
		}

		self::update_private_option( self::OPT_API_BASE, $api_base );
		self::update_private_option( self::OPT_SECRET, $encrypted );
		self::update_private_option( self::OPT_REMOTE_INSTALLATION_ID, $remote_installation_id );
		self::update_private_option( self::OPT_KEY_ID, $key_id );
		self::update_private_option( self::OPT_ENABLED, 1 );
		self::update_private_option( self::OPT_FAILURES, 0 );
		self::update_private_option( self::OPT_NEXT_ATTEMPT, 0 );
		self::update_private_option( self::OPT_LAST_ERROR, array() );
		self::update_private_option( self::OPT_LAST_HTTP, $status_code );

		self::schedule_events();
		self::schedule_sync_soon( 10 );
		self::schedule_size_soon( 60 );

		return true;
	}

	/**
	 * Desconecta la instalación y elimina el secreto local.
	 */
	public static function disconnect() {
		self::clear_scheduled_events();

		delete_option( self::OPT_ENABLED );
		delete_option( self::OPT_API_BASE );
		delete_option( self::OPT_REMOTE_INSTALLATION_ID );
		delete_option( self::OPT_KEY_ID );
		delete_option( self::OPT_SECRET );
		delete_option( self::OPT_FAILURES );
		delete_option( self::OPT_NEXT_ATTEMPT );
		delete_option( self::OPT_PAYLOAD_HASH );
		delete_option( self::OPT_LAST_ERROR );
		delete_option( self::OPT_LOCK );
		delete_option( self::OPT_SIZE_LOCK );
	}

	/**
	 * Genera y envía una instantánea ligera.
	 *
	 * @param bool $force Ignora temporalmente el backoff.
	 * @return true|WP_Error
	 */
	public static function send_snapshot( $force = false ) {
		if ( ! self::has_connection_config() ) {
			return new WP_Error( 'console_not_connected', 'La instalación no está conectada a la consola.' );
		}

		$next_attempt = (int) get_option( self::OPT_NEXT_ATTEMPT, 0 );
		if ( ! $force && $next_attempt > time() ) {
			return new WP_Error( 'console_backoff', 'El siguiente intento está aplazado temporalmente.' );
		}

		if ( ! self::acquire_lock( self::OPT_LOCK, 5 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'console_sync_locked', 'Ya hay una sincronización en curso.' );
		}

		try {
			$secret = self::get_secret();
			if ( is_wp_error( $secret ) ) {
				self::record_send_failure( $secret );
				return $secret;
			}

			$api_base = self::get_api_base();
			if ( ! $api_base ) {
				$error = new WP_Error( 'console_endpoint_missing', 'No se ha configurado la API de la consola.' );
				self::record_send_failure( $error );
				return $error;
			}

			$payload = self::build_snapshot();
			$payload = apply_filters( 'premiero_console_snapshot', $payload );
			if ( ! is_array( $payload ) ) {
				$error = new WP_Error( 'console_payload_invalid', 'La instantánea filtrada no es válida.' );
				self::record_send_failure( $error );
				return $error;
			}

			$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
			if ( false === $body ) {
				$error = new WP_Error( 'console_json_error', 'No se pudo codificar la instantánea.' );
				self::record_send_failure( $error );
				return $error;
			}
			if ( strlen( $body ) > 64 * KB_IN_BYTES ) {
				$error = new WP_Error( 'console_payload_too_large', 'La instantánea supera el tamaño máximo permitido.' );
				self::record_send_failure( $error );
				return $error;
			}

			$remote_installation_id = strtolower( sanitize_text_field( (string) get_option( self::OPT_REMOTE_INSTALLATION_ID, '' ) ) );
			$key_id                 = strtolower( sanitize_text_field( (string) get_option( self::OPT_KEY_ID, '' ) ) );
			if ( ! self::is_uuid( $remote_installation_id ) || ! self::is_uuid( $key_id ) ) {
				$error = new WP_Error( 'console_credentials_invalid', 'La identificación guardada de la consola no es válida.' );
				self::record_send_failure( $error );
				return $error;
			}

			$timestamp     = (string) time();
			$request_nonce = self::generate_request_nonce();
			$canonical     = implode(
				"\n",
				array(
					self::SIGNATURE_VERSION,
					'POST',
					self::TELEMETRY_CANONICAL,
					$remote_installation_id,
					$key_id,
					$timestamp,
					$request_nonce,
					hash( 'sha256', $body ),
				)
			);
			$signature = 'v1=' . self::base64url_encode(
				hash_hmac( 'sha256', $canonical, $secret, true )
			);
			$headers = array(
				'Accept'                => 'application/json',
				'Content-Type'          => 'application/json; charset=utf-8',
				'User-Agent'            => self::user_agent(),
				'X-Premiero-Install-ID' => $remote_installation_id,
				'X-Premiero-Key-ID'     => $key_id,
				'X-Premiero-Timestamp'  => $timestamp,
				'X-Premiero-Nonce'      => $request_nonce,
				'X-Premiero-Signature'  => $signature,
			);

			$response = wp_safe_remote_post(
				$api_base . '/telemetry',
				array(
					'timeout'             => 8,
					'redirection'         => 2,
					'sslverify'           => true,
					'limit_response_size' => 64 * KB_IN_BYTES,
					'headers'             => $headers,
					'body'                => $body,
				)
			);

			if ( is_wp_error( $response ) ) {
				self::record_send_failure( $response );
				return $response;
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );
			if ( $status_code < 200 || $status_code >= 300 ) {
				$error = new WP_Error(
					'console_http_' . $status_code,
					'La consola devolvió HTTP ' . $status_code . '.'
				);
				self::record_send_failure( $error, $status_code, $response );
				return $error;
			}

			self::update_private_option( self::OPT_LAST_SENT, time() );
			self::update_private_option( self::OPT_LAST_HTTP, $status_code );
			self::update_private_option( self::OPT_LAST_ERROR, array() );
			self::update_private_option( self::OPT_FAILURES, 0 );
			self::update_private_option( self::OPT_NEXT_ATTEMPT, 0 );
			self::update_private_option( self::OPT_PAYLOAD_HASH, hash( 'sha256', $body ) );

			do_action( 'premiero_console_snapshot_sent', $payload, $status_code );
			return true;
		} catch ( \Throwable $error ) {
			$wp_error = new WP_Error( 'console_unexpected_error', $error->getMessage() );
			self::record_send_failure( $wp_error );
			return $wp_error;
		} finally {
			delete_option( self::OPT_LOCK );
		}
	}

	/**
	 * Construye una instantánea sin cálculos recursivos ni llamadas externas.
	 *
	 * @return array
	 */
	public static function build_snapshot() {
		$plugins      = self::get_plugins_data();
		$updates      = self::collect_updates( $plugins );
		$backup       = self::collect_updraftplus( $plugins );
		$security     = self::collect_wordfence( $plugins );
		$branding     = self::collect_branding();
		$storage      = self::get_cached_sizes_for_payload();
		$plugin_items = self::normalize_update_items(
			isset( $updates['plugins']['items'] ) ? $updates['plugins']['items'] : array()
		);
		$theme_items  = self::normalize_update_items(
			isset( $updates['themes']['items'] ) ? $updates['themes']['items'] : array()
		);

		$plugin_count = isset( $updates['plugins']['count'] ) && null !== $updates['plugins']['count']
			? absint( $updates['plugins']['count'] )
			: count( $plugin_items );
		$theme_count  = isset( $updates['themes']['count'] ) && null !== $updates['themes']['count']
			? absint( $updates['themes']['count'] )
			: count( $theme_items );
		$updates_status = 'fresh';
		foreach ( array( 'plugins', 'themes', 'core' ) as $update_type ) {
			if ( empty( $updates[ $update_type ]['checked_at'] ) ) {
				$updates_status = 'unknown';
				break;
			}
			if ( ! empty( $updates[ $update_type ]['stale'] ) ) {
				$updates_status = 'stale';
			}
		}

		$backup_states = array(
			'success'     => 'success',
			'warning'     => 'partial',
			'error'       => 'failed',
			'unknown'     => 'unknown',
			'never'       => 'unknown',
			'unavailable' => 'missing',
		);
		$backup_state  = isset( $backup_states[ $backup['state'] ] ) ? $backup_states[ $backup['state'] ] : 'unknown';
		$last_backup   = in_array( $backup_state, array( 'success', 'partial' ), true ) && ! empty( $backup['last_at'] )
			? gmdate( 'Y-m-d\TH:i:s\Z', (int) $backup['last_at'] )
			: null;

		$security_states = array(
			'clean'       => 'clean',
			'issues'      => 'issues',
			'running'     => 'warning',
			'failed'      => 'failed',
			'unknown'     => 'unknown',
			'never'       => 'unknown',
			'unavailable' => 'missing',
		);
		$security_state = isset( $security_states[ $security['state'] ] ) ? $security_states[ $security['state'] ] : 'unknown';
		$last_scan      = ! empty( $security['last_scan_at'] )
			? gmdate( 'Y-m-d\TH:i:s\Z', (int) $security['last_scan_at'] )
			: null;
		$issue_count    = isset( $security['issue_count'] ) && null !== $security['issue_count']
			? absint( $security['issue_count'] )
			: 0;

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'snapshot_id'    => wp_generate_uuid4(),
			'instance_id'    => self::ensure_installation_id(),
			'generated_at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'agent'          => array(
				'version' => defined( 'PREMIERO_ATK_VER' ) ? (string) PREMIERO_ATK_VER : '',
			),
			'site'           => array(
				'site_url'    => esc_url_raw( site_url( '/' ) ),
				'home_url'    => esc_url_raw( home_url( '/' ) ),
				'admin_url'   => esc_url_raw( admin_url( '/' ) ),
				'name'        => sanitize_text_field( get_bloginfo( 'name' ) ),
				'wp_version'  => sanitize_text_field( get_bloginfo( 'version' ) ),
				'php_version' => sanitize_text_field( PHP_VERSION ),
				'size_bytes'  => isset( $storage['total_bytes'] ) ? self::normalize_size_value( $storage['total_bytes'] ) : null,
			),
			'identity'       => array(
				'mode'     => 'white_label' === $branding['mode'] ? 'white_label' : 'premiero',
				'name'     => sanitize_text_field( get_bloginfo( 'name' ) ),
				'logo_url' => $branding['client_logo_url'],
				'color'    => $branding['background_color'],
			),
			'updates'       => array(
				'status'          => $updates_status,
				'core_pending'    => ! empty( $updates['core']['update'] ),
				'plugins_pending' => min( 65535, $plugin_count ),
				'themes_pending'  => min( 65535, $theme_count ),
				'plugins'         => $plugin_items,
				'themes'          => $theme_items,
			),
			'backup'        => array(
				'provider'        => 'updraftplus',
				'status'          => $backup_state,
				'last_success_at' => $last_backup,
			),
			'security'      => array(
				'provider'     => 'wordfence',
				'status'       => $security_state,
				'last_scan_at' => $last_scan,
				'issues'       => array(
					'critical' => 0,
					'high'     => 0,
					'medium'   => 0,
					/*
					 * Wordfence expone aquí un total sin severidad fiable.
					 * Se informa como incidencia genérica para no convertirla
					 * artificialmente en una alerta crítica.
					 */
					'low'      => min( 65535, $issue_count ),
				),
			),
		);
	}

	/**
	 * Calcula tamaños en un cron separado y con presupuesto de tiempo.
	 *
	 * @return array|WP_Error
	 */
	public static function collect_sizes() {
		if ( ! self::has_connection_config() ) {
			return new WP_Error( 'console_not_connected', 'La instalación no está conectada a la consola.' );
		}

		if ( ! self::acquire_lock( self::OPT_SIZE_LOCK, 30 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'console_size_locked', 'Ya hay un cálculo de tamaños en curso.' );
		}

		try {
			if ( ! class_exists( 'WP_Debug_Data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
			}

			$time_limit = (int) apply_filters( 'premiero_console_size_time_limit', 12 );
			$time_limit = max( 5, min( 20, $time_limit ) );
			$dir_cache  = array();
			$upload_dir = wp_get_upload_dir();

			$wordpress_size = recurse_dirsize(
				untrailingslashit( ABSPATH ),
				null,
				$time_limit,
				$dir_cache
			);

			$plugins_size = recurse_dirsize(
				untrailingslashit( WP_PLUGIN_DIR ),
				null,
				$time_limit,
				$dir_cache
			);
			$themes_size = recurse_dirsize(
				untrailingslashit( get_theme_root() ),
				null,
				$time_limit,
				$dir_cache
			);

			$uploads_size = null;
			if ( ! empty( $upload_dir['basedir'] ) ) {
				$uploads_size = recurse_dirsize(
					untrailingslashit( $upload_dir['basedir'] ),
					null,
					$time_limit,
					$dir_cache
				);
			}

			$database_size = method_exists( 'WP_Debug_Data', 'get_database_size' )
				? WP_Debug_Data::get_database_size()
				: 0;

			$wordpress_bytes = self::normalize_size_value( $wordpress_size );
			$database_bytes  = self::normalize_size_value( $database_size );
			$total_bytes     = null;
			if ( null !== $wordpress_bytes && null !== $database_bytes ) {
				$total_bytes = $wordpress_bytes + $database_bytes;
			}

			$result = array(
				'captured_at'     => time(),
				'complete'        => null !== $total_bytes,
				'total_bytes'     => $total_bytes,
				'wordpress_bytes' => $wordpress_bytes,
				'database_bytes'  => $database_bytes,
				'plugins_bytes'   => self::normalize_size_value( $plugins_size ),
				'themes_bytes'    => self::normalize_size_value( $themes_size ),
				'uploads_bytes'   => self::normalize_size_value( $uploads_size ),
			);

			self::update_private_option( self::OPT_SIZE_CACHE, $result );
			self::schedule_sync_soon( 2 * MINUTE_IN_SECONDS );
			return $result;
		} catch ( \Throwable $error ) {
			$result = array(
				'captured_at' => time(),
				'complete'    => false,
				'total_bytes' => null,
				'error'       => sanitize_key( 'size_collection_failed' ),
			);
			self::update_private_option( self::OPT_SIZE_CACHE, $result );
			return new WP_Error( 'size_collection_failed', $error->getMessage() );
		} finally {
			delete_option( self::OPT_SIZE_LOCK );
		}
	}

	/**
	 * Datos mínimos enviados al emparejar, antes de disponer de HMAC.
	 *
	 * @return array
	 */
	private static function collect_pairing_site_data() {
		return array(
			'site_url'     => esc_url_raw( site_url( '/' ) ),
			'home_url'     => esc_url_raw( home_url( '/' ) ),
			'admin_url'    => esc_url_raw( admin_url( '/' ) ),
			'site_name'    => sanitize_text_field( get_bloginfo( 'name' ) ),
			'agent_version'=> defined( 'PREMIERO_ATK_VER' ) ? (string) PREMIERO_ATK_VER : '',
		);
	}

	/**
	 * Recopila identidad de mantenimiento y la identidad visual del cliente.
	 *
	 * @return array
	 */
	private static function collect_branding() {
		$is_white_label = function_exists( 'premiero_is_white_label' )
			? (bool) premiero_is_white_label()
			: (
				(bool) get_option( 'premiero_white_label_enabled', false )
				&& '' !== trim( (string) get_option( 'premiero_white_label_name', '' ) )
			);

		$brand_name = function_exists( 'premiero_get_brand_name' )
			? premiero_get_brand_name()
			: ( $is_white_label ? get_option( 'premiero_white_label_name', '' ) : 'Premiero' );

		$maintenance_logo = '';
		if ( function_exists( 'premiero_get_brand_logo_url' ) ) {
			$maintenance_logo = premiero_get_brand_logo_url( 'full' );
		}

		/*
		 * El logo del cliente no usa premiero_get_login_logo_url(), porque su
		 * fallback puede ser el logo de la marca de mantenimiento.
		 */
		$client_logo_id  = (int) get_option( 'premiero_login_logo_id', 0 );
		$client_logo_url = self::attachment_url( $client_logo_id );
		if ( ! $client_logo_url ) {
			$client_logo_url = self::attachment_url( (int) get_theme_mod( 'custom_logo', 0 ) );
		}

		$background = sanitize_hex_color( trim( (string) get_option( 'premiero_login_bg', '' ) ) );

		return array(
			'mode'                   => $is_white_label ? 'white_label' : 'direct',
			'maintenance_brand_name' => sanitize_text_field( (string) $brand_name ),
			'maintenance_logo_url'   => esc_url_raw( (string) $maintenance_logo ),
			'client_logo_url'        => esc_url_raw( (string) $client_logo_url ),
			'background_color'       => $background ? $background : '',
		);
	}

	/**
	 * Lee transients ya mantenidos por WordPress. No fuerza búsquedas remotas.
	 *
	 * @param array $plugins Plugins instalados.
	 * @return array
	 */
	private static function collect_updates( $plugins ) {
		$now              = time();
		$plugin_transient = get_site_transient( 'update_plugins' );
		$theme_transient  = get_site_transient( 'update_themes' );
		$core_transient   = get_site_transient( 'update_core' );

		$plugin_items = array();
		if ( is_object( $plugin_transient ) && ! empty( $plugin_transient->response ) && is_array( $plugin_transient->response ) ) {
			foreach ( $plugin_transient->response as $plugin_file => $update ) {
				if ( count( $plugin_items ) >= 100 ) {
					break;
				}
				$installed_version = '';
				if ( isset( $plugins[ $plugin_file ]['Version'] ) ) {
					$installed_version = (string) $plugins[ $plugin_file ]['Version'];
				} elseif ( isset( $plugin_transient->checked[ $plugin_file ] ) ) {
					$installed_version = (string) $plugin_transient->checked[ $plugin_file ];
				}
				$update_slug    = self::read_update_field( $update, 'slug', dirname( $plugin_file ) );
				$new_version    = self::read_update_field( $update, 'new_version', '' );
				$plugin_items[] = array(
					'slug'      => sanitize_key( (string) $update_slug ),
					'name'      => isset( $plugins[ $plugin_file ]['Name'] ) ? sanitize_text_field( $plugins[ $plugin_file ]['Name'] ) : '',
					'installed' => sanitize_text_field( $installed_version ),
					'available' => sanitize_text_field( (string) $new_version ),
				);
			}
		}

		$theme_items = array();
		if ( is_object( $theme_transient ) && ! empty( $theme_transient->response ) && is_array( $theme_transient->response ) ) {
			foreach ( $theme_transient->response as $stylesheet => $update ) {
				if ( count( $theme_items ) >= 100 ) {
					break;
				}
				$theme = wp_get_theme( $stylesheet );
				$new_version  = self::read_update_field( $update, 'new_version', '' );
				$theme_items[] = array(
					'slug'      => sanitize_key( $stylesheet ),
					'name'      => $theme->exists() ? sanitize_text_field( $theme->get( 'Name' ) ) : '',
					'installed' => $theme->exists() ? sanitize_text_field( $theme->get( 'Version' ) ) : '',
					'available' => sanitize_text_field( (string) $new_version ),
				);
			}
		}

		$core_update = null;
		if ( is_object( $core_transient ) && ! empty( $core_transient->updates ) && is_array( $core_transient->updates ) ) {
			foreach ( $core_transient->updates as $update ) {
				if ( 'upgrade' === self::read_update_field( $update, 'response', '' ) ) {
					$core_update = array(
						'installed' => sanitize_text_field( get_bloginfo( 'version' ) ),
						'available' => sanitize_text_field( (string) self::read_update_field( $update, 'current', '' ) ),
					);
					break;
				}
			}
		}

		$plugin_checked = is_object( $plugin_transient ) && isset( $plugin_transient->last_checked )
			? (int) $plugin_transient->last_checked
			: 0;
		$theme_checked = is_object( $theme_transient ) && isset( $theme_transient->last_checked )
			? (int) $theme_transient->last_checked
			: 0;
		$core_checked = is_object( $core_transient ) && isset( $core_transient->last_checked )
			? (int) $core_transient->last_checked
			: 0;

		$plugin_total = is_object( $plugin_transient ) && isset( $plugin_transient->response ) && is_array( $plugin_transient->response )
			? count( $plugin_transient->response )
			: null;
		$theme_total = is_object( $theme_transient ) && isset( $theme_transient->response ) && is_array( $theme_transient->response )
			? count( $theme_transient->response )
			: null;

		return array(
			'plugins' => array(
				'count'      => $plugin_total,
				'items'      => $plugin_items,
				'checked_at' => $plugin_checked,
				'stale'      => ! $plugin_checked || ( $now - $plugin_checked ) > DAY_IN_SECONDS,
			),
			'themes'  => array(
				'count'      => $theme_total,
				'items'      => $theme_items,
				'checked_at' => $theme_checked,
				'stale'      => ! $theme_checked || ( $now - $theme_checked ) > DAY_IN_SECONDS,
			),
			'core'    => array(
				'update'     => $core_update,
				'checked_at' => $core_checked,
				'stale'      => ! $core_checked || ( $now - $core_checked ) > DAY_IN_SECONDS,
			),
		);
	}

	/**
	 * Adaptador defensivo de UpdraftPlus.
	 *
	 * @param array $plugins Plugins instalados.
	 * @return array
	 */
	private static function collect_updraftplus( $plugins ) {
		$plugin_file = 'updraftplus/updraftplus.php';
		$installed   = isset( $plugins[ $plugin_file ] );
		$active      = self::is_plugin_active( $plugin_file );
		$result      = array(
			'provider' => 'updraftplus',
			'installed'=> $installed,
			'active'   => $active,
			'version'  => $installed && isset( $plugins[ $plugin_file ]['Version'] )
				? sanitize_text_field( (string) $plugins[ $plugin_file ]['Version'] )
				: '',
			'state'    => $active ? 'never' : 'unavailable',
			'last_at'  => 0,
			'warnings' => 0,
			'errors'   => 0,
		);

		if ( ! $active ) {
			return $result;
		}

		try {
			if ( class_exists( 'UpdraftPlus_Options' ) && method_exists( 'UpdraftPlus_Options', 'get_updraft_option' ) ) {
				$last_backup = UpdraftPlus_Options::get_updraft_option( 'updraft_last_backup', false );
			} else {
				$last_backup = get_option( 'updraft_last_backup', false );
			}

			if ( ! is_array( $last_backup ) || empty( $last_backup['backup_time'] ) ) {
				return $result;
			}

			$result['last_at'] = (int) $last_backup['backup_time'];
			$errors            = isset( $last_backup['errors'] ) && is_array( $last_backup['errors'] )
				? $last_backup['errors']
				: array();

			foreach ( $errors as $error ) {
				if ( is_array( $error ) && isset( $error['level'] ) && 'warning' === $error['level'] ) {
					++$result['warnings'];
				} else {
					++$result['errors'];
				}
			}

			if ( ! empty( $last_backup['success'] ) && 0 === $result['errors'] ) {
				$result['state'] = $result['warnings'] ? 'warning' : 'success';
			} else {
				$result['state'] = 'error';
			}
		} catch ( \Throwable $error ) {
			$result['state'] = 'unknown';
		}

		return $result;
	}

	/**
	 * Adaptador defensivo de Wordfence.
	 *
	 * @param array $plugins Plugins instalados.
	 * @return array
	 */
	private static function collect_wordfence( $plugins ) {
		$plugin_file = 'wordfence/wordfence.php';
		$installed   = isset( $plugins[ $plugin_file ] );
		$active      = self::is_plugin_active( $plugin_file );
		$result      = array(
			'provider'     => 'wordfence',
			'installed'    => $installed,
			'active'       => $active,
			'version'      => $installed && isset( $plugins[ $plugin_file ]['Version'] )
				? sanitize_text_field( (string) $plugins[ $plugin_file ]['Version'] )
				: '',
			'state'        => $active ? 'never' : 'unavailable',
			'last_scan_at' => 0,
			'issue_count'  => null,
			'failure_code' => '',
		);

		if ( ! $active ) {
			return $result;
		}

		try {
			if ( ! class_exists( 'wfConfig' ) ) {
				$result['state'] = 'unknown';
				return $result;
			}

			$last_completed = wfConfig::get( 'lastScanCompleted', false );
			$scanner        = class_exists( 'wfScanner' ) && method_exists( 'wfScanner', 'shared' )
				? wfScanner::shared()
				: null;

			if ( $scanner && method_exists( $scanner, 'lastScanTime' ) ) {
				$result['last_scan_at'] = (int) $scanner->lastScanTime();
			}
			if ( $scanner && method_exists( $scanner, 'isRunning' ) && $scanner->isRunning() ) {
				$result['state'] = 'running';
			}

			if ( class_exists( 'wfIssues' ) && method_exists( 'wfIssues', 'shared' ) ) {
				$issues = wfIssues::shared();
				if ( is_object( $issues ) && method_exists( $issues, 'getIssueCount' ) ) {
					$result['issue_count'] = (int) $issues->getIssueCount();
				}
				if ( method_exists( 'wfIssues', 'hasScanFailed' ) ) {
					$failure_code = wfIssues::hasScanFailed();
					if ( $failure_code ) {
						$result['failure_code'] = sanitize_key( (string) $failure_code );
					}
				}
			}

			if ( $result['failure_code'] ) {
				$result['state'] = 'failed';
			}

			if ( ! in_array( $result['state'], array( 'running', 'failed' ), true ) ) {
				if ( false === $last_completed || '' === $last_completed || null === $last_completed ) {
					$result['state'] = 'never';
				} elseif ( 'ok' === $last_completed ) {
					$result['state'] = $result['issue_count'] > 0 ? 'issues' : 'clean';
				} else {
					$result['state'] = 'failed';
				}
			}
		} catch ( \Throwable $error ) {
			$result['state'] = 'unknown';
		}

		return $result;
	}

	/**
	 * Devuelve únicamente los tamaños cacheados.
	 *
	 * @return array
	 */
	private static function get_cached_sizes_for_payload() {
		$cached = get_option( self::OPT_SIZE_CACHE, array() );
		if ( ! is_array( $cached ) || empty( $cached['captured_at'] ) ) {
			return array(
				'captured_at' => 0,
				'complete'    => false,
				'total_bytes' => null,
			);
		}

		$keys   = array(
			'captured_at',
			'complete',
			'total_bytes',
			'wordpress_bytes',
			'database_bytes',
			'plugins_bytes',
			'themes_bytes',
			'uploads_bytes',
		);
		$result = array();
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $cached ) ) {
				$result[ $key ] = $cached[ $key ];
			}
		}
		return $result;
	}

	/**
	 * Lee un campo de metadatos de actualización tanto si Core entrega un
	 * objeto como si otro actualizador utiliza un array.
	 *
	 * @param mixed  $update  Registro de actualización.
	 * @param string $key     Campo.
	 * @param mixed  $default Valor por defecto.
	 * @return mixed
	 */
	private static function read_update_field( $update, $key, $default = '' ) {
		if ( is_object( $update ) && isset( $update->{$key} ) ) {
			return $update->{$key};
		}
		if ( is_array( $update ) && isset( $update[ $key ] ) ) {
			return $update[ $key ];
		}
		return $default;
	}

	/**
	 * Adapta y limita el detalle de actualizaciones al contrato de la consola.
	 *
	 * @param array $items Elementos detectados por WordPress.
	 * @return array
	 */
	private static function normalize_update_items( $items ) {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$normalized = array();
		foreach ( array_slice( $items, 0, 50 ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['slug'] ) ) {
				continue;
			}

			$normalized[] = array(
				'slug'              => substr( sanitize_key( (string) $item['slug'] ), 0, 191 ),
				'name'              => substr( sanitize_text_field( isset( $item['name'] ) ? (string) $item['name'] : '' ), 0, 191 ),
				'current_version'   => substr( sanitize_text_field( isset( $item['installed'] ) ? (string) $item['installed'] : '' ), 0, 32 ),
				'available_version' => substr( sanitize_text_field( isset( $item['available'] ) ? (string) $item['available'] : '' ), 0, 32 ),
			);
		}

		return $normalized;
	}

	/**
	 * Carga una vez los datos de plugins durante el cron.
	 *
	 * @return array
	 */
	private static function get_plugins_data() {
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		return is_array( $plugins ) ? $plugins : array();
	}

	/**
	 * Comprueba activación normal o de red.
	 *
	 * @param string $plugin_file Archivo relativo del plugin.
	 * @return bool
	 */
	private static function is_plugin_active( $plugin_file ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( $plugin_file ) ) {
			return true;
		}
		return is_multisite()
			&& function_exists( 'is_plugin_active_for_network' )
			&& is_plugin_active_for_network( $plugin_file );
	}

	/**
	 * Obtiene la URL pública de un adjunto.
	 *
	 * @param int $attachment_id ID de adjunto.
	 * @return string
	 */
	private static function attachment_url( $attachment_id ) {
		if ( ! $attachment_id ) {
			return '';
		}
		$src = wp_get_attachment_image_src( $attachment_id, 'full' );
		return ! empty( $src[0] ) ? esc_url_raw( $src[0] ) : '';
	}

	/**
	 * Programa cron recurrentes con desfase aleatorio.
	 */
	private static function schedule_events() {
		if ( ! self::has_connection_config() ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_SYNC ) ) {
			wp_schedule_event(
				time() + wp_rand( 5 * MINUTE_IN_SECONDS, 45 * MINUTE_IN_SECONDS ),
				self::SCHEDULE_SYNC,
				self::CRON_SYNC
			);
		}

		if ( ! wp_next_scheduled( self::CRON_SIZE ) ) {
			wp_schedule_event(
				time() + wp_rand( 30 * MINUTE_IN_SECONDS, 6 * HOUR_IN_SECONDS ),
				self::SCHEDULE_SIZE,
				self::CRON_SIZE
			);
		}
	}

	/**
	 * Deduplica un envío cercano.
	 *
	 * @param int $delay Segundos.
	 */
	private static function schedule_sync_soon( $delay = 300 ) {
		if ( wp_next_scheduled( self::CRON_SYNC_SOON ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 1, (int) $delay ), self::CRON_SYNC_SOON );
	}

	/**
	 * Deduplica un cálculo de tamaños cercano.
	 *
	 * @param int $delay Segundos.
	 */
	private static function schedule_size_soon( $delay = 1 ) {
		if ( wp_next_scheduled( self::CRON_SIZE_SOON ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 1, (int) $delay ), self::CRON_SIZE_SOON );
	}

	/**
	 * Elimina cron recurrentes y eventos únicos.
	 */
	private static function clear_scheduled_events() {
		wp_clear_scheduled_hook( self::CRON_SYNC );
		wp_clear_scheduled_hook( self::CRON_SYNC_SOON );
		wp_clear_scheduled_hook( self::CRON_SIZE );
		wp_clear_scheduled_hook( self::CRON_SIZE_SOON );
	}

	/**
	 * Determina si hay configuración suficiente sin descifrar el secreto.
	 *
	 * @return bool
	 */
	private static function has_connection_config() {
		$remote_installation_id = (string) get_option( self::OPT_REMOTE_INSTALLATION_ID, '' );
		$key_id                 = (string) get_option( self::OPT_KEY_ID, '' );

		return (bool) get_option( self::OPT_ENABLED, false )
			&& '' !== self::get_api_base()
			&& self::is_uuid( $remote_installation_id )
			&& self::is_uuid( $key_id )
			&& '' !== (string) get_option( self::OPT_SECRET, '' );
	}

	/**
	 * URL API, permitiendo fijarla mediante wp-config.php.
	 *
	 * @return string
	 */
	private static function get_api_base() {
		$value = defined( 'PREMIERO_CONSOLE_API_BASE' )
			? (string) PREMIERO_CONSOLE_API_BASE
			: (string) get_option( self::OPT_API_BASE, '' );
		$normalized = self::normalize_api_base( $value );
		return is_wp_error( $normalized ) ? '' : $normalized;
	}

	/**
	 * Prefill de la URL sin obligar a mostrar la ruta REST completa.
	 *
	 * @return string
	 */
	private static function get_configured_console_url_for_form() {
		if ( defined( 'PREMIERO_CONSOLE_API_BASE' ) ) {
			return esc_url( (string) PREMIERO_CONSOLE_API_BASE );
		}
		return esc_url( (string) get_option( self::OPT_API_BASE, '' ) );
	}

	/**
	 * Normaliza la portada o la base REST de la consola.
	 *
	 * @param string $url URL introducida.
	 * @return string|WP_Error
	 */
	private static function normalize_api_base( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return new WP_Error( 'console_url_missing', 'Introduce la URL de la consola.' );
		}

		$url   = esc_url_raw( $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'console_url_invalid', 'La URL de la consola no es válida.' );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return new WP_Error( 'console_url_components', 'La URL de la consola no puede incluir credenciales, consulta ni fragmento.' );
		}

		$scheme         = strtolower( $parts['scheme'] );
		$allow_insecure = (bool) apply_filters( 'premiero_console_allow_insecure_endpoint', false, $url );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && $allow_insecure ) ) {
			return new WP_Error( 'console_https_required', 'La consola debe utilizar HTTPS.' );
		}

		$url = untrailingslashit( $url );
		$url = preg_replace( '#/(pair|telemetry)$#i', '', $url );
		if ( ! preg_match( '#/wp-json/' . preg_quote( self::API_NAMESPACE, '#' ) . '$#i', $url ) ) {
			$url .= '/wp-json/' . self::API_NAMESPACE;
		}

		$valid = (bool) apply_filters( 'premiero_console_validate_api_base', true, $url );
		if ( ! $valid ) {
			return new WP_Error( 'console_url_rejected', 'La URL de la consola no está permitida.' );
		}

		return esc_url_raw( $url );
	}

	/**
	 * Crea un UUID estable que no depende del dominio.
	 *
	 * @return string
	 */
	private static function ensure_installation_id() {
		$id = (string) get_option( self::OPT_INSTALLATION_ID, '' );
		if ( self::is_uuid( $id ) ) {
			return strtolower( $id );
		}

		$id = wp_generate_uuid4();
		self::update_private_option( self::OPT_INSTALLATION_ID, $id );
		return $id;
	}

	/**
	 * Comprueba un UUID canónico.
	 *
	 * @param string $uuid UUID.
	 * @return bool
	 */
	private static function is_uuid( $uuid ) {
		return 1 === preg_match(
			'/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',
			(string) $uuid
		);
	}

	/**
	 * Cifra el secreto con claves derivadas de las salts de WordPress.
	 *
	 * @param string $secret Secreto sin cifrar.
	 * @return string|WP_Error
	 */
	private static function encrypt_secret( $secret ) {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_get_cipher_methods' ) ) {
			return new WP_Error( 'console_crypto_unavailable', 'OpenSSL es necesario para proteger el secreto de la consola.' );
		}

		try {
			$master  = self::master_key();
			$ciphers = array_map( 'strtolower', openssl_get_cipher_methods() );

			if ( in_array( 'aes-256-gcm', $ciphers, true ) ) {
				$iv         = random_bytes( 12 );
				$tag        = '';
				$ciphertext = openssl_encrypt(
					(string) $secret,
					'aes-256-gcm',
					$master,
					OPENSSL_RAW_DATA,
					$iv,
					$tag
				);
				if ( false === $ciphertext || '' === $tag ) {
					throw new \RuntimeException( 'No se pudo cifrar el secreto.' );
				}
				$envelope = array(
					'v'   => 1,
					'alg' => 'aes-256-gcm',
					'iv'  => base64_encode( $iv ),
					'tag' => base64_encode( $tag ),
					'ct'  => base64_encode( $ciphertext ),
				);
			} elseif ( in_array( 'aes-256-cbc', $ciphers, true ) ) {
				$enc_key    = hash( 'sha256', $master . "\0enc", true );
				$mac_key    = hash( 'sha256', $master . "\0mac", true );
				$iv         = random_bytes( 16 );
				$ciphertext = openssl_encrypt(
					(string) $secret,
					'aes-256-cbc',
					$enc_key,
					OPENSSL_RAW_DATA,
					$iv
				);
				if ( false === $ciphertext ) {
					throw new \RuntimeException( 'No se pudo cifrar el secreto.' );
				}
				$tag      = hash_hmac( 'sha256', $iv . $ciphertext, $mac_key, true );
				$envelope = array(
					'v'   => 1,
					'alg' => 'aes-256-cbc-hmac-sha256',
					'iv'  => base64_encode( $iv ),
					'tag' => base64_encode( $tag ),
					'ct'  => base64_encode( $ciphertext ),
				);
			} else {
				return new WP_Error( 'console_cipher_unavailable', 'No hay un cifrado seguro compatible disponible.' );
			}

			return base64_encode( wp_json_encode( $envelope ) );
		} catch ( \Throwable $error ) {
			return new WP_Error( 'console_encrypt_failed', $error->getMessage() );
		}
	}

	/**
	 * Descifra el secreto guardado.
	 *
	 * @return string|WP_Error
	 */
	private static function get_secret() {
		$stored = (string) get_option( self::OPT_SECRET, '' );
		if ( '' === $stored ) {
			return new WP_Error( 'console_secret_missing', 'No hay un secreto de consola guardado.' );
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return new WP_Error( 'console_crypto_unavailable', 'OpenSSL es necesario para recuperar el secreto.' );
		}

		$json     = base64_decode( $stored, true );
		$envelope = $json ? json_decode( $json, true ) : null;
		if (
			! is_array( $envelope )
			|| empty( $envelope['alg'] )
			|| empty( $envelope['iv'] )
			|| empty( $envelope['tag'] )
			|| empty( $envelope['ct'] )
		) {
			return new WP_Error( 'console_secret_invalid', 'El secreto guardado tiene un formato inválido.' );
		}

		$iv         = base64_decode( $envelope['iv'], true );
		$tag        = base64_decode( $envelope['tag'], true );
		$ciphertext = base64_decode( $envelope['ct'], true );
		if ( false === $iv || false === $tag || false === $ciphertext ) {
			return new WP_Error( 'console_secret_invalid', 'El secreto guardado está dañado.' );
		}

		$master = self::master_key();
		if ( 'aes-256-gcm' === $envelope['alg'] ) {
			$secret = openssl_decrypt(
				$ciphertext,
				'aes-256-gcm',
				$master,
				OPENSSL_RAW_DATA,
				$iv,
				$tag
			);
		} elseif ( 'aes-256-cbc-hmac-sha256' === $envelope['alg'] ) {
			$enc_key      = hash( 'sha256', $master . "\0enc", true );
			$mac_key      = hash( 'sha256', $master . "\0mac", true );
			$expected_tag = hash_hmac( 'sha256', $iv . $ciphertext, $mac_key, true );
			if ( ! hash_equals( $expected_tag, $tag ) ) {
				return new WP_Error( 'console_secret_auth_failed', 'No se pudo autenticar el secreto guardado.' );
			}
			$secret = openssl_decrypt(
				$ciphertext,
				'aes-256-cbc',
				$enc_key,
				OPENSSL_RAW_DATA,
				$iv
			);
		} else {
			return new WP_Error( 'console_secret_algorithm', 'El algoritmo del secreto guardado no es compatible.' );
		}

		if ( false === $secret || strlen( $secret ) < 32 ) {
			return new WP_Error( 'console_secret_decrypt_failed', 'No se pudo descifrar el secreto. Comprueba las salts de WordPress.' );
		}
		return $secret;
	}

	/**
	 * Deriva una clave binaria de 256 bits de las salts de WordPress.
	 *
	 * @return string
	 */
	private static function master_key() {
		$material = wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|' . wp_salt( 'logged_in' );
		return hash( 'sha256', 'premiero-console-client|' . $material, true );
	}

	/**
	 * Registra fallo y aplica backoff.
	 *
	 * @param WP_Error    $error      Error.
	 * @param int         $http_code  HTTP opcional.
	 * @param array|false $response   Respuesta HTTP opcional.
	 */
	private static function record_send_failure( $error, $http_code = 0, $response = false ) {
		$failures = (int) get_option( self::OPT_FAILURES, 0 ) + 1;

		if ( in_array( (int) $http_code, array( 401, 403 ), true ) ) {
			$delay = DAY_IN_SECONDS;
		} elseif ( 429 === (int) $http_code ) {
			$retry_after = $response ? (int) wp_remote_retrieve_header( $response, 'retry-after' ) : 0;
			$delay       = $retry_after > 0
				? min( DAY_IN_SECONDS, max( 15 * MINUTE_IN_SECONDS, $retry_after ) )
				: 6 * HOUR_IN_SECONDS;
		} elseif ( 1 === $failures ) {
			$delay = 15 * MINUTE_IN_SECONDS;
		} elseif ( 2 === $failures ) {
			$delay = HOUR_IN_SECONDS;
		} elseif ( 3 === $failures ) {
			$delay = 6 * HOUR_IN_SECONDS;
		} else {
			$delay = 12 * HOUR_IN_SECONDS;
		}

		self::update_private_option( self::OPT_FAILURES, $failures );
		self::update_private_option( self::OPT_NEXT_ATTEMPT, time() + $delay );
		self::update_private_option( self::OPT_LAST_HTTP, (int) $http_code );
		self::store_error( $error->get_error_code(), $error->get_error_message(), $http_code );
	}

	/**
	 * Persiste un error sanitizado, nunca cuerpos remotos ni secretos.
	 *
	 * @param string $code      Código.
	 * @param string $message   Mensaje.
	 * @param int    $http_code HTTP.
	 */
	private static function store_error( $code, $message, $http_code = 0 ) {
		self::update_private_option(
			self::OPT_LAST_ERROR,
			array(
				'code'      => sanitize_key( (string) $code ),
				'message'   => substr( sanitize_text_field( (string) $message ), 0, 300 ),
				'http_code' => (int) $http_code,
				'at'        => time(),
			)
		);
		if ( $http_code ) {
			self::update_private_option( self::OPT_LAST_HTTP, (int) $http_code );
		}
	}

	/**
	 * Lock atómico basado en la clave única de wp_options.
	 *
	 * @param string $option_name Nombre del lock.
	 * @param int    $ttl         Caducidad.
	 * @return bool
	 */
	private static function acquire_lock( $option_name, $ttl ) {
		$existing = (int) get_option( $option_name, 0 );
		if ( $existing && $existing > ( time() - (int) $ttl ) ) {
			return false;
		}
		if ( $existing ) {
			delete_option( $option_name );
		}
		return add_option( $option_name, time(), '', false );
	}

	/**
	 * Guarda opciones de ejecución fuera del autoload.
	 *
	 * @param string $name  Nombre.
	 * @param mixed  $value Valor.
	 */
	private static function update_private_option( $name, $value ) {
		update_option( $name, $value, false );
	}

	/**
	 * Nonce criptográfico por petición.
	 *
	 * @return string
	 */
	private static function generate_request_nonce() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			return wp_generate_password( 32, false, false );
		}
	}

	/**
	 * Codifica binario como Base64 URL-safe sin padding.
	 *
	 * @param string $value Valor binario.
	 * @return string
	 */
	private static function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' );
	}

	/**
	 * User-Agent identificable y sin información sensible.
	 *
	 * @return string
	 */
	private static function user_agent() {
		$version = defined( 'PREMIERO_ATK_VER' ) ? (string) PREMIERO_ATK_VER : 'unknown';
		return 'Premiero-Admin-Toolkit/' . $version . '; Console-Client/' . self::SCHEMA_VERSION;
	}

	/**
	 * Normaliza un tamaño o devuelve null si no pudo calcularse.
	 *
	 * @param mixed $value Valor.
	 * @return int|null
	 */
	private static function normalize_size_value( $value ) {
		return is_numeric( $value ) && (float) $value >= 0 ? (int) $value : null;
	}

	/**
	 * Fecha administrativa localizada.
	 *
	 * @param int $timestamp Timestamp.
	 * @return string
	 */
	private static function format_timestamp( $timestamp ) {
		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			(int) $timestamp
		);
	}

	/**
	 * Avisos de resultado sin transportar mensajes arbitrarios por la URL.
	 */
	private static function render_status_notice() {
		if ( empty( $_GET['console-status'] ) ) {
			return;
		}

		$status   = sanitize_key( wp_unslash( $_GET['console-status'] ) );
		$messages = array(
			'paired'          => array( 'success', 'Instalación emparejada. El primer estado se enviará en segundo plano.' ),
			'pair-error'      => array( 'error', 'No se pudo emparejar la instalación. Revisa el error mostrado debajo.' ),
				'disconnected'    => array( 'success', 'Los envíos se han detenido y la clave local se ha eliminado. Revoca también la conexión desde la consola si quieres invalidar la credencial remota.' ),
			'sent'            => array( 'success', 'Estado enviado correctamente a la consola.' ),
			'send-error'      => array( 'error', 'No se pudo enviar el estado. Revisa el error mostrado debajo.' ),
			'sizes-scheduled' => array( 'info', 'El cálculo de tamaño se ha programado en segundo plano.' ),
			'not-connected'   => array( 'warning', 'La instalación no está conectada.' ),
			'invalid-action'  => array( 'error', 'La acción solicitada no es válida.' ),
		);
		if ( ! isset( $messages[ $status ] ) ) {
			return;
		}

		echo '<div class="notice notice-' . esc_attr( $messages[ $status ][0] ) . ' is-dismissible"><p>'
			. esc_html( $messages[ $status ][1] )
			. '</p></div>';
	}

	/**
	 * Redirección PRG a la pestaña de consola.
	 *
	 * @param string $status Resultado.
	 */
	private static function redirect_with_status( $status ) {
		$page = defined( 'PREMIERO_ATK_SLUG' ) ? PREMIERO_ATK_SLUG : 'premiero-admin';
		$url  = add_query_arg(
			array(
				'page'           => $page,
					'tab'            => 'monitoring',
				'console-status' => sanitize_key( $status ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
