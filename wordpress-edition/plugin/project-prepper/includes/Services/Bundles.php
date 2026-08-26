<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sets/Bundles (docs/07, v0.39.0): Ein Set ist ein normaler Inventar-Artikel mit
 * STÜCKLISTE (item_bundle_parts). Gebucht wird das Set nie selbst — die Buchung
 * expandiert serverseitig in Teil-Zeilen (Buchungs-Makro, Marker
 * project_items.bundle_item_id). Dadurch bleiben Verfügbarkeit, Freigaben und
 * Packliste unverändert korrekt.
 *
 * Regeln (docs/07 §4): nur eigene Artikel als Teile, kein Set im Set,
 * alles-oder-nichts beim Buchen, Set-Share genügt fürs Kollektiv.
 */
class Bundles {

	/**
	 * Stückliste eines Sets — Teile mit Name/Nummer/Bestand des Teil-Artikels.
	 *
	 * @return array<object> { part_item_id, quantity, name, inventory_number, part_total }
	 */
	public static function parts( int $bundle_item_id ): array {
		global $wpdb;
		if ( $bundle_item_id <= 0 ) {
			return [];
		}
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT bp.part_item_id, bp.quantity, i.name, i.inventory_number, i.quantity AS part_total
			 FROM %i bp
			 JOIN %i i ON i.id = bp.part_item_id
			 WHERE bp.bundle_item_id = %d
			 ORDER BY bp.sort_order ASC, i.name ASC',
			Schema::table( 'item_bundle_parts' ),
			Schema::table( 'items' ),
			$bundle_item_id
		) ) ?: [];
	}

	/**
	 * Stücklisten mehrerer Artikel in einem Rutsch (für Listen/Picker):
	 * bundle_item_id => Teil-Zeilen. Artikel ohne Zeilen fehlen im Ergebnis
	 * (= kein Set).
	 *
	 * @param int[] $item_ids
	 * @return array<int,array<object>>
	 */
	public static function for_items( array $item_ids ): array {
		global $wpdb;
		$item_ids = array_values( array_filter( array_map( 'intval', $item_ids ) ) );
		if ( ! $item_ids ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare(
			"SELECT bp.bundle_item_id, bp.part_item_id, bp.quantity, i.name, i.inventory_number, i.quantity AS part_total
			 FROM %i bp
			 JOIN %i i ON i.id = bp.part_item_id
			 WHERE bp.bundle_item_id IN ({$placeholders})
			 ORDER BY bp.sort_order ASC, i.name ASC",
			array_merge( [ Schema::table( 'item_bundle_parts' ), Schema::table( 'items' ) ], $item_ids )
		) ) ?: [];
		$out = [];
		foreach ( $rows as $row ) {
			$out[ (int) $row->bundle_item_id ][] = $row;
		}
		return $out;
	}

	/** Ist der Artikel ein Set (hat Stücklisten-Zeilen)? */
	public static function is_bundle( int $item_id ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE bundle_item_id = %d',
			Schema::table( 'item_bundle_parts' ),
			$item_id
		) ) > 0;
	}

	/** Name des Sets, in dem der Artikel als TEIL steckt (leer = in keinem). */
	public static function part_of_bundle_names( int $item_id ): array {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare(
			'SELECT i.name FROM %i bp JOIN %i i ON i.id = bp.bundle_item_id WHERE bp.part_item_id = %d ORDER BY i.name ASC',
			Schema::table( 'item_bundle_parts' ),
			Schema::table( 'items' ),
			$item_id
		) ) ?: [];
	}

	/**
	 * Stückliste eines eigenen Sets komplett setzen (Upsert aus dem Formular).
	 * Gates: Set + jedes Teil gehören $user_id; ein Teil darf selbst kein Set
	 * sein; das Set darf nicht selbst Teil eines anderen Sets sein (kein Set im
	 * Set, beidseitig); Teil != Set. Menge 0/leer entfernt das Teil.
	 *
	 * @param array<int,int> $parts part_item_id => quantity (>= 1).
	 * @return true|WP_Error
	 */
	public static function set_parts( int $user_id, int $bundle_item_id, array $parts ) {
		global $wpdb;
		if ( ! MemberInventory::owns( $user_id, $bundle_item_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This item is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		// Kein Set im Set: Der Artikel darf nicht selbst Teil eines anderen Sets sein.
		if ( $parts && self::part_of_bundle_names( $bundle_item_id ) ) {
			return new WP_Error( 'pp_bundle_nested', __( 'This item is part of another set and cannot be a set itself.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$clean = [];
		foreach ( $parts as $part_id => $qty ) {
			$part_id = (int) $part_id;
			$qty     = (int) $qty;
			if ( $part_id <= 0 || $qty <= 0 || $part_id === $bundle_item_id ) {
				continue;
			}
			if ( ! MemberInventory::owns( $user_id, $part_id ) ) {
				return new WP_Error( 'pp_forbidden', __( 'Sets can only contain your own items.', 'project-prepper' ), [ 'status' => 403 ] );
			}
			if ( self::is_bundle( $part_id ) ) {
				return new WP_Error( 'pp_bundle_nested', __( 'A set cannot contain another set.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$clean[ $part_id ] = $qty;
		}
		$table = Schema::table( 'item_bundle_parts' );
		// Komplett-Ersatz: alte Stückliste weg, neue rein (Reihenfolge = Eingabe).
		$wpdb->delete( $table, [ 'bundle_item_id' => $bundle_item_id ], [ '%d' ] );
		$sort = 0;
		foreach ( $clean as $part_id => $qty ) {
			$wpdb->insert( $table, [
				'bundle_item_id' => $bundle_item_id,
				'part_item_id'   => $part_id,
				'quantity'       => $qty,
				'sort_order'     => $sort++,
			], [ '%d', '%d', '%d', '%d' ] );
		}
		if ( $clean ) {
			ActivityLog::log( 'bundle_saved', 'item', $bundle_item_id, [ 'parts' => count( $clean ) ] );
		}
		return true;
	}

	/**
	 * Verfügbare SET-Anzahl = min über alle Teile: floor( frei(Teil) / Bedarf ).
	 * Mit Zeitraum zählt Availability (Verleihe + Projekt-Buchungen), ohne
	 * Zeitraum der rohe Teil-Bestand. $exclude_project_id wie bei
	 * Availability::available_quantity (eigene Projekt-Zeilen ausklammern).
	 *
	 * @param array<object> $parts Stückliste aus parts()/for_items().
	 */
	public static function available_sets( array $parts, string $from = '', string $to = '', int $exclude_project_id = 0 ): int {
		if ( ! $parts ) {
			return 0;
		}
		$min = PHP_INT_MAX;
		foreach ( $parts as $part ) {
			$need = max( 1, (int) $part->quantity );
			$free = ( '' !== $from && '' !== $to )
				? Availability::available_quantity( (int) $part->part_item_id, $from, $to, 0, $exclude_project_id )
				: (int) ( $part->part_total ?? 0 );
			$min  = min( $min, (int) floor( $free / $need ) );
		}
		return max( 0, PHP_INT_MAX === $min ? 0 : $min );
	}

	/**
	 * Beim Artikel-Löschen: Stücklisten-Zeilen beidseitig entfernen — als Set
	 * (bundle_item_id) UND als Teil in fremden Sets (part_item_id). Die Sets
	 * anderer Artikel bleiben bestehen, nur ohne dieses Teil (docs/07 §4.7).
	 */
	public static function delete_for_item( int $item_id ): void {
		global $wpdb;
		$table = Schema::table( 'item_bundle_parts' );
		$wpdb->delete( $table, [ 'bundle_item_id' => $item_id ], [ '%d' ] );
		$wpdb->delete( $table, [ 'part_item_id' => $item_id ], [ '%d' ] );
	}

	/**
	 * Kurz-Label einer Stückliste für Listen/Picker: „3× 10-m-Glied · 1× Einspeiser".
	 *
	 * @param array<object> $parts
	 */
	public static function parts_label( array $parts ): string {
		$bits = [];
		foreach ( $parts as $part ) {
			$bits[] = sprintf( '%d× %s', (int) $part->quantity, (string) $part->name );
		}
		return implode( ' · ', $bits );
	}
}
