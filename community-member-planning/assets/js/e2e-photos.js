/* Community Member Planning 0.33.0: optional end-to-end encryption for
   chastity photos. See docs/privacy-security-plan.md §7.

   - Each member who turns it on has an ECDH P-256 key pair made here. The
     private key is stored on the server only inside a "box": AES-256-GCM
     under a key derived from their photo passphrase (PBKDF2-SHA-256,
     600,000 rounds). The passphrase never leaves this page.
   - Unlocking keeps the private key in this browser's IndexedDB as a
     non-extractable CryptoKey, so pages can show photos without asking again.
   - A photo is re-saved through a canvas (drops EXIF/GPS, max 1600 px),
     encrypted with a random AES-256-GCM key, and that key is wrapped for each
     recipient with an ephemeral ECDH key + HKDF-SHA-256 + AES-GCM.
   - Envelope: "CMPE2E1" + uint32 header length + header JSON + ciphertext.
     Header: { v: 1, iv, r: [ { u: user id, e: ephemeral public key, i: iv, w: wrapped key } ] }. */
( function () {
	'use strict';
	var root = document.getElementById( 'cmp-e2e' );
	var subtle = window.crypto && window.crypto.subtle;
	if ( ! root ) {
		return;
	}
	var cfg = JSON.parse( root.getAttribute( 'data-config' ) || '{}' );
	var t = cfg.i18n || {};
	var MAGIC = 'CMPE2E1';
	var ITER = 600000;
	var AAD = new TextEncoder().encode( 'cmp-e2e-v1' );
	var enc = new TextEncoder();

	function say( el, text, bad ) {
		if ( ! el ) {
			return;
		}
		el.textContent = text || '';
		el.classList.toggle( 'is-error', !! bad );
	}
	if ( ! subtle || ! window.indexedDB ) {
		say( root.querySelector( '[data-cmp-e2e-status]' ), t.unsupported, true );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-cmp-e2e-upload] button[type="submit"]' ), function ( b ) {
			b.disabled = true;
		} );
		return;
	}

	/* bytes <-> base64 */
	function b64( buf ) {
		var b = new Uint8Array( buf ), s = '';
		for ( var i = 0; i < b.length; i += 0x8000 ) {
			s += String.fromCharCode.apply( null, b.subarray( i, i + 0x8000 ) );
		}
		return btoa( s );
	}
	function unb64( s ) {
		s = String( s ).replace( /-/g, '+' ).replace( /_/g, '/' );
		while ( s.length % 4 ) {
			s += '=';
		}
		var bin = atob( s ), out = new Uint8Array( bin.length );
		for ( var i = 0; i < bin.length; i++ ) {
			out[ i ] = bin.charCodeAt( i );
		}
		return out;
	}
	function b64url( buf ) {
		return b64( buf ).replace( /\+/g, '-' ).replace( /\//g, '_' ).replace( /=+$/, '' );
	}
	function rnd( n ) {
		return window.crypto.getRandomValues( new Uint8Array( n ) );
	}

	/* IndexedDB: one store, key "<user id>:<key id>" -> CryptoKey. */
	function db() {
		return new Promise( function ( res, rej ) {
			var r = indexedDB.open( 'cmp-e2e', 1 );
			r.onupgradeneeded = function () {
				r.result.createObjectStore( 'keys' );
			};
			r.onsuccess = function () {
				res( r.result );
			};
			r.onerror = function () {
				rej( r.error );
			};
		} );
	}
	function store( mode, fn ) {
		return db().then( function ( d ) {
			return new Promise( function ( res, rej ) {
				var tx = d.transaction( 'keys', mode ), req = fn( tx.objectStore( 'keys' ) );
				tx.oncomplete = function () {
					res( req && req.result );
				};
				tx.onerror = function () {
					rej( tx.error );
				};
			} );
		} );
	}
	var slot = cfg.uid + ':' + cfg.kid;
	function savedKey() {
		return cfg.kid ? store( 'readonly', function ( s ) {
			return s.get( slot );
		} ) : Promise.resolve( null );
	}
	function saveKey( k ) {
		return store( 'readwrite', function ( s ) {
			return s.put( k, slot );
		} );
	}
	function forgetKeys() {
		return store( 'readwrite', function ( s ) {
			return s.clear();
		} );
	}

	/* Keys */
	function derive( pass, salt, iter ) {
		return subtle.importKey( 'raw', enc.encode( pass ), 'PBKDF2', false, [ 'deriveKey' ] ).then( function ( base ) {
			return subtle.deriveKey( { name: 'PBKDF2', salt: salt, iterations: iter, hash: 'SHA-256' }, base, { name: 'AES-GCM', length: 256 }, false, [ 'encrypt', 'decrypt' ] );
		} );
	}
	function sealBox( pk8, pass ) {
		var salt = rnd( 16 ), iv = rnd( 12 );
		return derive( pass, salt, ITER ).then( function ( k ) {
			return subtle.encrypt( { name: 'AES-GCM', iv: iv }, k, pk8 );
		} ).then( function ( ct ) {
			return JSON.stringify( { v: 1, salt: b64( salt ), iter: ITER, iv: b64( iv ), ct: b64( ct ) } );
		} );
	}
	/* Rejects on a wrong passphrase (AES-GCM fails). */
	function openBox( box, pass ) {
		var b = JSON.parse( box );
		return derive( pass, unb64( b.salt ), b.iter ).then( function ( k ) {
			return subtle.decrypt( { name: 'AES-GCM', iv: unb64( b.iv ) }, k, unb64( b.ct ) );
		} );
	}
	function importPriv( pk8 ) {
		return subtle.importKey( 'pkcs8', pk8, { name: 'ECDH', namedCurve: 'P-256' }, false, [ 'deriveBits' ] );
	}
	function importPub( raw ) {
		return subtle.importKey( 'raw', raw, { name: 'ECDH', namedCurve: 'P-256' }, false, [] );
	}
	function keyId( pubRaw ) {
		return subtle.digest( 'SHA-256', pubRaw ).then( function ( h ) {
			return Array.prototype.map.call( new Uint8Array( h ).subarray( 0, 8 ), function ( x ) {
				return ( '0' + x.toString( 16 ) ).slice( -2 );
			} ).join( '' );
		} );
	}
	function kek( bits, uid ) {
		return subtle.importKey( 'raw', bits, 'HKDF', false, [ 'deriveKey' ] ).then( function ( base ) {
			return subtle.deriveKey( { name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array( 0 ), info: enc.encode( 'cmp-e2e-v1|' + uid ) }, base, { name: 'AES-GCM', length: 256 }, false, [ 'encrypt', 'decrypt' ] );
		} );
	}

	/* Photos */
	function reencode( file ) {
		var load = window.createImageBitmap ? createImageBitmap( file, { imageOrientation: 'from-image' } ) : new Promise( function ( res, rej ) {
			var img = new Image();
			img.onload = function () {
				res( img );
			};
			img.onerror = rej;
			img.src = URL.createObjectURL( file );
		} );
		return load.then( function ( img ) {
			var w = img.width, h = img.height, s = Math.min( 1, 1600 / Math.max( w, h ) );
			var c = document.createElement( 'canvas' );
			c.width = Math.max( 1, Math.round( w * s ) );
			c.height = Math.max( 1, Math.round( h * s ) );
			c.getContext( '2d' ).drawImage( img, 0, 0, c.width, c.height );
			return new Promise( function ( res, rej ) {
				c.toBlob( function ( b ) {
					return b ? res( b ) : rej( new Error( 'encode' ) );
				}, 'image/jpeg', 0.82 );
			} );
		} ).then( function ( b ) {
			return b.arrayBuffer();
		} );
	}
	function encryptPhoto( jpeg, recipients ) {
		var ckRaw = rnd( 32 ), iv = rnd( 12 ), head = { v: 1, iv: b64( iv ), r: [] };
		return subtle.importKey( 'raw', ckRaw, 'AES-GCM', false, [ 'encrypt' ] ).then( function ( ck ) {
			return subtle.encrypt( { name: 'AES-GCM', iv: iv, additionalData: AAD }, ck, jpeg );
		} ).then( function ( ct ) {
			return recipients.reduce( function ( p, r ) {
				return p.then( function () {
					var eph, epk;
					return subtle.generateKey( { name: 'ECDH', namedCurve: 'P-256' }, true, [ 'deriveBits' ] ).then( function ( k ) {
						eph = k;
						return subtle.exportKey( 'raw', k.publicKey );
					} ).then( function ( raw ) {
						epk = raw;
						return importPub( unb64( r.p ) );
					} ).then( function ( pub ) {
						return subtle.deriveBits( { name: 'ECDH', public: pub }, eph.privateKey, 256 );
					} ).then( function ( bits ) {
						return kek( bits, r.u );
					} ).then( function ( k ) {
						var wi = rnd( 12 );
						return subtle.encrypt( { name: 'AES-GCM', iv: wi }, k, ckRaw ).then( function ( w ) {
							head.r.push( { u: r.u, e: b64( epk ), i: b64( wi ), w: b64( w ) } );
						} );
					} );
				} );
			}, Promise.resolve() ).then( function () {
				var h = enc.encode( JSON.stringify( head ) ), out = new Uint8Array( 7 + 4 + h.length + ct.byteLength );
				out.set( enc.encode( MAGIC ), 0 );
				new DataView( out.buffer ).setUint32( 7, h.length );
				out.set( h, 11 );
				out.set( new Uint8Array( ct ), 11 + h.length );
				ckRaw.fill( 0 );
				return out;
			} );
		} );
	}
	function parse( buf ) {
		var b = new Uint8Array( buf );
		if ( new TextDecoder().decode( b.subarray( 0, 7 ) ) !== MAGIC ) {
			throw new Error( 'format' );
		}
		var n = new DataView( b.buffer, b.byteOffset ).getUint32( 7 );
		return { head: JSON.parse( new TextDecoder().decode( b.subarray( 11, 11 + n ) ) ), ct: b.subarray( 11 + n ) };
	}
	/* The photo's own key, unwrapped with my private key; null when it wasn't encrypted for me. */
	function contentKey( env, priv ) {
		var mine = env.head.r.filter( function ( r ) {
			return String( r.u ) === String( cfg.uid );
		} )[ 0 ];
		if ( ! mine ) {
			return Promise.resolve( null );
		}
		return importPub( unb64( mine.e ) ).then( function ( epk ) {
			return subtle.deriveBits( { name: 'ECDH', public: epk }, priv, 256 );
		} ).then( function ( bits ) {
			return kek( bits, mine.u );
		} ).then( function ( k ) {
			return subtle.decrypt( { name: 'AES-GCM', iv: unb64( mine.i ) }, k, unb64( mine.w ) );
		} );
	}
	function decryptWith( env, ckRaw ) {
		return subtle.importKey( 'raw', ckRaw, 'AES-GCM', false, [ 'decrypt' ] ).then( function ( ck ) {
			return subtle.decrypt( { name: 'AES-GCM', iv: unb64( env.head.iv ), additionalData: AAD }, ck, env.ct );
		} );
	}
	var cache = {};
	function fetchEnv( url ) {
		if ( ! cache[ url ] ) {
			cache[ url ] = fetch( url, { credentials: 'same-origin' } ).then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'fetch' );
				}
				return r.arrayBuffer();
			} ).then( parse );
		}
		return cache[ url ];
	}

	/* Showing photos, download links and share links once unlocked. */
	function reveal( priv ) {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-cmp-e2e-src]' ), function ( el ) {
			var url = el.getAttribute( 'data-cmp-e2e-src' ), note = el.parentNode.querySelector( '[data-cmp-e2e-note]' );
			fetchEnv( url ).then( function ( env ) {
				return contentKey( env, priv ).then( function ( ck ) {
					if ( ! ck ) {
						say( note, t.notForYou );
						return null;
					}
					return decryptWith( env, ck );
				} );
			} ).then( function ( jpeg ) {
				if ( jpeg ) {
					el.src = URL.createObjectURL( new Blob( [ jpeg ], { type: 'image/jpeg' } ) );
					el.hidden = false;
					el.setAttribute( 'data-cmp-e2e-shown', '1' );
					say( note, '' );
				}
			} ).catch( function () {
				say( note, t.cantOpen, true );
			} );
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-cmp-e2e-dl]' ), function ( a ) {
			a.hidden = false;
			a.addEventListener( 'click', function ( ev ) {
				if ( a.getAttribute( 'data-ready' ) ) {
					return;
				}
				ev.preventDefault();
				fetchEnv( a.getAttribute( 'data-cmp-e2e-dl' ) ).then( function ( env ) {
					return contentKey( env, priv ).then( function ( ck ) {
						return decryptWith( env, ck );
					} );
				} ).then( function ( jpeg ) {
					a.href = URL.createObjectURL( new Blob( [ jpeg ], { type: 'image/jpeg' } ) );
					a.setAttribute( 'data-ready', '1' );
					a.click();
				} );
			} );
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-cmp-e2e-share]' ), function ( input ) {
			fetchEnv( input.getAttribute( 'data-cmp-e2e-share' ) ).then( function ( env ) {
				return contentKey( env, priv );
			} ).then( function ( ck ) {
				if ( ck ) {
					input.value = input.getAttribute( 'data-base' ) + '#k=' + b64url( ck );
					input.disabled = false;
					var btn = document.querySelector( '[data-cmp-copy="#' + input.id + '"]' );
					if ( btn ) {
						btn.disabled = false;
					}
				}
			} );
		} );
		root.classList.add( 'is-unlocked' );
		say( root.querySelector( '[data-cmp-e2e-status]' ), t.unlocked );
	}

	/* Forms */
	function field( form, name ) {
		return form.querySelector( '[data-cmp-e2e-field="' + name + '"]' );
	}
	function busy( form, on ) {
		Array.prototype.forEach.call( form.querySelectorAll( 'button' ), function ( b ) {
			b.disabled = on;
		} );
	}

	// Set up: make the key pair, box it, send the public key and the box.
	var setup = root.querySelector( '[data-cmp-e2e-setup]' );
	if ( setup ) {
		setup.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			var p1 = field( setup, 'pass' ).value, p2 = field( setup, 'pass2' ).value, msg = setup.querySelector( '[data-cmp-e2e-msg]' );
			if ( p1.length < 10 ) {
				return say( msg, t.tooShort, true );
			}
			if ( p1 !== p2 ) {
				return say( msg, t.noMatch, true );
			}
			busy( setup, true );
			say( msg, t.working );
			var pair, pub, pk8;
			subtle.generateKey( { name: 'ECDH', namedCurve: 'P-256' }, true, [ 'deriveBits' ] ).then( function ( k ) {
				pair = k;
				return subtle.exportKey( 'raw', k.publicKey );
			} ).then( function ( raw ) {
				pub = raw;
				return subtle.exportKey( 'pkcs8', pair.privateKey );
			} ).then( function ( p ) {
				pk8 = p;
				return sealBox( pk8, p1 );
			} ).then( function ( box ) {
				setup.querySelector( 'input[name="pub"]' ).value = b64( pub );
				setup.querySelector( 'input[name="box"]' ).value = box;
				return Promise.all( [ importPriv( pk8 ), keyId( pub ) ] );
			} ).then( function ( r ) {
				slot = cfg.uid + ':' + r[ 1 ];
				return saveKey( r[ 0 ] );
			} ).then( function () {
				field( setup, 'pass' ).value = field( setup, 'pass2' ).value = '';
				HTMLFormElement.prototype.submit.call( setup );
			} ).catch( function () {
				busy( setup, false );
				say( msg, t.failed, true );
			} );
		} );
	}

	// Unlock on this device.
	var unlock = root.querySelector( '[data-cmp-e2e-unlock]' );
	if ( unlock ) {
		unlock.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			var msg = unlock.querySelector( '[data-cmp-e2e-msg]' );
			busy( unlock, true );
			say( msg, t.working );
			openBox( cfg.box, field( unlock, 'pass' ).value ).then( importPriv ).then( function ( k ) {
				return saveKey( k ).then( function () {
					field( unlock, 'pass' ).value = '';
					say( msg, '' );
					busy( unlock, false );
					reveal( k );
				} );
			} ).catch( function () {
				busy( unlock, false );
				say( msg, t.wrongPass, true );
			} );
		} );
	}

	// Change the passphrase: re-box the same key.
	var rewrap = root.querySelector( '[data-cmp-e2e-rewrap]' );
	if ( rewrap ) {
		rewrap.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			var msg = rewrap.querySelector( '[data-cmp-e2e-msg]' ), p1 = field( rewrap, 'new' ).value;
			if ( p1.length < 10 ) {
				return say( msg, t.tooShort, true );
			}
			if ( p1 !== field( rewrap, 'new2' ).value ) {
				return say( msg, t.noMatch, true );
			}
			busy( rewrap, true );
			say( msg, t.working );
			openBox( cfg.box, field( rewrap, 'old' ).value ).then( function ( pk8 ) {
				return sealBox( pk8, p1 );
			} ).then( function ( box ) {
				rewrap.querySelector( 'input[name="box"]' ).value = box;
				HTMLFormElement.prototype.submit.call( rewrap );
			} ).catch( function () {
				busy( rewrap, false );
				say( msg, t.wrongPass, true );
			} );
		} );
	}

	var forget = root.querySelector( '[data-cmp-e2e-forget]' );
	if ( forget ) {
		forget.addEventListener( 'click', function () {
			forgetKeys().then( function () {
				window.location.reload();
			} );
		} );
	}

	// Uploads: encrypt in the browser, then send the envelope in place of the photo.
	Array.prototype.forEach.call( document.querySelectorAll( 'form[data-cmp-e2e-upload]' ), function ( form ) {
		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			ev.stopImmediatePropagation();
			var input = form.querySelector( 'input[type="file"]' ), msg = form.querySelector( '[data-cmp-e2e-msg]' );
			var file = input && input.files && input.files[ 0 ];
			if ( ! file ) {
				return say( msg, t.choose, true );
			}
			busy( form, true );
			say( msg, t.encrypting );
			reencode( file ).then( function ( jpeg ) {
				return encryptPhoto( jpeg, JSON.parse( form.getAttribute( 'data-cmp-e2e-upload' ) ) );
			} ).then( function ( envBytes ) {
				var fd = new FormData( form );
				fd.set( 'photo', new Blob( [ envBytes ], { type: 'application/octet-stream' } ), 'photo.cmpe2e' );
				fd.set( 'e2e', '1' );
				return fetch( form.getAttribute( 'action' ), { method: 'POST', body: fd, credentials: 'same-origin' } );
			} ).then( function ( r ) {
				window.location.href = r.url;
			} ).catch( function () {
				busy( form, false );
				say( msg, t.failed, true );
			} );
		}, true );
	} );

	savedKey().then( function ( k ) {
		if ( k ) {
			reveal( k );
		} else if ( cfg.kid ) {
			say( root.querySelector( '[data-cmp-e2e-status]' ), t.locked );
		}
	} ).catch( function () {} );
}() );
