<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Persönliches Inventar der Mitglieder (Member-Portal Phase 3).
 *
 * Jedes Mitglied verwaltet sein EIGENES Inventar (owner_user_id) im Frontend und
 * teilt es in Kollektive (Gruppen), in denen es Mitglied ist. Dies ist die
 * scoped Schicht über dem (site-weiten) Inventory-Service: jede Methode prüft
 * Eigentum bzw. Mitgliedschaft selbst (RLS-Ersatz, security by design — kein
 * Zugriff auf fremde Items).
 */
class MemberInventory {

	/* ===================== Eigenes Inventar ===================== */

	/**
	 * @param string $search Optionale Volltextsuche (Name/Nummer/Hersteller/Tags …).
	 * @return array<object> Items mit owner_user_id = $user_id.
	 */
	public static function my_items( int $user_id, string $search = '' ): array {
		if ( ! $user_id ) {
			return [];
		}
		$args = [ 'owner_user_id' => $user_id ];
		if ( '' !== trim( $search ) ) {
			$args['search'] = trim( $search );
		}
		return Inventory::items( $args );
	}

	public static function owns( int $user_id, int $item_id ): bool {
		$item = Inventory::get_item( $item_id );
		return $item && (int) ( $item->owner_user_id ?? 0 ) === $user_id && $user_id > 0;
	}

