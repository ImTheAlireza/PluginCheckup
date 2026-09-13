/**
 * TisaCase Hub — رفتار صفحهٔ لانچر و تنظیمات.
 *
 * بدون jQuery، بدون وابستگی: جستجو، ناوبری کیبورد، سنجاق، تازه‌سازی شمارش‌ها،
 * رنگ برند و چگالی. هیچ‌کدام برای کار کردن لازم نیستند (HTML کامل رندر شده
 و فقط با این‌ها چابک‌تر می‌شود) — یعنی اگر JS خاموش باشد صفحه کامل کار می‌کند.
 */
( function () {
	'use strict';

	var cfg = window.TisaCaseHub || {};
	var body = document.body;

	function $( sel, root ) { return ( root || document ).querySelector( sel ); }
	function $$( sel, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) ); }

	function post( action, data ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) { fd.append( k, data[ k ] ); } );
		return fetch( cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd
		} ).then( function ( r ) { return r.json(); } );
	}

	function t( key ) {
		return ( cfg.i18n && cfg.i18n[ key ] ) ? cfg.i18n[ key ] : '';
	}

	function esc( s ) {
		return String( s ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}

	/* ---------------------------------------------------------------- 1) سنجاق‌ها */

	var pinnedGrid = $( '[data-tsh-grid="pinned"]' );
	var pinnedBox = $( '#tsh-pinned' );
	var pinnedN = $( '[data-pinned-n]' );

	function groupGridOf( card ) {
		var g = card.getAttribute( 'data-group' );
		var host = $( '[data-group="' + g + '"] [data-tsh-grid]' );
		if ( ! host ) {
			host = $( '[data-tsh-grid]:not([data-tsh-grid="pinned"])' );
		}
		return host;
	}

	function syncPinned() {
		if ( ! pinnedBox ) { return; }
		var n = pinnedGrid ? pinnedGrid.children.length : 0;
		pinnedBox.hidden = n === 0;
		if ( pinnedN ) { pinnedN.textContent = n ? String( n ) : ''; }
	}

	function movePinned( card, on ) {
		if ( ! card ) { return; }
		card.classList.toggle( 'is-pinned', on );
		var btn = $( '.tisa-pin', card );
		if ( btn ) { btn.setAttribute( 'aria-pressed', on ? 'true' : 'false' ); }
		if ( on && pinnedGrid ) {
			pinnedGrid.appendChild( card );
		} else if ( ! on ) {
			var host = groupGridOf( card );
			if ( host ) { host.appendChild( card ); }
		}
		syncPinned();
	}

	// کارت‌های سنجاق‌شده در HTML داخل گروه خودشان‌اند؛ اینجا بالا برده می‌شوند.
	$$( '.tisa-plugin-card.is-pinned' ).forEach( function ( c ) { movePinned( c, true ); } );

	$$( '.tisa-pin' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			var card = btn.closest( '.tisa-plugin-card' );
			var on = ! card.classList.contains( 'is-pinned' );
			// همهٔ کارت‌های هم‌کلید (اگر جایی دوبله رندر شده باشد) با هم عوض می‌شوند.
			var key = card.getAttribute( 'data-key' );
			$$( '.tisa-plugin-card[data-key="' + key + '"]' ).forEach( function ( c ) { movePinned( c, on ); } );
			if ( cfg.ajax ) { post( 'tsh_pin', { key: key, on: on ? 1 : 0 } ).catch( function () {} ); }
		} );
	} );

	syncPinned();

	/* ---------------------------------------------------------------- 2) جستجو و کیبورد */

	var input = $( '#tsh-q' );
	var counter = $( '#tsh-count' );
	var empty = $( '#tsh-empty' );
	var cards = $$( '.tisa-plugin-card' );
	var at = -1;

	function vis() {
		return cards.filter( function ( c ) { return ! c.hidden; } );
	}

	function highlight( el, q ) {
		if ( ! el ) { return; }
		var text = el.getAttribute( 'data-text' );
		if ( null === text ) {
			text = el.textContent;
			el.setAttribute( 'data-text', text );
		}
		if ( ! q ) {
			el.innerHTML = esc( text );
			return;
		}
		var i = text.toLowerCase().indexOf( q );
		if ( i < 0 ) {
			el.innerHTML = esc( text );
			return;
		}
		el.innerHTML = esc( text.slice( 0, i ) ) + '<mark>' + esc( text.slice( i, i + q.length ) ) + '</mark>' + esc( text.slice( i + q.length ) );
	}

	function focusCard( i, scroll ) {
		var list = vis();
		cards.forEach( function ( c ) { c.classList.remove( 'is-focused' ); } );
		if ( ! list.length || i < 0 ) { at = -1; return; }
		at = ( ( i % list.length ) + list.length ) % list.length;
		list[ at ].classList.add( 'is-focused' );
		if ( scroll ) {
			list[ at ].scrollIntoView( { block: 'nearest' } );
		}
	}

	function apply( scroll ) {
		var q = ( input.value || '' ).trim().toLowerCase();
		var n = 0;
		cards.forEach( function ( c ) {
			var hit = ! q || ( c.getAttribute( 'data-search' ) || '' ).indexOf( q ) > -1;
			c.hidden = ! hit;
			if ( hit ) { n++; }
			highlight( $( '.tisa-plugin-card__title', c ), hit ? q : '' );
			highlight( $( '.tisa-plugin-card__desc', c ), hit ? q : '' );
		} );
		$$( '.tisa-hub-group' ).forEach( function ( g ) {
			var any = $$( '.tisa-plugin-card', g ).some( function ( c ) { return ! c.hidden; } );
			g.hidden = ! any;
		} );
		if ( empty ) { empty.hidden = n !== 0; }
		if ( counter ) {
			counter.textContent = q
				? ( n + ' / ' + cards.length )
				: cards.length + ' ' + ( cfg.i18n && cfg.i18n.items ? cfg.i18n.items : '' );
		}
		focusCard( q ? 0 : -1, !! scroll );
	}

	if ( input ) {
		input.addEventListener( 'input', function () { apply( false ); } );
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) { input.value = ''; apply( false ); return; }
			if ( 'ArrowDown' === e.key ) { e.preventDefault(); focusCard( ( at < 0 ? -1 : at ) + 1, true ); }
			if ( 'ArrowUp' === e.key ) { e.preventDefault(); focusCard( ( at < 0 ? 0 : at ) - 1, true ); }
			if ( 'Enter' === e.key ) {
				var list = vis();
				var card = list[ at ] || list[ 0 ];
				var link = card && ( $( '.tisa-plugin-card__link', card ) || $( '.tisa-btn--primary[href]', card ) );
				if ( link && link.href ) { e.preventDefault(); window.open( link.href, '_blank', 'noopener' ); }
			}
		} );
		var clear = $( '#tsh-clear' );
		if ( clear ) { clear.addEventListener( 'click', function () { input.value = ''; apply( false ); input.focus(); } ); }
		document.addEventListener( 'keydown', function ( e ) {
			var t = e.target;
			var typing = t && ( 'INPUT' === t.tagName || 'TEXTAREA' === t.tagName || t.isContentEditable );
			if ( '/' === e.key && ! typing ) { e.preventDefault(); input.focus(); input.select(); }
		} );
		apply( false );
	}

	/* ---------------------------------------------------------------- 3) رنگ برند و چگالی */

	function mix( hex, other, pct ) {
		hex = hex.replace( '#', '' );
		if ( 3 === hex.length ) { hex = hex[ 0 ] + hex[ 0 ] + hex[ 1 ] + hex[ 1 ] + hex[ 2 ] + hex[ 2 ]; }
		var a = [ parseInt( hex.slice( 0, 2 ), 16 ), parseInt( hex.slice( 2, 4 ), 16 ), parseInt( hex.slice( 4, 6 ), 16 ) ];
		other = other.replace( '#', '' );
		var b = [ parseInt( other.slice( 0, 2 ), 16 ), parseInt( other.slice( 2, 4 ), 16 ), parseInt( other.slice( 4, 6 ), 16 ) ];
		return '#' + a.map( function ( v, i ) {
			var x = Math.round( v * ( 1 - pct ) + b[ i ] * pct );
			return ( '0' + Math.max( 0, Math.min( 255, x ) ).toString( 16 ) ).slice( -2 );
		} ).join( '' );
	}

	function previewAccent( hex ) {
		var st = $( '#tsh-live' );
		if ( ! st ) {
			st = document.createElement( 'style' );
			st.id = 'tsh-live';
			document.head.appendChild( st );
		}
		var rgba = function ( a ) {
			var h = hex.replace( '#', '' );
			return 'rgba(' + parseInt( h.slice( 0, 2 ), 16 ) + ',' + parseInt( h.slice( 2, 4 ), 16 ) + ',' + parseInt( h.slice( 4, 6 ), 16 ) + ',' + a + ')';
		};
		st.textContent = ':root{--tisa-primary:' + hex + ';'
			+ '--tisa-primary-ink:' + mix( hex, '#000000', 0.2 ) + ';'
			+ '--tisa-primary-deep:' + mix( hex, '#000000', 0.42 ) + ';'
			+ '--tisa-primary-bright:' + mix( hex, '#ffffff', 0.16 ) + ';'
			+ '--tisa-primary-soft:' + mix( hex, '#ffffff', 0.9 ) + ';'
			+ '--tisa-primary-tint:' + mix( hex, '#ffffff', 0.955 ) + ';'
			+ '--tisa-border-strong:' + mix( hex, '#ffffff', 0.75 ) + ';'
			+ '--tisa-ring:0 0 0 3px ' + rgba( 0.22 ) + ';}';
		$$( '.tisa-accent__sw' ).forEach( function ( b ) {
			b.classList.toggle( 'is-on', ( b.getAttribute( 'data-hex' ) || '' ).toLowerCase() === hex.toLowerCase() );
		} );
	}

	var pick = $( '#tsh-accent-pick' );
	if ( pick ) {
		pick.addEventListener( 'input', function () {
			previewAccent( pick.value );
			var f = $( '#tsh-accent' );
			if ( f ) { f.value = pick.value.replace( '#', '' ); }
		} );
		pick.addEventListener( 'change', function () { if ( cfg.ajax ) { post( 'tsh_prefs', { accent: pick.value } ); } } );
	}

	$$( '.tisa-accent__sw' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var hex = btn.getAttribute( 'data-hex' );
			previewAccent( hex );
			var field = $( '#tsh-accent' );
			if ( field ) { field.value = hex.replace( '#', '' ); }
			var pick = $( '#tsh-accent-pick' );
			if ( pick ) { pick.value = hex; }
			if ( cfg.ajax ) { post( 'tsh_prefs', { accent: hex } ); }
		} );
	} );

	var custom = $( '#tsh-accent-custom' );
	if ( custom ) {
		custom.addEventListener( 'input', function () { previewAccent( custom.value ); } );
		custom.addEventListener( 'change', function () { if ( cfg.ajax ) { post( 'tsh_prefs', { accent: custom.value } ); } } );
	}

	var compact = $( '#tsh-compact' );
	if ( compact ) {
		compact.addEventListener( 'change', function () {
			body.classList.toggle( 'tisa-compact', compact.checked );
			if ( cfg.ajax ) { post( 'tsh_prefs', { compact: compact.checked ? 1 : 0 } ); }
		} );
	}

	/* ---------------------------------------------------------------- 4) شمارش‌ها */

	var refresh = $( '#tsh-refresh' );
	if ( refresh ) {
		refresh.addEventListener( 'click', function () {
			refresh.classList.add( 'is-busy' );
			refresh.disabled = true;
			var badges = $$( '[data-count]' );
			badges.forEach( function ( b ) { b.classList.add( 'tisa-skeleton' ); } );
			post( 'tsh_counts', {} ).then( function ( res ) {
				var data = ( res && res.data && res.data.counts ) || {};
				badges.forEach( function ( b ) {
					var key = b.getAttribute( 'data-count' );
					var num = $( '.tisa-num', b );
					if ( num && data[ key ] ) { num.textContent = String( data[ key ].value ); }
					b.classList.remove( 'tisa-skeleton' );
				} );
				refresh.classList.remove( 'is-busy' );
				refresh.disabled = false;
			} ).catch( function () {
				badges.forEach( function ( b ) { b.classList.remove( 'tisa-skeleton' ); } );
				refresh.classList.remove( 'is-busy' );
				refresh.disabled = false;
			} );
		} );
	}

	/* ---------------------------------------------------------------- 5) کپی قطعهٔ کد */

	var copy = $( '#tsh-copy' );
	if ( copy ) {
		copy.addEventListener( 'click', function () {
			var box = $( '.tisa-codebox code' );
			if ( ! box ) { return; }
			var text = box.textContent;
			var done = function () {
				var old = copy.textContent;
				copy.textContent = t( 'copied' ) || 'کپی شد';
				window.setTimeout( function () { copy.textContent = old; }, 1600 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done, function () {} );
			} else {
				var ta = document.createElement( 'textarea' );
				ta.value = text;
				document.body.appendChild( ta );
				ta.select();
				try { document.execCommand( 'copy' ); done(); } catch ( e ) {}
				document.body.removeChild( ta );
			}
		} );
	}
}() );
