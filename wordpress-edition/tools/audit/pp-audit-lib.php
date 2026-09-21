<?php
/**
 * Testgerüst der Audit-Agenten (Skill /wp-audit) — NUR für die lokale wp-env.
 *
 * Wird nicht mit dem Plugin ausgeliefert (liegt außerhalb des Plugin-Ordners).
 * Einbinden in ein Testskript:   require '/tmp/pp-audit-lib.php';
 * (vorher per `docker cp` in den cli-Container legen, siehe /wp-audit).
 *
 * Grundregeln:
 * - Alles, was hier angelegt wird, trägt das Präfix ZZ-AUDIT und wird von
 *   pp_audit_cleanup() wieder entfernt. NIE auf Bestandsdaten schreiben:
 *   das Artikel-Formular postet den WUNSCHZUSTAND aller Felder — ein Aufruf
 *   mit nur zwei Feldern leert den Tagessatz und hebt alle Freigaben auf.
 * - Keine Logins, keine Passwörter: wp_set_current_user() genügt.
 */

if ( false === strpos( (string) home_url(), 'localhost' ) ) {
	fwrite( STDERR, "pp-audit-lib: nur gegen localhost erlaubt.\n" );
	exit( 1 );
}

/*
 * Parallelbetrieb: Jeder Agent setzt VOR dem require sein Kürzel —
 *   define( 'PP_AUDIT_TAG', 'FLOW' );
 * Dann heißen seine Daten „ZZ-AUDIT-FLOW …" und pp_audit_cleanup() räumt NUR
 * diese ab. Ohne Tag wird alles mit ZZ-AUDIT entfernt (Einzellauf/Generalputz).
 */
define( 'PP_AUDIT_PREFIX', 'ZZ-AUDIT' . ( defined( 'PP_AUDIT_TAG' ) && '' !== PP_AUDIT_TAG ? '-' . preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) PP_AUDIT_TAG ) ) : '' ) );

/**
 * Option NUR für diesen PHP-Prozess überschreiben (pre_option-Filter) — nie
 * update_option(): parallele Agenten teilen sich die Datenbank, ein global
 * umgelegter Schalter oder Puffer verfälscht die Proben der anderen.
 * Beispiel: pp_audit_option( 'pp_rental_buffer_after', 2 );
 *           pp_audit_option( 'pp_features', [ 'lending' => false ] + get_option( 'pp_features', [] ) );
 * Zurücknehmen: pp_audit_option( 'pp_rental_buffer_after', null );
 */
function pp_audit_option( string $name, $value ): void {
	static $hooks = [];
	if ( isset( $hooks[ $name ] ) ) {
		remove_filter( 'pre_option_' . $name, $hooks[ $name ], 10 );
		unset( $hooks[ $name ] );
	}
	if ( null === $value ) {
		return;
	}
	$hooks[ $name ] = static function () use ( $value ) { return $value; };
	add_filter( 'pre_option_' . $name, $hooks[ $name ], 10 );
}

/**
 * Portal-Aktion wie ein Formular-POST auslösen. wp_safe_redirect()+exit würde
 * das Skript beenden — der wp_redirect-Filter wirft vorher.
 *
 * @return string pp_msg-Code des Redirects (z. B. item_saved, forbidden, stale)
 *                oder die ganze Ziel-URL, wenn kein pp_msg dranhängt.
 */
function pp_dispatch( int $user_id, array $post, bool $valid_nonce = true ): string {
	wp_set_current_user( $user_id );
	$_POST = array_merge(
		[ 'action' => 'pp_collective', 'pp_nonce' => $valid_nonce ? wp_create_nonce( 'pp_collective' ) : 'invalid' ],
		$post
	);
	$_REQUEST                  = $_POST;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$hook = static function ( $location ) { throw new RuntimeException( (string) $location ); };
	add_filter( 'wp_redirect', $hook, 1 );
	try {
		\ProjectPrepper\Frontend\MemberPortal::handle_collective_action();
		$out = '(kein Redirect)';
	} catch ( RuntimeException $e ) {
		$out = $e->getMessage();
	}
	remove_filter( 'wp_redirect', $hook, 1 );
	$_POST = $_REQUEST = [];
	parse_str( (string) wp_parse_url( $out, PHP_URL_QUERY ), $q );
	return (string) ( $q['pp_msg'] ?? $out );
}

