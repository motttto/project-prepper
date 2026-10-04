<?php
/**
 * Mitglieder-API (/me…) — Mandanten-Trennung, Nur-Lese-Grenze, Schalter, Drosselung.
 *
 * Eigenständiger Regressionstest für die lokale wp-env (Skill /wp-audit). Legt
 * eigene Wegwerf-Nutzer (zz-audit-api-*), ein Kollektiv, Artikel und Verleihe
 * an und räumt am Ende alles wieder ab — braucht also KEINE Bestandsnutzer.
 *
 *   CLI=$(docker ps --format '{{.Names}}' | grep -E 'cli-1$' | grep -v tests | head -1)
 *   docker cp wordpress-edition/tools/audit/pp-audit-lib.php "$CLI":/tmp/pp-audit-lib.php
 *   docker cp wordpress-edition/tools/audit/member-api-isolation.php "$CLI":/tmp/api-isolation.php
 *   ( cd wordpress-edition/plugin/project-prepper && npx @wordpress/env run cli wp eval-file /tmp/api-isolation.php )
 *
 * Ausgabe: je Prüfung PASS/FAIL, am Ende die Summe. Exit-Code 1 bei einem FAIL.
 */

define( 'PP_AUDIT_TAG', 'API' );
require '/tmp/pp-audit-lib.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use ProjectPrepper\MemberApi;
use ProjectPrepper\Services\Groups;
use ProjectPrepper\Services\MemberRentals;

// wp eval-file bindet die Datei in einer Funktion ein — Zähler daher explizit global.
$GLOBALS['pp_fail'] = 0;
$GLOBALS['pp_pass'] = 0;
function pp_check( bool $ok, string $label, $detail = '' ): void {
	if ( $ok ) {
		++$GLOBALS['pp_pass'];
		echo "PASS  {$label}\n";
	} else {
		++$GLOBALS['pp_fail'];
		echo "FAIL  {$label}" . ( '' !== $detail ? '  → ' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) : '' ) . "\n";
	}
}

/** REST-Aufruf auf eine BELIEBIGE Route (pp_rest hängt den Namespace davor). */
function pp_rest_any( int $user_id, string $method, string $route, ?array $body = null ): array {
	wp_set_current_user( $user_id );
	$req = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	return [ $res->get_status(), $res->get_data() ];
}

/** Als wäre die Anfrage per App-Passwort angemeldet (Core liest diesen Global). */
function pp_as_app_password( bool $on ): void {
	$GLOBALS['wp_rest_application_password_uuid'] = $on ? 'zz-audit-app-password' : null;
}

/** GET mit Query-Parametern (pp_rest nimmt keinen Query-String). */
function pp_rest_q( int $user_id, string $route, array $query ): array {
	wp_set_current_user( $user_id );
	$req = new WP_REST_Request( 'GET', '/project-prepper/v1' . $route );
	$req->set_query_params( $query );
	$res = rest_do_request( $req );
	return [ $res->get_status(), $res->get_data() ];
}

/**
 * Fehlversuche, die per HTTP im wordpress-Container gezählt wurden, hängen an der
 * IP DIESES cli-Containers (Security::ip_key ist privat → Schlüssel nachbauen).
 */
function pp_clear_http_lock(): void {
	delete_transient( 'pp_sec_lf_' . md5( (string) gethostbyname( (string) gethostname() ) ) );
}

function pp_ids( $rows ): array {
	$ids = array_map( static fn( $r ) => (int) ( is_array( $r ) ? $r['id'] : $r->id ), (array) $rows );
	sort( $ids );
	return $ids;
}

function pp_row_array( $row ): array {
	$a = json_decode( wp_json_encode( $row ), true );
	ksort( $a );
	return $a;
}

/* ---------- Aufbau ---------- */

$logins = [ 'a' => 'pp_member', 'b' => 'pp_member', 'c' => 'pp_member', 'm' => 'pp_manager', 's' => 'subscriber' ];
$u      = [];
foreach ( $logins as $key => $role ) {
	$login = 'zz-audit-api-' . $key;
	$old   = get_user_by( 'login', $login );
	if ( $old ) {
		wp_delete_user( $old->ID ); // Rest eines abgebrochenen Laufs.
	}
	$u[ $key ] = (int) wp_insert_user( [
		'user_login'   => $login,
		'user_email'   => $login . '@example.invalid',
		'user_pass'    => wp_generate_password( 24 ),
		'display_name' => PP_AUDIT_PREFIX . ' ' . strtoupper( $key ),
		'role'         => $role,
	] );
}

wp_set_current_user( $u['a'] );
$group = (int) Groups::create( [ 'name' => PP_AUDIT_PREFIX . ' Kollektiv' ] );
Groups::add_member( $group, $u['b'] );

