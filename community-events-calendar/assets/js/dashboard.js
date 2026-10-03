( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.cec-dash-action', function () {
		var $btn   = $( this );
		var action = $btn.data( 'action' );
		var id     = $btn.data( 'id' );

		if ( 'delete' === action && ! window.confirm( 'Delete this event? This cannot be undone from here.' ) ) {
			return;
		}

		$btn.prop( 'disabled', true );

		$.post( CEC.ajax_url, {
			action: 'cec_dashboard_action',
			nonce: CEC.nonce,
			dash_action: action,
			event_id: id
		} ).done( function ( res ) {
			if ( res.success ) {
				window.location.reload();
			} else {
				alert( res.data && res.data.message ? res.data.message : 'Something went wrong.' );
				$btn.prop( 'disabled', false );
			}
		} ).fail( function () {
			alert( 'Something went wrong.' );
			$btn.prop( 'disabled', false );
		} );
	} );

} )( jQuery );
