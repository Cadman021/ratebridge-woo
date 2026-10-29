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
	 * Escape text for safe interpolation into an HTML string.
	 *
	 * @param {string} value Raw text.
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
	 * Wrap an LTR fragment (date, number, code) in a bdi isolate.
	 *
	 * Mirrors RateBridge\Frontend\Bidi::isolate() on the PHP side: a bare
	 * `2026-10-29 17:23` or `(1,234,567)` inside a Persian sentence is re-ordered by
	 * the Unicode bidi algorithm, and the surrounding brackets jump to the wrong
	 * side. `<bdi dir="ltr">` pins the fragment's direction and isolates it from
	 * the paragraph, so the punctuation around it stays where it was typed.
	 *
	 * @param {string} value  Raw text (escaped here).
	 * @param {string} [tag]  Wrapper tag name, default 'bdi'.
	 * @return {string} Safe HTML fragment.
	 */
	function bdi( value, tag ) {
		var name = tag || 'bdi';

		if ( value === null || typeof value === 'undefined' || value === '' ) {
			return '';
		}

		return '<' + name + ' dir="ltr">' + esc( value ) + '</' + name + '>';
	}

	/**
	 * Format a number for display with grouping, optionally in Persian digits.
	 *
	 * @param {number} value    Value.
	 * @param {number} decimals Fraction digits (default 0).
	 * @return {string} Grouped number string.
	 */
	function formatNumber( value, decimals ) {
		var digits = typeof decimals === 'number' ? decimals : 0;
		var text = Number( value ).toLocaleString( 'en-US', {
			minimumFractionDigits: digits,
			maximumFractionDigits: digits
		} );

		if ( config.usePersianDigits ) {
			text = text.replace( /[0-9]/g, function ( digit ) {
				return String.fromCharCode( 0x06f0 + parseInt( digit, 10 ) );
			} );
		}

		return text;
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

		// Never reload automatically: the user decides when to refresh.
	}

	/**
	 * Show an error inside a dialog (the page-level notice is behind the overlay).
	 *
	 * @param {string} id      Element id of the error box.
	 * @param {string} message Message text.
	 */
	function showModalError( id, message ) {
		var box = qs( '#' + id );
		if ( ! box ) {
			return;
		}
		box.textContent = message || '';
		box.hidden = ! message;
	}

	/**
	 * Clear a dialog's inline error.
	 *
	 * @param {string} id Element id of the error box.
	 */
	function clearModalError( id ) {
		showModalError( id, '' );
	}

	/**
	 * Toast stack host (created once).
	 *
	 * @return {HTMLElement} Container element.
	 */
	function toastHost() {
		var host = qs( '#rbw-toasts' );

		if ( host ) {
			return host;
		}

		host = document.createElement( 'div' );
		host.id = 'rbw-toasts';
		host.className = 'rbw-toasts';
		host.setAttribute( 'role', 'status' );
		host.setAttribute( 'aria-live', 'polite' );
		host.setAttribute( 'aria-atomic', 'false' );
		document.body.appendChild( host );

		return host;
	}

	/**
	 * Show a toast notification.
	 *
	 * Success toasts dismiss themselves; errors and warnings stay until the user
	 * closes them (or dismisses the last one).
	 *
	 * @param {string} message Message text.
	 * @param {string} type    success|error|warning|info.
	 */
	function notify( message, type ) {
		if ( ! message ) {
			return;
		}

		var kind = type || 'info';
		var host = toastHost();
		var toast = document.createElement( 'div' );
		var icons = { success: 'check', error: 'error', warning: 'warning', info: 'info' };

		toast.className = 'rbw-toast is-' + kind;
		toast.setAttribute( 'role', 'alert' === kind ? 'alert' : 'status' );

		toast.innerHTML =
			'<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-' + ( icons[ kind ] || 'info' ) + '"></use></svg>' +
			'<div class="rbw-toast-body"></div>' +
			'<button type="button" class="rbw-toast-close" aria-label="' + esc( strings.dismiss || 'Close' ) + '">' +
			'<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-close"></use></svg></button>';

		qs( '.rbw-toast-body', toast ).textContent = message;

		qs( '.rbw-toast-close', toast ).addEventListener( 'click', function () {
			removeToast( toast );
		} );

		host.appendChild( toast );

		// Keep the stack short.
		var all = qsa( '.rbw-toast', host );

		if ( all.length > 4 ) {
			removeToast( all[ 0 ] );
		}

		if ( 'error' !== kind && 'warning' !== kind ) {
			window.setTimeout( function () {
				removeToast( toast );
			}, 5000 );
		}
	}

	/**
	 * Remove a toast with a short fade.
	 *
	 * @param {HTMLElement} toast Toast element.
	 */
	function removeToast( toast ) {
		if ( ! toast || ! toast.parentNode ) {
			return;
		}

		toast.style.opacity = '0';

		window.setTimeout( function () {
			if ( toast.parentNode ) {
				toast.parentNode.removeChild( toast );
			}
		}, 150 );
	}

	/* ------------------------------------------------------------------ */
	/* Confirm dialog (replaces window.confirm)                            */
	/* ------------------------------------------------------------------ */

	var confirmResolve = null;
	var confirmLastFocus = null;

	/**
	 * Build (once) and show the confirm dialog.
	 *
	 * Behaves like window.confirm(): resolves true/false and the caller keeps its
	 * original payloads. Adds: focus trap, ESC, danger variant, RTL labels.
	 *
	 * @param {string} message Message text.
	 * @param {Object} options {danger:boolean, confirmLabel:string}.
	 * @return {Promise<boolean>} Resolves when the user answers.
	 */
	function confirmDialog( message, options ) {
		var settings = options || {};

		return new Promise( function ( resolve ) {
			var dialog = qs( '#rbw-confirm' );

			if ( ! dialog ) {
				confirmLastFocus = document.activeElement;
				confirmResolve = resolve;
				resolve( false );
				return;
			}
			confirmLastFocus = document.activeElement;
			confirmResolve = resolve;

			var body = qs( '#rbw-confirm-message', dialog );
			var okButton = qs( '#rbw-confirm-ok', dialog );
			var cancelButton = qs( '#rbw-confirm-cancel', dialog );

			if ( body ) {
				body.textContent = message || '';
			}

			if ( okButton ) {
				okButton.textContent = settings.confirmLabel || strings.okLabel || 'OK';
				okButton.className = 'button ' + ( settings.danger ? 'button-link-delete rbw-danger' : 'button-primary' );
			}

			if ( cancelButton ) {
				cancelButton.textContent = strings.cancelLabel || strings.cancel || 'Cancel';
			}

			dialog.hidden = false;
			( settings.danger ? cancelButton : okButton || cancelButton ).focus();

			function close( answer ) {
				dialog.hidden = true;

				document.removeEventListener( 'keydown', onKey );

				if ( confirmResolve ) {
					confirmResolve( answer );
					confirmResolve = null;
				}

				if ( confirmLastFocus && confirmLastFocus.focus ) {
					confirmLastFocus.focus();
				}
			}

			function onKey( event ) {
				if ( 'Escape' === event.key ) {
					event.preventDefault();
					close( false );
					return;
				}

				if ( 'Tab' !== event.key ) {
					return;
				}

				// Focus trap between the two buttons.
				var focusables = [ okButton, cancelButton ].filter( Boolean );

				if ( ! focusables.length ) {
					return;
				}

				var first = focusables[ 0 ];
				var last = focusables[ focusables.length - 1 ];

				if ( event.shiftKey && document.activeElement === first ) {
					event.preventDefault();
					last.focus();
				} else if ( ! event.shiftKey && document.activeElement === last ) {
					event.preventDefault();
					first.focus();
				}
			}

			document.addEventListener( 'keydown', onKey );

			okButton.onclick = function () {
				close( true );
			};

			cancelButton.onclick = function () {
				close( false );
			};

			var backdrop = qs( '.rbw-modal-backdrop', dialog );

			if ( backdrop ) {
				backdrop.onclick = function () {
					close( false );
				};
			}
		} );
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
						// WordPress answers a bare "0" when the request is not
						// recognised (wrong action name or expired nonce).
						if ( '0' === String( text ).trim() ) {
							throw new Error(
								( strings.requestNotRecognised || strings.genericError ).replace( '%d', response.status )
							);
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
				// The item lives in a portalled menu: close it before opening the dialog.
				closeMenus();

				confirmDialog( strings.confirmDelete, { danger: true, confirmLabel: strings.deleteLabel } ).then( function ( ok ) {
					if ( ! ok ) {
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
		} );

		qsa( '.rbw-edit', root ).forEach( function ( button ) {
			if ( button.getAttribute( 'data-rbw-bound' ) ) {
				return;
			}
			button.setAttribute( 'data-rbw-bound', '1' );

			button.addEventListener( 'click', function () {
				closeMenus();

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
				'<button type="button" class="button button-small rbw-edit">' + esc( strings.editLabel ) + '</button> ' +
				'<button type="button" class="button button-small rbw-update-one">' + esc( strings.updateNowLabel ) + '</button> ' +
				'<button type="button" class="button button-small button-link-delete rbw-delete">' + esc( strings.deleteLabel ) + '</button>' +
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

		// Pricing section (percent/fixed + amounts).
		setRuleField( modal, 'profit', isEdit ? currency.profit_type : 'percent', isEdit ? currency.profit_value : 0 );
		setRuleField( modal, 'commission', isEdit ? currency.commission_type : 'percent', isEdit ? currency.commission_value : 0 );

		var extraField = modal.querySelector( '[name="extra_amount"]' );
		if ( extraField ) {
			extraField.value = isEdit && currency.extra_amount > 0 ? String( currency.extra_amount ) : '0';
		}

		clearModalError( 'rbw-currency-error' );
		modal.hidden = false;

		// Warn when the row interval is shorter than the source can serve.
		checkIntervalWarning( isEdit ? currency.provider : 'manual', Number( modal.querySelector( '[name="update_interval_minutes"]' ).value ) || 0 );

		var firstField = modal.querySelector( '[name="name"]' );
		if ( firstField ) {
			firstField.focus();
		}
	}

	/**
	 * Fill one profit/commission rule and sync the unit hint.
	 *
	 * @param {HTMLElement} modal Modal element.
	 * @param {string}      slot  profit|commission.
	 * @param {string}      type  percent|fixed.
	 * @param {number}      value Amount.
	 */
	function setRuleField( modal, slot, type, value ) {
		var typeField = modal.querySelector( '[name="' + slot + '_type"]' );
		var valueField = modal.querySelector( '[name="' + slot + '_value"]' );

		if ( typeField ) {
			typeField.value = 'fixed' === type ? 'fixed' : 'percent';
		}

		if ( valueField ) {
			valueField.value = value > 0 ? String( value ) : '0';
		}

		updateRuleUnit( slot );
	}

	/**
	 * Show "%" or the store unit next to a rule input.
	 *
	 * @param {string} slot profit|commission.
	 */
	function updateRuleUnit( slot ) {
		var typeField = qs( '#rbw-field-' + slot + '-type' );
		var unitLabel = qs( '.rbw-rule-unit[data-slot="' + slot + '"]' );

		if ( ! unitLabel ) {
			return;
		}

		unitLabel.textContent = typeField && 'fixed' === typeField.value
			? ( config.storeUnitLabel || '' )
			: '%';
	}

	/**
	 * Bind the rule type selects inside the currency dialog.
	 */
	function bindRuleFields() {
		qsa( '.rbw-type-select' ).forEach( function ( select ) {
			select.addEventListener( 'change', function () {
				updateRuleUnit( select.getAttribute( 'data-slot' ) );
			} );
		} );
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
					// Select value when a preset is chosen, otherwise the free-text input.
					provider_symbol: currentProviderSymbol(),
					rate: form.querySelector( '[name="rate"]' ).value,
					update_interval_minutes: form.querySelector( '[name="update_interval_minutes"]' ).value,
					sort_order: form.querySelector( '[name="sort_order"]' ).value,
					is_enabled: form.querySelector( '[name="is_enabled"]' ).checked ? '1' : '0',
					profit_type: form.querySelector( '[name="profit_type"]' ).value,
					profit_value: form.querySelector( '[name="profit_value"]' ).value,
					commission_type: form.querySelector( '[name="commission_type"]' ).value,
					commission_value: form.querySelector( '[name="commission_value"]' ).value,
					extra_amount: form.querySelector( '[name="extra_amount"]' ).value
				};

				if ( button ) {
					button.disabled = true;
					button.textContent = strings.saving;
				}

				request( 'save', payload )
					.then( function ( response ) {
						if ( ! response.success ) {
							// Show it inside the dialog; the page notice sits behind it.
							showModalError( 'rbw-currency-error', messageFrom( response ) );
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
						showModalError( 'rbw-currency-error', ( error && error.message ) || strings.genericError );
					} )
					.finally( function () {
						if ( button ) {
							button.disabled = false;
							button.textContent = strings.saveButton;
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

			[ entry.time, null, entry.message, entry.trigger === 'manual' ? strings.manualLabel : strings.cronLabel ].forEach( function ( value, index ) {
				var td = document.createElement( 'td' );

				if ( 1 === index ) {
					var badge = document.createElement( 'span' );
					badge.className = 'rbw-badge rbw-badge-' + ( entry.status === 'success' ? 'success' : 'error' );
					badge.textContent = entry.status === 'success' ? strings.okLabel : strings.errorShort;
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

		// The upstream sample is shown in Persian sentences, so it is isolated
		// and grouped: `(1,234,567)` must not scatter its brackets in RTL.
		if ( output ) {
			output.innerHTML = bdi( formatNumber( result ) );
		}
	}

	/**
	 * Read a checkbox as '1'/'0' (missing checkbox = unchecked).
	 *
	 * @param {HTMLElement} form  Form element.
	 * @param {string}      name  Field name.
	 * @return {string} '1' or '0'.
	 */
	function checkboxValue( form, name ) {
		var field = form.querySelector( '[name="' + name + '"]' );

		return field && field.checked ? '1' : '0';
	}

	/**
	 * Read a text/select field value ('' when missing).
	 *
	 * @param {HTMLElement} form Form element.
	 * @param {string}      name Field name.
	 * @return {string} Value.
	 */
	function fieldValue( form, name ) {
		var field = form.querySelector( '[name="' + name + '"]' );

		return field ? field.value : '';
	}

	/**
	 * Explain the effect of a store-unit change, inline.
	 */
	function unitWarning() {
		var box = qs( '#rbw-unit-warning' );

		if ( ! box ) {
			return;
		}

		var chosen = ( qs( 'input[name="store_currency_unit"]:checked' ) || {} ).value || 'toman';

		if ( chosen === ( config.storeUnit || 'toman' ) ) {
			box.hidden = true;
			box.textContent = '';
			return;
		}

		box.hidden = false;
		box.textContent = strings.unitChangeWarning || '';
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

		qsa( 'input[name="store_currency_unit"]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', unitWarning );
		} );

		unitWarning();

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var button = qs( '#rbw-save-settings' );
			var chosenUnit = ( qs( 'input[name="store_currency_unit"]:checked' ) || {} ).value || 'toman';
			var unitChanged = chosenUnit !== ( config.storeUnit || 'toman' );
			var ageField = qs( '#rbw-max-rate-age' );

			var payload = {
				store_currency_unit: chosenUnit,
				rounding_mode: qs( '#rbw-rounding-mode' ).value,
				rounding_step: qs( '#rbw-rounding-step' ).value,
				default_update_interval: qs( '#rbw-default-interval' ).value,
				max_rate_age_hours: ageField ? ageField.value : '48',
				delete_data_on_uninstall: form.querySelector( '[name="delete_data_on_uninstall"]' ).checked ? '1' : '0',
				confirm_unit_change: unitChanged ? '1' : '0',
				// Phase 4: order display + product page block.
				show_rate_in_orders: checkboxValue( form, 'show_rate_in_orders' ),
				product_block_enabled: checkboxValue( form, 'product_block_enabled' ),
				product_block_position: fieldValue( form, 'product_block_position' ),
				product_block_template: fieldValue( form, 'product_block_template' ),
				date_format: fieldValue( form, 'date_format' ),
				use_persian_digits: checkboxValue( form, 'use_persian_digits' ),
				use_jalali_dates: checkboxValue( form, 'use_jalali_dates' )
			};

			// The unit change rewrites every stored rate: ask explicitly.
			var gate = unitChanged
				? confirmDialog( strings.confirmUnitChange || 'Convert all stored rates?', { confirmLabel: strings.saveSettingsButton } )
				: Promise.resolve( true );

			gate.then( function ( ok ) {
				if ( ! ok ) {
					return;
				}

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
						button.textContent = strings.saveSettingsButton;
					}
				} );
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
				confirmDialog( strings.confirmClear, { danger: true } ).then( function ( ok ) {
					if ( ! ok ) {
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
				hint.textContent = strings.symbolHint;
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

	/**
	 * Derive a currency code from the selected preset symbol.
	 *
	 * `gold:sekkeh` -> `SEKKEH`, `currency:usd` -> `USD`.
	 *
	 * @param {string} symbol Typed provider symbol.
	 * @return {string}
	 */
	function codeFromSymbol( symbol ) {
		var bare = String( symbol || '' ).indexOf( ':' ) !== -1 ? String( symbol ).split( ':' )[ 1 ] : String( symbol || '' );

		return bare.toUpperCase().replace( /[^A-Z0-9]/g, '' ).slice( 0, 32 );
	}

	/**
	 * Fill the preset dialog's editable code field from the chosen symbol.
	 */
	function updatePresetCode() {
		var list = qs( '#rbw-preset-symbol' );
		var code = qs( '#rbw-preset-code' );

		if ( ! list || ! code ) {
			return;
		}

		code.value = codeFromSymbol( list.value );
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
			updatePresetCode();
			clearModalError( 'rbw-preset-error' );
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

		var presetList = qs( '#rbw-preset-symbol' );
		if ( presetList ) {
			presetList.addEventListener( 'change', function () {
				clearModalError( 'rbw-preset-error' );
				updatePresetCode();
			} );
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
				var codeField = qs( '#rbw-preset-code' );
				var button = qs( '#rbw-preset-submit' );

				// Use the editable field, falling back to the derived value.
				var code = codeField && codeField.value ? codeField.value : codeFromSymbol( selected );

				if ( button ) {
					button.disabled = true;
					button.textContent = strings.saving;
				}

				request( 'add_preset', {
					provider: provider,
					symbol: selected,
					code: code,
					name: label
				} )
					.then( function ( response ) {
						if ( ! response.success ) {
							var data = response.data || {};

							// Code collision: suggest a free one inline and keep the dialog open.
							if ( data.suggestion ) {
								showModalError(
									'rbw-preset-error',
									( strings.codeTaken || messageFrom( response ) ).replace( '%s', data.suggestion )
								);

								if ( codeField ) {
									codeField.value = data.suggestion;
									codeField.focus();
								}
								return;
							}

							showModalError( 'rbw-preset-error', messageFrom( response ) );
							return;
						}

						notify( messageFrom( response ), 'success' );
						modal.hidden = true;

						// Append the new row instead of reloading.
						if ( response.data && response.data.currency ) {
							appendCurrencyRow( response.data.currency );
						} else {
							window.location.reload();
						}
					} )
					.catch( function ( error ) {
						showModalError( 'rbw-preset-error', error.message );
					} )
					.finally( function () {
						if ( button ) {
							button.disabled = false;
							button.textContent = strings.addButton;
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
		/*
		 * Show/hide for secret fields. The value is never rendered server-side
		 * (the field is empty and only posts when the admin types), so revealing
		 * simply flips the input type and swaps the eye icon.
		 */
		qsa( '.rbw-reveal-secret' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var wrapper = button.closest( '.rbw-secret' );
				var field = wrapper ? wrapper.querySelector( 'input' ) : null;

				if ( ! field ) {
					return;
				}

				var reveal = 'password' === field.type;

				field.type = reveal ? 'text' : 'password';
				button.setAttribute( 'aria-pressed', reveal ? 'true' : 'false' );
				button.classList.toggle( 'is-revealed', reveal );
			} );
		} );

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
							button.textContent = strings.saveButton;
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
				confirmDialog( strings.confirmBudgetReset, { danger: true } ).then( function ( ok ) {
					if ( ! ok ) {
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

	/**
	 * Dismiss the stale-rate notice for the current user.
	 *
	 * The notice is also shown on the Products screen, where this script is not
	 * enqueued, so it degrades to hiding the notice for this page view.
	 */
	function bindStaleNotice() {
		qsa( '.rbw-stale-notice' ).forEach( function ( notice ) {
			var button = notice.querySelector( '.notice-dismiss' );

			if ( ! button ) {
				return;
			}

			button.addEventListener( 'click', function () {
				request( 'dismiss_stale_notice', {} ).catch( function () {} );
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Formula builder                                                    */
	/* ------------------------------------------------------------------ */

	var formulaState = {
		variables: [],
		lastReport: null
	};

	/**
	 * The formula dialog form.
	 *
	 * @return {HTMLElement|null} Form element.
	 */
	function formulaForm() {
		return qs( '#rbw-formula-form' );
	}

	/**
	 * Read a field from the formula form.
	 *
	 * @param {string} name Field name.
	 * @return {string} Value.
	 */
	function formulaField( name ) {
		var form = formulaForm();

		if ( ! form ) {
			return '';
		}

		var field = form.querySelector( '[name="' + name + '"]' );

		return field ? field.value : '';
	}

	/**
	 * Insert text at the caret of the expression textarea.
	 *
	 * @param {string} text  Text to insert.
	 * @param {number} caret Offset relative to the end of the inserted text.
	 */
	function insertIntoExpression( text, caret ) {
		var area = qs( '#rbw-formula-expression' );

		if ( ! area ) {
			return;
		}

		var start = area.selectionStart;
		var end   = area.selectionEnd;
		var value = area.value;

		// Keep tokens separated: add a space when needed.
		var before = value.slice( 0, start );
		var after  = value.slice( end );

		if ( before && ! /[\s(]$/.test( before ) && ! /^[\s).,]/.test( text ) ) {
			text = ' ' + text;
		}

		if ( after && ! /^[\s)]/.test( after ) && ! /[\s(]$/.test( text ) ) {
			text = text + ' ';
		}

		area.value = before + text + after;

		var pos = before.length + text.length + ( caret || 0 );

		area.focus();
		area.setSelectionRange( pos, pos );

		scheduleValidate();
	}

	/**
	 * Debounced validation.
	 */
	var validateTimer = null;

	function scheduleValidate() {
		window.clearTimeout( validateTimer );
		validateTimer = window.setTimeout( validateFormula, 350 );
	}

	/**
	 * Collect the variable rows from the editor.
	 *
	 * @return {Array} Variable definitions.
	 */
	function collectVariables() {
		return formulaState.variables.slice();
	}

	/**
	 * Send the expression to the server for validation.
	 */
	function validateFormula() {
		var form = formulaForm();

		if ( ! form || form.querySelector( '[name="expression"]' ).value.trim() === '' ) {
			setFormulaStatus( '', '' );
			return;
		}

		request( 'validate_formula', {
			expression: formulaField( 'expression' ),
			variables: collectVariables()
		} )
			.then( function ( response ) {
				var data = response.data || {};

				if ( ! response.success ) {
					formulaState.lastReport = null;
					setFormulaStatus( data.message || strings.formulaInvalid, 'error', data.position, data.expression );
					return;
				}

				formulaState.lastReport = data;
				setFormulaStatus( data.message || strings.formulaValid, 'success' );

				renderChips( data.variables || [] );

				var missing = data.undeclared || [];
				var autoButton = qs( '#rbw-autocreate-vars' );

				if ( autoButton ) {
					autoButton.hidden = missing.length === 0;
					autoButton.setAttribute( 'data-missing', missing.join( ',' ) );
					autoButton.textContent = ( strings.createMissing || 'Create missing variables' ) +
						( missing.length ? ' (' + missing.length + ')' : '' );
				}

				if ( data.unused && data.unused.length ) {
					setFormulaStatus(
						( data.message || '' ) + ' — ' +
						( strings.unusedVars || 'Unused variables: %s' ).replace( '%s', data.unused.join( ', ' ) ),
						'warning'
					);
				}
			} )
			.catch( function ( error ) {
				setFormulaStatus( ( error && error.message ) || strings.genericError, 'error' );
			} );
	}

	/**
	 * Show a status line under the expression (and the offending snippet).
	 *
	 * @param {string} message  Message.
	 * @param {string} type     success|error|warning.
	 * @param {number} position Character offset of the error.
	 * @param {string} source   Expression text.
	 */
	function setFormulaStatus( message, type, position, source ) {
		var status = qs( '#rbw-formula-status' );
		var errorLine = qs( '#rbw-formula-error-line' );

		if ( status ) {
			status.hidden = ! message;
			status.className = 'rbw-formula-status' + ( type ? ' is-' + type : '' );
			status.textContent = message || '';
		}

		if ( ! errorLine ) {
			return;
		}

		if ( 'error' !== type || 'number' !== typeof position ) {
			errorLine.hidden = true;
			errorLine.textContent = '';
			return;
		}

		// Show the expression with the error position marked.
		var text = typeof source === 'string' && source ? source : ( qs( '#rbw-formula-expression' ) || {} ).value || '';

		errorLine.hidden = false;
		errorLine.textContent = text + '\n' + new Array( Math.max( 0, position ) + 1 ).join( ' ' ) + '^';

		// Put the caret on the error so it is immediately visible.
		var area = qs( '#rbw-formula-expression' );

		if ( area ) {
			area.focus();
			area.setSelectionRange( position, position );
		}
	}

	/**
	 * Render the variable chips above the expression.
	 *
	 * @param {Array} names Variable names.
	 */
	function renderChips( names ) {
		var box = qs( '#rbw-chip-vars' );

		if ( ! box ) {
			return;
		}

		box.innerHTML = '';

		names.forEach( function ( name ) {
			var chip = document.createElement( 'button' );
			chip.type = 'button';
			chip.className = 'button button-small rbw-chip rbw-insert';
			chip.textContent = name;
			chip.addEventListener( 'click', function () {
				insertIntoExpression( name, 0 );
			} );
			box.appendChild( chip );
		} );

		if ( ! names.length ) {
			var hint = document.createElement( 'span' );
			hint.className = 'rbw-muted';
			hint.textContent = strings.noVarsYet || '—';
			box.appendChild( hint );
		}
	}

	/**
	 * Render the variable editor rows.
	 */
	function renderVariableRows() {
		var body = qs( '#rbw-formula-vars-body' );

		if ( ! body ) {
			return;
		}

		body.innerHTML = '';

		formulaState.variables.forEach( function ( variable, index ) {
			var tr = document.createElement( 'tr' );
			tr.setAttribute( 'data-index', String( index ) );

			tr.innerHTML =
				'<td><input type="text" class="rbw-var-name" dir="ltr" maxlength="40" /></td>' +
				'<td><input type="text" class="rbw-var-label" /></td>' +
				'<td><select class="rbw-var-type"></select></td>' +
				'<td class="rbw-var-extra"></td>' +
				'<td class="rbw-actions"><button type="button" class="button button-small button-link-delete rbw-var-remove">×</button></td>';

			var nameField = qs( '.rbw-var-name', tr );
			var labelField = qs( '.rbw-var-label', tr );
			var typeField = qs( '.rbw-var-type', tr );
			var extra = qs( '.rbw-var-extra', tr );

			nameField.value = variable.name || '';
			labelField.value = variable.label || '';
			typeField.innerHTML = '';

			Object.keys( config.variableTypes || {} ).forEach( function ( key ) {
				var option = document.createElement( 'option' );
				option.value = key;
				option.textContent = config.variableTypes[ key ];
				if ( variable.type === key ) {
					option.selected = true;
				}
				typeField.appendChild( option );
			} );

			nameField.addEventListener( 'input', function () {
				formulaState.variables[ index ].name = nameField.value.trim();
				scheduleValidate();
				renderTestInputs();
			} );

			labelField.addEventListener( 'input', function () {
				formulaState.variables[ index ].label = labelField.value;
				renderTestInputs();
			} );

			typeField.addEventListener( 'change', function () {
				formulaState.variables[ index ].type = typeField.value;
				renderVariableExtra( extra, formulaState.variables[ index ] );
				renderTestInputs();
				scheduleValidate();
			} );

			qs( '.rbw-var-remove', tr ).addEventListener( 'click', function () {
				formulaState.variables.splice( index, 1 );
				renderVariableRows();
				renderTestInputs();
				scheduleValidate();
			} );

			renderVariableExtra( extra, variable );
			body.appendChild( tr );
		} );

		if ( ! formulaState.variables.length ) {
			var empty = document.createElement( 'tr' );
			empty.className = 'rbw-formula-vars-empty';
			var cell = document.createElement( 'td' );
			cell.colSpan = 5;
			cell.className = 'rbw-muted';
			cell.textContent = strings.noVarsYet || '—';
			empty.appendChild( cell );
			body.appendChild( empty );
		}
	}

	/**
	 * Render the type-specific fields of a variable row.
	 *
	 * @param {HTMLElement} cell     Target cell.
	 * @param {Object}      variable Variable definition.
	 */
	function renderVariableExtra( cell, variable ) {
		cell.innerHTML = '';

		if ( 'currency' === variable.type ) {
			var select = document.createElement( 'select' );
			select.className = 'rbw-var-currency';

			(config.currencyCodes || []).forEach( function ( item ) {
				var option = document.createElement( 'option' );
				option.value = item.code;
				option.textContent = item.label;
				if ( variable.currency_code === item.code ) {
					option.selected = true;
				}
				select.appendChild( option );
			} );

			select.addEventListener( 'change', function () {
				variable.currency_code = select.value;
				renderTestInputs();
				scheduleValidate();
			} );

			cell.appendChild( select );
			return;
		}

		if ( 'product' === variable.type ) {
			var fields = document.createElement( 'select' );
			fields.className = 'rbw-var-product-field';

			Object.keys( config.productFields || {} ).forEach( function ( key ) {
				var option = document.createElement( 'option' );
				option.value = key;
				option.textContent = config.productFields[ key ];
				if ( variable.product_field === key ) {
					option.selected = true;
				}
				fields.appendChild( option );
			} );

			fields.addEventListener( 'change', function () {
				variable.product_field = fields.value;
			} );

			cell.appendChild( fields );
			return;
		}

		// Custom value: default, min, max, money flag.
		var wrap = document.createElement( 'div' );
		wrap.className = 'rbw-var-custom';

		wrap.innerHTML =
			'<label class="rbw-var-inline">' + esc( strings.defaultValue || 'Default' ) +
			' <input type="number" step="any" class="rbw-var-default" /></label>' +
			'<label class="rbw-var-inline">' + esc( strings.minValue || 'Min' ) +
			' <input type="number" step="any" class="rbw-var-min" /></label>' +
			'<label class="rbw-var-inline">' + esc( strings.maxValue || 'Max' ) +
			' <input type="number" step="any" class="rbw-var-max" /></label>' +
			'<label class="rbw-var-inline"><input type="checkbox" class="rbw-var-money" /> ' +
			esc( strings.isMoney || 'Amount in store unit' ) + '</label>';

		var defaultField = qs( '.rbw-var-default', wrap );
		var minField = qs( '.rbw-var-min', wrap );
		var maxField = qs( '.rbw-var-max', wrap );
		var moneyField = qs( '.rbw-var-money', wrap );

		defaultField.value = 'number' === typeof variable.default ? variable.default : '';
		minField.value = 'number' === typeof variable.min ? variable.min : '';
		maxField.value = 'number' === typeof variable.max ? variable.max : '';
		moneyField.checked = !! variable.is_money;

		defaultField.addEventListener( 'input', function () {
			variable.default = '' === defaultField.value ? 0 : parseFloat( defaultField.value );
			renderTestInputs();
		} );

		minField.addEventListener( 'input', function () {
			variable.min = '' === minField.value ? null : parseFloat( minField.value );
		} );

		maxField.addEventListener( 'input', function () {
			variable.max = '' === maxField.value ? null : parseFloat( maxField.value );
		} );

		moneyField.addEventListener( 'change', function () {
			variable.is_money = moneyField.checked;
		} );

		cell.appendChild( wrap );
	}

	/**
	 * Render the sample-value inputs of the test panel.
	 */
	function renderTestInputs() {
		var box = qs( '#rbw-formula-test-vars' );

		if ( ! box ) {
			return;
		}

		box.innerHTML = '';

		var seen = {};

		collectVariables().forEach( function ( variable ) {
			if ( ! variable.name || seen[ variable.name ] ) {
				return;
			}

			seen[ variable.name ] = true;

			var wrap = document.createElement( 'p' );
			wrap.className = 'rbw-test-var';

			var id = 'rbw-test-' + variable.name;
			var label = document.createElement( 'label' );
			label.setAttribute( 'for', id );
			label.textContent = ( variable.label || variable.name ) + ' (' + variable.name + ')';

			var input = document.createElement( 'input' );
			input.type = 'number';
			input.step = 'any';
			input.id = id;
			input.className = 'rbw-test-value';
			input.setAttribute( 'data-name', variable.name );

			if ( 'currency' === variable.type ) {
				input.value = '';
				input.placeholder = strings.liveRate || 'live rate';
			} else if ( 'custom' === variable.type && 'number' === typeof variable.default ) {
				input.value = String( variable.default );
			}

			wrap.appendChild( label );
			wrap.appendChild( input );
			box.appendChild( wrap );
		} );
	}

	/**
	 * Run the formula with the sample values.
	 */
	function runFormulaTest() {
		var button = qs( '#rbw-test-formula' );
		var result = qs( '#rbw-formula-test-result' );
		var values = {};

		qsa( '.rbw-test-value' ).forEach( function ( input ) {
			if ( '' !== input.value ) {
				values[ input.getAttribute( 'data-name' ) ] = input.value;
			}
		} );

		if ( button ) {
			button.disabled = true;
			button.textContent = strings.working;
		}

		request( 'test_formula', {
			expression: formulaField( 'expression' ),
			variables: collectVariables(),
			values: values
		} )
			.then( function ( response ) {
				var data = response.data || {};

				if ( ! result ) {
					return;
				}

				result.hidden = false;

				if ( ! response.success ) {
					result.className = 'rbw-formula-test-result is-error';
					result.textContent = data.message || strings.genericError;
					return;
				}

				var lines = [];
				lines.push( ( strings.testValue || 'Result' ) + ': ' + data.value_formatted + ' ' + ( data.unit || '' ) );

				Object.keys( data.trace || {} ).forEach( function ( key ) {
					lines.push( '  ' + key + ' = ' + data.trace[ key ] );
				} );

				result.className = 'rbw-formula-test-result is-success';
				result.textContent = lines.join( '\n' );
			} )
			.catch( function ( error ) {
				if ( result ) {
					result.hidden = false;
					result.className = 'rbw-formula-test-result is-error';
					result.textContent = ( error && error.message ) || strings.genericError;
				}
			} )
			.finally( function () {
				if ( button ) {
					button.disabled = false;
					button.textContent = strings.calculate || 'Calculate';
				}
			} );
	}

	/**
	 * Open the formula dialog, optionally for an existing formula.
	 *
	 * @param {Object|null} formula Formula payload.
	 */
	function openFormulaModal( formula ) {
		var modal = qs( '#rbw-formula-modal' );
		var form = formulaForm();

		if ( ! modal || ! form ) {
			return;
		}

		var isEdit = formula && formula.id;

		qs( '#rbw-formula-title' ).textContent = isEdit ? strings.editFormula : strings.addFormula;

		form.querySelector( '[name="id"]' ).value = isEdit ? formula.id : '0';
		form.querySelector( '[name="name"]' ).value = isEdit ? formula.name : '';
		form.querySelector( '[name="slug"]' ).value = isEdit ? formula.slug : '';
		form.querySelector( '[name="description"]' ).value = isEdit ? ( formula.description || '' ) : '';
		form.querySelector( '[name="expression"]' ).value = isEdit ? formula.expression : '';
		form.querySelector( '[name="rounding_mode"]' ).value = isEdit ? ( formula.rounding_mode || '' ) : '';
		form.querySelector( '[name="rounding_step"]' ).value = isEdit && formula.rounding_step ? formula.rounding_step : 0;
		qs( '#rbw-formula-enabled' ).checked = isEdit ? !! formula.is_enabled : true;

		formulaState.variables = isEdit && Array.isArray( formula.variables )
			? formula.variables.map( function ( variable ) { return Object.assign( {}, variable ); } )
			: [];

		renderVariableRows();
		renderTestInputs();
		renderChips( [] );
		setFormulaStatus( '', '' );

		var result = qs( '#rbw-formula-test-result' );

		if ( result ) {
			result.hidden = true;
			result.textContent = '';
		}

		showModalError( 'rbw-formula-error', '' );

		modal.hidden = false;

		( qs( '#rbw-formula-name' ) || {} ).focus && qs( '#rbw-formula-name' ).focus();

		if ( isEdit ) {
			scheduleValidate();
		}
	}

	/**
	 * Close the formula dialog.
	 */
	function closeFormulaModal() {
		var modal = qs( '#rbw-formula-modal' );

		if ( modal ) {
			modal.hidden = true;
		}
	}

	/**
	 * Reload the formulas table from the server.
	 */
	function reloadFormulas() {
		window.location.reload();
	}

	/**
	 * Bind the whole formulas tab.
	 */
	function bindFormulas() {
		var modal = qs( '#rbw-formula-modal' );

		if ( ! modal ) {
			return;
		}

		qsa( '[data-rbw-close]', modal ).forEach( function ( el ) {
			el.addEventListener( 'click', closeFormulaModal );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! modal.hidden ) {
				closeFormulaModal();
			}
		} );

		var addButton = qs( '#rbw-add-formula' );

		if ( addButton ) {
			addButton.addEventListener( 'click', function () {
				openFormulaModal( null );
			} );
		}

		var sampleButton = qs( '#rbw-add-formula-samples' );

		if ( sampleButton ) {
			sampleButton.addEventListener( 'click', function () {
				sampleButton.disabled = true;

				request( 'add_formula_samples', {} )
					.then( function ( response ) {
						notify( messageFrom( response ), response.success ? 'success' : 'error' );

						if ( response.success ) {
							reloadFormulas();
						}
					} )
					.catch( handleRequestError )
					.finally( function () {
						sampleButton.disabled = false;
					} );
			} );
		}

		var expression = qs( '#rbw-formula-expression' );

		if ( expression ) {
			expression.addEventListener( 'input', scheduleValidate );
		}

		qsa( '.rbw-insert' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				insertIntoExpression(
					button.getAttribute( 'data-insert' ) || '',
					parseInt( button.getAttribute( 'data-caret' ) || '0', 10 )
				);
			} );
		} );

		var addVar = qs( '#rbw-add-variable' );

		if ( addVar ) {
			addVar.addEventListener( 'click', function () {
				formulaState.variables.push( {
					name: '',
					label: '',
					type: 'custom',
					default: 0,
					is_money: false
				} );

				renderVariableRows();
				renderTestInputs();
			} );
		}

		var autoButton = qs( '#rbw-autocreate-vars' );

		if ( autoButton ) {
			autoButton.addEventListener( 'click', function () {
				var missing = ( autoButton.getAttribute( 'data-missing' ) || '' ).split( ',' ).filter( Boolean );

				missing.forEach( function ( name ) {
					formulaState.variables.push( {
						name: name,
						label: name,
						type: 'custom',
						default: 0,
						is_money: false
					} );
				} );

				renderVariableRows();
				renderTestInputs();
				scheduleValidate();
			} );
		}

		var testButton = qs( '#rbw-test-formula' );

		if ( testButton ) {
			testButton.addEventListener( 'click', runFormulaTest );
		}

		var form = formulaForm();

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();

				var button = qs( '#rbw-save-formula' );

				if ( button ) {
					button.disabled = true;
					button.textContent = strings.saving;
				}

				request( 'save_formula', {
					id: formulaField( 'id' ),
					name: formulaField( 'name' ),
					slug: formulaField( 'slug' ),
					description: formulaField( 'description' ),
					expression: formulaField( 'expression' ),
					variables: collectVariables(),
					rounding_mode: formulaField( 'rounding_mode' ),
					rounding_step: formulaField( 'rounding_step' ),
					is_enabled: qs( '#rbw-formula-enabled' ).checked ? '1' : '0'
				} )
					.then( function ( response ) {
						if ( ! response.success ) {
							var data = response.data || {};
							showModalError( 'rbw-formula-error', data.message || strings.genericError );
							return;
						}

						notify( messageFrom( response ), 'success' );
						closeFormulaModal();
						reloadFormulas();
					} )
					.catch( function ( error ) {
						showModalError( 'rbw-formula-error', ( error && error.message ) || strings.genericError );
					} )
					.finally( function () {
						if ( button ) {
							button.disabled = false;
							button.textContent = strings.saveFormula || 'Save formula';
						}
					} );
			} );
		}

		// Row actions.
		qsa( '.rbw-formula-toggle' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				var enabled = input.checked;

				request( 'toggle_formula', { id: input.getAttribute( 'data-id' ), enabled: enabled ? '1' : '0' } )
					.then( function ( response ) {
						if ( ! response.success ) {
							input.checked = ! enabled;
						}

						notify( messageFrom( response ), response.success ? 'success' : 'error' );
					} )
					.catch( function ( error ) {
						input.checked = ! enabled;
						handleRequestError( error );
					} );
			} );
		} );

		qsa( '.rbw-formula-edit' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				/*
				 * Close first: while the menu is portalled it is a child of <body>,
				 * so `closest('tr')` would return null. closeMenus() puts it back.
				 */
				closeMenus();

				var id = button.getAttribute( 'data-id' );
				var row = button.closest( 'tr' );

				// Reuse the cached payload when available, otherwise reload it.
				if ( row && row.getAttribute( 'data-payload' ) ) {
					openFormulaModal( JSON.parse( row.getAttribute( 'data-payload' ) ) );
					return;
				}

				openFormulaModalForId( id );
			} );
		} );

		qsa( '.rbw-formula-duplicate' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				closeMenus();

				request( 'duplicate_formula', { id: button.getAttribute( 'data-id' ) } )
					.then( function ( response ) {
						notify( messageFrom( response ), response.success ? 'success' : 'error' );

						if ( response.success ) {
							reloadFormulas();
						}
					} )
					.catch( handleRequestError );
			} );
		} );

		qsa( '.rbw-formula-delete' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				closeMenus();

				var products = parseInt( button.getAttribute( 'data-products' ) || '0', 10 );
				var detach = '0';
				var question = products > 0
					? ( strings.confirmDetach || 'Products use this formula. Detach and delete?' )
					: ( strings.confirmDeleteFormula || strings.confirmDelete );

				confirmDialog( question, { danger: true, confirmLabel: strings.deleteLabel } ).then( function ( ok ) {
					if ( ! ok ) {
						return;
					}

					if ( products > 0 ) {
						detach = '1';
					}

					request( 'delete_formula', { id: button.getAttribute( 'data-id' ), detach: detach } )
					.then( function ( response ) {
						notify( messageFrom( response ), response.success ? 'success' : 'error' );

						if ( response.success ) {
							reloadFormulas();
						}
					} )
					.catch( handleRequestError );
				} );
			} );
		} );
	}

	/**
	 * Open the dialog for an id by asking the server for the row payload.
	 *
	 * @param {string} id Formula id.
	 */
	function openFormulaModalForId( id ) {
		var row = qs( 'tr[data-formula-id="' + id + '"]' );

		if ( row && row.getAttribute( 'data-payload' ) ) {
			openFormulaModal( JSON.parse( row.getAttribute( 'data-payload' ) ) );
			return;
		}

		notify( strings.genericError, 'error' );
	}

	/* ------------------------------------------------------------------ */
	/* Import / Export                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Build the package download URL with the ticked sections.
	 */
	function bindPackageExport() {
		var button = qs( '#rbw-export-package' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var base = button.getAttribute( 'data-base-url' ) || '';
			var sections = qsa( '#rbw-package-export-form input[name="sections[]"]:checked' ).map( function ( input ) {
				return encodeURIComponent( input.value );
			} );

			if ( ! sections.length ) {
				notify( strings.selectOneSection || strings.genericError, 'error' );
				return;
			}

			// `sections[]=a&sections[]=b`
			var query = sections.map( function ( value ) {
				return 'sections%5B%5D=' + value;
			} ).join( '&' );

			window.location.href = base + '&' + query;
		} );
	}

	/**
	 * Render the package dry-run report.
	 *
	 * @param {Object} data Response payload.
	 */
	function renderPackageReport( data ) {
		var box = qs( '#rbw-package-report' );

		if ( ! box ) {
			return;
		}

		var html = '';
		var counts = data.counts || {};

		if ( Object.keys( counts ).length ) {
			html += '<table class="widefat striped rbw-io-table"><thead><tr>' +
				'<th>' + esc( strings.section || 'Section' ) + '</th>' +
				'<th>' + esc( strings.create || 'New' ) + '</th>' +
				'<th>' + esc( strings.update || 'Update' ) + '</th>' +
				'<th>' + esc( strings.skip || 'Skip' ) + '</th>' +
				'<th>' + esc( strings.invalid || 'Invalid' ) + '</th>' +
				'</tr></thead><tbody>';

			Object.keys( counts ).forEach( function ( section ) {
				var row = counts[ section ] || {};

				html += '<tr><th scope="row">' + esc( section ) + '</th>' +
					'<td>' + esc( row.create || 0 ) + '</td>' +
					'<td>' + esc( row.update || 0 ) + '</td>' +
					'<td>' + esc( row.skip || 0 ) + '</td>' +
					'<td>' + esc( row.invalid || 0 ) + '</td></tr>';
			} );

			html += '</tbody></table>';
		}

		if ( data.meta && data.meta.unit_differs ) {
			html += '<p class="rbw-warning">' + esc(
				( strings.unitDiffers || 'The package was exported in a different store unit (%s).' )
					.replace( '%s', ( data.meta.store_unit_from || '' ) + ' → ' + ( data.meta.store_unit_to || '' ) )
			) + '</p>';
		}

		var messages = data.messages || [];

		if ( messages.length ) {
			html += '<ul class="rbw-io-messages">';

			messages.forEach( function ( message ) {
				html += '<li class="is-' + esc( message.level || 'info' ) + '">' +
					'<strong>' + esc( message.section || '' ) + '</strong> ' +
					esc( message.item || '' ) + ': ' + esc( message.message || '' ) + '</li>';
			} );

			html += '</ul>';
		}

		var sample = data.sample || [];

		if ( sample.length ) {
			html += '<table class="widefat striped rbw-io-table"><thead><tr>' +
				'<th>' + esc( strings.item || 'Item' ) + '</th>' +
				'<th>' + esc( strings.action || 'Action' ) + '</th>' +
				'<th>' + esc( strings.oldValue || 'Before' ) + '</th>' +
				'<th>' + esc( strings.newValue || 'After' ) + '</th>' +
				'</tr></thead><tbody>';

			sample.forEach( function ( row ) {
				html += '<tr><td><code dir="ltr">' + esc( row.item ) + '</code></td>' +
					'<td>' + esc( row.action ) + '</td>' +
					'<td dir="ltr">' + esc( ( row.old || [] ).join( ' · ' ) ) + '</td>' +
					'<td dir="ltr">' + esc( ( row.new || [] ).join( ' · ' ) ) + '</td></tr>';
			} );

			html += '</tbody></table>';
		}

		box.innerHTML = html || '<p class="rbw-muted">' + esc( strings.nothingToDo || '—' ) + '</p>';
		box.hidden = false;
	}

	/**
	 * Bind the package import (dry run + execute).
	 */
	function bindPackageImport() {
		var dryRun = qs( '#rbw-package-dry-run' );
		var execute = qs( '#rbw-package-execute' );
		var token = '';

		if ( ! dryRun || ! execute ) {
			return;
		}

		// Step 1: choosing a file unlocks the dry run (2 MB cap mirrors the server).
		bindDropzone( 'rbw-package', {
			accept: '.json',
			maxBytes: 2 * 1024 * 1024,
			onChange: function ( file ) {
				dryRun.disabled = ! file;
				execute.disabled = true;
			}
		} );

		dryRun.addEventListener( 'click', function () {
			var fileInput = qs( '#rbw-package-file' );

			if ( ! fileInput || ! fileInput.files || ! fileInput.files.length ) {
				notify( strings.chooseFile || strings.genericError, 'error' );
				return;
			}

			var body = new FormData();
			body.append( 'action', 'rbw_import_package_dry_run' );
			body.append( 'rbw_action', 'import_package_dry_run' );
			body.append( 'nonce', config.nonce );
			body.append( 'file', fileInput.files[ 0 ] );
			body.append( 'policy', ( qs( '#rbw-package-policy' ) || {} ).value || 'skip' );
			body.append( 'convert', ( qs( '#rbw-package-convert' ) || {} ).value || '1' );

			dryRun.disabled = true;
			var previous = dryRun.textContent;
			dryRun.textContent = strings.working;
			execute.disabled = true;

			window.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( response ) { return response.json(); } )
				.then( function ( response ) {
					if ( ! response.success ) {
						notify( messageFrom( response ), 'error' );
						return;
					}

					token = ( response.data || {} ).token || '';
					renderPackageReport( response.data || {} );
					execute.disabled = ! token;
					notify( messageFrom( response ), 'success' );
				} )
				.catch( handleRequestError )
				.finally( function () {
					dryRun.disabled = false;
					dryRun.textContent = previous;
				} );
		} );

		execute.addEventListener( 'click', function () {
			if ( ! token ) {
				return;
			}

			confirmDialog( strings.confirmImport || strings.genericError, { confirmLabel: strings.importNow || strings.saveButton } ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}

				execute.disabled = true;
				var previous = execute.textContent;
				execute.textContent = strings.working;

				request( 'import_package_execute', { token: token } )
					.then( function ( response ) {
						notify( messageFrom( response ), response.success ? 'success' : 'error' );

						if ( ! response.success ) {
							return;
						}

						token = '';
						setStepDone( 'rbw-package' );
						refreshBackups( ( response.data || {} ).backups );
					} )
					.catch( handleRequestError )
					.finally( function () {
						execute.disabled = true;
						execute.textContent = previous;
					} );
			} );
		} );
	}

	/**
	 * Re-render the backups table.
	 *
	 * @param {Array} backups Backups payload.
	 */
	function refreshBackups( backups ) {
		var table = qs( '#rbw-backups-table' );

		if ( ! table || ! Array.isArray( backups ) ) {
			return;
		}

		var tbody = table.querySelector( 'tbody' );

		if ( ! tbody ) {
			return;
		}

		if ( ! backups.length ) {
			tbody.innerHTML = '<tr class="rbw-empty-row"><td colspan="5">' + esc( strings.noBackups || '—' ) + '</td></tr>';
			return;
		}

		tbody.innerHTML = backups.map( function ( backup ) {
			var counts = backup.counts || {};

			return '<tr data-backup-id="' + esc( backup.id ) + '">' +
				'<td>' + esc( backup.date || '—' ) + '</td>' +
				'<td><code>' + esc( backup.reason || '' ) + '</code></td>' +
				'<td>' + esc( counts.currencies || 0 ) + '</td>' +
				'<td>' + esc( counts.formulas || 0 ) + '</td>' +
				'<td class="rbw-actions"><button type="button" class="button button-small rbw-restore-backup" data-id="' +
				esc( backup.id ) + '">' + esc( strings.restore || 'Restore' ) + '</button></td></tr>';
		} ).join( '' );

		bindRestoreButtons();
	}

	/**
	 * Bind the restore buttons (also after a re-render).
	 */
	function bindRestoreButtons() {
		qsa( '.rbw-restore-backup' ).forEach( function ( button ) {
			if ( button.getAttribute( 'data-rbw-bound' ) ) {
				return;
			}

			button.setAttribute( 'data-rbw-bound', '1' );

			button.addEventListener( 'click', function () {
				confirmDialog( strings.confirmRestore || strings.genericError, { danger: true, confirmLabel: strings.restore || strings.okLabel } ).then( function ( ok ) {
					if ( ! ok ) {
						return;
					}

					button.disabled = true;

					request( 'restore_backup', { id: button.getAttribute( 'data-id' ) } )
						.then( function ( response ) {
							notify( messageFrom( response ), response.success ? 'success' : 'error' );

							if ( response.success ) {
								refreshBackups( ( response.data || {} ).backups );
							}
						} )
						.catch( handleRequestError )
						.finally( function () {
							button.disabled = false;
						} );
				} );
			} );
		} );
	}

	/**
	 * Bind the products CSV import (upload, dry run, chunked progress).
	 */
	function bindCsvImport() {
		var upload = qs( '#rbw-csv-upload' );
		var execute = qs( '#rbw-csv-execute' );
		var progress = qs( '#rbw-csv-progress' );
		var fill = qs( '#rbw-csv-progress-fill' );
		var label = qs( '#rbw-csv-progress-label' );
		var report = qs( '#rbw-csv-report' );
		var token = '';

		if ( ! upload || ! execute ) {
			return;
		}

		// Step 1: choosing a file unlocks the dry run (5 MB cap mirrors the server).
		bindDropzone( 'rbw-csv', {
			accept: '.csv',
			maxBytes: 5 * 1024 * 1024,
			onChange: function ( file ) {
				upload.disabled = ! file;
				execute.disabled = true;
				if ( report ) {
					report.hidden = true;
				}
			}
		} );

		upload.addEventListener( 'click', function () {
			var fileInput = qs( '#rbw-csv-file' );

			if ( ! fileInput || ! fileInput.files || ! fileInput.files.length ) {
				notify( strings.chooseFile || strings.genericError, 'error' );
				return;
			}

			var body = new FormData();
			body.append( 'action', 'rbw_import_products_csv' );
			body.append( 'rbw_action', 'import_products_csv' );
			body.append( 'nonce', config.nonce );
			body.append( 'file', fileInput.files[ 0 ] );
			body.append( 'file_unit', ( qs( '#rbw-csv-unit' ) || {} ).value || 'toman' );

			upload.disabled = true;
			var previous = upload.textContent;
			upload.textContent = strings.working;

			window.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( response ) { return response.json(); } )
				.then( function ( response ) {
					if ( ! response.success ) {
						notify( messageFrom( response ), 'error' );
						return;
					}

					var data = response.data || {};
					token = data.token || '';
					execute.disabled = ! token;

					if ( report ) {
						var dry = data.dry_run || {};
						var html = '<p><strong>' + esc(
							( strings.rowsRead || '%d rows read.' ).replace( '%d', String( data.rows || 0 ) )
						) + '</strong> ' + esc(
							( strings.validInvalid || 'Valid: %1$d, invalid: %2$d.' )
								.replace( '%1$d', String( dry.valid || 0 ) )
								.replace( '%2$d', String( dry.invalid || 0 ) )
						) + '</p>';

						var messages = dry.messages || [];

						if ( messages.length ) {
							html += '<ul class="rbw-io-messages">';

							messages.forEach( function ( message ) {
								html += '<li class="is-error">' + esc(
									( strings.row || 'Row' ) + ' ' + ( message.row || '' ) + ': ' + ( message.message || '' )
								) + '</li>';
							} );

							html += '</ul>';
						}

						report.innerHTML = html;
						report.hidden = false;
					}

					if ( progress ) {
						progress.hidden = false;
						if ( fill ) {
							fill.style.width = '0%';
						}
						if ( label ) {
							label.textContent = '0%';
						}
					}

					notify( messageFrom( response ), 'success' );
				} )
				.catch( handleRequestError )
				.finally( function () {
					upload.disabled = false;
					upload.textContent = previous;
				} );
		} );

		execute.addEventListener( 'click', function () {
			if ( ! token ) {
				return;
			}

			execute.disabled = true;

			/**
			 * Import one chunk, then continue until done.
			 *
			 * @param {number} offset Row offset.
			 */
			function step( offset ) {
				request( 'import_products_chunk', { token: token, offset: offset } )
					.then( function ( response ) {
						if ( ! response.success ) {
							notify( messageFrom( response ), 'error' );
							execute.disabled = false;
							return;
						}

						var data = response.data || {};

						if ( fill ) {
							fill.style.width = ( data.percent || 0 ) + '%';
						}

						if ( label ) {
							label.textContent = ( data.percent || 0 ) + '% (' + ( data.offset || 0 ) + '/' + ( data.total || 0 ) + ')';
						}

						if ( data.done ) {
							notify( messageFrom( response ), 'success' );
							refreshBackups();
							window.setTimeout( function () {
								window.location.reload();
							}, 1200 );
							return;
						}

						step( data.offset || 0 );
					} )
					.catch( function ( error ) {
						handleRequestError( error );
						execute.disabled = false;
					} );
			}

			step( 0 );
		} );
	}

	/**
	 * Bind the whole Import/Export tab.
	 */
	/**
	 * Turn a stepper into a drag-and-drop file picker.
	 *
	 * The native <input type="file"> is visually hidden but stays functional, so
	 *:
	 *  - clicking the zone opens the OS picker (label semantics + programmatic
	 *    click), which is also what mobile browsers need;
	 *  - Enter/Space on the focused zone does the same (keyboard path);
	 *  - dropping a file assigns it to the input via DataTransfer, so the rest of
	 *    the code keeps reading `fileInput.files[0]` and no contract changes.
	 *
	 * No English browser chrome («Choose File / No file chosen») is ever shown,
	 * because the input itself is never rendered.
	 *
	 * @param {string} id        Stepper prefix ('rbw-package' or 'rbw-csv').
	 * @param {Object} opts      Options.
	 * @param {string} opts.accept      Comma-separated extensions, e.g. '.json'.
	 * @param {number} opts.maxBytes    Hard size limit.
	 * @param {Function} opts.onChange  Called after a valid file is selected.
	 * @param {string} opts.stepActions  Selector for the buttons enabled on select.
	 * @return {HTMLInputElement|null} The file input, or null when absent.
	 */
	function bindDropzone( id, opts ) {
		var zone = qs( '[data-dropzone="' + id + '"]' );
		var input = qs( '#' + id + '-file' );
		var chosen = qs( '[data-file-chosen="' + id + '"]' );

		if ( ! zone || ! input ) {
			return null;
		}

		var steps = zone.parentNode.querySelectorAll( '.rbw-step' );
		var extensions = ( opts.accept || '' ).split( ',' )
			.map( function ( part ) { return part.trim().toLowerCase(); } )
			.filter( function ( part ) { return part.charAt( 0 ) === '.'; } );

		/**
		 * Reflect the current file in the zone, the label and the stepper.
		 *
		 * @param {File|null} file Selected file.
		 */
		function reflect( file ) {
			var label = zone.querySelector( '.rbw-dropzone-label' );
			var hint = zone.querySelector( '.rbw-dropzone-hint' );
			var isValid = !! file;

			zone.classList.toggle( 'is-filled', isValid );
			zone.classList.remove( 'is-error' );

			if ( label ) {
				label.textContent = isValid ? file.name : ( label.getAttribute( 'data-default' ) || label.textContent );
			}

			if ( hint ) {
				hint.textContent = isValid ? formatBytes( file.size ) : ( hint.getAttribute( 'data-default' ) || hint.textContent );
			}

			if ( chosen ) {
				if ( isValid ) {
					chosen.innerHTML = '<strong>' + esc( file.name ) + '</strong>' +
						'<span>' + esc( formatBytes( file.size ) ) + '</span>';
					chosen.hidden = false;
				} else {
					chosen.textContent = '';
					chosen.hidden = true;
				}
			}

			// Step 1 done; step 2 becomes the active one.
			if ( steps.length ) {
				Array.prototype.forEach.call( steps, function ( step, index ) {
					step.classList.toggle( 'is-done', isValid && 0 === index );
					step.classList.toggle( 'is-active', isValid ? 1 === index : 0 === index );
				} );
			}

			if ( opts.onChange ) {
				opts.onChange( file );
			}
		}

		/**
		 * Validate and accept a file.
		 *
		 * @param {File} file Candidate file.
		 */
		function accept( file ) {
			zone.classList.remove( 'is-error' );

			if ( ! file ) {
				return;
			}

			var name = String( file.name || '' ).toLowerCase();
			var okExt = ! extensions.length || extensions.some( function ( ext ) {
				return name.lastIndexOf( ext ) === name.length - ext.length;
			} );

			if ( ! okExt ) {
				zone.classList.add( 'is-error' );
				notify( strings.wrongFileType || strings.genericError, 'error' );
				return;
			}

			if ( opts.maxBytes && file.size > opts.maxBytes ) {
				zone.classList.add( 'is-error' );
				notify( strings.fileTooLarge || strings.genericError, 'error' );
				return;
			}

			reflect( file );
		}

		// Remember the original copy so clearing the file restores it.
		[ '.rbw-dropzone-label', '.rbw-dropzone-hint' ].forEach( function ( sel ) {
			var node = zone.querySelector( sel );

			if ( node ) {
				node.setAttribute( 'data-default', node.textContent );
			}
		} );

		zone.addEventListener( 'click', function () {
			input.click();
		} );

		zone.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key || ' ' === event.key || 'Spacebar' === event.key ) {
				event.preventDefault();
				input.click();
			}
		} );

		// Dropping on the window would otherwise navigate away from the admin.
		[ 'dragenter', 'dragover' ].forEach( function ( type ) {
			zone.addEventListener( type, function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				zone.classList.add( 'is-dragover' );
			} );
		} );

		[ 'dragleave', 'dragend' ].forEach( function ( type ) {
			zone.addEventListener( type, function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				zone.classList.remove( 'is-dragover' );
			} );
		} );

		zone.addEventListener( 'drop', function ( event ) {
			event.preventDefault();
			event.stopPropagation();
			zone.classList.remove( 'is-dragover' );

			var files = event.dataTransfer && event.dataTransfer.files;

			if ( ! files || ! files.length ) {
				return;
			}

			// Assign through DataTransfer so `input.files` is populated as if the
			// user had used the picker.
			try {
				var transfer = new DataTransfer();
				transfer.items.add( files[ 0 ] );
				input.files = transfer.files;
			} catch ( error ) {
				// Older browsers cannot set `input.files`; fall back to a soft state.
				zone.setAttribute( 'data-dropped-name', files[ 0 ].name );
			}

			accept( files[ 0 ] );
		} );

		input.addEventListener( 'change', function () {
			accept( input.files && input.files[ 0 ] );
		} );

		reflect( input.files && input.files[ 0 ] );

		return input;
	}

	/**
	 * Human-readable byte size.
	 *
	 * @param {number} bytes Size in bytes.
	 * @return {string}
	 */
	function formatBytes( bytes ) {
		var size = Number( bytes ) || 0;

		if ( size < 1024 ) {
			return size + ' B';
		}

		if ( size < 1024 * 1024 ) {
			return ( size / 1024 ).toFixed( 1 ) + ' KB';
		}

		return ( size / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MB';
	}

	/**
	 * Mark the final stepper step as the active one (after a successful run).
	 *
	 * @param {string} id Stepper prefix.
	 * @return {void}
	 */
	function setStepDone( id ) {
		var zone = qs( '[data-dropzone="' + id + '"]' );

		if ( ! zone ) {
			return;
		}

		var list = zone.parentNode.querySelectorAll( '.rbw-step' );

		Array.prototype.forEach.call( list, function ( step ) {
			step.classList.add( 'is-done' );
			step.classList.remove( 'is-active' );
		} );
	}

	function bindImportExport() {
		if ( ! qs( '#rbw-package-export-form' ) && ! qs( '#rbw-csv-import-form' ) ) {
			return;
		}

		bindPackageExport();
		bindPackageImport();
		bindCsvImport();
		bindRestoreButtons();
	}

	/**
	 * Accessible tablist: arrow keys move between tabs, Home/End jump.
	 *
	 * Activation follows focus (both arrow directions map to visual order, which
	 * RTL reverses for free because the DOM order is what matters).
	 */
	function bindTabs() {
		var list = qs( '.rbw-tabs[role="tablist"]' );

		if ( ! list ) {
			return;
		}

		var tabs = qsa( '[role="tab"]', list );

		tabs.forEach( function ( tab, index ) {
			tab.addEventListener( 'keydown', function ( event ) {
				var next = null;

				if ( 'ArrowRight' === event.key || 'ArrowDown' === event.key ) {
					next = tabs[ ( index + 1 ) % tabs.length ];
				} else if ( 'ArrowLeft' === event.key || 'ArrowUp' === event.key ) {
					next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
				} else if ( 'Home' === event.key ) {
					next = tabs[ 0 ];
				} else if ( 'End' === event.key ) {
					next = tabs[ tabs.length - 1 ];
				}

				if ( ! next ) {
					return;
				}

				event.preventDefault();
				next.focus();

				// Deep-link navigation is the existing mechanic (?tab=…).
				window.location.href = next.getAttribute( 'href' );
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Kebab menus                                                         */
	/* ------------------------------------------------------------------ */

	/* ------------------------------------------------------------------ */
	/* Kebab menus (portalled)                                             */
	/* ------------------------------------------------------------------ */

	/*
	 * A kebab menu cannot live inside the table it belongs to: `.rbw-table`
	 * needs `overflow: hidden` for its rounded corners and `overflow-x: auto`
	 * on mobile, and either one clips an absolutely-positioned popup. The last
	 * rows of a list are the worst case because the menu opens downward.
	 *
	 * So on open the menu ELEMENT is moved to <body> (a "portal"), switched to
	 * `position: fixed` and placed from the toggle's bounding rect. The original
	 * parent and next sibling are remembered and the menu is put back on close,
	 * so event delegation, AJAX re-renders and the existing CSS keep working.
	 */
	var OPEN_MENU = null; // { menu, toggle, wrap, parent, next, onScroll, onResize }.

	/**
	 * Items inside a menu that can receive focus.
	 *
	 * @param {Element} menu Menu element.
	 * @return {Element[]} Focusable items.
	 */
	function menuItems( menu ) {
		return qsa( '.rbw-menu-item', menu ).filter( function ( item ) {
			return ! item.disabled;
		} );
	}

	/**
	 * Put a portalled menu back where it came from.
	 *
	 * Safe to call twice, and safe when the original parent was destroyed by an
	 * AJAX re-render (the menu is then dropped instead of being orphaned).
	 *
	 * @param {Object} state Portal state.
	 * @return {void}
	 */
	function restoreMenu( state ) {
		var menu = state.menu;

		if ( ! menu ) {
			return;
		}

		menu.hidden = true;
		menu.classList.remove( 'rbw-menu--portal' );
		menu.style.top = '';
		menu.style.left = '';
		menu.style.bottom = '';
		menu.style.right = '';
		menu.removeAttribute( 'data-placement' );

		window.removeEventListener( 'scroll', state.onScroll, true );
		window.removeEventListener( 'resize', state.onResize );

		if ( state.parent && state.parent.isConnected ) {
			if ( state.next && state.next.parentNode === state.parent ) {
				state.parent.insertBefore( menu, state.next );
			} else {
				state.parent.appendChild( menu );
			}
		} else if ( menu.parentNode ) {
			// The row was re-rendered away: do not leave an orphan in <body>.
			menu.parentNode.removeChild( menu );
		}
	}

	/**
	 * Position a portalled menu against its toggle.
	 *
	 * Aligns the menu's inline-end edge with the toggle's inline-end edge (RTL and
	 * LTR aware), clamps it inside the viewport with an 8px margin and flips it
	 * above the toggle when there is not enough room below.
	 *
	 * @param {Object} state Portal state.
	 * @return {void}
	 */
	function positionMenu( state ) {
		var menu = state.menu;
		var toggle = state.toggle;

		if ( ! menu || ! toggle || ! toggle.isConnected || menu.hidden ) {
			return;
		}

		var margin = 8;
		var gap = 4;
		var rect = toggle.getBoundingClientRect();
		var rtl = 'rtl' === window.getComputedStyle( document.documentElement ).direction;

		// Reset so the natural size can be measured.
		menu.style.top = '0px';
		menu.style.left = '0px';

		var size = menu.getBoundingClientRect();
		var viewportW = document.documentElement.clientWidth || window.innerWidth;
		var viewportH = document.documentElement.clientHeight || window.innerHeight;

		// Inline-end alignment: RTL anchors the right edge, LTR the left edge.
		var left = rtl ? rect.right - size.width : rect.left;

		// Vertical placement with flip.
		var spaceBelow = viewportH - rect.bottom - margin;
		var spaceAbove = rect.top - margin;
		var top = rect.bottom + gap;
		var flip = spaceBelow < size.height && spaceAbove > spaceBelow;

		if ( flip ) {
			top = rect.top - size.height - gap;
		}

		if ( top < margin ) {
			top = margin;
		}

		if ( top + size.height > viewportH - margin ) {
			top = Math.max( margin, viewportH - margin - size.height );
		}

		if ( left < margin ) {
			left = margin;
		}

		if ( left + size.width > viewportW - margin ) {
			left = Math.max( margin, viewportW - margin - size.width );
		}

		menu.style.top = Math.round( top ) + 'px';
		menu.style.left = Math.round( left ) + 'px';
		menu.setAttribute( 'data-placement', flip ? 'top' : 'bottom' );
	}

	/**
	 * Close the open kebab menu, if any.
	 *
	 * @param {Object} [options]           Options.
	 * @param {boolean} [options.focusToggle] Return focus to the trigger.
	 * @return {void}
	 */
	function closeMenus( options ) {
		var state = OPEN_MENU;

		if ( ! state ) {
			return;
		}

		OPEN_MENU = null;

		var toggle = state.toggle;

		restoreMenu( state );

		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', 'false' );
		}

		if ( options && options.focusToggle && toggle && toggle.isConnected ) {
			toggle.focus();
		}
	}

	/**
	 * Open a kebab menu as a portal.
	 *
	 * @param {Element} toggle The trigger button.
	 * @return {void}
	 */
	function openMenu( toggle ) {
		var wrap = toggle.closest( '.rbw-kebab' );
		var menu = wrap ? qs( '.rbw-menu', wrap ) : null;

		if ( ! menu ) {
			return;
		}

		// Single-open policy: never leave a previous menu behind.
		closeMenus();

		var state = {
			menu: menu,
			toggle: toggle,
			wrap: wrap,
			parent: menu.parentNode,
			next: menu.nextSibling,
			onScroll: null,
			onResize: null
		};

		state.onScroll = function () {
			// Capture phase: catches any scrolled ancestor, not just the window.
			positionMenu( state );
		};

		state.onResize = function () {
			positionMenu( state );
		};

		menu.classList.add( 'rbw-menu--portal' );
		document.body.appendChild( menu );
		menu.hidden = false;

		positionMenu( state );

		window.addEventListener( 'scroll', state.onScroll, true );
		window.addEventListener( 'resize', state.onResize );

		toggle.setAttribute( 'aria-expanded', 'true' );
		OPEN_MENU = state;

		var first = menuItems( menu )[ 0 ];

		if ( first ) {
			first.focus();
		}
	}

	/**
	 * Bind the kebab menus (click + keyboard).
	 *
	 * Delegated from `document` so rows re-rendered by AJAX keep working without a
	 * re-bind, and so the portalled menu (now a child of <body>) still receives
	 * its own clicks.
	 */
	function bindMenus() {
		document.addEventListener( 'click', function ( event ) {
			var toggle = event.target.closest && event.target.closest( '.rbw-kebab-toggle' );

			if ( toggle ) {
				event.preventDefault();
				event.stopPropagation();

				if ( OPEN_MENU && OPEN_MENU.toggle === toggle ) {
					closeMenus();
				} else {
					openMenu( toggle );
				}

				return;
			}

			// A click on a menu ITEM must reach its own delegated handler, so it is
			// not treated as an outside click. Dismissing on item click is done by
			// the action handlers themselves (they close the menu after acting).
			if ( event.target.closest && event.target.closest( '.rbw-menu' ) ) {
				return;
			}

			closeMenus();
		} );

		document.addEventListener( 'keydown', function ( event ) {
			var toggle = event.target.closest && event.target.closest( '.rbw-kebab-toggle' );

			if ( toggle && ! OPEN_MENU ) {
				// Enter/Space is handled by the click handler; arrows open directly.
				if ( 'ArrowDown' === event.key || 'ArrowUp' === event.key ) {
					event.preventDefault();
					openMenu( toggle );

					var opened = OPEN_MENU ? menuItems( OPEN_MENU.menu ) : [];
					var target = 'ArrowUp' === event.key ? opened[ opened.length - 1 ] : opened[ 0 ];

					if ( target ) {
						target.focus();
					}
				}

				return;
			}

			if ( ! OPEN_MENU ) {
				return;
			}

			if ( 'Escape' === event.key ) {
				event.preventDefault();
				closeMenus( { focusToggle: true } );
				return;
			}

			if ( 'Tab' === event.key ) {
				// Tab navigation leaving the menu closes it. Focus is never stolen,
				// so the browser keeps its normal tab order.
				var inside = OPEN_MENU.menu.contains( document.activeElement );

				if ( ! inside ) {
					return;
				}

				if ( event.shiftKey ) {
					closeMenus( { focusToggle: true } );
					return;
				}

				window.setTimeout( function () {
					if ( OPEN_MENU && ! OPEN_MENU.menu.contains( document.activeElement ) ) {
						closeMenus();
					}
				}, 0 );

				return;
			}

			if ( 'ArrowDown' !== event.key && 'ArrowUp' !== event.key && 'Home' !== event.key && 'End' !== event.key ) {
				return;
			}

			if ( ! OPEN_MENU.menu.contains( document.activeElement ) ) {
				return;
			}

			var list = menuItems( OPEN_MENU.menu );

			if ( ! list.length ) {
				return;
			}

			event.preventDefault();

			var index = list.indexOf( document.activeElement );
			var nextIndex = 0;

			if ( 'ArrowDown' === event.key ) {
				nextIndex = ( index + 1 ) % list.length;
			} else if ( 'ArrowUp' === event.key ) {
				nextIndex = ( index - 1 + list.length ) % list.length;
			} else if ( 'End' === event.key ) {
				nextIndex = list.length - 1;
			}

			if ( list[ nextIndex ] ) {
				list[ nextIndex ].focus();
			}
		} );

		// A re-render that detaches the toggle must also drop its portal.
		if ( window.MutationObserver ) {
			var observer = new MutationObserver( function () {
				if ( OPEN_MENU && ! OPEN_MENU.toggle.isConnected ) {
					closeMenus();
				}
			} );

			observer.observe( document.body, { childList: true, subtree: true } );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Currencies: client-side search + filters                            */
	/* ------------------------------------------------------------------ */

	var CURRENCY_FILTER_KEY = 'rbwCurrencyFilter';

	/**
	 * Apply the search box and the two selects to the currency rows.
	 */
	function applyCurrencyFilters() {
		var table = qs( '#rbw-currencies-table' );

		if ( ! table ) {
			return;
		}

		var search = qs( '#rbw-currency-search' );
		var status = qs( '#rbw-filter-status' );
		var provider = qs( '#rbw-filter-provider' );
		var count = qs( '#rbw-filter-count' );

		var term = search ? String( search.value || '' ).toLowerCase().trim() : '';
		var wantStatus = status ? status.value : '';
		var wantProvider = provider ? provider.value : '';

		var rows = qsa( 'tbody tr[data-id]', table );
		var shown = 0;

		rows.forEach( function ( row ) {
			var haystack = row.getAttribute( 'data-search' ) || '';
			var rowStatus = row.getAttribute( 'data-status' ) || '';
			var rowProvider = row.getAttribute( 'data-provider' ) || '';

			var match = ( ! term || haystack.indexOf( term ) !== -1 ) &&
				( ! wantStatus || rowStatus === wantStatus ) &&
				( ! wantProvider || rowProvider === wantProvider );

			row.hidden = ! match;

			if ( match ) {
				++shown;
			}
		} );

		if ( count ) {
			count.textContent = rows.length
				? ( strings.showingCount || '%1$d of %2$d' ).replace( '%1$d', shown ).replace( '%2$d', rows.length )
				: '';
		}

		try {
			window.sessionStorage.setItem(
				CURRENCY_FILTER_KEY,
				JSON.stringify( { term: term, status: wantStatus, provider: wantProvider } )
			);
		} catch ( e ) {
			// Storage can be disabled: the filters still work for this page view.
		}
	}

	/**
	 * Restore the persisted filters and bind the controls.
	 */
	function bindCurrencyFilters() {
		var search = qs( '#rbw-currency-search' );

		if ( ! search ) {
			return;
		}

		try {
			var saved = JSON.parse( window.sessionStorage.getItem( CURRENCY_FILTER_KEY ) || '{}' );

			if ( saved.term ) {
				search.value = saved.term;
			}

			if ( saved.status && qs( '#rbw-filter-status' ) ) {
				qs( '#rbw-filter-status' ).value = saved.status;
			}

			if ( saved.provider && qs( '#rbw-filter-provider' ) ) {
				qs( '#rbw-filter-provider' ).value = saved.provider;
			}
		} catch ( e ) {
			// Ignore malformed storage.
		}

		[ '#rbw-currency-search', '#rbw-filter-status', '#rbw-filter-provider' ].forEach( function ( selector ) {
			var field = qs( selector );

			if ( ! field ) {
				return;
			}

			field.addEventListener( 'input', applyCurrencyFilters );
			field.addEventListener( 'change', applyCurrencyFilters );
		} );

		applyCurrencyFilters();
	}

	/* ------------------------------------------------------------------ */
	/* Modal focus management + unsaved-change guard                       */
	/* ------------------------------------------------------------------ */

	var modalSnapshots = {};

	/**
	 * Serialise the current values of a form (for the unsaved-changes check).
	 *
	 * @param {HTMLElement} form Form element.
	 * @return {string} Snapshot.
	 */
	function snapshotForm( form ) {
		var parts = [];

		qsa( 'input, select, textarea', form ).forEach( function ( field ) {
			if ( ! field.name || 'hidden' === field.type ) {
				return;
			}

			parts.push( field.name + '=' + ( field.type === 'checkbox' ? ( field.checked ? '1' : '0' ) : field.value ) );
		} );

		return parts.join( '|' );
	}

	/**
	 * Track open modals: remember the trigger, trap nothing (native dialog
	 * semantics are enough here) but close on ESC and warn about lost edits.
	 */
	function bindModalShell() {
		qsa( '.rbw-modal[id]' ).forEach( function ( modal ) {
			var form = qs( 'form', modal );

			if ( ! form ) {
				return;
			}

			// Snapshot when opened (the open functions set hidden = false).
			var observer = new window.MutationObserver( function () {
				if ( ! modal.hidden ) {
					modalSnapshots[ modal.id ] = snapshotForm( form );
				}
			} );

			observer.observe( modal, { attributes: true, attributeFilter: [ 'hidden' ] } );

			qsa( '[data-rbw-close]', modal ).forEach( function ( closer ) {
				closer.addEventListener( 'click', function ( event ) {
					var before = modalSnapshots[ modal.id ];

					if ( before && before !== snapshotForm( form ) ) {
						event.preventDefault();
						event.stopImmediatePropagation();

						confirmDialog( strings.confirmDiscard || strings.genericError ).then( function ( ok ) {
							if ( ok ) {
								modal.hidden = true;
							}
						} );
					}
				}, true );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindToolbar();
		bindTable();
		bindModal();
		bindRuleFields();
		bindGeneral();
		bindStaleNotice();
		bindSymbolPicker();
		bindPresets();
		bindSources();
		bindFormulas();
		bindImportExport();
		bindTabs();
		bindMenus();
		bindCurrencyFilters();
		bindModalShell();
	} );
}() );