$a1 = pp_audit_item( $u['a'], 'A1 geteilt', 2, $group );
$a2 = pp_audit_item( $u['a'], 'A2 privat' );
$b1 = pp_audit_item( $u['b'], 'B1 geteilt', 2, $group );
$b2 = pp_audit_item( $u['b'], 'B2 privat' );

$day = static fn( int $d ) => gmdate( 'Y-m-d', strtotime( "+{$d} days" ) );
$mk  = static function ( int $user, string $name, array $items, int $from, int $group_id = 0 ) use ( $day ) {
	wp_set_current_user( $user );
	$id = MemberRentals::create( $user, [
		'borrower_name'  => PP_AUDIT_PREFIX . ' ' . $name,
		'borrower_email' => 'borrower@example.invalid',
		'event_name'     => 'Audit ' . $name,
		'date_from'      => $day( $from ),
		'date_to'        => $day( $from + 2 ),
	], array_map( static fn( $i ) => [ 'item_id' => $i, 'quantity' => 1, 'daily_rate' => 10 ], $items ), [], $group_id );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( "Verleih {$name}: " . $id->get_error_message() );
	}
	return pp_audit_track( 'rentals', (int) $id );
};
$ra  = $mk( $u['a'], 'RA solo', [ $a2 ], 40 );
$rag = $mk( $u['a'], 'RAG kollektiv', [ $a1, $b1 ], 50, $group );
$rb  = $mk( $u['b'], 'RB solo', [ $b2 ], 40 );
$rbg = $mk( $u['b'], 'RBG kollektiv mit A-Equipment', [ $a1 ], 60, $group );

