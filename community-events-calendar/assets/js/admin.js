( function ( $ ) {
	'use strict';

	$( function () {
		$( '.cec-color-field' ).wpColorPicker();

		$( document ).on( 'click', '.cec-media-button', function ( e ) {
			e.preventDefault();
			var $button = $( this );
			var $input  = $button.siblings( '.cec-media-field' );

			var frame = wp.media( {
				title: 'Select Image',
				multiple: false
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				$input.val( attachment.url );
			} );

			frame.open();
		} );

		// Autofills Code of Conduct / RSVP fields from a checked Partner
		// Organization's saved defaults (CEC_ADMIN.orgDefaults, localized
		// from term meta), but only into fields still empty — never
		// overwrites something already typed. Targets WP's own native
		// Partner Organizations checklist checkbox in the sidebar, not a
		// custom-rendered one.
		$( document ).on( 'change', 'input[name="tax_input[cec_partner_org][]"]', function () {
			if ( ! this.checked || typeof CEC_ADMIN === 'undefined' ) {
				return;
			}
			var defaults = CEC_ADMIN.orgDefaults[ $( this ).val() ];
			if ( ! defaults ) {
				return;
			}
			var $coc  = $( '#cec_code_of_conduct' );
			var $mode = $( '#cec_rsvp_mode' );
			var $url  = $( '#cec_rsvp_url' );

			if ( defaults.coc && $coc.length && ! $coc.val() ) {
				$coc.val( defaults.coc );
			}
			if ( defaults.rsvpUrl && $url.length && ! $url.val() ) {
				$url.val( defaults.rsvpUrl );
				if ( defaults.rsvpMode && $mode.length ) {
					$mode.val( defaults.rsvpMode );
				}
			}
		} );

		// Same admission/location field-toggle convention as the front-end
		// forms (assets/js/frontend.js) — kept here too since the wp-admin
		// meta box is a separate page/script context, not a shared enqueue.
		function toggleAdmissionFields( $select ) {
			$( '.cec-admission-paid-fields' ).prop( 'hidden', 'paid' !== $select.val() );
			$( '.cec-admission-varies-fields' ).prop( 'hidden', 'price_varies' !== $select.val() );
		}
		$( document ).on( 'change', '.cec-admission-status-select', function () {
			toggleAdmissionFields( $( this ) );
		} );
		$( '.cec-admission-status-select' ).each( function () {
			toggleAdmissionFields( $( this ) );
		} );

		function toggleLocationFields( $select ) {
			var mode = $select.val();
			$( '.cec-location-in-person-fields' ).prop( 'hidden', 'online' === mode || 'not_posted' === mode );
			$( '.cec-location-online-fields' ).prop( 'hidden', 'online' !== mode && 'hybrid' !== mode );
		}
		$( document ).on( 'change', '.cec-location-mode-select', function () {
			toggleLocationFields( $( this ) );
		} );
		$( '.cec-location-mode-select' ).each( function () {
			toggleLocationFields( $( this ) );
		} );
	} );

} )( jQuery );
