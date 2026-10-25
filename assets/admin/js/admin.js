/**
 * RateBridge Woo - admin script (vanilla JS, no build step).
 *
 * All state-changing calls go through admin-ajax.php with the rbwAdmin nonce
 * and are re-checked server-side for the manage_woocommerce capability.
 */
( function () {
	'use strict';

	if ( typeof window.rbwAdmin === 'undefined' ) {
		return;
	}

	var config = window.rbwAdmin;
	var strings = config.strings || {};

	function qs( selector, root ) {
		return ( root || document ).querySelector( selector );
	}

	function qsa( selector, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( selector ) );
	}

	/**
	 * Show a request error, and recover automatically when the session/nonce
	 * has expired (WordPress answers with a bare "0" in that case).
	 *
	 * @param {Error} error Error thrown by request().
	 */
	function handleRequestError( error ) {
		var message = ( error && error.message ) || strings.genericError;

		notify( message, 'error' );

		if ( message === strings.sessionExpired ) {
			// A stale nonce cannot be repaired client-side: reload once.
			window.setTimeout( function () {
				window.location.reload();
			}, 1500 );
		}
	}

	/**
	 * Show a notice above the tables.
	 *
	 * @param {string} message Message text.
	 * @param {string} type    success|error|info.
	 */
	function notify( message, type ) {
		var box = qs( '#rbw-notices' );
		if ( ! box || ! message ) {
			return;
		}

		var el = document.createElement( 'div' );
		el.className = 'rbw-notice is-' + ( type || 'info' );
		el.textContent = message;

		box.innerHTML = '';
		box.appendChild( el );

		window.setTimeout( function () {
			if ( el.parentNode === box ) {
				box.innerHTML = '';
			}
		}, 6000 );
	}

	/**
	 * Perform an AJAX request.
	 *
	 * Nested objects are flattened into `key[subkey]=value` pairs, which is how
	 * PHP expects them (`$_POST['key']['subkey']`). A plain String() cast would
	 * send "[object Object]" and the server would see nothing.
	 *
	 * @param {string} action  rbw_action value.
	 * @param {Object} payload Extra parameters.
	 * @return {Promise<Object>} Parsed JSON response.
	 */
	function request( action, payload ) {
		var body = new URLSearchParams();
		body.append( 'action', 'rbw_' + action );
		body.append( 'rbw_action', action );
		body.append( 'nonce', config.nonce );

		/**
		 * Append a value, recursing into plain objects and arrays.
		 *
		 * @param {string} key   Field name (already bracketed for nesting).
		 * @param {*}      value Value to append.
		 */
		function append( key, value ) {
			if ( value === true ) {
				body.append( key, '1' );
				return;
			}

			if ( value === false ) {
				body.append( key, '0' );
				return;
			}

			if ( value === null || typeof value === 'undefined' ) {
				body.append( key, '' );
				return;
			}

			if ( typeof value === 'object' ) {
				Object.keys( value ).forEach( function ( subkey ) {
					append( key + '[' + subkey + ']', value[ subkey ] );
				} );
				return;
			}

			body.append( key, String( value ) );
		}

		Object.keys( payload || {} ).forEach( function ( key ) {
			append( key, payload[ key ] );
		} );

		return window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} )
			.then( function ( response ) {
				// Read the body once as text so we can show what the server really
				// said when it is not JSON (e.g. a PHP fatal or a wp_die page).
				return response.text().then( function ( text ) {
					var parsed = null;

					try {
						parsed = JSON.parse( text );
					} catch ( parseError ) {
						// WordPress replies with a bare "0" when the nonce check fails.
						// Tell the user what to do instead of a generic error.
						if ( '0' === String( text ).trim() ) {
							throw new Error( strings.sessionExpired || strings.genericError );
						}

						var snippet = ( text || '' ).replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim().slice( 0, 220 );

						throw new Error(
							( strings.genericError || 'Error' ) +
							' [HTTP ' + response.status + ']' +
							( snippet ? ' ' + snippet : '' )
						);
					}

					return parsed;
				} );
			} );
	}

	function messageFrom( response, fallback ) {
		if ( response && response.data && response.data.message ) {
			return response.data.message;
		}
		return fallback || strings.genericError;
	}

	/* ------------------------------------------------------------------ */
	/* Currency row actions                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Bind row actions. Safe to call repeatedly: each element is bound once.
	 *
	 * @param {Element} [scope] Optional subtree to bind (defaults to document).
	 */
	function bindTable( scope ) {
		var root = scope || document;

		qsa( '.rbw-toggle', root ).forEach( function ( input ) {
			if ( input.getAttribute( 'data-rbw-bound' ) ) {
				return;
			}
			input.setAttribute( 'data-rbw-bound', '1' );

			input.addEventListener( 'change', function () {
				var id = input.getAttribute( 'data-id' );
				var enabled = input.checked;

				request( 'toggle', { id: id, enabled: enabled ? '1' : '0' } )
					.then( function ( response ) {
						if ( ! response.success ) {
							input.checked = ! enabled;
							notify( messageFrom( response ), 'error' );
							return;
						}
						notify( messageFrom( response ), 'success' );
					} )
					.catch( function ( error ) {
						input.checked = ! enabled;
						handleRequestError( error );
					} );
			} );
		} );

		qsa( '.rbw-delete', root ).forEach( function ( button ) {
			if ( button.getAttribute( 'data-rbw-bound' ) ) {
				return;
			}
			button.setAttribute( 'data-rbw-bound', '1' );

			button.addEventListener( 'click', function () {
				if ( ! window.confirm( strings.confirmDelete ) ) {
					return;
				}

				request( 'delete', { id: button.getAttribute( 'data-id' ) } )
					.then( function ( response ) {
						if ( ! response.success ) {
							notify( messageFrom( response ), 'error' );
							return;
						}

						var row = button.closest( 'tr' );
						if ( row ) {
							row.parentNode.removeChild( row );
						}
						notify( messageFrom( response ), 'success' );
						refreshLog();
					} )
					.catch( function ( error ) {
						handleRequestError( error );
					} );
			} );
		} );

		qsa( '.rbw-edit', root ).forEach( function ( button ) {
			if ( button.getAttribute( 'data-rbw-bound' ) ) {
				return;
			}
			button.setAttribute( 'data-rbw-bound', '1' );

			button.addEventListener( 'click', function () {
				var id = button.getAttribute( 'data-id' );

				request( 'get_currency', { id: id } )
					.then( function ( response ) {
						if ( ! response.success || ! response.data.currency ) {
							notify( messageFrom( response ), 'error' );
							return;
						}
						openModal( response.data.currency );
					} )
					.catch( function ( error ) {
						handleRequestError( error );
					} );
			} );
		} );

		qsa( '.rbw-update-one', root ).forEach( function ( button ) {
			if ( button.getAttribute( 'data-rbw-bound' ) ) {
				return;
			}
			button.setAttribute( 'data-rbw-bound', '1' );

			button.addEventListener( 'click', function () {
				runUpdate( button, 'update_now', { id: button.getAttribute( 'data-id' ) } );
			} );
		} );
	}

	function runUpdate( button, action, payload ) {
		var previous = button.textContent;
		button.disabled = true;
		button.textContent = strings.working;

		request( action, payload )
			.then( function ( response ) {
				notify( messageFrom( response ), response.success ? 'success' : 'error' );
				updateRow( response.data );
				refreshLog();
			} )
			.catch( function ( error ) {
				handleRequestError( error );
			} )
			.finally( function () {
				button.disabled = false;
				button.textContent = previous;
			} );
	}

	/**
	 * Patch one or more rows with fresh data returned by the server.
	 *
	 * Updates existing rows in place and inserts new rows, so the page never
	 * needs a full reload (which used to race with in-flight AJAX calls and
	 * produce a stale-nonce "0" response).
	 *
	 * @param {Object} data Response payload.
	 */
	function updateRow( data ) {
		if ( ! data ) {
			return;
		}

		var list = data.currencies || ( data.currency ? [ data.currency ] : [] );

		list.forEach( function ( currency ) {
			var row = qs( 'tr[data-id="' + currency.id + '"]' );

			if ( ! row ) {
				appendCurrencyRow( currency );
				return;
			}

			var rateCell = qs( '.rbw-rate', row );
			if ( rateCell ) {
				rateCell.textContent = currency.rate_formatted;
				rateCell.setAttribute( 'data-rate', currency.rate );
			}

			var updatedCell = qs( '.rbw-last-updated', row );
			if ( updatedCell ) {
				updatedCell.textContent = currency.last_updated;
			}

			// Provider label can change when the row is edited.
			var providerCell = qs( 'td:nth-child(6)', row );
			if ( providerCell && currency.provider ) {
				providerCell.textContent = ( config.providerLabels && config.providerLabels[ currency.provider ] ) || currency.provider;
			}

			var intervalCell = qs( 'td:nth-child(7)', row );
			if ( intervalCell && currency.update_interval_minutes ) {
				intervalCell.textContent = currency.update_interval_minutes;
			}

			var codeCell = qs( 'td:nth-child(3) code', row );
			if ( codeCell && currency.code ) {
				codeCell.textContent = currency.code;
			}

			var nameCell = qs( 'td:nth-child(2)', row );

			if ( nameCell && currency.name ) {
				var strong = qs( 'strong', nameCell );
				if ( strong ) {
					strong.textContent = currency.name;
				}
			}

			var oldError = qs( '.rbw-error-text', row );
			if ( oldError ) {
				oldError.parentNode.removeChild( oldError );
			}

			if ( currency.last_error && nameCell ) {
				var errorEl = document.createElement( 'div' );
				errorEl.className = 'rbw-error-text';
				errorEl.textContent = currency.last_error;
				nameCell.appendChild( errorEl );
			}

			var toggle = qs( '.rbw-toggle', row );
			if ( toggle ) {
				toggle.checked = !! currency.is_enabled;
			}
		} );
	}

	/**
	 * Insert a freshly created currency as a new table row.
	 *
	 * @param {Object} currency Currency payload from the server.
	 */
	function appendCurrencyRow( currency ) {
		var tbody = qs( '#rbw-currencies-table tbody' );
		if ( ! tbody || ! currency || ! currency.id ) {
			return;
		}

		// Drop the "no currencies yet" placeholder if present.
		var empty = qs( '.rbw-empty-row', tbody );
		if ( empty ) {
			empty.parentNode.removeChild( empty );
		}

		var providerLabel = ( config.providerLabels && config.providerLabels[ currency.provider ] ) || currency.provider;
		var tr = document.createElement( 'tr' );
		tr.setAttribute( 'data-id', currency.id );

		tr.innerHTML =
			'<td><label class="rbw-switch"><input type="checkbox" class="rbw-toggle" data-id="' + currency.id + '"' + ( currency.is_enabled ? ' checked' : '' ) + ' /></label></td>' +
			'<td><strong></strong> <span class="rbw-muted"></span></td>' +
			'<td><code></code></td>' +
			'<td class="rbw-rate"></td>' +
			'<td class="rbw-last-updated"></td>' +
			'<td class="rbw-provider"></td>' +
			'<td class="rbw-col-interval"></td>' +
			'<td class="rbw-actions">' +
				'<button type="button" class="button button-small rbw-edit">Edit</button> ' +
				'<button type="button" class="button button-small rbw-update-one">Update now</button> ' +
				'<button type="button" class="button button-small button-link-delete rbw-delete">Delete</button>' +
			'</td>';

		// Fill text via textContent so provider data can never inject markup.
		qs( 'strong', tr ).textContent = currency.name || '';
		qs( '.rbw-muted', tr ).textContent = currency.symbol ? '(' + currency.symbol + ')' : '';
		qs( 'code', tr ).textContent = currency.code || '';
		qs( '.rbw-rate', tr ).textContent = currency.rate_formatted || '—';
		qs( '.rbw-last-updated', tr ).textContent = currency.last_updated || '—';
		qs( '.rbw-provider', tr ).textContent = providerLabel;
		qs( '.rbw-col-interval', tr ).textContent = currency.update_interval_minutes || 60;

		qsa( '[data-id]', tr ).forEach( function ( el ) {
			el.setAttribute( 'data-id', currency.id );
		} );

		tbody.appendChild( tr );

		// Bind only the new row's actions (existing rows are already bound).
		bindTable( tr );
	}

	/* ------------------------------------------------------------------ */
	/* Modal                                                              */
	/* ------------------------------------------------------------------ */

	function openModal( currency ) {
		var modal = qs( '#rbw-currency-modal' );
		if ( ! modal ) {
			return;
		}

		var isEdit = currency && currency.id;

		qs( '#rbw-modal-title' ).textContent = isEdit ? strings.editTitle : strings.addTitle;

		modal.querySelector( '[name="id"]' ).value = isEdit ? currency.id : '0';
		modal.querySelector( '[name="name"]' ).value = isEdit ? currency.name : '';
		modal.querySelector( '[name="code"]' ).value = isEdit ? currency.code : '';
		modal.querySelector( '[name="code"]' ).disabled = !! isEdit;
		modal.querySelector( '[name="symbol"]' ).value = isEdit ? currency.symbol : '';
		modal.querySelector( '[name="provider"]' ).value = isEdit ? currency.provider : 'manual';

		// Reset the symbol picker, then let it restore the stored value.
		refreshSymbolPicker( isEdit ? currency.provider : 'manual', isEdit ? currency.provider_symbol : '' );
		modal.querySelector( '[name="rate"]' ).value = isEdit && currency.rate > 0 ? String( currency.rate ) : '';
		modal.querySelector( '[name="rate"]' ).placeholder = isEdit ? strings.emptyRate : '';
		modal.querySelector( '[name="update_interval_minutes"]' ).value = isEdit ? currency.update_interval_minutes : ( config.minInterval || 60 );
		modal.querySelector( '[name="sort_order"]' ).value = isEdit ? currency.sort_order : 0;
		modal.querySelector( '[name="is_enabled"]' ).checked = isEdit ? !! currency.is_enabled : false;

		modal.hidden = false;

		// Warn when the row interval is shorter than the source can serve.
		checkIntervalWarning( isEdit ? currency.provider : 'manual', Number( modal.querySelector( '[name="update_interval_minutes"]' ).value ) || 0 );

		var firstField = modal.querySelector( '[name="name"]' );
		if ( firstField ) {
			firstField.focus();
		}
	}

	function closeModal() {
		var modal = qs( '#rbw-currency-modal' );
		if ( modal ) {
			modal.hidden = true;
		}
	}

	function bindModal() {
		var modal = qs( '#rbw-currency-modal' );
		if ( ! modal ) {
			return;
		}

		qsa( '[data-rbw-close]', modal ).forEach( function ( el ) {
			el.addEventListener( 'click', closeModal );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! modal.hidden ) {
				closeModal();
			}
		} );

		var form = qs( '#rbw-currency-form' );

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();

				var button = qs( '#rbw-save-currency' );
				var payload = {
					id: form.querySelector( '[name="id"]' ).value,
					name: form.querySelector( '[name="name"]' ).value,
					code: form.querySelector( '[name="code"]' ).value,
					symbol: form.querySelector( '[name="symbol"]' ).value,
					provider: form.querySelector( '[name="provider"]' ).value,
					provider_symbol: form.querySelector( '[name="provider_symbol"]' ).value,
					rate: form.querySelector( '[name="rate"]' ).value,
					update_interval_minutes: form.querySelector( '[name="update_interval_minutes"]' ).value,
					sort_order: form.querySelector( '[name="sort_order"]' ).value,
					is_enabled: form.querySelector( '[name="is_enabled"]' ).checked ? '1' : '0'
				};

				if ( button ) {
					button.disabled = true;
					button.textContent = strings.saving;
				}

				request( 'save', payload )
					.then( function ( response ) {
						if ( ! response.success ) {
							notify( messageFrom( response ), 'error' );
							return;
						}

						notify( messageFrom( response ), 'success' );
						closeModal();

						// Patch the row in place. A full reload here raced with
						// in-flight AJAX calls (stale nonce -> bare "0" response).
						if ( response.data && response.data.currency ) {
							updateRow( { currencies: [ response.data.currency ] } );
						} else {
							window.location.reload();
						}
					} )
					.catch( function ( error ) {
						handleRequestError( error );
					} )
					.finally( function () {
						if ( button ) {
							button.disabled = false;
							button.textContent = 'Save';
						}
					} );
			} );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Log                                                                */
	/* ------------------------------------------------------------------ */

	function renderLog( entries ) {
		var tbody = qs( '#rbw-log-table tbody' );
		if ( ! tbody ) {
			return;
		}

		tbody.innerHTML = '';

		if ( ! entries || ! entries.length ) {
			var empty = document.createElement( 'tr' );
			var emptyCell = document.createElement( 'td' );
			emptyCell.colSpan = 4;
			emptyCell.textContent = '—';
			empty.appendChild( emptyCell );
			tbody.appendChild( empty );
			return;
		}

		entries.forEach( function ( entry ) {
			var tr = document.createElement( 'tr' );

			[ entry.time, null, entry.message, entry.trigger === 'manual' ? 'Manual' : 'Cron' ].forEach( function ( value, index ) {
				var td = document.createElement( 'td' );

				if ( 1 === index ) {
					var badge = document.createElement( 'span' );
					badge.className = 'rbw-badge rbw-badge-' + ( entry.status === 'success' ? 'success' : 'error' );
					badge.textContent = entry.status === 'success' ? 'OK' : '!';
					td.appendChild( badge );
				} else {
					td.textContent = value || '';
				}

				tr.appendChild( td );
			} );

			tbody.appendChild( tr );
		} );
	}

	function refreshLog() {
		request( 'get_log', {} )
			.then( function ( response ) {
				if ( response.success && response.data.entries ) {
					renderLog( response.data.entries );
				}
			} )
			.catch( function () {} );
	}

	/* ------------------------------------------------------------------ */
	/* General settings                                                   */
	/* ------------------------------------------------------------------ */

	function roundingPreview() {
		var output = qs( '#rbw-rounding-preview-value' );
		var modeInput = qs( '#rbw-rounding-mode' );
		var stepInput = qs( '#rbw-rounding-step' );

		if ( ! output || ! modeInput || ! stepInput ) {
			return;
		}

		var sample = 1234567;
		var step = Math.max( 1, parseInt( stepInput.value, 10 ) || 1 );
		var mode = modeInput.value;
		var result = sample;

		if ( 'nearest' === mode ) {
			result = Math.round( sample / step ) * step;
		} else if ( 'ceil' === mode ) {
			result = Math.ceil( sample / step ) * step;
		} else if ( 'floor' === mode ) {
			result = Math.floor( sample / step ) * step;
		}

		output.textContent = result.toLocaleString();
	}

	function bindGeneral() {
		var form = qs( '#rbw-general-form' );
		if ( ! form ) {
			return;
		}

		[ '#rbw-rounding-mode', '#rbw-rounding-step' ].forEach( function ( selector ) {
			var el = qs( selector );
			if ( el ) {
				el.addEventListener( 'change', roundingPreview );
				el.addEventListener( 'input', roundingPreview );
			}
		} );

		roundingPreview();

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var button = qs( '#rbw-save-settings' );
			var payload = {
				store_currency_unit: ( qs( 'input[name="store_currency_unit"]:checked' ) || {} ).value || 'toman',
				rounding_mode: qs( '#rbw-rounding-mode' ).value,
				rounding_step: qs( '#rbw-rounding-step' ).value,
				default_update_interval: qs( '#rbw-default-interval' ).value,
				delete_data_on_uninstall: form.querySelector( '[name="delete_data_on_uninstall"]' ).checked ? '1' : '0'
			};

			if ( button ) {
				button.disabled = true;
				button.textContent = strings.saving;
			}

			request( 'save_settings', payload )
				.then( function ( response ) {
					notify( messageFrom( response ), response.success ? 'success' : 'error' );
				} )
				.catch( function ( error ) {
					handleRequestError( error );
				} )
				.finally( function () {
					if ( button ) {
						button.disabled = false;
						button.textContent = 'Save settings';
					}
				} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Toolbar                                                            */
	/* ------------------------------------------------------------------ */

	function bindToolbar() {
		var addButton = qs( '#rbw-add-currency' );
		if ( addButton ) {
			addButton.addEventListener( 'click', function () {
				openModal( null );
			} );
		}

		var updateAll = qs( '#rbw-update-all' );
		if ( updateAll ) {
			updateAll.addEventListener( 'click', function () {
				runUpdate( updateAll, 'update_all', {} );
			} );
		}

		var refreshButton = qs( '#rbw-refresh-log' );
		if ( refreshButton ) {
			refreshButton.addEventListener( 'click', refreshLog );
		}

		var clearButton = qs( '#rbw-clear-log' );
		if ( clearButton ) {
			clearButton.addEventListener( 'click', function () {
				if ( ! window.confirm( strings.confirmClear ) ) {
					return;
				}

				request( 'clear_log', {} )
					.then( function ( response ) {
						notify( messageFrom( response ), response.success ? 'success' : 'error' );
						renderLog( [] );
					} )
					.catch( function ( error ) {
						handleRequestError( error );
					} );
			} );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Symbol picker (searchable preset list + free text)                  */
	/* ------------------------------------------------------------------ */

	var providerEffMin = config.providerEffMin || {};

	/**
	 * Build the option list for a provider, honouring the search filter.
	 *
	 * @param {string} provider Provider key.
	 * @param {string} search   Search text.
	 * @return {Array} List of {value,label}.
	 */
	function symbolOptions( provider, search ) {
		var map = ( config.symbols && config.symbols[ provider ] ) || {};
		var term = ( search || '' ).toLowerCase().trim();
		var out = [];

		Object.keys( map ).forEach( function ( key ) {
			var label = String( map[ key ] || '' );

			if ( term && key.toLowerCase().indexOf( term ) === -1 && label.toLowerCase().indexOf( term ) === -1 ) {
				return;
			}

			out.push( { value: key, label: label + ' — ' + key } );
		} );

		return out;
	}

	/**
	 * Fill a <select> from a provider's known symbols.
	 *
	 * @param {HTMLElement} select   Target select.
	 * @param {string}      provider Provider key.
	 * @param {string}      current  Currently selected value.
	 * @param {string}      search   Search filter.
	 */
	function fillSymbolSelect( select, provider, current, search ) {
		if ( ! select ) {
			return;
		}

		var options = symbolOptions( provider, search );
		select.innerHTML = '';

		var custom = document.createElement( 'option' );
		custom.value = '';
		custom.textContent = strings.customSymbol;
		select.appendChild( custom );

		options.forEach( function ( item ) {
			var opt = document.createElement( 'option' );
			opt.value = item.value;
			opt.textContent = item.label;
			if ( item.value === current ) {
				opt.selected = true;
			}
			select.appendChild( opt );
		} );

		// Keep a stored symbol visible even when it is not in the preset list.
		if ( current && ! options.some( function ( o ) { return o.value === current; } ) ) {
			var extra = document.createElement( 'option' );
			extra.value = current;
			extra.textContent = current;
			extra.selected = true;
			select.appendChild( extra );
		}
	}

	/**
	 * Rebuild the provider-symbol controls for the current provider.
	 *
	 * @param {string} provider Provider key.
	 * @param {string} current  Current symbol.
	 */
	function refreshSymbolPicker( provider, current ) {
		var select = qs( '#rbw-field-provider-symbol' );
		var text = qs( '#rbw-field-provider-symbol-text' );
		var search = qs( '#rbw-symbol-search' );
		var hint = qs( '#rbw-symbol-hint' );

		if ( ! select || ! text ) {
			return;
		}

		var map = ( config.symbols && config.symbols[ provider ] ) || {};
		var hasSymbols = Object.keys( map ).length > 0;

		select.hidden = ! hasSymbols;
		if ( search ) {
			search.hidden = ! hasSymbols;
			search.value = '';
		}

		if ( hasSymbols ) {
			fillSymbolSelect( select, provider, current, '' );
			select.value = map[ current ] !== undefined ? current : '';
			text.value = map[ current ] !== undefined ? '' : ( current || '' );
			if ( hint ) {
				hint.textContent = strings.searchSymbol;
			}
		} else {
			select.innerHTML = '';
			text.value = current || '';
			if ( hint ) {
				hint.textContent = strings.noSymbols;
			}
		}
	}

	/**
	 * Current provider symbol from whichever control is active.
	 *
	 * @return {string}
	 */
	function currentProviderSymbol() {
		var select = qs( '#rbw-field-provider-symbol' );
		var text = qs( '#rbw-field-provider-symbol-text' );

		if ( select && ! select.hidden && select.value ) {
			return select.value;
		}

		return text ? text.value.trim() : '';
	}

	function bindSymbolPicker() {
		var providerSelect = qs( '#rbw-field-provider' );
		var select = qs( '#rbw-field-provider-symbol' );
		var search = qs( '#rbw-symbol-search' );

		if ( providerSelect ) {
			providerSelect.addEventListener( 'change', function () {
				refreshSymbolPicker( providerSelect.value, '' );
				var intervalField = qs( '#rbw-field-interval' );
				checkIntervalWarning( providerSelect.value, intervalField ? Number( intervalField.value ) : 0 );
			} );
		}

		var intervalField = qs( '#rbw-field-interval' );
		if ( intervalField ) {
			intervalField.addEventListener( 'input', function () {
				checkIntervalWarning( providerSelect ? providerSelect.value : '', Number( intervalField.value ) || 0 );
			} );
		}

		if ( search && select ) {
			search.addEventListener( 'input', function () {
				fillSymbolSelect( select, providerSelect ? providerSelect.value : '', select.value, search.value );
			} );
		}

		if ( select ) {
			select.addEventListener( 'change', function () {
				autofillFromSymbol( providerSelect ? providerSelect.value : '', select.value );
			} );
		}
	}

	/**
	 * Fill code/name/symbol when the chosen preset leaves them empty.
	 *
	 * @param {string} provider Provider key.
	 * @param {string} symbol   Chosen symbol.
	 */
	function autofillFromSymbol( provider, symbol ) {
		if ( ! symbol ) {
			return;
		}

		var modal = qs( '#rbw-currency-modal' );
		if ( ! modal ) {
			return;
		}

		var map = ( config.symbols && config.symbols[ provider ] ) || {};
		var label = map[ symbol ];
		var codeField = modal.querySelector( '[name="code"]' );
		var nameField = modal.querySelector( '[name="name"]' );
		var symbolField = modal.querySelector( '[name="symbol"]' );

		// Derive an ASCII-ish code from the symbol: `gold:sekkeh` -> SEKKEH.
		var bare = symbol.indexOf( ':' ) !== -1 ? symbol.split( ':' )[ 1 ] : symbol;
		var code = bare.toUpperCase().replace( /[^A-Z0-9]/g, '' ).slice( 0, 32 );

		if ( codeField && ! codeField.value ) {
			codeField.value = code;
		}

		if ( nameField && ! nameField.value && label ) {
			nameField.value = label;
		}

		if ( symbolField && ! symbolField.value && label ) {
			symbolField.value = bare.toLowerCase();
		}
	}

	/**
	 * Warn when the chosen interval is shorter than the source's effective minimum.
	 *
	 * @param {string} provider Provider key.
	 * @param {number} interval Chosen interval.
	 */
	function checkIntervalWarning( provider, interval ) {
		var box = qs( '#rbw-interval-warning' );
		var effective = providerEffMin[ provider ] || 0;

		if ( ! box ) {
			return;
		}

		if ( effective > 0 && interval > 0 && interval < effective ) {
			box.hidden = false;
			box.textContent = ( strings.intervalTooShort || '' )
				.replace( '%1$d', interval )
				.replace( '%2$d', effective );
		} else {
			box.hidden = true;
			box.textContent = '';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Add from presets                                                    */
	/* ------------------------------------------------------------------ */

	function presetProviders() {
		var out = [];
		var map = config.symbols || {};

		Object.keys( map ).forEach( function ( key ) {
			if ( Object.keys( map[ key ] ).length ) {
				out.push( key );
			}
		} );

		return out;
	}

	function fillPresetList() {
		var providerSelect = qs( '#rbw-preset-provider' );
		var list = qs( '#rbw-preset-symbol' );
		var search = qs( '#rbw-preset-symbol-search' );

		if ( ! providerSelect || ! list ) {
			return;
		}

		var map = ( config.symbols && config.symbols[ providerSelect.value ] ) || {};
		var term = ( search ? search.value : '' ).toLowerCase().trim();

		list.innerHTML = '';

		Object.keys( map ).forEach( function ( key ) {
			var label = String( map[ key ] || '' );

			if ( term && key.toLowerCase().indexOf( term ) === -1 && label.toLowerCase().indexOf( term ) === -1 ) {
				return;
			}

			var opt = document.createElement( 'option' );
			opt.value = key;
			opt.textContent = label + ' — ' + key;
			list.appendChild( opt );
		} );
	}

	function bindPresets() {
		var openButton = qs( '#rbw-add-preset' );
		var modal = qs( '#rbw-preset-modal' );

		if ( ! openButton || ! modal ) {
			return;
		}

		openButton.addEventListener( 'click', function () {
			var providerSelect = qs( '#rbw-preset-provider' );
			if ( providerSelect && ! providerSelect.value ) {
				var available = presetProviders();
				if ( available.length ) {
					providerSelect.value = available[ 0 ];
				}
			}
			fillPresetList();
			modal.hidden = false;
		} );

		qsa( '[data-rbw-close]', modal ).forEach( function ( el ) {
			el.addEventListener( 'click', function () {
				modal.hidden = true;
			} );
		} );

		var providerSelect = qs( '#rbw-preset-provider' );
		if ( providerSelect ) {
			providerSelect.addEventListener( 'change', fillPresetList );
		}

		var search = qs( '#rbw-preset-symbol-search' );
		if ( search ) {
			search.addEventListener( 'input', fillPresetList );
		}

		var form = qs( '#rbw-preset-form' );
		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();

				var list = qs( '#rbw-preset-symbol' );
				var selected = list ? list.value : '';

				if ( ! selected ) {
					notify( strings.noSymbols, 'error' );
					return;
				}

				var provider = providerSelect.value;
				var map = ( config.symbols && config.symbols[ provider ] ) || {};
				var label = map[ selected ] || selected;
				var bare = selected.indexOf( ':' ) !== -1 ? selected.split( ':' )[ 1 ] : selected;
				var button = qs( '#rbw-preset-submit' );

				if ( button ) {
					button.disabled = true;
					button.textContent = strings.saving;
				}

				request( 'add_preset', {
					provider: provider,
					symbol: selected,
					code: bare.toUpperCase().replace( /[^A-Z0-9]/g, '' ).slice( 0, 32 ),
					name: label
				} )
					.then( function ( response ) {
						if ( ! response.success ) {
							notify( messageFrom( response ), 'error' );
							return;
						}

						notify( messageFrom( response ), 'success' );
						modal.hidden = true;

						// Append the new row instead of reloading (a reload raced with
						// in-flight AJAX and produced stale-nonce errors).
						if ( response.data && response.data.currency ) {
							appendCurrencyRow( response.data.currency );
						} else {
							window.location.reload();
						}
					} )
					.catch( function ( error ) {
						handleRequestError( error );
					} )
					.finally( function () {
						if ( button ) {
							button.disabled = false;
							button.textContent = 'Add';
						}
					} );
			} );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Price sources tab                                                   */
	/* ------------------------------------------------------------------ */

	function renderTestResult( provider, ok, data ) {
		var box = qs( '.rbw-test-result[data-provider="' + provider + '"]' );
		if ( ! box ) {
			return;
		}

		var details = ( data && data.details ) || {};
		var lines = [];

		lines.push( ( ok ? strings.testSuccess : strings.testFailed ) );

		if ( undefined !== data.http_status && 0 !== Number( data.http_status ) ) {
			lines.push( 'HTTP: ' + data.http_status );
		}

		if ( undefined !== data.parsed ) {
			lines.push( ( strings.parsedCount || 'Parsed: %d' ).replace( '%d', data.parsed ) );
		}

		if ( data.sample && Object.keys( data.sample ).length ) {
			var pairs = Object.keys( data.sample ).map( function ( key ) {
				return key + ' = ' + data.sample[ key ];
			} );
			lines.push( strings.samplePrefix + ' ' + pairs.join( ', ' ) );
		}

		if ( ! ok ) {
			lines.push( messageFrom( data ) );
		}

		box.hidden = false;
		box.className = 'rbw-test-result ' + ( ok ? 'is-success' : 'is-error' );
		box.textContent = lines.join( ' · ' );
	}

	function bindSources() {
		qsa( '.rbw-provider-form' ).forEach( function ( form ) {
			var provider = form.getAttribute( 'data-provider' );

			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();

				var button = form.querySelector( '.rbw-save-provider' );
				var body = {};

				qsa( '[name]', form ).forEach( function ( input ) {
					if ( 'checkbox' === input.type ) {
						body[ input.name ] = input.checked ? '1' : '0';
					} else if ( 'password' === input.type ) {
						// Only send when the admin typed something (empty keeps the old secret).
						if ( input.value ) {
							body[ input.name ] = input.value;
						}
					} else {
						body[ input.name ] = input.value;
					}
				} );

				if ( button ) {
					button.disabled = true;
					button.textContent = strings.saving;
				}

				request( 'save_provider_settings', { provider: provider, fields: body } )
					.then( function ( response ) {
						notify( messageFrom( response ), response.success ? 'success' : 'error' );

						qsa( '[type="password"]', form ).forEach( function ( input ) {
							input.value = '';
						} );

						if ( response.success && response.data.card ) {
							applyBudget( provider, response.data.card );
						}
					} )
					.catch( function ( error ) {
						handleRequestError( error );
					} )
					.finally( function () {
						if ( button ) {
							button.disabled = false;
							button.textContent = 'Save';
						}
					} );
			} );
		} );

		qsa( '.rbw-test-provider' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var provider = button.getAttribute( 'data-provider' );
				var previous = button.textContent;

				button.disabled = true;
				button.textContent = strings.working;

				request( 'test_provider', { provider: provider } )
					.then( function ( response ) {
						var data = response.data || {};
						if ( response.success ) {
							renderTestResult( provider, true, data );
							if ( data.budget ) {
								applyBudget( provider, { budget: data.budget } );
							}
						} else {
							data.message = messageFrom( response );
							renderTestResult( provider, false, data );
						}
					} )
					.catch( function ( error ) {
						renderTestResult( provider, false, { message: error.message } );
					} )
					.finally( function () {
						button.disabled = false;
						button.textContent = previous;
					} );
			} );
		} );

		qsa( '.rbw-reset-budget' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				if ( ! window.confirm( strings.confirmBudgetReset ) ) {
					return;
				}

				var provider = button.getAttribute( 'data-provider' );

				request( 'reset_budget', { provider: provider } )
					.then( function ( response ) {
						notify( messageFrom( response ), response.success ? 'success' : 'error' );
						if ( response.success && response.data.card ) {
							applyBudget( provider, response.data.card );
						}
					} )
					.catch( function ( error ) {
						handleRequestError( error );
					} );
			} );
		} );

		// Remember effective minimums so the currency dialog can warn.
		qsa( '.rbw-card' ).forEach( function ( card ) {
			var provider = card.getAttribute( 'data-provider' );
			var meter = card.querySelector( '.rbw-budget-list' );
			if ( meter && ! providerEffMin[ provider ] ) {
				providerEffMin[ provider ] = 0;
			}
		} );
	}

	/**
	 * Update the quota block in a provider card after an AJAX call.
	 *
	 * @param {string} provider Provider key.
	 * @param {Object} card     Card model from the server.
	 */
	function applyBudget( provider, card ) {
		var budget = card && card.budget;
		var cardEl = qs( '.rbw-card[data-provider="' + provider + '"]' );

		if ( ! budget || ! cardEl ) {
			return;
		}

		providerEffMin[ provider ] = Number( budget.effective ) || 0;

		var list = cardEl.querySelector( '.rbw-budget-list' );
		if ( ! list ) {
			return;
		}

		var items = list.querySelectorAll( 'li strong' );
		var texts = [
			budget.limit > 0 ? budget.used + ' / ' + budget.limit : String( budget.used ),
			budget.last_request_fmt || '—',
			budget.next_allowed_fmt || '—',
			( budget.min_interval || 0 ) + ' / ' + ( budget.effective || 0 )
		];

		for ( var i = 0; i < items.length && i < texts.length; i++ ) {
			items[ i ].textContent = texts[ i ];
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindToolbar();
		bindTable();
		bindModal();
		bindGeneral();
		bindSymbolPicker();
		bindPresets();
		bindSources();
	} );
}() );
