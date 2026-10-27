<?php
/**
 * Shared base class for the RateBridge Elementor widgets.
 *
 * Every widget renders through `Frontend`/`Shortcodes`/`ProductNotice`, so no
 * formatting logic is duplicated here: this class only provides the common
 * controls (style tab, labels, icons), the data helpers and the "no data"
 * placeholder rule.
 *
 * @package RateBridge
 */

namespace RateBridge\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;
use RateBridge\Currency\Currency;
use RateBridge\Core\Settings;
use RateBridge\Elementor\ElementorIntegration;
use RateBridge\Frontend\Frontend;
use RateBridge\Frontend\RateFormatter;
use RateBridge\Frontend\Shortcodes;
use RateBridge\Product\ProductData;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Skeleton for all RateBridge widgets.
 */
abstract class AbstractWidget extends Widget_Base {

	/**
	 * Widget category.
	 *
	 * @return string[]
	 */
	public function get_categories(): array {
		return array( ElementorIntegration::CATEGORY );
	}

	/**
	 * Elementor icon (its own icon font).
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-exchange';
	}

	/**
	 * Keywords for the Elementor search box (Persian first).
	 *
	 * @return string[]
	 */
	public function get_keywords(): array {
		return array( 'نرخ', 'ارز', 'قیمت', 'rate', 'currency', 'ratebridge' );
	}

	/**
	 * The shared frontend stylesheet.
	 *
	 * @return string[]
	 */
	public function get_style_depends(): array {
		return array( Frontend::STYLE );
	}

	/**
	 * Widget settings.
	 *
	 * Reads Elementor's stored settings array directly.
	 *
	 * Both `get_settings()` and `get_settings_for_display()` run Elementor's
	 * sanitiser, which requires a live rendering document and calls `wp_die()`
	 * otherwise — that would make the widgets unusable from cron, REST, CLI tests
	 * or any non-Elementor render context. The stored array is exactly what the
	 * editor saved and every value is sanitised again where it is used (codes
	 * through the repository, formats through `Settings::sanitize_*`, templates
	 * through `wp_kses`), so reading it directly is safe.
	 *
	 * @return array<string, mixed>
	 */
	protected function settings(): array {
		$defaults = $this->control_defaults();

		$settings = $this->raw_settings();

		return array_merge( $defaults, $settings );
	}

	/**
	 * Elementor's stored settings, read without triggering its sanitiser.
	 *
	 * `$data` is private to `Controls_Stack`, so it must be read through the
	 * declaring class; `get_object_vars()` from this scope would silently return
	 * the public properties only.
	 *
	 * @return array<string, mixed>
	 */
	private function raw_settings(): array {
		$reflection = new \ReflectionObject( $this );

		$declaring = $reflection;

		while ( $declaring instanceof \ReflectionClass ) {
			if ( $declaring->hasProperty( 'data' ) && ! $declaring->getProperty( 'data' )->isStatic() ) {
				$property = $declaring->getProperty( 'data' );
				$property->setAccessible( true );

				/*
				 * `ReflectionProperty::getValue()` on a private property of a
				 * parent class is safe: it does not call the magic `__get()`
				 * that Elementor uses for other properties.
				 */
				$data = $property->getValue( $this );

				if ( is_array( $data ) && isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
					return $data['settings'];
				}

				break;
			}

			$declaring = $declaring->getParentClass();
		}

		return array();
	}

	/**
	 * Default values merged under the stored settings.
	 *
	 * Subclasses override this so a freshly dropped widget (no stored settings
	 * yet) renders exactly like the editor preview.
	 *
	 * @return array<string, mixed>
	 */
	protected function control_defaults(): array {
		return array();
	}

	/**
	 * Shared renderer for templates/tables/tickers.
	 *
	 * @return Shortcodes
	 */
	protected function shortcodes(): Shortcodes {
		return new Shortcodes();
	}

