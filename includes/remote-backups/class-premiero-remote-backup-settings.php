<?php
/**
 * Configuracion y diagnostico administrativo de backups remotos.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Remote_Backup_Settings {

	const NONCE_ACTION = 'premiero_remote_backups_admin_action';
	const OPT_CONFIG = 'premiero_remote_backups_config';
	const OPT_PASSWORD = 'premiero_remote_backups_password';
	const OPT_FINGERPRINTS = 'premiero_remote_backups_host_keys';
	const OPT_LAST_TEST = 'premiero_remote_backups_last_test';

	/**
	 * Registra el procesamiento PRG de los formularios.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'process_forms' ), 6 );
	}

	/**
	 * Procesa guardar, probar y olvidar la clave SSH.
	 */
	public static function process_forms() {
		if (
			! is_admin()
			|| 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '' )
			|| empty( $_POST['premiero_remote_backups_action'] )
		) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permisos para configurar los backups remotos.' );
		}

		check_admin_referer( self::NONCE_ACTION );
		$action = sanitize_key( wp_unslash( $_POST['premiero_remote_backups_action'] ) );

		if ( 'forget_key' === $action ) {
			$config = self::get_config();
			self::forget_fingerprint( $config['host'], $config['port'] );
			self::set_notice( 'success', 'La clave SSH guardada se ha olvidado. La proxima prueba registrara una nueva despues de autenticar y escribir correctamente.' );
			self::redirect();
		}

		if ( ! in_array( $action, array( 'save', 'test', 'upload_pending' ), true ) ) {
			self::set_notice( 'error', 'La accion solicitada no es valida.' );
			self::redirect();
		}

		$config = ( 'upload_pending' === $action && ! isset( $_POST['premiero_remote_backups_host'] ) )
			? self::get_config()
			: self::config_from_request();
		if ( is_wp_error( $config ) ) {
			self::set_notice( 'error', $config->get_error_message() );
			self::redirect();
		}

		$password = isset( $_POST['premiero_remote_backups_password'] )
			? (string) wp_unslash( $_POST['premiero_remote_backups_password'] )
			: '';

		if ( '' !== $password ) {
			$encrypted = self::encrypt_password( $password );
			if ( is_wp_error( $encrypted ) ) {
				self::set_notice( 'error', $encrypted->get_error_message() );
				self::redirect();
			}
			update_option( self::OPT_PASSWORD, $encrypted, false );
		}
		if ( ! empty( $config['enabled'] ) && '' === $password && ! self::has_password() ) {
			self::set_notice( 'error', 'Introduce la contrasena antes de activar los backups remotos.' );
			self::redirect();
		}
		update_option( self::OPT_CONFIG, $config, false );
		Premiero_Remote_Backups::refresh_schedule();

		if ( 'save' === $action ) {
			self::set_notice( 'success', ! empty( $config['enabled'] ) ? 'Configuracion guardada. La deteccion y la sincronizacion SFTP estan activas.' : 'Configuracion guardada. Los backups remotos estan desactivados.' );
			self::redirect();
		}

		if ( 'upload_pending' === $action ) {
			if ( empty( $config['enabled'] ) ) {
				self::set_notice( 'warning', 'Activa los backups remotos antes de subir archivos pendientes.' );
				self::redirect();
			}
			$new_files = Premiero_Backup_Detector::scan( true );
			$reconciled = Premiero_Backup_Reconciler::run();
			$released  = Premiero_Backup_Sync_Queue::release_pending();
			Premiero_Remote_Backups::schedule_worker( 1 );
			self::set_notice(
				'success',
				sprintf(
					'Sincronizacion programada: %d nuevas, %d recuperadas del destino, %d eliminadas por retencion y %d pendientes preparados.',
					$new_files,
					isset( $reconciled['requeued'] ) ? (int) $reconciled['requeued'] : 0,
					isset( $reconciled['pruned'] ) ? (int) $reconciled['pruned'] : 0,
					$released
				)
			);
			self::redirect();
		}

		$stored_password = self::get_password();
		if ( is_wp_error( $stored_password ) ) {
			self::set_notice( 'error', $stored_password->get_error_message() );
			self::redirect();
		}

		$probes = array(
			Premiero_SFTP_Client::probe_port( $config['host'], 22 ),
			Premiero_SFTP_Client::probe_port( $config['host'], 23 ),
		);
		if ( ! in_array( (int) $config['port'], array( 22, 23 ), true ) ) {
			$probes[] = Premiero_SFTP_Client::probe_port( $config['host'], (int) $config['port'] );
		}

		$selected_probe = null;
		foreach ( $probes as $probe ) {
			if ( (int) $probe['port'] === (int) $config['port'] ) {
				$selected_probe = $probe;
				break;
			}
		}

		$config['password']             = $stored_password;
		$config['expected_fingerprint'] = self::get_fingerprint( $config['host'], $config['port'] );
		if ( is_array( $selected_probe ) && empty( $selected_probe['reachable'] ) ) {
			$result = new WP_Error( 'premiero_sftp_port_unreachable', 'El puerto SFTP seleccionado esta bloqueado o no es accesible desde este hosting.' );
		} else {
			$client = new Premiero_SFTP_Client( $config );
			$result = $client->test_write_access( 4096 );
		}
		unset( $config['password'] );

		$test_record = array(
			'captured_at' => time(),
			'host'        => $config['host'],
			'port'        => (int) $config['port'],
			'probes'      => $probes,
			'success'     => ! is_wp_error( $result ),
			'result'      => is_wp_error( $result )
				? array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
				: $result,
		);
		update_option( self::OPT_LAST_TEST, $test_record, false );

		if ( is_wp_error( $result ) ) {
			self::set_notice( 'error', 'La prueba SFTP ha fallado: ' . $result->get_error_message() );
			self::redirect();
		}

		if ( empty( $config['expected_fingerprint'] ) && ! empty( $result['host_key_fingerprint'] ) ) {
			self::store_fingerprint( $config['host'], $config['port'], $result['host_key_fingerprint'] );
		}
		self::set_notice( 'success', 'SFTP operativo: autenticacion, escritura, verificacion de tamano y limpieza completadas correctamente.' );
		self::redirect();
	}

	/**
	 * Renderiza la pestana de diagnostico.
	 */
	public static function render_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$config      = self::get_config();
		$last_test   = get_option( self::OPT_LAST_TEST, array() );
		$fingerprint = self::get_fingerprint( $config['host'], $config['port'] );
		Premiero_Backup_Sync_Queue::maybe_install();
		$recent         = Premiero_Backup_Sync_Queue::recent( 500 );
		$sets           = self::build_backup_sets( $recent, $config );
		$set_counts     = self::set_counts( $sets );
		$last_reconcile = get_option( Premiero_Backup_Reconciler::OPT_LAST_REPORT, array() );
		self::render_notice();
		?>
		<div class="premiero-remote-backups">
			<header class="premiero-remote-header">
				<div>
					<h2>Copias de Seguridad Remotas</h2>
					<p>Sincronizacion SFTP de las copias terminadas de UpdraftPlus con tu servidor remoto.</p>
				</div>
				<span class="premiero-remote-status <?php echo ! empty( $config['enabled'] ) ? 'is-active' : 'is-off'; ?>"><span aria-hidden="true"></span><?php echo ! empty( $config['enabled'] ) ? 'Activo' : 'Desactivado'; ?></span>
			</header>

			<section class="premiero-remote-daily" aria-labelledby="premiero-remote-daily-title">
				<div>
					<h3 id="premiero-remote-daily-title">Sincroniza tus copias</h3>
					<p>Detecta copias nuevas, repara archivos remotos ausentes y procesa cualquier pendiente. La sincronizacion automatica sigue activa en segundo plano.</p>
				</div>
				<form method="post" class="premiero-remote-quick-form">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<button type="submit" class="button button-primary button-hero" name="premiero_remote_backups_action" value="upload_pending">Sincronizar ahora</button>
					<span>Ultima comprobacion: <?php echo ! empty( $last_reconcile['checked_at'] ) ? esc_html( 'hace ' . human_time_diff( (int) $last_reconcile['checked_at'], time() ) ) : 'pendiente'; ?></span>
				</form>
			</section>

			<div class="premiero-overview">
				<a class="is-warning" href="#premiero-remote-queue"><strong>Pendientes</strong><span><?php echo esc_html( (string) $set_counts['pending'] ); ?> <?php echo 1 === $set_counts['pending'] ? 'copia' : 'copias'; ?></span></a>
				<a class="is-success" href="#premiero-remote-queue"><strong>En servidor SFTP</strong><span><?php echo esc_html( (string) $set_counts['synced'] ); ?> <?php echo 1 === $set_counts['synced'] ? 'copia verificada' : 'copias verificadas'; ?></span></a>
				<a class="is-warning" href="#premiero-remote-queue"><strong>Esperando retencion</strong><span><?php echo esc_html( (string) $set_counts['retention'] ); ?> <?php echo 1 === $set_counts['retention'] ? 'copia' : 'copias'; ?></span></a>
				<a class="is-danger" href="#premiero-remote-queue"><strong>Requieren revision</strong><span><?php echo esc_html( (string) $set_counts['review'] ); ?> <?php echo 1 === $set_counts['review'] ? 'copia' : 'copias'; ?></span></a>
			</div>

			<?php self::render_queue( $sets, $last_reconcile ); ?>

			<details class="premiero-remote-settings-panel" id="premiero-remote-settings">
				<summary><span><strong>Configuracion y seguridad SFTP</strong><small>Host, credenciales, retencion y diagnostico</small></span><span class="premiero-remote-settings-chevron" aria-hidden="true"></span></summary>
				<div class="premiero-remote-settings-body">
					<div class="premiero-remote-help">
						<strong>Como funciona</strong>
						<p>UpdraftPlus sigue generando, programando y restaurando las copias. Premiero solo se encarga de transportarlas por SFTP y no modifica Premiero Control.</p>
						<p><strong>Recomendacion:</strong> deja el almacenamiento remoto de UpdraftPlus en <em>Ninguno</em>. Despues de guardar estos datos, usa <strong>Probar conexion</strong> una vez para verificar escritura, tamaño y clave SSH.</p>
					</div>

					<form method="post" class="premiero-remote-settings-form">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Sincronizacion</th>
						<td>
							<label><input type="checkbox" name="premiero_remote_backups_enabled" value="1" <?php checked( ! empty( $config['enabled'] ) ); ?>> Activar deteccion y subida automatica</label>
							<p class="description">Desactivarla detiene los cron propios sin borrar la configuracion ni el historial.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Retencion remota</th>
						<td>
							<label><input type="checkbox" name="premiero_remote_backups_sync_deletions" value="1" <?php checked( ! empty( $config['sync_deletions'] ) ); ?>> Mantener el servidor SFTP sincronizado con las copias conservadas por UpdraftPlus</label>
							<p class="description">UpdraftPlus decide cuantas copias se conservan: si configuras 4, el servidor SFTP terminara conservando esas mismas 4. Premiero eliminara unicamente archivos que hubiera sincronizado previamente, cuando no haya subidas pendientes y despues de varias comprobaciones durante al menos 30 minutos.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="premiero-remote-host">Host</label></th>
						<td>
							<input type="text" class="regular-text code" id="premiero-remote-host" name="premiero_remote_backups_host" value="<?php echo esc_attr( $config['host'] ); ?>" placeholder="sftp.example.com" required>
							<p class="description">Introduce solo el dominio, sin <code>sftp://</code> ni puerto.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="premiero-remote-port">Puerto</label></th>
						<td>
							<input type="number" class="small-text" id="premiero-remote-port" name="premiero_remote_backups_port" value="<?php echo esc_attr( $config['port'] ); ?>" min="1" max="65535" required>
							<p class="description">El puerto habitual es 22. Algunos proveedores ofrecen puertos SFTP alternativos.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="premiero-remote-username">Usuario</label></th>
						<td><input type="text" class="regular-text" id="premiero-remote-username" name="premiero_remote_backups_username" value="<?php echo esc_attr( $config['username'] ); ?>" autocomplete="username" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="premiero-remote-password">Contrasena</label></th>
						<td>
							<input type="password" class="regular-text" id="premiero-remote-password" name="premiero_remote_backups_password" value="" autocomplete="new-password" <?php echo self::has_password() ? '' : 'required'; ?>>
							<p class="description"><?php echo self::has_password() ? 'Ya hay una contrasena cifrada. Deja el campo vacio para conservarla.' : 'Se guardara cifrada con las salts de WordPress.'; ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="premiero-remote-path">Ruta remota</label></th>
						<td>
							<input type="text" class="regular-text code" id="premiero-remote-path" name="premiero_remote_backups_path" value="<?php echo esc_attr( $config['remote_path'] ); ?>" placeholder="/backups/mi-web" required>
							<p class="description">La prueba creara la ruta si no existe y escribira un archivo temporal de 4 KB.</p>
						</td>
					</tr>
				</table>

				<div class="premiero-remote-settings-actions">
					<button type="submit" class="button button-primary" name="premiero_remote_backups_action" value="save">Guardar cambios</button>
					<button type="submit" class="button" name="premiero_remote_backups_action" value="test">Probar conexion</button>
					<span class="description">La sincronizacion automatica no necesita que vuelvas a abrir esta seccion.</span>
				</div>
			</form>

					<?php if ( $fingerprint ) : ?>
						<div class="premiero-remote-fingerprint">
							<div><strong>Clave SSH confiada</strong><span><?php echo esc_html( $config['host'] . ':' . $config['port'] ); ?></span></div>
							<code><?php echo esc_html( $fingerprint ); ?></code>
							<form method="post" onsubmit="return window.confirm('¿Olvidar la clave SSH guardada para este servidor?');">
								<?php wp_nonce_field( self::NONCE_ACTION ); ?>
								<input type="hidden" name="premiero_remote_backups_action" value="forget_key">
								<?php submit_button( 'Olvidar clave SSH', 'secondary', 'submit', false ); ?>
							</form>
						</div>
					<?php endif; ?>
					<?php self::render_last_test( $last_test ); ?>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * @return array
	 */
	private static function get_config() {
		$stored = get_option( self::OPT_CONFIG, array() );
		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				'enabled'     => false,
				'sync_deletions' => false,
				'host'        => '',
				'port'        => 22,
				'username'    => '',
				'remote_path' => '/backups',
			)
		);
	}

	/**
	 * @return array|WP_Error
	 */
	private static function config_from_request() {
		$host = isset( $_POST['premiero_remote_backups_host'] )
			? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['premiero_remote_backups_host'] ) ) ) )
			: '';
		$port = isset( $_POST['premiero_remote_backups_port'] ) ? absint( $_POST['premiero_remote_backups_port'] ) : 22;
		$username = isset( $_POST['premiero_remote_backups_username'] )
			? trim( sanitize_text_field( wp_unslash( $_POST['premiero_remote_backups_username'] ) ) )
			: '';
		$path = isset( $_POST['premiero_remote_backups_path'] )
			? trim( sanitize_text_field( wp_unslash( $_POST['premiero_remote_backups_path'] ) ) )
			: '/backups';

		if ( '' === $host || false !== strpos( $host, '://' ) || preg_match( '/[\s\/:]/', $host ) ) {
			return new WP_Error( 'premiero_sftp_invalid_host', 'Introduce un host valido sin protocolo, ruta ni puerto.' );
		}
		if ( ! preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
			return new WP_Error( 'premiero_sftp_invalid_host', 'El host contiene caracteres no admitidos.' );
		}
		if ( $port < 1 || $port > 65535 ) {
			return new WP_Error( 'premiero_sftp_invalid_port', 'El puerto SFTP debe estar entre 1 y 65535.' );
		}
		if ( '' === $username || preg_match( '/[\x00-\x1F\x7F]/', $username ) ) {
			return new WP_Error( 'premiero_sftp_invalid_username', 'Introduce un usuario SFTP valido.' );
		}

		$path = str_replace( '\\', '/', $path );
		if ( '' === $path || preg_match( '#(^|/)\.\.(/|$)#', $path ) || preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
			return new WP_Error( 'premiero_sftp_invalid_path', 'La ruta remota no es valida.' );
		}
		$path = '/' . trim( preg_replace( '#/+#', '/', $path ), '/' );
		if ( '/' === $path ) {
			$path = '/';
		}

		return array(
			'enabled'     => isset( $_POST['premiero_remote_backups_enabled'] ) ? 1 : 0,
			'sync_deletions' => isset( $_POST['premiero_remote_backups_sync_deletions'] ) ? 1 : 0,
			'host'        => $host,
			'port'        => $port,
			'username'    => $username,
			'remote_path' => $path,
		);
	}

	public static function is_enabled() {
		$config = self::get_config();
		return ! empty( $config['enabled'] );
	}

	public static function sync_deletions_enabled() {
		$config = self::get_config();
		return ! empty( $config['enabled'] ) && ! empty( $config['sync_deletions'] );
	}

	/**
	 * Identifica el destino sin incluir la contrasena ni otros secretos.
	 *
	 * @param array|null $config Configuracion normalizada.
	 * @return string
	 */
	public static function target_key( $config = null ) {
		$config = is_array( $config ) ? $config : self::get_config();
		$parts = array(
			strtolower( trim( isset( $config['host'] ) ? (string) $config['host'] : '' ) ),
			isset( $config['port'] ) ? (int) $config['port'] : 22,
			trim( isset( $config['username'] ) ? (string) $config['username'] : '' ),
			isset( $config['remote_path'] ) ? (string) $config['remote_path'] : '/',
		);
		return hash( 'sha256', implode( "\0", $parts ) );
	}

	/**
	 * Configuracion completa para el worker, incluida la credencial descifrada.
	 *
	 * @return array|WP_Error
	 */
	public static function runtime_config() {
		$config = self::get_config();
		if ( empty( $config['enabled'] ) ) {
			return new WP_Error( 'premiero_remote_disabled', 'Los backups remotos estan desactivados.' );
		}
		$password = self::get_password();
		if ( is_wp_error( $password ) ) {
			return $password;
		}
		$fingerprint = self::get_fingerprint( $config['host'], $config['port'] );
		if ( '' === $fingerprint ) {
			return new WP_Error( 'premiero_sftp_untrusted_host', 'Prueba la conexion una vez para registrar la clave SSH del servidor.' );
		}
		$config['password']             = $password;
		$config['expected_fingerprint'] = $fingerprint;
		return $config;
	}

	private static function build_backup_sets( $items, $config ) {
		$retained = Premiero_Backup_Detector::retained_backup_ids();
		$retained = is_wp_error( $retained ) ? array() : $retained;
		$directory = Premiero_Backup_Detector::backup_directory();
		$sets = array();

		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$backup_id = trim( (string) $item->backup_id );
			if ( '' === $backup_id ) {
				$backup_id = 'registro-' . (int) $item->id;
			}
			if ( ! isset( $sets[ $backup_id ] ) ) {
				$sets[ $backup_id ] = array(
					'backup_id'     => $backup_id,
					'items'         => array(),
					'statuses'      => array(),
					'components'    => array(),
					'bytes'         => 0,
					'local_files'   => 0,
					'updated_at'    => 0,
					'missing_since' => 0,
					'retained'      => isset( $retained[ $backup_id ] ),
				);
			}

			$sets[ $backup_id ]['items'][] = $item;
			$status = (string) $item->status;
			$sets[ $backup_id ]['statuses'][ $status ] = isset( $sets[ $backup_id ]['statuses'][ $status ] )
				? $sets[ $backup_id ]['statuses'][ $status ] + 1
				: 1;
			$sets[ $backup_id ]['components'][ self::component_label( $item->filename ) ] = true;
			$sets[ $backup_id ]['bytes'] += (int) $item->local_size;
			$sets[ $backup_id ]['updated_at'] = max( $sets[ $backup_id ]['updated_at'], (int) $item->updated_at );
			if ( ! empty( $item->local_missing_since ) ) {
				$sets[ $backup_id ]['missing_since'] = 0 === $sets[ $backup_id ]['missing_since']
					? (int) $item->local_missing_since
					: min( $sets[ $backup_id ]['missing_since'], (int) $item->local_missing_since );
			}
			$local = trailingslashit( $directory ) . basename( (string) $item->filename );
			if ( is_file( $local ) && ! is_link( $local ) ) {
				++$sets[ $backup_id ]['local_files'];
			}
		}

		foreach ( $sets as &$set ) {
			$total    = count( $set['items'] );
			$statuses = $set['statuses'];
			$retention_statuses = array_diff( array_keys( $statuses ), array( 'synced', 'missing' ) );
			$awaits_retention = ! empty( $config['sync_deletions'] )
				&& empty( $set['retained'] )
				&& 0 === (int) $set['local_files']
				&& empty( $retention_statuses );
			if ( ! empty( $statuses['uploading'] ) ) {
				$set['state'] = 'uploading';
			} elseif ( ! empty( $statuses['pending'] ) || ! empty( $statuses['retry'] ) ) {
				$set['state'] = 'pending';
			} elseif ( $awaits_retention ) {
				$set['state'] = 'retention';
			} elseif ( ! empty( $statuses['orphaned'] ) || ! empty( $statuses['missing'] ) ) {
				$set['state'] = 'review';
			} elseif ( isset( $statuses['pruned'] ) && (int) $statuses['pruned'] === $total ) {
				$set['state'] = 'pruned';
			} elseif ( isset( $statuses['synced'] ) && (int) $statuses['synced'] === $total ) {
				$set['state'] = 'synced';
			} else {
				$set['state'] = 'review';
			}
			$set['synced_files'] = isset( $statuses['synced'] ) ? (int) $statuses['synced'] : 0;
			$set['components']   = array_keys( $set['components'] );
		}
		unset( $set );

		uasort(
			$sets,
			static function ( $left, $right ) {
				return (int) $right['updated_at'] <=> (int) $left['updated_at'];
			}
		);
		return array_values( $sets );
	}

	private static function set_counts( $sets ) {
		$counts = array( 'pending' => 0, 'synced' => 0, 'retention' => 0, 'review' => 0, 'pruned' => 0 );
		foreach ( $sets as $set ) {
			$state = in_array( $set['state'], array( 'pending', 'uploading' ), true ) ? 'pending' : $set['state'];
			if ( isset( $counts[ $state ] ) ) {
				++$counts[ $state ];
			}
		}
		return $counts;
	}

	private static function render_queue( $sets, $last_reconcile ) {
		$active_sets = array();
		$pruned_sets = array();
		foreach ( $sets as $set ) {
			if ( 'pruned' === $set['state'] ) {
				$pruned_sets[] = $set;
			} else {
				$active_sets[] = $set;
			}
		}
		?>
		<section id="premiero-remote-queue" style="max-width:1100px;margin-top:28px;">
			<h3>Copias y actividad de sincronizacion</h3>
			<p style="max-width:920px;">Cada fila representa una <strong>copia de UpdraftPlus</strong>. Despliega sus detalles para ver los archivos que contiene. Una copia solo figura como sincronizada cuando todos sus archivos remotos tienen el mismo tamano que los locales.</p>
			<?php if ( is_array( $last_reconcile ) && ! empty( $last_reconcile['inventory_checked'] ) && ! empty( $last_reconcile['unmanaged_files'] ) ) : ?>
				<div class="notice notice-warning inline" style="margin:14px 0;padding:10px 14px;max-width:920px;">
							<p style="margin:0;"><strong>Archivos anteriores no gestionados:</strong> El servidor remoto contiene <?php echo esc_html( (string) $last_reconcile['unmanaged_sets'] ); ?> copias (<?php echo esc_html( (string) $last_reconcile['unmanaged_files'] ); ?> archivos) que no fueron subidas por este modulo. Se muestran como aviso, pero no se eliminan automaticamente para evitar borrar datos ajenos.</p>
				</div>
			<?php endif; ?>
			<?php if ( empty( $active_sets ) ) : ?>
				<p><?php echo empty( $sets ) ? 'Todavia no se han detectado archivos de backup terminados.' : 'No hay copias activas ni pendientes en la cola.'; ?></p>
			<?php else : ?>
				<div class="premiero-remote-table-wrap">
				<table class="widefat striped premiero-remote-table" style="table-layout:auto;">
					<thead><tr><th>Copia</th><th>Contenido</th><th>Estado</th><th>Progreso</th><th>Tamano</th><th>Ultima actividad</th><th>Archivos</th></tr></thead>
					<tbody>
					<?php foreach ( $active_sets as $set ) : ?>
						<tr>
							<td><strong><?php echo esc_html( self::backup_date_label( $set['backup_id'] ) ); ?></strong></td>
							<td><?php echo esc_html( implode( ', ', $set['components'] ) ); ?></td>
							<td>
								<strong style="color:<?php echo esc_attr( self::state_color( $set['state'] ) ); ?>;"><?php echo esc_html( self::state_label( $set['state'] ) ); ?></strong>
								<div style="max-width:260px;font-size:12px;margin-top:4px;color:#50575e;"><?php echo esc_html( self::state_detail( $set ) ); ?></div>
							</td>
							<td><?php echo esc_html( self::progress_label( $set ) ); ?></td>
							<td><?php echo esc_html( size_format( (int) $set['bytes'], 2 ) ); ?></td>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $set['updated_at'] ) ); ?></td>
							<td>
								<details>
									<summary style="cursor:pointer;">Ver <?php echo esc_html( (string) count( $set['items'] ) ); ?> archivos</summary>
									<ul style="margin:8px 0 0 18px;min-width:280px;">
									<?php foreach ( $set['items'] as $item ) : ?>
										<li style="margin-bottom:8px;">
											<code style="overflow-wrap:anywhere;"><?php echo esc_html( $item->filename ); ?></code><br>
											<?php echo esc_html( self::file_status_label( $item->status, $set['state'] ) . ' · ' . size_format( (int) $item->local_size, 2 ) ); ?>
											<?php if ( ! empty( $item->attempts ) ) : ?> · <?php echo esc_html( (string) $item->attempts ); ?> intentos<?php endif; ?>
											<?php if ( ! in_array( $set['state'], array( 'retention', 'pruned' ), true ) && '' !== trim( (string) $item->last_error ) ) : ?><br><span style="color:#646970;"><?php echo esc_html( (string) $item->last_error ); ?></span><?php endif; ?>
										</li>
									<?php endforeach; ?>
									</ul>
								</details>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $pruned_sets ) ) : ?>
				<details style="margin-top:16px;max-width:920px;padding:10px 14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">
					<summary style="cursor:pointer;"><strong>Historial de retencion</strong> · <?php echo esc_html( (string) count( $pruned_sets ) ); ?> copias eliminadas del servidor SFTP</summary>
					<ul style="margin:12px 0 0 20px;">
					<?php foreach ( $pruned_sets as $set ) : ?>
						<li><?php echo esc_html( self::backup_date_label( $set['backup_id'] ) . ' · ' . implode( ', ', $set['components'] ) . ' · ' . size_format( (int) $set['bytes'], 2 ) ); ?></li>
					<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function backup_date_label( $backup_id ) {
		$raw = substr( (string) $backup_id, 0, 15 );
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d-Hi', $raw, wp_timezone() );
		if ( false === $date ) {
			return 'Copia ' . $backup_id;
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $date->getTimestamp(), wp_timezone() );
	}

	private static function component_label( $filename ) {
		if ( ! preg_match( '/_[0-9a-f]{12}-([a-z]+)[0-9]*\./i', (string) $filename, $matches ) ) {
			return 'Backup';
		}
		$labels = array(
			'db'      => 'Base de datos',
			'plugins' => 'Plugins',
			'themes'  => 'Temas',
			'uploads' => 'Medios',
			'others'  => 'Otros archivos',
		);
		$key = strtolower( $matches[1] );
		return isset( $labels[ $key ] ) ? $labels[ $key ] : ucfirst( $key );
	}

	private static function state_label( $state ) {
		$labels = array(
			'uploading' => 'Subiendo ahora',
			'pending'   => 'Pendiente de subida',
			'synced'    => 'Sincronizada',
			'retention' => 'Esperando retencion',
			'pruned'    => 'Eliminada por retencion',
			'review'    => 'Requiere revision',
		);
		return isset( $labels[ $state ] ) ? $labels[ $state ] : (string) $state;
	}

	private static function state_color( $state ) {
		$colors = array(
			'uploading' => '#135e96',
			'pending'   => '#996800',
			'synced'    => '#116329',
			'retention' => '#996800',
			'pruned'    => '#646970',
			'review'    => '#8a2424',
		);
		return isset( $colors[ $state ] ) ? $colors[ $state ] : '#50575e';
	}

	private static function state_detail( $set ) {
		switch ( $set['state'] ) {
			case 'uploading':
				return 'Hay una transferencia SFTP activa.';
			case 'pending':
				return 'Se subira o reintentara automaticamente.';
			case 'synced':
				return 'Todos los tamanos remotos coinciden.';
			case 'retention':
				if ( empty( $set['missing_since'] ) ) {
					return 'Ya no esta en UpdraftPlus; falta iniciar la comprobacion de seguridad.';
				}
				$remaining = max( 0, Premiero_Backup_Reconciler::DELETION_GRACE - ( time() - (int) $set['missing_since'] ) );
				return $remaining > 0
					? 'Ya no esta en UpdraftPlus; se eliminara tras ' . human_time_diff( time(), time() + $remaining ) . ' y una nueva comprobacion.'
					: 'Periodo de seguridad cumplido; se eliminara en la proxima comprobacion.';
			case 'pruned':
				return 'Premiero retiro sus archivos del servidor SFTP.';
			default:
				return 'Abre los archivos para consultar el detalle.';
		}
	}

	private static function progress_label( $set ) {
		if ( 'retention' === $set['state'] ) {
			return 'Retencion programada';
		}
		if ( 'pruned' === $set['state'] ) {
			return 'Retirada del servidor SFTP';
		}
		if ( 'review' === $set['state'] ) {
			return 'Revisar el detalle';
		}
		return (string) $set['synced_files'] . ' de ' . count( $set['items'] ) . ' verificados';
	}

	private static function file_status_label( $status, $set_state ) {
		if ( 'retention' === $set_state ) {
			return 'Ya no esta en UpdraftPlus';
		}
		if ( 'pruned' === $set_state ) {
			return 'Eliminado del servidor SFTP';
		}
		return self::status_label( $status );
	}

	private static function status_label( $status ) {
		$labels = array( 'pending' => 'Pendiente', 'retry' => 'Reintento', 'uploading' => 'Subiendo', 'synced' => 'Sincronizado', 'missing' => 'No disponible', 'pruned' => 'Eliminado por retencion', 'orphaned' => 'Requiere revision' );
		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * @param array $last_test Ultimo diagnostico.
	 */
	private static function render_last_test( $last_test ) {
		if ( ! is_array( $last_test ) || empty( $last_test['captured_at'] ) ) {
			return;
		}
		$success = ! empty( $last_test['success'] );
		?>
		<section style="max-width:920px;margin-top:24px;">
			<h3>Ultimo diagnostico</h3>
			<p>
				<strong style="color:<?php echo $success ? '#116329' : '#8a2424'; ?>;"><?php echo $success ? 'SFTP operativo' : 'SFTP no operativo'; ?></strong>
				· <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last_test['captured_at'] ) ); ?>
				· <?php echo esc_html( $last_test['host'] . ':' . $last_test['port'] ); ?>
			</p>
			<table class="widefat striped" style="max-width:920px;">
				<thead><tr><th>Puerto</th><th>TCP</th><th>Latencia</th><th>Respuesta</th></tr></thead>
				<tbody>
				<?php foreach ( isset( $last_test['probes'] ) && is_array( $last_test['probes'] ) ? $last_test['probes'] : array() as $probe ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $probe['port'] ); ?></td>
						<td><?php echo ! empty( $probe['reachable'] ) ? '<strong style="color:#116329;">Accesible</strong>' : '<strong style="color:#8a2424;">Bloqueado o inaccesible</strong>'; ?></td>
						<td><?php echo esc_html( (string) $probe['latency_ms'] ); ?> ms</td>
						<td><?php echo esc_html( ! empty( $probe['banner'] ) ? $probe['banner'] : ( isset( $probe['error'] ) ? $probe['error'] : '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( isset( $last_test['result'] ) && is_array( $last_test['result'] ) ) : ?>
				<div style="margin-top:12px;padding:12px 14px;border-left:4px solid <?php echo $success ? '#00a32a' : '#d63638'; ?>;background:#fff;">
					<?php if ( $success ) : ?>
						<?php echo esc_html( (string) $last_test['result']['bytes_verified'] ); ?> bytes verificados en <?php echo esc_html( (string) $last_test['result']['duration_ms'] ); ?> ms.
						SFTP v<?php echo esc_html( (string) $last_test['result']['sftp_version'] ); ?>.
					<?php else : ?>
						<strong><?php echo esc_html( (string) $last_test['result']['code'] ); ?>:</strong>
						<?php echo esc_html( (string) $last_test['result']['message'] ); ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @param string $password Contrasena plana.
	 * @return string|WP_Error
	 */
	private static function encrypt_password( $password ) {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_get_cipher_methods' ) ) {
			return new WP_Error( 'premiero_sftp_crypto_unavailable', 'OpenSSL es necesario para guardar la contrasena de forma segura.' );
		}
		try {
			$master  = self::master_key();
			$ciphers = array_map( 'strtolower', openssl_get_cipher_methods() );
			if ( in_array( 'aes-256-gcm', $ciphers, true ) ) {
				$iv  = random_bytes( 12 );
				$tag = '';
				$ct  = openssl_encrypt( (string) $password, 'aes-256-gcm', $master, OPENSSL_RAW_DATA, $iv, $tag );
				if ( false === $ct || '' === $tag ) {
					throw new \RuntimeException( 'No se pudo cifrar la contrasena.' );
				}
				$algorithm = 'aes-256-gcm';
			} elseif ( in_array( 'aes-256-cbc', $ciphers, true ) ) {
				$enc_key = hash( 'sha256', $master . "\0enc", true );
				$mac_key = hash( 'sha256', $master . "\0mac", true );
				$iv      = random_bytes( 16 );
				$ct      = openssl_encrypt( (string) $password, 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv );
				if ( false === $ct ) {
					throw new \RuntimeException( 'No se pudo cifrar la contrasena.' );
				}
				$tag       = hash_hmac( 'sha256', $iv . $ct, $mac_key, true );
				$algorithm = 'aes-256-cbc-hmac-sha256';
			} else {
				return new WP_Error( 'premiero_sftp_cipher_unavailable', 'No hay un cifrado seguro compatible disponible.' );
			}
			return base64_encode(
				wp_json_encode(
					array(
						'v'   => 1,
						'alg' => $algorithm,
						'iv'  => base64_encode( $iv ),
						'tag' => base64_encode( $tag ),
						'ct'  => base64_encode( $ct ),
					)
				)
			);
		} catch ( \Throwable $error ) {
			return new WP_Error( 'premiero_sftp_encrypt_failed', sanitize_text_field( $error->getMessage() ) );
		}
	}

	/**
	 * @return string|WP_Error
	 */
	private static function get_password() {
		$stored = (string) get_option( self::OPT_PASSWORD, '' );
		if ( '' === $stored ) {
			return new WP_Error( 'premiero_sftp_password_missing', 'Introduce la contrasena del servidor SFTP antes de probar la conexion.' );
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return new WP_Error( 'premiero_sftp_crypto_unavailable', 'OpenSSL es necesario para recuperar la contrasena guardada.' );
		}

		$decoded = base64_decode( $stored, true );
		$envelope = $decoded ? json_decode( $decoded, true ) : null;
		if ( ! is_array( $envelope ) || empty( $envelope['alg'] ) ) {
			return new WP_Error( 'premiero_sftp_password_invalid', 'La contrasena guardada tiene un formato invalido.' );
		}

		$iv  = isset( $envelope['iv'] ) ? base64_decode( $envelope['iv'], true ) : false;
		$tag = isset( $envelope['tag'] ) ? base64_decode( $envelope['tag'], true ) : false;
		$ct  = isset( $envelope['ct'] ) ? base64_decode( $envelope['ct'], true ) : false;
		if ( false === $iv || false === $tag || false === $ct ) {
			return new WP_Error( 'premiero_sftp_password_invalid', 'La contrasena guardada esta danada.' );
		}

		$master = self::master_key();
		if ( 'aes-256-gcm' === $envelope['alg'] ) {
			$password = openssl_decrypt( $ct, 'aes-256-gcm', $master, OPENSSL_RAW_DATA, $iv, $tag );
		} elseif ( 'aes-256-cbc-hmac-sha256' === $envelope['alg'] ) {
			$enc_key      = hash( 'sha256', $master . "\0enc", true );
			$mac_key      = hash( 'sha256', $master . "\0mac", true );
			$expected_tag = hash_hmac( 'sha256', $iv . $ct, $mac_key, true );
			if ( ! hash_equals( $expected_tag, $tag ) ) {
				return new WP_Error( 'premiero_sftp_password_auth', 'No se pudo autenticar la contrasena guardada.' );
			}
			$password = openssl_decrypt( $ct, 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv );
		} else {
			return new WP_Error( 'premiero_sftp_password_algorithm', 'El algoritmo de la contrasena guardada no es compatible.' );
		}
		if ( false === $password || '' === $password ) {
			return new WP_Error( 'premiero_sftp_password_decrypt', 'No se pudo descifrar la contrasena. Comprueba las salts de WordPress.' );
		}
		return $password;
	}

	/**
	 * @return string
	 */
	private static function master_key() {
		$material = wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|' . wp_salt( 'logged_in' );
		return hash( 'sha256', 'premiero-remote-backups|' . $material, true );
	}

	/**
	 * @return bool
	 */
	private static function has_password() {
		return '' !== (string) get_option( self::OPT_PASSWORD, '' );
	}

	/**
	 * @param string $host Host.
	 * @param int    $port Puerto.
	 * @return string
	 */
	private static function get_fingerprint( $host, $port ) {
		$keys = get_option( self::OPT_FINGERPRINTS, array() );
		$key  = strtolower( trim( (string) $host ) ) . ':' . (int) $port;
		return is_array( $keys ) && isset( $keys[ $key ] ) ? (string) $keys[ $key ] : '';
	}

	private static function store_fingerprint( $host, $port, $fingerprint ) {
		$keys = get_option( self::OPT_FINGERPRINTS, array() );
		$keys = is_array( $keys ) ? $keys : array();
		$keys[ strtolower( trim( (string) $host ) ) . ':' . (int) $port ] = sanitize_text_field( $fingerprint );
		update_option( self::OPT_FINGERPRINTS, $keys, false );
	}

	private static function forget_fingerprint( $host, $port ) {
		$keys = get_option( self::OPT_FINGERPRINTS, array() );
		$keys = is_array( $keys ) ? $keys : array();
		unset( $keys[ strtolower( trim( (string) $host ) ) . ':' . (int) $port ] );
		update_option( self::OPT_FINGERPRINTS, $keys, false );
	}

	private static function set_notice( $type, $message ) {
		set_transient(
			'premiero_remote_backups_notice_' . get_current_user_id(),
			array(
				'type'    => sanitize_key( $type ),
				'message' => sanitize_text_field( $message ),
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	private static function render_notice() {
		$key    = 'premiero_remote_backups_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
	}

	private static function redirect() {
		$url = add_query_arg(
			array(
				'page' => defined( 'PREMIERO_ATK_SLUG' ) ? PREMIERO_ATK_SLUG : 'premiero-admin',
				'tab'  => 'remote-backups',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
