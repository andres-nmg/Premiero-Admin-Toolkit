<?php
/**
 * Cola persistente por archivo para backups remotos.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Backup_Sync_Queue {

	const DB_VERSION = 2;
	const OPT_DB_VERSION = 'premiero_remote_backups_db_version';

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			fingerprint char(64) NOT NULL,
			backup_id varchar(80) NOT NULL DEFAULT '',
			filename varchar(255) NOT NULL,
			local_size bigint(20) unsigned NOT NULL DEFAULT 0,
			local_mtime bigint(20) unsigned NOT NULL DEFAULT 0,
			remote_file text NULL,
			remote_size bigint(20) unsigned NULL,
			remote_target char(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			next_attempt_at bigint(20) unsigned NOT NULL DEFAULT 0,
			local_missing_since bigint(20) unsigned NOT NULL DEFAULT 0,
			last_error text NULL,
			created_at bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
			synced_at bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY fingerprint (fingerprint),
			KEY status_next (status,next_attempt_at)
		) {$charset};";
		dbDelta( $sql );
		update_option( self::OPT_DB_VERSION, self::DB_VERSION, false );
	}

	public static function maybe_install() {
		if ( self::DB_VERSION !== (int) get_option( self::OPT_DB_VERSION, 0 ) ) {
			self::install();
		}
	}

	/**
	 * @param array $file Archivo normalizado por el detector.
	 * @return bool
	 */
	public static function enqueue( $file ) {
		global $wpdb;
		$now = time();
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::table_name() . ' (fingerprint, backup_id, filename, local_size, local_mtime, status, attempts, next_attempt_at, created_at, updated_at) VALUES (%s, %s, %s, %d, %d, %s, 0, 0, %d, %d) ON DUPLICATE KEY UPDATE next_attempt_at = IF(status IN (\'missing\',\'pruned\'), 0, next_attempt_at), local_missing_since = IF(status IN (\'missing\',\'pruned\'), 0, local_missing_since), last_error = IF(status IN (\'missing\',\'pruned\'), \'\', last_error), attempts = IF(status IN (\'missing\',\'pruned\'), 0, attempts), updated_at = IF(status IN (\'missing\',\'pruned\'), VALUES(updated_at), updated_at), status = IF(status IN (\'missing\',\'pruned\'), \'pending\', status)',
				(string) $file['fingerprint'],
				(string) $file['backup_id'],
				(string) $file['filename'],
				(int) $file['size'],
				(int) $file['mtime'],
				'pending',
				$now,
				$now
			)
		);
		return (int) $result > 0;
	}

	/**
	 * Recupera el siguiente trabajo y lo reserva dentro de la misma peticion.
	 * El lock global del worker evita consumidores concurrentes.
	 *
	 * @return object|null
	 */
	public static function claim_next() {
		global $wpdb;
		$table = self::table_name();
		$now   = time();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'retry', next_attempt_at = 0, updated_at = %d, last_error = %s WHERE status = 'uploading' AND updated_at < %d",
				$now,
				'Proceso anterior interrumpido; trabajo recuperado.',
				$now - 2 * HOUR_IN_SECONDS
			)
		);
		$item = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN ('pending','retry') AND next_attempt_at <= %d ORDER BY created_at ASC, id ASC LIMIT 1",
				$now
			)
		);
		if ( ! $item ) {
			return null;
		}
		$updated = $wpdb->update(
			$table,
			array( 'status' => 'uploading', 'updated_at' => $now ),
			array( 'id' => (int) $item->id, 'status' => (string) $item->status ),
			array( '%s', '%d' ),
			array( '%d', '%s' )
		);
		return 1 === (int) $updated ? $item : null;
	}

	public static function mark_synced( $id, $remote_file, $remote_size, $remote_target ) {
		global $wpdb;
		$now = time();
		$wpdb->update(
			self::table_name(),
			array(
				'status'          => 'synced',
				'remote_file'     => (string) $remote_file,
				'remote_size'     => (int) $remote_size,
				'remote_target'   => (string) $remote_target,
				'next_attempt_at' => 0,
				'local_missing_since' => 0,
				'last_error'      => '',
				'updated_at'      => $now,
				'synced_at'       => $now,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%d' ),
			array( '%d' )
		);
	}

	public static function mark_retry( $id, $message ) {
		global $wpdb;
		$table = self::table_name();
		$item  = $wpdb->get_row( $wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d", (int) $id ) );
		$attempts = $item ? (int) $item->attempts + 1 : 1;
		$base     = min( 12 * HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS * ( 2 ** min( 8, $attempts - 1 ) ) );
		$delay    = $base + wp_rand( 0, max( 30, (int) ( $base * 0.2 ) ) );
		$wpdb->update(
			$table,
			array(
				'status'          => 'retry',
				'attempts'        => $attempts,
				'next_attempt_at' => time() + $delay,
				'last_error'      => substr( sanitize_text_field( (string) $message ), 0, 1000 ),
				'updated_at'      => time(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d', '%d', '%s', '%d' ),
			array( '%d' )
		);
		return $delay;
	}

	public static function mark_missing( $id, $message = 'El archivo local ya no existe.' ) {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array( 'status' => 'missing', 'last_error' => substr( sanitize_text_field( $message ), 0, 1000 ), 'updated_at' => time() ),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	public static function mark_pending( $id, $message = '' ) {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'status'                => 'pending',
				'attempts'              => 0,
				'next_attempt_at'       => 0,
				'local_missing_since'   => 0,
				'last_error'            => substr( sanitize_text_field( (string) $message ), 0, 1000 ),
				'updated_at'            => time(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d', '%d', '%d', '%s', '%d' ),
			array( '%d' )
		);
	}

	public static function mark_pruned( $id, $message = 'El conjunto ya no existe en UpdraftPlus y se elimino del almacenamiento remoto.' ) {
		self::mark_reconciled_status( $id, 'pruned', $message );
	}

	public static function mark_orphaned( $id, $message ) {
		self::mark_reconciled_status( $id, 'orphaned', $message );
	}

	private static function mark_reconciled_status( $id, $status, $message ) {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'status'          => (string) $status,
				'next_attempt_at' => 0,
				'last_error'      => substr( sanitize_text_field( (string) $message ), 0, 1000 ),
				'updated_at'      => time(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d', '%s', '%d' ),
			array( '%d' )
		);
	}

	public static function mark_group_missing( $backup_id, $timestamp ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . ' SET local_missing_since = IF(local_missing_since = 0, %d, local_missing_since), updated_at = %d WHERE backup_id = %s AND status IN (\'synced\',\'missing\')',
				(int) $timestamp,
				time(),
				(string) $backup_id
			)
		);
	}

	public static function clear_group_missing( $backup_id ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . ' SET local_missing_since = 0 WHERE backup_id = %s AND status IN (\'synced\',\'missing\') AND local_missing_since > 0',
				(string) $backup_id
			)
		);
	}

	public static function set_reconcile_error( $id, $message ) {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array( 'last_error' => substr( sanitize_text_field( (string) $message ), 0, 1000 ), 'updated_at' => time() ),
			array( 'id' => (int) $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	public static function synced_items() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table_name() . " WHERE status = 'synced' ORDER BY backup_id ASC, id ASC" );
	}

	public static function retention_items() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table_name() . " WHERE status IN ('synced','missing') ORDER BY backup_id ASC, id ASC" );
	}

	public static function uploading_items() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table_name() . " WHERE status = 'uploading' ORDER BY updated_at ASC, id ASC" );
	}

	public static function has_unfinished_items() {
		global $wpdb;
		$count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_name() . " WHERE status IN ('pending','retry','uploading')" );
		return (int) $count > 0;
	}

	public static function release_pending() {
		global $wpdb;
		return (int) $wpdb->query(
			"UPDATE " . self::table_name() . " SET status = 'pending', next_attempt_at = 0, updated_at = " . (int) time() . " WHERE status IN ('pending','retry')"
		);
	}

	public static function has_ready_items() {
		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . self::table_name() . " WHERE status IN ('pending','retry') AND next_attempt_at <= %d",
				time()
			)
		);
		return (int) $count > 0;
	}

	public static function counts() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT status, COUNT(*) AS total FROM ' . self::table_name() . ' GROUP BY status', ARRAY_A );
		$out  = array( 'pending' => 0, 'retry' => 0, 'uploading' => 0, 'synced' => 0, 'missing' => 0, 'pruned' => 0, 'orphaned' => 0 );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $out[ $row['status'] ] ) ) {
				$out[ $row['status'] ] = (int) $row['total'];
			}
		}
		return $out;
	}

	public static function recent( $limit = 20 ) {
		global $wpdb;
		$limit = max( 1, min( 500, (int) $limit ) );
		return $wpdb->get_results( 'SELECT * FROM ' . self::table_name() . ' ORDER BY updated_at DESC, id DESC LIMIT ' . $limit );
	}

	/**
	 * Nombres registrados para el destino actual. Se usa solo para distinguir
	 * los archivos gestionados por Premiero de los que ya estaban en el servidor.
	 *
	 * @param string $target_key Identificador no secreto del destino.
	 * @return array
	 */
	public static function managed_filenames( $target_key ) {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT filename FROM ' . self::table_name() . ' WHERE remote_target = %s',
				(string) $target_key
			)
		);
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $filename ) {
			$out[ basename( (string) $filename ) ] = true;
		}
		return $out;
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'premiero_backup_sync_queue';
	}
}
