<?php
/**
 * Elementor widget: product rate update date (تاریخ آخرین بروزرسانی قیمت محصول).
 *
 * @package RateBridge
 */

namespace RateBridge\Elementor\Widgets;

use Elementor\Controls_Manager;
use RateBridge\Frontend\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Shows when the current product's price was last recalculated.
 */
final class ProductUpdated extends AbstractWidget {

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'rbw_product_updated';
	}

	/**
	 * Panel title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Product price update date', 'ratebridge-woo' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-calendar';
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

		$this->add_product_control( 'rbw' );

		$this->add_control(
			'rbw_mode',
			array(
				'label'   => __( 'Format', 'ratebridge-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'relative' => __( 'Relative time', 'ratebridge-woo' ),
					'date'     => __( 'Date', 'ratebridge-woo' ),
				),
				'default' => 'relative',
			)
		);

		$this->add_control(
			'rbw_format',
			array(
				'label'       => __( 'Date format', 'ratebridge-woo' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'Y-m-d H:i',
				'label_block' => true,
				'condition'   => array( 'rbw_mode' => 'date' ),
				'description' => __( 'Letters: Y m d j H i s — for example Y-m-d H:i', 'ratebridge-woo' ),
				'ai'          => array( 'active' => false ),
			)
		);

		$this->add_control(
			'rbw_before',
			array(
				'label'       => __( 'Text before', 'ratebridge-woo' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'ai'          => array( 'active' => false ),
			)
		);

		$this->add_control(
			'rbw_after',
			array(
				'label'       => __( 'Text after', 'ratebridge-woo' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'ai'          => array( 'active' => false ),
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
			'rbw_product_id' => '',
			'rbw_mode'       => 'relative',
			'rbw_format'     => 'Y-m-d H:i',
			'rbw_before'     => '',
			'rbw_after'      => '',
			'rbw_icon'       => array(),
		);
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	protected function render(): void {
		$settings = $this->settings();
		$product  = $this->resolve_product( $settings['rbw_product_id'] ?? 0 );

		if ( null === $product ) {
			echo $this->placeholder( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in placeholder().
				__( 'Choose a product, or place this widget on a product page or inside a product loop.', 'ratebridge-woo' )
			);

			return;
		}

		if ( null === $this->product_currency( $product ) ) {
			echo $this->placeholder( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in placeholder().
				__( 'This product is not priced by a currency.', 'ratebridge-woo' )
			);

			return;
		}

		$timestamp = Frontend::product_calc_timestamp( $product );

		if ( $timestamp <= 0 ) {
			echo $this->placeholder( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in placeholder().
				__( 'The price of this product has not been calculated yet.', 'ratebridge-woo' )
			);

			return;
		}

		$mode   = isset( $settings['rbw_mode'] ) ? (string) $settings['rbw_mode'] : 'relative';
		$format = $this->sanitize_format( $settings['rbw_format'] ?? '' );

		$text = $this->format_timestamp( $timestamp, $mode, $format );

		if ( '' === $text ) {
			return;
		}

		$inner = sprintf(
			'<time datetime="%s">%s</time>',
			esc_attr( gmdate( 'c', $timestamp ) ),
			esc_html( $text )
		);

		echo Frontend::wrap( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in wrap().
			$inner,
			isset( $settings['rbw_before'] ) ? (string) $settings['rbw_before'] : '',
			isset( $settings['rbw_after'] ) ? (string) $settings['rbw_after'] : '',
			$this->render_icon( $settings['rbw_icon'] ?? null ),
			'rbw-widget rbw-widget-updated'
		);
	}
}
