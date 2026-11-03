// Browser half of tests/locations-e2e.sh (CEC 1.49.0).
// Usage: node tests/locations-e2e.js <calendar page URL> <output folder>
const { chromium } = require( 'playwright' );
const [ , , URL_, OUT ] = process.argv;

let pass = 0, fail = 0;
function ok( name, cond, got ) {
	if ( cond ) { pass++; console.log( 'PASS ' + name ); } else { fail++; console.log( 'FAIL ' + name + ( undefined !== got ? ' (got: ' + JSON.stringify( got ) + ')' : '' ) ); }
}

( async () => {
	const browser = await chromium.launch();
	const errors = [];
	const open = async ( width, height, url ) => {
		const page = await browser.newPage( { viewport: { width, height } } );
		page.on( 'pageerror', ( e ) => errors.push( e.message ) );
		await page.goto( url || URL_ );
		await page.waitForSelector( '.cec-calendar-wrap' );
		return page;
	};
	const titles = ( page ) => page.$$eval( '.cec-cal-grid-wrap .cec-cal-pill, .cec-cal-grid-wrap .cec-cal-bar', ( els ) => els.map( ( e ) => e.getAttribute( 'title' ) ) );
	const options = ( page, level ) => page.$$eval( `.cec-loc-select[data-level="${ level }"] option`, ( els ) => els.map( ( e ) => e.value ).filter( Boolean ) );
	const waitLoaded = ( page ) => page.waitForFunction( () => ! document.querySelector( '.cec-calendar-wrap.cec-cal-loading' ) );

	// Wide: the day list is beside the grid, on the right, and filled on load.
	let page = await open( 1400, 900 );
	await page.waitForSelector( '.cec-cal-day-detail:not([hidden])' );
	let g = await page.locator( '.cec-cal-grid-wrap' ).boundingBox();
	let d = await page.locator( '.cec-cal-day-detail' ).boundingBox();
	ok( 'wide: day list opens on load, to the right of the grid', d && g && d.x >= g.x + g.width - 1 && Math.abs( d.y - g.y ) < 40, { g, d } );
	ok( 'wide: grid keeps most of the width', g && g.width > 700, g && g.width );

	// Click a date: the list shows that day's events.
	const m = await page.$eval( '.cec-calendar-wrap', ( w ) => [ w.dataset.year, String( w.dataset.month ).padStart( 2, '0' ) ] );
	const day10 = `${ m[ 0 ] }-${ m[ 1 ] }-10`;
	await page.click( `.cec-cal-daynum[data-date="${ day10 }"]` );
	let list = await page.$$eval( '.cec-cal-day-detail-title', ( els ) => els.map( ( e ) => e.textContent ) );
	ok( 'clicking the 10th lists its two events', list.includes( 'ZZ Loc Columbus' ) && list.includes( 'ZZ Loc Cleveland' ), list );
	await page.screenshot( { path: OUT + '/wide.png', fullPage: true } );

	// Country narrows the state list; state narrows the cities.
	await page.selectOption( '.cec-loc-select[data-level="country"]', 'United States' );
	await waitLoaded( page );
	let regions = await options( page, 'region' );
	ok( 'country United States: states are US only', regions.includes( 'Ohio' ) && regions.includes( 'Illinois' ) && ! regions.includes( 'Ontario' ), regions );
	ok( 'URL carries cec_country', page.url().includes( 'cec_country=United+States' ) || page.url().includes( 'cec_country=United%20States' ), page.url() );
	await page.selectOption( '.cec-loc-select[data-level="region"]', 'Ohio' );
	await waitLoaded( page );
	await page.waitForTimeout( 300 );
	let cities = await options( page, 'city' );
	ok( 'state Ohio: cities are Cleveland and Columbus', 'Cleveland,Columbus' === cities.join( ',' ), cities );
	let shown = await titles( page );
	ok( 'state Ohio: calendar shows Ohio events only', shown.includes( 'ZZ Loc Columbus' ) && ! shown.includes( 'ZZ Loc Chicago' ) && ! shown.includes( 'ZZ Loc Berlin' ), shown );
	ok( 'the day list still shows after filtering', await page.isVisible( '.cec-cal-day-detail' ) );
	ok( 'Show all places button appears', await page.isVisible( '.cec-loc-clear' ) );

	// Month arrows keep the filter (Toronto next month is not in Ohio).
	await page.click( '.cec-cal-next' );
	await waitLoaded( page );
	await page.waitForTimeout( 300 );
	shown = await titles( page );
	ok( 'next month with Ohio chosen: Toronto hidden', ! shown.includes( 'ZZ Loc Toronto' ), shown );
	ok( 'filter still chosen after the arrow', 'Ohio' === await page.inputValue( '.cec-loc-select[data-level="region"]' ) );
	ok( 'subscribe links survive the arrow', await page.locator( '.cec-subscribe-links' ).count() > 0 );

	// Show all places.
	await page.click( '.cec-loc-clear' );
	await waitLoaded( page );
	await page.waitForTimeout( 300 );
	shown = await titles( page );
	ok( 'Show all places: Toronto shows again', shown.includes( 'ZZ Loc Toronto' ), shown );
	ok( 'URL is clean again', ! page.url().includes( 'cec_' ), page.url() );
	ok( 'Show all places button hides', ! await page.isVisible( '.cec-loc-clear' ) );

	// Today button with a city chosen.
	await page.selectOption( '.cec-loc-select[data-level="city"]', 'Chicago' );
	await waitLoaded( page );
	await page.click( '.cec-cal-today-btn' );
	await waitLoaded( page );
	await page.waitForTimeout( 300 );
	shown = await titles( page );
	ok( 'Today button keeps the city filter', shown.includes( 'ZZ Loc Chicago' ) && ! shown.includes( 'ZZ Loc Columbus' ), shown );
	await page.close();

	// Medium width: not enough room, so the list stays below the grid.
	page = await open( 900, 900 );
	await page.waitForSelector( '.cec-cal-day-detail:not([hidden])' );
	g = await page.locator( '.cec-cal-grid-wrap' ).boundingBox();
	d = await page.locator( '.cec-cal-day-detail' ).boundingBox();
	ok( 'medium: day list below the grid', d && g && d.y >= g.y + g.height - 1, { g, d } );
	await page.close();

	// Phone: its own grid and day list; the desktop panel is hidden.
	page = await open( 390, 844 );
	ok( 'phone: phone calendar shows', await page.isVisible( '.cec-cal-phone' ) );
	ok( 'phone: desktop grid and panel hidden', ! await page.isVisible( '.cec-cal-grid-wrap' ) && ! await page.isVisible( '.cec-cal-day-detail' ) );
	ok( 'phone: filter fits the screen', await page.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth + 1 ) );
	await page.screenshot( { path: OUT + '/phone.png', fullPage: true } );
	await page.close();

	ok( 'no JavaScript errors', 0 === errors.length, errors );
	await browser.close();
	console.log( `browser: ${ pass } passed, ${ fail } failed` );
	process.exit( fail ? 1 : 0 );
} )().catch( ( e ) => { console.log( 'FAIL browser crashed: ' + e.message ); process.exit( 1 ); } );
