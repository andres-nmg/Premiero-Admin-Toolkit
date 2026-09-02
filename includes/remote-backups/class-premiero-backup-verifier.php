<?php
/**
 * Verificaciones locales y remotas de una transferencia.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Backup_Verifier {

	/**
	 * @param string $path Ruta local.
	 * @param int    $expected_size Tamano en cola.
	 * @param int    $expected_mtime Fecha en cola.
	 * @return true|WP_Error
	 */
	public function verify_local( $path, $expected_size, $expected_mtime ) {
		clearstatcache( true, $path );
		if ( ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'premiero_backup_local_missing', 'El archivo local no existe o no es legible.' );
		}
		$size  = filesize( $path );
		$mtime = filemtime( $path );
		if ( false === $size || false === $mtime ) {
			return new WP_Error( 'premiero_backup_local_stat', 'No se pudo consultar el archivo local.' );
		}
		if ( (int) $size !== (int) $expected_size || (int) $mtime !== (int) $expected_mtime ) {
			return new WP_Error( 'premiero_backup_local_changed', 'El archivo local ha cambiado desde que se encolo.' );
		}
		return true;
	}

	/**
	 * @param Premiero_SFTP_Client $client Cliente conectado.
	 * @param string               $remote Archivo remoto.
	 * @param int                  $expected_size Tamano esperado.
	 * @return true|WP_Error
	 */
	public function verify_remote( $client, $remote, $expected_size ) {
		$size = $client->remote_size( $remote );
		if ( false === $size ) {
			return new WP_Error( 'premiero_backup_remote_missing', 'No se pudo consultar el archivo remoto.' );
		}
		if ( (int) $size !== (int) $expected_size ) {
			return new WP_Error( 'premiero_backup_remote_size', 'El tamano remoto no coincide con el archivo local.' );
		}
		return true;
	}
}
