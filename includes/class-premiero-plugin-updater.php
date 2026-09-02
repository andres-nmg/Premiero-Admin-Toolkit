<?php
/** Envoltorio cerrado sobre el actualizador nativo de WordPress. */

defined( 'ABSPATH' ) || exit;

final class Premiero_Plugin_Updater {

	public static function validate_plugin_file( $plugin_file ) {
		$plugin_file = is_scalar( $plugin_file ) ? trim( (string) $plugin_file ) : '';
		if (
			'' === $plugin_file || strlen( $plugin_file ) > 191
			|| false !== strpos( $plugin_file, '\\' ) || false !== strpos( $plugin_file, '..' )
			|| '/' === substr( $plugin_file, 0, 1 )
			|| ! preg_match( '#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*\.php$#', $plugin_file )
		) {
			return new WP_Error( 'premiero_plugin_file_invalid', 'La identidad del plugin no es válida.' );
		}
		return $plugin_file;
	}

	/** Fuerza los metadatos oficiales y valida que haya paquete. */
	public static function preflight( $plugin_file ) {
		if ( is_multisite() ) {
			return new WP_Error( 'premiero_multisite_unsupported', 'Las actualizaciones remotas no admiten Multisite.' );
		}
		$plugin_file = self::validate_plugin_file( $plugin_file );
		if ( is_wp_error( $plugin_file ) ) {
			return $plugin_file;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin_file ] ) ) {
			return new WP_Error( 'premiero_plugin_not_installed', 'El plugin solicitado no está instalado.' );
		}
		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );
		if ( ! is_object( $updates ) || empty( $updates->response[ $plugin_file ] ) ) {
			return new WP_Error( 'premiero_plugin_update_missing', 'WordPress no ofrece una actualización para este plugin.' );
		}
		$update  = $updates->response[ $plugin_file ];
		$package = is_object( $update ) && isset( $update->package ) ? (string) $update->package : ( is_array( $update ) && isset( $update['package'] ) ? (string) $update['package'] : '' );
		$target  = is_object( $update ) && isset( $update->new_version ) ? (string) $update->new_version : ( is_array( $update ) && isset( $update['new_version'] ) ? (string) $update['new_version'] : '' );
		if ( '' === trim( $package ) || '' === trim( $target ) ) {
			return new WP_Error( 'premiero_plugin_package_unavailable', 'La actualización no tiene un paquete autorizado disponible; comprueba la licencia.' );
		}
		return array(
			'plugin_file'     => $plugin_file,
			'previous_version'=> sanitize_text_field( (string) $plugins[ $plugin_file ]['Version'] ),
			'target_version'  => sanitize_text_field( $target ),
			'was_active'      => is_plugin_active( $plugin_file ),
		);
	}

	/** Ejecuta exactamente Plugin_Upgrader::upgrade() y verifica el resultado. */
	public static function upgrade( $preflight ) {
		if ( ! is_array( $preflight ) || empty( $preflight['plugin_file'] ) ) {
			return new WP_Error( 'premiero_preflight_missing', 'Falta la comprobación previa de la actualización.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $preflight['plugin_file'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			return new WP_Error( 'premiero_plugin_upgrade_failed', 'WordPress no pudo completar la actualización.' );
		}

		wp_clean_plugins_cache( true );
		wp_update_plugins();
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $preflight['plugin_file'] ] ) ) {
			return new WP_Error( 'premiero_plugin_missing_after_update', 'El plugin no aparece instalado después de actualizar.' );
		}
		$installed = sanitize_text_field( (string) $plugins[ $preflight['plugin_file'] ]['Version'] );
		$active    = is_plugin_active( $preflight['plugin_file'] );
		if ( $installed === $preflight['previous_version'] || version_compare( $installed, $preflight['target_version'], '<' ) ) {
			return new WP_Error( 'premiero_plugin_version_not_updated', 'La versión instalada no alcanzó la versión esperada.' );
		}
		if ( ! empty( $preflight['was_active'] ) && ! $active ) {
			$reactivated = activate_plugin( $preflight['plugin_file'], '', false, true );
			if ( is_wp_error( $reactivated ) ) {
				return new WP_Error( 'premiero_plugin_reactivation_failed', 'El plugin se actualizó, pero WordPress no pudo reactivarlo de forma segura.' );
			}
			wp_clean_plugins_cache( false );
			$active = is_plugin_active( $preflight['plugin_file'] );
			if ( ! $active ) {
				return new WP_Error( 'premiero_plugin_became_inactive', 'El plugin se actualizó, pero continuó inactivo tras intentar reactivarlo.' );
			}
		}
		return array(
			'installed_version' => $installed,
			'is_active'         => $active,
		);
	}
}
