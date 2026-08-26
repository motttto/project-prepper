/**
 * Live-Suche für alle Suchmasken (Portal + öffentliches Inventar).
 *
 * Formulare mit [data-pp-live] filtern beim Tippen sofort die Zeilen ihres
 * Bereichs ([data-pp-live-scope], sonst die ganze Seite): Text-Match auf
 * Elementen mit [data-pp-searchable]; versteckt wird das umschließende
 * [data-pp-search-row] (falls vorhanden), sonst das Element selbst. Ein
 * geleertes Feld zeigt sofort wieder ALLE Zeilen.
 *
 * Server-Suche bleibt erhalten: Enter/„Suchen" submittet wie bisher (die
 * Tiefensuche findet auch nicht angezeigte Felder wie Seriennummer/Tags).
 * Wurde die Seite MIT aktiver Server-Suche geladen (pp_q in der URL), lädt
 * ein geleertes Feld nach kurzer Pause die volle Liste nach (URL ohne pp_q)
 * — weitertippen bricht das ab. Ohne JS bleibt alles voll benutzbar.
 */
( function () {
	'use strict';

	var emptyTimer = null;

	/* Bereich, dessen Zeilen dieses Feld filtert. REGEL: [data-pp-live-scope]
	 * muss das Suchformular UMSCHLIESSEN (oder das Formular trägt es selbst, wie
	 * der Technik-Picker) — nur so ist die Zuordnung eindeutig, wenn eine Seite
	 * mehrere Suchmasken hat. Ohne Scope filtert das Feld das ganze Dokument. */
	function scopeOf( input ) {
		return input.closest( '[data-pp-live-scope]' ) || document;
	}

	function hideTarget( el ) {
		return el.closest( '[data-pp-search-row]' ) || el;
	}

	document.addEventListener( 'input', function ( e ) {
		var input = e.target;
		if ( ! input.matches || ! input.matches( 'form[data-pp-live] input[type="search"]' ) ) {
			return;
		}
		window.clearTimeout( emptyTimer );

		var q     = input.value.toLowerCase().trim();
		var scope = scopeOf( input );
		var any   = false;
		scope.querySelectorAll( '[data-pp-searchable]' ).forEach( function ( el ) {
			var hit = '' === q || -1 !== el.textContent.toLowerCase().indexOf( q );
			hideTarget( el ).hidden = ! hit;
			if ( hit ) {
				any = true;
			}
		} );
		var none = scope.querySelector( '[data-pp-search-none]' );
		if ( none ) {
			none.hidden = any || '' === q;
		}

		// Seite wurde server-gefiltert geladen und das Feld ist jetzt leer →
		// nach kurzer Pause die VOLLE Liste laden (alle übrigen Parameter wie
		// Ansicht/Kategorie bleiben erhalten). Weitertippen bricht ab.
		if ( '' === q ) {
			var served = new URLSearchParams( window.location.search ).get( 'pp_q' ) || '';
			if ( '' !== served ) {
				emptyTimer = window.setTimeout( function () {
					var url = new URL( window.location.href );
					url.searchParams.delete( 'pp_q' );
					window.location.href = url.toString();
				}, 350 );
			}
		}
	} );
} )();
