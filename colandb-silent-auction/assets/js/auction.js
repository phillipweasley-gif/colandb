/* COL&B Silent Auction 0.1.0: countdowns, and a check before an unusually large bid or Buy It Now. */
( function () {
	'use strict';

	function left( seconds ) {
		if ( seconds <= 0 ) {
			return null;
		}
		var d = Math.floor( seconds / 86400 ),
			h = Math.floor( ( seconds % 86400 ) / 3600 ),
			m = Math.floor( ( seconds % 3600 ) / 60 ),
			s = seconds % 60;
		if ( d > 0 ) {
			return d + 'd ' + h + 'h';
		}
		if ( h > 0 ) {
			return h + 'h ' + m + 'm';
		}
		return m + 'm ' + ( s < 10 ? '0' : '' ) + s + 's';
	}

	var timers = document.querySelectorAll( '[data-csa-end]' );
	function tick() {
		var now = Math.floor( Date.now() / 1000 );
		for ( var i = 0; i < timers.length; i++ ) {
			var el = timers[ i ],
				t = left( parseInt( el.getAttribute( 'data-csa-end' ), 10 ) - now );
			el.textContent = t ? t + ' ' + el.getAttribute( 'data-csa-prefix' ) : 'refresh the page';
		}
	}
	if ( timers.length ) {
		tick();
		setInterval( tick, 1000 );
	}

	document.addEventListener( 'submit', function ( e ) {
		var f = e.target;
		if ( f.classList && f.classList.contains( 'csa-buy' ) ) {
			if ( ! window.confirm( f.getAttribute( 'data-csa-confirm' ) ) ) {
				e.preventDefault();
			}
			return;
		}
		if ( f.classList && f.classList.contains( 'csa-bid' ) ) {
			var min = parseFloat( f.getAttribute( 'data-csa-min' ) ),
				amt = parseFloat( f.querySelector( 'input[name="csa_amount"]' ).value );
			if ( amt > min * 3 && amt - min >= 50 && ! window.confirm( 'Bid $' + amt.toFixed( 2 ).replace( /\.00$/, '' ) + '? That is well above the minimum of $' + min + '.' ) ) {
				e.preventDefault();
			}
		}
	} );
}() );
