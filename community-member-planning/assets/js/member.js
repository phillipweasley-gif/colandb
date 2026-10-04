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
			if ( form.closest( '[data-cmp-photo]' ) ) {
				return; // Photo forms handle their own sending (and retries).
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

/* Profile tab: character counters, choice limits, age mode, and photos
   (crop in the browser, then upload with progress). Without JavaScript
   the same forms post normally and the server crops from the centre. */
( function () {
	'use strict';

	var each = function ( selector, fn, root ) {
		Array.prototype.forEach.call( ( root || document ).querySelectorAll( selector ), fn );
	};
	var t = ( window.CMP && window.CMP.i18n ) || {};
	var text = function ( key, fallback ) {
		return t[ key ] || fallback;
	};

	// "123 of 500 characters"
	each( '.cmp-member-area textarea[data-cmp-count]', function ( area ) {
		var counter = area.parentNode.querySelector( '[data-cmp-counter]' );
		var max = parseInt( area.getAttribute( 'data-cmp-count' ), 10 );
		var update = function () {
			if ( counter ) {
				counter.textContent = area.value.length + ' / ' + max;
			}
		};
		area.addEventListener( 'input', update );
		update();
	} );

	// At most N choices: further boxes are disabled once the limit is reached.
	each( '.cmp-member-area [data-cmp-limit]', function ( group ) {
		var limit = parseInt( group.getAttribute( 'data-cmp-limit' ), 10 );
		var update = function () {
			var boxes = group.querySelectorAll( 'input[type="checkbox"]' );
			var on = group.querySelectorAll( 'input[type="checkbox"]:checked' ).length;
			Array.prototype.forEach.call( boxes, function ( b ) {
				b.disabled = ! b.checked && on >= limit;
			} );
		};
		group.addEventListener( 'change', update );
		update();
	} );

	// Typing an age or picking a range selects that option.
	each( '.cmp-member-area [data-cmp-age]', function ( age ) {
		var pick = function ( value ) {
			var radio = age.querySelector( 'input[type="radio"][value="' + value + '"]' );
			if ( radio ) {
				radio.checked = true;
			}
		};
		var num = age.querySelector( '.cmp-age-number' );
		var band = age.querySelector( 'select' );
		if ( num ) {
			num.addEventListener( 'input', function () { pick( 'exact' ); } );
		}
		if ( band ) {
			band.addEventListener( 'change', function () { pick( 'band' ); } );
		}
	} );

	each( '.cmp-member-area [data-cmp-photo]', function ( panel ) {
		var form = panel.querySelector( 'form' );
		var input = form.querySelector( 'input[type="file"]' );
		var error = form.querySelector( '[data-cmp-photo-error]' );
		var cropper = form.querySelector( '[data-cmp-cropper]' );
		var frame = form.querySelector( '.cmp-crop-frame' );
		var img = form.querySelector( '[data-cmp-crop-img]' );
		var zoom = form.querySelector( '[data-cmp-zoom]' );
		var progress = form.querySelector( '[data-cmp-progress]' );
		var decorative = form.querySelector( '[data-cmp-decorative]' );
		var altRow = form.querySelector( '[data-cmp-alt-row]' );
		var outW = parseInt( panel.getAttribute( 'data-width' ), 10 );
		var outH = parseInt( panel.getAttribute( 'data-height' ), 10 );
		var minW = parseInt( panel.getAttribute( 'data-min' ), 10 );
		var state = null; // { w, h, scale (at zoom 1), zoom, x, y } in frame pixels

		var say = function ( message ) {
			error.textContent = message || '';
			error.hidden = ! message;
		};
		var syncAlt = function () {
			altRow.hidden = decorative.checked;
		};
		decorative.addEventListener( 'change', syncAlt );
		syncAlt();

		var clamp = function () {
			var fw = frame.clientWidth, fh = frame.clientHeight;
			var s = state.scale * state.zoom;
			state.x = Math.min( 0, Math.max( fw - state.w * s, state.x ) );
			state.y = Math.min( 0, Math.max( fh - state.h * s, state.y ) );
		};
		var draw = function () {
			clamp();
			var s = state.scale * state.zoom;
			img.style.width = ( state.w * s ) + 'px';
			img.style.height = ( state.h * s ) + 'px';
			img.style.transform = 'translate(' + state.x + 'px,' + state.y + 'px)';
		};
		var setZoom = function ( z ) {
			var fw = frame.clientWidth, fh = frame.clientHeight;
			var old = state.scale * state.zoom;
			// Keep the centre of the frame where it is.
			var cx = ( fw / 2 - state.x ) / old, cy = ( fh / 2 - state.y ) / old;
			state.zoom = z;
			var s = state.scale * z;
			state.x = fw / 2 - cx * s;
			state.y = fh / 2 - cy * s;
			draw();
		};

		input.addEventListener( 'change', function () {
			say( '' );
			state = null;
			cropper.hidden = true;
			var file = input.files && input.files[ 0 ];
			if ( ! file ) {
				return;
			}
			var okType = /^image\/(jpeg|png|webp|heic|heif)$/.test( file.type ) || /\.(heic|heif)$/i.test( file.name );
			if ( ! okType ) {
				say( text( 'photoType', "That file isn't a JPEG, PNG, WebP or HEIC photo." ) );
				input.value = '';
				return;
			}
			if ( file.size > 5242880 ) {
				say( text( 'photoBig', 'That photo is larger than 5 MB. Please choose a smaller one.' ) );
				input.value = '';
				return;
			}
			var url = URL.createObjectURL( file );
			var probe = new Image();
			probe.onload = function () {
				if ( probe.naturalWidth < minW || probe.naturalHeight < Math.round( minW * outH / outW ) ) {
					say( text( 'photoSmall', 'That photo is too small.' ) + ' ' + minW + ' × ' + Math.round( minW * outH / outW ) + ' px' );
					input.value = '';
					return;
				}
				img.src = url;
				cropper.hidden = false;
				var fw = frame.clientWidth, fh = frame.clientHeight;
				state = { w: probe.naturalWidth, h: probe.naturalHeight, zoom: 1, x: 0, y: 0 };
				state.scale = Math.max( fw / state.w, fh / state.h );
				state.x = ( fw - state.w * state.scale ) / 2;
				state.y = ( fh - state.h * state.scale ) / 2;
				zoom.value = 1;
				draw();
			};
			probe.onerror = function () {
				// Most browsers other than Safari can't open HEIC; the server may.
				if ( /heic|heif/i.test( file.type + file.name ) ) {
					say( text( 'photoHeic', "This browser can't show HEIC photos, so it will be uploaded as it is and converted by the site if it can. If that fails, open this page in Safari or save the photo as a JPEG." ) );
				} else {
					say( text( 'photoRead', "That photo couldn't be opened. It may be damaged; please try another." ) );
					input.value = '';
				}
			};
			probe.src = url;
		} );

		zoom.addEventListener( 'input', function () {
			if ( state ) {
				setZoom( parseFloat( zoom.value ) );
			}
		} );
		var drag = null;
		frame.addEventListener( 'pointerdown', function ( e ) {
			if ( ! state ) {
				return;
			}
			drag = { x: e.clientX - state.x, y: e.clientY - state.y };
			frame.setPointerCapture( e.pointerId );
			e.preventDefault();
		} );
		frame.addEventListener( 'pointermove', function ( e ) {
			if ( drag ) {
				state.x = e.clientX - drag.x;
				state.y = e.clientY - drag.y;
				draw();
			}
		} );
		var stop = function () { drag = null; };
		frame.addEventListener( 'pointerup', stop );
		frame.addEventListener( 'pointercancel', stop );
		frame.addEventListener( 'keydown', function ( e ) {
			if ( ! state ) {
				return;
			}
			var step = e.shiftKey ? 40 : 10;
			var moves = { ArrowLeft: [ step, 0 ], ArrowRight: [ -step, 0 ], ArrowUp: [ 0, step ], ArrowDown: [ 0, -step ] };
			if ( moves[ e.key ] ) {
				state.x += moves[ e.key ][ 0 ];
				state.y += moves[ e.key ][ 1 ];
				draw();
				e.preventDefault();
			} else if ( '+' === e.key || '=' === e.key || '-' === e.key ) {
				var z = Math.min( 4, Math.max( 1, state.zoom + ( '-' === e.key ? -0.1 : 0.1 ) ) );
				zoom.value = z;
				setZoom( z );
				e.preventDefault();
			}
		} );
		window.addEventListener( 'resize', function () {
			if ( state ) {
				var fw = frame.clientWidth, fh = frame.clientHeight;
				var old = state.scale;
				state.scale = Math.max( fw / state.w, fh / state.h );
				state.x *= state.scale / old;
				state.y *= state.scale / old;
				draw();
			}
		} );

		// The chosen area, drawn at the stored size on a white background.
		var cropped = function ( done ) {
			var s = state.scale * state.zoom;
			var sx = -state.x / s, sy = -state.y / s;
			var sw = frame.clientWidth / s, sh = frame.clientHeight / s;
			var canvas = document.createElement( 'canvas' );
			var w = Math.min( outW, Math.round( sw ) ), h = Math.round( w * outH / outW );
			canvas.width = w;
			canvas.height = h;
			var ctx = canvas.getContext( '2d' );
			ctx.fillStyle = '#ffffff';
			ctx.fillRect( 0, 0, w, h );
			ctx.drawImage( img, sx, sy, sw, sh, 0, 0, w, h );
			canvas.toBlob( done, 'image/jpeg', 0.9 );
		};

		form.addEventListener( 'submit', function ( e ) {
			var submitter = e.submitter;
			if ( submitter && 'remove' === submitter.name ) {
				return; // A normal post; the shared handler asks to confirm.
			}
			var alt = form.querySelector( 'input[name="alt"]' );
			var hasPhoto = !! panel.querySelector( '.cmp-photo-current img' ) || ( input.files && input.files.length );
			if ( hasPhoto && ! decorative.checked && ! alt.value.trim() ) {
				e.preventDefault();
				e.stopImmediatePropagation();
				say( text( 'photoAlt', 'Describe the photo for people who can\'t see it, or tick "Decorative image".' ) );
				alt.focus();
				return;
			}
			if ( ! ( input.files && input.files.length ) || ! window.FormData || ! window.XMLHttpRequest ) {
				return; // Nothing to upload, or an old browser: post normally.
			}
			e.preventDefault();
			e.stopImmediatePropagation();
			var send = function ( blob ) {
				var data = new FormData( form );
				if ( blob ) {
					data.set( 'photo', blob, 'photo.jpg' );
				}
				data.set( 'cmp_ajax', '1' );
				var xhr = new XMLHttpRequest();
				var bar = progress.querySelector( 'progress' );
				var label = progress.querySelector( '[data-cmp-progress-text]' );
				progress.hidden = false;
				form.querySelector( '[data-cmp-photo-save]' ).disabled = true;
				xhr.upload.onprogress = function ( ev ) {
					if ( ev.lengthComputable ) {
						bar.value = Math.round( ev.loaded / ev.total * 100 );
						label.textContent = bar.value + '%';
					}
				};
				var fail = function ( message ) {
					progress.hidden = true;
					form.querySelector( '[data-cmp-photo-save]' ).disabled = false;
					say( message );
				};
				xhr.onload = function () {
					var res = null;
					try {
						res = JSON.parse( xhr.responseText );
					} catch ( err ) {
						res = null;
					}
					if ( res && res.ok && res.redirect ) {
						label.textContent = text( 'photoDone', 'Saved. Reloading…' );
						window.location.assign( res.redirect );
					} else if ( 413 === xhr.status ) {
						fail( text( 'photoBig', 'That photo is larger than 5 MB. Please choose a smaller one.' ) );
					} else {
						fail( ( res && res.message ) || text( 'error', 'Something went wrong. Please try again.' ) );
					}
				};
				xhr.onerror = function () {
					fail( navigator.onLine === false ? text( 'offline', "You're offline. Reconnect and try again." ) : text( 'error', 'Something went wrong. Please try again.' ) );
				};
				xhr.open( 'POST', form.getAttribute( 'action' ) );
				xhr.send( data );
			};
			if ( state ) {
				cropped( send );
			} else {
				send( null ); // e.g. HEIC the browser couldn't open: the server converts it.
			}
		} );
	} );
}() );
