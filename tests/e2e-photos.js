// Browser half of tests/e2e-photos-e2e.sh (CMP 0.33.0). Args: host, member
// page URL, lock id, temp folder, test-kit folder, wearer id, keyholder id.
const { chromium } = require( 'playwright' );
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const [ H, PAGE, LID, T, S, PUP, KH ] = process.argv.slice( 2 );
const TAB = PAGE + '&cmp_tab=chastity';
const LOCKPAGE = TAB + '&lock=' + LID;
let pass = 0, fail = 0;
function ok( name, cond ) {
	console.log( ( cond ? 'PASS ' : 'FAIL ' ) + name );
	cond ? pass++ : fail++;
}
function php( code ) {
	return execFileSync( 'php', [ S + '/wp-cli.phar', '--allow-root', 'eval', code ], { cwd: S + '/wordpress' } ).toString().trim();
}
const PUP_PASS = 'pup photo passphrase 1', KH_PASS = 'keyholder passphrase 2';

async function login( browser, user, pw ) {
	const ctx = await browser.newContext( { acceptDownloads: true } );
	const page = await ctx.newPage();
	await page.goto( H + '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pw );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
	return { ctx, page };
}
async function setUp( page, pw ) {
	await page.goto( TAB + '&e2e=1' );
	await page.fill( '#cmp_e2e_p1', pw );
	await page.fill( '#cmp_e2e_p2', pw );
	await Promise.all( [ page.waitForNavigation( { timeout: 60000 } ), page.click( '[data-cmp-e2e-setup] button[type="submit"]' ) ] );
}
async function shown( page, sel ) {
	try {
		await page.waitForSelector( sel + '[data-cmp-e2e-shown="1"]', { state: 'attached', timeout: 20000 } );
		return await page.$eval( sel + '[data-cmp-e2e-shown="1"]', ( i ) => i.complete && i.naturalWidth > 0 );
	} catch ( e ) {
		return false;
	}
}

( async () => {
	const browser = await chromium.launch( { executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' } ).catch( () => chromium.launch() );
	const pup = await login( browser, 'epup', 'epuppass12345' );
	const kh = await login( browser, 'ekh', 'ekhpass123456' );
	const nosy = await login( browser, 'enosy', 'enosypass1234' );

	// Setup: validation in the page, then real keys.
	await pup.page.goto( TAB + '&e2e=1' );
	ok( 'the panel explains end-to-end and that a lost passphrase can\'t be recovered', ( await pup.page.content() ).includes( 'can&#039;t be recovered' ) || ( await pup.page.content() ).includes( "can't be recovered" ) );
	ok( 'passphrase fields have no name (never posted)', await pup.page.$eval( '#cmp_e2e_p1', ( i ) => ! i.name ) );
	await pup.page.fill( '#cmp_e2e_p1', 'short' );
	await pup.page.fill( '#cmp_e2e_p2', 'short' );
	await pup.page.click( '[data-cmp-e2e-setup] button[type="submit"]' );
	await pup.page.waitForTimeout( 500 );
	ok( 'too short: refused in the page, nothing sent', pup.page.url().endsWith( '&e2e=1' ) && php( `echo CMP_E2E::has_key(${ PUP }) ? 1 : 0;` ) === '0' );
	await setUp( pup.page, PUP_PASS );
	ok( 'wearer set up: notice, key stored, mode on', pup.page.url().includes( 'cl_e2e_ready' ) && php( `echo CMP_E2E::is_on(${ PUP }) && CMP_E2E::valid_pub(CMP_E2E::pub(${ PUP })) ? 1 : 0;` ) === '1' );
	ok( 'the stored box doesn\'t contain the passphrase', ! php( `echo CMP_E2E::box(${ PUP });` ).includes( PUP_PASS ) );
	await setUp( kh.page, KH_PASS );
	ok( 'keyholder set up too', php( `echo CMP_E2E::has_key(${ KH }) ? 1 : 0;` ) === '1' );

	// Upload: encrypted in the browser for both.
	await pup.page.goto( TAB );
	const rc = JSON.parse( await pup.page.getAttribute( 'form[data-cmp-e2e-upload]', 'data-cmp-e2e-upload' ) );
	ok( 'the verify form encrypts for the wearer and the keyholder', rc.length === 2 && rc.map( ( r ) => String( r.u ) ).sort().join() === [ PUP, KH ].sort().join() );
	await pup.page.setInputFiles( '#cmp_cl_vphoto', T + '/photo.jpg' );
	await Promise.all( [ pup.page.waitForNavigation( { timeout: 60000 } ), pup.page.click( 'form[data-cmp-e2e-upload] button[type="submit"]' ) ] );
	ok( 'sent: Recorded', pup.page.url().includes( 'cl_logged' ) );
	if ( ! pup.page.url().includes( 'cl_logged' ) ) {
		console.log( 'DEBUG url ' + pup.page.url() + ' msg ' + await pup.page.textContent( '[data-cmp-e2e-msg]' ).catch( () => '' ) );
	}
	const info = JSON.parse( php( `global $wpdb; $e=$wpdb->get_row('SELECT * FROM '.CMP_Install::table('lock_events')." WHERE lock_id=${ LID } AND type='verification' ORDER BY id DESC LIMIT 1"); $env=CMP_Photo_Vault::open($e->photo,${ LID },${ PUP }); $h=CMP_E2E::header($env); echo wp_json_encode(array('id'=>(int)$e->id,'e2e'=>(int)$e->photo_e2e,'sealed'=>CMP_Photo_Vault::is_sealed($e->photo),'magic'=>substr($env,0,7),'jpeg'=>false!==strpos($env,"\\xFF\\xD8\\xFF"),'gps'=>false!==strpos($env,'SECRET-GPS'),'r'=>array_map(function($r){return $r['u'];},$h['r'])));` ) );
	ok( 'stored as an end-to-end envelope, also sealed by the server, with no JPEG or GPS data in it', info.e2e === 1 && info.sealed && info.magic === 'CMPE2E1' && ! info.jpeg && ! info.gps );
	ok( 'wrapped for exactly the wearer and keyholder', info.r.map( String ).sort().join() === [ PUP, KH ].sort().join() );
	const PIC = H + '/?cmp_lockpic=' + info.id;

	// The wearer sees it (key kept in this browser since setup).
	await pup.page.goto( TAB + '&photos=1' );
	ok( 'wearer: decrypted and shown in Your photos', await shown( pup.page, '.cmp-cl-photos img' ) );
	const dims = await pup.page.$eval( '.cmp-cl-photos img[data-cmp-e2e-shown]', ( i ) => [ i.naturalWidth, i.naturalHeight ] );
	ok( 'resized in the browser to at most 1600px', Math.max( ...dims ) === 1600 );
	const [ dl ] = await Promise.all( [ pup.page.waitForEvent( 'download' ), pup.page.click( '[data-cmp-e2e-dl]' ) ] );
	const file = fs.readFileSync( await dl.path() );
	ok( 'Download gives the decrypted JPEG, without the GPS marker', file[ 0 ] === 0xff && file[ 1 ] === 0xd8 && ! file.includes( 'SECRET-GPS-MARKER' ) && dl.suggestedFilename().startsWith( 'colandb-lock-' ) );

	// The keyholder.
	await kh.page.goto( LOCKPAGE + '&e2e=1' );
	ok( 'keyholder: decrypted and shown for review', await shown( kh.page, '.cmp-hw-proof img' ) );
	await Promise.all( [ kh.page.waitForNavigation(), kh.page.click( '[data-cmp-e2e-forget]' ) ] );
	ok( 'Forget on this device: not shown any more', ! ( await shown( kh.page, '.cmp-hw-proof img' ) ) );
	await kh.page.fill( '#cmp_e2e_unlock', 'not the passphrase' );
	await kh.page.click( '[data-cmp-e2e-unlock] button[type="submit"]' );
	await kh.page.waitForFunction( () => document.querySelector( '[data-cmp-e2e-unlock] [data-cmp-e2e-msg]' ).textContent.includes( 'isn' ), null, { timeout: 30000 } );
	ok( 'wrong passphrase: refused', true );
	await kh.page.fill( '#cmp_e2e_unlock', KH_PASS );
	await kh.page.click( '[data-cmp-e2e-unlock] button[type="submit"]' );
	ok( 'right passphrase: unlocked and shown', await shown( kh.page, '.cmp-hw-proof img' ) );

	// Someone else.
	const r404 = await nosy.page.request.get( PIC );
	ok( 'another member gets 404 for the envelope', r404.status() === 404 );
	const rkh = await kh.page.request.get( PIC );
	ok( 'the server hands authorised viewers only the encrypted envelope', rkh.status() === 200 && rkh.headers()[ 'content-type' ] === 'application/octet-stream' && ( await rkh.body() ).slice( 0, 7 ).toString() === 'CMPE2E1' );

	// Plain upload while end-to-end is on: refused.
	const nonce = await pup.page.getAttribute( 'form[data-cmp-e2e-upload] input[name="_cmp_nonce"]', 'value' );
	const plain = await pup.page.request.post( H + '/wp-admin/admin-post.php', { multipart: { action: 'cmp_chastity', _cmp_nonce: nonce, do: 'verify', lock: LID, photo: { name: 'p.jpg', mimeType: 'image/jpeg', buffer: fs.readFileSync( T + '/photo.jpg' ) } }, maxRedirects: 0 } );
	ok( 'an unencrypted photo is refused while end-to-end is on', ( plain.headers().location || '' ).includes( 'cl_e2e_needed' ) );
	const bogus = await pup.page.request.post( H + '/wp-admin/admin-post.php', { multipart: { action: 'cmp_chastity', _cmp_nonce: nonce, do: 'verify', lock: LID, e2e: '1', photo: { name: 'p.cmpe2e', mimeType: 'application/octet-stream', buffer: Buffer.concat( [ Buffer.from( 'CMPE2E1' ), Buffer.from( [ 0, 0, 0, 2 ] ), Buffer.from( '{}' ), Buffer.alloc( 500 ) ] ) } }, maxRedirects: 0 } );
	ok( 'a malformed envelope is refused', ( bogus.headers().location || '' ).includes( 'cl_e2e_needed' ) );

	// Share link: the key travels in the #fragment.
	await pup.page.goto( TAB + '&photos=1' );
	await pup.page.$eval( '.cmp-cl-shareform', ( f ) => {
		f.closest( 'details' ).open = true;
		f.querySelector( 'input[name="once"]' ).checked = false;
	} );
	await Promise.all( [ pup.page.waitForNavigation(), pup.page.click( '.cmp-cl-shareform button[type="submit"]' ) ] );
	await pup.page.waitForFunction( () => {
		const i = document.querySelector( '[data-cmp-e2e-share]' );
		return i && i.value.includes( '#k=' );
	}, null, { timeout: 20000 } );
	const link = await pup.page.$eval( '[data-cmp-e2e-share]', ( i ) => i.value );
	ok( 'the wearer\'s link includes the key after #k=', /#k=[A-Za-z0-9_-]{43}$/.test( link ) );
	ok( 'the key is never stored on the server', ! php( `global $wpdb; echo wp_json_encode($wpdb->get_results('SELECT * FROM '.CMP_Install::table('photo_shares')));` ).includes( link.split( '#k=' )[ 1 ] ) );
	const anon = await browser.newContext();
	const ap = await anon.newPage();
	await ap.goto( link );
	ok( 'opening the link: View photo, no photo yet', await ap.isVisible( '#cmp-view button' ) && ! ( await ap.$( '#cmp-img:not([hidden])' ) ) );
	await Promise.all( [ ap.waitForNavigation(), ap.click( '#cmp-view button' ) ] );
	await ap.waitForSelector( '#cmp-img:not([hidden])', { timeout: 20000 } ).catch( () => {} );
	ok( 'View photo: decrypted in the visitor\'s browser', await ap.$eval( '#cmp-img', ( i ) => ! i.hidden && i.naturalWidth > 0 ).catch( () => false ) );
	ok( '...and the key is removed from the address bar', ! ap.url().includes( '#k=' ) );
	const ap2 = await anon.newPage();
	await ap2.goto( link.split( '#' )[ 0 ] );
	ok( 'the link without its #k= part: no View button, explains why', ! ( await ap2.isVisible( '#cmp-view' ) ) && await ap2.isVisible( '#cmp-nokey' ) );
	const ap3 = await anon.newPage();
	await ap3.goto( link.split( '#k=' )[ 0 ] + '#k=' + 'A'.repeat( 43 ) );
	await Promise.all( [ ap3.waitForNavigation(), ap3.click( '#cmp-view button' ) ] );
	await ap3.waitForFunction( () => document.getElementById( 'cmp-msg' ).textContent.includes( 'couldn' ), null, { timeout: 20000 } ).catch( () => {} );
	ok( 'a wrong key: "couldn\'t be opened", no photo', ( await ap3.textContent( '#cmp-msg' ) ).includes( 'couldn' ) && await ap3.$eval( '#cmp-img', ( i ) => i.hidden ) );
	const csp = ( await anon.request.get( link.split( '#' )[ 0 ] ) ).headers()[ 'content-security-policy' ] || '';
	ok( 'share page CSP: only its own nonce script, images from blob:', /script-src 'nonce-[0-9a-f]{32}'/.test( csp ) && csp.includes( 'img-src blob: data:' ) && ! csp.includes( 'unsafe-eval' ) );

	// Passphrase change.
	await pup.page.goto( TAB + '&e2e=1' );
	await pup.page.$eval( '[data-cmp-e2e-rewrap]', ( f ) => {
		f.closest( 'details' ).open = true;
	} );
	await pup.page.fill( '#cmp_e2e_old', PUP_PASS );
	await pup.page.fill( '#cmp_e2e_new', 'a brand new passphrase' );
	await pup.page.fill( '#cmp_e2e_new2', 'a brand new passphrase' );
	await Promise.all( [ pup.page.waitForNavigation( { timeout: 60000 } ), pup.page.click( '[data-cmp-e2e-rewrap] button[type="submit"]' ) ] );
	ok( 'passphrase changed', pup.page.url().includes( 'cl_e2e_rewrapped' ) );
	const pup2 = await login( browser, 'epup', 'epuppass12345' );
	await pup2.page.goto( TAB + '&e2e=1&photos=1' );
	await pup2.page.fill( '#cmp_e2e_unlock', PUP_PASS );
	await pup2.page.click( '[data-cmp-e2e-unlock] button[type="submit"]' );
	await pup2.page.waitForFunction( () => document.querySelector( '[data-cmp-e2e-unlock] [data-cmp-e2e-msg]' ).textContent.includes( 'isn' ), null, { timeout: 30000 } ).catch( () => {} );
	ok( 'on a new device the old passphrase no longer works', ( await pup2.page.textContent( '[data-cmp-e2e-unlock] [data-cmp-e2e-msg]' ) ).includes( 'isn' ) );
	await pup2.page.fill( '#cmp_e2e_unlock', 'a brand new passphrase' );
	await pup2.page.click( '[data-cmp-e2e-unlock] button[type="submit"]' );
	ok( '...the new one does, and the photo shows', await shown( pup2.page, '.cmp-cl-photos img' ) );

	// Turning it off.
	await pup.page.goto( TAB + '&e2e=1' );
	await Promise.all( [ pup.page.waitForNavigation(), pup.page.click( 'form:has(input[name="do"][value="e2e_mode"]) button' ) ] );
	await pup.page.goto( TAB );
	ok( 'turned off: the verify form uploads normally again', ! ( await pup.page.$( 'form[data-cmp-e2e-upload]' ) ) && php( `echo CMP_E2E::is_on(${ PUP }) ? 1 : 0;` ) === '0' );

	await browser.close();
	console.log( `RESULT: ${ pass } passed, ${ fail } failed` );
	process.exit( fail ? 1 : 0 );
} )().catch( ( e ) => {
	console.log( 'FAIL crashed: ' + e.message );
	console.log( `RESULT: ${ pass } passed, ${ fail + 1 } failed` );
	process.exit( 1 );
} );
