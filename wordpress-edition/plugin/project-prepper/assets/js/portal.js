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

	/* Verwalten-Modal: EIN Formular (data-pp-autosave) für Foto, Stammdaten und
	 * Freigaben. Geänderte Felder werden beim SCHLIESSEN (✕, Backdrop, Esc)
	 * automatisch gespeichert — der Submit lädt die Seite neu, das Modal ist
	 * danach zu. Unverändert schließt sofort ohne Request. */
	function autosaveForm( dlg ) {
		return dlg ? dlg.querySelector( 'form[data-pp-autosave]' ) : null;
	}

	document.addEventListener( 'input', function ( e ) {
		var form = e.target.closest ? e.target.closest( 'form[data-pp-autosave]' ) : null;
		if ( form ) { form.setAttribute( 'data-pp-dirty', '1' ); }
	} );
	document.addEventListener( 'change', function ( e ) {
		var form = e.target.closest ? e.target.closest( 'form[data-pp-autosave]' ) : null;
		if ( form ) { form.setAttribute( 'data-pp-dirty', '1' ); }
	} );

	/* Dialog schließen — mit ungespeicherten Änderungen erst speichern (Submit
	 * mit HTML-Validierung: ein leerer Pflicht-Name hält das Modal offen). */
	function closeDialog( dlg ) {
		var form = autosaveForm( dlg );
		if ( form && form.hasAttribute( 'data-pp-dirty' ) ) {
			if ( typeof form.requestSubmit === 'function' ) { form.requestSubmit(); } else { form.submit(); }
			return;
		}
		dlg.close();
	}

	document.addEventListener( 'click', function ( e ) {
		var closeBtn = e.target.closest( '[data-pp-modal-close]' );
		if ( closeBtn ) {
			var ownDlg = closeBtn.closest( 'dialog' );
			if ( ownDlg ) { closeDialog( ownDlg ); }
			return;
		}
		// Klick auf den Backdrop (außerhalb der Modal-Box) schließt.
		if ( 'DIALOG' === e.target.tagName && e.target.classList.contains( 'pp-modal' ) ) {
			var r = e.target.getBoundingClientRect();
			var inside = e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom;
			if ( ! inside ) { closeDialog( e.target ); }
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

	// Esc im <dialog> feuert 'cancel' — mit ungespeicherten Änderungen erst
	// speichern statt zu schließen (gleiches Verhalten wie ✕/Backdrop).
	document.addEventListener( 'cancel', function ( e ) {
		var dlg  = e.target;
		var form = 'DIALOG' === dlg.tagName ? autosaveForm( dlg ) : null;
		if ( form && form.hasAttribute( 'data-pp-dirty' ) ) {
			e.preventDefault();
			if ( typeof form.requestSubmit === 'function' ) { form.requestSubmit(); } else { form.submit(); }
		}
	}, true );

	/* Die Live-Suche des Technik-Pickers läuft seit v0.131.0 über das gemeinsame
	 * live-search.js (data-pp-live / data-pp-searchable am Buchungsformular) —
	 * inklusive „leeres Feld zeigt wieder alles". Ausgefilterte, aber angehakte
	 * Artikel bleiben dabei ausgewählt (versteckte Checkboxen submitten mit). */

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
	/* Auswahl-Anzeige über den Artikel-Listen (Verleih-Formular, Technik-Picker):
	 * zeigt live, was schon angehakt ist — auch wenn die Live-Suche die Zeile
	 * gerade ausblendet. Struktur: [data-pp-picker] umschließt die Liste und
	 * [data-pp-picker-summary]; die Chips landen in [data-pp-picker-chips], der
	 * Leer-Hinweis steckt in [data-pp-picker-empty]. Ohne JS bleibt der
	 * Leer-Hinweis stehen und die Auswahl funktioniert unverändert. */
	( function () {
		function itemName( row ) {
			var name = row.querySelector( '.pp-book-item__name' );
			if ( ! name ) { return ''; }
			// Nur die direkten Textknoten — Chips („Set"), Inventarnummer und
			// Badges stecken in Kind-Elementen und würden den Chip aufblähen.
			var txt = '';
			Array.prototype.forEach.call( name.childNodes, function ( n ) {
				if ( 3 === n.nodeType ) { txt += n.textContent; }
			} );
			txt = txt.replace( /\s+/g, ' ' ).trim();
			return txt || name.textContent.replace( /\s+/g, ' ' ).trim();
		}

		function refresh( picker ) {
			var chips = picker.querySelector( '[data-pp-picker-chips]' );
			var empty = picker.querySelector( '[data-pp-picker-empty]' );
			if ( ! chips ) { return; }
			var picked = [];
			picker.querySelectorAll( '.pp-book-item' ).forEach( function ( row ) {
				var box = row.querySelector( 'input[type="checkbox"]' );
				if ( ! box || ! box.checked ) { return; }
				var qty = row.querySelector( '.pp-book-item__qty' );
				var n   = qty ? parseInt( qty.value, 10 ) : 1;
				picked.push( ( n > 1 ? n + '× ' : '' ) + itemName( row ) );
			} );
			chips.textContent = '';
			picked.forEach( function ( label ) {
				var chip = document.createElement( 'span' );
				chip.className = 'pp-picker-summary__chip';
				chip.textContent = label;
				chips.appendChild( chip );
			} );
			if ( empty ) { empty.hidden = picked.length > 0; }
		}

		function refreshFrom( el ) {
			var picker = el.closest ? el.closest( '[data-pp-picker]' ) : null;
			if ( picker ) { refresh( picker ); }
		}

		document.addEventListener( 'change', function ( e ) {
			if ( e.target.matches && e.target.matches( '[data-pp-picker] input[type="checkbox"]' ) ) {
				refreshFrom( e.target );
			}
		} );

		/* Zeitraum im Verleih-Formular geändert → die serverseitig gerechneten
		 * Verfügbarkeiten gelten für die ALTEN Tage. Statt stiller falscher Zahlen
		 * sagt der Hinweis das offen; die echte Prüfung macht ohnehin der Server
		 * beim Speichern (MemberRentals/Rentals). Der Ersatztext kommt übersetzt
		 * aus data-pp-stale. */
		document.addEventListener( 'change', function ( e ) {
			if ( ! e.target.matches || ! e.target.matches( '.pp-rental-form input[type="date"]' ) ) { return; }
			var form = e.target.closest( '.pp-rental-form' );
			var note = form ? form.querySelector( '[data-pp-avail-note]' ) : null;
			if ( note && note.dataset.ppStale ) {
				note.textContent = note.dataset.ppStale;
				note.classList.add( 'pp-hint--warn' );
			}
		} );
		document.addEventListener( 'input', function ( e ) {
			if ( e.target.matches && e.target.matches( '[data-pp-picker] .pp-book-item__qty' ) ) {
				refreshFrom( e.target );
			}
		} );
		document.addEventListener( 'DOMContentLoaded', function () {
			document.querySelectorAll( '[data-pp-picker]' ).forEach( refresh );
		} );
		// Skript liegt im Footer — bei bereits fertigem DOM sofort initialisieren.
		if ( 'loading' !== document.readyState ) {
			document.querySelectorAll( '[data-pp-picker]' ).forEach( refresh );
		}
	} )();
} )();