	/**
	 * Enabled currencies with a rate, keyed by code (for the select controls).
	 *
	 * Includes currencies without a rate so an admin can still pick them; the
	 * render path filters them out (rate 0 is invisible on the frontend).
	 *
	 * @return array<string, string>
	 */
	protected function currency_options(): array {
		$options = array();

		foreach ( Frontend::resolve_currencies_all() as $currency ) {
			$label = sprintf( '%s — %s', $currency->code, $currency->name );

			if ( ! $currency->has_rate() ) {
				$label .= ' — ' . __( 'no rate yet', 'ratebridge-woo' );
			}

			$options[ $currency->code ] = $label;
		}

		return $options;
	}

	/**
	 * Placeholder shown to editors when a widget has nothing to display.
	 *
	 * Visitors get empty output (same rule as the shortcodes).
	 *
	 * @param string $message Persian message.
	 * @return string
	 */
	protected function placeholder( string $message ): string {
		if ( ! $this->is_editor_context() ) {
			return '';
		}

		return sprintf(
			'<div class="rbw-widget-placeholder rbw-elementor-placeholder">%s</div>',
			esc_html( $message )
		);
	}

	/**
	 * Whether we are rendering inside the Elementor editor preview.
	 *
	 * @return bool
	 */
	protected function is_editor_context(): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$plugin = \Elementor\Plugin::instance();

