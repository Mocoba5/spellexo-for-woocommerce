/* global module */
( function( window, document ) {
	'use strict';

	var ELIGIBILITY_PATH = '/v1/storefront/wordpress/eligibility';
	var CLICK_EVENT_PATH = '/v1/storefront/wordpress/events/click';
	var STORE_API_NAMESPACE = 'spellexo-for-woocommerce';
	var REQUEST_TIMEOUT_MS = 5000;
	var TOKEN_REFRESH_WINDOW_MS = 20000;
	var activeController = null;

	function createPageViewId() {
		var bytes;
		var index;

		if ( window && window.crypto && window.crypto.randomUUID ) {
			return window.crypto.randomUUID();
		}

		if ( window && window.crypto && window.crypto.getRandomValues ) {
			bytes = new Uint8Array( 16 );
			window.crypto.getRandomValues( bytes );
			bytes[ 6 ] = ( bytes[ 6 ] & 15 ) | 64;
			bytes[ 8 ] = ( bytes[ 8 ] & 63 ) | 128;
			return Array.prototype.map.call( bytes, function( byte, byteIndex ) {
				var value = byte.toString( 16 );
				if ( 4 === byteIndex || 6 === byteIndex || 8 === byteIndex || 10 === byteIndex ) {
					return '-' + ( value.length === 1 ? '0' + value : value );
				}
				return value.length === 1 ? '0' + value : value;
			} ).join( '' );
		}

		bytes = '';
		for ( index = 0; index < 32; index++ ) {
			bytes += Math.floor( Math.random() * 16 ).toString( 16 );
		}
		return bytes.slice( 0, 8 ) + '-' + bytes.slice( 8, 12 ) + '-4' + bytes.slice( 13, 16 ) + '-8' + bytes.slice( 17, 20 ) + '-' + bytes.slice( 20 );
	}

	function normalizeVariantId( value ) {
		var stringValue = String( value || '' ).trim();

		if ( /^variation:\d+$/.test( stringValue ) || /^product:\d+$/.test( stringValue ) ) {
			return stringValue;
		}

		return /^\d+$/.test( stringValue ) ? 'variation:' + stringValue : '';
	}

	function parseConfig( mount ) {
		var raw = mount.getAttribute( 'data-spellexo-woocommerce-config' );
		var config;

		if ( ! raw ) {
			return null;
		}

		try {
			config = JSON.parse( raw );
		} catch ( error ) {
			return null;
		}

		if ( ! config || ! /^[A-Za-z0-9_-]{32,128}$/.test( String( config.publicStoreKey || '' ) ) || ! /^\d+$/.test( String( config.productId || '' ) ) ) {
			return null;
		}

		return config;
	}

	function safeHttpsUrl( rawUrl ) {
		var parsed;

		try {
			parsed = new URL( rawUrl );
		} catch ( error ) {
			return null;
		}

		return 'https:' === parsed.protocol ? parsed : null;
	}

	function buildEligibilityPayload( config, variantId, pageViewId ) {
		return {
			schema_version: 1,
			public_store_key: config.publicStoreKey,
			product_id: String( config.productId ),
			variant_id: variantId,
			page_view_id: pageViewId,
			integration_version: Number( config.integrationVersion || 1 )
		};
	}

	function validEligibility( payload ) {
		return !! ( payload && 1 === payload.schema_version && true === payload.eligible && typeof payload.launch_token === 'string' && payload.launch_token.length >= 16 && payload.launch_token.length <= 4096 && validAttributionToken( payload.attribution_token ) && typeof payload.expires_at === 'string' && ! isNaN( Date.parse( payload.expires_at ) ) && validViewerOrigin( payload.viewer_origin ) && validEligibilityUi( payload.ui ) );
	}

	function validViewerOrigin( value ) {
		var parsed = safeHttpsUrl( value );

		return !! ( parsed && parsed.origin === value );
	}

	function validEligibilityUi( value ) {
		return !! ( value && 1 === value.placement_version && typeof value.label === 'string' && value.label.length >= 1 && value.label.length <= 120 && typeof value.style_preset === 'string' && value.style_preset.length >= 1 && value.style_preset.length <= 64 );
	}

	function validAttributionToken( token ) {
		return typeof token === 'string' && /^[A-Za-z0-9._~+/=-]{16,4096}$/.test( token );
	}

	function buildViewerUrl( viewerUrl, launchToken ) {
		var url = safeHttpsUrl( viewerUrl );

		if ( ! url || typeof launchToken !== 'string' || launchToken.length < 16 ) {
			return '';
		}

		url.search = '';
		url.hash = '';
		url.searchParams.set( 'launch_token', launchToken );

		return url.toString();
	}

	function buildProductPageHandoffUrl( windowObject, documentObject, variantId ) {
		var targetWindow = windowObject || window;
		var targetDocument = documentObject || document;
		var pageUrl = targetWindow && targetWindow.location ? safeHttpsUrl( targetWindow.location.href ) : null;
		var controls;
		var seenNames = {};
		var index;
		var control;
		var name;
		var value;
		var normalizedVariant = normalizeVariantId( variantId );

		if ( ! pageUrl ) {
			return '';
		}

		controls = targetDocument && targetDocument.querySelectorAll ? targetDocument.querySelectorAll(
			'form.variations_form [name^="attribute_"], form.cart [name^="attribute_"], [data-wp-context] [name^="attribute_"]'
		) : [];
		for ( index = 0; index < controls.length; index++ ) {
			control = controls[ index ];
			name = control && control.name ? String( control.name ) : '';
			if ( ! /^attribute_[A-Za-z0-9_-]{1,180}$/.test( name ) ) {
				continue;
			}

			if ( ! seenNames[ name ] ) {
				pageUrl.searchParams.delete( name );
				seenNames[ name ] = true;
			}

			if ( ( 'radio' === control.type || 'checkbox' === control.type ) && ! control.checked ) {
				continue;
			}

			value = typeof control.value === 'string' ? control.value.trim() : '';
			if ( value ) {
				pageUrl.searchParams.set( name, value );
			}
		}

		if ( 0 === normalizedVariant.indexOf( 'variation:' ) ) {
			pageUrl.searchParams.set( 'variation_id', normalizedVariant.slice( 'variation:'.length ) );
		}

		return pageUrl.toString();
	}

	/*
	 * This is deliberately best-effort: a viewer launch must never depend on
	 * analytics succeeding. It carries only a short-lived launch token, never an
	 * integration credential or shopper data.
	 */
	function sendClickEvent( config, launchToken, fetchImplementation ) {
		var apiBase = safeHttpsUrl( config && config.apiBase );
		var endpoint;
		var request = fetchImplementation || ( window && window.fetch );
		var result;

		if ( ! apiBase || ! request || typeof launchToken !== 'string' || launchToken.length < 16 || launchToken.length > 4096 ) {
			return false;
		}

		endpoint = new URL( CLICK_EVENT_PATH, apiBase.toString() ).toString();

		try {
			result = request( endpoint, {
				method: 'POST',
				headers: {
					'Accept': 'application/json',
					'Content-Type': 'application/json'
				},
				credentials: 'omit',
				cache: 'no-store',
				body: JSON.stringify( {
					schema_version: 1,
					launch_token: launchToken
				} )
			} );
			if ( result && result.catch ) {
				result.catch( function() {} );
			}
		} catch ( error ) {
			return false;
		}

		return true;
	}

	function findClassicAddToCartForm( documentObject ) {
		var forms = documentObject && documentObject.querySelectorAll ? documentObject.querySelectorAll( 'form.cart' ) : [];

		return forms.length ? forms[ 0 ] : null;
	}

	function removeClassicAttributionFields( documentObject ) {
		var forms = documentObject && documentObject.querySelectorAll ? documentObject.querySelectorAll( 'form.cart' ) : [];
		var formIndex;
		var fields;
		var fieldIndex;

		for ( formIndex = 0; formIndex < forms.length; formIndex++ ) {
			fields = forms[ formIndex ].querySelectorAll( 'input[name="spellexo_attribution"]' );
			for ( fieldIndex = fields.length - 1; fieldIndex >= 0; fieldIndex-- ) {
				if ( fields[ fieldIndex ].parentNode ) {
					fields[ fieldIndex ].parentNode.removeChild( fields[ fieldIndex ] );
				}
			}
		}
	}

	function updateClassicAttributionField( form, attributionToken ) {
		var fields;
		var field;
		var index;

		if ( ! form || ! form.ownerDocument || ! form.querySelectorAll || ! validAttributionToken( attributionToken ) ) {
			return false;
		}

		fields = form.querySelectorAll( 'input[name="spellexo_attribution"]' );
		field = fields.length ? fields[ 0 ] : form.ownerDocument.createElement( 'input' );

		for ( index = fields.length - 1; index > 0; index-- ) {
			if ( fields[ index ].parentNode ) {
				fields[ index ].parentNode.removeChild( fields[ index ] );
			}
		}

		field.type = 'hidden';
		field.name = 'spellexo_attribution';
		field.value = attributionToken;
		field.setAttribute( 'data-spellexo-woocommerce-attribution', '1' );
		if ( ! field.parentNode ) {
			form.appendChild( field );
		}

		return true;
	}

	/*
	 * Keeps all background body children out of the keyboard and accessibility
	 * trees while the local dialog is open. The exact pre-dialog state is
	 * retained so close restores a merchant theme without assumptions.
	 */
	function setModalBackgroundInert( documentObject, overlay ) {
		var children = documentObject && documentObject.body && documentObject.body.children ? documentObject.body.children : [];
		var state = [];
		var index;
		var element;
		var previousAriaHidden;
		var supportsInert;

		for ( index = 0; index < children.length; index++ ) {
			element = children[ index ];
			if ( ! element || element === overlay || ! element.setAttribute ) {
				continue;
			}

			previousAriaHidden = element.getAttribute ? element.getAttribute( 'aria-hidden' ) : null;
			supportsInert = 'inert' in element;
			state.push( {
				element: element,
				hadAriaHidden: null !== previousAriaHidden,
				ariaHidden: previousAriaHidden,
				supportsInert: supportsInert,
				inert: supportsInert ? element.inert : false
			} );

			if ( supportsInert ) {
				element.inert = true;
			}
			element.setAttribute( 'aria-hidden', 'true' );
		}

		return state;
	}

	function restoreModalBackground( state ) {
		var index;
		var entry;
		var element;

		for ( index = 0; index < ( state || [] ).length; index++ ) {
			entry = state[ index ];
			element = entry && entry.element;
			if ( ! element ) {
				continue;
			}

			if ( entry.supportsInert ) {
				element.inert = entry.inert;
			}
			if ( entry.hadAriaHidden ) {
				element.setAttribute( 'aria-hidden', entry.ariaHidden );
			} else if ( element.removeAttribute ) {
				element.removeAttribute( 'aria-hidden' );
			}
		}
	}

	function dialogFocusables( dialog ) {
		var selector = 'button:not([disabled]), iframe, [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
		var candidates = dialog && dialog.querySelectorAll ? dialog.querySelectorAll( selector ) : [];

		return Array.prototype.filter.call( candidates, function( element ) {
			return !! ( element && ! element.disabled && ! element.hidden && ( ! element.getAttribute || '-1' !== element.getAttribute( 'tabindex' ) ) );
		} );
	}

	/*
	 * Provides the local boundary in addition to inert background content. This
	 * makes Shift+Tab from Close and Tab from the iframe cycle within the dialog.
	 */
	function trapDialogFocus( event, dialog, documentObject ) {
		var focusables;
		var first;
		var last;
		var active;
		var containsActive;

		if ( ! event || 'Tab' !== event.key ) {
			return false;
		}

		focusables = dialogFocusables( dialog );
		if ( ! focusables.length ) {
			return false;
		}

		first = focusables[ 0 ];
		last = focusables[ focusables.length - 1 ];
		active = documentObject ? documentObject.activeElement : null;
		containsActive = !! ( dialog && dialog.contains && active && dialog.contains( active ) );

		if ( event.shiftKey ? active === first || ! containsActive : active === last || ! containsActive ) {
			if ( event.preventDefault ) {
				event.preventDefault();
			}
			if ( event.shiftKey ? last.focus : first.focus ) {
				( event.shiftKey ? last : first ).focus();
			}
			return true;
		}

		return false;
	}

	function isTrustedIframeMessage( event, viewerOrigin, iframe ) {
		return !! ( event && event.origin === viewerOrigin && iframe && event.source === iframe.contentWindow && event.data && 'object' === typeof event.data && ! Array.isArray( event.data ) );
	}

	function isTrustedViewerLoadedMessage( event, viewerOrigin, iframe, viewerContext, currentVariantId ) {
		return isTrustedIframeMessage( event, viewerOrigin, iframe ) && 1 === event.data.version && 'spellexo:viewer_loaded' === event.data.type && viewerContext && viewerContext.variantId === currentVariantId && validAttributionToken( viewerContext.attributionToken );
	}

	function notifyAttributionReady( context ) {
		if ( window && window.wp && window.wp.hooks && typeof window.wp.hooks.doAction === 'function' ) {
			window.wp.hooks.doAction( 'spellexo_woocommerce_attribution_ready', {
				attribution_token: context.attributionToken,
				product_id: String( context.productId ),
				variant_id: context.variantId
			} );
		}
	}

	/*
	 * Uses WooCommerce's documented extensionCartUpdate API. It sends a
	 * namespaced cart/extensions request to the registered PHP callback; it does
	 * not patch or observe global fetch.
	 */
	function sendStoreApiAttribution( context, windowObject ) {
		var targetWindow = windowObject || window;
		var blocksCheckout = targetWindow && targetWindow.wc && targetWindow.wc.blocksCheckout;
		var extensionCartUpdate = blocksCheckout && blocksCheckout.extensionCartUpdate;
		var result;

		if ( ! context || ! validAttributionToken( context.attributionToken ) || ! /^\d+$/.test( String( context.productId || '' ) ) || ! normalizeVariantId( context.variantId ) || typeof extensionCartUpdate !== 'function' ) {
			return false;
		}

		try {
			result = extensionCartUpdate( {
				namespace: STORE_API_NAMESPACE,
				data: {
					attribution_token: context.attributionToken,
					product_id: String( context.productId ),
					variant_id: context.variantId
				}
			} );
			if ( result && result.catch ) {
				result.catch( function() {} );
			}
		} catch ( error ) {
			return false;
		}

		return true;
	}

	function clearStoreApiAttribution( windowObject ) {
		var targetWindow = windowObject || window;
		var blocksCheckout = targetWindow && targetWindow.wc && targetWindow.wc.blocksCheckout;
		var extensionCartUpdate = blocksCheckout && blocksCheckout.extensionCartUpdate;
		var result;

		if ( typeof extensionCartUpdate !== 'function' ) {
			return false;
		}

		try {
			result = extensionCartUpdate( {
				namespace: STORE_API_NAMESPACE,
				data: { clear: true }
			} );
			if ( result && result.catch ) {
				result.catch( function() {} );
			}
		} catch ( error ) {
			return false;
		}

		return true;
	}

	function variationFromInteractivityContext( value, depth ) {
		var keys = [ 'selectedVariationId', 'selected_variation_id', 'variationId', 'variation_id' ];
		var index;
		var key;
		var nested;

		if ( ! value || depth > 5 || 'object' !== typeof value ) {
			return '';
		}

		for ( index = 0; index < keys.length; index++ ) {
			key = keys[ index ];
			if ( Object.prototype.hasOwnProperty.call( value, key ) ) {
				nested = normalizeVariantId( value[ key ] );
				if ( nested ) {
					return nested;
				}
			}
		}

		for ( key in value ) {
			if ( Object.prototype.hasOwnProperty.call( value, key ) ) {
				nested = variationFromInteractivityContext( value[ key ], depth + 1 );
				if ( nested ) {
					return nested;
				}
			}
		}

		return '';
	}

	/*
	 * The Interactivity API exposes JSON context through data-wp-context. This
	 * optional adapter complements the classic hidden variation input; the server
	 * still verifies the actual product/variation before attribution is attached.
	 */
	function findInteractivityVariantId( documentObject ) {
		var inputs = documentObject && documentObject.querySelectorAll ? documentObject.querySelectorAll( 'input[name="variation_id"]' ) : [];
		var contexts = documentObject && documentObject.querySelectorAll ? documentObject.querySelectorAll( '[data-wp-context]' ) : [];
		var index;
		var candidate;
		var rawContext;
		var parsedContext;

		for ( index = 0; index < inputs.length; index++ ) {
			candidate = normalizeVariantId( inputs[ index ].value );
			if ( candidate ) {
				return candidate;
			}
		}

		for ( index = 0; index < contexts.length; index++ ) {
			rawContext = contexts[ index ].getAttribute ? contexts[ index ].getAttribute( 'data-wp-context' ) : '';
			if ( ! rawContext ) {
				continue;
			}
			try {
				parsedContext = JSON.parse( rawContext );
			} catch ( error ) {
				continue;
			}
			candidate = variationFromInteractivityContext( parsedContext, 0 );
			if ( candidate ) {
				return candidate;
			}
		}

		return '';
	}

	function Controller( mount, config ) {
		this.mount = mount;
		this.config = config;
		this.pageViewId = createPageViewId();
		this.currentVariantId = 'simple' === config.productType ? normalizeVariantId( config.simpleVariantId ) : '';
		this.requestCounter = 0;
		this.abortController = null;
		this.eligibility = null;
		this.button = null;
		this.dialog = null;
		this.iframe = null;
		this.previousFocus = null;
		this.viewerOrigin = '';
		this.viewerContext = null;
		this.messageHandler = null;
		this.keydownHandler = null;
		this.backgroundState = [];
		this.variationTimer = null;
	}

	Controller.prototype.initialize = function() {
		if ( 'variable' === this.config.productType ) {
			this.bindVariationAdapters();
		}

		this.refreshEligibility();
	};

	Controller.prototype.bindVariationAdapters = function() {
		var self = this;
		var form = document.querySelector( 'form.variations_form' );
		var input = form ? form.querySelector( 'input[name="variation_id"]' ) : null;
		var stateRefreshQueued = false;
		var onInput = function() {
			self.setVariant( input && input.value ? input.value : findInteractivityVariantId( document ) );
		};
		var queueStateRefresh = function() {
			if ( stateRefreshQueued ) {
				return;
			}
			stateRefreshQueued = true;
			window.setTimeout( function() {
				stateRefreshQueued = false;
				onInput();
			}, 0 );
		};
		var onBlockVariation = function( event ) {
			var detail = event && event.detail ? event.detail : {};
			self.setVariant( detail.variationId || detail.variation_id || detail.id || '' );
		};

		if ( form && window.jQuery ) {
			window.jQuery( form ).on( 'found_variation.spellexoWooCommerce', function( event, variation ) {
				self.setVariant( variation && variation.variation_id ? variation.variation_id : ( input ? input.value : '' ) );
			} );
			window.jQuery( form ).on( 'reset_data.spellexoWooCommerce hide_variation.spellexoWooCommerce', function() {
				self.setVariant( '' );
			} );
		}

		if ( input ) {
			input.addEventListener( 'change', onInput );
			input.addEventListener( 'input', onInput );
		}

		/* Official Interactivity context plus standard control state is primary. */
		document.addEventListener( 'change', queueStateRefresh, true );
		document.addEventListener( 'input', queueStateRefresh, true );
		if ( window.MutationObserver && document.body ) {
			new window.MutationObserver( queueStateRefresh ).observe( document.body, {
				subtree: true,
				childList: true,
				attributes: true,
				attributeFilter: [ 'data-wp-context', 'value', 'checked', 'aria-checked', 'selected' ]
			} );
		}

		/* Compatibility-only fallback for older third-party Woo block scripts. */
		document.addEventListener( 'wc-blocks_product_variation_changed', onBlockVariation );
		document.addEventListener( 'wc-blocks_product_variation_reset', function() {
			self.setVariant( '' );
		} );

		/* Narrow classic fallback for builders that update the hidden input only. */
		if ( input && window.MutationObserver ) {
			new window.MutationObserver( onInput ).observe( input, { attributes: true, attributeFilter: [ 'value' ] } );
			this.variationTimer = window.setInterval( onInput, 500 );
		}

		onInput();
	};

	Controller.prototype.setVariant = function( value ) {
		var nextVariant = normalizeVariantId( value );

		if ( nextVariant === this.currentVariantId ) {
			return;
		}

		this.currentVariantId = nextVariant;
		this.eligibility = null;
		this.invalidateViewerContext();
		this.refreshEligibility();
	};

	Controller.prototype.refreshEligibility = function() {
		var self = this;
		var requestId = ++this.requestCounter;
		var apiBase = safeHttpsUrl( this.config.apiBase );
		var endpoint;
		var timeout;
		var requestOptions;

		this.hideButton();

		if ( ! this.currentVariantId || ! apiBase || ! window.fetch ) {
			return Promise.resolve( null );
		}

		endpoint = new URL( ELIGIBILITY_PATH, apiBase.toString() ).toString();
		if ( this.abortController && this.abortController.abort ) {
			this.abortController.abort();
		}

		this.abortController = window.AbortController ? new window.AbortController() : null;
		requestOptions = {
			method: 'POST',
			headers: {
				'Accept': 'application/json',
				'Content-Type': 'application/json'
			},
			credentials: 'omit',
			cache: 'no-store',
			body: JSON.stringify( buildEligibilityPayload( this.config, this.currentVariantId, this.pageViewId ) )
		};

		if ( this.abortController ) {
			requestOptions.signal = this.abortController.signal;
		}

		timeout = window.setTimeout( function() {
			if ( self.abortController && self.abortController.abort ) {
				self.abortController.abort();
			}
		}, REQUEST_TIMEOUT_MS );

		this.mount.setAttribute( 'aria-busy', 'true' );

		return window.fetch( endpoint, requestOptions )
			.then( function( response ) {
				if ( ! response.ok ) {
					throw new Error( 'eligibility_failed' );
				}
				return response.json();
			} )
			.then( function( payload ) {
				if ( requestId !== self.requestCounter || ! validEligibility( payload ) ) {
					return null;
				}

				self.eligibility = {
					launchToken: payload.launch_token,
					attributionToken: payload.attribution_token,
					expiresAt: Date.parse( payload.expires_at || '' ) || 0,
					variantId: self.currentVariantId,
					viewerOrigin: payload.viewer_origin
				};
				self.showButton();
				return self.eligibility;
			} )
			.catch( function() {
				return null;
			} )
			.then( function( result ) {
				window.clearTimeout( timeout );
				if ( requestId === self.requestCounter ) {
					self.mount.removeAttribute( 'aria-busy' );
				}
				return result;
			} );
	};

	Controller.prototype.showButton = function() {
		var self = this;

		if ( ! this.button ) {
			this.button = document.createElement( 'button' );
			this.button.type = 'button';
			this.button.className = 'spellexo-woocommerce-button';
			this.button.addEventListener( 'click', function() {
				self.openForCurrentSelection();
			} );
			this.mount.appendChild( this.button );
		}

		this.button.textContent = ( this.config.i18n && this.config.i18n.label ) || 'View In Your Room';
		this.button.hidden = false;
	};

	Controller.prototype.hideButton = function() {
		if ( this.button ) {
			this.button.hidden = true;
			this.button.disabled = false;
		}
	};

	Controller.prototype.openForCurrentSelection = function() {
		var self = this;

		if ( ! this.currentVariantId ) {
			return;
		}

		if ( this.button ) {
			this.button.disabled = true;
		}
		this.invalidateViewerContext();

		/* Always request a short-lived token immediately before creating an iframe. */
		this.refreshEligibility().then( function( eligibility ) {
			if ( self.button ) {
				self.button.disabled = false;
			}

			if ( ! eligibility || eligibility.variantId !== self.currentVariantId || ( eligibility.expiresAt && eligibility.expiresAt - Date.now() < TOKEN_REFRESH_WINDOW_MS ) ) {
				return;
			}

			self.openDialog( eligibility.launchToken, eligibility.attributionToken, eligibility.variantId, eligibility.viewerOrigin );
		} );
	};

	Controller.prototype.openDialog = function( launchToken, attributionToken, variantId, viewerOrigin ) {
		var iframeUrl = buildViewerUrl( this.config.viewerUrl, launchToken );
		var viewerUrl = safeHttpsUrl( this.config.viewerUrl );
		var overlay;
		var dialog;
		var closeButton;
		var status;
		var iframe;
		var self = this;

		if ( ! iframeUrl || ! viewerUrl || ! validViewerOrigin( viewerOrigin ) || viewerUrl.origin !== viewerOrigin || ! validAttributionToken( attributionToken ) || variantId !== this.currentVariantId ) {
			return;
		}

		this.invalidateViewerContext();
		this.viewerContext = {
			attributionToken: attributionToken,
			productId: this.config.productId,
			variantId: variantId,
			viewerLoaded: false
		};
		sendClickEvent( this.config, launchToken );
		/* Eligibility refresh hides the trigger and clears its browser focus. */
		this.previousFocus = this.button || document.activeElement;
		this.viewerOrigin = viewerOrigin;
		overlay = document.createElement( 'div' );
		overlay.className = 'spellexo-woocommerce-dialog-overlay';

		dialog = document.createElement( 'section' );
		dialog.className = 'spellexo-woocommerce-dialog';
		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.setAttribute( 'aria-label', ( this.config.i18n && this.config.i18n.label ) || 'View In Your Room' );

		closeButton = document.createElement( 'button' );
		closeButton.type = 'button';
		closeButton.className = 'spellexo-woocommerce-dialog-close';
		closeButton.setAttribute( 'aria-label', ( this.config.i18n && this.config.i18n.close ) || 'Close viewer' );
		closeButton.textContent = '×';
		closeButton.addEventListener( 'click', function() {
			self.closeDialog();
		} );

		status = document.createElement( 'p' );
		status.className = 'spellexo-woocommerce-dialog-status';
		status.setAttribute( 'role', 'status' );
		status.textContent = ( this.config.i18n && this.config.i18n.loading ) || 'Loading viewer…';

		iframe = document.createElement( 'iframe' );
		iframe.className = 'spellexo-woocommerce-viewer-frame';
		iframe.title = ( this.config.i18n && this.config.i18n.label ) || 'View In Your Room';
		iframe.referrerPolicy = 'no-referrer';
		iframe.setAttribute( 'allow', 'camera; xr-spatial-tracking; gyroscope; accelerometer; magnetometer; fullscreen' );
		iframe.setAttribute( 'allowfullscreen', '' );
		iframe.src = iframeUrl;
		iframe.addEventListener( 'load', function() {
			status.hidden = true;
		} );

		dialog.appendChild( closeButton );
		dialog.appendChild( status );
		dialog.appendChild( iframe );
		overlay.appendChild( dialog );
		document.body.appendChild( overlay );
		this.backgroundState = setModalBackgroundInert( document, overlay );
		document.body.classList.add( 'spellexo-woocommerce-dialog-open' );

		this.dialog = overlay;
		this.iframe = iframe;
		this.keydownHandler = function( event ) {
			if ( trapDialogFocus( event, dialog, document ) ) {
				return;
			}
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				self.closeDialog();
			}
		};
		this.messageHandler = function( event ) {
			if ( ! isTrustedIframeMessage( event, self.viewerOrigin, self.iframe ) ) {
				return;
			}

			if ( 1 === event.data.version && 'spellexo:request_product_page' === event.data.type ) {
				var productPageUrl = buildProductPageHandoffUrl( window, document, self.currentVariantId );
				if ( productPageUrl && event.source && event.source.postMessage ) {
					event.source.postMessage( {
						version: 1,
						type: 'spellexo:product_page_context',
						url: productPageUrl,
						platform: 'wordpress'
					}, self.viewerOrigin );
				}
				return;
			}

			if ( isTrustedViewerLoadedMessage( event, self.viewerOrigin, self.iframe, self.viewerContext, self.currentVariantId ) ) {
				if ( ! self.viewerContext.viewerLoaded ) {
					self.viewerContext.viewerLoaded = true;
					self.applyAttribution();
				}
				return;
			}

			if ( 1 === event.data.version && 'spellexo:close' === event.data.type ) {
				self.closeDialog();
			}
		};

		document.addEventListener( 'keydown', this.keydownHandler );
		window.addEventListener( 'message', this.messageHandler );
		window.setTimeout( function() {
			closeButton.focus();
		}, 0 );
	};

	Controller.prototype.closeDialog = function() {
		if ( ! this.dialog ) {
			return;
		}

		if ( this.keydownHandler ) {
			document.removeEventListener( 'keydown', this.keydownHandler );
		}
		if ( this.messageHandler ) {
			window.removeEventListener( 'message', this.messageHandler );
		}

		restoreModalBackground( this.backgroundState );
		this.backgroundState = [];
		this.dialog.remove();
		this.dialog = null;
		this.iframe = null;
		this.keydownHandler = null;
		this.messageHandler = null;
		document.body.classList.remove( 'spellexo-woocommerce-dialog-open' );

		if ( this.previousFocus && this.previousFocus.focus ) {
			this.previousFocus.focus();
		}
		this.previousFocus = null;
	};

	Controller.prototype.applyAttribution = function() {
		var form;

		if ( ! this.viewerContext || this.viewerContext.variantId !== this.currentVariantId || ! validAttributionToken( this.viewerContext.attributionToken ) ) {
			return;
		}

		removeClassicAttributionFields( document );
		form = findClassicAddToCartForm( document );
		if ( form ) {
			updateClassicAttributionField( form, this.viewerContext.attributionToken );
		}

		sendStoreApiAttribution( this.viewerContext );
		/* Extension authors may additionally consume this local event. */
		notifyAttributionReady( this.viewerContext );
	};

	Controller.prototype.invalidateViewerContext = function() {
		removeClassicAttributionFields( document );
		clearStoreApiAttribution();
		this.viewerContext = null;
		this.closeDialog();
	};

	function selectPrimaryMount( mounts ) {
		var index;

		for ( index = 0; index < mounts.length; index++ ) {
			if ( ! mounts[ index ].hidden && mounts[ index ].getClientRects && mounts[ index ].getClientRects().length ) {
				return mounts[ index ];
			}
		}

		return mounts.length ? mounts[ 0 ] : null;
	}

	function initializeMounts() {
		var mounts = document.querySelectorAll( '[data-spellexo-woocommerce-mount="1"]' );
		var primaryMount = selectPrimaryMount( mounts );
		var index;
		var config;

		for ( index = 0; index < mounts.length; index++ ) {
			if ( mounts[ index ] !== primaryMount ) {
				mounts[ index ].hidden = true;
				continue;
			}

			config = parseConfig( primaryMount );
			if ( config ) {
				activeController = new Controller( primaryMount, config );
				activeController.initialize();
			}
		}
	}

	var publicApi = {
		ATTRIBUTION_READY_ACTION: 'spellexo_woocommerce_attribution_ready',
		STORE_API_NAMESPACE: STORE_API_NAMESPACE,
		buildEligibilityPayload: buildEligibilityPayload,
		buildProductPageHandoffUrl: buildProductPageHandoffUrl,
		buildViewerUrl: buildViewerUrl,
		clearStoreApiAttribution: clearStoreApiAttribution,
		findClassicAddToCartForm: findClassicAddToCartForm,
		findInteractivityVariantId: findInteractivityVariantId,
		isTrustedViewerLoadedMessage: isTrustedViewerLoadedMessage,
		normalizeVariantId: normalizeVariantId,
		removeClassicAttributionFields: removeClassicAttributionFields,
		restoreModalBackground: restoreModalBackground,
		selectPrimaryMount: selectPrimaryMount,
		sendStoreApiAttribution: sendStoreApiAttribution,
		sendClickEvent: sendClickEvent,
		setModalBackgroundInert: setModalBackgroundInert,
		trapDialogFocus: trapDialogFocus,
		updateClassicAttributionField: updateClassicAttributionField,
		validAttributionToken: validAttributionToken,
		validEligibility: validEligibility,
		validEligibilityUi: validEligibilityUi,
		validViewerOrigin: validViewerOrigin
	};

	if ( 'undefined' !== typeof module && module.exports ) {
		module.exports = publicApi;
	}

	if ( ! window || ! document ) {
		return;
	}

	window.SpellexoWooCommerceStorefront = publicApi;

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initializeMounts );
	} else {
		initializeMounts();
	}
} )( 'undefined' !== typeof window ? window : null, 'undefined' !== typeof document ? document : null );
