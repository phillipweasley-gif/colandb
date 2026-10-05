/* Installable app (Community Member Planning 0.19.0): registers the service
   worker and drives the install help (CMP_App::install_html). */
( function () {
	'use strict';
	var cfg = window.CMP_APP || {};
	if ( 'serviceWorker' in navigator && window.isSecureContext && cfg.sw ) {
		window.addEventListener( 'load', function () {
			navigator.serviceWorker.register( cfg.sw, { scope: cfg.scope || '/' } ).catch( function () {} );
		} );
	}

	var DISMISS = 'cmp_app_install_later';
	var deferred = null;
	var standalone = ( window.matchMedia && window.matchMedia( '(display-mode: standalone)' ).matches ) || true === window.navigator.standalone;
	var ua = navigator.userAgent || '';
	var ios = /iphone|ipad|ipod/i.test( ua ) || ( /macintosh/i.test( ua ) && navigator.maxTouchPoints > 1 );

	var later = function () {
		try {
			return parseInt( window.localStorage.getItem( DISMISS ) || '0', 10 ) > Date.now();
		} catch ( e ) {
			return false;
		}
	};

	var each = function ( sel, fn ) {
		Array.prototype.forEach.call( document.querySelectorAll( sel ), fn );
	};

	var render = function () {
		each( '[data-cmp-install]', function ( box ) {
			var banner = box.hasAttribute( 'data-cmp-install-banner' );
			var ready = box.querySelector( '[data-cmp-install-ready]' );
			var iosBox = box.querySelector( '[data-cmp-install-ios]' );
			var done = box.querySelector( '[data-cmp-install-done]' );
			var other = box.querySelector( '[data-cmp-install-other]' );
			var canPrompt = !! deferred && ! standalone;
			var showIos = ios && ! standalone && ! canPrompt;
			if ( ready ) {
				ready.hidden = ! canPrompt;
			}
			if ( iosBox ) {
				iosBox.hidden = ! showIos;
			}
			if ( done ) {
				done.hidden = ! standalone;
			}
			if ( other ) {
				other.hidden = standalone || canPrompt || showIos;
			}
			if ( banner ) {
				box.hidden = standalone || later() || ! ( canPrompt || showIos );
			}
		} );
	};

	window.addEventListener( 'beforeinstallprompt', function ( e ) {
		e.preventDefault();
		deferred = e;
		render();
	} );
	window.addEventListener( 'appinstalled', function () {
		deferred = null;
		standalone = true;
		render();
	} );

	document.addEventListener( 'click', function ( e ) {
		var t = e.target;
		if ( ! t || ! t.closest ) {
			return;
		}
		if ( t.closest( '[data-cmp-install-btn]' ) && deferred ) {
			var p = deferred;
			deferred = null;
			p.prompt();
			( p.userChoice || Promise.resolve() ).then( render, render );
			render();
		}
		if ( t.closest( '[data-cmp-install-later]' ) ) {
			try {
				window.localStorage.setItem( DISMISS, String( Date.now() + 30 * 24 * 3600 * 1000 ) );
			} catch ( err ) {}
			render();
		}
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', render );
	} else {
		render();
	}
}() );
