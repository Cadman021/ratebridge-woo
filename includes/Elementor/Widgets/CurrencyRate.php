<?php
/**
 * Elementor widget: single currency rate (قیمت ارز).
 *
 * @package RateBridge
 */

namespace RateBridge\Elementor\Widgets;

use Elementor\Controls_Manager;
use RateBridge\Frontend\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Renders one currency through the RateFormatter template engine.
 */
final class CurrencyRate extends AbstractWidget {

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'rbw_currency_rate';
	}

	/**
	 * Panel title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Currency rate', 'ratebridge-woo' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-number-field';
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

		$options = $this->currency_options();

		$this->add_control(
			'rbw_currency',
			array(
				'label'       => __( 'Currency', 'ratebridge-woo' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $options,
				'default'     => (string) array_key_first( $options ),
				'label_block' => true,
			)
		);

		$this->add_text_controls( 'rbw', '%name%: %rate% %unit%' );

		$this->add_control(
			'rbw_show_updated',
			array(
				'label'        => __( 'Show update time', 'ratebridge-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'ratebridge-woo' ),
				'label_off'    => __( 'No', 'ratebridge-woo' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'Appends the relative update time when the template does not use %updated%.', 'ratebridge-woo' ),
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
		$options = $this->currency_options();

		return array(
			'rbw_currency'       => (string) array_key_first( $options ),
			'rbw_template'       => '%name%: %rate% %unit%',
			'rbw_show_updated'   => '',
			'rbw_before'         => '',
			'rbw_after'          => '',
			'rbw_icon'           => array(),
		);
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	protected function render(): void {
		$settings = $this->settings();

		$code     = isset( $settings['rbw_currency'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $settings['rbw_currency'] ) ?? '' ) : '';
		$template = isset( $settings['rbw_template'] ) ? (string) $settings['rbw_template'] : '%name%: %rate% %unit%';

		if ( 'yes' === ( $settings['rbw_show_updated'] ?? '' ) && ! str_contains( $template, '%updated%' ) ) {
			$template .= ' — %updated%';
		}

		$currencies = Frontend::resolve_currencies( array( $code ) );

		if ( empty( $currencies ) ) {
			echo $this->placeholder( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in placeholder().
				__( 'Choose a currency that is enabled and has a rate.', 'ratebridge-woo' )
			);

			return;
		}

		$currency = $currencies[0];

		$inner = Frontend::cached(
			(string) wp_json_encode( array( 'elementor-rate', $currency->code, $currency->rate, $currency->last_updated, $template, Frontend::use_persian_digits() ) ),
			fn (): string => $this->shortcodes()->render_rate_item( $currency, $template )
		);

		if ( '' === $inner ) {
			echo $this->placeholder( esc_html__( 'The template is empty.', 'ratebridge-woo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- placeholder() escapes.

			return;
		}

		echo Frontend::wrap( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in wrap()/render().
			$inner,
			isset( $settings['rbw_before'] ) ? (string) $settings['rbw_before'] : '',
			isset( $settings['rbw_after'] ) ? (string) $settings['rbw_after'] : '',
			$this->render_icon( $settings['rbw_icon'] ?? null ),
			'rbw-widget rbw-widget-rate'
		);
	}
}
