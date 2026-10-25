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
	 * @param {string} action  rbw_action value.
	 * @param {Object} payload Extra parameters.
	 * @return {Promise<Object>} Parsed JSON response.
	 */
	function request( action, payload ) {
		var body = new URLSearchParams();
		body.append( 'action', 'rbw_' + action );
		body.append( 'rbw_action', action );
		body.append( 'nonce', config.nonce );

		Object.keys( payload || {} ).forEach( function ( key ) {
			body.append( key, payload[ key ] === true ? '1' : payload[ key ] === false ? '0' : String( payload[ key ] ) );
		} );

		return window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					throw new Error( strings.genericError );
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

	function bindTable() {
		qsa( '.rbw-toggle' ).forEach( function ( input ) {
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
						notify( error.message, 'error' );
					} );
			} );
		} );

		qsa( '.rbw-delete' ).forEach( function ( button ) {
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
						notify( error.message, 'error' );
					} );
			} );
		} );

		qsa( '.rbw-edit' ).forEach( function ( button ) {
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
						notify( error.message, 'error' );
					} );
			} );
		} );

		qsa( '.rbw-update-one' ).forEach( function ( button ) {
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
				notify( error.message, 'error' );
			} )
			.finally( function () {
				button.disabled = false;
				button.textContent = previous;
			} );
	}

	/**
	 * Patch one or more rows with fresh data returned by the server.
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

			var nameCell = qs( 'td:nth-child(2)', row );
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
		} );
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
		modal.querySelector( '[name="provider_symbol"]' ).value = isEdit ? currency.provider_symbol : '';
		modal.querySelector( '[name="rate"]' ).value = isEdit && currency.rate > 0 ? String( currency.rate ) : '';
		modal.querySelector( '[name="rate"]' ).placeholder = isEdit ? strings.emptyRate : '';
		modal.querySelector( '[name="update_interval_minutes"]' ).value = isEdit ? currency.update_interval_minutes : ( config.minInterval || 60 );
		modal.querySelector( '[name="sort_order"]' ).value = isEdit ? currency.sort_order : 0;
		modal.querySelector( '[name="is_enabled"]' ).checked = isEdit ? !! currency.is_enabled : false;

		modal.hidden = false;

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
						window.location.reload();
					} )
					.catch( function ( error ) {
						notify( error.message, 'error' );
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
					notify( error.message, 'error' );
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
						notify( error.message, 'error' );
					} );
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindToolbar();
		bindTable();
		bindModal();
		bindGeneral();
	} );
}() );
