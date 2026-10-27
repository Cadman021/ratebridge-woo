/**
 * RateBridge Woo - product editor (classic) script.
 *
 * Responsibilities:
 *  - show/hide the pricing section from the "Pricing by currency" checkbox;
 *  - make the native regular/sale price inputs read-only while managed;
 *  - sync the variation price inputs the same way;
 *  - ask the server for a live price preview (same calculator as the repricer).
 *
 * No build step: plain DOM APIs and fetch().
 */
( function () {
	'use strict';

	if ( typeof window.rbwProduct === 'undefined' ) {
		return;
	}

	var config = window.rbwProduct;
	var strings = config.strings || {};

	/**
	 * Query one element.
	 *
	 * @param {string}      selector CSS selector.
	 * @param {Element}     [root]   Root element.
	 * @return {Element|null} Element.
	 */
	function qs( selector, root ) {
		return ( root || document ).querySelector( selector );
	}

	/**
	 * Query all elements as an array.
	 *
	 * @param {string}  selector CSS selector.
	 * @param {Element} [root]   Root element.
	 * @return {Array} Elements.
	 */
	function qsa( selector, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( selector ) );
	}

	/**
	 * Escape text for HTML interpolation.
	 *
	 * @param {*} value Raw value.
	 * @return {string} Escaped text.
	 */
	function esc( value ) {
		return String( value === null || typeof value === 'undefined' ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	/**
	 * Extract an error message from a wp_send_json_error payload.
	 *
	 * @param {Object} response Parsed response.
	 * @return {string} Message.
	 */
	function messageFrom( response ) {
		if ( response && response.data && response.data.message ) {
			return response.data.message;
		}

		return strings.error || '';
	}

	/* ------------------------------------------------------------------ */
	/* Simple product                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Show or hide the managed section and lock the native price fields.
	 */
	function syncSimple() {
		var checkbox = qs( '#rbw_enabled' );
		var fields = qs( '#rbw-simple-fields' );
		var note = qs( '#rbw-managed-note' );
		var managed = !! ( checkbox && checkbox.checked );

		if ( fields ) {
			fields.hidden = ! managed;
		}

		if ( note ) {
			note.hidden = ! managed;
		}

		// Native price inputs are controlled by the currency while managed.
		lockPriceInputs( managed );

		syncMode();
	}

	/**
	 * Show the pricing-method panel that matches the selected mode.
	 */
	function syncMode() {
		var select = qs( '#rbw_mode' );
		var mode = select ? select.value : 'currency';

		var currencyBox = qs( '.rbw-mode-currency' );
		var formulaBox = qs( '.rbw-mode-formula' );

		if ( currencyBox ) {
			currencyBox.hidden = 'currency' !== mode;
		}

		if ( formulaBox ) {
			formulaBox.hidden = 'formula' !== mode;
		}
	}

	/**
	 * Make the native price inputs read-only (or editable again).
	 *
	 * @param {boolean} managed Whether pricing is managed by a currency.
	 */
	function lockPriceInputs( managed ) {
		qsa( '#_regular_price, #_sale_price' ).forEach( function ( input ) {
			input.readOnly = managed;
			input.classList.toggle( 'rbw-readonly', managed );

			if ( managed ) {
				input.setAttribute( 'title', strings.readOnlyHint || '' );
			} else {
				input.removeAttribute( 'title' );
			}
		} );
	}

	/**
	 * Lock the variation price inputs of a managed variable product.
	 */
	function syncVariations() {
		var checkbox = qs( '#rbw_enabled' );
		var managed = !! ( checkbox && checkbox.checked );

		qsa( '.woocommerce_variation' ).forEach( function ( row ) {
			var base = qs( '[data-rbw="variation-base"]', row );
			var lock = managed && base && !! base.value;

			qsa( '.short.wc_input_price', row ).forEach( function ( input ) {
				// The plugin's own inputs live inside .rbw-variation-fields.
				if ( input.closest( '.rbw-variation-fields' ) ) {
					return;
				}

				input.readOnly = lock;
				input.classList.toggle( 'rbw-readonly', lock );
			} );
		} );
	}

	/**
	 * Build the preview form body from the current (unsaved) fields.
	 *
	 * @return {URLSearchParams} Payload.
	 */
	function previewPayload() {
		var body = new URLSearchParams();

		body.append( 'action', 'rbw_' + config.action );
		body.append( 'nonce', config.nonce );

		// Formula mode sends its own fields; the preview endpoint understands both.
		var mode = ( qs( '#rbw_mode' ) || {} ).value || 'currency';

		body.append( 'mode', mode );

		if ( 'formula' === mode ) {
			var formulaSelect = qs( '#rbw_formula_id' );

			body.append( 'formula_id', formulaSelect ? formulaSelect.value : '' );

			qsa( '.rbw-formula-var-input' ).forEach( function ( input ) {
				body.append( 'formula_vars[' + input.getAttribute( 'name' ).replace( /^rbw_formula_vars\[/, '' ).replace( /\]$/, '' ) + ']', input.value );
			} );
		}

		var map = {
			currency: 'rbw_currency',
			base_price: 'rbw_base_price',
			sale_base_price: 'rbw_sale_base_price',
			min_price: 'rbw_min_price',
			fluctuation: 'rbw_fluctuation',
			profit_mode: 'rbw_profit_mode',
			rbw_profit_type: 'rbw_profit_type',
			profit_value: 'rbw_profit_value',
			rbw_commission_type: 'rbw_commission_type',
			commission_value: 'rbw_commission_value',
			extra_value: 'rbw_extra_value',
			product_id: 'post_ID'
		};

		Object.keys( map ).forEach( function ( key ) {
			var field = qs( '#' + map[ key ] );

			if ( field ) {
				body.append( key, field.value );
			}
		} );

		return body;
	}

	/**
	 * Render the breakdown into a target element.
	 *
	 * @param {Element} target Target element.
	 * @param {Object}  data   Response payload.
	 */
	function renderPreview( target, data ) {
		if ( ! target ) {
			return;
		}

		if ( ! data || ! data.ok ) {
			target.className = 'rbw-preview-output is-error';
			target.innerHTML = '<p>' + esc( ( data && data.message ) || strings.error ) + '</p>';
			return;
		}

		var rows = data.breakdown || [];
		var html = '<table class="rbw-preview-table"><tbody>';

		rows.forEach( function ( row ) {
			var isFinal = 'final' === row.key;
			html += '<tr' + ( isFinal ? ' class="rbw-preview-final"' : '' ) + '>';
			html += '<th scope="row">' + esc( row.label ) + '</th>';
			html += '<td dir="ltr">' + esc( row.value ) + '</td>';
			html += '</tr>';
		} );

		html += '</tbody></table>';

		var notes = [];

		if ( data.sale_price ) {
			notes.push( ( strings.saleFinal || '' ) + ': ' + data.sale_price_formatted + ' ' + ( data.unit || '' ) );
		}

		if ( data.sale_warning ) {
			notes.push( data.sale_warning );
		}

		if ( data.rules && data.rules.min_price_applied ) {
			notes.push( strings.minApplied );
		}

		if ( data.rules && data.rules.rounding_applied ) {
			notes.push( strings.roundingApplied );
		}

		if ( data.rules && data.rules.fluctuation_kept_previous ) {
			notes.push( strings.fluctuationKept );
		}

		if ( data.converted && data.converted.profit ) {
			notes.push( strings.convertedProfit );
		}

		if ( data.converted && data.converted.commission ) {
			notes.push( strings.convertedCommission );
		}

		if ( notes.length ) {
			html += '<ul class="rbw-preview-notes">';
			notes.forEach( function ( note ) {
				html += '<li>' + esc( note ) + '</li>';
			} );
			html += '</ul>';
		}

		target.className = 'rbw-preview-output' + ( data.stale ? ' is-stale' : '' );
		target.innerHTML = html;
	}

	/**
	 * Request a preview from the server.
	 *
	 * @param {Element} button Clicked button.
	 */
	function requestPreview( button ) {
		var targetId = button.getAttribute( 'data-target' );
		var target = qs( '#' + targetId );
		var previous = button.textContent;

		if ( ! qs( '#rbw_currency' ) || ! qs( '#rbw_currency' ).value ) {
			renderPreview( target, { ok: false, message: strings.noCurrency } );
			return;
		}

		button.disabled = true;
		button.textContent = strings.working;

		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: previewPayload().toString()
		} )
			.then( function ( response ) {
				return response.text().then( function ( text ) {
					try {
						return JSON.parse( text );
					} catch ( error ) {
						throw new Error( strings.error );
					}
				} );
			} )
			.then( function ( response ) {
				renderPreview( target, response.data || { ok: false, message: messageFrom( response ) } );
			} )
			.catch( function ( error ) {
				renderPreview( target, { ok: false, message: ( error && error.message ) || strings.error } );
			} )
			.finally( function () {
				button.disabled = false;
				button.textContent = previous;
			} );
	}

	/**
	 * Bind the simple-product section.
	 */
	function bindSimple() {
		var checkbox = qs( '#rbw_enabled' );

		if ( checkbox ) {
			checkbox.addEventListener( 'change', syncSimple );
		}

		var modeSelect = qs( '#rbw_mode' );

		if ( modeSelect ) {
			modeSelect.addEventListener( 'change', syncMode );
		}

		qsa( '.rbw-rule-type' ).forEach( function ( select ) {
			select.addEventListener( 'change', function () {
				var hint = qs( '.rbw-rule-hint[data-slot="' + select.getAttribute( 'data-slot' ) + '"]' );

				if ( hint ) {
					hint.textContent = 'fixed' === select.value ? ( config.unit || '' ) : '%';
				}
			} );
		} );

		qsa( '.rbw-preview-button' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				requestPreview( button );
			} );
		} );

		syncSimple();
	}

	/* ------------------------------------------------------------------ */
	/* Variations                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Re-apply the variation locks after WooCommerce re-rendered a row.
	 */
	function onVariationsLoaded() {
		syncVariations();
	}

	/**
	 * Bind the variation hooks WooCommerce exposes.
	 */
	function bindVariations() {
		window.jQuery( document.body ).on( 'woocommerce_variations_loaded woocommerce_variations_added', onVariationsLoaded );

		// A base price typed by the admin decides whether the row is locked.
		document.addEventListener( 'input', function ( event ) {
			if ( event.target && event.target.matches && event.target.matches( '[data-rbw="variation-base"]' ) ) {
				syncVariations();
			}
		} );

		onVariationsLoaded();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindSimple();

		if ( window.jQuery ) {
			bindVariations();
		}
	} );
}() );
