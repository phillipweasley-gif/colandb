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

	// While one of our forms is submitting, other scripts on the page (for
	// example the page builder's or push service's "Leave site?" warnings)
	// must not stop it (0.15.1). A capturing listener on window runs before
	// theirs and stops them; it only acts during our own submit.
	var submitting = false;
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( submitting ) {
			e.stopImmediatePropagation();
		}
	}, true );

	// Coming back with the Back button shows the page as it was left: unlock it.
	window.addEventListener( 'pageshow', function () {
		submitting = false;
		Array.prototype.forEach.call( document.querySelectorAll( '.cmp-member-area form[data-cmp-sent]' ), function ( f ) {
			f.removeAttribute( 'data-cmp-sent' );
			Array.prototype.forEach.call( f.querySelectorAll( '.is-busy' ), function ( b ) {
				b.classList.remove( 'is-busy' );
				b.removeAttribute( 'aria-disabled' );
			} );
		} );
	} );

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
			submitting = true;
			// If the page didn't actually leave (a dialog was cancelled, or the
			// network dropped), unlock the form so pressing it again works.
			window.setTimeout( function () {
				submitting = false;
				form.removeAttribute( 'data-cmp-sent' );
				if ( button ) {
					button.removeAttribute( 'aria-disabled' );
					button.classList.remove( 'is-busy' );
				}
			}, 6000 );
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

	// Every photo panel on the page, so one Save uploads all chosen photos
	// (otherwise the reload after the first would drop the others).
	var photoPanels = [];
	each( '.cmp-member-area [data-cmp-photo]', function ( panel ) {
		var form = panel.querySelector( 'form' );
		var input = form.querySelector( 'input[type="file"]' );
		var error = form.querySelector( '[data-cmp-photo-error]' );
		var cropper = form.querySelector( '[data-cmp-cropper]' );
		var frame = form.querySelector( '.cmp-crop-frame' );
		var img = form.querySelector( '[data-cmp-crop-img]' );
		var zoom = form.querySelector( '[data-cmp-zoom]' );
		var progress = form.querySelector( '[data-cmp-progress]' );
		var outW = parseInt( panel.getAttribute( 'data-width' ), 10 );
		var outH = parseInt( panel.getAttribute( 'data-height' ), 10 );
		var minW = parseInt( panel.getAttribute( 'data-min' ), 10 );
		var state = null; // { w, h, scale (at zoom 1), zoom, x, y } in frame pixels

		var say = function ( message ) {
			error.textContent = message || '';
			error.hidden = ! message;
		};

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

		// Uploads this panel's chosen photo; done( ok, redirect ).
		var upload = function ( done ) {
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
					panel.scrollIntoView( { block: 'center' } );
					done( false );
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
						input.value = ''; // Saved: don't send it again if another photo fails.
						done( true, res.redirect );
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
		};
		var me = {
			form: form,
			pending: function () {
				return !! ( input.files && input.files.length );
			},
			upload: upload
		};
		photoPanels.push( me );

		form.addEventListener( 'submit', function ( e ) {
			var submitter = e.submitter;
			if ( submitter && 'remove' === submitter.name ) {
				return; // A normal post; the shared handler asks to confirm.
			}
			var queue = photoPanels.filter( function ( p ) {
				return p.pending();
			} );
			if ( ! queue.length || ! window.FormData || ! window.XMLHttpRequest ) {
				return; // Nothing to upload, or an old browser: post normally.
			}
			e.preventDefault();
			e.stopImmediatePropagation();
			// This panel first, then any other photo that's waiting.
			var mine = me.pending();
			queue.sort( function ( a, b ) {
				return ( a === me ? -1 : 0 ) - ( b === me ? -1 : 0 );
			} );
			var last = '';
			var next = function () {
				var p = queue.shift();
				if ( p ) {
					p.upload( function ( ok, redirect ) {
						if ( ok ) {
							last = redirect;
							next();
						}
						// On a failure, stay: that panel shows why; photos already saved stay saved.
					} );
				} else if ( mine ) {
					window.location.assign( last );
				} else {
					// This panel had no new photo: still post its own changes (e.g. the Show switch).
					HTMLFormElement.prototype.submit.call( form );
				}
			};
			next();
		} );
	} );
}() );

