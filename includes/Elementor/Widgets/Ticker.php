<?php
/**
 * Elementor widget: scrolling ticker (نوار متحرک).
 *
 * @package RateBridge
 */

namespace RateBridge\Elementor\Widgets;

use Elementor\Controls_Manager;
use RateBridge\Frontend\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the same CSS-only ticker as `[rbw_ticker]`.
 */
final class Ticker extends AbstractWidget {

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'rbw_ticker';
	}

	/**
	 * Panel title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Rates ticker', 'ratebridge-woo' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-slider-album';
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
			'rbw_currencies',
			array(
				'label'       => __( 'Currencies', 'ratebridge-woo' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => $this->currency_options(),
				'description' => __( 'Leave empty to show every enabled currency.', 'ratebridge-woo' ),
			)
		);

		$this->add_control(
			'rbw_template',
			array(
				'label'       => __( 'Item template', 'ratebridge-woo' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '%name% <span class="rbw-ticker-rate">%rate%</span> %unit%',
				'label_block' => true,
				'description' => $this->placeholder_help(),
				'ai'          => array( 'active' => false ),
			)
		);

		$this->add_control(
			'rbw_speed',
			array(
				'label'      => __( 'Duration (seconds)', 'ratebridge-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'range'      => array(
					'px' => array(
						'min'  => 5,
						'max'  => 120,
						'step' => 1,
					),
				),
				'default'    => array( 'size' => 30 ),
				'description' => __( 'How long one full pass takes.', 'ratebridge-woo' ),
			)
		);

		$this->add_control(
			'rbw_pause',
			array(
				'label'        => __( 'Pause on hover', 'ratebridge-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'ratebridge-woo' ),
				'label_off'    => __( 'No', 'ratebridge-woo' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		$this->add_base_style_controls( '{{WRAPPER}} .rbw-ticker', '{{WRAPPER}} .rbw-ticker-item' );

		$this->start_controls_section(
			'rbw_style_ticker',
			array(
				'label' => __( 'Ticker', 'ratebridge-woo' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'rbw_item_gap',
			array(
				'label'      => __( 'Item gap', 'ratebridge-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 120,
						'step' => 1,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .rbw-ticker-group' => 'gap: {{SIZE}}{{UNIT}}; padding-inline-end: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'rbw_sep_color',
			array(
				'label'     => __( 'Separator colour', 'ratebridge-woo' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .rbw-ticker-sep' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'rbw_ticker_bg',
			array(
				'label'     => __( 'Strip background', 'ratebridge-woo' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .rbw-ticker' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Default control values for a freshly dropped widget.
	 *
	 * @return array<string, mixed>
	 */
	protected function control_defaults(): array {
		return array(
			'rbw_currencies' => array(),
			'rbw_template'   => '%name% <span class="rbw-ticker-rate">%rate%</span> %unit%',
			'rbw_speed'      => array( 'size' => 30 ),
			'rbw_pause'      => 'yes',
		);
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	protected function render(): void {
		$settings = $this->settings();

		$codes = $this->to_codes( $settings['rbw_currencies'] ?? array() );
		$currencies = Frontend::resolve_currencies( $codes );

		if ( empty( $currencies ) ) {
			echo $this->placeholder( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in placeholder().
				__( 'The ticker has nothing to show: enable a currency and give it a rate first.', 'ratebridge-woo' )
			);

			return;
		}

		$template = isset( $settings['rbw_template'] ) ? (string) $settings['rbw_template'] : '%name% %rate% %unit%';
		$speed    = isset( $settings['rbw_speed']['size'] ) ? (int) $settings['rbw_speed']['size'] : 30;
		$pause    = 'yes' === ( $settings['rbw_pause'] ?? 'yes' );

		$html = Frontend::cached(
			(string) wp_json_encode( array( 'elementor-ticker', $codes, $template, $speed, $pause, Frontend::use_persian_digits(), \RateBridge\Core\Settings::store_unit() ) ),
			fn (): string => $this->shortcodes()->render_ticker( $currencies, $template, $speed, $pause, 'rbw-elementor-ticker' )
		);

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped upstream in Shortcodes::render_ticker().
	}
}
