<?php
/**
 * Elementor widget: site rates update date (تاریخ آخرین بروزرسانی سایت).
 *
 * @package RateBridge
 */

namespace RateBridge\Elementor\Widgets;

use Elementor\Controls_Manager;
use RateBridge\Currency\Currency;
use RateBridge\Frontend\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the most recent successful rate update across all enabled currencies.
 */
final class SiteUpdated extends AbstractWidget {

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'rbw_site_updated';
	}

	/**
	 * Panel title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Site rates update date', 'ratebridge-woo' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-clock-o';
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
			'rbw_show_source',
			array(
				'label'        => __( 'Also show the currency of the newest rate', 'ratebridge-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'ratebridge-woo' ),
				'label_off'    => __( 'No', 'ratebridge-woo' ),
				'return_value' => 'yes',
				'default'      => '',
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
			'rbw_mode'        => 'relative',
			'rbw_format'      => 'Y-m-d H:i',
			'rbw_show_source' => '',
			'rbw_before'      => '',
			'rbw_after'       => '',
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

		$latest = $this->latest_currency();

		if ( null === $latest ) {
			echo $this->placeholder( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in placeholder().
				__( 'No enabled currency has a rate yet, so there is no update date to show.', 'ratebridge-woo' )
			);

			return;
		}

		$timestamp = Frontend::currency_timestamp( $latest );

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

		if ( 'yes' === ( $settings['rbw_show_source'] ?? '' ) ) {
			$inner .= sprintf(
				' <span class="rbw-widget-source">%s</span>',
				esc_html( $latest->code )
			);
		}

		echo Frontend::wrap( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in wrap().
			$inner,
			isset( $settings['rbw_before'] ) ? (string) $settings['rbw_before'] : '',
			isset( $settings['rbw_after'] ) ? (string) $settings['rbw_after'] : '',
			$this->render_icon( $settings['rbw_icon'] ?? null ),
			'rbw-widget rbw-widget-site-updated'
		);
	}

	/**
	 * The enabled currency whose rate was updated most recently.
	 *
	 * @return Currency|null
	 */
	private function latest_currency(): ?Currency {
		$latest     = null;
		$latest_ts  = 0;

		foreach ( Frontend::resolve_currencies_all() as $currency ) {
			if ( ! $currency->has_rate() ) {
				continue;
			}

			$timestamp = Frontend::currency_timestamp( $currency );

			if ( $timestamp > $latest_ts ) {
				$latest_ts = $timestamp;
				$latest    = $currency;
			}
		}

		return $latest;
	}
}