/* Propose a dynamic (0.16.0): pick any number of types; each type with
   sides asks for your side once it's ticked. Chastity and homework are
   add-ons with a "who leads" choice; Keyholder / chastity wearer turns
   chastity on with the Keyholder holding the key. Without JavaScript every
   side question shows and the server checks the same rules. */
( function () {
	'use strict';
	var form = document.querySelector( '[data-cmp-dyn-form]' );
	if ( ! form ) {
		return;
	}
	var boxes = form.querySelectorAll( '[data-cmp-dyn-t]' );
	var error = form.querySelector( '[data-cmp-dyn-error]' );
	var chastity = form.querySelector( '#cmp_dyn_a_chastity' );
	var note = form.querySelector( '[data-cmp-dyn-kh-note]' );
	var keyholder = form.querySelector( '#cmp_dyn_t_keyholder' );
	var sideOf = function ( key ) {
		var picked = form.querySelector( 'input[name="side[' + key + ']"]:checked' );
		return picked ? picked.value : '';
	};
	var sync = function () {
		Array.prototype.forEach.call( boxes, function ( box ) {
			var key = box.getAttribute( 'data-cmp-dyn-t' );
			var side = form.querySelector( '[data-cmp-dyn-side="' + key + '"]' );
			if ( side ) {
				side.hidden = ! box.checked;
				Array.prototype.forEach.call( side.querySelectorAll( 'input' ), function ( r ) {
					r.required = box.checked;
				} );
			}
		} );
		var kh = keyholder && keyholder.checked;
		if ( chastity ) {
			if ( kh ) {
				chastity.checked = true;
				var lead = 'b' === sideOf( 'keyholder' ) ? 'them' : 'me';
				var pick = form.querySelector( '#cmp_dyn_l_chastity_' + lead );
				if ( pick && sideOf( 'keyholder' ) ) {
					pick.checked = true;
				}
			}
			chastity.setAttribute( 'aria-disabled', kh ? 'true' : 'false' );
			if ( note ) {
				note.hidden = ! kh;
			}
		}
		Array.prototype.forEach.call( form.querySelectorAll( '[data-cmp-dyn-addon]' ), function ( row ) {
			var on = row.querySelector( 'input[type="checkbox"]' ).checked;
			var leads = row.querySelector( '.cmp-dyn-addon-lead' );
			leads.hidden = ! on;
			leads.classList.toggle( 'is-locked', 'chastity' === row.getAttribute( 'data-cmp-dyn-addon' ) && !! kh );
		} );
	};
	form.addEventListener( 'change', function ( e ) {
		if ( error && e.target.matches( '[data-cmp-dyn-t]' ) ) {
			error.hidden = true;
		}
		sync();
	} );
	// With Keyholder ticked, chastity stays on and follows the Keyholder side.
	form.addEventListener( 'click', function ( e ) {
		if ( keyholder && keyholder.checked && ( e.target === chastity || ( e.target.closest && e.target.closest( '.cmp-dyn-addon-lead.is-locked' ) ) ) ) {
			e.preventDefault();
		}
	} );
	form.addEventListener( 'submit', function ( e ) {
		var any = Array.prototype.some.call( boxes, function ( b ) {
			return b.checked;
		} );
		if ( ! any && error ) {
			e.preventDefault();
			e.stopImmediatePropagation();
			error.hidden = false;
			error.scrollIntoView( { block: 'center' } );
		}
	}, true );
	sync();
}() );

/* My calendar (0.17.0): changing who can see an event saves straight away;
   the Save button stays for when JavaScript is off. */
( function () {
	'use strict';
	Array.prototype.forEach.call( document.querySelectorAll( '[data-cmp-autosave]' ), function ( select ) {
		var btn = select.form && select.form.querySelector( '[data-cmp-autosave-btn]' );
		if ( btn ) {
			btn.hidden = true;
		}
		select.addEventListener( 'change', function () {
			if ( select.form.requestSubmit ) {
				select.form.requestSubmit();
			} else {
				select.form.submit();
			}
		} );
	} );
}() );

