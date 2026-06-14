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
} )();
