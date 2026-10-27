<?php
/**
 * Builds the JSON package from live data.
 *
 * Reads settings, currencies and formulas and produces the envelope described in
 * `Package`. Nothing credential-shaped is ever read, so nothing can leak.
 *
 * @package RateBridge
 */

namespace RateBridge\ImportExport;

use RateBridge\Core\Settings;
use RateBridge\Currency\Currency;
use RateBridge\Currency\CurrencyRepository;
use RateBridge\Formula\FormulaModel;
use RateBridge\Formula\FormulaRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles a package array for export (and for the automatic backup).
 */
final class Exporter {

	/**
	 * Build the package structure.
	 *
	 * @param string[] $sections Sections to include (settings|currencies|formulas).
	 * @return array<string, mixed>
	 */
	public function build( array $sections = array() ): array {
		$sections = array_values( array_intersect( Package::sections(), $sections ) );

		if ( empty( $sections ) ) {
			$sections = Package::sections();
		}

		$out = array(
			'format'         => Package::FORMAT,
			'schema'         => Package::SCHEMA,
			'plugin_version' => defined( 'RBW_VERSION' ) ? RBW_VERSION : '',
			'exported_at'    => gmdate( 'c' ),
			'store_unit'     => Package::unit( Settings::store_unit() ),
			'site'           => $this->site_host(),
			'sections'       => array(),
		);

		if ( in_array( 'settings', $sections, true ) ) {
			$out['sections']['settings'] = Package::clean_settings( Settings::all() );
		}

		if ( in_array( 'currencies', $sections, true ) ) {
			$out['sections']['currencies'] = $this->currencies();
		}

		if ( in_array( 'formulas', $sections, true ) ) {
			$out['sections']['formulas'] = $this->formulas();
		}

		return $out;
	}

	/**
	 * Pretty-printed JSON for download.
	 *
	 * @param string[] $sections Sections.
	 * @return string
	 */
	public function to_json( array $sections = array() ): string {
		$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

		$json = wp_json_encode( $this->build( $sections ), $flags );

		return false === $json ? '{}' : $json;
	}

	/**
	 * Currencies as configuration only (never rate/last_updated/last_error).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function currencies(): array {
		$out = array();

		foreach ( ( new CurrencyRepository() )->get_all() as $currency ) {
			if ( ! $currency instanceof Currency ) {
				continue;
			}

			$out[] = array(
				'code'                    => $currency->code,
				'name'                    => $currency->name,
				'symbol'                  => $currency->symbol,
				'provider'                => $currency->provider,
				'provider_symbol'         => $currency->provider_symbol,
				'update_interval_minutes' => $currency->update_interval_minutes,
				'is_enabled'              => $currency->is_enabled ? 1 : 0,
				'sort_order'              => $currency->sort_order,
				'profit_type'             => $currency->profit_type,
				'profit_value'            => $currency->profit_value,
				'commission_type'         => $currency->commission_type,
				'commission_value'        => $currency->commission_value,
				'extra_amount'            => $currency->extra_amount,
			);
		}

		return $out;
	}

	/**
	 * Formulas including their variable definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function formulas(): array {
		$out = array();

		foreach ( ( new FormulaRepository() )->get_all() as $formula ) {
			if ( ! $formula instanceof FormulaModel ) {
				continue;
			}

			$out[] = array(
				'slug'          => $formula->slug,
				'name'          => $formula->name,
				'description'   => $formula->description,
				'expression'    => $formula->expression,
				'variables'     => $formula->variables,
				'rounding_mode' => $formula->rounding_mode,
				'rounding_step' => $formula->rounding_step,
				'is_enabled'    => $formula->is_enabled ? 1 : 0,
			);
		}

		return $out;
	}

	/**
	 * Site host only (no scheme, no path, no query).
	 *
	 * @return string
	 */
	private function site_host(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) ? $host : '';
	}
}