/* Copy buttons (0.18.0): copy a read-only field's text; without the
   Clipboard API, select it so the member can copy it themselves. */
( function () {
	'use strict';
	Array.prototype.forEach.call( document.querySelectorAll( '[data-cmp-copy]' ), function ( btn ) {
		btn.addEventListener( 'click', function () {
			var field = document.querySelector( btn.getAttribute( 'data-cmp-copy' ) );
			if ( ! field ) {
				return;
			}
			var label = btn.getAttribute( 'data-cmp-label' ) || btn.textContent;
			btn.setAttribute( 'data-cmp-label', label );
			var say = function ( text ) {
				btn.textContent = text || label;
				window.setTimeout( function () {
					btn.textContent = label;
				}, 2500 );
			};
			var done = function () {
				say( btn.getAttribute( 'data-cmp-copied' ) );
			};
			// Copying blocked: leave the link selected and say so.
			var fallback = function () {
				field.focus();
				field.select();
				var ok = false;
				try {
					ok = document.execCommand( 'copy' );
				} catch ( e ) {}
				say( ok ? btn.getAttribute( 'data-cmp-copied' ) : btn.getAttribute( 'data-cmp-select' ) );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( field.value ).then( done, fallback );
			} else {
				fallback();
			}
		} );
	} );
}() );

/* Profile tab (0.4.0): a field's "Show to members" switch only appears once
   the field has something in it, already switched on. Without JavaScript the
   hidden switch still posts "on", so the server reaches the same result. */
( function () {
	'use strict';
	Array.prototype.forEach.call( document.querySelectorAll( '.cmp-profile-row' ), function ( row ) {
		var sw = row.querySelector( '[data-cmp-show]' );
		if ( ! sw || ! sw.hasAttribute( 'hidden' ) ) {
			return; // Already filled when the page loaded: always shown.
		}
		var box = sw.querySelector( 'input[type="checkbox"]' );
		var filled = function () {
			return Array.prototype.some.call( row.querySelectorAll( '.cmp-profile-input input, .cmp-profile-input textarea, .cmp-profile-input select' ), function ( el ) {
				if ( 'checkbox' === el.type || 'radio' === el.type ) {
					return el.checked && '' !== el.value; // An empty radio means "none" (kink picker).
				}
				return '' !== String( el.value ).trim();
			} );
		};
		var sync = function () {
			var was = ! sw.hasAttribute( 'hidden' );
			var now = filled();
			if ( now && ! was && box ) {
				// A newly filled field starts shown, except health details,
				// which start hidden (data-cmp-sensitive).
				box.checked = ! sw.hasAttribute( 'data-cmp-sensitive' );
			}
			sw.toggleAttribute( 'hidden', ! now );
		};
		row.addEventListener( 'input', sync );
		row.addEventListener( 'change', sync );
	} );
}() );

/* Chastity (0.8.0): keep the "Locked for" timer current without a reload. */
( function () {
	'use strict';
	var timers = document.querySelectorAll( '.cmp-member-area [data-cmp-since]' );
	if ( ! timers.length ) {
		return;
	}
	function tick() {
		var now = Math.floor( Date.now() / 1000 );
		Array.prototype.forEach.call( timers, function ( el ) {
			var s = Math.max( 0, now - parseInt( el.getAttribute( 'data-cmp-since' ), 10 ) );
			var d = Math.floor( s / 86400 ), h = Math.floor( ( s % 86400 ) / 3600 ), m = Math.floor( ( s % 3600 ) / 60 );
			el.textContent = ( d ? d + 'd ' : '' ) + h + 'h ' + m + 'm';
		} );
	}
	window.setInterval( tick, 30000 );
}() );

/* Kink picker (0.10.0): only the member's picks are listed; others are found
   by search or category. Without this script, "Browse all kinks" lists every
   kink by category and the same radios save the same way. */
