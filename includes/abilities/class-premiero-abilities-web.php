<?php
/**
 * Abilities de solo lectura sobre HTTP y HTML servido:
 * premiero/http-check, premiero/verify-page y premiero/seo-head.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Abilities_Web {

	const TIMEOUT       = 15;
	const MAX_REDIRECTS = 5;

	/*
	 * Comentarios que delimitan la salida de cada plugin SEO. Los de Rank Math
	 * están traducidos, así que se reconocen por la URL y por «Rank Math».
	 */
	const YOAST_START     = '/<!-- This site is optimized with the Yoast SEO/';
	const YOAST_END       = '/<!-- \\/ Yoast SEO/';
	const RANK_MATH_START = '/<!--[^>]*rankmath\\.com[^>]*-->/i';
	const RANK_MATH_END   = '/<!-- \\/[^>]*Rank Math[^>]*-->/i';

	/** IP fijada para la petición en curso (anti DNS rebinding). */
	private static $pin = null;

	public static function register() {
		$nullable = array( 'type' => array( 'string', 'null' ) );
		$sourced  = array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'value'  => array( 'type' => 'string' ),
				'source' => array( 'type' => 'string' ),
			),
		);

		Premiero_Abilities::add( 'http-check', array(
			'label'       => 'Comprobar una URL por HTTP',
			'description' => 'Pide una URL http/https (GET o HEAD) y devuelve el código de estado, las cabeceras, la cadena de redirecciones (hasta 5 saltos, con el código y el Location de cada uno), el tiempo de respuesta y, en HTTPS, la caducidad y el emisor del certificado. Bloquea IPs privadas, loopback y link-local.',
			'annotations' => Premiero_Abilities::readonly(),
			'execute'     => array( __CLASS__, 'http_check' ),
			'input'       => Premiero_Abilities::obj( array(
				'url'    => array( 'type' => 'string', 'format' => 'uri', 'description' => 'URL absoluta http o https.' ),
				'method' => array( 'type' => 'string', 'enum' => array( 'GET', 'HEAD' ), 'default' => 'GET' ),
			), array( 'url' ), true ),
			'output'      => Premiero_Abilities::obj( array(
				'url'                => array( 'type' => 'string' ),
				'final_url'          => array( 'type' => 'string' ),
				'method'             => array( 'type' => 'string' ),
				'status_code'        => array( 'type' => array( 'integer', 'null' ) ),
				'headers'            => array( 'type' => 'object' ),
				'redirects'          => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'too_many_redirects' => array( 'type' => 'boolean' ),
				'total_time_ms'      => array( 'type' => 'integer' ),
				'error'              => $nullable,
				'ssl'                => array( 'type' => array( 'object', 'null' ) ),
			) ),
		) );

		Premiero_Abilities::add( 'verify-page', array(
			'label'       => 'Verificar una página del sitio',
			'description' => 'Descarga el HTML servido de una URL de esta web y devuelve el código HTTP, title, meta description, canonical, meta robots, X-Robots-Tag, hreflang y H1, y si aparece cada texto esperado. Solo acepta URLs de este dominio (o rutas como /contacto/).',
			'annotations' => Premiero_Abilities::readonly(),
			'execute'     => array( __CLASS__, 'verify_page' ),
			'input'       => Premiero_Abilities::obj( array(
				'url'      => array( 'type' => 'string', 'minLength' => 1, 'description' => 'URL de este sitio o ruta que empiece por /.' ),
				'expected' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'minLength' => 1 ), 'maxItems' => 50, 'description' => 'Textos que deberían aparecer en la página.' ),
			), array( 'url' ), true ),
			'output'      => Premiero_Abilities::obj( array(
				'url'              => array( 'type' => 'string' ),
				'status_code'      => array( 'type' => 'integer' ),
				'title'            => $nullable,
				'meta_description' => $nullable,
				'canonical'        => $nullable,
				'meta_robots'      => $nullable,
				'x_robots_tag'     => $nullable,
				'hreflang'         => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'h1'               => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'expected'         => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
			) ),
		) );

		Premiero_Abilities::add( 'seo-head', array(
			'label'       => 'Ver etiquetas SEO del <head>',
			'description' => 'Para un post_id o una URL de esta web, devuelve las etiquetas SEO del <head> realmente servido (title, description, robots, canonical, Open Graph y Twitter) y los bloques JSON-LD parseados. Indica si está activo Rank Math o Yoast y qué plugin genera cada dato.',
			'annotations' => Premiero_Abilities::readonly(),
			'execute'     => array( __CLASS__, 'seo_head' ),
			'input'       => Premiero_Abilities::obj( array(
				'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'url'     => array( 'type' => 'string', 'minLength' => 1, 'description' => 'URL de este sitio o ruta que empiece por /.' ),
			), array(), true ),
			'output'      => Premiero_Abilities::obj( array(
				'url'         => array( 'type' => 'string' ),
				'status_code' => array( 'type' => 'integer' ),
				'seo_plugins' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'message'     => array( 'type' => 'string' ),
				'title'       => $sourced,
				'description' => $sourced,
				'robots'      => $sourced,
				'canonical'   => $sourced,
				'open_graph'  => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'twitter'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'json_ld'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
			) ),
		) );
	}

	/* =========================================================
	 *  http-check
	 * ========================================================= */

	public static function http_check( $input ) {
		$url    = trim( (string) $input['url'] );
		$method = isset( $input['method'] ) && 'HEAD' === $input['method'] ? 'HEAD' : 'GET';

		$result = array(
			'url'                => $url,
			'final_url'          => $url,
			'method'             => $method,
			'status_code'        => null,
			'headers'            => array(),
			'redirects'          => array(),
			'too_many_redirects' => false,
			'total_time_ms'      => 0,
			'error'              => null,
			'ssl'                => null,
		);

		$current = $url;
		$started = microtime( true );
		$target  = null;

		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$target = self::resolve_public_target( $current );
			if ( is_wp_error( $target ) ) {
				if ( 0 === $hop ) {
					return $target;
				}
				$result['error'] = 'Redirección bloqueada: ' . $target->get_error_message();
				break;
			}

			$t0       = microtime( true );
			$response = self::pinned_request( $current, $target, $method );
			$ms       = (int) round( ( microtime( true ) - $t0 ) * 1000 );

			$result['final_url'] = $current;
			if ( is_wp_error( $response ) ) {
				$result['error'] = $response->get_error_message();
				break;
			}

			$code     = (int) wp_remote_retrieve_response_code( $response );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );

			$result['status_code'] = $code;
			$result['headers']     = self::headers_to_array( wp_remote_retrieve_headers( $response ) );

			if ( $code >= 300 && $code < 400 && '' !== $location ) {
				$next                  = WP_Http::make_absolute_url( $location, $current );
				$result['redirects'][] = array(
					'url'         => $current,
					'status_code' => $code,
					'location'    => $location,
					'time_ms'     => $ms,
				);
				if ( self::MAX_REDIRECTS === $hop ) {
					$result['too_many_redirects'] = true;
					break;
				}
				$current = $next;
				continue;
			}
			break;
		}

		$result['total_time_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( ! is_wp_error( $target ) && 'https' === $target['scheme'] ) {
			$result['ssl'] = self::ssl_info( $target );
		}

		return $result;
	}

	/**
	 * Valida esquema y resuelve el host; rechaza cualquier IP no pública.
	 *
	 * @return array|WP_Error {scheme, host, port, ip}
	 */
	private static function resolve_public_target( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'premiero_http_url', 'URL no válida: ' . $url );
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'premiero_http_scheme', 'Solo se admiten URLs http y https.' );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'premiero_http_userinfo', 'No se admiten credenciales en la URL.' );
		}

		$host = strtolower( trim( $parts['host'], '[]' ) );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips = array( $host );
		} else {
			$ips = gethostbynamel( $host );
			$ips = $ips ? $ips : array();
			if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
				$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				foreach ( (array) $records as $record ) {
					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}

		if ( ! $ips ) {
			return new WP_Error( 'premiero_http_dns', 'No se pudo resolver el host ' . $host . '.' );
		}
		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return new WP_Error( 'premiero_http_ssrf', 'El host ' . $host . ' resuelve a una IP privada, loopback, link-local o reservada (' . $ip . ').' );
			}
		}

		return array(
			'scheme' => $scheme,
			'host'   => $host,
			'port'   => $port,
			'ip'     => $ips[0],
		);
	}

	private static function is_public_ip( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		// IPv4 mapeada en IPv6 (::ffff:127.0.0.1).
		if ( preg_match( '/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m ) ) {
			$ip = $m[1];
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = ip2long( $ip );
			$deny = array(
				array( '0.0.0.0', 8 ),
				array( '10.0.0.0', 8 ),
				array( '100.64.0.0', 10 ),
				array( '127.0.0.0', 8 ),
				array( '169.254.0.0', 16 ),
				array( '172.16.0.0', 12 ),
				array( '192.0.0.0', 24 ),
				array( '192.168.0.0', 16 ),
				array( '198.18.0.0', 15 ),
				array( '224.0.0.0', 4 ),
				array( '240.0.0.0', 4 ),
			);
			foreach ( $deny as $range ) {
				$mask = -1 << ( 32 - $range[1] );
				if ( ( $long & $mask ) === ( ip2long( $range[0] ) & $mask ) ) {
					return false;
				}
			}
			return true;
		}
		$lower = strtolower( $ip );
		return ! ( '::' === $lower || '::1' === $lower
			|| preg_match( '/^(fe[89ab]|f[cd]|ff)/', $lower ) );
	}

	/** Petición sin seguir redirecciones, fijando la IP ya validada cuando el transporte es cURL. */
	private static function pinned_request( $url, $target, $method ) {
		self::$pin = $target;
		add_action( 'http_api_curl', array( __CLASS__, 'pin_curl' ), 10, 3 );
		$response = wp_remote_request( $url, array(
			'method'             => $method,
			'timeout'            => self::TIMEOUT,
			'redirection'        => 0,
			'sslverify'          => true,
			'reject_unsafe_urls' => false,
			'user-agent'         => 'Premiero-Admin-Toolkit/' . PREMIERO_ATK_VER . '; ' . home_url( '/' ),
			'limit_response_size' => 2 * MB_IN_BYTES,
		) );
		remove_action( 'http_api_curl', array( __CLASS__, 'pin_curl' ), 10 );
		self::$pin = null;
		return $response;
	}

	public static function pin_curl( $handle, $args, $url ) {
		if ( ! self::$pin || ! defined( 'CURLOPT_RESOLVE' ) ) {
			return;
		}
		$ip = self::$pin['ip'];
		if ( false !== strpos( $ip, ':' ) ) {
			$ip = '[' . $ip . ']';
		}
		curl_setopt( $handle, CURLOPT_RESOLVE, array( self::$pin['host'] . ':' . self::$pin['port'] . ':' . $ip ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	private static function headers_to_array( $headers ) {
		$out = array();
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}
		foreach ( (array) $headers as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return $out;
	}

	/** Lee el certificado conectando a la IP validada con SNI del host. */
	private static function ssl_info( $target ) {
		if ( ! function_exists( 'openssl_x509_parse' ) || ! function_exists( 'stream_socket_client' ) ) {
			return array( 'error' => 'La extensión OpenSSL no está disponible.' );
		}
		$ip      = false !== strpos( $target['ip'], ':' ) ? '[' . $target['ip'] . ']' : $target['ip'];
		$context = stream_context_create( array(
			'ssl' => array(
				'capture_peer_cert' => true,
				'verify_peer'       => false,
				'verify_peer_name'  => false,
				'peer_name'         => $target['host'],
				'SNI_enabled'       => true,
			),
		) );
		$errno  = 0;
		$errstr = '';
		$socket = @stream_socket_client( 'ssl://' . $ip . ':' . $target['port'], $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $socket ) {
			return array( 'error' => 'No se pudo leer el certificado: ' . ( $errstr ? $errstr : 'conexión fallida' ) );
		}
		$params = stream_context_get_params( $socket );
		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( empty( $params['options']['ssl']['peer_certificate'] ) ) {
			return array( 'error' => 'El servidor no devolvió certificado.' );
		}
		$cert = openssl_x509_parse( $params['options']['ssl']['peer_certificate'] );
		if ( ! $cert ) {
			return array( 'error' => 'No se pudo interpretar el certificado.' );
		}

		$valid_to = isset( $cert['validTo_time_t'] ) ? (int) $cert['validTo_time_t'] : 0;
		$issuer   = isset( $cert['issuer'] ) ? (array) $cert['issuer'] : array();
		$subject  = isset( $cert['subject'] ) ? (array) $cert['subject'] : array();
		$san      = isset( $cert['extensions']['subjectAltName'] ) ? (string) $cert['extensions']['subjectAltName'] : '';

		return array(
			'host'           => $target['host'],
			'subject'        => isset( $subject['CN'] ) ? (string) $subject['CN'] : '',
			'issuer'         => trim( ( isset( $issuer['O'] ) ? $issuer['O'] : '' ) . ( isset( $issuer['CN'] ) ? ' (' . $issuer['CN'] . ')' : '' ) ),
			'valid_from'     => isset( $cert['validFrom_time_t'] ) ? gmdate( 'c', (int) $cert['validFrom_time_t'] ) : null,
			'valid_to'       => $valid_to ? gmdate( 'c', $valid_to ) : null,
			'days_remaining' => $valid_to ? (int) floor( ( $valid_to - time() ) / DAY_IN_SECONDS ) : null,
			'expired'        => $valid_to ? $valid_to < time() : null,
			'subject_alt_names' => $san,
		);
	}

	/* =========================================================
	 *  Descarga de páginas del propio sitio
	 * ========================================================= */

	/**
	 * Normaliza una URL o ruta y comprueba que pertenece a este sitio.
	 *
	 * @return string|WP_Error URL absoluta.
	 */
	public static function own_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return new WP_Error( 'premiero_url_empty', 'Falta la URL.' );
		}
		if ( '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
			return home_url( $url );
		}
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'premiero_url_invalid', 'URL no válida: ' . $url );
		}
		$allowed = array_unique( array_filter( array(
			strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
		) ) );
		if ( ! in_array( strtolower( $parts['host'] ), $allowed, true ) ) {
			return new WP_Error( 'premiero_url_foreign', 'Solo se admiten URLs de este sitio (' . implode( ', ', $allowed ) . ').' );
		}
		return $url;
	}

	/**
	 * Descarga una página del propio sitio siguiendo sus redirecciones.
	 *
	 * @return array|WP_Error {url, status_code, headers, html}
	 */
	public static function fetch_own_page( $url ) {
		$url = self::own_url( $url );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$response = wp_remote_get( $url, array(
			'timeout'             => self::TIMEOUT,
			'redirection'         => self::MAX_REDIRECTS,
			'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
			'reject_unsafe_urls'  => false,
			'user-agent'          => 'Premiero-Admin-Toolkit/' . PREMIERO_ATK_VER . '; ' . home_url( '/' ),
			'limit_response_size' => 5 * MB_IN_BYTES,
			'headers'             => array( 'Cache-Control' => 'no-cache' ),
		) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'premiero_fetch_failed', 'No se pudo descargar ' . $url . ': ' . $response->get_error_message() );
		}
		return array(
			'url'         => $url,
			'status_code' => (int) wp_remote_retrieve_response_code( $response ),
			'headers'     => self::headers_to_array( wp_remote_retrieve_headers( $response ) ),
			'html'        => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/* =========================================================
	 *  Análisis de HTML (sin dependencias)
	 * ========================================================= */

	private static function head_end( $html ) {
		$pos = stripos( $html, '</head>' );
		return false === $pos ? strlen( $html ) : $pos;
	}

	private static function parse_attrs( $raw ) {
		$attrs = array();
		preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*(?:=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', $raw, $m, PREG_SET_ORDER );
		foreach ( $m as $a ) {
			$value = '';
			if ( isset( $a[2] ) && '' !== $a[2] ) {
				$value = $a[2];
			} elseif ( isset( $a[3] ) && '' !== $a[3] ) {
				$value = $a[3];
			} elseif ( isset( $a[4] ) ) {
				$value = $a[4];
			}
			$attrs[ strtolower( $a[1] ) ] = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $attrs;
	}

	/** <meta> y <link> del head con su posición. */
	private static function head_tags( $html ) {
		$head = substr( $html, 0, self::head_end( $html ) );
		$tags = array();
		preg_match_all( '/<(meta|link)\b([^>]*)>/i', $head, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		foreach ( $m as $tag ) {
			$tags[] = array(
				'tag'    => strtolower( $tag[1][0] ),
				'attrs'  => self::parse_attrs( $tag[2][0] ),
				'offset' => $tag[0][1],
			);
		}
		return $tags;
	}

	private static function clean_text( $html ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	private static function title_tag( $html ) {
		$head = substr( $html, 0, self::head_end( $html ) );
		if ( preg_match( '/<title\b[^>]*>(.*?)<\/title>/is', $head, $m, PREG_OFFSET_CAPTURE ) ) {
			return array( 'value' => self::clean_text( $m[1][0] ), 'offset' => $m[0][1] );
		}
		return null;
	}

	private static function find_meta( $tags, $attr, $name ) {
		foreach ( $tags as $tag ) {
			if ( 'meta' === $tag['tag'] && isset( $tag['attrs'][ $attr ] ) && strtolower( $tag['attrs'][ $attr ] ) === $name ) {
				return $tag;
			}
		}
		return null;
	}

	private static function find_link( $tags, $rel ) {
		foreach ( $tags as $tag ) {
			if ( 'link' === $tag['tag'] && isset( $tag['attrs']['rel'] ) && in_array( $rel, preg_split( '/\s+/', strtolower( $tag['attrs']['rel'] ) ), true ) ) {
				return $tag;
			}
		}
		return null;
	}

	/* =========================================================
	 *  verify-page
	 * ========================================================= */

	public static function verify_page( $input ) {
		$page = self::fetch_own_page( $input['url'] );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$html  = $page['html'];
		$tags  = self::head_tags( $html );
		$title = self::title_tag( $html );
		$desc  = self::find_meta( $tags, 'name', 'description' );
		$robots = self::find_meta( $tags, 'name', 'robots' );
		$canon = self::find_link( $tags, 'canonical' );

		$hreflang = array();
		foreach ( $tags as $tag ) {
			if ( 'link' === $tag['tag'] && isset( $tag['attrs']['hreflang'] ) ) {
				$hreflang[] = array(
					'hreflang' => $tag['attrs']['hreflang'],
					'href'     => isset( $tag['attrs']['href'] ) ? $tag['attrs']['href'] : '',
				);
			}
		}

		$h1 = array();
		if ( preg_match_all( '/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $m ) ) {
			foreach ( $m[1] as $inner ) {
				$h1[] = self::clean_text( $inner );
			}
		}

		$visible  = self::clean_text( $html );
		$expected = array();
		foreach ( isset( $input['expected'] ) ? (array) $input['expected'] : array() as $text ) {
			$text       = (string) $text;
			$in_text    = false !== mb_stripos( $visible, $text, 0, 'UTF-8' );
			$in_html    = $in_text || false !== stripos( $html, $text );
			$expected[] = array(
				'text'     => $text,
				'found'    => $in_html,
				'found_in' => $in_text ? 'texto visible' : ( $in_html ? 'código HTML' : 'no encontrado' ),
			);
		}

		return array(
			'url'              => $page['url'],
			'status_code'      => $page['status_code'],
			'title'            => $title ? $title['value'] : null,
			'meta_description' => $desc && isset( $desc['attrs']['content'] ) ? $desc['attrs']['content'] : null,
			'canonical'        => $canon && isset( $canon['attrs']['href'] ) ? $canon['attrs']['href'] : null,
			'meta_robots'      => $robots && isset( $robots['attrs']['content'] ) ? $robots['attrs']['content'] : null,
			'x_robots_tag'     => isset( $page['headers']['x-robots-tag'] ) ? $page['headers']['x-robots-tag'] : null,
			'hreflang'         => $hreflang,
			'h1'               => $h1,
			'expected'         => $expected,
		);
	}

	/* =========================================================
	 *  seo-head
	 * ========================================================= */

	/** Plugins SEO activos: 'rank-math', 'yoast'. */
	public static function active_seo_plugins() {
		$active = array();
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$active[] = 'rank-math';
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			$active[] = 'yoast';
		}
		return $active;
	}

	/** Rangos [inicio, fin] del bloque de salida de cada plugin SEO en el HTML. */
	private static function plugin_ranges( $html ) {
		$ranges = array();
		$pairs  = array(
			'yoast'     => array( self::YOAST_START, self::YOAST_END ),
			'rank-math' => array( self::RANK_MATH_START, self::RANK_MATH_END ),
		);
		foreach ( $pairs as $plugin => $pair ) {
			if ( ! preg_match( $pair[0], $html, $start, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			$start = $start[0][1];
			$end   = preg_match( $pair[1], $html, $m, PREG_OFFSET_CAPTURE, $start ) ? $m[0][1] : self::head_end( $html );
			$ranges[ $plugin ] = array( $start, $end );
		}
		return $ranges;
	}

	private static function source_for( $offset, $ranges ) {
		foreach ( $ranges as $plugin => $range ) {
			if ( $offset >= $range[0] && $offset <= $range[1] ) {
				return $plugin;
			}
		}
		return 'otro (tema, núcleo u otro plugin)';
	}

	public static function seo_head( $input ) {
		if ( ! empty( $input['post_id'] ) ) {
			$post = get_post( (int) $input['post_id'] );
			if ( ! $post ) {
				return new WP_Error( 'premiero_seo_post', 'No existe el post ' . (int) $input['post_id'] . '.' );
			}
			if ( 'publish' !== $post->post_status ) {
				return new WP_Error( 'premiero_seo_unpublished', 'El post ' . $post->ID . ' no está publicado (' . $post->post_status . '); su <head> no se sirve públicamente.' );
			}
			$url = get_permalink( $post );
		} elseif ( ! empty( $input['url'] ) ) {
			$url = $input['url'];
		} else {
			return new WP_Error( 'premiero_seo_input', 'Indica post_id o url.' );
		}

		$page = self::fetch_own_page( $url );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$html    = $page['html'];
		$tags    = self::head_tags( $html );
		$ranges  = self::plugin_ranges( $html );
		$plugins = self::active_seo_plugins();

		$sourced = function ( $tag, $key ) use ( $ranges ) {
			if ( ! $tag || ! isset( $tag['attrs'][ $key ] ) ) {
				return null;
			}
			return array( 'value' => $tag['attrs'][ $key ], 'source' => self::source_for( $tag['offset'], $ranges ) );
		};

		$title = self::title_tag( $html );
		if ( $title ) {
			$title_source = self::source_for( $title['offset'], $ranges );
			// Rank Math y Yoast pueden generar el <title> mediante el filtro del núcleo, fuera de su bloque.
			if ( ! isset( $ranges[ $title_source ] ) && $plugins ) {
				$title_source = 'núcleo (title-tag), probablemente filtrado por ' . implode( ' / ', $plugins );
			}
			$title = array( 'value' => $title['value'], 'source' => $title_source );
		}

		// Rank Math y Yoast generan el meta robots mediante el filtro wp_robots del núcleo.
		$robots = $sourced( self::find_meta( $tags, 'name', 'robots' ), 'content' );
		if ( $robots && ! isset( $ranges[ $robots['source'] ] ) && $plugins ) {
			$robots['source'] = 'núcleo (wp_robots), probablemente filtrado por ' . implode( ' / ', $plugins );
		}

		$og      = array();
		$twitter = array();
		foreach ( $tags as $tag ) {
			if ( 'meta' !== $tag['tag'] || ! isset( $tag['attrs']['content'] ) ) {
				continue;
			}
			$property = isset( $tag['attrs']['property'] ) ? strtolower( $tag['attrs']['property'] ) : '';
			$name     = isset( $tag['attrs']['name'] ) ? strtolower( $tag['attrs']['name'] ) : '';
			if ( 0 === strpos( $property, 'og:' ) || 0 === strpos( $property, 'article:' ) ) {
				$og[] = array( 'property' => $property, 'content' => $tag['attrs']['content'], 'source' => self::source_for( $tag['offset'], $ranges ) );
			} elseif ( 0 === strpos( $name, 'twitter:' ) || 0 === strpos( $property, 'twitter:' ) ) {
				$twitter[] = array( 'name' => $name ? $name : $property, 'content' => $tag['attrs']['content'], 'source' => self::source_for( $tag['offset'], $ranges ) );
			}
		}

		$json_ld = array();
		if ( preg_match_all( '/<script\b([^>]*)>(.*?)<\/script>/is', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $script ) {
				$attrs = self::parse_attrs( $script[1][0] );
				if ( ! isset( $attrs['type'] ) || false === stripos( $attrs['type'], 'ld+json' ) ) {
					continue;
				}
				$class  = isset( $attrs['class'] ) ? $attrs['class'] : '';
				$source = false !== strpos( $class, 'yoast-schema-graph' ) ? 'yoast'
					: ( false !== strpos( $class, 'rank-math-schema' ) ? 'rank-math' : self::source_for( $script[0][1], $ranges ) );
				$data   = json_decode( trim( $script[2][0] ), true );
				$entry  = array( 'source' => $source, 'in_head' => $script[0][1] < self::head_end( $html ) );
				if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
					$entry['error'] = 'JSON no válido: ' . json_last_error_msg();
					$entry['raw']   = mb_substr( trim( $script[2][0] ), 0, 2000, 'UTF-8' );
				} else {
					$entry['data'] = $data;
				}
				$json_ld[] = $entry;
			}
		}

		if ( ! $plugins ) {
			$message = 'No hay ningún plugin SEO compatible activo (Rank Math o Yoast). Las etiquetas encontradas proceden del tema, del núcleo o de otros plugins.';
		} else {
			$message = 'Plugin SEO activo: ' . implode( ', ', $plugins ) . '.';
			foreach ( $plugins as $plugin ) {
				if ( isset( $ranges[ $plugin ] ) ) {
					continue;
				}
				if ( 'rank-math' === $plugin && class_exists( 'RankMath\Helper' ) && method_exists( 'RankMath\Helper', 'is_invalid_registration' ) && \RankMath\Helper::is_invalid_registration() ) {
					$message .= ' Rank Math no genera salida porque su asistente de configuración no está completado (falta conectar la cuenta u omitir el registro).';
				} else {
					$message .= ' No se encontró el bloque de salida de ' . $plugin . ' en el HTML (¿caché, marca blanca o salida desactivada?).';
				}
			}
		}

		return array(
			'url'         => $page['url'],
			'status_code' => $page['status_code'],
			'seo_plugins' => $plugins,
			'message'     => $message,
			'title'       => $title,
			'description' => $sourced( self::find_meta( $tags, 'name', 'description' ), 'content' ),
			'robots'      => $robots,
			'canonical'   => $sourced( self::find_link( $tags, 'canonical' ), 'href' ),
			'open_graph'  => $og,
			'twitter'     => $twitter,
			'json_ld'     => $json_ld,
		);
	}
}