/** REST-Aufruf als User; liefert [ status, data ]. */
function pp_rest( int $user_id, string $method, string $route, ?array $body = null ): array {
	wp_set_current_user( $user_id );
	$req = new WP_REST_Request( $method, '/project-prepper/v1' . $route );
	if ( null !== $body ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	return [ $res->get_status(), $res->get_data() ];
}

/** Portal-Ansicht als User rendern (HTML). $solo = persönlichen Arbeitsbereich erzwingen. */
function pp_render( int $user_id, string $view, array $get = [], bool $solo = false ): string {
	wp_set_current_user( $user_id );
	$filter = static function ( $v, $id, $key ) { return 'pp_active_group' === $key ? [ 'solo' ] : $v; };
	if ( $solo ) {
		add_filter( 'get_user_metadata', $filter, 10, 3 );
	}
	$_GET = array_merge( [ 'pp_view' => $view ], $get );
	$html = do_shortcode( '[pp_member_portal]' );
	$_GET = [];
	remove_filter( 'get_user_metadata', $filter, 10 );
	return $html;
}

/**
 * Angelegtes Wegwerf-Objekt vormerken, damit pp_audit_cleanup() es auch dann
 * noch abräumen kann, wenn der Test es selbst schon gelöscht hat — über den
 * Namen ist es dann nicht mehr auffindbar, seine Kindzeilen bleiben aber liegen
 * (Lehre aus dem LIFE-Lauf: genau so blieben Leih-Anfragen, Föderations-Zeilen
 * und Team-Zeilen zurück).
 *
 * @param string $kind items | rentals | projects | inquiries | item_field_defs | attachments
 */
function pp_audit_track( string $kind, int $id ): int {
	$reg = &pp_audit_registry();
	if ( $id > 0 && ! in_array( $id, $reg[ $kind ] ?? [], true ) ) {
		$reg[ $kind ][] = $id;
	}
	return $id;
}

/** @return array<string,array<int>> Referenz auf die Merkliste. */
function &pp_audit_registry(): array {
	static $reg = [];
	return $reg;
}

/** Wegwerf-Artikel anlegen (optional mit Gruppe geteilt). @return int Artikel-ID. */
function pp_audit_item( int $owner_id, string $suffix, int $quantity = 1, int $share_group = 0, bool $requires_approval = false ): int {
	wp_set_current_user( $owner_id );
	$id = \ProjectPrepper\Services\MemberInventory::create( $owner_id, [
		'name'      => PP_AUDIT_PREFIX . ' ' . $suffix,
		'quantity'  => $quantity,
		'condition' => 'good',
	] );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'pp_audit_item: ' . $id->get_error_message() );
	}
	pp_audit_track( 'items', (int) $id );
	if ( $share_group > 0 ) {
		\ProjectPrepper\Services\MemberInventory::set_share( $owner_id, (int) $id, $share_group, [
			'daily_rate'        => null,
			'requires_approval' => $requires_approval,
			'conditions_tags'   => [],
			'conditions'        => '',
		] );
	}
	return (int) $id;
}

/**
 * Alles mit ZZ-AUDIT-Präfix entfernen: Artikel (+ jede Zeile, die per
 * item_id/part_item_id/bundle_item_id auf sie zeigt), Verleihe, Projekte,
 * Anfragen und eigene Felder. Generisch über SHOW COLUMNS, damit neue
 * Tabellen automatisch mit aufgeräumt werden.
 *
 * @return array<string,int> gelöschte Zeilen je Tabelle.
 */
function pp_audit_cleanup(): array {
	global $wpdb;
	$like    = $wpdb->esc_like( PP_AUDIT_PREFIX ) . '%';
	$t       = static fn( string $n ) => \ProjectPrepper\Schema::table( $n );
	$deleted = [];
	$tables  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'pp_' ) . '%' ) );
	$purge   = static function ( array $ids, array $columns ) use ( $wpdb, $tables, &$deleted ) {
		if ( ! $ids ) {
			return;
		}
		$in = implode( ',', array_map( 'intval', $ids ) );
		foreach ( $tables as $table ) {
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore
			foreach ( array_intersect( $columns, $cols ) as $col ) {
				$n = (int) $wpdb->query( "DELETE FROM `{$table}` WHERE `{$col}` IN ({$in})" ); // phpcs:ignore
				if ( $n ) {
					$deleted[ $table ] = ( $deleted[ $table ] ?? 0 ) + $n;
				}
			}
		}
	};

	// Je Objektart: per Namen gefundene UND vorgemerkte IDs (pp_audit_track) —
	// letztere greifen auch bei Objekten, die der Test selbst gelöscht hat.
	$reg  = pp_audit_registry();
	$kinds = [
		'items'           => [ 'name',          [ 'item_id', 'part_item_id', 'bundle_item_id' ] ],
		'rentals'         => [ 'borrower_name', [ 'rental_id' ] ],
		'projects'        => [ 'name',          [ 'project_id' ] ],
		// Anfragen haben ZWEI Namensspalten: `name` (Kunde, von create() gesetzt)
		// und `title` (Betreff) — je nach Testweg steht das Präfix in einer davon.
		'inquiries'       => [ [ 'name', 'title' ], [ 'inquiry_id' ] ],
		'item_field_defs' => [ 'label',         [ 'field_id' ] ],
	];
	foreach ( $kinds as $table => $spec ) {
		$found = array_map( 'intval', $reg[ $table ] ?? [] );
		foreach ( (array) $spec[0] as $col ) {
			$found = array_merge( $found, array_map( 'intval', $wpdb->get_col(
				$wpdb->prepare( 'SELECT id FROM %i WHERE %i LIKE %s', $t( $table ), $col, $like )
			) ) );
		}
		$ids = array_values( array_unique( $found ) );
		$purge( $ids, $spec[1] );
		if ( $ids ) {
			$in = implode( ',', $ids );
			$n  = (int) $wpdb->query( "DELETE FROM `{$t( $table )}` WHERE id IN ({$in})" ); // phpcs:ignore
			if ( $n ) {
				$deleted[ $t( $table ) ] = ( $deleted[ $t( $table ) ] ?? 0 ) + $n;
			}
		}
	}
	// Vorgemerkte Medien (Fotos, Dokumente) — das Plugin räumt sie nicht ab.
	foreach ( $reg['attachments'] ?? [] as $att ) {
		if ( wp_delete_attachment( (int) $att, true ) ) {
			$deleted['attachments'] = ( $deleted['attachments'] ?? 0 ) + 1;
		}
	}
	return $deleted;
}
