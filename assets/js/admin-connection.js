( function( window, document, settings ) {
	'use strict';

	if ( ! settings ) {
		return;
	}

	var PAIRING_POLL_DELAY_MS = 5000;
	var PAIRING_POLL_MAX_DELAY_MS = 60000;
	var SYNC_STEP_DELAY_MS = 100;
	var SYNC_BUSY_DELAY_MS = 1000;
	var SYNC_MAX_BUSY_RETRIES = 90;
	var pairingPollFailures = 0;

	function request( action ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', settings.nonce );

		return window.fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function( response ) {
			return response.json();
		} );
	}

	function setBusy( button, busy ) {
		if ( ! button ) {
			return;
		}
		button.disabled = busy;
		if ( busy ) {
			button.dataset.originalLabel = button.textContent;
			button.textContent = settings.labels.working;
		} else if ( button.dataset.originalLabel ) {
			button.textContent = button.dataset.originalLabel;
		}
	}

	function reloadAfterSuccess( result ) {
		if ( result && result.success ) {
			window.location.reload();
			return;
		}
		window.alert( settings.labels.error );
	}

	function safeResultMessage( result ) {
		return result && result.data && 'string' === typeof result.data.message && result.data.message
			? result.data.message
			: settings.labels.error;
	}

	function closeDashboardWindow( dashboardWindow ) {
		if ( dashboardWindow && ! dashboardWindow.closed && typeof dashboardWindow.close === 'function' ) {
			dashboardWindow.close();
		}
	}

	function pollPairing() {
		if ( ! settings.pairingActive ) {
			return;
		}
		request( 'spellexo_woocommerce_poll_pairing' ).then( function( result ) {
			if ( result && result.success && result.data && 'pairing' === result.data.status ) {
				pairingPollFailures = 0;
				window.setTimeout( pollPairing, PAIRING_POLL_DELAY_MS );
				return;
			}
			if ( result && result.success ) {
				window.location.reload();
				return;
			}
			retryPairingPoll();
		} ).catch( function() {
			retryPairingPoll();
		} );
	}

	/* A signed/bootstrap error can be temporary; cap delay but keep polling. */
	function retryPairingPoll() {
		var delay = Math.min( PAIRING_POLL_MAX_DELAY_MS, PAIRING_POLL_DELAY_MS * Math.pow( 2, pairingPollFailures ) );

		pairingPollFailures += 1;
		window.setTimeout( pollPairing, delay );
	}

	var connectButton = document.querySelector( '[data-spellexo-connect]' );
	var syncButton = document.querySelector( '[data-spellexo-sync]' );
	var disconnectButton = document.querySelector( '[data-spellexo-disconnect]' );
	var copyButton = document.querySelector( '[data-spellexo-copy-diagnostics]' );

	if ( connectButton ) {
		connectButton.addEventListener( 'click', function() {
			var dashboardWindow = window.open( '', 'spellexo-connect' );
			setBusy( connectButton, true );
			request( 'spellexo_woocommerce_begin_pairing' ).then( function( result ) {
				if ( result && result.success && result.data.dashboard_url ) {
					if ( dashboardWindow ) {
						dashboardWindow.opener = null;
						dashboardWindow.location.href = result.data.dashboard_url;
					} else {
						window.location.href = result.data.dashboard_url;
					}
					window.location.reload();
					return;
				}
				closeDashboardWindow( dashboardWindow );
				setBusy( connectButton, false );
				window.alert( settings.labels.error );
			} ).catch( function() {
				closeDashboardWindow( dashboardWindow );
				setBusy( connectButton, false );
				window.alert( settings.labels.error );
			} );
		} );
	}

	if ( syncButton ) {
		syncButton.addEventListener( 'click', function() {
			setBusy( syncButton, true );

			function runStep( busyRetries ) {
				request( 'spellexo_woocommerce_queue_sync' ).then( function( result ) {
					var data = result && result.data ? result.data : {};

					if ( ! result || ! result.success ) {
						setBusy( syncButton, false );
						window.alert( safeResultMessage( result ) );
						return;
					}

					if ( data.message ) {
						syncButton.textContent = data.message;
					}

					if ( 'completed' === data.status ) {
						setBusy( syncButton, false );
						window.location.reload();
						return;
					}

					if ( 'running' === data.status ) {
						window.setTimeout( function() { runStep( 0 ); }, SYNC_STEP_DELAY_MS );
						return;
					}

					if ( 'busy' === data.status && busyRetries < SYNC_MAX_BUSY_RETRIES ) {
						window.setTimeout( function() { runStep( busyRetries + 1 ); }, SYNC_BUSY_DELAY_MS );
						return;
					}

					setBusy( syncButton, false );
					window.alert( safeResultMessage( result ) );
				} ).catch( function() {
					setBusy( syncButton, false );
					window.alert( settings.labels.error );
				} );
			}

			runStep( 0 );
		} );
	}

	if ( disconnectButton ) {
		disconnectButton.addEventListener( 'click', function() {
			if ( ! window.confirm( settings.labels.disconnectConfirm ) ) {
				return;
			}
			setBusy( disconnectButton, true );
			request( 'spellexo_woocommerce_disconnect' ).then( function( result ) {
				setBusy( disconnectButton, false );
				reloadAfterSuccess( result );
			} ).catch( function() {
				setBusy( disconnectButton, false );
				window.alert( settings.labels.error );
			} );
		} );
	}

	if ( copyButton ) {
		copyButton.addEventListener( 'click', function() {
			var feedback = document.querySelector( '[data-spellexo-diagnostics-feedback]' );
			var fallback = document.querySelector( '[data-spellexo-diagnostics-fallback]' );
			var diagnostics;
			function showFallback() {
				if ( feedback ) {
					feedback.textContent = settings.labels.diagnosticsCopyFallback;
				}
				if ( fallback ) {
					fallback.value = diagnostics;
					fallback.hidden = false;
					fallback.focus();
					fallback.select();
				}
			}
			try {
				diagnostics = JSON.stringify( JSON.parse( copyButton.dataset.spellexoDiagnostics ), null, 2 );
				if ( fallback ) {
					fallback.hidden = true;
				}
				if ( ! window.navigator.clipboard || typeof window.navigator.clipboard.writeText !== 'function' ) {
					showFallback();
					return;
				}
				Promise.resolve( window.navigator.clipboard.writeText( diagnostics ) ).then( function() {
					if ( feedback ) {
						feedback.textContent = settings.labels.diagnosticsCopied;
					}
				} ).catch( showFallback );
			} catch ( error ) {
				if ( diagnostics ) {
					showFallback();
				} else if ( feedback ) {
					feedback.textContent = settings.labels.error;
				}
			}
		} );
	}

	pollPairing();
} )( window, document, window.SpellexoWooCommerceAdmin );