		return is_object( $plugin )
			&& isset( $plugin->editor )
			&& is_object( $plugin->editor )
			&& method_exists( $plugin->editor, 'is_edit_mode' )
			&& $plugin->editor->is_edit_mode();
	}

	/**
	 * Resolve the control value to a list of currency codes.
	 *
	 * @param mixed $value Raw control value (string[] or string).
	 * @return string[]
	 */
	protected function to_codes( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$codes = array();

		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$code = strtoupper( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $item ) ?? '' );

			if ( '' !== $code ) {
				$codes[] = $code;
			}
		}

		return array_values( array_unique( $codes ) );
	}

	/**
	 * Render an Elementor icon control value.
	 *
	 * @param array<string, mixed>|null $icon Icon control value.
	 * @return string Escaped icon markup ('' when unset).
	 */
	protected function render_icon( mixed $icon ): string {
		if ( ! is_array( $icon ) || empty( $icon['value'] ) ) {
			return '';
		}

		/*
		 * Icons_Manager is a *class*, so this must be class_exists() — using
		 * function_exists() silently disabled every icon control.
		 */
		if ( ! class_exists( '\Elementor\Icons_Manager' ) ) {
			return '';
		}

		ob_start();
		\Elementor\Icons_Manager::render_icon( $icon, array( 'aria-hidden' => 'true' ) );

		$rendered = (string) ob_get_clean();

		/*
		 * The markup comes from Elementor's own Icons_Manager (not user input),
		 * so it is emitted as-is: `wp_kses()` lowercases SVG attribute names and
		 * would turn `viewBox` into `viewbox`, which breaks SVG scaling. The
		 * value in the control is only ever read, never echoed by this class.
		 */
		return self::is_safe_icon_markup( $rendered ) ? $rendered : '';
	}

	/**
	 * Belt-and-braces check on Elementor's icon markup.
	 *
	 * Rejects anything that is not a single `<svg>`/`<i>` fragment, so a
	 * tampered control value can never inject a script tag through the icon slot.
	 *
	 * @param string $markup Rendered icon markup.
	 * @return bool
	 */
	private static function is_safe_icon_markup( string $markup ): bool {
		$markup = trim( $markup );

		if ( '' === $markup ) {
			return false;
		}

		// No scripts, styles, event handlers or foreign objects.
		if ( preg_match( '/<\s*(script|style|iframe|object|embed|foreignObject)\b/i', $markup )
			|| preg_match( '/\son[a-z]+\s*=/i', $markup )
			|| preg_match( '/javascript\s*:/i', $markup ) ) {
			return false;
		}

		// Exactly one root element.
		return 1 === preg_match_all( '/<(svg|i)\b/i', $markup );
	}

	/**
	 * Shared "Content" controls for single-value widgets.
	 *
	 * @param string $prefix   Control id prefix.
	 * @param string $template Default template.
	 * @param string $before   Default "before" label.
	 * @return void
	 */
	protected function add_text_controls( string $prefix, string $template = '', string $before = '' ): void {
		if ( '' !== $template ) {
			$this->add_control(
				$prefix . '_template',
				array(
					'label'       => __( 'Template', 'ratebridge-woo' ),
					'type'        => Controls_Manager::TEXT,
					'default'     => $template,
					'label_block' => true,
					'description' => $this->placeholder_help(),
					'ai'          => array( 'active' => false ),
				)
			);
		}

		$this->add_control(
			$prefix . '_icon',
			array(
				'label' => __( 'Icon', 'ratebridge-woo' ),
				'type'  => Controls_Manager::ICONS,
			)
		);

		$this->add_control(
			$prefix . '_before',
			array(
				'label'       => __( 'Text before', 'ratebridge-woo' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => $before,
				'label_block' => true,
				'ai'          => array( 'active' => false ),
			)
		);

		$this->add_control(
			$prefix . '_after',
			array(
				'label'       => __( 'Text after', 'ratebridge-woo' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'ai'          => array( 'active' => false ),
			)
		);
	}

	/**
	 * Help text listing the supported template placeholders.
	 *
	 * @return string
	 */
	protected function placeholder_help(): string {
		return sprintf(
			/* translators: %s: comma separated placeholder list */
			__( 'Placeholders: %s', 'ratebridge-woo' ),
			implode( ' ', array_map( static fn ( string $p ): string => '%' . $p . '%', RateFormatter::placeholders() ) )
		);
	}

	/**
	 * Shared widgets style section: alignment, typography, colours, box.
	 *
	 * @param string $selector CSS selector for the wrapper.
	 * @param string $inner    Selector for the value text.
	 * @return void
	 */
	protected function add_base_style_controls( string $selector = '{{WRAPPER}} .rbw-widget', string $inner = '{{WRAPPER}} .rbw-widget-value' ): void {
		$this->start_controls_section(
			'rbw_style_section',
			array(
				'label' => __( 'Widget style', 'ratebridge-woo' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'rbw_align',
			array(
				'label'     => __( 'Alignment', 'ratebridge-woo' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array(
						'title' => __( 'Left', 'ratebridge-woo' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center' => array(
						'title' => __( 'Center', 'ratebridge-woo' ),
						'icon'  => 'eicon-text-align-center',
					),
					'right'  => array(
						'title' => __( 'Right', 'ratebridge-woo' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				// Logical values keep the control direction-aware.
				'selectors' => array(
					$selector => 'text-align: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'rbw_typography',
				'label'    => __( 'Typography', 'ratebridge-woo' ),
				'selector' => $inner,
			)
		);

		$this->add_responsive_control(
			'rbw_icon_size',
			array(
				'label'      => __( 'Icon size', 'ratebridge-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min'  => 8,
						'max'  => 96,
						'step' => 1,
					),
				),
				'selectors'  => array(
					$selector . ' .rbw-widget-icon'     => 'font-size: {{SIZE}}{{UNIT}};',
					$selector . ' .rbw-widget-icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'rbw_icon_color',
			array(
				'label'     => __( 'Icon colour', 'ratebridge-woo' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					$selector . ' .rbw-widget-icon'     => 'color: {{VALUE}};',
					$selector . ' .rbw-widget-icon svg' => 'fill: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'rbw_text_color',
			array(
				'label'     => __( 'Text colour', 'ratebridge-woo' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					$inner                          => 'color: {{VALUE}};',
					$selector . ' .rbw-widget-icon' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'rbw_accent_color',
			array(
				'label'     => __( 'Accent colour (values)', 'ratebridge-woo' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					$selector => '--rbw-accent: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'rbw_border',
				'label'    => __( 'Border', 'ratebridge-woo' ),
				'selector' => $selector,
			)
		);

		$this->add_responsive_control(
			'rbw_padding',
			array(
				'label'      => __( 'Padding', 'ratebridge-woo' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					$selector => 'padding-block: {{TOP}}{{UNIT}} {{BOTTOM}}{{UNIT}}; padding-inline: {{RIGHT}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'rbw_background',
			array(
				'label'     => __( 'Background', 'ratebridge-woo' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					$selector => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'rbw_shadow',
				'label'    => __( 'Box shadow', 'ratebridge-woo' ),
				'selector' => $selector,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Resolve the product for the product widgets.
	 *
	 * Order of precedence:
	 * 1. the explicit `product_id` control (Elementor Pro templates, pages),
	 * 2. the queried product (`single-product`),
	 * 3. the current loop item (`get_the_ID()`), so the widgets work inside
	 *    Elementor's loop/product-archive templates.
	 *
	 * @param mixed $product_id Control value.
	 * @return WC_Product|null
	 */
	protected function resolve_product( mixed $product_id = 0 ): ?WC_Product {
		$explicit = is_numeric( $product_id ) ? (int) $product_id : 0;

		$product = Frontend::current_product( $explicit );

		if ( $product instanceof WC_Product ) {
			return $product;
		}

		// Loop / archive templates: `get_the_ID()` points at the current item.
		if ( $explicit <= 0 && function_exists( 'get_the_ID' ) ) {
			$loop_id = (int) get_the_ID();

			if ( $loop_id > 0 ) {
				$loop_product = wc_get_product( $loop_id );

				if ( $loop_product instanceof WC_Product ) {
					return $loop_product;
				}
			}
		}

		return null;
	}

	/**
	 * Managed currency of a product ('' when not managed).
	 *
	 * @param WC_Product|null $product Product.
	 * @return Currency|null
	 */
	protected function product_currency( ?WC_Product $product ): ?Currency {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		return Frontend::product_currency( $product );
	}

	/**
	 * Control definition for an optional product selector.
	 *
	 * @param string $prefix Control prefix.
	 * @return void
	 */
	protected function add_product_control( string $prefix = 'rbw' ): void {
		$this->add_control(
			$prefix . '_product_id',
			array(
				'label'       => __( 'Product', 'ratebridge-woo' ),
				'type'        => Controls_Manager::SELECT2,
				'options'     => $this->product_options(),
				'label_block' => true,
				'description' => __( 'Leave empty to use the product of the current page (or the current item in a loop template).', 'ratebridge-woo' ),
			)
		);
	}

	/**
	 * A short list of managed products for the selector.
	 *
	 * @return array<string, string>
	 */
	private function product_options(): array {
		$products = wc_get_products(
			array(
				'limit'      => 50,
				'status'     => array( 'publish', 'private', 'draft' ),
				'orderby'    => 'title',
				'order'      => 'ASC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => ProductData::META_ENABLED,
						'value' => 'yes',
					),
				),
			)
		);

		$options = array();

		foreach ( (array) $products as $product ) {
			if ( $product instanceof WC_Product ) {
				$options[ (string) $product->get_id() ] = $product->get_name();
			}
		}

		return $options;
	}

	/**
	 * Format a timestamp as either a relative time or a date.
	 *
	 * @param int    $timestamp Timestamp.
	 * @param string $mode      'relative' or 'date'.
	 * @param string $format    Token date format (used when mode = date).
	 * @return string
	 */
	protected function format_timestamp( int $timestamp, string $mode = 'relative', string $format = '' ): string {
		if ( $timestamp <= 0 ) {
			return '';
		}

		if ( 'date' === $mode ) {
			return Frontend::format_date(
				$timestamp,
				'' !== $format ? $format : (string) Settings::get( 'date_format', 'Y-m-d H:i' )
			);
		}

		return Frontend::time_ago( $timestamp );
	}

	/**
	 * Sanitise a user-supplied date format (same rules as the settings).
	 *
	 * @param mixed $format Raw format.
	 * @return string
	 */
	protected function sanitize_format( mixed $format ): string {
		return is_string( $format ) ? Settings::sanitize_date_format( $format ) : '';
	}
}