try {
	/* ---------- /me ---------- */
	[ $st, $me ] = pp_rest( $u['a'], 'GET', '/me' );
	pp_check( 200 === $st && (int) $me['id'] === $u['a'], '/me liefert den angemeldeten Nutzer', [ $st, $me ] );
	pp_check( in_array( 'pp_member', (array) ( $me['roles'] ?? [] ), true ), '/me: Rolle pp_member' );
	pp_check( [ $group ] === array_map( static fn( $g ) => (int) $g['id'], (array) ( $me['groups'] ?? [] ) ) && 'founder' === ( $me['groups'][0]['role'] ?? '' ), '/me: Kollektiv + Rolle', $me['groups'] ?? null );
	pp_check( [ 'groups', 'id', 'name', 'roles' ] === ( static function ( $k ) { sort( $k ); return $k; } )( array_keys( (array) $me ) ), '/me: genau id, name, roles, groups' );

	/* ---------- /me/items ---------- */
	[ $st, $items_a ] = pp_rest( $u['a'], 'GET', '/me/items' );
	pp_check( 200 === $st && pp_ids( $items_a ) === [ $a1, $a2 ], 'A sieht genau seine Artikel', [ $st, pp_ids( $items_a ) ] );
	pp_check( ! array_filter( (array) $items_a, static fn( $i ) => (int) $i->owner_user_id !== $u['a'] ), 'A: jede Zeile owner_user_id = A' );
	[ , $items_b ] = pp_rest( $u['b'], 'GET', '/me/items' );
	pp_check( pp_ids( $items_b ) === [ $b1, $b2 ], 'B sieht genau seine Artikel (nicht A1, obwohl geteilt)', pp_ids( $items_b ) );
	[ , $items_c ] = pp_rest( $u['c'], 'GET', '/me/items' );
	pp_check( [] === pp_ids( $items_c ), 'Außenstehender C sieht keine Artikel', pp_ids( $items_c ) );
	$needed = [ 'inventory_number', 'name', 'category_name', 'quantity', 'cost_per_day', 'purchase_price', 'current_value', 'condition', 'location', 'manufacturer', 'model', 'out_now', 'image_url', 'owner_user_id', 'owner_name' ];
	$row    = pp_row_array( $items_a[0] ?? [] );
	pp_check( ! array_diff( $needed, array_keys( $row ) ), 'Artikel-Felder wie GET /items vorhanden', array_values( array_diff( $needed, array_keys( $row ) ) ) );
	[ , $admin_items ] = pp_rest( 1, 'GET', '/items' );
	$admin_row = null;
	foreach ( $admin_items as $it ) {
		if ( (int) $it->id === $a1 ) {
			$admin_row = pp_row_array( $it );
		}
	}
	$me_row = null;
	foreach ( $items_a as $it ) {
		if ( (int) $it->id === $a1 ) {
			$me_row = pp_row_array( $it );
		}
	}
	pp_check( null !== $admin_row && $admin_row === $me_row, 'Artikel-Zeile identisch zu GET /items (Admin)' );
	[ , $search ] = pp_rest_q( $u['a'], '/me/items', [ 'search' => 'B1' ] );
	pp_check( [] === pp_ids( $search ), 'Suche nach fremdem Artikel liefert nichts' );

	/* ---------- /me/rentals ---------- */
	[ $st, $rent_a ] = pp_rest( $u['a'], 'GET', '/me/rentals' );
	pp_check( 200 === $st && pp_ids( $rent_a ) === [ $ra, $rag ], 'A sieht genau seine Verleihe (solo + im Kollektiv angelegt)', [ $st, pp_ids( $rent_a ) ] );
	pp_check( ! in_array( $rbg, pp_ids( $rent_a ), true ), 'A sieht B\'s Kollektiv-Verleih NICHT (auch wenn A-Equipment drin)' );
	[ , $rent_b ] = pp_rest( $u['b'], 'GET', '/me/rentals' );
	pp_check( pp_ids( $rent_b ) === [ $rb, $rbg ], 'B sieht genau seine Verleihe', pp_ids( $rent_b ) );
	[ , $rent_c ] = pp_rest( $u['c'], 'GET', '/me/rentals' );
	pp_check( [] === pp_ids( $rent_c ), 'Außenstehender C sieht keine Verleihe' );
	[ , $rent_r ] = pp_rest_q( $u['a'], '/me/rentals', [ 'status' => 'reserved' ] );
	[ , $rent_x ] = pp_rest_q( $u['a'], '/me/rentals', [ 'status' => 'cancelled' ] );
	pp_check( pp_ids( $rent_r ) === [ $ra, $rag ] && [] === pp_ids( $rent_x ), '?status= filtert' );
	$need_r = [ 'rental_number', 'event_name', 'borrower_name', 'borrower_email', 'borrower_phone', 'borrower_address', 'date_from', 'date_to', 'status', 'rental_fee', 'deposit_amount', 'vat_rate', 'discount_type', 'discount_value', 'notes', 'owner_user_id', 'item_count' ];
	$rrow   = pp_row_array( $rent_a[0] ?? [] );
	pp_check( ! array_diff( $need_r, array_keys( $rrow ) ), 'Verleih-Kopf-Felder wie GET /rentals', array_values( array_diff( $need_r, array_keys( $rrow ) ) ) );
	[ , $admin_rent ] = pp_rest( 1, 'GET', '/rentals' );
	$adm = null;
	foreach ( $admin_rent as $r ) {
		if ( (int) $r->id === $ra ) {
			$adm = pp_row_array( $r );
		}
	}
	$mine = null;
	foreach ( $rent_a as $r ) {
		if ( (int) $r->id === $ra ) {
			$mine = pp_row_array( $r );
		}
	}
	pp_check( null !== $adm && $adm === $mine, 'Verleih-Zeile identisch zu GET /rentals (Admin)' );

	/* ---------- /me/rentals/{id} ---------- */
	[ $st, $one ] = pp_rest( $u['a'], 'GET', '/me/rentals/' . $ra );
	pp_check( 200 === $st && (int) $one->id === $ra && is_array( $one->items ) && isset( $one->billing['gross'], $one->billing['lines'], $one->billing['vat'], $one->billing['deposit'] ), 'A liest eigenen Verleih mit items[] + billing{}', $st );
	[ , $adm_one ] = pp_rest( 1, 'GET', '/rentals/' . $ra );
	pp_check( pp_row_array( $adm_one ) === pp_row_array( $one ), 'Einzel-Verleih identisch zu GET /rentals/{id} (Admin)' );
	foreach ( [ 'RB (fremd, solo)' => $rb, 'RBG (fremd, Kollektiv, A-Equipment)' => $rbg, 'nicht existent' => 999999999 ] as $label => $rid ) {
		[ $st, $err ] = pp_rest( $u['a'], 'GET', '/me/rentals/' . $rid );
		pp_check( 404 === $st && 'pp_not_found' === ( $err['code'] ?? '' ), "A → /me/rentals/{id} {$label} = 404", [ $st, $err['code'] ?? null ] );
	}
	[ $st ] = pp_rest( $u['c'], 'GET', '/me/rentals/' . $ra );
	pp_check( 404 === $st, 'C → fremder Verleih = 404' );
	[ $st ] = pp_rest( $u['b'], 'GET', '/me/rentals/' . $rag );
	pp_check( 404 === $st, 'B → A\'s Kollektiv-Verleih (B-Equipment drin) = 404' );

	/* ---------- Anmeldung / Rolle ---------- */
	foreach ( [ '/me', '/me/items', '/me/rentals', '/me/rentals/' . $ra ] as $route ) {
		[ $st ] = pp_rest( 0, 'GET', $route );
		pp_check( 401 === $st, "abgemeldet {$route} = 401", $st );
	}
	[ $st ] = pp_rest( $u['s'], 'GET', '/me' );
	pp_check( 403 === $st, 'Nutzer ohne Project-Prepper-Rolle = 403', $st );
	[ $st, $mm ] = pp_rest( $u['m'], 'GET', '/me' );
	pp_check( 200 === $st && (int) $mm['id'] === $u['m'], 'Manager darf /me' );

	/* ---------- Admin-Routen unverändert ---------- */
	foreach ( [ '/items', '/rentals', '/rentals/' . $ra, '/items/' . $a1 ] as $route ) {
		[ $st ] = pp_rest( $u['a'], 'GET', $route );
		pp_check( 403 === $st, "Mitglied → Admin-Route {$route} bleibt 403", $st );
	}

	/* ---------- Schalter ---------- */
	$features = static fn( array $off ) => array_merge( \ProjectPrepper\Settings::feature_defaults(), $off );
	pp_audit_option( 'pp_features', $features( [ 'api' => false ] ) );
	[ $st, $err ] = pp_rest( $u['a'], 'GET', '/me' );
	pp_check( 403 === $st && 'pp_member_api_off' === ( $err['code'] ?? '' ), 'Mitglieder-API aus → /me 403', [ $st, $err['code'] ?? null ] );
	pp_check( false === wp_is_application_passwords_available_for_user( get_userdata( $u['a'] ) ), 'API aus → App-Passwörter für Mitglied nicht verfügbar' );
	pp_check( true === wp_is_application_passwords_available_for_user( get_userdata( $u['m'] ) ), 'API aus → Manager behält App-Passwörter' );
	pp_check( 'feature_off' === pp_dispatch( $u['a'], [ 'pp_do' => 'api_password_create', 'pp_name' => 'x' ] ), 'API aus → Portal-Aktion feature_off' );
	pp_audit_option( 'pp_features', $features( [ 'inventory' => false ] ) );
	[ $st1 ] = pp_rest( $u['a'], 'GET', '/me/items' );
	[ $st2 ] = pp_rest( $u['a'], 'GET', '/me' );
	pp_check( 403 === $st1 && 200 === $st2, 'Inventar aus → /me/items 403, /me bleibt', [ $st1, $st2 ] );
	pp_audit_option( 'pp_features', $features( [ 'lending' => false ] ) );
	[ $st1 ] = pp_rest( $u['a'], 'GET', '/me/rentals' );
	[ $st2 ] = pp_rest( $u['a'], 'GET', '/me/rentals/' . $ra );
	pp_check( 403 === $st1 && 403 === $st2, 'Verleih aus → /me/rentals… 403' );
	pp_audit_option( 'pp_features', null );

	/* ---------- Nur-Lese-Grenze für App-Passwörter ---------- */
	pp_as_app_password( true );
	[ $st ] = pp_rest_any( $u['a'], 'GET', '/project-prepper/v1/me/items' );
	pp_check( 200 === $st, 'App-Passwort Mitglied: GET /me/items erlaubt', $st );
	[ $st ] = pp_rest_any( $u['a'], 'GET', '/wp/v2/users/me' );
	pp_check( 200 === $st, 'App-Passwort Mitglied: GET /wp/v2/users/me erlaubt', $st );
	foreach ( [
		[ 'GET', '/project-prepper/v1/items' ],
		[ 'GET', '/project-prepper/v1/members' ],
		[ 'GET', '/project-prepper/v1/meX' ],
		[ 'GET', '/wp/v2/posts' ],
		[ 'GET', '/wp/v2/users' ],
		[ 'POST', '/wp/v2/users/me' ],
		[ 'POST', '/project-prepper/v1/me' ],
		[ 'DELETE', '/wp/v2/users/me/application-passwords' ],
	] as [ $m, $r ] ) {
		[ $st, $err ] = pp_rest_any( $u['a'], $m, $r, 'POST' === $m ? [ 'name' => 'x' ] : null );
		pp_check( 403 === $st && in_array( $err['code'] ?? '', [ 'pp_api_read_only', 'pp_api_manage_in_portal' ], true ), "App-Passwort Mitglied: {$m} {$r} gesperrt", [ $st, $err['code'] ?? null ] );
	}
	$name_before = get_userdata( $u['a'] )->display_name;
	pp_rest_any( $u['a'], 'POST', '/wp/v2/users/me', [ 'name' => 'Gekapert' ] );
	clean_user_cache( $u['a'] );
	pp_check( get_userdata( $u['a'] )->display_name === $name_before, 'App-Passwort Mitglied: Profil unverändert' );
	[ $st ] = pp_rest_any( $u['m'], 'GET', '/project-prepper/v1/items' );
	pp_check( 200 === $st, 'App-Passwort Manager: Admin-Route /items wie bisher', $st );
	pp_as_app_password( false );
	[ $st, $err ] = pp_rest_any( $u['a'], 'POST', '/wp/v2/users/me/application-passwords', [ 'name' => 'x' ] );
	pp_check( 403 === $st && 'pp_api_manage_in_portal' === ( $err['code'] ?? '' ), 'Mitglied (Cookie): Core-Route zum Anlegen gesperrt → Portal', [ $st, $err['code'] ?? null ] );
	// ACC-API-01: Core vergleicht Routen ohne Groß-/Kleinschreibung.
	foreach ( [ '/wp/v2/users/me/Application-Passwords', '/wp/v2/USERS/me/application-passwords', '/WP/v2/users/' . $u['a'] . '/APPLICATION-PASSWORDS' ] as $r ) {
		[ $st ] = pp_rest_any( $u['a'], 'POST', $r, [ 'name' => 'x' ] );
		pp_check( 403 === $st, "Mitglied (Cookie): POST {$r} gesperrt", $st );
	}
	pp_check( [] === WP_Application_Passwords::get_user_application_passwords( $u['a'] ), 'Mitglied: über Core-Routen nichts angelegt' );
	pp_check( ! user_can( $u['a'], 'create_app_password', $u['a'] ) && ! user_can( $u['a'], 'delete_app_passwords', $u['a'] ), 'Mitglied: Core-Recht create/delete_app_password(s) gesperrt' );
	pp_check( user_can( 1, 'create_app_password', $u['a'] ), 'Admin verwaltet Mitglieder-Passwörter im wp-admin weiter' );
	// ACC-API-05: Batch mit gesperrter Unteranfrage → sauberes 207, kein Fatal.
	[ $st, $batch ] = pp_rest_any( $u['a'], 'POST', '/batch/v1', [ 'requests' => [ [ 'method' => 'POST', 'path' => '/wp/v2/users/me/application-passwords', 'body' => [ 'name' => 'x' ] ] ] ] );
	pp_check( 207 === $st && 403 === ( $batch['responses'][0]['status'] ?? 0 ), 'Batch auf gesperrte Route: 207 mit 403 innen', [ $st, $batch['responses'][0]['status'] ?? null ] );

	/* ---------- Portal: anlegen, einmal anzeigen, widerrufen ---------- */
	$msg = pp_dispatch( $u['a'], [ 'pp_do' => 'api_password_create', 'pp_name' => PP_AUDIT_PREFIX . ' Dashboard' ] );
	pp_check( 'api_pw_created' === $msg, 'Portal: App-Passwort anlegen', $msg );
	$new = MemberApi::take_new_password( $u['a'] );
	pp_check( is_array( $new ) && strlen( preg_replace( '/\s/', '', $new['pw'] ) ) >= 24, 'Portal: neues Passwort einmal abrufbar' );
	pp_check( null === MemberApi::take_new_password( $u['a'] ), 'Portal: zweites Abrufen liefert nichts' );
	$raw = get_option( '_transient_' . MemberApi::NEW_PW_TRANSIENT . $u['a'] );
	pp_check( false === $raw, 'Portal: kein Rest im Transient' );
	$list = MemberApi::passwords( $u['a'] );
	pp_check( 1 === count( $list ) && PP_AUDIT_PREFIX . ' Dashboard' === $list[0]['name'], 'Portal: Liste zeigt das Passwort' );
	pp_check( 'detail' === pp_dispatch( $u['a'], [ 'pp_do' => 'api_password_create', 'pp_name' => PP_AUDIT_PREFIX . ' Dashboard' ] ), 'Portal: gleicher Name abgelehnt' );
	pp_check( 'detail' === pp_dispatch( $u['a'], [ 'pp_do' => 'api_password_create', 'pp_name' => '   ' ] ), 'Portal: leerer Name abgelehnt' );
	pp_check( 'error' === pp_dispatch( $u['a'], [ 'pp_do' => 'api_password_create', 'pp_name' => 'x' ], false ), 'Portal: falscher Nonce abgelehnt' );
	$uuid = $list[0]['uuid'];
	pp_check( 'forbidden' === pp_dispatch( $u['b'], [ 'pp_do' => 'api_password_revoke', 'pp_uuid' => $uuid ] ), 'Portal: B kann A\'s Passwort nicht widerrufen' );
	pp_check( 1 === count( MemberApi::passwords( $u['a'] ) ), 'Portal: A\'s Passwort besteht noch' );

	/* ---------- Echte Basic-Auth über HTTP (wp-env: Dienst „wordpress") ---------- */
	$base = 'http://wordpress/?rest_route=';
	$auth = static fn( string $login, string $pw ) => [ 'Authorization' => 'Basic ' . base64_encode( $login . ':' . $pw ) ]; // phpcs:ignore
	$probe = wp_remote_get( $base . '/', [ 'timeout' => 5 ] );
	if ( is_wp_error( $probe ) ) {
		echo 'SKIP  HTTP-Tests (wordpress-Container nicht erreichbar: ' . $probe->get_error_message() . ")\n";
	} else {
		$h   = $auth( 'zz-audit-api-a', $new['pw'] );
		$get = static fn( string $route, array $hdr ) => wp_remote_get( $base . $route, [ 'headers' => $hdr, 'timeout' => 10 ] );
		$res = $get( '/project-prepper/v1/me/items', $h );
		$ids = pp_ids( json_decode( wp_remote_retrieve_body( $res ) ) ?: [] );
		pp_check( 200 === wp_remote_retrieve_response_code( $res ) && $ids === [ $a1, $a2 ], 'HTTP Basic: A liest /me/items', [ wp_remote_retrieve_response_code( $res ), $ids ] );
		$res = $get( '/project-prepper/v1/me/rentals/' . $rb, $h );
		pp_check( 404 === wp_remote_retrieve_response_code( $res ), 'HTTP Basic: A → B\'s Verleih 404' );
		$res = $get( '/project-prepper/v1/rentals', $h );
		pp_check( 403 === wp_remote_retrieve_response_code( $res ), 'HTTP Basic: A → Admin /rentals 403' );
		$res = wp_remote_post( $base . '/wp/v2/users/me', [ 'headers' => $h + [ 'Content-Type' => 'application/json' ], 'body' => '{"name":"Gekapert"}', 'timeout' => 10 ] );
		pp_check( 403 === wp_remote_retrieve_response_code( $res ), 'HTTP Basic: A → POST /wp/v2/users/me 403' );
		$res = $get( '/wp/v2/users/me', $h );
		pp_check( 200 === wp_remote_retrieve_response_code( $res ) && (int) ( json_decode( wp_remote_retrieve_body( $res ), true )['id'] ?? 0 ) === $u['a'], 'HTTP Basic: /wp/v2/users/me identifiziert A' );
		$res = $get( '/project-prepper/v1/me/items', $auth( 'zz-audit-api-b', $new['pw'] ) );
		pp_check( 401 === wp_remote_retrieve_response_code( $res ) && 'pp_bad_credentials' === ( json_decode( wp_remote_retrieve_body( $res ), true )['code'] ?? '' ), 'HTTP Basic: A\'s Passwort mit B\'s Namen = 401 pp_bad_credentials', wp_remote_retrieve_body( $res ) );
		$res = $get( '/project-prepper/v1/me/items', $auth( 'zz-gibt-es-nicht-4711', 'falsch' ) );
		pp_check( 'pp_bad_credentials' === ( json_decode( wp_remote_retrieve_body( $res ), true )['code'] ?? '' ), 'HTTP Basic: unbekannter Name = pp_bad_credentials (gleiche Meldung, keine Aufzählung)' );
		$res = $get( '/project-prepper/v1/me/items', [] );
		pp_check( 401 === wp_remote_retrieve_response_code( $res ) && 'rest_not_logged_in' === ( json_decode( wp_remote_retrieve_body( $res ), true )['code'] ?? '' ), 'HTTP ohne Zugangsdaten = rest_not_logged_in' );
		pp_clear_http_lock(); // Der Fehlversuch oben zählt für die IP des cli-Containers.
	}

	pp_check( 'api_pw_revoked' === pp_dispatch( $u['a'], [ 'pp_do' => 'api_password_revoke', 'pp_uuid' => $uuid ] ), 'Portal: A widerruft sein Passwort' );
	pp_check( [] === MemberApi::passwords( $u['a'] ), 'Portal: Liste danach leer' );
	if ( ! is_wp_error( $probe ) ) {
		$res = wp_remote_get( $base . '/project-prepper/v1/me', [ 'headers' => $auth( 'zz-audit-api-a', $new['pw'] ), 'timeout' => 10 ] );
		pp_check( 401 === wp_remote_retrieve_response_code( $res ) && 'pp_bad_credentials' === ( json_decode( wp_remote_retrieve_body( $res ), true )['code'] ?? '' ), 'HTTP Basic: widerrufenes Passwort = 401 pp_bad_credentials', wp_remote_retrieve_response_code( $res ) );
		pp_clear_http_lock();
	}

	/* ---------- Portal-Passwort eines Managers ist auch nur-lesend (ACC-API-02/03) ---------- */
	pp_check( 'api_pw_created' === pp_dispatch( $u['m'], [ 'pp_do' => 'api_password_create', 'pp_name' => 'ZZ Manager' ] ), 'Manager legt Portal-Passwort an' );
	MemberApi::take_new_password( $u['m'] );
	$m_pw = MemberApi::passwords( $u['m'] )[0]['uuid'] ?? '';
	$m_wp = WP_Application_Passwords::create_new_application_password( $u['m'], [ 'name' => 'zz wp-admin' ] ); // wie im wp-admin angelegt (ohne Kennung)
	$GLOBALS['wp_rest_application_password_uuid'] = $m_pw;
	[ $st1 ] = pp_rest_any( $u['m'], 'GET', '/project-prepper/v1/items' );
	[ $st2 ] = pp_rest_any( $u['m'], 'GET', '/project-prepper/v1/me/items' );
	[ $st3 ] = pp_rest_any( $u['m'], 'POST', '/wp/v2/users/me', [ 'name' => 'Gekapert' ] );
	pp_check( 403 === $st1 && 200 === $st2 && 403 === $st3, 'Manager mit Portal-Passwort: nur /me lesen', [ $st1, $st2, $st3 ] );
	$GLOBALS['wp_rest_application_password_uuid'] = $m_wp[1]['uuid'];
	[ $st ] = pp_rest_any( $u['m'], 'GET', '/project-prepper/v1/items' );
	pp_check( 200 === $st, 'Manager mit wp-admin-Passwort: Admin-Route wie bisher', $st );
	pp_as_app_password( false );

	/* ---------- Deaktivierung/Deinstallation widerruft, was nur lesen durfte (ACC-API-04) ---------- */
	WP_Application_Passwords::create_new_application_password( $u['a'], [ 'name' => 'zz alt' ] );
	$n_a = MemberApi::revoke_unguarded( $u['a'] );
	$n_m = MemberApi::revoke_unguarded( $u['m'] );
	$left_m = array_column( WP_Application_Passwords::get_user_application_passwords( $u['m'] ), 'name' );
	pp_check( 1 === $n_a && [] === WP_Application_Passwords::get_user_application_passwords( $u['a'] ), 'Widerruf: Mitglied verliert alle Passwörter', $n_a );
	pp_check( 1 === $n_m && [ 'zz wp-admin' ] === $left_m, 'Widerruf: Manager verliert nur das Portal-Passwort', [ $n_m, $left_m ] );

	/* ---------- Abonnent ohne Project-Prepper-Rolle (ACC-API-07) ---------- */
	pp_check( false === wp_is_application_passwords_available_for_user( get_userdata( $u['s'] ) ) && '' !== MemberApi::unavailable_reason( get_userdata( $u['s'] ) ), 'Abonnent: keine API-Passwörter' );
	pp_check( 'detail' === pp_dispatch( $u['s'], [ 'pp_do' => 'api_password_create', 'pp_name' => 'x' ] ), 'Abonnent: Portal-Aktion abgelehnt' );

	/* ---------- API aus: sehen + widerrufen geht weiter (ACC-API-06) ---------- */
	pp_dispatch( $u['a'], [ 'pp_do' => 'api_password_create', 'pp_name' => 'ZZ aus' ] );
	MemberApi::take_new_password( $u['a'] );
	$off_uuid = MemberApi::passwords( $u['a'] )[0]['uuid'] ?? '';
	pp_audit_option( 'pp_features', $features( [ 'api' => false ] ) );
	pp_check( false !== strpos( pp_render( $u['a'], 'api', [], true ), 'pp-api__list' ), 'API aus: Seite mit Liste bleibt für Inhaber erreichbar' );
	pp_check( false === strpos( pp_render( $u['c'], 'api', [], true ), 'pp-api__' ) || [] !== MemberApi::passwords( $u['c'] ), 'API aus: ohne Passwörter keine API-Seite' );
	pp_check( 'api_pw_revoked' === pp_dispatch( $u['a'], [ 'pp_do' => 'api_password_revoke', 'pp_uuid' => $off_uuid ] ), 'API aus: Widerrufen klappt' );
	pp_audit_option( 'pp_features', null );

	/* ---------- .htaccess-Block: im CLI nicht als erledigt markieren (v0.149.1) ---------- */
	$perf_before = get_option( \ProjectPrepper\Performance::OPTION_KEY );
	pp_audit_option( \ProjectPrepper\Performance::OPTION_KEY, null );
	delete_option( \ProjectPrepper\Performance::OPTION_KEY );
	\ProjectPrepper\Performance::install();
	pp_check( false === get_option( \ProjectPrepper\Performance::OPTION_KEY ), 'Performance::install() im CLI: Version bleibt offen, Web-Request schreibt den Block' );
	if ( false !== $perf_before ) {
		update_option( \ProjectPrepper\Performance::OPTION_KEY, $perf_before );
	}
	pp_check( in_array( "\t" . 'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1', \ProjectPrepper\Performance::rules(), true ), '.htaccess-Regeln reichen den Authorization-Header durch' );

	/* ---------- Höchstzahl ---------- */
	for ( $i = 0; $i < MemberApi::MAX_PASSWORDS; $i++ ) {
		WP_Application_Passwords::create_new_application_password( $u['c'], [ 'name' => "zz {$i}" ] );
	}
	pp_check( 'detail' === pp_dispatch( $u['c'], [ 'pp_do' => 'api_password_create', 'pp_name' => 'eins zu viel' ] ), 'Portal: Höchstzahl greift' );

	/* ---------- Drosselung ---------- */
	pp_audit_option( 'pp_security', array_merge( \ProjectPrepper\Security::defaults(), [ 'api_rate_limit' => 3 ] ) );
	delete_transient( MemberApi::RATE_TRANSIENT . $u['a'] );
	delete_transient( MemberApi::RATE_TRANSIENT . $u['b'] );
	$codes = [];
	for ( $i = 0; $i < 4; $i++ ) {
		[ $codes[] ] = pp_rest( $u['a'], 'GET', '/me' );
	}
	pp_check( [ 200, 200, 200, 429 ] === $codes, 'Drosselung: 4. Anfrage im Fenster = 429', $codes );
	[ $st ] = pp_rest( $u['b'], 'GET', '/me' );
	pp_check( 200 === $st, 'Drosselung gilt je Nutzer (B unberührt)' );
	delete_transient( MemberApi::RATE_TRANSIENT . $u['a'] );
	delete_transient( MemberApi::RATE_TRANSIENT . $u['b'] );
	pp_audit_option( 'pp_security', null );

	/* ---------- Login-Sperre zählt App-Passwort-Fehlversuche ---------- */
	add_filter( 'application_password_is_api_request', '__return_true' );
	$pw_c = WP_Application_Passwords::create_new_application_password( $u['b'], [ 'name' => 'zz lock' ] );
	\ProjectPrepper\Security::clear_failed_login();
	$max = \ProjectPrepper\Security::int( 'login_max_attempts' );
	for ( $i = 0; $i < $max; $i++ ) {
		wp_authenticate_application_password( null, 'zz-audit-api-b', 'falsch-falsch-falsch-falsch' );
	}
	$locked = wp_authenticate_application_password( null, 'zz-audit-api-b', $pw_c[0] );
	pp_check( is_wp_error( $locked ) && 'pp_locked' === $locked->get_error_code(), 'Sperre: nach Fehlversuchen auch richtiges App-Passwort abgewiesen', is_wp_error( $locked ) ? $locked->get_error_code() : 'ok' );
	\ProjectPrepper\Security::clear_failed_login();
	$ok = wp_authenticate_application_password( null, 'zz-audit-api-b', $pw_c[0] );
	pp_check( $ok instanceof WP_User && (int) $ok->ID === $u['b'], 'Sperre aufgehoben → Anmeldung klappt' );
	// ACC-API-09: gesperrt → verständliche Meldung statt „bitte anmelden".
	for ( $i = 0; $i < $max; $i++ ) {
		\ProjectPrepper\Security::record_failed_login();
	}
	[ $st, $err ] = pp_rest( 0, 'GET', '/me' );
	pp_check( 401 === $st && 'pp_locked' === ( $err['code'] ?? '' ), 'Sperre: /me meldet pp_locked', [ $st, $err['code'] ?? null ] );
	\ProjectPrepper\Security::clear_failed_login();
	remove_filter( 'application_password_is_api_request', '__return_true' );
} catch ( Throwable $e ) {
	pp_check( false, 'Ausnahme: ' . $e->getMessage() );
} finally {
	pp_as_app_password( false );
	wp_set_current_user( 1 );
	$cleaned = pp_audit_cleanup();
	Groups::delete( $group );
	foreach ( $u as $id ) {
		wp_delete_user( $id ); // räumt über MemberApi::forget_user auch die Zähler ab
	}
	$left = array_filter( $u, static fn( $id ) => false !== get_option( '_transient_' . MemberApi::RATE_TRANSIENT . $id ) );
	pp_check( ! $left, 'Nutzer gelöscht → keine API-Zähler übrig', array_keys( $left ) );
	// Protokollzeilen der Wegwerf-Nutzer (Anlegen/Widerrufen/Drosselung, Gruppe) mit abräumen.
	global $wpdb;
	$in = implode( ',', array_map( 'intval', $u ) );
	$wpdb->query( "DELETE FROM `" . \ProjectPrepper\Schema::table( 'activity_log' ) . "` WHERE actor_id IN ({$in}) OR ( entity_type = 'user' AND entity_id IN ({$in}) ) OR ( entity_type = 'group' AND entity_id = " . (int) $group . ' )' ); // phpcs:ignore
	echo 'Aufgeräumt: ' . wp_json_encode( $cleaned ) . "\n";
}

echo "\n{$GLOBALS['pp_pass']} bestanden, {$GLOBALS['pp_fail']} fehlgeschlagen\n";
exit( $GLOBALS['pp_fail'] > 0 ? 1 : 0 );
