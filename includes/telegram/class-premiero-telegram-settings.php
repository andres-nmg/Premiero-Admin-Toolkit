<?php
/**
 * Pestaña de ajustes de los avisos por Telegram.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Telegram_Settings {

	const ACTION_SAVE = 'premiero_telegram_save';
	const ACTION_TEST = 'premiero_telegram_test';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_TEST, array( __CLASS__, 'handle_test' ) );
	}

	/**
	 * Grupos de la seccion 2. Los avisos de plugins inactivos no aparecen aqui, asi
	 * que no se muestran y sus valores se conservan al guardar.
	 */
	private static function event_groups() {
		return array(
			array(
				'title'  => 'Formularios',
				'events' => array_filter(
					array(
						'event_cf7'       => defined( 'WPCF7_VERSION' ) ? 'Contact Form 7' : null,
						'event_elementor' => defined( 'ELEMENTOR_PRO_VERSION' ) ? 'Formularios de Elementor Pro' : null,
					)
				),
			),
			array(
				'title'  => 'Actualizaciones',
				'events' => array(
					'event_updates'         => 'Actualizaciones realizadas',
					'event_update_failures' => 'Fallos en actualizaciones automáticas',
					'event_digest'          => 'Resumen diario de actualizaciones pendientes',
				),
			),
			array(
				'title'  => 'Errores',
				'events' => array(
					'event_fatal_recovery' => 'Error fatal con enlace de recuperación',
					'event_fatal_frontend' => 'Error fatal fuera del admin (portada, sin enlace de recuperación)',
				),
			),
			array(
				'title'  => 'Seguridad',
				'events' => array(
					'event_admin_login'    => 'Login de administradores',
					'event_failed_login'   => 'Intentos de login fallidos (máximo un resumen por hora)',
					'event_new_user'       => 'Nuevo usuario',
					'event_admin_role'     => 'Un usuario pasa a tener rol de administrador',
					'event_password_reset' => 'Restablecimiento de contraseña de un administrador',
					'event_site_urls'      => 'Cambio del email de administración o de las URLs siteurl/home',
					'event_plugins'        => 'Plugin activado, desactivado o eliminado',
					'event_theme'          => 'Cambio de tema',
				),
			),
			array(
				'title'  => 'Contenido',
				'events' => array(
					'event_comment' => 'Comentario pendiente de moderación',
				),
			),
		);
	}

	private static function event_label( $key ) {
		$labels = array(
			'event_new_user'        => 'Nuevo usuario',
			'event_updates'         => 'Actualizaciones realizadas',
			'event_update_failures' => 'Fallos en actualizaciones automáticas',
			'event_digest'          => 'Resumen diario de actualizaciones pendientes',
			'event_password_reset'  => 'Restablecimiento de contraseña de un administrador',
			'event_comment'         => 'Comentario pendiente de moderación',
		);
		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}

	/* ---------- Procesamiento ---------- */

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permisos para configurar los avisos de Telegram.' );
		}
		check_admin_referer( self::ACTION_SAVE );

		$settings = Premiero_Telegram::settings();
		$errors   = array();

		$settings['enabled'] = isset( $_POST['enabled'] ) ? 1 : 0;

		foreach ( self::event_groups() as $group ) {
			foreach ( array_keys( $group['events'] ) as $key ) {
				$settings[ $key ] = isset( $_POST[ $key ] ) ? 1 : 0;
			}
		}
		foreach ( Premiero_Telegram::email_rules() as $key => $rule ) {
			if ( Premiero_Telegram::email_rule_is_supported( $rule ) ) {
				$settings[ $key ] = isset( $_POST[ $key ] ) ? 1 : 0;
			}
		}

		if ( ! ( defined( 'PRM_TG_TOKEN' ) && '' !== trim( (string) PRM_TG_TOKEN ) ) ) {
			$token = isset( $_POST['token'] ) ? trim( (string) wp_unslash( $_POST['token'] ) ) : '';
			if ( '' !== $token ) {
				if ( 1 === preg_match( Premiero_Telegram::TOKEN_PATTERN, $token ) ) {
					$encrypted = Premiero_Telegram::encrypt_token( $token );
					if ( is_wp_error( $encrypted ) ) {
						$errors[] = $encrypted->get_error_message();
					} else {
						$settings['token'] = $encrypted;
					}
				} else {
					$errors[] = 'El token no tiene un formato válido y no se ha guardado.';
				}
			} elseif ( isset( $_POST['delete_token'] ) ) {
				$settings['token'] = '';
			}
		}

		if ( ! ( defined( 'PRM_TG_CHAT_ID' ) && '' !== trim( (string) PRM_TG_CHAT_ID ) ) ) {
			$chat_id = isset( $_POST['chat_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['chat_id'] ) ) ) : '';
			if ( '' === $chat_id || 1 === preg_match( Premiero_Telegram::CHAT_ID_PATTERN, $chat_id ) ) {
				$settings['chat_id'] = $chat_id;
			} else {
				$errors[] = 'El Chat ID no tiene un formato válido y no se ha guardado.';
			}
		}

		// Autoload activo: se consulta en cada peticion para decidir que hooks registrar.
		update_option( Premiero_Telegram::OPTION, $settings, true );
		Premiero_Telegram::reset_credentials_cache();
		Premiero_Telegram::sync_schedules();

		foreach ( $errors as $error ) {
			self::add_notice( 'error', $error );
		}
		self::add_notice( 'success', $errors ? 'El resto de los ajustes de Telegram se ha guardado.' : 'Ajustes de Telegram guardados.' );
		if ( ! Premiero_Telegram::sync_mu_plugin() ) {
			self::add_notice( 'warning', 'No se pudo escribir en wp-content/mu-plugins. Los avisos de errores fatales no cubrirán los errores que ocurran antes de cargar el Toolkit.' );
		}
		self::redirect();
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permisos para enviar mensajes de prueba.' );
		}
		check_admin_referer( self::ACTION_TEST );

		$result = Premiero_Telegram_Sender::send( 'Mensaje de prueba', array( 'Los avisos por Telegram funcionan correctamente.' ) );
		if ( $result['ok'] ) {
			self::add_notice( 'success', 'Mensaje de prueba enviado correctamente.' );
		} else {
			self::add_notice( 'error', 'No se pudo enviar el mensaje de prueba. HTTP ' . $result['code'] . ': ' . $result['description'] );
		}
		self::redirect();
	}

	private static function add_notice( $type, $message ) {
		$notices = get_transient( Premiero_Telegram::TRANSIENT_NOTICES );
		$notices = is_array( $notices ) ? $notices : array();

		$notices[ get_current_user_id() ][] = array(
			'type'    => $type,
			'message' => sanitize_text_field( $message ),
		);
		set_transient( Premiero_Telegram::TRANSIENT_NOTICES, $notices, 5 * MINUTE_IN_SECONDS );
	}

	private static function render_notices() {
		$notices = get_transient( Premiero_Telegram::TRANSIENT_NOTICES );
		$user_id = get_current_user_id();
		if ( ! is_array( $notices ) || empty( $notices[ $user_id ] ) ) {
			return;
		}
		foreach ( $notices[ $user_id ] as $notice ) {
			echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . ' is-dismissible premiero-capture-ignore"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
		unset( $notices[ $user_id ] );
		if ( $notices ) {
			set_transient( Premiero_Telegram::TRANSIENT_NOTICES, $notices, 5 * MINUTE_IN_SECONDS );
		} else {
			delete_transient( Premiero_Telegram::TRANSIENT_NOTICES );
		}
	}

	private static function redirect() {
		wp_safe_redirect( admin_url( 'admin.php?page=' . PREMIERO_ATK_SLUG . '&tab=telegram' ) );
		exit;
	}

	/* ---------- Render ---------- */

	public static function render_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings    = Premiero_Telegram::settings();
		$credentials = Premiero_Telegram::credentials();
		$has_token   = '' !== (string) $settings['token'];
		$is_active   = Premiero_Telegram::is_active();
		$has_valid   = Premiero_Telegram::has_valid_credentials();
		$groups      = self::event_groups();

		$events_total = 0;
		$events_on    = 0;
		foreach ( $groups as $group ) {
			foreach ( array_keys( $group['events'] ) as $key ) {
				++$events_total;
				$events_on += empty( $settings[ $key ] ) ? 0 : 1;
			}
		}

		$emails_off = 0;
		foreach ( array_keys( Premiero_Telegram::email_rules() ) as $key ) {
			$emails_off += Premiero_Telegram::email_is_suppressed( $key ) ? 1 : 0;
		}

		$mu_wanted = Premiero_Telegram::mu_plugin_is_wanted();
		$mu_exists = file_exists( Premiero_Telegram::mu_plugin_path() );

		if ( $is_active ) {
			$status = 'Avisos activos';
		} elseif ( ! $has_valid ) {
			$status = 'Faltan credenciales';
		} else {
			$status = 'Avisos desactivados';
		}

		self::render_notices();
		?>
		<div class="premiero-telegram">
			<header class="premiero-remote-header">
				<div>
					<h2>Avisos por Telegram</h2>
					<p>Recibe en un chat de Telegram lo que ocurre en esta web y decide qué emails de WordPress dejan de ser necesarios.</p>
				</div>
				<span class="premiero-remote-status <?php echo $is_active ? 'is-active' : 'is-off'; ?>"><span aria-hidden="true"></span><?php echo esc_html( $status ); ?></span>
			</header>

			<?php if ( $credentials['token_unreadable'] ) : ?>
				<div class="notice notice-error inline premiero-capture-ignore"><p>El token guardado no se puede descifrar. Suele pasar al migrar la web o al cambiar las salts de WordPress. Vuelve a escribirlo.</p></div>
			<?php endif; ?>
			<?php if ( ! $has_valid ) : ?>
				<div class="notice notice-warning inline premiero-capture-ignore"><p>Faltan credenciales válidas: no se envía ningún aviso y los emails de WordPress siguen enviándose con normalidad.</p></div>
			<?php endif; ?>
			<?php if ( $mu_wanted && ! $mu_exists ) : ?>
				<div class="notice notice-warning inline premiero-capture-ignore"><p>No se pudo crear el mu-plugin de errores fatales en wp-content/mu-plugins. Los errores que ocurran antes de cargar el Toolkit no se avisarán por Telegram.</p></div>
			<?php endif; ?>

			<div class="premiero-overview premiero-notices-overview">
				<div class="<?php echo $has_valid ? '' : 'is-attention'; ?>">
					<strong>Bot</strong>
					<span><?php echo $has_valid ? 'Credenciales válidas' : 'Sin configurar'; ?></span>
				</div>
				<div>
					<strong>Avisos marcados</strong>
					<span><?php echo esc_html( $events_on . ' de ' . $events_total ); ?></span>
				</div>
				<div class="<?php echo $emails_off ? 'is-attention' : ''; ?>">
					<strong>Emails desactivados</strong>
					<span><?php echo esc_html( $emails_off ? (string) $emails_off : 'Ninguno' ); ?></span>
				</div>
				<div class="<?php echo $mu_wanted && ! $mu_exists ? 'is-attention' : ''; ?>">
					<strong>Errores fatales</strong>
					<span>
						<?php
						if ( ! $mu_wanted ) {
							echo 'Sin vigilar';
						} else {
							echo $mu_exists ? 'Protección temprana activa' : 'Protección parcial';
						}
						?>
					</span>
				</div>
			</div>

			<form method="post" class="premiero-appearance-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>

				<section class="premiero-appearance-section">
					<h2>Bot</h2>
					<p>Las credenciales son propias de esta instalación.</p>
					<label class="premiero-appearance-toggle">
						<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
						<span>
							<strong>Activar avisos por Telegram</strong>
							<small>Al desactivarlos no se envía nada y todos los emails de WordPress vuelven a enviarse.</small>
						</span>
					</label>
					<table class="form-table premiero-telegram-fields" role="presentation">
						<tr>
							<th scope="row"><label for="premiero-telegram-token">Token</label></th>
							<td>
								<input type="password" id="premiero-telegram-token" name="token" class="regular-text" autocomplete="new-password" <?php disabled( $credentials['token_from_config'] ); ?> placeholder="<?php echo esc_attr( $has_token && ! $credentials['token_from_config'] ? 'Token guardado' : '' ); ?>">
								<?php if ( $credentials['token_from_config'] ) : ?>
									<p class="description">Definido en wp-config.php</p>
								<?php else : ?>
									<p class="description">Se guarda cifrado y no vuelve a mostrarse. Déjalo vacío para conservar el actual.</p>
									<?php if ( $has_token ) : ?>
										<label class="premiero-telegram-inline"><input type="checkbox" name="delete_token" value="1"> Borrar el token guardado</label>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="premiero-telegram-chat-id">Chat ID</label></th>
							<td>
								<input type="text" id="premiero-telegram-chat-id" name="chat_id" class="regular-text" inputmode="numeric" value="<?php echo esc_attr( $credentials['chat_from_config'] ? '' : (string) $settings['chat_id'] ); ?>" <?php disabled( $credentials['chat_from_config'] ); ?>>
								<?php if ( $credentials['chat_from_config'] ) : ?>
									<p class="description">Definido en wp-config.php</p>
								<?php else : ?>
									<p class="description">Identificador numérico del chat que recibe los avisos.</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">Prueba</th>
							<td>
								<button type="submit" class="button" form="premiero-telegram-test-form">Enviar mensaje de prueba</button>
								<p class="description">Usa las credenciales guardadas: guarda antes si las has cambiado.</p>
							</td>
						</tr>
					</table>
				</section>

				<section class="premiero-appearance-section">
					<div class="premiero-telegram-section-head">
						<div>
							<h2>Qué avisos enviar por Telegram</h2>
							<p>Solo se activan los avisos marcados. Los grupos de Contact Form 7 y Elementor Pro aparecen si el plugin está activo.</p>
						</div>
						<div class="premiero-telegram-section-actions">
							<button type="button" class="button" data-premiero-telegram-set="1">Marcar todo</button>
							<button type="button" class="button" data-premiero-telegram-set="0">Desmarcar todo</button>
						</div>
					</div>
					<div class="premiero-telegram-groups">
						<?php foreach ( $groups as $group ) : ?>
							<?php
							if ( empty( $group['events'] ) ) {
								continue;
							}
							?>
							<fieldset class="premiero-telegram-group">
								<legend><?php echo esc_html( $group['title'] ); ?></legend>
								<?php foreach ( $group['events'] as $key => $label ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" data-premiero-telegram-event <?php checked( ! empty( $settings[ $key ] ) ); ?>> <span><?php echo esc_html( $label ); ?></span></label>
								<?php endforeach; ?>
							</fieldset>
						<?php endforeach; ?>
					</div>
				</section>

				<div class="notice notice-warning inline premiero-capture-ignore premiero-telegram-warning"><p>Estos emails van al email de administración de la web, que puede ser el del cliente. Si los desactivas, el cliente dejará de recibirlos.</p></div>

				<section class="premiero-appearance-section">
					<h2>Desactivar emails duplicados de WordPress</h2>
					<p>Cada casilla solo tiene efecto con Telegram activo, credenciales válidas y los avisos de los que depende marcados. Si falta algo, el email se envía con normalidad.</p>
					<div class="premiero-telegram-emails">
						<?php foreach ( Premiero_Telegram::email_rules() as $key => $rule ) : ?>
							<?php
							$supported  = Premiero_Telegram::email_rule_is_supported( $rule );
							$depends_on = implode( ' y ', array_map( array( __CLASS__, 'event_label' ), $rule['events'] ) );
							if ( ! $supported ) {
								$state_class = 'premiero-notice-state is-hidden';
								$state_label = 'Requiere WordPress ' . $rule['min_wp'];
							} elseif ( Premiero_Telegram::email_is_suppressed( $key ) ) {
								$state_class = 'premiero-notice-state is-hidden';
								$state_label = 'Email desactivado';
							} elseif ( ! empty( $settings[ $key ] ) ) {
								$state_class = 'premiero-notice-type is-warning';
								$state_label = 'Sin efecto: el email se envía';
							} else {
								$state_class = 'premiero-notice-state is-visible';
								$state_label = 'Email activo';
							}
							?>
							<div class="premiero-telegram-email">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( ! empty( $settings[ $key ] ) ); ?> <?php disabled( ! $supported ); ?>>
									<span>
										<strong><?php echo esc_html( $rule['label'] ); ?></strong>
										<small>Depende de: <?php echo esc_html( $depends_on ); ?>.</small>
										<?php if ( ! empty( $rule['note'] ) ) : ?>
											<small><?php echo esc_html( $rule['note'] ); ?></small>
										<?php endif; ?>
									</span>
								</label>
								<span class="<?php echo esc_attr( $state_class ); ?>"><?php echo esc_html( $state_label ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</section>

				<div class="premiero-sticky-actions">
					<span class="description">Los cambios se aplican al guardar.</span>
					<?php submit_button( 'Guardar cambios', 'primary', 'submit', false ); ?>
				</div>
			</form>

			<form method="post" id="premiero-telegram-test-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_TEST ); ?>">
				<?php wp_nonce_field( self::ACTION_TEST ); ?>
			</form>
			<script>
			(function(){
				document.querySelectorAll("[data-premiero-telegram-set]").forEach(function(button){
					button.addEventListener("click", function(){
						var checked = button.dataset.premieroTelegramSet === "1";
						document.querySelectorAll("[data-premiero-telegram-event]").forEach(function(box){ box.checked = checked; });
					});
				});
			})();
			</script>
		</div>
		<?php
	}
}
