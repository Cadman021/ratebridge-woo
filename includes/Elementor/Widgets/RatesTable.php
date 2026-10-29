<?php
/**
 * Elementor widget: currencies table (جدول ارزها).
 *
 * @package RateBridge
 */

namespace RateBridge\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use RateBridge\Frontend\Frontend;
use RateBridge\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the same table markup as `[rbw_rates]`.
 */
final class RatesTable extends AbstractWidget {

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'rbw_rates_table';
	}

	/**
	 * Panel title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Currency table', 'ratebridge-woo' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-table';
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
			'rbw_title',
			array(
				'label'       => __( 'Title', 'ratebridge-woo' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'ai'          => array( 'active' => false ),
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
			'rbw_style_variant',
			array(
				'label'   => __( 'Style', 'ratebridge-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'minimal' => __( 'Minimal', 'ratebridge-woo' ),
					'striped' => __( 'Striped', 'ratebridge-woo' ),
					'cards'   => __( 'Cards', 'ratebridge-woo' ),
				),
				'default' => 'minimal',
			)
		);

		$this->add_control(
			'rbw_columns_heading',
			array(
				'label'     => __( 'Columns', 'ratebridge-woo' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'rbw_columns',
			array(
				'label'       => __( 'Visible columns', 'ratebridge-woo' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => Shortcodes::columns(),
				'default'     => array( 'name', 'rate', 'updated' ),
				'description' => __( 'The order of this list is the order of the columns.', 'ratebridge-woo' ),
			)
		);

		// Custom labels: one per supported column.
		foreach ( Shortcodes::columns() as $key => $label ) {
			$this->add_control(
				'rbw_label_' . $key,
				array(
					'label'       => sprintf(
						/* translators: %s: column name */
						__( 'Label: %s', 'ratebridge-woo' ),
						$label
					),
					'type'        => Controls_Manager::TEXT,
					'default'     => '',
					'label_block' => true,
					'placeholder' => $label,
					'ai'          => array( 'active' => false ),
				)
			);
		}

		$this->end_controls_section();

		$this->add_base_style_controls( '{{WRAPPER}} .rbw-rates-wrap', '{{WRAPPER}} .rbw-rates td' );

		$this->start_controls_section(
			'rbw_style_table',
			array(
				'label' => __( 'Table', 'ratebridge-woo' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'rbw_head_color',
			array(
				'label'     => __( 'Header text colour', 'ratebridge-woo' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .rbw-rates thead th' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'rbw_head_bg',
			array(
				'label'     => __( 'Header background', 'ratebridge-woo' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .rbw-rates thead th' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'rbw_row_color',
			array(
				'label'     => __( 'Row text colour', 'ratebridge-woo' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .rbw-rates tbody td' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'rbw_row_bg',
			array(
				'label'     => __( 'Row background', 'ratebridge-woo' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .rbw-rates tbody tr' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'rbw_odd_bg',
			array(
				'label'     => __( 'Odd row background', 'ratebridge-woo' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .rbw-rates tbody tr:nth-child(odd)' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'rbw_line_color',
			array(
				'label'     => __( 'Row line colour', 'ratebridge-woo' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .rbw-rates th, {{WRAPPER}} .rbw-rates td' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'rbw_cell_padding',
			array(
				'label'      => __( 'Cell padding', 'ratebridge-woo' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .rbw-rates th, {{WRAPPER}} .rbw-rates td' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'rbw_table_typography',
				'label'    => __( 'Table typography', 'ratebridge-woo' ),
				'selector' => '{{WRAPPER}} .rbw-rates',
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
			'rbw_title'      => '',
			'rbw_currencies' => array(),
			'rbw_columns'    => array( 'name', 'rate', 'updated' ),
			'rbw_style_variant' => 'minimal',
		);
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	protected function render(): void {
		$settings = $this->settings();

		$codes   = $this->to_codes( $settings['rbw_currencies'] ?? array() );
		$columns = $this->sanitize_columns( $settings['rbw_columns'] ?? array() );
		$title   = isset( $settings['rbw_title'] ) ? sanitize_text_field( (string) $settings['rbw_title'] ) : '';
		$style   = Shortcodes::table_style( $settings['rbw_style_variant'] ?? 'minimal' );

		$currencies = Frontend::resolve_currencies( $codes );

		if ( empty( $currencies ) ) {
			echo $this->placeholder( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in placeholder().
				__( 'No enabled currency with a rate is available yet. Enable a currency and give it a rate in the plugin settings.', 'ratebridge-woo' )
			);

			return;
		}

		$html = Frontend::cached(
			(string) wp_json_encode( array( 'elementor-table', $codes, $columns, $title, $style, $this->custom_labels( $settings ), Frontend::use_persian_digits() ) ),
			function () use ( $currencies, $columns, $title, $style, $settings ): string {
				return $this->shortcodes()->render_table( $currencies, $columns, $title, 'rbw-elementor-table', $style );
			}
		);

		echo $this->apply_labels( $html, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_html/wp_kses upstream.
	}

	/**
	 * Replace the default column headers with the custom labels.
	 *
	 * Runs on the already-escaped table markup, so the labels are escaped here.
	 *
	 * @param string               $html     Table HTML.
	 * @param array<string, mixed> $settings Widget settings.
	 * @return string
	 */
	private function apply_labels( string $html, array $settings ): string {
		$labels = $this->custom_labels( $settings );

		foreach ( $labels as $column => $label ) {
			if ( '' === $label ) {
				continue;
			}

			// Header cell.
			$html = preg_replace(
				'/<th scope="col" class="rbw-col-' . preg_quote( $column, '/' ) . '">(.*?)<\/th>/',
				'<th scope="col" class="rbw-col-' . $column . '">' . esc_html( $label ) . '</th>',
				$html,
				1
			) ?? $html;

			// The sticky label used by the mobile stacked layout.
			$html = str_replace(
				'data-label="' . esc_attr( Shortcodes::columns()[ $column ] ?? $column ) . '"',
				'data-label="' . esc_attr( $label ) . '"',
				$html
			);
		}

		return $html;
	}

	/**
	 * Custom labels keyed by column.
	 *
	 * @param array<string, mixed> $settings Widget settings.
	 * @return array<string, string>
	 */
	private function custom_labels( array $settings ): array {
		$labels = array();

		foreach ( array_keys( Shortcodes::columns() ) as $column ) {
			$key    = 'rbw_label_' . $column;
			$labels[ $column ] = isset( $settings[ $key ] ) ? sanitize_text_field( (string) $settings[ $key ] ) : '';
		}

		return $labels;
	}

	/**
	 * Keep only known column keys, in the given order.
	 *
	 * @param mixed $columns Raw control value.
	 * @return string[]
	 */
	private function sanitize_columns( mixed $columns ): array {
		$allowed = array_keys( Shortcodes::columns() );

		if ( is_string( $columns ) ) {
			$columns = preg_split( '/[\s,]+/', $columns );
		}

		if ( ! is_array( $columns ) || empty( $columns ) ) {
			return array( 'name', 'rate', 'updated' );
		}

		$out = array();

		foreach ( $columns as $column ) {
			$column = sanitize_key( (string) $column );

			if ( in_array( $column, $allowed, true ) && ! in_array( $column, $out, true ) ) {
				$out[] = $column;
			}
		}

		return empty( $out ) ? array( 'name', 'rate', 'updated' ) : $out;
	}
}
