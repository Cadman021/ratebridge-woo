<?php
/**
 * Elementor widget: product currency (ارز محصول).
 *
 * @package RateBridge
 */

namespace RateBridge\Elementor\Widgets;

use Elementor\Controls_Manager;
use RateBridge\Frontend\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the currency of the current product, in single-product and loop
 * templates.
 */
final class ProductCurrency extends AbstractWidget {

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'rbw_product_currency';
	}

	/**
	 * Panel title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Product currency', 'ratebridge-woo' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-product-price';
	}

	/**
	 * Controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'rbw_content',
			array(
				'label' => __( 'Content', 'ratebridge-woo' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'rbw_format',
			array(
				'label'   => __( 'Format', 'ratebridge-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'name'       => __( 'Currency name', 'ratebridge-woo' ),
					'code'       => __( 'Currency code', 'ratebridge-woo' ),
					'name_code'  => __( 'Name and code', 'ratebridge-woo' ),
					'symbol'     => __( 'Symbol', 'ratebridge-woo' ),
				),
				'default' => 'name',
			)
		);

		$this->add_control(
			'rbw_profile',
			array(
				'label'       => __( 'Prefix', 'ratebridge-woo' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'ai'          => array( 'active' => false ),
			)
		);

		$this->add_control(
			'rbw_suffix',
			array(
				'label'       => __( 'Suffix', 'ratebridge-woo' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'ai'          => array( 'active' => false ),
			)
		);

		$this->add_control(
			'rbw_show_rate',
			array(
				'label'        => __( 'Show the current rate too', 'ratebridge-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'ratebridge-woo' ),
				'label_off'    => __( 'No', 'ratebridge-woo' ),
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->add_control(
			'rbw_icon',
			array(
				'label' => __( 'Icon', 'ratebridge-woo' ),
				'type'  => Controls_Manager::ICONS,
			)
		);

		$this->end_controls_section();

		$this->add_base_style_controls();
	}

	/**
	 * Default control values for a freshly dropped widget.
	 *
	 * @return array<string, mixed>
	 */
	protected function control_defaults(): array {
		return array(
			'rbw_format'    => 'name',
			'rbw_profile'   => '',
			'rbw_suffix'    => '',
			'rbw_show_rate' => '',
			'rbw_icon'      => array(),
		);
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	protected function render(): void {
		$settings = $this->settings();
		$product  = $this->resolve_product();

		if ( null === $product ) {
			echo $this->placeholder( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in placeholder().
				__( 'This widget only shows a value on a product page or inside a product loop.', 'ratebridge-woo' )
			);

			return;
		}

		$currency = $this->product_currency( $product );

		if ( null === $currency ) {
			echo $this->placeholder( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in placeholder().
				__( 'This product is not priced by a currency.', 'ratebridge-woo' )
			);

			return;
		}

		$format = isset( $settings['rbw_format'] ) ? (string) $settings['rbw_format'] : 'name';

		switch ( $format ) {
			case 'code':
				$inner = esc_html( $currency->code );
				break;

			case 'symbol':
				$inner = esc_html( Frontend::symbol_or_code( $currency ) );
				break;

			case 'name_code':
				$inner = sprintf( '%s (%s)', esc_html( $currency->name ), esc_html( $currency->code ) );
				break;

			case 'name':
			default:
				$inner = esc_html( $currency->name );
				break;
		}

		if ( 'yes' === ( $settings['rbw_show_rate'] ?? '' ) ) {
			$inner .= sprintf(
				' <span class="rbw-product-currency-rate" dir="ltr">%s</span> <span class="rbw-product-currency-unit">%s</span>',
				esc_html( Frontend::format_rate( $currency->rate ) ),
				esc_html( Frontend::unit_label() )
			);
		}

		echo Frontend::wrap( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in wrap().
			$inner,
			isset( $settings['rbw_profile'] ) ? (string) $settings['rbw_profile'] : '',
			isset( $settings['rbw_suffix'] ) ? (string) $settings['rbw_suffix'] : '',
			$this->render_icon( $settings['rbw_icon'] ?? null ),
			'rbw-widget rbw-widget-product-currency'
		);
	}
}
