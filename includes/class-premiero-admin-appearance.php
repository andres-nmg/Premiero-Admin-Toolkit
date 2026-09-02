<?php
/**
 * Personalización visual del administrador de WordPress.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Admin_Appearance {
	const OPTION = 'premiero_admin_appearance';

	/**
	 * Registra los hooks que aplican la apariencia al administrador.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'handle_submission' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_classes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ), 99 );
	}

	/**
	 * Paletas recomendadas para cada modo.
	 *
	 * @param string $mode Modo visual.
	 * @return array<string, string>
	 */
	public static function palette( $mode = 'custom' ) {
		if ( 'premiero' === $mode ) {
			return array(
				'accent'        => '#8a2c0d',
				'button_bg'     => '#7b2609',
				'button_text'   => '#fff8ed',
				'button_secondary_bg'   => '#fff7ea',
				'button_secondary_text' => '#7b2609',
				'menu_bg'       => '#6f230b',
				'menu_text'     => '#fff8ed',
				'menu_active'   => '#4d190b',
				'topbar_bg'     => '#4d190b',
				'content_bg'    => '#fbf1e2',
				'surface_bg'    => '#ffffff',
				'heading_color' => '#7b2609',
				'text_color'    => '#514b47',
				'muted_color'   => '#756d67',
			);
		}

		if ( 'dark' === $mode ) {
			return array(
				'accent'        => '#72aee6',
				'button_bg'     => '#72aee6',
				'button_text'   => '#111827',
				'button_secondary_bg'   => '#273449',
				'button_secondary_text' => '#f3f4f6',
				'menu_bg'       => '#111827',
				'menu_text'     => '#e5e7eb',
				'menu_active'   => '#2563eb',
				'topbar_bg'     => '#0b1220',
				'content_bg'    => '#111827',
				'surface_bg'    => '#1f2937',
				'heading_color' => '#ffffff',
				'text_color'    => '#f3f4f6',
				'muted_color'   => '#b6c0ce',
			);
		}

		return array(
			'accent'        => '#2271b1',
			'button_bg'     => '#2271b1',
			'button_text'   => '#ffffff',
			'button_secondary_bg'   => '#f6f7f7',
			'button_secondary_text' => '#2c3338',
			'menu_bg'       => '#1d2327',
			'menu_text'     => '#f0f0f1',
			'menu_active'   => '#2271b1',
			'topbar_bg'     => '#1d2327',
			'content_bg'    => '#f0f0f1',
			'surface_bg'    => '#ffffff',
			'heading_color' => '#1d2327',
			'text_color'    => '#2c3338',
			'muted_color'   => '#50575e',
		);
	}

	/**
	 * Ajustes completos con valores predeterminados.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$legacy_premiero = array(
			'accent'                  => '#55752e',
			'button_secondary_bg'      => '#eef2e7',
			'button_secondary_text'    => '#405b25',
			'menu_active'              => '#55752e',
		);
		$is_legacy_premiero = true;
		foreach ( $legacy_premiero as $key => $value ) {
			if ( ! isset( $stored[ $key ] ) || strtolower( (string) $stored[ $key ] ) !== $value ) {
				$is_legacy_premiero = false;
				break;
			}
		}
		if ( $is_legacy_premiero ) {
			$stored = array_merge( $stored, self::palette( 'premiero' ) );
			$stored['preset'] = 'premiero';
		}
		$mode   = isset( $stored['mode'] ) && 'dark' === $stored['mode'] ? 'dark' : 'custom';
		if ( ! isset( $stored['preset'] ) && 'custom' === $mode ) {
			$is_premiero = true;
			foreach ( self::palette( 'premiero' ) as $key => $value ) {
				if ( ! isset( $stored[ $key ] ) || strtolower( (string) $stored[ $key ] ) !== $value ) {
					$is_premiero = false;
					break;
				}
			}
			if ( $is_premiero ) {
				$stored['preset'] = 'premiero';
			}
		}
		$legacy_font = isset( $stored['font'] ) ? $stored['font'] : 'system';
		if ( ! isset( $stored['heading_color'] ) && isset( $stored['text_color'] ) ) {
			$stored['heading_color'] = $stored['text_color'];
			$stored['text_color']    = self::palette( $mode )['text_color'];
		}

		$settings = wp_parse_args(
			$stored,
			array_merge(
				array(
					'enabled' => 0,
					'mode'    => $mode,
					'preset'  => 'custom',
					'body_font'    => $legacy_font,
					'heading_font' => $legacy_font,
				),
				self::palette( $mode )
			)
		);
		$settings['enabled'] = empty( $settings['enabled'] ) ? 0 : 1;
		$settings['mode']    = $mode;
		$settings['preset']  = 'custom' === $mode && 'premiero' === sanitize_key( $settings['preset'] ) ? 'premiero' : 'custom';
		$settings['body_font']    = self::sanitize_font( $settings['body_font'] );
		$settings['heading_font'] = self::sanitize_font( $settings['heading_font'] );
		unset( $settings['font'] );
		foreach ( self::palette( $mode ) as $key => $fallback ) {
			$value            = sanitize_hex_color( $settings[ $key ] );
			$settings[ $key ] = $value ? $value : $fallback;
		}

		return $settings;
	}

	/**
	 * Procesa el formulario antes de imprimir la cabecera y vuelve por GET.
	 */
	public static function handle_submission() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( PREMIERO_ATK_SLUG !== $page || 'appearance' !== $tab || ! isset( $_POST['premiero_appearance_submit'] ) ) {
			return;
		}

		$status = self::maybe_save();
		if ( ! $status ) {
			return;
		}

		$url = add_query_arg(
			array(
				'page'              => PREMIERO_ATK_SLUG,
				'tab'               => 'appearance',
				'appearance-status' => $status,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Guarda o restaura los ajustes enviados desde la pestaña.
	 *
	 * @return string Estado del guardado o cadena vacía.
	 */
	public static function maybe_save() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			return '';
		}

		if ( ! isset( $_POST['premiero_appearance_submit'], $_POST['premiero_appearance_nonce'] ) ) {
			return '';
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return 'forbidden';
		}

		check_admin_referer( 'premiero_appearance_save', 'premiero_appearance_nonce' );

		if ( 'reset' === sanitize_key( wp_unslash( $_POST['premiero_appearance_submit'] ) ) ) {
			delete_option( self::OPTION );
			return 'reset';
		}

		$input = isset( $_POST[ self::OPTION ] ) && is_array( $_POST[ self::OPTION ] )
			? wp_unslash( $_POST[ self::OPTION ] )
			: array();
		$mode  = isset( $input['mode'] ) && 'dark' === sanitize_key( $input['mode'] ) ? 'dark' : 'custom';
		$preset = 'custom' === $mode && isset( $input['preset'] ) && 'premiero' === sanitize_key( $input['preset'] ) ? 'premiero' : 'custom';
		$clean = array(
			'enabled' => empty( $input['enabled'] ) ? 0 : 1,
			'mode'    => $mode,
			'preset'  => $preset,
			'body_font'    => self::sanitize_font( isset( $input['body_font'] ) ? $input['body_font'] : 'system' ),
			'heading_font' => self::sanitize_font( isset( $input['heading_font'] ) ? $input['heading_font'] : 'system' ),
		);

		foreach ( array_keys( self::palette( $mode ) ) as $key ) {
			$value         = isset( $input[ $key ] ) ? sanitize_hex_color( $input[ $key ] ) : '';
			$clean[ $key ] = $value ? $value : self::palette( $mode )[ $key ];
		}

		update_option( self::OPTION, $clean, false );
		return 'updated';
	}

	/**
	 * Añade clases de estado al body del administrador.
	 *
	 * @param string $classes Clases existentes.
	 * @return string
	 */
	public static function body_classes( $classes ) {
		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return $classes;
		}

		$classes .= ' premiero-admin-appearance';
		if ( 'dark' === $settings['mode'] ) {
			$classes .= ' premiero-admin-dark';
		}
		if ( 'premiero' === $settings['preset'] ) {
			$classes .= ' premiero-admin-brand';
		}

		return trim( $classes );
	}

	/**
	 * Carga la hoja visual y sus variables configurables.
	 */
	public static function enqueue_styles() {
		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$path    = PREMIERO_ATK_DIR . 'assets/admin-appearance.css';
		$version = file_exists( $path ) ? (string) filemtime( $path ) : PREMIERO_ATK_VER;
		wp_enqueue_style( 'premiero-admin-appearance', PREMIERO_ATK_ASSETS . 'admin-appearance.css', array(), $version );

		$body_font_stack    = self::font_stack( $settings['body_font'] );
		$heading_font_stack = self::font_stack( $settings['heading_font'] );
		$border             = 'dark' === $settings['mode'] ? '#374151' : '#c3c4c7';
		$variables  = sprintf(
			'body.premiero-admin-appearance{--premiero-admin-accent:%1$s;--premiero-admin-on-accent:%3$s;--premiero-admin-button-bg:%2$s;--premiero-admin-button-text:%3$s;--premiero-admin-button-secondary-bg:%4$s;--premiero-admin-button-secondary-text:%5$s;--premiero-admin-menu-bg:%6$s;--premiero-admin-menu-text:%7$s;--premiero-admin-menu-active:%8$s;--premiero-admin-topbar-bg:%9$s;--premiero-admin-canvas:%10$s;--premiero-admin-surface:%11$s;--premiero-admin-heading:%12$s;--premiero-admin-text:%13$s;--premiero-admin-muted:%14$s;--premiero-admin-border:%15$s;--premiero-admin-body-font:%16$s;--premiero-admin-heading-font:%17$s;}',
			$settings['accent'],
			$settings['button_bg'],
			$settings['button_text'],
			$settings['button_secondary_bg'],
			$settings['button_secondary_text'],
			$settings['menu_bg'],
			$settings['menu_text'],
			$settings['menu_active'],
			$settings['topbar_bg'],
			$settings['content_bg'],
			$settings['surface_bg'],
			$settings['heading_color'],
			$settings['text_color'],
			$settings['muted_color'],
			$border,
			$body_font_stack,
			$heading_font_stack
		);

		wp_add_inline_style( 'premiero-admin-appearance', $variables );
	}

	/**
	 * Renderiza la pestaña usando los patrones visuales del Toolkit.
	 */
	public static function render_tab() {
		$settings = self::get_settings();
		$fonts    = array(
			'system'      => 'Sistema',
			'mavenpro'    => 'Maven Pro',
			'ibmplexmono' => 'IBM Plex Mono',
			'modern'      => 'Moderna',
			'rounded'     => 'Redondeada',
			'serif'       => 'Serif',
		);
		$fields   = array(
			'accent'      => array( 'Color de énfasis', 'Enlaces, focos y elementos seleccionados.' ),
			'button_bg'   => array( 'Botón principal', 'Fondo de las acciones principales y de guardado.' ),
			'button_text' => array( 'Texto del botón principal', 'Texto e iconos sobre el botón principal.' ),
			'button_secondary_bg'   => array( 'Botón secundario', 'Fondo de botones auxiliares, filtros y navegación.' ),
			'button_secondary_text' => array( 'Texto del botón secundario', 'Texto e iconos sobre los botones secundarios.' ),
			'menu_bg'     => array( 'Fondo del menú', 'Color principal del menú lateral.' ),
			'menu_text'   => array( 'Texto del menú', 'Textos e iconos del menú lateral.' ),
			'menu_active' => array( 'Elemento activo', 'Fondo de la sección seleccionada.' ),
			'topbar_bg'   => array( 'Barra superior', 'Fondo de la barra de herramientas de WordPress.' ),
			'content_bg'  => array( 'Fondo general', 'Lienzo de las pantallas de administración.' ),
			'surface_bg'  => array( 'Paneles y tarjetas', 'Fondo de tablas, cajas y formularios.' ),
			'heading_color' => array( 'Títulos', 'Encabezados, títulos de tarjetas y cabeceras de tablas.' ),
			'text_color'    => array( 'Texto principal', 'Párrafos, valores, etiquetas y contenidos.' ),
			'muted_color'   => array( 'Texto secundario', 'Descripciones, ayudas, metadatos y textos atenuados.' ),
		);
		?>
		<div class="premiero-appearance-layout">
			<form method="post" class="premiero-appearance-form">
				<?php wp_nonce_field( 'premiero_appearance_save', 'premiero_appearance_nonce' ); ?>
				<input type="hidden" id="premiero-appearance-preset-name" name="<?php echo esc_attr( self::OPTION ); ?>[preset]" value="<?php echo esc_attr( $settings['preset'] ); ?>">
				<section class="premiero-appearance-section">
					<h2>Apariencia del administrador</h2>
					<p>Aplica una identidad visual común a las pantallas de WordPress sin modificar el núcleo ni los archivos de otros plugins.</p>
					<label class="premiero-appearance-toggle">
						<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
						<span>
							<strong>Activar personalización</strong>
							<small>Al desactivarla, WordPress recupera inmediatamente su aspecto original.</small>
						</span>
					</label>
				</section>

				<section class="premiero-appearance-section">
					<h2>Estilo base</h2>
					<div class="premiero-appearance-modes">
						<label>
							<input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[mode]" value="custom" <?php checked( 'custom', $settings['mode'] ); ?>>
							<span><strong>Claro personalizado</strong><small>Una base luminosa y editable.</small></span>
						</label>
						<label>
							<input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[mode]" value="dark" <?php checked( 'dark', $settings['mode'] ); ?>>
							<span><strong>Modo oscuro</strong><small>Interfaz oscura con contraste reforzado.</small></span>
						</label>
					</div>
					<p class="premiero-appearance-presets">
						<button type="button" class="button premiero-appearance-preset" data-preset="custom">Usar paleta clara</button>
						<button type="button" class="button premiero-appearance-preset" data-preset="dark">Usar paleta oscura</button>
						<button type="button" class="button premiero-appearance-preset premiero-appearance-preset--brand" data-preset="premiero">Usar colores Premiero</button>
					</p>
				</section>

				<section class="premiero-appearance-section">
					<h2>Colores</h2>
					<div class="premiero-appearance-colors">
						<?php foreach ( $fields as $key => $field ) : ?>
							<div class="premiero-appearance-color-control">
								<div class="premiero-appearance-color-copy">
									<label for="premiero-appearance-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $field[0] ); ?></strong></label>
									<small><?php echo esc_html( $field[1] ); ?></small>
								</div>
								<input type="text" class="premiero-appearance-color" id="premiero-appearance-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $settings[ $key ] ); ?>" data-setting="<?php echo esc_attr( $key ); ?>">
							</div>
						<?php endforeach; ?>
					</div>
					<p class="description">Comprueba que tus combinaciones de fondo y texto mantengan suficiente contraste para una lectura cómoda.</p>
				</section>

				<section class="premiero-appearance-section">
					<h2>Tipografía</h2>
					<div class="premiero-appearance-fonts">
						<div>
							<label for="premiero-appearance-body-font" class="premiero-appearance-font-label">Textos y controles</label>
							<select id="premiero-appearance-body-font" name="<?php echo esc_attr( self::OPTION ); ?>[body_font]">
								<?php foreach ( $fonts as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $settings['body_font'] ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<small>Menús, párrafos, formularios, botones y valores.</small>
						</div>
						<div>
							<label for="premiero-appearance-heading-font" class="premiero-appearance-font-label">Títulos y encabezados</label>
							<select id="premiero-appearance-heading-font" name="<?php echo esc_attr( self::OPTION ); ?>[heading_font]">
								<?php foreach ( $fonts as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $settings['heading_font'] ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<small>Títulos de página, secciones, tarjetas y tablas.</small>
						</div>
					</div>
					<p class="premiero-appearance-presets"><button type="button" class="button" id="premiero-appearance-premiero-fonts">Usar tipografías Premiero</button></p>
					<p class="description">Maven Pro e IBM Plex Mono están incluidas localmente en el plugin y no realizan conexiones externas.</p>
				</section>

				<div class="premiero-sticky-actions">
					<?php submit_button( 'Guardar cambios', 'primary', 'premiero_appearance_submit', false ); ?>
					<button type="submit" class="button button-link-delete" name="premiero_appearance_submit" value="reset" data-premiero-appearance-reset>Restaurar valores de WordPress</button>
				</div>
			</form>

			<aside class="premiero-appearance-preview" id="premiero-appearance-preview">
				<strong>Vista previa</strong>
				<p>Los cambios se muestran aquí antes de guardarlos.</p>
				<div class="premiero-appearance-preview-window">
					<div class="premiero-appearance-preview-topbar"><span>Mi sitio</span><span>Hola, admin</span></div>
					<div class="premiero-appearance-preview-main">
						<nav>
							<span>Escritorio</span>
							<span class="is-active">Entradas</span>
							<span>Medios</span>
							<span>Páginas</span>
						</nav>
						<div class="premiero-appearance-preview-content">
							<h3>Escritorio</h3>
							<div class="premiero-appearance-preview-card">
								<strong>Estado del sitio</strong>
								<p>Todo funciona correctamente.</p>
								<div class="premiero-appearance-preview-buttons">
									<button type="button" class="is-primary">Guardar cambios</button>
									<button type="button" class="is-secondary">Cancelar</button>
								</div>
							</div>
						</div>
					</div>
				</div>
			</aside>
		</div>
		<script>
		jQuery(function($){
			var preview = $('#premiero-appearance-preview');
			var palettes = <?php echo wp_json_encode( array( 'custom' => self::palette( 'custom' ), 'dark' => self::palette( 'dark' ), 'premiero' => self::palette( 'premiero' ) ) ); ?>;
			var fonts = {
				system: '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
				mavenpro: '"Maven Pro", sans-serif',
				ibmplexmono: '"IBM Plex Mono", ui-monospace, monospace',
				modern: '"Segoe UI", Roboto, Helvetica, Arial, sans-serif',
				rounded: 'ui-rounded, "SF Pro Rounded", "Arial Rounded MT Bold", sans-serif',
				serif: 'Georgia, "Times New Roman", serif'
			};

			function currentMode(){
				return $('input[name="<?php echo esc_js( self::OPTION ); ?>[mode]"]:checked').val() || 'custom';
			}

			function colorValue(key){
				return $('#premiero-appearance-' + key).val() || palettes[currentMode()][key];
			}

			function refreshPreview(){
				var mode = currentMode();
				preview.toggleClass('is-dark', mode === 'dark');
				preview.toggleClass('is-brand', $('#premiero-appearance-preset-name').val() === 'premiero');
				preview.toggleClass('is-disabled', !$('input[name="<?php echo esc_js( self::OPTION ); ?>[enabled]"]').is(':checked'));
				preview[0].style.setProperty('--preview-accent', colorValue('accent'));
				preview[0].style.setProperty('--preview-button-bg', colorValue('button_bg'));
				preview[0].style.setProperty('--preview-button-text', colorValue('button_text'));
				preview[0].style.setProperty('--preview-button-secondary-bg', colorValue('button_secondary_bg'));
				preview[0].style.setProperty('--preview-button-secondary-text', colorValue('button_secondary_text'));
				preview[0].style.setProperty('--preview-menu-bg', colorValue('menu_bg'));
				preview[0].style.setProperty('--preview-menu-text', colorValue('menu_text'));
				preview[0].style.setProperty('--preview-menu-active', colorValue('menu_active'));
				preview[0].style.setProperty('--preview-topbar', colorValue('topbar_bg'));
				preview[0].style.setProperty('--preview-canvas', colorValue('content_bg'));
				preview[0].style.setProperty('--preview-surface', colorValue('surface_bg'));
				preview[0].style.setProperty('--preview-heading', colorValue('heading_color'));
				preview[0].style.setProperty('--preview-text', colorValue('text_color'));
				preview[0].style.setProperty('--preview-muted', colorValue('muted_color'));
				preview[0].style.setProperty('--preview-body-font', fonts[$('#premiero-appearance-body-font').val()] || fonts.system);
				preview[0].style.setProperty('--preview-heading-font', fonts[$('#premiero-appearance-heading-font').val()] || fonts.system);
			}

			$('.premiero-appearance-color').each(function(){
				var input = $(this);
				if ($.fn.wpColorPicker) {
					input.wpColorPicker({
						change: function(event, ui){ input.val(ui.color.toString()); refreshPreview(); },
						clear: refreshPreview
					});
				}
			}).on('input change', refreshPreview);

			function applyPalette(mode){
				var palette = palettes[mode];
				$('#premiero-appearance-preset-name').val(mode === 'premiero' ? 'premiero' : 'custom');
				$.each(palette, function(key, value){
					var input = $('#premiero-appearance-' + key);
					if ($.fn.wpColorPicker && input.hasClass('wp-color-picker')) {
						input.wpColorPicker('color', value);
					} else {
						input.val(value);
					}
				});
				refreshPreview();
			}

			$('.premiero-appearance-preset').on('click', function(){
				var preset = $(this).data('preset');
				var mode = preset === 'dark' ? 'dark' : 'custom';
				$('input[name="<?php echo esc_js( self::OPTION ); ?>[mode]"][value="' + mode + '"]').prop('checked', true);
				applyPalette(preset);
			});

			$('#premiero-appearance-premiero-fonts').on('click', function(){
				$('#premiero-appearance-body-font').val('mavenpro');
				$('#premiero-appearance-heading-font').val('ibmplexmono');
				refreshPreview();
			});

			$('input[name="<?php echo esc_js( self::OPTION ); ?>[enabled]"], #premiero-appearance-body-font, #premiero-appearance-heading-font').on('change', refreshPreview);
			$('input[name="<?php echo esc_js( self::OPTION ); ?>[mode]"]').on('change', function(){ applyPalette(this.value); });
			$('[data-premiero-appearance-reset]').on('click', function(event){
				if (!window.confirm('¿Quieres desactivar la personalización y restaurar los valores originales de WordPress?')) {
					event.preventDefault();
				}
			});
			refreshPreview();
		});
		</script>
		<?php
	}

	/**
	 * Limita la tipografía a las familias disponibles.
	 *
	 * @param string $font Identificador recibido.
	 * @return string
	 */
	private static function sanitize_font( $font ) {
		$font = sanitize_key( $font );
		return in_array( $font, array( 'system', 'mavenpro', 'ibmplexmono', 'modern', 'rounded', 'serif' ), true ) ? $font : 'system';
	}

	/**
	 * Devuelve una pila segura para la familia seleccionada.
	 *
	 * @param string $font Identificador de fuente.
	 * @return string
	 */
	private static function font_stack( $font ) {
		$stacks = array(
			'system'      => '-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
			'mavenpro'    => '"Maven Pro",sans-serif',
			'ibmplexmono' => '"IBM Plex Mono",ui-monospace,monospace',
			'modern'      => '"Segoe UI",Roboto,Helvetica,Arial,sans-serif',
			'rounded'     => 'ui-rounded,"SF Pro Rounded","Arial Rounded MT Bold",sans-serif',
			'serif'       => 'Georgia,"Times New Roman",serif',
		);
		$font   = self::sanitize_font( $font );
		return $stacks[ $font ];
	}

}
