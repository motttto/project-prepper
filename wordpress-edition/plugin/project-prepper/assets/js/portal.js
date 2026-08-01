/**
 * Member-Portal — kleine progressive Erweiterung (das Portal ist sonst
 * server-rendered). Aktuell nur: „+ Option" beim Umfrage-Anlegen fügt eine
 * weitere Options-Box hinzu. Ohne JS bleiben die Start-Boxen voll nutzbar.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.pp-poll-add' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();

		var fieldset = btn.closest( '.pp-poll-optset' );
		var list = fieldset ? fieldset.querySelector( '.pp-poll-optbox-list' ) : null;
		if ( ! list ) {
			return;
		}

		var n = list.querySelectorAll( '.pp-poll-optbox' ).length + 1;
		var input = document.createElement( 'input' );
		input.type = 'text';
		input.name = 'pp_opt[]';
		input.className = 'pp-poll-optbox';
		input.placeholder = ( btn.getAttribute( 'data-label' ) || 'Option' ) + ' ' + n;
		list.appendChild( input );
		input.focus();
	} );

	/* Inventar-Detail-Modal: Zeile öffnet das <dialog>, ✕/Backdrop/Esc schließen.
	 * Native <dialog> liefert Esc + Top-Layer; Backdrop-Klick erkennen wir per
	 * Trefferfläche (Klick außerhalb der Modal-Box). */
	function openModal( id ) {
		var dlg = document.getElementById( id );
		if ( dlg && typeof dlg.showModal === 'function' && ! dlg.open ) {
			dlg.showModal();
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var closeBtn = e.target.closest( '[data-pp-modal-close]' );
		if ( closeBtn ) {
			var ownDlg = closeBtn.closest( 'dialog' );
			if ( ownDlg ) { ownDlg.close(); }
			return;
		}
		// Klick auf den Backdrop (außerhalb der Modal-Box) schließt.
		if ( 'DIALOG' === e.target.tagName && e.target.classList.contains( 'pp-modal' ) ) {
			var r = e.target.getBoundingClientRect();
			var inside = e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom;
			if ( ! inside ) { e.target.close(); }
			return;
		}
		var trigger = e.target.closest( '[data-pp-modal]' );
		if ( ! trigger ) { return; }
		// Interaktive Kindelemente in der Zeile nicht abfangen — der Trigger
		// selbst darf aber ein Button sein (z.B. „Inventar freigeben").
		var interactive = e.target.closest( 'a, button, input, label, summary' );
		if ( interactive && interactive !== trigger ) { return; }
		e.preventDefault();
		openModal( trigger.getAttribute( 'data-pp-modal' ) );
	} );

	// Tastaturbedienung der Zeile (role="button").
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' !== e.key && ' ' !== e.key ) { return; }
		var trigger = e.target.closest ? e.target.closest( '[data-pp-modal]' ) : null;
		if ( ! trigger || e.target !== trigger ) { return; }
		e.preventDefault();
		openModal( trigger.getAttribute( 'data-pp-modal' ) );
	} );

	/* Technik buchen: Live-Suche filtert die Artikel-Liste im Modal.
	 * Ohne JS bleibt die volle Liste sichtbar und buchbar. */
	document.addEventListener( 'input', function ( e ) {
		if ( ! e.target.classList || ! e.target.classList.contains( 'pp-book-search' ) ) { return; }
		var form = e.target.closest( 'form' );
		if ( ! form ) { return; }
		var q = e.target.value.toLowerCase().trim();
		var any = false;
		form.querySelectorAll( '.pp-book-item' ).forEach( function ( row ) {
			var hit = '' === q || -1 !== row.textContent.toLowerCase().indexOf( q );
			row.hidden = ! hit;
			if ( hit ) { any = true; }
			// Ausgefilterte, aber angehakte Artikel bleiben ausgewählt (Checkbox
			// submittet auch verborgen) — Mehrfachauswahl über mehrere Suchen hinweg.
		} );
		var none = form.querySelector( '.pp-book-none' );
		if ( none ) { none.hidden = any; }
	} );

	/* Kopieren-Button (z.B. iCal-Feed-URL): kopiert den Wert des per
	 * data-pp-copy referenzierten Feldes. Ohne JS bleibt das Feld selektierbar
	 * (readonly + onclick select). */
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-pp-copy]' );
		if ( ! btn ) { return; }
		var input = document.getElementById( btn.getAttribute( 'data-pp-copy' ) );
		if ( ! input ) { return; }
		var done = function () {
			var orig = btn.textContent;
			btn.textContent = btn.getAttribute( 'data-copied-label' ) || orig;
			window.setTimeout( function () { btn.textContent = orig; }, 2000 );
		};
		input.select();
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( input.value ).then( done, function () {
				document.execCommand( 'copy' );
				done();
			} );
		} else {
			document.execCommand( 'copy' );
			done();
		}
	} );

	/* Presence-Heartbeat: hält den eigenen „zuletzt gesehen"-Stempel frisch,
	 * damit Mitglieder desselben Kollektivs sich beim nächsten Seitenaufbau als
	 * online sehen. Bewusst leichtgewichtig: nur ein Ping beim Laden + im
	 * Intervall, und NUR wenn der Tab sichtbar ist (kein Ping im Hintergrund).
	 * Es gibt kein Live-DOM-Update — die Anzeige ist eine Momentaufnahme. */
	( function () {
		var cfg = window.ppPortal;
		if ( ! cfg || ! cfg.heartbeatUrl ) {
			return;
		}
		var intervalMs = parseInt( cfg.heartbeatMs, 10 ) || 45000;

		function beat() {
			// Im Hintergrund-Tab nicht pingen — spart Last.
			if ( document.hidden ) {
				return;
			}
			fetch( cfg.heartbeatUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': cfg.nonce }
			} ).catch( function () { /* still — Presence ist rein optional */ } );
		}

		beat();
		window.setInterval( beat, intervalMs );
		// Ein zusätzlicher Ping, sobald der Tab wieder sichtbar wird — so ist man
		// nach der Rückkehr direkt wieder als online sichtbar.
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) {
				beat();
			}
		} );
	} )();

	/* Hover-Prefetch: lädt Portal-Seiten schon beim Draufzeigen im Hintergrund,
	 * damit sich die Vollreload-Navigation wie eine App anfühlt. Bewusst eng
	 * gefasst: nur same-origin Seiten-Links — niemals Aktions-/Auth-URLs
	 * (admin-post/wp-login/wp-admin oder Links mit Nonce/action), die beim
	 * Vorladen etwas ausführen würden. Ohne JS oder in Browsern ohne
	 * rel=prefetch (Safari) ändert sich nichts. */
	( function () {
		if ( navigator.connection && navigator.connection.saveData ) {
			return; // Daten-Sparmodus respektieren.
		}
		var done  = {};
		var timer = null;

		function safe( a ) {
			if ( ! a || ! a.href || a.origin !== window.location.origin ) { return false; }
			if ( a.hasAttribute( 'download' ) || a.hasAttribute( 'data-no-prefetch' ) || '_blank' === a.target ) { return false; }
			var url = a.href;
			if ( -1 !== url.indexOf( 'admin-post.php' ) || -1 !== url.indexOf( 'wp-login.php' ) || -1 !== url.indexOf( '/wp-admin' ) ) { return false; }
			if ( /[?&](_wpnonce|pp_nonce|action|pp_export)=/.test( url ) ) { return false; }
			return true;
		}

		function prefetch( a ) {
			var key = a.href.split( '#' )[ 0 ];
			if ( done[ key ] || key === window.location.href.split( '#' )[ 0 ] ) { return; }
			done[ key ] = true;
			var link = document.createElement( 'link' );
			link.rel  = 'prefetch';
			link.href = key;
			document.head.appendChild( link );
		}

		document.addEventListener( 'mouseover', function ( e ) {
			var a = e.target.closest ? e.target.closest( 'a[href]' ) : null;
			if ( ! a || ! safe( a ) ) { return; }
			// Kurze Verweil-Schwelle, damit bloßes Überstreichen nicht lädt.
			window.clearTimeout( timer );
			timer = window.setTimeout( function () { prefetch( a ); }, 65 );
		} );
		document.addEventListener( 'mouseout', function () {
			window.clearTimeout( timer );
		} );
		// Touch: beim Antippen sofort vorladen — der Tap selbst dauert ~100 ms,
		// die der Seitenaufbau dann schon voraus ist.
		document.addEventListener( 'touchstart', function ( e ) {
			var a = e.target.closest ? e.target.closest( 'a[href]' ) : null;
			if ( a && safe( a ) ) { prefetch( a ); }
		}, { passive: true } );
	} )();
} )();
