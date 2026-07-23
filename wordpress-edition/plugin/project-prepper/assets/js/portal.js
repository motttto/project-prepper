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
			// Versteckte required-Radios würden die Browser-Validierung blockieren
			// („not focusable") — daher mit ausknipsen, die gewählte aber behalten.
			var radio = row.querySelector( 'input[type="radio"]' );
			if ( radio ) { radio.disabled = ! hit && ! radio.checked; }
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
} )();
