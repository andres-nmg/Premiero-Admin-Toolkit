<?php
/**
 * Envio de mensajes a la API de Telegram.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Telegram_Sender {

	const API_BASE = 'https://api.telegram.org/bot';
	const MAX_LENGTH = 4000;

	/**
	 * Envia un mensaje. El titulo y las lineas son texto plano: se truncan antes
	 * de escapar para no cortar nunca una entidad HTML, que Telegram rechazaria.
	 *
	 * @param string   $title Primera linea del mensaje.
	 * @param string[] $lines Lineas siguientes.
	 * @return array{ok:bool,code:int,description:string}
	 */
	public static function send( $title, $lines = array() ) {
		$credentials = Premiero_Telegram::credentials();
		if ( ! Premiero_Telegram::has_valid_credentials() ) {
			return self::result( false, 0, 'Faltan credenciales válidas de Telegram.' );
		}

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		if ( '' === trim( $site ) ) {
			$site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}

		$body = trim( implode( "\n", array_merge( array( (string) $title ), array_map( 'strval', (array) $lines ) ) ) );
		$room = max( 0, self::MAX_LENGTH - mb_strlen( $site, 'UTF-8' ) - 1 );
		$body = mb_substr( $body, 0, $room, 'UTF-8' );

		try {
			$response = wp_remote_post(
				self::API_BASE . $credentials['token'] . '/sendMessage',
				array(
					'timeout'  => 5,
					'blocking' => true,
					'body'     => array(
						'chat_id'                  => $credentials['chat_id'],
						'text'                     => '<b>' . self::escape( $site ) . "</b>\n" . self::escape( $body ),
						'parse_mode'               => 'HTML',
						'disable_web_page_preview' => 'true',
					),
				)
			);
		} catch ( \Throwable $error ) {
			// La traza de una excepcion podria incluir la URL con el token: no se propaga.
			return self::result( false, 0, 'Error inesperado al contactar con Telegram.' );
		}

		if ( is_wp_error( $response ) ) {
			return self::result( false, 0, self::redact( $response->get_error_message(), $credentials['token'] ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$ok   = 200 === $code && is_array( $data ) && ! empty( $data['ok'] );

		return self::result( $ok, $code, self::redact( is_array( $data ) && isset( $data['description'] ) ? (string) $data['description'] : '', $credentials['token'] ) );
	}

	private static function escape( $text ) {
		return htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	private static function redact( $text, $token ) {
		return '' === $token ? $text : str_replace( $token, '[token oculto]', $text );
	}

	private static function result( $ok, $code, $description ) {
		return array(
			'ok'          => (bool) $ok,
			'code'        => (int) $code,
			'description' => (string) $description,
		);
	}
}
