<?php
/**
 * Cliente SFTP de Premiero para el almacenamiento remoto de backups.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use phpseclib3\Net\SFTP;

final class Premiero_SFTP_Client {

	/** @var array */
	private $config;

	/** @var SFTP|null */
	private $connection;

	/**
	 * @param array $config Configuracion SFTP normalizada.
	 */
	public function __construct( $config ) {
		$this->config     = is_array( $config ) ? $config : array();
		$this->connection = null;
	}

	/** @return true|WP_Error */
	public function open() {
		return $this->connect();
	}

	public function close() {
		$this->disconnect();
	}

	/**
	 * @param string $remote Archivo remoto.
	 * @return int|false
	 */
	public function remote_size( $remote ) {
		return $this->connection ? $this->connection->filesize( $remote ) : false;
	}

	public function remote_exists( $remote ) {
		return $this->connection && $this->connection->is_file( $remote );
	}

	/**
	 * Inventario de solo lectura de archivos UpdraftPlus en la ruta configurada.
	 *
	 * @param string $directory Ruta remota.
	 * @return array|WP_Error
	 */
	public function list_backup_files( $directory ) {
		if ( ! $this->connection ) {
			return new WP_Error( 'premiero_sftp_not_connected', 'No hay una conexion SFTP activa.' );
		}
		try {
			$entries = $this->connection->nlist( (string) $directory );
		} catch ( \Throwable $error ) {
			return new WP_Error( 'premiero_sftp_list_failed', self::safe_exception_message( $error ) );
		}
		if ( ! is_array( $entries ) ) {
			return new WP_Error( 'premiero_sftp_list_failed', 'No se pudo leer el contenido de la ruta remota.' );
		}

		$files = array();
		foreach ( $entries as $entry ) {
			$filename = basename( (string) $entry );
			if ( Premiero_Backup_Detector::is_updraft_filename( $filename ) ) {
				$files[ $filename ] = $filename;
			}
		}
		return array_values( $files );
	}

	/**
	 * @param string $local Archivo local.
	 * @param string $remote Archivo remoto temporal.
	 * @param bool   $resume Reanudar desde el tamano remoto.
	 * @return bool
	 */
	public function upload_local_file( $local, $remote, $resume = false ) {
		if ( ! $this->connection ) {
			return false;
		}
		$mode = SFTP::SOURCE_LOCAL_FILE | ( $resume ? SFTP::RESUME : 0 );
		return (bool) $this->connection->put( $remote, $local, $mode );
	}

	public function rename_remote( $from, $to ) {
		return $this->connection && $this->connection->rename( $from, $to );
	}

	/**
	 * Solo se usa para temporales .part creados por este modulo.
	 */
	public function delete_partial( $remote ) {
		if ( '.part' !== substr( (string) $remote, -5 ) || ! $this->connection ) {
			return false;
		}
		return (bool) $this->connection->delete( $remote, false );
	}

	/**
	 * Elimina exclusivamente un archivo de backup dentro de la ruta configurada.
	 * No admite rutas arbitrarias, directorios, enlaces ni archivos temporales.
	 *
	 * @param string $remote Ruta remota exacta.
	 * @return true|WP_Error
	 */
	public function delete_managed_backup_file( $remote ) {
		if ( ! $this->connection ) {
			return new WP_Error( 'premiero_sftp_not_connected', 'No hay una conexion SFTP activa.' );
		}

		$filename  = basename( (string) $remote );
		$directory = isset( $this->config['remote_path'] ) ? (string) $this->config['remote_path'] : '/';
		$expected  = self::join_remote_path( $directory, $filename );
		if (
			(string) $remote !== $expected
			|| ! Premiero_Backup_Detector::is_updraft_filename( $filename )
			|| false !== strpos( $remote, '..' )
		) {
			return new WP_Error( 'premiero_sftp_delete_unsafe', 'Se rechazo una eliminacion remota fuera de la ruta gestionada.' );
		}

		if ( ! $this->connection->is_file( $remote ) ) {
			return true;
		}
		if ( ! $this->connection->delete( $remote, false ) ) {
			return new WP_Error( 'premiero_sftp_delete_failed', 'No se pudo eliminar el archivo de backup remoto.' );
		}
		return true;
	}

	/**
	 * Comprueba alcance TCP y devuelve el banner SSH cuando esta disponible.
	 * No autentica ni transmite credenciales.
	 *
	 * @param string $host Host remoto.
	 * @param int    $port Puerto remoto.
	 * @param int    $timeout Tiempo maximo en segundos.
	 * @return array
	 */
	public static function probe_port( $host, $port, $timeout = 6 ) {
		$started = microtime( true );
		$errno   = 0;
		$errstr  = '';
		$socket  = @stream_socket_client(
			'tcp://' . $host . ':' . (int) $port,
			$errno,
			$errstr,
			max( 1, (int) $timeout ),
			STREAM_CLIENT_CONNECT
		);

		$result = array(
			'port'       => (int) $port,
			'reachable'  => is_resource( $socket ),
			'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'banner'     => '',
			'error'      => '',
		);

		if ( ! is_resource( $socket ) ) {
			$result['error'] = self::safe_socket_error( $errno, $errstr );
			return $result;
		}

		stream_set_timeout( $socket, 2 );
		$banner = fgets( $socket, 256 );
		fclose( $socket );
		if ( is_string( $banner ) && 0 === strpos( $banner, 'SSH-' ) ) {
			$result['banner'] = sanitize_text_field( trim( $banner ) );
		}

		return $result;
	}

	/**
	 * Autentica, crea una sonda remota y verifica su tamano.
	 *
	 * @return array|WP_Error
	 */
	public function test_write_access( $test_bytes = 4096 ) {
		$started = microtime( true );
		$test_bytes = max( 4096, min( 100 * MB_IN_BYTES, (int) $test_bytes ) );
		$connect = $this->connect();
		if ( is_wp_error( $connect ) ) {
			return $connect;
		}

		$directory = isset( $this->config['remote_path'] ) ? (string) $this->config['remote_path'] : '/';
		try {
			$directory_ready = $this->ensure_directory( $directory );
		} catch ( \Throwable $error ) {
			$this->disconnect();
			return new WP_Error( 'premiero_sftp_remote_path', self::safe_exception_message( $error ) );
		}
		if ( ! $directory_ready ) {
			$this->disconnect();
			return new WP_Error( 'premiero_sftp_remote_path', 'No se pudo acceder ni crear la ruta remota configurada.' );
		}

		$source = $this->create_test_source( $test_bytes );
		if ( is_wp_error( $source ) ) {
			$this->disconnect();
			return $source;
		}

		$probe_name = '.premiero-sftp-test-' . wp_generate_uuid4() . '.tmp';
		$remote     = self::join_remote_path( $directory, $probe_name );
		$uploaded   = false;
		$local_file = isset( $source['local_file'] ) ? (string) $source['local_file'] : '';

		try {
			$uploaded = $this->connection->put( $remote, $source['data'], $source['mode'] );
			if ( ! $uploaded ) {
				return new WP_Error( 'premiero_sftp_write_failed', 'La autenticacion funciono, pero no se pudo escribir en la ruta remota.' );
			}

			$remote_size = $this->connection->filesize( $remote );
			if ( false === $remote_size || $test_bytes !== (int) $remote_size ) {
				return new WP_Error( 'premiero_sftp_size_mismatch', 'El archivo de prueba se subio, pero su tamano remoto no coincide con el local.' );
			}

			if ( ! $this->connection->delete( $remote, false ) ) {
				$uploaded = false;
				return new WP_Error( 'premiero_sftp_cleanup_failed', 'La prueba de escritura funciono, pero no se pudo eliminar el archivo temporal remoto.' );
			}
			$uploaded = false;

			return array(
				'host'                => (string) $this->config['host'],
				'port'                => (int) $this->config['port'],
				'remote_path'         => $directory,
				'bytes_verified'      => $test_bytes,
				'duration_ms'         => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'host_key_fingerprint'=> $this->fingerprint(),
				'sftp_version'        => $this->negotiated_version(),
			);
		} catch ( \Throwable $error ) {
			return new WP_Error( 'premiero_sftp_test_exception', self::safe_exception_message( $error ) );
		} finally {
			if ( $uploaded && $this->connection ) {
				try {
					$this->connection->delete( $remote, false );
				} catch ( \Throwable $error ) {
					// La limpieza es de mejor esfuerzo y nunca reintenta una eliminacion fallida.
				}
			}
			$this->disconnect();
			if ( '' !== $local_file && is_file( $local_file ) ) {
				wp_delete_file( $local_file );
			}
		}
	}

	/**
	 * Crea una fuente pequena en memoria o un archivo temporal para pruebas grandes.
	 *
	 * @param int $test_bytes Tamano solicitado.
	 * @return array|WP_Error
	 */
	private function create_test_source( $test_bytes ) {
		if ( $test_bytes <= 4096 ) {
			try {
				$payload = random_bytes( $test_bytes );
			} catch ( \Throwable $error ) {
				$payload = str_repeat( hash( 'sha256', wp_generate_uuid4(), true ), (int) ceil( $test_bytes / 32 ) );
				$payload = substr( $payload, 0, $test_bytes );
			}
			return array(
				'data'       => $payload,
				'mode'       => SFTP::SOURCE_STRING,
				'local_file' => '',
			);
		}

		$local_file = wp_tempnam( 'premiero-sftp-test.bin' );
		if ( ! is_string( $local_file ) || '' === $local_file ) {
			return new WP_Error( 'premiero_sftp_tempfile', 'No se pudo crear el archivo temporal para la prueba de transferencia.' );
		}

		$handle = fopen( $local_file, 'wb' );
		if ( false === $handle ) {
			wp_delete_file( $local_file );
			return new WP_Error( 'premiero_sftp_tempfile', 'No se pudo abrir el archivo temporal para la prueba de transferencia.' );
		}

		try {
			$chunk = random_bytes( min( MB_IN_BYTES, $test_bytes ) );
		} catch ( \Throwable $error ) {
			$seed  = hash( 'sha256', wp_generate_uuid4(), true );
			$chunk = str_repeat( $seed, (int) ceil( min( MB_IN_BYTES, $test_bytes ) / 32 ) );
			$chunk = substr( $chunk, 0, min( MB_IN_BYTES, $test_bytes ) );
		}

		$remaining = $test_bytes;
		$write_ok  = true;
		while ( $remaining > 0 ) {
			$piece   = $remaining >= strlen( $chunk ) ? $chunk : substr( $chunk, 0, $remaining );
			$written = fwrite( $handle, $piece );
			if ( false === $written || 0 === $written ) {
				$write_ok = false;
				break;
			}
			$remaining -= $written;
		}
		fclose( $handle );

		if ( ! $write_ok || ! is_file( $local_file ) || $test_bytes !== (int) filesize( $local_file ) ) {
			wp_delete_file( $local_file );
			return new WP_Error( 'premiero_sftp_tempfile_write', 'No se pudo preparar por completo el archivo temporal de prueba.' );
		}

		return array(
			'data'       => $local_file,
			'mode'       => SFTP::SOURCE_LOCAL_FILE,
			'local_file' => $local_file,
		);
	}

	/**
	 * @return true|WP_Error
	 */
	private function connect() {
		if ( ! class_exists( 'phpseclib3\\Net\\SFTP' ) ) {
			return new WP_Error( 'premiero_sftp_dependency_missing', 'phpseclib no esta disponible en esta instalacion del plugin.' );
		}

		$host     = isset( $this->config['host'] ) ? (string) $this->config['host'] : '';
		$port     = isset( $this->config['port'] ) ? (int) $this->config['port'] : 22;
		$username = isset( $this->config['username'] ) ? (string) $this->config['username'] : '';
		$password = isset( $this->config['password'] ) ? (string) $this->config['password'] : '';

		try {
			$this->connection = new SFTP( $host, $port, 10 );
			$this->connection->setTimeout( 15 );
			$this->connection->setKeepAlive( 10 );

			$server_key = $this->connection->getServerPublicHostKey();
			if ( ! is_string( $server_key ) || '' === $server_key ) {
				$this->disconnect();
				return new WP_Error( 'premiero_sftp_host_key', 'El servidor no presento una clave SSH valida.' );
			}

			$fingerprint = self::host_key_fingerprint( $server_key );
			$this->config['detected_fingerprint'] = $fingerprint;
			$expected = isset( $this->config['expected_fingerprint'] )
				? trim( (string) $this->config['expected_fingerprint'] )
				: '';
			if ( '' !== $expected && ! hash_equals( $expected, $fingerprint ) ) {
				$this->disconnect();
				return new WP_Error( 'premiero_sftp_host_key_changed', 'La clave SSH del servidor no coincide con la guardada. No se enviaron credenciales.' );
			}

			if ( ! $this->connection->login( $username, $password ) ) {
				$this->disconnect();
				return new WP_Error( 'premiero_sftp_login_failed', 'El servidor respondio, pero rechazo el usuario o la contrasena.' );
			}
		} catch ( \Throwable $error ) {
			$this->disconnect();
			return new WP_Error( 'premiero_sftp_connect_failed', self::safe_exception_message( $error ) );
		}

		return true;
	}

	/**
	 * @param string $directory Ruta remota.
	 * @return bool
	 */
	public function ensure_directory( $directory ) {
		if ( '/' === $directory || '.' === $directory || '' === $directory ) {
			return true;
		}
		if ( $this->connection->is_dir( $directory ) ) {
			return true;
		}
		return (bool) $this->connection->mkdir( $directory, -1, true );
	}

	/**
	 * @return string
	 */
	private function fingerprint() {
		return isset( $this->config['detected_fingerprint'] )
			? (string) $this->config['detected_fingerprint']
			: '';
	}

	/**
	 * @return int|string
	 */
	private function negotiated_version() {
		if ( ! $this->connection || ! method_exists( $this->connection, 'getNegotiatedVersion' ) ) {
			return '';
		}
		return $this->connection->getNegotiatedVersion();
	}

	private function disconnect() {
		if ( $this->connection && method_exists( $this->connection, 'disconnect' ) ) {
			try {
				$this->connection->disconnect();
			} catch ( \Throwable $error ) {
				// El socket se liberara al destruir el objeto.
			}
		}
		$this->connection = null;
	}

	/**
	 * @param string $directory Directorio remoto.
	 * @param string $filename Nombre base.
	 * @return string
	 */
	private static function join_remote_path( $directory, $filename ) {
		return ( '/' === $directory ? '/' : rtrim( $directory, '/' ) . '/' ) . ltrim( $filename, '/' );
	}

	/**
	 * @param string $server_key Clave publica en formato SSH.
	 * @return string
	 */
	private static function host_key_fingerprint( $server_key ) {
		$parts = preg_split( '/\s+/', trim( (string) $server_key ) );
		$raw   = isset( $parts[1] ) ? base64_decode( $parts[1], true ) : false;
		if ( false === $raw ) {
			$raw = (string) $server_key;
		}
		return 'SHA256:' . rtrim( base64_encode( hash( 'sha256', $raw, true ) ), '=' );
	}

	/**
	 * Evita mostrar rutas internas o trazas de phpseclib en el administrador.
	 *
	 * @param Throwable $error Excepcion capturada.
	 * @return string
	 */
	private static function safe_exception_message( $error ) {
		$message = sanitize_text_field( (string) $error->getMessage() );
		if ( '' === $message ) {
			return 'No se pudo completar la conexion SFTP.';
		}
		return substr( $message, 0, 300 );
	}

	/**
	 * @param int    $errno Codigo de socket.
	 * @param string $errstr Mensaje de socket.
	 * @return string
	 */
	private static function safe_socket_error( $errno, $errstr ) {
		$message = sanitize_text_field( (string) $errstr );
		return '' !== $message
			? 'TCP ' . (int) $errno . ': ' . substr( $message, 0, 180 )
			: 'No se pudo abrir la conexion TCP.';
	}
}
