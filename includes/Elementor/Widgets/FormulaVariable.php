<?php
/**
 * Elementor widget: product formula variable (متغیر فرمول محصول).
 *
 * @package RateBridge
 */

namespace RateBridge\Elementor\Widgets;

use Elementor\Controls_Manager;
use RateBridge\Frontend\Frontend;
use RateBridge\Frontend\RateFormatter;
use RateBridge\Formula\FormulaModel;
use RateBridge\Pricing\FormulaPricer;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the effective value of one variable from the product's formula.
 */
final class FormulaVariable extends AbstractWidget {

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'rbw_formula_variable';
	}

	/**
	 * Panel title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Product formula variable', 'ratebridge-woo' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-variables';
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
			'rbw_variable',
			array(
				'label'       => __( 'Variable name', 'ratebridge-woo' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'wage',
				'dir'         => 'ltr',
				'label_block' => true,
				'description' => __( 'An identifier from the product’s formula, for example wage or weight.', 'ratebridge-woo' ),
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
			'rbw_show_label',
			array(
				'label'        => __( 'Show the variable label', 'ratebridge-woo' ),
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
			'rbw_product_id'  => '',
			'rbw_variable'    => 'wage',
			'rbw_before'      => '',
			'rbw_after'       => '',
			'rbw_show_label'  => '',
			'rbw_icon'        => array(),
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

		$name = isset( $settings['rbw_variable'] ) ? trim( (string) $settings['rbw_variable'] ) : '';

		if ( '' === $name || ! \RateBridge\Formula\Formula::is_valid_variable_name( $name ) ) {
			echo $this->placeholder( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in placeholder().
				__( 'Enter a valid variable name (letters, digits and underscore).', 'ratebridge-woo' )
			);

			return;
		}

		$formula = ( new FormulaPricer() )->formula_for( (int) $product->get_id() );

		if ( ! $formula instanceof FormulaModel ) {
			echo $this->placeholder( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in placeholder().
				__( 'This product is not priced by a formula.', 'ratebridge-woo' )
			);

			return;
		}

		$value = Frontend::formula_variable_value( $product, $name );

		if ( null === $value ) {
			echo $this->placeholder( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in placeholder().
				sprintf(
					/* translators: 1: variable name, 2: formula name */
					__( 'The formula “%2$s” has no variable named “%1$s”.', 'ratebridge-woo' ),
					$name,
					$formula->name
				)
			);

			return;
		}

		$inner = esc_html( $value['formatted'] );

		if ( 'yes' === ( $settings['rbw_show_label'] ?? '' ) ) {
			$inner = sprintf(
				'<span class="rbw-formula-var-label">%s</span> <span class="rbw-formula-var-value">%s</span>',
				esc_html( $value['label'] ),
				$inner
			);
		} else {
			$inner = sprintf( '<span class="rbw-formula-var-value">%s</span>', $inner );
		}

		echo Frontend::wrap( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in wrap().
			$inner,
			isset( $settings['rbw_before'] ) ? (string) $settings['rbw_before'] : '',
			isset( $settings['rbw_after'] ) ? (string) $settings['rbw_after'] : '',
			$this->render_icon( $settings['rbw_icon'] ?? null ),
			'rbw-widget rbw-widget-formula-var'
		);
	}
}
