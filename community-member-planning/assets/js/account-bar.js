/* [cmp_account_bar]: close the open account menu with Escape (returning
   focus to the button) or a click anywhere else. It opens and works
   without this script. */
( function () {
	'use strict';
	var menus = document.querySelectorAll( '.cmp-account-bar details' );
	if ( ! menus.length ) {
		return;
	}
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' !== e.key ) {
			return;
		}
		Array.prototype.forEach.call( menus, function ( d ) {
			if ( d.open ) {
				d.open = false;
				d.querySelector( 'summary' ).focus();
			}
		} );
	} );
	document.addEventListener( 'click', function ( e ) {
		Array.prototype.forEach.call( menus, function ( d ) {
			if ( d.open && ! d.contains( e.target ) ) {
				d.open = false;
			}
		} );
	} );
}() );
