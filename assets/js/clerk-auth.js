/**
 * Clerk WP Sync — front-end session bridge.
 *
 * Loads Clerk, mounts the embedded sign-in widget on the portal, and exchanges
 * the Clerk session token for a WordPress login session via the REST bridge.
 */
( function () {
	'use strict';

	var cfg = window.ClerkWPAuth || {};
	var bridgeInFlight = false;
	var listenerAttached = false;

	function showBridgeError( message ) {
		var el = document.getElementById( 'clerk-signin-error' );

		if ( ! el ) {
			return;
		}

		el.textContent = message || cfg.genericError || 'Sign-in could not be completed.';
		el.hidden = false;
	}

	/**
	 * Wait for Clerk's async browser SDK to become available.
	 *
	 * @return {Promise<boolean>} Whether Clerk loaded before the timeout.
	 */
	function waitForClerk() {
		return new Promise( function ( resolve ) {
			var attempts = 0;

			function check() {
				if ( window.Clerk ) {
					resolve( true );
					return;
				}

				attempts += 1;
				if ( attempts >= 100 ) {
					resolve( false );
					return;
				}

				window.setTimeout( check, 100 );
			}

			check();
		} );
	}

	/**
	 * Send the current Clerk session token to WordPress to establish a WP login.
	 *
	 * @return {Promise<boolean>} Whether the bridge succeeded.
	 */
	async function bridgeSession() {
		if ( bridgeInFlight || ! window.Clerk.session ) {
			return false;
		}
		bridgeInFlight = true;
		try {
			var token = await window.Clerk.session.getToken();
			if ( ! token ) {
				return false;
			}

			var res = await fetch( cfg.restUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce
				},
				credentials: 'same-origin',
				body: JSON.stringify( { token: token } )
			} );

			var data = await res.json().catch( function () {
				return null;
			} );

			if ( res.ok && data && data.ok ) {
				window.location.assign( data.redirect || cfg.portalUrl );
				return true;
			}

			showBridgeError( data && data.message ? data.message : cfg.genericError );

			if ( data && 'registration_email_denied' === data.code && window.Clerk.user ) {
				await window.Clerk.signOut();
				mountSignIn();
			}

			window.console && console.error( 'Clerk sign-in could not be completed.', data );
		} catch ( err ) {
			showBridgeError( cfg.genericError );
			window.console && console.error( 'Clerk session bridge error', err );
		} finally {
			bridgeInFlight = false;
		}

		return false;
	}

	/**
	 * Mount the embedded Clerk sign-in widget into the portal panel.
	 */
	function mountSignIn() {
		var el = document.getElementById( 'clerk-signin' );
		if ( ! el ) {
			return;
		}

		window.Clerk.mountSignIn( el, {
			withSignUp: true,
			routing: 'hash',
			forceRedirectUrl: cfg.portalUrl,
			fallbackRedirectUrl: cfg.portalUrl,
			signInForceRedirectUrl: cfg.portalUrl,
			signInFallbackRedirectUrl: cfg.portalUrl,
			signUpForceRedirectUrl: cfg.portalUrl,
			signUpFallbackRedirectUrl: cfg.portalUrl,
			appearance: {
				variables: {
					borderRadius: '2px',
					colorBackground: '#f1e9c8',
					colorDanger: '#8a1f1f',
					colorInputBackground: '#f8f3df',
					colorInputText: '#0f3a2d',
					colorPrimary: '#c5a04a',
					colorSuccess: '#1f5f3f',
					colorText: '#0f3a2d',
					colorTextSecondary: 'rgba(20, 36, 28, 0.72)',
					fontFamily: '"Inter Tight", "Helvetica Neue", Arial, sans-serif'
				},
				elements: {
					rootBox: {
						width: '100%'
					},
					cardBox: {
						backgroundColor: '#f1e9c8',
						border: '2px solid rgba(15, 58, 45, 0.22)',
						borderRadius: '2px',
						boxShadow: 'none',
						width: '100%'
					},
					card: {
						backgroundColor: '#f1e9c8',
						borderRadius: '2px',
						boxShadow: 'none'
					},
					headerTitle: {
						color: '#0f3a2d',
						fontFamily: '"Oswald", Impact, sans-serif',
						fontSize: '28px',
						fontWeight: '700',
						letterSpacing: '0.02em',
						lineHeight: '0.98',
						textTransform: 'uppercase'
					},
					headerSubtitle: {
						color: 'rgba(20, 36, 28, 0.72)'
					},
					formFieldLabel: {
						color: '#0f3a2d',
						fontFamily: '"Barlow Condensed", "Oswald", sans-serif',
						fontSize: '13px',
						fontWeight: '700',
						letterSpacing: '0.12em',
						textTransform: 'uppercase'
					},
					formFieldInput: {
						backgroundColor: '#f8f3df',
						border: '2px solid rgba(15, 58, 45, 0.2)',
						color: '#0f3a2d'
					},
					formButtonPrimary: {
						backgroundColor: '#c5a04a',
						border: '2px solid #c5a04a',
						color: '#0f3a2d',
						fontFamily: '"Barlow Condensed", "Oswald", sans-serif',
						fontSize: '13px',
						fontWeight: '700',
						letterSpacing: '0.14em',
						textTransform: 'uppercase'
					},
					socialButtonsBlockButton: {
						backgroundColor: '#f8f3df',
						border: '2px solid rgba(15, 58, 45, 0.16)',
						color: '#0f3a2d'
					},
					dividerText: {
						color: 'rgba(20, 36, 28, 0.72)'
					},
					footer: {
						backgroundColor: '#f8f3df',
						borderTop: '1px solid rgba(15, 58, 45, 0.14)'
					},
					footerActionLink: {
						color: '#0f3a2d',
						fontWeight: '700'
					}
				}
			}
		} );

		// When sign-in/up completes in place, establish the WP session.
		if ( ! listenerAttached ) {
			listenerAttached = true;
			window.Clerk.addListener( function ( payload ) {
				if ( payload && payload.user && payload.session ) {
					bridgeSession();
				}
			} );
		}
	}

	window.addEventListener( 'load', async function () {
		if ( ! await waitForClerk() ) {
			showBridgeError( 'The sign-in service could not load. Please refresh and try again.' );
			return;
		}

		try {
			var loadOptions = {
				signInUrl: cfg.portalUrl,
				signUpUrl: cfg.portalUrl,
				afterSignOutUrl: cfg.portalUrl
			};
			if ( cfg.proxyUrl ) {
				loadOptions.proxyUrl = cfg.proxyUrl;
			}
			await window.Clerk.load( loadOptions );
		} catch ( err ) {
			showBridgeError( 'The sign-in service could not load. Please refresh and try again.' );
			window.console && console.error( 'Clerk failed to load', err );
			return;
		}

		// If we just logged out of WordPress, also clear the Clerk session so the
		// user isn't silently signed back in by the bridge below.
		var params = new URLSearchParams( window.location.search );
		if ( '1' === params.get( 'clerk_signout' ) ) {
			if ( window.Clerk.user || window.Clerk.session ) {
				try {
					await window.Clerk.signOut();
				} catch ( err ) {
					window.console && console.error( 'Clerk sign-out failed', err );
				}
			}
			params.delete( 'clerk_signout' );
			var clean = window.location.pathname + ( params.toString() ? '?' + params.toString() : '' );
			window.history.replaceState( {}, '', clean );
		}

		// Already authenticated in WordPress — nothing to do.
		if ( cfg.isLoggedIn ) {
			if ( cfg.isAuthCallback && ! cfg.isPortalPage ) {
				window.location.assign( cfg.portalUrl );
			}

			return;
		}

		if ( window.Clerk.user ) {
			// Signed into Clerk but not WordPress — establish the WP session.
			await bridgeSession();
		} else {
			mountSignIn();
		}
	} );
} )();
