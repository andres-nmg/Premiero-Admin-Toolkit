<?php
/**
 * Ability de escritura SEO: premiero/seo-update (Rank Math o Yoast).
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Abilities_Seo {

	/** Campo de la ability => clave de meta de cada plugin. */
	private static function keys( $plugin ) {
		if ( 'yoast' === $plugin ) {
			return array(
				'title'          => '_yoast_wpseo_title',
				'description'    => '_yoast_wpseo_metadesc',
				'canonical'      => '_yoast_wpseo_canonical',
				'noindex'        => '_yoast_wpseo_meta-robots-noindex',
				'nofollow'       => '_yoast_wpseo_meta-robots-nofollow',
				'og_title'       => '_yoast_wpseo_opengraph-title',
				'og_description' => '_yoast_wpseo_opengraph-description',
				'og_image'       => '_yoast_wpseo_opengraph-image',
				'og_image_id'    => '_yoast_wpseo_opengraph-image-id',
			);
		}
		return array(
			'title'          => 'rank_math_title',
			'description'    => 'rank_math_description',
			'canonical'      => 'rank_math_canonical_url',
			'robots'         => 'rank_math_robots',
			'og_title'       => 'rank_math_facebook_title',
			'og_description' => 'rank_math_facebook_description',
			'og_image'       => 'rank_math_facebook_image',
			'og_image_id'    => 'rank_math_facebook_image_id',
		);
	}

	public static function register() {
		$values = array( 'type' => 'object', 'description' => 'Campo => valor guardado ("" si está vacío).' );

		Premiero_Abilities::add( 'seo-update', array(
			'label'       => 'Actualizar el SEO de un post',
			'description' => 'Escribe el SEO de un post con Rank Math o Yoast (el que esté activo): title, description, canonical, robots (noindex/nofollow), og_title, og_description y og_image (ID de adjunto o URL; Rank Math solo publica imágenes de la biblioteca de medios). Solo cambia los campos enviados; un texto vacío borra el valor y vuelve al predeterminado. Devuelve los valores antes y después y limpia la caché SEO del post. Da error si no hay Rank Math ni Yoast.',
			'annotations' => Premiero_Abilities::writes( true, true ),
			'execute'     => array( __CLASS__, 'execute' ),
			'input'       => Premiero_Abilities::obj( array(
				'post_id'        => array( 'type' => 'integer', 'minimum' => 1 ),
				'title'          => array( 'type' => 'string', 'maxLength' => 300 ),
				'description'    => array( 'type' => 'string', 'maxLength' => 1000 ),
				'canonical'      => array( 'type' => 'string', 'description' => 'URL absoluta o "" para quitarla.' ),
				'robots'         => Premiero_Abilities::obj( array(
					'noindex'  => array( 'type' => 'boolean' ),
					'nofollow' => array( 'type' => 'boolean' ),
				), array(), true ),
				'og_title'       => array( 'type' => 'string', 'maxLength' => 300 ),
				'og_description' => array( 'type' => 'string', 'maxLength' => 1000 ),
				'og_image'       => array( 'type' => 'string', 'description' => 'URL de la imagen, ID de un adjunto o "" para quitarla.' ),
			), array( 'post_id' ), true ),
			'output'      => Premiero_Abilities::obj( array(
				'post_id'       => array( 'type' => 'integer' ),
				'plugins'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'before'        => $values,
				'after'         => $values,
				'cache_cleared' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'warnings'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			) ),
		) );
	}

	public static function execute( $input ) {
		$plugins = Premiero_Abilities_Web::active_seo_plugins();
		if ( ! $plugins ) {
			return new WP_Error( 'premiero_seo_no_plugin', 'No hay ningún plugin SEO compatible activo. Instala y activa Rank Math o Yoast SEO para poder escribir el SEO del post.' );
		}

		$post_id = (int) $input['post_id'];
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'premiero_seo_post', 'No existe el post ' . $post_id . '.' );
		}

		$changes = self::sanitize_changes( $input );
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}
		if ( ! $changes ) {
			return new WP_Error( 'premiero_seo_nothing', 'No se ha indicado ningún campo que cambiar.' );
		}

		$before = array();
		$after  = array();
		$caches = array();
		foreach ( $plugins as $plugin ) {
			$before[ $plugin ] = self::read( $plugin, $post_id );
			self::write( $plugin, $post_id, $changes );
		}

		clean_post_cache( $post_id );
		wp_cache_delete( $post_id, 'post_meta' );
		$caches[] = 'post';

		foreach ( $plugins as $plugin ) {
			$cleared = 'yoast' === $plugin ? self::refresh_yoast( $post_id ) : self::refresh_rank_math( $post_id );
			if ( $cleared ) {
				$caches[] = $cleared;
			}
			$after[ $plugin ] = self::read( $plugin, $post_id );
		}

		$warnings = array();
		if ( in_array( 'rank-math', $plugins, true ) && ! empty( $changes['og_image'] ) && empty( $changes['og_image_id'] ) ) {
			$warnings[] = 'Rank Math solo muestra og:image si la imagen está en la biblioteca de medios (usa su ID o la URL de un adjunto). La URL externa se ha guardado pero Rank Math no la publicará.';
		}

		return array(
			'warnings'      => $warnings,
			'post_id'       => $post_id,
			'plugins'       => $plugins,
			'before'        => $before,
			'after'         => $after,
			'cache_cleared' => $caches,
		);
	}

	/** @return array|WP_Error Campo => valor normalizado. */
	private static function sanitize_changes( $input ) {
		$changes = array();
		foreach ( array( 'title', 'og_title' ) as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$changes[ $field ] = sanitize_text_field( $input[ $field ] );
			}
		}
		foreach ( array( 'description', 'og_description' ) as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$changes[ $field ] = sanitize_textarea_field( $input[ $field ] );
			}
		}
		if ( isset( $input['canonical'] ) ) {
			$canonical = trim( (string) $input['canonical'] );
			if ( '' !== $canonical && ( ! preg_match( '#^https?://#i', $canonical ) || ! filter_var( $canonical, FILTER_VALIDATE_URL ) ) ) {
				return new WP_Error( 'premiero_seo_canonical', 'La canonical debe ser una URL absoluta http(s).' );
			}
			$changes['canonical'] = '' === $canonical ? '' : esc_url_raw( $canonical );
		}
		if ( isset( $input['robots'] ) && is_array( $input['robots'] ) ) {
			foreach ( array( 'noindex', 'nofollow' ) as $flag ) {
				if ( isset( $input['robots'][ $flag ] ) ) {
					$changes[ $flag ] = (bool) $input['robots'][ $flag ];
				}
			}
		}
		if ( isset( $input['og_image'] ) ) {
			$image = trim( (string) $input['og_image'] );
			if ( '' === $image ) {
				$changes['og_image']    = '';
				$changes['og_image_id'] = '';
			} elseif ( ctype_digit( $image ) ) {
				$url = wp_get_attachment_url( (int) $image );
				if ( ! $url ) {
					return new WP_Error( 'premiero_seo_image', 'No existe el adjunto ' . $image . '.' );
				}
				$changes['og_image']    = $url;
				$changes['og_image_id'] = (int) $image;
			} elseif ( preg_match( '#^https?://#i', $image ) ) {
				$changes['og_image']    = esc_url_raw( $image );
				$id                     = attachment_url_to_postid( $image );
				$changes['og_image_id'] = $id ? $id : '';
			} else {
				return new WP_Error( 'premiero_seo_image', 'og_image debe ser una URL http(s) o el ID de un adjunto.' );
			}
		}
		return $changes;
	}

	private static function read( $plugin, $post_id ) {
		$out = array();
		foreach ( self::keys( $plugin ) as $field => $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( 'yoast' === $plugin && 'noindex' === $field ) {
				$value = '1' === (string) $value ? 'noindex' : ( '2' === (string) $value ? 'index' : '' );
			} elseif ( 'yoast' === $plugin && 'nofollow' === $field ) {
				$value = '1' === (string) $value ? 'nofollow' : '';
			}
			$out[ $field ] = is_array( $value ) ? implode( ',', $value ) : (string) $value;
		}
		return $out;
	}

	private static function write( $plugin, $post_id, $changes ) {
		$keys = self::keys( $plugin );

		if ( 'rank-math' === $plugin && ( isset( $changes['noindex'] ) || isset( $changes['nofollow'] ) ) ) {
			$robots = get_post_meta( $post_id, $keys['robots'], true );
			$robots = is_array( $robots ) ? $robots : array_filter( explode( ',', (string) $robots ) );
			if ( isset( $changes['noindex'] ) ) {
				$robots   = array_diff( $robots, array( 'index', 'noindex' ) );
				$robots[] = $changes['noindex'] ? 'noindex' : 'index';
			}
			if ( isset( $changes['nofollow'] ) ) {
				$robots = array_diff( $robots, array( 'follow', 'nofollow' ) );
				if ( $changes['nofollow'] ) {
					$robots[] = 'nofollow';
				}
			}
			update_post_meta( $post_id, $keys['robots'], array_values( array_unique( $robots ) ) );
		}

		foreach ( $changes as $field => $value ) {
			if ( in_array( $field, array( 'noindex', 'nofollow' ), true ) ) {
				if ( 'yoast' !== $plugin ) {
					continue;
				}
				// Yoast: noindex '1' = noindex, '2' = index forzado; nofollow '1' = nofollow, '0' = follow.
				$value = 'noindex' === $field ? ( $value ? '1' : '2' ) : ( $value ? '1' : '0' );
			}
			if ( ! isset( $keys[ $field ] ) ) {
				continue;
			}
			if ( '' === $value ) {
				delete_post_meta( $post_id, $keys[ $field ] );
				continue;
			}
			if ( 'yoast' === $plugin && class_exists( 'WPSEO_Meta' ) && method_exists( 'WPSEO_Meta', 'set_value' ) ) {
				WPSEO_Meta::set_value( substr( $keys[ $field ], strlen( '_yoast_wpseo_' ) ), (string) $value, $post_id );
			} else {
				update_post_meta( $post_id, $keys[ $field ], $value );
			}
		}
	}

	/** Reconstruye el indexable de Yoast, que es de donde sale el <head>. */
	private static function refresh_yoast( $post_id ) {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return '';
		}
		try {
			$repository = YoastSEO()->classes->get( 'Yoast\WP\SEO\Repositories\Indexable_Repository' );
			$builder    = YoastSEO()->classes->get( 'Yoast\WP\SEO\Builders\Indexable_Builder' );
			$indexable  = $repository->find_by_id_and_type( $post_id, 'post', false );
			$builder->build_for_id_and_type( $post_id, 'post', $indexable );
			return 'yoast-indexable';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Rank Math no guarda una caché propia del <head>: lee los metadatos en cada
	 * carga, así que basta con la caché del post ya limpiada.
	 */
	private static function refresh_rank_math( $post_id ) {
		return 'rank-math (sin caché propia; caché del post limpiada)';
	}
}
