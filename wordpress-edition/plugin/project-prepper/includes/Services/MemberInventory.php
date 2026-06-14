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

	/** @return array<object> Items mit owner_user_id = $user_id. */
	public static function my_items( int $user_id ): array {
		if ( ! $user_id ) {
			return [];
		}
		return Inventory::items( [ 'owner_user_id' => $user_id ] );
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
			'SELECT i.*, s.shared_by, c.name AS category_name, c.icon AS category_icon
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
		}
		return $rows;
	}
}
