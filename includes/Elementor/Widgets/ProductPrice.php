<?php
/**
 * Elementor widget: product price at the current rate (قیمت محصول).
 *
 * @package RateBridge
 */

namespace RateBridge\Elementor\Widgets;

use Elementor\Controls_Manager;
use RateBridge\Frontend\Frontend;
use RateBridge\Frontend\ProductNotice;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the currency info of the current product using the ProductNotice
 * logic (same template placeholders, same escaping).
 */
final class ProductPrice extends AbstractWidget {

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'rbw_product_price';
	}

	/**
	 * Panel title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Product price at current rate', 'ratebridge-woo' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-price-list';
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
			'rbw_template',
			array(
				'label'       => __( 'Template', 'ratebridge-woo' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 3,
				'default'     => ProductNotice::DEFAULT_TEMPLATE,
				'label_block' => true,
				'description' => $this->placeholder_help(),
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

		/*
		 * The widget renders one box (`.rbw-widget-box`) that contains the icon
		 * and the notice, so the box controls, the icon controls and the accent
		 * variable all target the same element.
		 */
		$this->add_base_style_controls(
			'{{WRAPPER}} .rbw-widget-box',
			'{{WRAPPER}} .rbw-product-notice',
			'{{WRAPPER}} .rbw-widget-box'
		);

	}

	/**
	 * Default control values for a freshly dropped widget.
	 *
	 * @return array<string, mixed>
	 */
	protected function control_defaults(): array {
		return array(
			'rbw_product_id' => '',
			'rbw_template'   => ProductNotice::DEFAULT_TEMPLATE,
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

		$product = $this->resolve_product( $settings['rbw_product_id'] ?? 0 );

		if ( null === $product ) {
			echo $this->placeholder( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in placeholder().
				__( 'Choose a product, or place this widget on a product page or inside a product loop.', 'ratebridge-woo' )
			);

			return;
		}

		$notice = new ProductNotice();
		$html   = $notice->html_for( $product, (string) ( $settings['rbw_template'] ?? ProductNotice::DEFAULT_TEMPLATE ) );

		if ( '' === $html ) {
			echo $this->placeholder( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in placeholder().
				__( 'This product is not priced by a currency, or its currency has no rate yet.', 'ratebridge-woo' )
			);

			return;
		}

		$icon = $this->render_icon( $settings['rbw_icon'] ?? null );

		if ( '' !== $icon ) {
			$html = sprintf( '<span class="rbw-widget-icon" aria-hidden="true">%s</span>%s', $icon, $html );
		}

		echo '<div class="rbw-widget-box">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_html/wp_kses upstream.
	}
}