( function () {
	'use strict';
	Array.prototype.forEach.call( document.querySelectorAll( '.cmp-member-area [data-cmp-kp]' ), function ( kp ) {
		var mine = kp.querySelector( '[data-cmp-kp-mine]' );
		var browse = kp.querySelector( '[data-cmp-kp-browse]' );
		var find = kp.querySelector( '[data-cmp-kp-find]' );
		var q = kp.querySelector( '[data-cmp-kp-q]' );
		var results = kp.querySelector( '[data-cmp-kp-results]' );
		var more = kp.querySelector( '[data-cmp-kp-more]' );
		var count = kp.querySelector( '[data-cmp-kp-count]' );
		var empty = kp.querySelector( '[data-cmp-kp-empty]' );
		var limit = parseInt( kp.getAttribute( 'data-limit' ), 10 ) || 40;
		var cat = '';
		var countTpl = count.textContent.replace( /^\d+/, '%1' ).replace( /\d+(?=\D*$)/, '%2' );

		browse.hidden = true;
		find.hidden = false;

		var rows = function () {
			return Array.prototype.slice.call( kp.querySelectorAll( '.cmp-kp-row' ) );
		};
		var picked = function () {
			return mine.querySelectorAll( '.cmp-kp-row' ).length;
		};
		var sync = function () {
			var n = picked();
			count.textContent = countTpl.replace( '%1', n ).replace( '%2', limit );
			empty.hidden = n > 0;
		};
		var render = function () {
			var term = q.value.trim().toLowerCase();
			var list = rows().filter( function ( r ) {
				return r.parentNode !== mine && ( ! cat || r.getAttribute( 'data-group' ) === cat ) && ( ! term || r.getAttribute( 'data-label' ).toLowerCase().indexOf( term ) > -1 );
			} );
			var total = list.length;
			var shown = list.slice( 0, term || cat ? 80 : 18 );
			var full = picked() >= limit;
			results.innerHTML = '';
			shown.forEach( function ( r ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'cmp-kp-chip';
				b.textContent = '+ ' + r.getAttribute( 'data-label' );
				b.disabled = full;
				b.addEventListener( 'click', function () {
					add( r );
				} );
				results.appendChild( b );
			} );
			if ( ! total ) {
				more.textContent = kp.getAttribute( 'data-label-none' );
			} else if ( full ) {
				more.textContent = kp.getAttribute( 'data-label-full' );
			} else {
				more.textContent = total > shown.length ? kp.getAttribute( 'data-label-more' ).replace( '%1$d', shown.length ).replace( '%2$d', total ) : '';
			}
		};
		var add = function ( r ) {
			if ( picked() >= limit ) {
				return;
			}
			mine.appendChild( r );
			r.querySelector( 'input[value="like"]' ).checked = true;
			// Lets the field's Show switch appear (Profile tab, 0.4.0).
			r.querySelector( 'input[value="like"]' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );
			sync();
			render();
			r.querySelector( 'input[value="like"]' ).focus();
		};
		var remove = function ( r ) {
			var group = browse.querySelector( '[data-cmp-kp-group="' + r.getAttribute( 'data-group' ) + '"] ul' ) || browse.querySelector( 'ul' );
			r.querySelector( '[data-cmp-kp-rm]' ).checked = true;
			r.querySelector( '.cmp-kp-none' ).checked = true;
			group.appendChild( r );
			mine.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			sync();
			render();
			q.focus();
		};

		kp.addEventListener( 'change', function ( e ) {
			if ( e.target.hasAttribute( 'data-cmp-kp-rm' ) && e.target.checked ) {
				remove( e.target.closest( '.cmp-kp-row' ) );
			}
		} );
		// Tapping the chosen Giving / Receiving / Both again clears it.
		kp.addEventListener( 'pointerdown', function ( e ) {
			var label = e.target.closest( '.cmp-kp-dir label' );
			if ( label ) {
				var input = label.querySelector( 'input' );
				input.setAttribute( 'data-was', input.checked ? '1' : '' );
			}
		} );
		kp.addEventListener( 'click', function ( e ) {
			var input = e.target.matches && e.target.matches( '[data-cmp-kp-dir]' ) ? e.target : null;
			if ( input && '1' === input.getAttribute( 'data-was' ) ) {
				input.closest( '.cmp-kp-dir' ).querySelector( '.cmp-kp-none' ).checked = true;
				input.setAttribute( 'data-was', '' );
			}
		} );
		kp.querySelector( '[data-cmp-kp-cats]' ).addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-cmp-kp-cat]' );
			if ( ! b ) {
				return;
			}
			cat = b.getAttribute( 'data-cmp-kp-cat' );
			Array.prototype.forEach.call( this.querySelectorAll( '[data-cmp-kp-cat]' ), function ( x ) {
				x.setAttribute( 'aria-pressed', x === b ? 'true' : 'false' );
			} );
			render();
		} );
		q.addEventListener( 'input', render );
		// Enter in the search box adds the first match instead of saving the form.
		q.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				var first = results.querySelector( '.cmp-kp-chip:not([disabled])' );
				if ( first ) {
					first.click();
					q.value = '';
					render();
				}
			}
		} );
		sync();
		render();
	} );
}() );

