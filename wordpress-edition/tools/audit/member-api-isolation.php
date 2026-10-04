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
use ProjectPrepper\Services\MemberInventory;
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

		// v0.150.0: Schreiben nur mit „Lesen + eigenes Equipment bearbeiten".
		$post = static fn( string $route, array $hdr, array $body, string $method = 'POST' ) => wp_remote_request( $base . $route, [
			'method' => $method, 'headers' => $hdr + [ 'Content-Type' => 'application/json' ], 'body' => wp_json_encode( $body ), 'timeout' => 10,
		] );
		$res = $post( '/project-prepper/v1/me/items', $h, [ 'name' => PP_AUDIT_PREFIX . ' HTTP RO' ] );
		pp_check( 403 === wp_remote_retrieve_response_code( $res ) && 'pp_api_read_only' === ( json_decode( wp_remote_retrieve_body( $res ), true )['code'] ?? '' ), 'HTTP Basic: Nur-Lese-Passwort → POST /me/items 403' );
		pp_dispatch( $u['a'], [ 'pp_do' => 'api_password_create', 'pp_name' => 'ZZ HTTP Schreiben', 'pp_scope' => 'inventory' ] );
		$wnew = MemberApi::take_new_password( $u['a'] );
		$hw   = $auth( 'zz-audit-api-a', (string) ( $wnew['pw'] ?? '' ) );
		$res  = $post( '/project-prepper/v1/me/items', $hw, [ 'name' => PP_AUDIT_PREFIX . ' HTTP neu', 'quantity' => 2 ] );
		$made = json_decode( wp_remote_retrieve_body( $res ), true );
		$hid  = pp_audit_track( 'items', (int) ( $made['id'] ?? 0 ) );
		pp_check( 201 === wp_remote_retrieve_response_code( $res ) && MemberInventory::owns( $u['a'], $hid ), 'HTTP Basic: Schreib-Passwort → POST /me/items 201, gehört A', wp_remote_retrieve_body( $res ) );
		$res = $post( '/project-prepper/v1/me/items/' . $hid, $hw, [ 'location' => 'HTTP-Halle', 'expect' => (string) ( $made['updated_at'] ?? '' ) ], 'PUT' );
		pp_check( 200 === wp_remote_retrieve_response_code( $res ) && 'HTTP-Halle' === \ProjectPrepper\Services\Inventory::get_item( $hid )->location, 'HTTP Basic: Schreib-Passwort → PUT /me/items/{id} 200' );
		$res = $post( '/project-prepper/v1/me/items/' . $b1, $hw, [ 'name' => 'Gekapert' ], 'PUT' );
		pp_check( 404 === wp_remote_retrieve_response_code( $res ), 'HTTP Basic: Schreib-Passwort → PUT auf B\'s Artikel 404' );
		$res = $post( '/wp/v2/users/me', $hw, [ 'name' => 'Gekapert' ] );
		pp_check( 403 === wp_remote_retrieve_response_code( $res ), 'HTTP Basic: Schreib-Passwort → POST /wp/v2/users/me 403' );
		$res = $post( '/project-prepper/v1/me/items/' . $hid, $hw, [], 'DELETE' );
		pp_check( 403 === wp_remote_retrieve_response_code( $res ) && null !== \ProjectPrepper\Services\Inventory::get_item( $hid ), 'HTTP Basic: Schreib-Passwort → DELETE 403' );
		$wuuid = array_values( array_filter( MemberApi::passwords( $u['a'] ), static fn( $p ) => 'ZZ HTTP Schreiben' === $p['name'] ) )[0]['uuid'] ?? '';
		pp_dispatch( $u['a'], [ 'pp_do' => 'api_password_revoke', 'pp_uuid' => $wuuid ] );

		// ACC-W-02: Portal-Passwörter (auch von Managern) nie über XML-RPC; wp-admin-Passwort unverändert.
		$xml = static fn( string $login, string $pw ) => wp_remote_post( 'http://wordpress/xmlrpc.php', [ 'timeout' => 10, 'headers' => [ 'Content-Type' => 'text/xml' ],
			'body' => '<?xml version="1.0"?><methodCall><methodName>wp.getProfile</methodName><params><param><value><int>1</int></value></param>'
				. '<param><value><string>' . esc_xml( $login ) . '</string></value></param><param><value><string>' . esc_xml( $pw ) . '</string></value></param></params></methodCall>' ] );
		foreach ( [ 'read', 'inventory' ] as $scope ) {
			pp_dispatch( $u['m'], [ 'pp_do' => 'api_password_create', 'pp_name' => 'ZZ XMLRPC ' . $scope, 'pp_scope' => $scope ] );
			$mpw  = (string) ( MemberApi::take_new_password( $u['m'] )['pw'] ?? '' );
			$body = wp_remote_retrieve_body( $xml( 'zz-audit-api-m', $mpw ) );
			pp_check( false !== strpos( $body, '<fault>' ) && false === strpos( $body, 'zz-audit-api-m' ), "W-02: Manager-Portal-Passwort ({$scope}) über XML-RPC abgewiesen", substr( $body, 0, 200 ) );
			$muuid = array_values( array_filter( MemberApi::passwords( $u['m'] ), static fn( $p ) => 'ZZ XMLRPC ' . $scope === $p['name'] ) )[0]['uuid'] ?? '';
			pp_dispatch( $u['m'], [ 'pp_do' => 'api_password_revoke', 'pp_uuid' => $muuid ] );
			pp_clear_http_lock();
		}
		$m_adm = WP_Application_Passwords::create_new_application_password( $u['m'], [ 'name' => 'zz xmlrpc wp-admin' ] ); // wie im wp-admin angelegt
		$body  = wp_remote_retrieve_body( $xml( 'zz-audit-api-m', (string) $m_adm[0] ) );
		pp_check( false === strpos( $body, '<fault>' ), 'W-02: wp-admin-Passwort des Managers über XML-RPC wie bisher', substr( $body, 0, 200 ) );
		WP_Application_Passwords::delete_application_password( $u['m'], (string) $m_adm[1]['uuid'] );
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

	/* ---------- Schreiben: eigenes Equipment (v0.150.0) ---------- */
	// Nur-Lese-Passwort (Mitglied, beliebige Kennung) darf nicht schreiben.
	pp_as_app_password( true );
	[ $st, $err ] = pp_rest_any( $u['a'], 'POST', '/project-prepper/v1/me/items', [ 'name' => PP_AUDIT_PREFIX . ' RO' ] );
	pp_check( 403 === $st && 'pp_api_read_only' === ( $err['code'] ?? '' ), 'Nur-Lese-Passwort: POST /me/items gesperrt', [ $st, $err['code'] ?? null ] );
	$GLOBALS['wp_rest_application_password_uuid'] = $m_pw; // Portal-Passwort „nur lesen" des Managers
	[ $st ] = pp_rest_any( $u['m'], 'POST', '/project-prepper/v1/me/items', [ 'name' => PP_AUDIT_PREFIX . ' RO-M' ] );
	pp_check( 403 === $st, 'Manager mit Nur-Lese-Portal-Passwort: POST /me/items gesperrt', $st );
	pp_as_app_password( false );
	pp_check( 'read' === ( MemberApi::passwords( $u['m'] )[0]['scope'] ?? '' ) || 'read' === ( MemberApi::passwords( $u['m'] )[1]['scope'] ?? '' ), 'Liste: Portal-Passwort ohne Wahl = read' );

	// Passwort „Lesen + eigenes Equipment bearbeiten" im Portal anlegen.
	pp_check( 'api_pw_created' === pp_dispatch( $u['a'], [ 'pp_do' => 'api_password_create', 'pp_name' => 'ZZ Schreiben', 'pp_scope' => 'inventory' ] ), 'Portal: Schreib-Passwort anlegen' );
	MemberApi::take_new_password( $u['a'] );
	$w_row = array_values( array_filter( MemberApi::passwords( $u['a'] ), static fn( $p ) => 'ZZ Schreiben' === $p['name'] ) )[0] ?? [];
	pp_check( 'inventory' === ( $w_row['scope'] ?? '' ), 'Liste: Zugriffsart inventory', $w_row );
	pp_check( 'api_pw_created' === pp_dispatch( $u['a'], [ 'pp_do' => 'api_password_create', 'pp_name' => 'ZZ Unbekannt', 'pp_scope' => 'admin' ] ), 'Portal: unbekannte Zugriffsart angelegt …' );
	MemberApi::take_new_password( $u['a'] );
	$x_row = array_values( array_filter( MemberApi::passwords( $u['a'] ), static fn( $p ) => 'ZZ Unbekannt' === $p['name'] ) )[0] ?? [];
	pp_check( 'read' === ( $x_row['scope'] ?? '' ), '… und nur lesend', $x_row );
	pp_dispatch( $u['a'], [ 'pp_do' => 'api_password_revoke', 'pp_uuid' => $x_row['uuid'] ?? '' ] );

	$cat_a = MemberInventory::create_category( $u['a'], [ 'name' => PP_AUDIT_PREFIX . ' Kat A' ] );
	$cat_b = MemberInventory::create_category( $u['b'], [ 'name' => PP_AUDIT_PREFIX . ' Kat B' ] );
	pp_check( is_int( $cat_a ) && is_int( $cat_b ), 'Kategorien für A und B angelegt', [ $cat_a, $cat_b ] );
	$GLOBALS['wp_rest_application_password_uuid'] = $w_row['uuid'] ?? '';
	$W = static fn( string $m, string $r, ?array $b = null ) => pp_rest_any( $u['a'], $m, '/project-prepper/v1' . $r, $b );

	[ $st ] = $W( 'GET', '/me/items' );
	pp_check( 200 === $st, 'Schreib-Passwort: GET /me/items', $st );
	[ $st, $cats ] = $W( 'GET', '/me/categories' );
	pp_check( 200 === $st && [ (int) $cat_a ] === array_map( static fn( $c ) => (int) $c['id'], (array) $cats ), 'GET /me/categories: nur eigene', [ $st, $cats ] );

	[ $st, $it ] = $W( 'POST', '/me/items', [ 'name' => PP_AUDIT_PREFIX . ' Neu', 'quantity' => 3, 'cost_per_day' => 12.5, 'condition' => 'fair',
		'location' => 'Lager', 'tags' => [ 'x' ], 'owner_user_id' => $u['b'], 'image_id' => 1, 'category_id' => $cat_a ] );
	$new_id = pp_audit_track( 'items', (int) ( $it->id ?? $it['id'] ?? 0 ) );
	$row    = MemberInventory::owns( $u['a'], $new_id ) ? \ProjectPrepper\Services\Inventory::get_item( $new_id ) : null;
	pp_check( 201 === $st && $row, 'POST /me/items: angelegt, Besitzer = A (owner_user_id im Body ignoriert)', [ $st, $it ] );
	pp_check( $row && 3 === (int) $row->quantity && 'fair' === $row->item_condition && (int) $row->category_id === (int) $cat_a && empty( $row->image_id ), 'POST /me/items: Felder übernommen, image_id ignoriert', $row );
	pp_check( $row && '' !== (string) $row->inventory_number, 'POST /me/items: Inventarnummer automatisch', $row->inventory_number ?? null );

	[ $st, $err ] = $W( 'POST', '/me/items', [ 'quantity' => 1 ] );
	pp_check( 400 === $st && 'pp_missing_name' === ( $err['code'] ?? '' ), 'POST ohne Namen → 400', [ $st, $err['code'] ?? null ] );
	[ $st, $err ] = $W( 'POST', '/me/items', [ 'name' => PP_AUDIT_PREFIX . ' FremdKat', 'category_id' => $cat_b ] );
	pp_check( 400 === $st && 'pp_bad_category' === ( $err['code'] ?? '' ), 'POST mit fremder Kategorie → 400', [ $st, $err['code'] ?? null ] );
	[ $st, $err ] = $W( 'POST', '/me/items', [ 'name' => PP_AUDIT_PREFIX . ' Zustand', 'condition' => 'kaputt' ] );
	pp_check( 400 === $st && 'pp_bad_condition' === ( $err['code'] ?? '' ), 'POST mit unbekanntem Zustand → 400', [ $st, $err['code'] ?? null ] );
	$b2_nr = (string) \ProjectPrepper\Services\Inventory::get_item( $b2 )->inventory_number;
	[ $st, $err ] = $W( 'POST', '/me/items', [ 'name' => PP_AUDIT_PREFIX . ' Doppelt', 'inventory_number' => $b2_nr ] );
	pp_check( 409 === $st && 'pp_number_taken' === ( $err['code'] ?? '' ), 'POST mit vergebener Inventarnummer → 409', [ $st, $err['code'] ?? null ] );
	[ $st, $err ] = $W( 'POST', '/me/items', [] );
	pp_check( 400 === $st, 'POST ohne Body → 400', $st );

	[ $st, $it ] = $W( 'PUT', '/me/items/' . $new_id, [ 'location' => 'Halle' ] );
	$row = \ProjectPrepper\Services\Inventory::get_item( $new_id );
	pp_check( 200 === $st && 'Halle' === $row->location && PP_AUDIT_PREFIX . ' Neu' === $row->name && 3 === (int) $row->quantity, 'PUT: nur mitgeschickte Felder geändert', [ $st, $row->location ?? null ] );
	[ $st, $err ] = $W( 'PUT', '/me/items/' . $new_id, [ 'location' => 'Alt', 'expect' => '2000-01-01 00:00:00' ] );
	pp_check( 409 === $st && 'pp_stale' === ( $err['code'] ?? '' ) && 'Halle' === \ProjectPrepper\Services\Inventory::get_item( $new_id )->location, 'PUT mit veraltetem expect → 409, nichts überschrieben', [ $st, $err['code'] ?? null ] );
	[ $st ] = $W( 'PUT', '/me/items/' . $new_id, [ 'location' => 'Bühne', 'expect' => (string) $row->updated_at ] );
	pp_check( 200 === $st && 'Bühne' === \ProjectPrepper\Services\Inventory::get_item( $new_id )->location, 'PUT mit aktuellem expect → 200', $st );
	[ $st ] = $W( 'PATCH', '/me/items/' . $new_id, [ 'quantity' => 0 ] );
	pp_check( 200 === $st && 1 === (int) \ProjectPrepper\Services\Inventory::get_item( $new_id )->quantity, 'PATCH: Menge 0 bei Gerät → 1 (wie im Portal)', $st );
	[ $st, $err ] = $W( 'PUT', '/me/items/' . $new_id, [ 'name' => '  ' ] );
	pp_check( 400 === $st, 'PUT mit leerem Namen → 400', $st );
	$b1_before = \ProjectPrepper\Services\Inventory::get_item( $b1 );
	[ $st, $err ] = $W( 'PUT', '/me/items/' . $b1, [ 'name' => 'Gekapert', 'location' => 'weg' ] );
	pp_check( 404 === $st && 'pp_not_found' === ( $err['code'] ?? '' ), 'PUT auf B\'s Artikel → 404', [ $st, $err['code'] ?? null ] );
	[ $st ] = $W( 'PUT', '/me/items/999999999', [ 'name' => 'x' ] );
	pp_check( 404 === $st, 'PUT auf unbekannte ID → 404', $st );
	pp_check( \ProjectPrepper\Services\Inventory::get_item( $b1 )->name === $b1_before->name && \ProjectPrepper\Services\Inventory::get_item( $b1 )->location === $b1_before->location, 'B\'s Artikel unverändert' );
	foreach ( [
		[ 'DELETE', '/project-prepper/v1/me/items/' . $new_id ],
		[ 'POST', '/project-prepper/v1/me/items/' . $new_id ],
		[ 'PUT', '/project-prepper/v1/me/items' ],
		[ 'POST', '/project-prepper/v1/items' ],
		[ 'PUT', '/project-prepper/v1/items/' . $new_id ],
		[ 'POST', '/project-prepper/v1/items/' . $new_id . '/image' ],
		[ 'POST', '/project-prepper/v1/me/rentals' ],
		[ 'POST', '/project-prepper/v1/me' ],
		[ 'POST', '/wp/v2/users/me' ],
		[ 'POST', '/wp/v2/users/me/application-passwords' ],
	] as [ $m, $r ] ) {
		[ $st ] = pp_rest_any( $u['a'], $m, $r, [ 'name' => 'Gekapert' ] );
		pp_check( 403 === $st, "Schreib-Passwort: {$m} {$r} gesperrt", $st );
	}
	pp_check( null !== \ProjectPrepper\Services\Inventory::get_item( $new_id ), 'Schreib-Passwort: Löschen nicht möglich' );
	clean_user_cache( $u['a'] );
	pp_check( get_userdata( $u['a'] )->display_name === $name_before, 'Schreib-Passwort: Profil unverändert' );
	[ $st ] = pp_rest_any( $u['a'], 'POST', '/batch/v1', [ 'requests' => [ [ 'method' => 'DELETE', 'path' => '/project-prepper/v1/me/items/' . $new_id ] ] ] );
	pp_check( null !== \ProjectPrepper\Services\Inventory::get_item( $new_id ), 'Schreib-Passwort: Batch löscht nichts', $st );
	pp_audit_option( 'pp_features', $features( [ 'inventory' => false ] ) );
	[ $st, $err ] = $W( 'POST', '/me/items', [ 'name' => PP_AUDIT_PREFIX . ' Aus' ] );
	pp_check( 403 === $st && 'pp_feature_off' === ( $err['code'] ?? '' ), 'Inventar aus → POST /me/items 403', [ $st, $err['code'] ?? null ] );
	pp_audit_option( 'pp_features', null );
	pp_audit_option( 'pp_features', $features( [ 'api' => false ] ) );
	[ $st ] = $W( 'PUT', '/me/items/' . $new_id, [ 'location' => 'aus' ] );
	pp_check( 403 === $st, 'API aus → PUT /me/items 403', $st );
	pp_audit_option( 'pp_features', null );
	pp_as_app_password( false );

	// Angemeldet im Portal (Cookie) geht es auch — wie „Mein Inventar".
	[ $st, $it ] = pp_rest( $u['a'], 'POST', '/me/items', [ 'name' => PP_AUDIT_PREFIX . ' Cookie' ] );
	pp_audit_track( 'items', (int) ( $it->id ?? 0 ) );
	pp_check( 201 === $st, 'Cookie: POST /me/items', $st );
	[ $st ] = pp_rest( $u['s'], 'POST', '/me/items', [ 'name' => PP_AUDIT_PREFIX . ' Abo' ] );
	pp_check( 403 === $st, 'Abonnent ohne Prepper-Rolle: POST /me/items 403', $st );

	/* ---------- Funde des Zugriffs-Audits v0.150.0 (ACC-W-01/03, C1/C3/C6) ---------- */
	$GLOBALS['wp_rest_application_password_uuid'] = $w_row['uuid'] ?? '';
	$nr_alt = (string) \ProjectPrepper\Services\Inventory::get_item( $new_id )->inventory_number;
	[ $st, $err ] = $W( 'PUT', '/me/items/' . $new_id, [ 'inventory_number' => 'ZZ-ANDERS-1' ] );
	pp_check( 400 === $st && 'pp_number_fixed' === ( $err['code'] ?? '' ) && $nr_alt === \ProjectPrepper\Services\Inventory::get_item( $new_id )->inventory_number, 'C1: Inventarnummer per PUT nicht änderbar', [ $st, $err['code'] ?? null ] );
	[ $st ] = $W( 'PUT', '/me/items/' . $new_id, [ 'inventory_number' => $nr_alt, 'location' => 'gleiche Nr' ] );
	pp_check( 200 === $st, 'C1: gleiche Inventarnummer mitschicken geht', $st );
	[ $st, $err ] = $W( 'POST', '/me/items', [ 'name' => PP_AUDIT_PREFIX . ' Lang', 'inventory_number' => 'ZZAUDN-99999999999999999999' ] );
	pp_check( 400 === $st && 'pp_bad_number' === ( $err['code'] ?? '' ), 'W-01: überlange Nummer per API abgelehnt', [ $st, $err['code'] ?? null ] );
	[ $st, $err ] = $W( 'POST', '/me/items', [ 'name' => PP_AUDIT_PREFIX . ' Neg', 'category_id' => -5 ] );
	pp_check( 400 === $st && 'pp_bad_category' === ( $err['code'] ?? '' ), 'C3: negative Kategorie → 400', [ $st, $err['code'] ?? null ] );
	[ $st, $it ] = $W( 'POST', '/me/items', [ 'name' => PP_AUDIT_PREFIX . ' Array', 'location' => [ 'x' ], 'notes' => [ 'a' => 1 ] ] );
	$arr_id = pp_audit_track( 'items', (int) ( is_object( $it ) ? $it->id : 0 ) );
	pp_check( 201 === $st && '' === (string) \ProjectPrepper\Services\Inventory::get_item( $arr_id )->location, 'C6: Array in Textfeld wird ignoriert', [ $st ] );
	pp_as_app_password( false );
	// W-01: Nummernkreis überspringt überlange Endungen (z. B. aus einem Import).
	global $wpdb;
	$wpdb->insert( \ProjectPrepper\Schema::table( 'categories' ), [ 'name' => PP_AUDIT_PREFIX . ' Präfix', 'prefix' => 'ZZAUDX', 'owner_user_id' => $u['a'], 'created_at' => current_time( 'mysql' ) ] );
	$cat_x = (int) $wpdb->insert_id;
	wp_set_current_user( $u['a'] );
	pp_audit_track( 'items', \ProjectPrepper\Services\Inventory::create_item( [ 'name' => PP_AUDIT_PREFIX . ' N7', 'inventory_number' => 'ZZAUDX-0007', 'owner_user_id' => $u['a'] ] ) );
	pp_audit_track( 'items', \ProjectPrepper\Services\Inventory::create_item( [ 'name' => PP_AUDIT_PREFIX . ' Gift', 'inventory_number' => 'ZZAUDX-99999999999999999999', 'owner_user_id' => $u['a'] ] ) );
	$nx = \ProjectPrepper\Services\Numbering::next_inventory_number( $cat_x );
	pp_check( 'ZZAUDX-0008' === $nx, 'W-01: Nummernkreis ignoriert überlange Nummer', $nx );
	$n8 = MemberInventory::create( $u['a'], [ 'name' => PP_AUDIT_PREFIX . ' N8', 'category_id' => $cat_x ] );
	pp_audit_track( 'items', is_int( $n8 ) ? $n8 : 0 );
	pp_check( is_int( $n8 ) && 'ZZAUDX-0008' === \ProjectPrepper\Services\Inventory::get_item( $n8 )->inventory_number, 'W-01: automatisches Anlegen klappt danach weiter', $n8 );
	// W-03: fremde Kategorie auch über Portal und Service abgelehnt, Vorlage weiter erlaubt.
	$msg = pp_dispatch( $u['a'], [ 'pp_do' => 'item_update', 'pp_item' => $a2, 'pp_name' => PP_AUDIT_PREFIX . ' A2 privat', 'pp_category' => $cat_b, 'pp_quantity' => 1, 'pp_condition' => 'good' ] );
	pp_check( 'item_saved' !== $msg && (int) \ProjectPrepper\Services\Inventory::get_item( $a2 )->category_id !== (int) $cat_b, 'W-03: Portal item_update mit fremder Kategorie abgelehnt', $msg );
	$fremd = MemberInventory::create( $u['a'], [ 'name' => PP_AUDIT_PREFIX . ' Fremd', 'category_id' => $cat_b ] );
	pp_audit_track( 'items', is_int( $fremd ) ? $fremd : 0 );
	pp_check( is_wp_error( $fremd ) && 'pp_bad_category' === $fremd->get_error_code(), 'W-03: Service create mit fremder Kategorie abgelehnt' );
	$wpdb->insert( \ProjectPrepper\Schema::table( 'categories' ), [ 'name' => PP_AUDIT_PREFIX . ' Vorlage', 'prefix' => 'ZZAUDV', 'owner_user_id' => null, 'created_at' => current_time( 'mysql' ) ] );
	$cat_v = (int) $wpdb->insert_id;
	$vor = MemberInventory::create( $u['a'], [ 'name' => PP_AUDIT_PREFIX . ' Vorlage', 'category_id' => $cat_v ] );
	pp_audit_track( 'items', is_int( $vor ) ? $vor : 0 );
	pp_check( is_int( $vor ), 'W-03: Vorlage-Kategorie bleibt im Service erlaubt (Portal-Bestand)', $vor );
	$wpdb->delete( \ProjectPrepper\Schema::table( 'categories' ), [ 'id' => $cat_x ] );
	$wpdb->delete( \ProjectPrepper\Schema::table( 'categories' ), [ 'id' => $cat_v ] );

	// Aufräumen dieses Abschnitts: Schreib-Passwort widerrufen (sonst zählt der Widerruf-Test unten mit), Kategorien weg.
	pp_check( 'api_pw_revoked' === pp_dispatch( $u['a'], [ 'pp_do' => 'api_password_revoke', 'pp_uuid' => $w_row['uuid'] ?? '' ] ), 'Schreib-Passwort widerrufen' );
	foreach ( [ [ $u['a'], $cat_a ], [ $u['b'], $cat_b ] ] as [ $cu, $cc ] ) {
		if ( is_int( $cc ) ) {
			wp_set_current_user( $cu );
			MemberInventory::delete_category( $cu, $cc );
		}
	}

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