	/**
	 * Neues eigenes Item anlegen.
	 *
	 * @return int|WP_Error Item-ID.
	 */
	public static function create( int $user_id, array $data ) {
		if ( ! $user_id || ! current_user_can( Capabilities::COLLECTIVES ) ) {
			return new WP_Error( 'pp_forbidden', __( 'You are not allowed to add inventory.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'pp_missing_name', __( 'Please enter a name for the item.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$data['owner_user_id'] = $user_id;
		$id = Inventory::create_item( $data );
		if ( ! $id ) {
			return new WP_Error( 'pp_create_failed', __( 'The item could not be saved.', 'project-prepper' ), [ 'status' => 500 ] );
		}
		ActivityLog::log( 'member_item_created', 'item', $id, [ 'owner' => $user_id ] );
		return $id;
	}

	/**
	 * Eigenes Item ändern (nur Owner).
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $user_id, int $item_id, array $data ) {
		if ( ! self::owns( $user_id, $item_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This item is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'pp_missing_name', __( 'Please enter a name for the item.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		// owner_user_id NICHT überschreibbar machen — bleibt beim Owner.
		unset( $data['owner_user_id'] );
		Inventory::update_item( $item_id, $data );
		return true;
	}

	/**
	 * Foto eines eigenen Items setzen (Attachment-ID) oder entfernen (null).
	 * Nur der Owner. KEINE Name-Pflicht (anders als update()).
	 *
	 * @return true|WP_Error
	 */
	public static function set_image( int $user_id, int $item_id, ?int $attachment_id ) {
		if ( ! self::owns( $user_id, $item_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This item is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		Inventory::update_item( $item_id, [ 'image_id' => $attachment_id ] );
		return true;
	}

	/**
	 * Dokument (PDF/Bild) an ein eigenes Item anhängen. Nur Owner.
	 *
	 * @return true|WP_Error
	 */
	public static function add_document( int $user_id, int $item_id, int $attachment_id ) {
		if ( ! self::owns( $user_id, $item_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This item is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$item = Inventory::get_item( $item_id );
		$ids  = array_map( 'intval', (array) ( $item->document_ids ?? [] ) );
		if ( ! in_array( $attachment_id, $ids, true ) ) {
			$ids[] = $attachment_id;
		}
		Inventory::update_item( $item_id, [ 'document_ids' => array_values( $ids ) ] );
		return true;
	}

	/**
	 * Dokument von einem eigenen Item entfernen (nur aus der Liste — das
	 * Attachment selbst bleibt in der Mediathek). Nur Owner.
	 *
	 * @return true|WP_Error
	 */
	public static function remove_document( int $user_id, int $item_id, int $attachment_id ) {
		if ( ! self::owns( $user_id, $item_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This item is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$item = Inventory::get_item( $item_id );
		$ids  = array_values( array_filter(
			array_map( 'intval', (array) ( $item->document_ids ?? [] ) ),
			static fn( $id ) => $id !== $attachment_id
		) );
		Inventory::update_item( $item_id, [ 'document_ids' => $ids ] );
		return true;
	}

	/**
	 * Eigenes Item löschen (nur Owner) inkl. seiner Gruppen-Freigaben.
	 *
	 * @return true|WP_Error
	 */
	public static function delete( int $user_id, int $item_id ) {
		global $wpdb;
		if ( ! self::owns( $user_id, $item_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This item is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$wpdb->delete( Schema::table( 'item_group_shares' ), [ 'item_id' => $item_id ], [ '%d' ] );
		Inventory::delete_item( $item_id );
		ActivityLog::log( 'member_item_deleted', 'item', $item_id, [ 'owner' => $user_id ] );
		return true;
	}

	/* ===================== Kategorien (eigene + Vorlagen) ===================== */

	/**
	 * Eigene Kategorien des Mitglieds (owner_user_id = $user_id).
	 *
	 * @return array<object>
	 */
	public static function own_categories( int $user_id ): array {
		global $wpdb;
		if ( ! $user_id ) {
			return [];
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE owner_user_id = %d ORDER BY sort_order ASC, name ASC',
				Schema::table( 'categories' ),
				$user_id
			)
		) ?: [];
	}

	/**
	 * Vom Betreiber gepflegte Vorlagen-Kategorien (Vorschläge, docs/06 §10.3).
	 *
	 * @return array<object>
	 */
	public static function template_categories(): array {
		return Inventory::template_categories();
	}

	/**
	 * Eigene Kategorie anlegen.
	 *
	 * @return int|WP_Error Kategorie-ID.
	 */
	public static function create_category( int $user_id, array $data ) {
		if ( ! $user_id || ! current_user_can( Capabilities::COLLECTIVES ) ) {
			return new WP_Error( 'pp_forbidden', __( 'You are not allowed to manage categories.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'pp_missing_name', __( 'Please enter a category name.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		global $wpdb;
		if ( self::has_own_category_named( $user_id, $name ) ) {
			return new WP_Error( 'pp_dup_category', __( 'You already have a category with this name.', 'project-prepper' ), [ 'status' => 409 ] );
		}
		$wpdb->insert( Schema::table( 'categories' ), [
			'name'          => $name,
			'icon'          => sanitize_text_field( (string) ( $data['icon'] ?? '' ) ),
			'prefix'        => strtoupper( sanitize_text_field( (string) ( $data['prefix'] ?? '' ) ) ),
			'sort_order'    => 0,
			'owner_user_id' => $user_id,
			'created_at'    => current_time( 'mysql' ),
		], [ '%s', '%s', '%s', '%d', '%d', '%s' ] );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Vorlagen-Kategorie als eigene übernehmen (Kopie).
	 *
	 * @return int|WP_Error Neue eigene Kategorie-ID.
	 */
	public static function adopt_template( int $user_id, int $template_id ) {
		global $wpdb;
		$tpl = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d AND owner_user_id IS NULL',
				Schema::table( 'categories' ),
				$template_id
			)
		);
		if ( ! $tpl ) {
			return new WP_Error( 'pp_not_found', __( 'Template category not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		return self::create_category( $user_id, [
			'name'   => $tpl->name,
			'icon'   => $tpl->icon,
			'prefix' => $tpl->prefix,
		] );
	}

	/**
	 * Eigene Kategorie löschen — Items behalten, Zuordnung wird gelöst.
	 *
	 * @return true|WP_Error
	 */
	public static function delete_category( int $user_id, int $cat_id ) {
		global $wpdb;
		$owner = $wpdb->get_var(
			$wpdb->prepare( 'SELECT owner_user_id FROM %i WHERE id = %d', Schema::table( 'categories' ), $cat_id )
		);
		if ( null === $owner || (int) $owner !== $user_id ) {
			return new WP_Error( 'pp_forbidden', __( 'This category is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$wpdb->update( Schema::table( 'items' ), [ 'category_id' => null ], [ 'category_id' => $cat_id ], [ '%d' ], [ '%d' ] );
		$wpdb->delete( Schema::table( 'categories' ), [ 'id' => $cat_id ], [ '%d' ] );
		return true;
	}

	private static function has_own_category_named( int $user_id, string $name ): bool {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE owner_user_id = %d AND name = %s',
				Schema::table( 'categories' ),
				$user_id,
				$name
			)
		) > 0;
	}

	/* ===================== Teilen mit Kollektiven ===================== */

	/**
	 * Eigenes Item mit einer Gruppe teilen, in der der User Mitglied ist.
	 *
	 * @return true|WP_Error
	 */
	public static function share( int $user_id, int $item_id, int $group_id ) {
		global $wpdb;
		if ( ! self::owns( $user_id, $item_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This item is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		if ( ! Groups::is_member( $group_id, $user_id ) ) {
			return new WP_Error( 'pp_not_member', __( 'You can only share with collectives you belong to.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$exists = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM %i WHERE item_id = %d AND group_id = %d',
			Schema::table( 'item_group_shares' ),
			$item_id,
			$group_id
		) );
		if ( ! $exists ) {
			$wpdb->insert( Schema::table( 'item_group_shares' ), [
				'item_id'    => $item_id,
				'group_id'   => $group_id,
				'shared_by'  => $user_id,
				'created_at' => current_time( 'mysql' ),
			] );
			ActivityLog::log( 'item_shared', 'group', $group_id, [ 'item_id' => $item_id ] );
		}
		return true;
	}

	/**
	 * Freigabe eines eigenen Items für eine Gruppe zurücknehmen.
	 *
	 * @return true|WP_Error
	 */
	public static function unshare( int $user_id, int $item_id, int $group_id ) {
		global $wpdb;
		if ( ! self::owns( $user_id, $item_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This item is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$wpdb->delete( Schema::table( 'item_group_shares' ), [ 'item_id' => $item_id, 'group_id' => $group_id ], [ '%d', '%d' ] );
		ActivityLog::log( 'item_unshared', 'group', $group_id, [ 'item_id' => $item_id ] );
		return true;
	}

	/**
	 * Bedingungs-Presets fürs Gruppen-Teilen (Pendant zur App `CONDITION_PRESETS`).
	 *
	 * @return array<string,string> key => Label
	 */
	public static function condition_presets(): array {
		return [
			'free_use'             => __( 'Free to use', 'project-prepper' ),
			'owner_pickup'         => __( 'Only the owner hands it out', 'project-prepper' ),
			'group_projects_only'  => __( 'Group projects only', 'project-prepper' ),
			'rental_fee'           => __( 'Against a rental fee', 'project-prepper' ),
			'deposit_required'     => __( 'Deposit required', 'project-prepper' ),
			'non_commercial'       => __( 'Non-commercial use only', 'project-prepper' ),
			'instruction_required' => __( 'Prior instruction required', 'project-prepper' ),
			'expert_only'          => __( 'Qualified users only', 'project-prepper' ),
		];
	}

	/**
	 * Item mit einer Gruppe teilen ODER die Konditionen einer bestehenden Freigabe
	 * aktualisieren (Upsert). Pendant zum App-Modal: Tagessatz, Freigabe-Pflicht,
	 * Bedingungs-Tags + Freitext.
	 *
	 * @param array $opts daily_rate (float|string|null), requires_approval (bool),
	 *                    conditions_tags (string[]), conditions (string).
	 * @return true|WP_Error
	 */
	public static function set_share( int $user_id, int $item_id, int $group_id, array $opts ) {
		global $wpdb;
		if ( ! self::owns( $user_id, $item_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This item is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		if ( ! Groups::is_member( $group_id, $user_id ) ) {
			return new WP_Error( 'pp_not_member', __( 'You can only share with collectives you belong to.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$rate  = ( isset( $opts['daily_rate'] ) && '' !== $opts['daily_rate'] && null !== $opts['daily_rate'] )
			? round( (float) $opts['daily_rate'], 2 ) : null;
		$valid = array_keys( self::condition_presets() );
		$tags  = array_values( array_intersect( $valid, array_map( 'sanitize_key', (array) ( $opts['conditions_tags'] ?? [] ) ) ) );
		$data  = [
			'daily_rate'        => $rate,
			'requires_approval' => ! empty( $opts['requires_approval'] ) ? 1 : 0,
			'conditions_tags'   => wp_json_encode( $tags ),
			'conditions'        => sanitize_textarea_field( (string) ( $opts['conditions'] ?? '' ) ),
		];
		$table = Schema::table( 'item_group_shares' );
		$id    = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE item_id = %d AND group_id = %d', $table, $item_id, $group_id ) );
		if ( $id ) {
			$wpdb->update( $table, $data, [ 'id' => (int) $id ] );
		} else {
			$wpdb->insert( $table, array_merge( $data, [
				'item_id'    => $item_id,
				'group_id'   => $group_id,
				'shared_by'  => $user_id,
				'created_at' => current_time( 'mysql' ),
			] ) );
		}
		ActivityLog::log( 'item_shared', 'group', $group_id, [ 'item_id' => $item_id ] );
		return true;
	}

	/**
	 * Freigabe-Konditionen eines Items je Gruppe (zur Vorbelegung im Modal).
	 *
	 * @return array<int,object> group_id => { daily_rate, requires_approval, conditions_tags[], conditions }
	 */
	public static function share_settings( int $item_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT group_id, daily_rate, requires_approval, conditions_tags, conditions FROM %i WHERE item_id = %d',
			Schema::table( 'item_group_shares' ),
			$item_id
		) ) ?: [];
		$out = [];
		foreach ( $rows as $r ) {
			$tags                = json_decode( (string) $r->conditions_tags, true );
			$r->conditions_tags  = is_array( $tags ) ? $tags : [];
			$out[ (int) $r->group_id ] = $r;
		}
		return $out;
	}

	/**
	 * Gesamt-Freigabe (App: „Inventar freigeben"): teilt ALLE eigenen Artikel auf
	 * einmal mit einer Gruppe, in der der User Mitglied ist. Reversibel über
	 * unshare_all(). Idempotent — bereits geteilte Artikel werden übersprungen.
	 *
	 * @param array $opts Default-Konditionen für die NEU erstellten Freigaben
	 *                    (daily_rate, requires_approval, conditions_tags, conditions).
	 *                    Bereits geteilte Artikel behalten ihre Einzel-Konditionen.
	 * @return int|WP_Error Anzahl neu freigegebener Artikel.
	 */
	public static function share_all( int $user_id, int $group_id, array $opts = [] ) {
		global $wpdb;
		if ( ! $user_id || ! current_user_can( Capabilities::COLLECTIVES ) ) {
			return new WP_Error( 'pp_forbidden', __( 'You are not allowed to share inventory.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		if ( ! Groups::is_member( $group_id, $user_id ) ) {
			return new WP_Error( 'pp_not_member', __( 'You can only share with collectives you belong to.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		// Default-Konditionen für die neuen Freigaben (gleiche Logik wie set_share()).
		$rate     = ( isset( $opts['daily_rate'] ) && '' !== $opts['daily_rate'] && null !== $opts['daily_rate'] ) ? round( (float) $opts['daily_rate'], 2 ) : null;
		$valid    = array_keys( self::condition_presets() );
		$tags     = array_values( array_intersect( $valid, array_map( 'sanitize_key', (array) ( $opts['conditions_tags'] ?? [] ) ) ) );
		$defaults = [
			'daily_rate'        => $rate,
			'requires_approval' => ! empty( $opts['requires_approval'] ) ? 1 : 0,
			'conditions_tags'   => wp_json_encode( $tags ),
			'conditions'        => sanitize_textarea_field( (string) ( $opts['conditions'] ?? '' ) ),
		];
		$item_ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM %i WHERE owner_user_id = %d',
			Schema::table( 'items' ),
			$user_id
		) ) ?: [];
		$already = $wpdb->get_col( $wpdb->prepare(
			'SELECT item_id FROM %i WHERE group_id = %d',
			Schema::table( 'item_group_shares' ),
			$group_id
		) ) ?: [];
		$already = array_map( 'intval', $already );
		$count   = 0;
		foreach ( $item_ids as $iid ) {
			$iid = (int) $iid;
			if ( in_array( $iid, $already, true ) ) {
				continue;
			}
			$wpdb->insert( Schema::table( 'item_group_shares' ), array_merge( $defaults, [
				'item_id'    => $iid,
				'group_id'   => $group_id,
				'shared_by'  => $user_id,
				'created_at' => current_time( 'mysql' ),
			] ) );
			++$count;
		}
		if ( $count > 0 ) {
			ActivityLog::log( 'item_shared_all', 'group', $group_id, [ 'count' => $count, 'owner' => $user_id ] );
		}
		return $count;
	}

	/**
	 * Gesamt-Freigabe zurücknehmen: entfernt alle Freigaben EIGENER Artikel an eine
	 * Gruppe (fremde Freigaben anderer Mitglieder bleiben unangetastet).
	 *
	 * @return int|WP_Error Anzahl entfernter Freigaben.
	 */
	public static function unshare_all( int $user_id, int $group_id ) {
		global $wpdb;
		if ( ! $user_id || ! current_user_can( Capabilities::COLLECTIVES ) ) {
			return new WP_Error( 'pp_forbidden', __( 'You are not allowed to do this.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		// Nur Freigaben löschen, deren Item dem User gehört.
		$removed = $wpdb->query( $wpdb->prepare(
			"DELETE s FROM %i s
			 JOIN %i i ON i.id = s.item_id
			 WHERE s.group_id = %d AND i.owner_user_id = %d",
			Schema::table( 'item_group_shares' ),
			Schema::table( 'items' ),
			$group_id,
			$user_id
		) );
		if ( $removed ) {
			ActivityLog::log( 'item_unshared_all', 'group', $group_id, [ 'count' => (int) $removed, 'owner' => $user_id ] );
		}
		return (int) $removed;
	}

	/**
	 * Wie viele EIGENE Artikel sind bereits mit einer Gruppe geteilt (für die
	 * Gesamt-Freigabe-Maske: „12 von 20 geteilt").
	 */
	public static function shared_count_in_group( int $user_id, int $group_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i s
			 JOIN %i i ON i.id = s.item_id
			 WHERE s.group_id = %d AND i.owner_user_id = %d",
			Schema::table( 'item_group_shares' ),
			Schema::table( 'items' ),
			$group_id,
			$user_id
		) );
	}

	/**
	 * Auswertung (App: „Auswertung"): pro eigenem Artikel + gesamt.
	 *
	 * Ehrlich, ohne erfundene Erträge: WP/Kollektiv-Leihe ist nichtkommerziell, es
	 * gibt KEINE projektbasierten Ertrags-Snapshots wie in der App. Stattdessen
	 * zeigen wir, was sich aus vorhandenen Daten belegen lässt:
	 *  - Tageswert je Artikel = cost_per_day × Menge (Potenzial)
	 *  - Auslastung: wie oft / wie viele Tage war der Artikel unterwegs
	 *    (genehmigte Kollektiv-Leihen + externe Verleihe, returned/active/reserved)
	 *  - Reale Verleih-Erträge NUR aus externen Verleihen mit Positions-Tagessatz
	 *    (daily_rate auf rental_items), als belegbarer Beitrag.
	 *
	 * @return array{items: array<object>, totals: object}
	 */
	public static function evaluation( int $user_id ): array {
		global $wpdb;
		$items = self::my_items( $user_id );
		$rows  = [];

		$brt = Schema::table( 'borrow_requests' );
		$rl  = Schema::table( 'rentals' );
		$rli = Schema::table( 'rental_items' );

		foreach ( $items as $it ) {
			$iid          = (int) $it->id;
			$qty          = max( 1, (int) $it->quantity );
			$daily_value  = (float) ( $it->cost_per_day ?? 0 ) * $qty;

			// Auslastung Kollektiv-Leihen (genehmigt/zurückgegeben zählen als Einsatz).
			$borrow = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS uses,
				        COALESCE(SUM(DATEDIFF(date_to, date_from) + 1), 0) AS days
				 FROM %i
				 WHERE item_id = %d AND status IN ('approved','returned')",
				$brt,
				$iid
			) );

			// Externe Verleihe mit eigener Position (Einsätze + Tage + belegte Erträge).
			$rental = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS uses,
				        COALESCE(SUM(DATEDIFF(r.date_to, r.date_from) + 1), 0) AS days,
				        COALESCE(SUM(
				            CASE WHEN ri.daily_rate IS NOT NULL
				                 THEN ri.daily_rate * (DATEDIFF(r.date_to, r.date_from) + 1) * ri.quantity
				                 ELSE 0 END
				        ), 0) AS earnings
				 FROM %i ri
				 JOIN %i r ON r.id = ri.rental_id
				 WHERE ri.item_id = %d AND r.status IN ('reserved','active','returned')",
				$rli,
				$rl,
				$iid
			) );

			$uses     = (int) ( $borrow->uses ?? 0 ) + (int) ( $rental->uses ?? 0 );
			$days_out = (int) ( $borrow->days ?? 0 ) + (int) ( $rental->days ?? 0 );
			$earnings = (float) ( $rental->earnings ?? 0 );

			$rows[] = (object) [
				'id'               => $iid,
				'inventory_number' => (string) $it->inventory_number,
				'name'             => (string) $it->name,
				'category'         => (string) ( $it->category_name ?? '' ),
				'quantity'         => $qty,
				'daily_value'      => $daily_value,
				'uses'             => $uses,
				'days_out'         => $days_out,
				'earnings'         => $earnings,
			];
		}

		$totals = (object) [
			'daily_value' => array_sum( array_map( static fn( $r ) => $r->daily_value, $rows ) ),
			'uses'        => array_sum( array_map( static fn( $r ) => $r->uses, $rows ) ),
			'days_out'    => array_sum( array_map( static fn( $r ) => $r->days_out, $rows ) ),
			'earnings'    => array_sum( array_map( static fn( $r ) => $r->earnings, $rows ) ),
			'active'      => count( array_filter( $rows, static fn( $r ) => $r->uses > 0 ) ),
			'count'       => count( $rows ),
		];

		usort( $rows, static fn( $a, $b ) => ( $b->earnings <=> $a->earnings ) ?: ( $b->daily_value <=> $a->daily_value ) );
		return [ 'items' => $rows, 'totals' => $totals ];
	}

	/** Gruppen-IDs, mit denen ein Item geteilt ist. @return int[] */
	public static function shared_group_ids( int $item_id ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT group_id FROM %i WHERE item_id = %d',
			Schema::table( 'item_group_shares' ),
			$item_id
		) );
		return array_map( 'intval', $ids ?: [] );
	}

	/**
	 * Mit einer Gruppe geteilte Items (für Phase 4 „Stöbern & Leihen"). Liefert
	 * Items inkl. Owner-Name. Zugriff: Aufrufer prüft Gruppen-Mitgliedschaft.
	 *
	 * @return array<object>
	 */
	public static function items_shared_with_group( int $group_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT i.*, s.shared_by, s.daily_rate AS share_daily_rate, c.name AS category_name, c.icon AS category_icon
			 FROM %i s
			 JOIN %i i ON i.id = s.item_id
			 LEFT JOIN %i c ON c.id = i.category_id
			 WHERE s.group_id = %d
			 ORDER BY i.name ASC',
			Schema::table( 'item_group_shares' ),
			Schema::table( 'items' ),
			Schema::table( 'categories' ),
			$group_id
		) ) ?: [];
		foreach ( $rows as $row ) {
			$owner          = get_userdata( (int) $row->shared_by );
			$row->owner_name = $owner ? $owner->display_name : '';
			// Vorschaubild wie in der Inventar-Liste (Attachment → medium).
			$row->image_url  = ! empty( $row->image_id ) ? ( wp_get_attachment_image_url( (int) $row->image_id, 'medium' ) ?: null ) : null;
		}
		return $rows;
	}
}