/* Messages (0.11.0): open a conversation at its newest message; Ctrl/⌘ + Enter sends. */
( function () {
	'use strict';
	var box = document.querySelector( '.cmp-member-area [data-cmp-msg-scroll]' );
	if ( box ) {
		box.scrollTop = box.scrollHeight;
	}
	Array.prototype.forEach.call( document.querySelectorAll( '.cmp-member-area [data-cmp-msg-body]' ), function ( t ) {
		t.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key && ( e.metaKey || e.ctrlKey ) && t.value.trim() ) {
				e.preventDefault();
				t.form.requestSubmit();
			}
		} );
	} );
}() );

/* Profile tabs (0.13.0): About · Kinks · Going to · Posts. Without this
   script every section shows, one after another. */
( function () {
	'use strict';
	Array.prototype.forEach.call( document.querySelectorAll( '.cmp-member-area [data-cmp-pv]' ), function ( card ) {
		var bar = card.querySelector( '[data-cmp-pv-tabs]' );
		var tabs = card.querySelectorAll( '[data-cmp-pv-tab]' );
		if ( ! bar || tabs.length < 2 ) {
			return;
		}
		bar.hidden = false;
		card.classList.add( 'has-tabs' );
		var show = function ( key, focus ) {
			Array.prototype.forEach.call( tabs, function ( t ) {
				var on = t.getAttribute( 'data-cmp-pv-tab' ) === key;
				t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				t.tabIndex = on ? 0 : -1;
				if ( on && focus ) {
					t.focus();
				}
			} );
			Array.prototype.forEach.call( card.querySelectorAll( '[data-cmp-pv-panel]' ), function ( p ) {
				p.hidden = p.getAttribute( 'data-cmp-pv-panel' ) !== key;
			} );
		};
		Array.prototype.forEach.call( tabs, function ( t, i ) {
			t.addEventListener( 'click', function () {
				show( t.getAttribute( 'data-cmp-pv-tab' ) );
			} );
			t.addEventListener( 'keydown', function ( e ) {
				var d = 'ArrowRight' === e.key ? 1 : ( 'ArrowLeft' === e.key ? -1 : 0 );
				if ( d ) {
					e.preventDefault();
					show( tabs[ ( i + d + tabs.length ) % tabs.length ].getAttribute( 'data-cmp-pv-tab' ), true );
				}
			} );
		} );
		show( tabs[0].getAttribute( 'data-cmp-pv-tab' ) );
	} );
}() );

/* Chastity, start a lock (0.19.2): the keyholder's-name field only shows
   (and is required) when "Someone without an account" is chosen. Without
   JavaScript it is always shown and only used for that choice. */
( function () {
	'use strict';
	var select = document.getElementById( 'cmp_cl_kh' );
	var row = document.querySelector( '[data-cmp-kh-name]' );
	if ( ! select || ! row ) {
		return;
	}
	var input = row.querySelector( 'input' );
	var sync = function () {
		var named = 'named' === select.value;
		row.hidden = ! named;
		input.required = named;
		if ( named && document.activeElement === select ) {
			input.focus();
		}
	};
	select.addEventListener( 'change', sync );
	sync();
}() );
