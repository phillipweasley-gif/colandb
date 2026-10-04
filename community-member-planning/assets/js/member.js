/* Community Member Planning — member area.
   Notification inbox: mark one / all as read through the cmp/v1 REST
   routes. Every failure path ends in a visible, announced state
   (offline, permission denied, server error with retry) per the brief. */
/* Account tab: show/hide passwords, confirm before risky requests, and
   no double submits. Forms work without JavaScript too. */
( function () {
	'use strict';

	var toggle = document.querySelector( '[data-cmp-show-passwords]' );
	if ( toggle ) {
		toggle.addEventListener( 'change', function () {
			var fields = toggle.form.querySelectorAll( 'input[type="password"], input[data-cmp-was-password]' );
			Array.prototype.forEach.call( fields, function ( input ) {
				input.setAttribute( 'data-cmp-was-password', '1' );
				input.type = toggle.checked ? 'text' : 'password';
			} );
		} );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.cmp-member-area form.cmp-form' ), function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var button = event.submitter || form.querySelector( 'button[type="submit"]' );
			var question = button && button.getAttribute( 'data-cmp-confirm' );
			if ( question && ! window.confirm( question ) ) {
				event.preventDefault();
				return;
			}
			if ( form.getAttribute( 'data-cmp-sent' ) ) {
				event.preventDefault();
				return;
			}
			form.setAttribute( 'data-cmp-sent', '1' );
			if ( button ) {
				button.setAttribute( 'aria-disabled', 'true' );
				button.classList.add( 'is-busy' );
			}
		} );
	} );
}() );

( function () {
	'use strict';

	var inbox = document.querySelector( '[data-cmp-inbox]' );
	if ( ! inbox || ! window.CMP ) {
		return;
	}

	var status = inbox.querySelector( '[data-cmp-status]' );
	var unreadBadge = inbox.querySelector( '[data-cmp-unread]' );
	var readAllBtn = inbox.querySelector( '[data-cmp-read-all]' );

	function say( message, retry ) {
		status.textContent = '';
		if ( ! message ) {
			return;
		}
		var text = document.createElement( 'span' );
		text.textContent = message;
		status.appendChild( text );
		if ( retry ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'cmp-btn cmp-btn-small cmp-btn-outline';
			btn.textContent = CMP.i18n.retry;
			btn.addEventListener( 'click', retry );
			status.appendChild( document.createTextNode( ' ' ) );
			status.appendChild( btn );
		}
	}

	function setUnread( count ) {
		unreadBadge.textContent = String( count );
		unreadBadge.hidden = count === 0;
		if ( readAllBtn ) {
			readAllBtn.hidden = count === 0;
		}
	}

	// onSettled always runs once the request is over, success or not.
	function post( path, onDone, retry, onSettled ) {
		onSettled = onSettled || function () {};
		if ( ! navigator.onLine ) {
			say( CMP.i18n.offline, retry );
			onSettled();
			return;
		}
		fetch( CMP.root + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': CMP.nonce }
		} )
			.then( function ( res ) {
				if ( res.status === 401 || res.status === 403 ) {
					say( CMP.i18n.denied );
					return null;
				}
				if ( ! res.ok ) {
					throw new Error( res.status );
				}
				return res.json();
			} )
			.then( function ( data ) {
				if ( data ) {
					say( '' );
					onDone( data );
				}
			} )
			.catch( function () {
				say( navigator.onLine ? CMP.i18n.error : CMP.i18n.offline, retry );
			} )
			.then( onSettled );
	}

	function markItemRead( li ) {
		li.classList.add( 'is-read' );
		var dot = li.querySelector( '.cmp-notification-state' );
		if ( dot ) {
			dot.innerHTML = '&#9675;';
		}
		var sr = li.querySelector( '.cmp-notification-body .screen-reader-text' );
		if ( sr ) {
			sr.textContent = CMP.i18n.read;
		}
		var btn = li.querySelector( '[data-cmp-read]' );
		if ( btn ) {
			btn.remove();
		}
	}

	inbox.addEventListener( 'click', function ( e ) {
		var one = e.target.closest( '[data-cmp-read]' );
		if ( one ) {
			var li = one.closest( '.cmp-notification' );
			var go = function () {
				one.disabled = true;
				post( 'notifications/' + li.getAttribute( 'data-id' ) + '/read', function ( data ) {
					markItemRead( li );
					setUnread( data.unread );
				}, go, function () {
					one.disabled = false;
				} );
			};
			go();
			return;
		}
		if ( e.target.closest( '[data-cmp-read-all]' ) ) {
			var all = function () {
				post( 'notifications/read-all', function () {
					inbox.querySelectorAll( '.cmp-notification' ).forEach( markItemRead );
					setUnread( 0 );
				}, all );
			};
			all();
		}
	} );
}() );
