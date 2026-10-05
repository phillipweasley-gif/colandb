( function ( $ ) {
	'use strict';

	// Works for both a plain text button (cards) and an icon+label item
	// (the share menu) — swaps whichever holds the visible text.
	function copyLink( url, $target ) {
		var $label   = $target.find( '.cec-share-label' );
		var $textEl  = $label.length ? $label : $target;
		var original = $textEl.text();
		var done = function () {
			$target.addClass( 'cec-share-copied' );
			$textEl.text( 'Copied!' );
			setTimeout( function () {
				$target.removeClass( 'cec-share-copied' );
				$textEl.text( original );
			}, 2000 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( url ).then( done );
			return;
		}
		var $temp = $( '<textarea readonly></textarea>' ).val( url ).css( { position: 'fixed', top: '-1000px' } ).appendTo( 'body' );
		$temp[ 0 ].select();
		try {
			document.execCommand( 'copy' );
			done();
		} catch ( e ) {
			window.prompt( 'Copy this link:', url ); // eslint-disable-line no-alert
		}
		$temp.remove();
	}

	// Native share is only used on touch devices (phones/tablets), where
	// it's mature and reliable. On desktop, Windows/macOS share-broker
	// integrations have repeatedly opened the target app (Telegram, Messages)
	// with the right link showing and then silently failed to actually
	// deliver it — a failure this site can't detect or prevent, since the
	// Web Share API hands off completely and gives no delivery confirmation.
	// Desktop always gets the icon menu instead, which needs no OS handoff.
	var isTouchDevice = ( 'ontouchstart' in window ) || ( navigator.maxTouchPoints > 0 );

	$( document ).on( 'click', '.cec-share-toggle', function ( e ) {
		var $share = $( this ).closest( '.cec-share' );
		if ( navigator.share && isTouchDevice ) {
			navigator.share( {
				title: $share.data( 'share-title' ),
				text: $share.data( 'share-text' ),
				url: $share.data( 'share-url' )
			} ).catch( function () {} ); // user cancelled — nothing to do
			return;
		}
		e.stopPropagation();
		var $menu    = $share.find( '.cec-share-menu' );
		var willOpen = $menu.prop( 'hidden' );
		$( '.cec-share-menu' ).prop( 'hidden', true );
		$menu.prop( 'hidden', ! willOpen );
	} );

	// Closes the menu on any click outside it; clicks inside (including
	// Copy) don't bubble up to trigger that same close.
	$( document ).on( 'click', function () {
		$( '.cec-share-menu' ).prop( 'hidden', true );
	} );
	$( document ).on( 'click', '.cec-share-menu', function ( e ) {
		e.stopPropagation();
	} );

	$( document ).on( 'click', '.cec-share-compact', function () {
		var $btn = $( this );
		if ( navigator.share && isTouchDevice ) {
			navigator.share( {
				title: $btn.data( 'share-title' ),
				text: $btn.data( 'share-text' ),
				url: $btn.data( 'share-url' )
			} ).catch( function () {} );
		} else {
			copyLink( $btn.data( 'share-url' ), $btn );
		}
	} );

	$( document ).on( 'click', '.cec-share-copy', function ( e ) {
		e.preventDefault();
		copyLink( $( this ).data( 'url' ), $( this ) );
	} );

	function loadMonth( $wrap, month, year ) {
		$.post( CEC.ajax_url, {
			action: 'cec_calendar_month',
			nonce: CEC.nonce,
			month: month,
			year: year
		} ).done( function ( res ) {
			if ( res.success ) {
				$wrap.html( res.data.html ).attr( 'data-month', month ).attr( 'data-year', year );
			}
		} );
	}

	$( document ).on( 'click', '.cec-cal-nav', function () {
		var $wrap = $( this ).closest( '.cec-calendar-wrap' );
		loadMonth( $wrap, $( this ).data( 'month' ), $( this ).data( 'year' ) );
	} );

	// Reflects the current filter/sort/view state into the URL's own query
	// params (via replaceState, not pushState — a filter tweak shouldn't
	// pile up back-button history entries) so copying or bookmarking the
	// page URL reproduces the same result set, per the brief. Defaults are
	// left out of the URL entirely, so the plain/unfiltered page keeps a
	// clean URL. Only ever touches this widget's own params — assumes one
	// [cec_events] instance per page, same as the AJAX action backing it.
	function updateUrlFromState( data ) {
		if ( ! window.URL || ! window.history || ! window.history.replaceState ) {
			return;
		}
		try {
			var url = new URL( window.location.href );
			var defaults = { cec_event_type: '', cec_venue: '', cec_date_range: 'all_upcoming', cec_sort: 'date_asc', view: 'grid' };
			var paramNames = { cec_event_type: 'cec_event_type', cec_venue: 'cec_venue', cec_date_range: 'cec_date_range', cec_sort: 'cec_sort', view: 'cec_view' };
			Object.keys( paramNames ).forEach( function ( key ) {
				var param = paramNames[ key ];
				if ( data[ key ] && data[ key ] !== defaults[ key ] ) {
					url.searchParams.set( param, data[ key ] );
				} else {
					url.searchParams.delete( param );
				}
			} );
			window.history.replaceState( null, '', url.toString() );
		} catch ( e ) {} // malformed URL or an unsupported environment — URL just won't reflect state
	}

	function loadEvents( $wrap ) {
		var $results = $wrap.find( '.cec-events-results' );
		var data = { action: 'cec_filter_events', nonce: CEC.nonce, view: $wrap.attr( 'data-view' ), count: $wrap.attr( 'data-count' ), cec_sort: $wrap.attr( 'data-sort' ) };
		$wrap.find( '.cec-filter' ).each( function () {
			data[ $( this ).data( 'filter' ) ] = $( this ).val();
		} );
		updateUrlFromState( data );
		$results.css( 'opacity', 0.5 );
		$.post( CEC.ajax_url, data ).done( function ( res ) {
			if ( res.success ) {
				$results.html( res.data.html ).css( 'opacity', 1 );
			}
		} );
	}

	// The three quick-filter selects (event type, venue, date range) only
	// take effect on "Filter" — an explicit submit, not auto-reload per
	// dropdown change — so picking all three doesn't fire three separate
	// round-trips.
	$( document ).on( 'click', '.cec-filter-submit', function () {
		loadEvents( $( this ).closest( '.cec-events-wrap' ) );
	} );

	// The sort toggle is a single on/off control, not a multi-field
	// submission, so it still acts immediately on click like the view
	// toggle below.
	$( document ).on( 'click', '.cec-sort-toggle', function () {
		var $btn    = $( this );
		var $wrap   = $btn.closest( '.cec-events-wrap' );
		var reverse = 'date_asc' === $btn.data( 'sort' );
		var sort    = reverse ? 'date_desc' : 'date_asc';
		$btn.data( 'sort', sort ).attr( 'data-sort', sort );
		$wrap.attr( 'data-sort', sort );
		$btn.find( '.cec-sort-toggle-icon' ).html( reverse ? '&darr;' : '&uarr;' );
		$btn.find( '.cec-sort-toggle-label' ).text( reverse ? CEC.i18n.latestFirst : CEC.i18n.soonestFirst );
		loadEvents( $wrap );
	} );

	$( document ).on( 'click', '.cec-view-btn', function () {
		var $wrap = $( this ).closest( '.cec-events-wrap' );
		$wrap.attr( 'data-view', $( this ).data( 'view' ) );
		$wrap.find( '.cec-view-btn' ).removeClass( 'active' ).attr( 'aria-pressed', 'false' );
		$( this ).addClass( 'active' ).attr( 'aria-pressed', 'true' );
		loadEvents( $wrap );
	} );

	function toggleRecurrenceUntil( $select ) {
		var $row   = $select.closest( '.cec-field-row' );
		var $until = $row.find( '.cec-recurrence-until-field' );
		$until.prop( 'hidden', 'none' === $select.val() );
		$row.next( '.cec-recurrence-monthly-weekday-fields' ).prop( 'hidden', 'monthly_weekday' !== $select.val() );
	}
	$( document ).on( 'change', '.cec-recurrence-rule', function () {
		toggleRecurrenceUntil( $( this ) );
	} );
	$( '.cec-recurrence-rule' ).each( function () {
		toggleRecurrenceUntil( $( this ) );
	} );

	// Only the fields relevant to the chosen Admission state are useful to
	// fill in (a price range means nothing for "Free", for instance) — kept
	// in the DOM either way so nothing already typed is lost by switching
	// back and forth, just hidden.
	function toggleAdmissionFields( $select ) {
		var $wrap = $select.closest( 'form' );
		$wrap.find( '.cec-admission-paid-fields' ).prop( 'hidden', 'paid' !== $select.val() );
		$wrap.find( '.cec-admission-varies-fields' ).prop( 'hidden', 'price_varies' !== $select.val() );
	}
	$( document ).on( 'change', '.cec-admission-status-select', function () {
		toggleAdmissionFields( $( this ) );
	} );
	$( '.cec-admission-status-select' ).each( function () {
		toggleAdmissionFields( $( this ) );
	} );

	// Same idea for Location: the in-person fields (venue/address) are
	// irrelevant for a purely Online event, and the online-access URL is
	// irrelevant for a purely in-person one; Hybrid shows both.
	function toggleLocationFields( $select ) {
		var $wrap = $select.closest( 'form' );
		var mode  = $select.val();
		$wrap.find( '.cec-location-in-person-fields' ).prop( 'hidden', 'online' === mode || 'not_posted' === mode );
		$wrap.find( '.cec-location-online-fields' ).prop( 'hidden', 'online' !== mode && 'hybrid' !== mode );
	}
	$( document ).on( 'change', '.cec-location-mode-select', function () {
		toggleLocationFields( $( this ) );
	} );
	$( '.cec-location-mode-select' ).each( function () {
		toggleLocationFields( $( this ) );
	} );

	// Autofills the manager dashboard's Code of Conduct / RSVP fields from a
	// checked Partner Organization's saved defaults, but only into fields
	// that are still empty — never overwrites something already typed.
	$( document ).on( 'change', '.cec-org-autofill', function () {
		if ( ! this.checked ) {
			return;
		}
		var coc  = $( this ).data( 'coc' );
		var mode = $( this ).data( 'rsvp-mode' );
		var url  = $( this ).data( 'rsvp-url' );

		var $coc  = $( '#cec-dash-coc' );
		var $mode = $( '#cec-dash-rsvp-mode' );
		var $url  = $( '#cec-dash-rsvp-url' );

		if ( coc && $coc.length && ! $coc.val() ) {
			$coc.val( coc );
		}
		if ( url && $url.length && ! $url.val() ) {
			$url.val( url );
			if ( mode && $mode.length ) {
				$mode.val( mode );
			}
		}
	} );

	$( document ).on( 'click', '.cec-dash-status', function () {
		var $btn = $( this );
		$btn.prop( 'disabled', true );
		$.post( CEC.ajax_url, {
			action: 'cec_dashboard_action',
			nonce: CEC.nonce,
			dash_action: 'set_status',
			event_id: $btn.data( 'id' ),
			event_status: $btn.data( 'status' )
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

	$( document ).on( 'click', '.cec-expand-toggle', function () {
		var $target = $( '#' + $( this ).data( 'target' ) );
		$target.prop( 'hidden', ! $target.prop( 'hidden' ) );
		var expanded = ! $target.prop( 'hidden' );
		$( this ).text( expanded ? 'Less info' : 'More info' ).attr( 'aria-expanded', expanded ? 'true' : 'false' );
	} );

	// Selecting any date on the month calendar — via its day number or a
	// day's own "+N more" — shows the complete list of everything active
	// that date in one persistent panel below the grid, built from the
	// JSON payload rendered alongside the grid (so this never needs its
	// own server round-trip). This is the single mechanism for both
	// single-day pill overflow and multi-day bars that didn't get their
	// own lane this week — nothing is ever only reachable from a
	// specific week's own floating popup.
	function selectCalendarDate( $wrap, dateStr ) {
		var raw = $wrap.find( '.cec-cal-data' ).text();
		var byDate = {};
		try {
			byDate = JSON.parse( raw || '{}' );
		} catch ( e ) {}
		var items = byDate[ dateStr ] || [];

		$wrap.find( '.cec-cal-daynum' ).each( function () {
			$( this ).toggleClass( 'cec-cal-selected-daynum', $( this ).data( 'date' ) === dateStr );
		} );
		$wrap.find( '.cec-cal-cell' ).each( function () {
			var $num = $( this ).find( '.cec-cal-daynum' );
			$( this ).toggleClass( 'cec-cal-selected', $num.length && $num.data( 'date' ) === dateStr );
		} );

		var heading = new Date( dateStr + 'T00:00:00' ).toLocaleDateString( undefined, { weekday: 'long', month: 'long', day: 'numeric' } );
		var $detail = $wrap.find( '.cec-cal-day-detail' );
		$detail.find( '.cec-cal-day-detail-heading' ).text( heading );

		var $list = $detail.find( '.cec-cal-day-detail-list' ).empty();
		if ( ! items.length ) {
			$list.append( $( '<li class="cec-cal-day-detail-empty"></li>' ).text( 'No events on this day.' ) );
		} else {
			items.forEach( function ( item ) {
				var meta = [ item.date_range ];
				if ( item.startDate && item.startDate < dateStr ) {
					meta[ 0 ] = meta[ 0 ] + ' (in progress)';
				}
				if ( item.location ) { meta.push( item.location ); }
				if ( item.host ) { meta.push( 'Hosted by ' + item.host ); }

				var $li = $( '<li class="cec-cal-day-detail-item"></li>' );
				$( '<span class="cec-cal-day-detail-bar"></span>' ).appendTo( $li );
				var $body = $( '<div class="cec-cal-day-detail-body"></div>' ).appendTo( $li );
				$( '<strong class="cec-cal-day-detail-title"></strong>' ).text( item.title ).appendTo( $body );
				$( '<span class="cec-cal-day-detail-meta"></span>' ).text( meta.join( ' · ' ) ).appendTo( $body );
				if ( item.badge ) {
					$( '<span class="cec-badge"></span>' ).addClass( item.badgeClass ).text( item.badge ).appendTo( $li );
				}
				$( '<a class="cec-btn cec-btn-small"></a>' ).attr( 'href', item.permalink ).text( 'Event details' ).appendTo( $li );
				$list.append( $li );
			} );
		}
		$detail.prop( 'hidden', false );
		selectPhoneDate( $wrap, dateStr, byDate );
	}

	// Phone month grid (below 700px): highlight the tapped day and rebuild
	// its list. Mirrors CEC_Month_Grid::render_phone_day_list() and
	// render_phone_card(); keep the two in step.
	function cecText( key, fallback ) {
		return ( window.CEC && CEC.i18n && CEC.i18n[ key ] ) ? CEC.i18n[ key ] : fallback;
	}

	function daysBetween( a, b ) {
		return Math.round( ( new Date( b + 'T12:00:00' ) - new Date( a + 'T12:00:00' ) ) / 864e5 );
	}

	function phoneCard( item, dateStr ) {
		var single = item.startDate === item.endDate;
		var $a = $( '<a class="cec-cal-pcard"></a>' ).attr( 'href', item.permalink );
		if ( single ) { $a.addClass( 'cec-cal-pcard-single' ); }
		if ( item.status && 'scheduled' !== item.status ) { $a.addClass( 'cec-cal-pcard-' + item.status ); }
		$( '<span class="cec-cal-pcard-bar"></span>' ).appendTo( $a );
		var $body = $( '<span class="cec-cal-pcard-body"></span>' ).appendTo( $a );
		$( '<span class="cec-cal-pcard-title"></span>' ).text( item.title ).appendTo( $body );
		var $when = $( '<span class="cec-cal-pcard-when"></span>' ).appendTo( $body );
		$( '<span class="cec-cal-pcard-chip"></span>' ).text( single ? ( item.time || cecText( 'allDay', 'All day' ) ) : item.date_range ).appendTo( $when );
		if ( ! single && item.startDate < dateStr ) {
			var of = cecText( 'dayOf', 'day %1$d of %2$d' )
				.replace( '%1$d', daysBetween( item.startDate, dateStr ) + 1 )
				.replace( '%2$d', daysBetween( item.startDate, item.endDate ) + 1 );
			$( '<span class="cec-cal-pcard-of"></span>' ).text( of ).appendTo( $when );
		}
		var where = [ item.location, item.badge ].filter( Boolean );
		if ( where.length ) {
			$( '<span class="cec-cal-pcard-where"></span>' ).text( where.join( ' · ' ) ).appendTo( $body );
		}
		$( '<span class="cec-cal-pcard-chev" aria-hidden="true">›</span>' ).appendTo( $a );
		return $a;
	}

	function selectPhoneDate( $wrap, dateStr, byDate ) {
		var $phone = $wrap.find( '.cec-cal-phone' );
		if ( ! $phone.length ) { return; }
		$phone.find( '.cec-cal-pday[data-date]' ).each( function () {
			var on = $( this ).data( 'date' ) === dateStr;
			$( this ).toggleClass( 'cec-cal-pday-selected', on ).attr( 'aria-pressed', on ? 'true' : 'false' );
		} );
		var items = byDate[ dateStr ] || [];
		var starts = items.filter( function ( i ) { return ! ( i.startDate < dateStr ); } );
		var running = items.filter( function ( i ) { return i.startDate < dateStr; } );
		var $list = $phone.find( '.cec-cal-pday-list' ).empty();
		var heading = new Date( dateStr + 'T00:00:00' ).toLocaleDateString( undefined, { weekday: 'long', month: 'long', day: 'numeric' } );
		$( '<h4 class="cec-cal-pday-heading"></h4>' ).text( heading ).appendTo( $list );
		if ( ! items.length ) {
			$( '<p class="cec-cal-pday-empty-note"></p>' ).text( cecText( 'nothingDay', 'Nothing on this day.' ) ).appendTo( $list );
			return;
		}
		var count = ( 1 === items.length ? cecText( 'oneEvent', '%d event' ) : cecText( 'manyEvents', '%d events' ) ).replace( '%d', items.length );
		$( '<p class="cec-cal-pday-count"></p>' ).text( count ).appendTo( $list );
		if ( starts.length && running.length ) {
			$( '<p class="cec-cal-pday-sub"></p>' ).text( cecText( 'startingDay', 'Starting this day' ) ).appendTo( $list );
		}
		starts.forEach( function ( i ) { $list.append( phoneCard( i, dateStr ) ); } );
		if ( running.length ) {
			$( '<p class="cec-cal-pday-sub"></p>' ).text( cecText( 'stillRunning', 'Still running' ) ).appendTo( $list );
			running.forEach( function ( i ) { $list.append( phoneCard( i, dateStr ) ); } );
		}
	}

	$( document ).on( 'click', '.cec-cal-pday[data-date]', function () {
		selectCalendarDate( $( this ).closest( '.cec-calendar-wrap' ), $( this ).data( 'date' ) );
	} );

	$( document ).on( 'click', '.cec-cal-daynum, .cec-cal-daymore', function () {
		selectCalendarDate( $( this ).closest( '.cec-calendar-wrap' ), $( this ).data( 'date' ) );
	} );

	$( document ).on( 'click', '.cec-cal-today-btn', function () {
		var $btn    = $( this );
		var $wrap   = $btn.closest( '.cec-calendar-wrap' );
		var date    = $btn.data( 'date' );
		if ( ! $btn.data( 'jump' ) ) {
			selectCalendarDate( $wrap, date );
			return;
		}
		$.post( CEC.ajax_url, {
			action: 'cec_calendar_month',
			nonce: CEC.nonce,
			month: $btn.data( 'month' ),
			year: $btn.data( 'year' )
		} ).done( function ( res ) {
			if ( res.success ) {
				$wrap.html( res.data.html ).attr( 'data-month', $btn.data( 'month' ) ).attr( 'data-year', $btn.data( 'year' ) );
				selectCalendarDate( $wrap, date );
			}
		} );
	} );

	$( document ).on( 'click', '.cec-scroll-left', function () {
		$( this ).siblings( '.cec-upcoming-scroll' ).animate( { scrollLeft: '-=250' }, 300 );
	} );
	$( document ).on( 'click', '.cec-scroll-right', function () {
		$( this ).siblings( '.cec-upcoming-scroll' ).animate( { scrollLeft: '+=250' }, 300 );
	} );

	$( document ).on( 'submit', '.cec-rsvp-form', function ( e ) {
		e.preventDefault();
		var $form = $( this );
		var $box = $form.closest( '.cec-rsvp-box' );
		var $msg = $form.find( '.cec-rsvp-message' );
		$.post( CEC.ajax_url, {
			action: 'cec_rsvp',
			nonce: CEC.nonce,
			event_id: $box.data( 'event-id' ),
			name: $form.find( '[name=name]' ).val(),
			email: $form.find( '[name=email]' ).val(),
			guests: $form.find( '[name=guests]' ).val()
		} ).done( function ( res ) {
			$msg.prop( 'hidden', false ).text( res.data.message );
			if ( res.success ) {
				$form.find( 'input, button' ).prop( 'disabled', true );
			}
		} );
	} );

	$( document ).on( 'submit', '.cec-waitlist-form', function ( e ) {
		e.preventDefault();
		var $form = $( this );
		var $box = $form.closest( '.cec-rsvp-box' );
		var $msg = $form.find( '.cec-rsvp-message' );
		$.post( CEC.ajax_url, {
			action: 'cec_rsvp_waitlist',
			nonce: CEC.nonce,
			event_id: $box.data( 'event-id' ),
			name: $form.find( '[name=name]' ).val(),
			email: $form.find( '[name=email]' ).val(),
			guests: $form.find( '[name=guests]' ).val()
		} ).done( function ( res ) {
			$msg.prop( 'hidden', false ).text( res.data.message );
			if ( res.success ) {
				$form.find( 'input, button' ).prop( 'disabled', true );
			}
		} );
	} );

} )( jQuery );
