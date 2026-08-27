<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Inventar-Service: Artikel + Kategorien (CRUD, Suche, KPIs).
 */
class Inventory {

	// Zustands-Enum wie in der App (Dok 01 §8.1).
	const CONDITIONS = [ 'new', 'good', 'fair', 'poor', 'broken', 'retired' ];

	// Eigentums-/Abschreibungs-Enums (§8.7 — reine Dokumentation, keine Buchung).
	const OWNERSHIP_TYPES      = [ '', 'own', 'loaned', 'funded', 'other' ];
	const DEPRECIATION_METHODS = [ '', 'linear', 'degressive', 'none' ];

	// Default-Kategorien (Auto-Seed bei leerer Tabelle, wie App §8.3).
	const DEFAULT_CATEGORIES = [
		[ 'name' => 'Projektion', 'icon' => '📽', 'prefix' => 'PRO' ],
		[ 'name' => 'Licht', 'icon' => '💡', 'prefix' => 'LIC' ],
		[ 'name' => 'Effekte', 'icon' => '✨', 'prefix' => 'EFF' ],
		[ 'name' => 'Steuerung', 'icon' => '🎛', 'prefix' => 'STR' ],
		[ 'name' => 'Video', 'icon' => '🖥', 'prefix' => 'VID' ],
		[ 'name' => 'Audio', 'icon' => '🔊', 'prefix' => 'AUD' ],
		[ 'name' => 'Kabel', 'icon' => '🔌', 'prefix' => 'KAB' ],
		[ 'name' => 'Rigging', 'icon' => '🔧', 'prefix' => 'RIG' ],
		[ 'name' => 'Transport', 'icon' => '📦', 'prefix' => 'TRA' ],
		[ 'name' => 'Zubehör', 'icon' => '🧰', 'prefix' => 'ZUB' ],
	];

	/* ---------- Kategorien ---------- */

	public static function categories(): array {
		global $wpdb;
		self::maybe_seed_categories();
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY sort_order ASC, name ASC', Schema::table( 'categories' ) )
		) ?: [];
	}

	/**
	 * Vorlagen-Kategorien (Betreiber-gepflegt, owner_user_id IS NULL).
	 *
	 * Diese bilden die „Inventar-Mechanik" der Steuerzentrale: der Betreiber
	 * pflegt einen Satz vorgeschlagener Kategorien, den Mitglieder im Portal
	 * übernehmen oder durch eigene ergänzen (docs/06 §10.3). Die im Portal von
	 * Mitgliedern angelegten Kategorien (owner_user_id > 0) bleiben außen vor.
	 */
	public static function template_categories(): array {
		global $wpdb;
		self::maybe_seed_categories();
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE owner_user_id IS NULL ORDER BY sort_order ASC, name ASC', Schema::table( 'categories' ) )
		) ?: [];
	}

	private static function maybe_seed_categories(): void {
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'categories' ) ) );
		if ( $count > 0 ) {
			return;
		}
		foreach ( self::DEFAULT_CATEGORIES as $index => $cat ) {
			$wpdb->insert( Schema::table( 'categories' ), [
				'name'       => $cat['name'],
				'icon'       => $cat['icon'],
				'prefix'     => $cat['prefix'],
				'sort_order' => $index,
				'created_at' => current_time( 'mysql' ),
			], [ '%s', '%s', '%s', '%d', '%s' ] );
		}
	}

	public static function create_category( array $data ): int {
		global $wpdb;
		$wpdb->insert( Schema::table( 'categories' ), [
			'name'       => $data['name'],
			'icon'       => $data['icon'] ?? '',
			'prefix'     => strtoupper( $data['prefix'] ?? '' ),
			'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
			'created_at' => current_time( 'mysql' ),
		], [ '%s', '%s', '%s', '%d', '%s' ] );
		return (int) $wpdb->insert_id;
	}

	public static function update_category( int $id, array $data ): bool {
		global $wpdb;
		$fields  = [];
		$formats = [];
		foreach ( [ 'name' => '%s', 'icon' => '%s', 'prefix' => '%s', 'sort_order' => '%d' ] as $key => $format ) {
			if ( array_key_exists( $key, $data ) ) {
				$fields[ $key ] = 'prefix' === $key ? strtoupper( $data[ $key ] ) : $data[ $key ];
				$formats[]      = $format;
			}
		}
		if ( ! $fields ) {
			return false;
		}
		return false !== $wpdb->update( Schema::table( 'categories' ), $fields, [ 'id' => $id ], $formats, [ '%d' ] );
	}

	public static function delete_category( int $id ): bool {
		global $wpdb;
		// Artikel behalten, nur die Zuordnung lösen (Pendant zu ON DELETE SET NULL).
		$wpdb->update( Schema::table( 'items' ), [ 'category_id' => null ], [ 'category_id' => $id ], [ '%d' ], [ '%d' ] );
		return false !== $wpdb->delete( Schema::table( 'categories' ), [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Kategorien zusammenführen (Pendant zur App-Migration 097):
	 * alle Artikel der Quelle auf das Ziel umhängen, Quelle löschen.
	 *
	 * @return array|WP_Error [ 'moved' => n, 'target_id' => … ]
	 */
	public static function merge_categories( int $source_id, int $target_id ) {
		global $wpdb;

		if ( $source_id === $target_id ) {
			return new WP_Error( 'pp_merge_self', __( 'Source and target are identical.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$cats   = Schema::table( 'categories' );
		$source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$cats} WHERE id = %d", $source_id ) );
		$target = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$cats} WHERE id = %d", $target_id ) );
		if ( ! $source || ! $target ) {
			return new WP_Error( 'pp_not_found', __( 'Category not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}

		$moved = $wpdb->update(
			Schema::table( 'items' ),
			[ 'category_id' => $target_id ],
			[ 'category_id' => $source_id ],
			[ '%d' ],
			[ '%d' ]
		);
		$wpdb->delete( $cats, [ 'id' => $source_id ], [ '%d' ] );

		ActivityLog::log( 'category_merged', 'category', $target_id, [
			'source_id'   => $source_id,
			'source_name' => $source->name,
			'target_name' => $target->name,
			'moved'       => (int) $moved,
		] );

		return [ 'moved' => (int) $moved, 'target_id' => $target_id ];
	}

	/* ---------- Artikel ---------- */

	public static function items( array $args = [] ): array {
		global $wpdb;
		$items   = Schema::table( 'items' );
		$cats    = Schema::table( 'categories' );
		$lines   = Schema::table( 'rental_items' );
		$rentals = Schema::table( 'rentals' );
		$p_items = Schema::table( 'project_items' );
		$projs   = Schema::table( 'projects' );
		$today   = current_time( 'Y-m-d' );

		$where        = [ '1=1' ];
		$where_params = [];

		if ( ! empty( $args['search'] ) ) {
			// Volltextsuche über die wichtigsten Felder (§8.5).
			$like         = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$fields       = [ 'i.name', 'i.inventory_number', 'i.description', 'i.manufacturer', 'i.model', 'i.serial_number', 'i.location', 'i.tags', 'i.accessories', 'i.notes' ];
			$where[]      = '(' . implode( ' LIKE %s OR ', $fields ) . ' LIKE %s)';
			$where_params = array_merge( $where_params, array_fill( 0, count( $fields ), $like ) );
		}
		if ( ! empty( $args['category_id'] ) ) {
			$where[]        = 'i.category_id = %d';
			$where_params[] = (int) $args['category_id'];
		}
		if ( ! empty( $args['ids'] ) ) {
			// Gezielte Artikel-Menge (z.B. Set-Teile) — inkl. out_now-Berechnung.
			$ids          = array_map( 'intval', (array) $args['ids'] );
			$where[]      = 'i.id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$where_params = array_merge( $where_params, $ids );
		}
		if ( ! empty( $args['owner_user_id'] ) ) {
			// Member-Portal: nur das persönliche Inventar dieses Users.
			$where[]        = 'i.owner_user_id = %d';
			$where_params[] = (int) $args['owner_user_id'];
		}
		if ( ! empty( $args['shared_with_group'] ) ) {
			// Gruppen-Inventar (Member-Portal): alle Artikel, die Mitglieder mit
			// dieser Gruppe geteilt haben (Pendant zur owner_group_id-Sicht der App).
			$where[]        = 'i.id IN ( SELECT s.item_id FROM %i s WHERE s.group_id = %d )';
			$where_params[] = Schema::table( 'item_group_shares' );
			$where_params[] = (int) $args['shared_with_group'];
		}
		if ( ! empty( $args['usable_only'] ) ) {
			// Öffentliches Frontend: defekte/ausgemusterte Artikel ausblenden.
			$where[] = "i.item_condition NOT IN ('broken', 'retired')";
		}
		if ( ! empty( $args['out_only'] ) ) {
			// Filter "Ausgeliehen": nur Artikel, die heute unterwegs sind.
			$where[] = 'COALESCE(o.out_now, 0) > 0';
		}

		// out_now = heute unterwegs: Summe der Mengen aus überlappenden
		// reserved/active-Verleihen UND confirmed/running-Projekt-Buchungen
		// (gleiche Semantik wie Availability), als ein Subquery-JOIN — kein N+1.
		// Platzhalter-Reihenfolge folgt dem SQL von oben nach unten.
		$params = array_merge(
			[ $items, $cats ],                            // FROM %i i, LEFT JOIN %i c
			[ $lines, $rentals, $today, $today ],         // Verleih-Zweig der UNION
			[ $p_items, $projs, $today, $today ],         // Projekt-Zweig der UNION
			$where_params                                 // WHERE
		);
		$sql = $wpdb->prepare(
			"SELECT i.*, c.name AS category_name, c.icon AS category_icon,
					COALESCE(o.out_now, 0) AS out_now
				FROM %i i
				LEFT JOIN %i c ON c.id = i.category_id
				LEFT JOIN (
					SELECT u.item_id, SUM(u.quantity) AS out_now FROM (
						SELECT ri.item_id, ri.quantity
						FROM %i ri
						INNER JOIN %i r ON r.id = ri.rental_id
						WHERE r.status IN ('reserved', 'active')
						  AND r.date_from <= %s AND r.date_to >= %s
						UNION ALL
						SELECT pi.item_id, pi.quantity
						FROM %i pi
						INNER JOIN %i p ON p.id = pi.project_id
						WHERE p.status IN ('confirmed', 'running')
						  AND COALESCE(pi.date_from, p.date_start) <= %s
						  AND COALESCE(pi.date_to, p.date_end) >= %s
					) u
					GROUP BY u.item_id
				) o ON o.item_id = i.id
				WHERE " . implode( ' AND ', $where ) . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE-Bedingungen sind statische Strings mit Platzhaltern.
				' ORDER BY i.inventory_number ASC',
			$params
		);
		return array_map( [ self::class, 'decode_item' ], $wpdb->get_results( $sql ) ?: [] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql ist oben via prepare() aufgebaut.
	}

	public static function get_item( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT i.*, c.name AS category_name, c.icon AS category_icon
			 FROM %i i
			 LEFT JOIN %i c ON c.id = i.category_id
			 WHERE i.id = %d',
			Schema::table( 'items' ),
			Schema::table( 'categories' ),
			$id
		) );
		return $row ? self::decode_item( $row ) : null;
	}

	/**
	 * Artikel über die Inventarnummer finden (öffentliche Detailseite).
	 */
	public static function get_item_by_number( string $inventory_number ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT i.*, c.name AS category_name, c.icon AS category_icon
			 FROM %i i
			 LEFT JOIN %i c ON c.id = i.category_id
			 WHERE i.inventory_number = %s',
			Schema::table( 'items' ),
			Schema::table( 'categories' ),
			$inventory_number
		) );
		return $row ? self::decode_item( $row ) : null;
	}

	public static function create_item( array $data ): int {
		global $wpdb;

		$category_id = ! empty( $data['category_id'] ) ? (int) $data['category_id'] : null;
		$number      = ! empty( $data['inventory_number'] )
			? $data['inventory_number']
			: Numbering::next_inventory_number( $category_id );

		$now    = current_time( 'mysql' );
		$result = $wpdb->insert( Schema::table( 'items' ), [
			'inventory_number' => $number,
			'category_id'      => $category_id,
			'name'             => $data['name'],
			'description'      => $data['description'] ?? '',
			'manufacturer'     => $data['manufacturer'] ?? '',
			'model'            => $data['model'] ?? '',
			'serial_number'    => $data['serial_number'] ?? '',
			'tags'             => wp_json_encode( $data['tags'] ?? [] ),
			'quantity'         => max( 1, (int) ( $data['quantity'] ?? 1 ) ),
			'item_condition'   => in_array( $data['condition'] ?? '', self::CONDITIONS, true ) ? $data['condition'] : 'good',
			'location'         => $data['location'] ?? '',
			'cost_per_day'     => self::dec( $data, 'cost_per_day' ),
			'purchase_price'   => self::dec( $data, 'purchase_price' ),
			'current_value'    => self::dec( $data, 'current_value' ),
			'purchase_date'    => ! empty( $data['purchase_date'] ) ? $data['purchase_date'] : null,
			'dimensions'       => $data['dimensions'] ?? '',
			'power_watts'      => isset( $data['power_watts'] ) && '' !== $data['power_watts'] ? (int) $data['power_watts'] : null,
			'accessories'      => $data['accessories'] ?? '',
			'manufacturer_url' => $data['manufacturer_url'] ?? '',
			'manual_url'       => $data['manual_url'] ?? '',
			// Eigentum & Abschreibung (§8.7 — reine Dokumentation)
			'ownership_type'      => in_array( $data['ownership_type'] ?? '', self::OWNERSHIP_TYPES, true ) ? ( $data['ownership_type'] ?? '' ) : '',
			'funding_source'      => $data['funding_source'] ?? '',
			'depreciation_method' => in_array( $data['depreciation_method'] ?? '', self::DEPRECIATION_METHODS, true ) ? ( $data['depreciation_method'] ?? '' ) : '',
			'depreciation_years'  => isset( $data['depreciation_years'] ) && '' !== $data['depreciation_years'] ? (int) $data['depreciation_years'] : null,
			'residual_value'      => self::dec( $data, 'residual_value' ),
			'image_id'         => ! empty( $data['image_id'] ) ? (int) $data['image_id'] : null,
			'document_ids'     => wp_json_encode( $data['document_ids'] ?? [] ),
			'notes'            => $data['notes'] ?? '',
			// owner_user_id (v0.18.0): gesetzt = persönliches Mitglieder-Inventar,
			// NULL = Kollektiv-/Haus-Inventar (Admin). Das Member-Portal setzt es.
			'owner_user_id'    => ! empty( $data['owner_user_id'] ) ? (int) $data['owner_user_id'] : null,
			'created_by'       => get_current_user_id() ?: null,
			'created_at'       => $now,
			'updated_at'       => $now,
		] );

		if ( false === $result ) {
			return 0; // z. B. doppelte Inventarnummer (Unique-Constraint)
		}

		$item_id = (int) $wpdb->insert_id;
		ActivityLog::log( 'item_created', 'item', $item_id, [ 'name' => $data['name'], 'inventory_number' => $number ] );
		return $item_id;
	}

	public static function update_item( int $id, array $data ): bool {
		global $wpdb;

		$map = [
			'inventory_number' => '%s',
			'category_id'      => '%d',
			'name'             => '%s',
			'description'      => '%s',
			'manufacturer'     => '%s',
			'model'            => '%s',
			'serial_number'    => '%s',
			'quantity'         => '%d',
			'location'         => '%s',
			'dimensions'       => '%s',
			'accessories'      => '%s',
			'manufacturer_url' => '%s',
			'manual_url'       => '%s',
			'funding_source'   => '%s',
			'image_id'         => '%d',
			'notes'            => '%s',
		];

		$fields  = [];
		$formats = [];
		foreach ( $map as $key => $format ) {
			if ( array_key_exists( $key, $data ) ) {
				$fields[ $key ] = $data[ $key ];
				$formats[]      = $format;
			}
		}
		if ( array_key_exists( 'condition', $data ) && in_array( $data['condition'], self::CONDITIONS, true ) ) {
			$fields['item_condition'] = $data['condition'];
			$formats[]                = '%s';
		}
		if ( array_key_exists( 'ownership_type', $data ) && in_array( $data['ownership_type'], self::OWNERSHIP_TYPES, true ) ) {
			$fields['ownership_type'] = $data['ownership_type'];
			$formats[]                = '%s';
		}
		if ( array_key_exists( 'depreciation_method', $data ) && in_array( $data['depreciation_method'], self::DEPRECIATION_METHODS, true ) ) {
			$fields['depreciation_method'] = $data['depreciation_method'];
			$formats[]                     = '%s';
		}
		if ( array_key_exists( 'depreciation_years', $data ) ) {
			$fields['depreciation_years'] = '' === $data['depreciation_years'] || null === $data['depreciation_years'] ? null : (int) $data['depreciation_years'];
			$formats[]                    = '%d';
		}
		if ( array_key_exists( 'residual_value', $data ) ) {
			$fields['residual_value'] = '' === $data['residual_value'] || null === $data['residual_value'] ? null : (float) $data['residual_value'];
			$formats[]                = '%f';
		}
		// Nullable Zahlen-/Datumsfelder: ein GELEERTES Feld muss NULL werden, nicht
		// 0.00 / 0 / '0000-00-00'. Über die $map (%f/%d/%s) käme sonst eine 0 in die
		// Spalte und Listen zeigten „0,00 €/Tag" statt „—" (create_item macht es
		// über self::dec() schon richtig).
		foreach ( [ 'cost_per_day' => '%f', 'purchase_price' => '%f', 'current_value' => '%f', 'power_watts' => '%d', 'purchase_date' => '%s' ] as $pp_key => $pp_format ) {
			if ( ! array_key_exists( $pp_key, $data ) ) {
				continue;
			}
			$pp_value          = $data[ $pp_key ];
			$fields[ $pp_key ] = ( '' === $pp_value || null === $pp_value )
				? null
				: ( '%d' === $pp_format ? (int) $pp_value : ( '%f' === $pp_format ? (float) $pp_value : $pp_value ) );
			$formats[]         = $pp_format;
		}
		if ( array_key_exists( 'tags', $data ) ) {
			$fields['tags'] = wp_json_encode( (array) $data['tags'] );
			$formats[]      = '%s';
		}
		if ( array_key_exists( 'document_ids', $data ) ) {
			$fields['document_ids'] = wp_json_encode( (array) $data['document_ids'] );
			$formats[]              = '%s';
		}
		if ( ! $fields ) {
			return false;
		}

		$fields['updated_at'] = current_time( 'mysql' );
		$formats[]            = '%s';

		$ok = false !== $wpdb->update( Schema::table( 'items' ), $fields, [ 'id' => $id ], $formats, [ '%d' ] );
		if ( $ok ) {
			ActivityLog::log( 'item_updated', 'item', $id, [ 'fields' => array_keys( $fields ) ] );
		}
		return $ok;
	}

	public static function delete_item( int $id ): bool {
		global $wpdb;
		$item = self::get_item( $id );
		if ( ! $item ) {
			return false;
		}
		$wpdb->delete( Schema::table( 'units' ), [ 'item_id' => $id ], [ '%d' ] );
		$ok = false !== $wpdb->delete( Schema::table( 'items' ), [ 'id' => $id ], [ '%d' ] );
		if ( $ok ) {
			ActivityLog::log( 'item_deleted', 'item', $id, [ 'name' => $item->name, 'inventory_number' => $item->inventory_number ] );
		}
		return $ok;
	}

	/* ---------- KPIs (§8.5) ---------- */

	public static function stats(): array {
		global $wpdb;
		$items   = Schema::table( 'items' );
		$lines   = Schema::table( 'rental_items' );
		$rentals = Schema::table( 'rentals' );
		$p_items = Schema::table( 'project_items' );
		$projs   = Schema::table( 'projects' );
		$today   = current_time( 'Y-m-d' );

		// "Heute unterwegs" = Verleihe (reserved/active) + Projekt-Buchungen
		// (confirmed/running), die heute überlappen — dieselbe Semantik wie
		// Availability::available_quantity().
		$out_rented = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(ri.quantity), 0)
			 FROM %i ri
			 INNER JOIN %i r ON r.id = ri.rental_id
			 WHERE r.status IN ('reserved', 'active')
			   AND r.date_from <= %s AND r.date_to >= %s",
			$lines,
			$rentals,
			$today,
			$today
		) );

		$out_booked = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(pi.quantity), 0)
			 FROM %i pi
			 INNER JOIN %i p ON p.id = pi.project_id
			 WHERE p.status IN ('confirmed', 'running')
			   AND COALESCE(pi.date_from, p.date_start) <= %s
			   AND COALESCE(pi.date_to, p.date_end) >= %s",
			$p_items,
			$projs,
			$today,
			$today
		) );

		$out_today = $out_rented + $out_booked;

		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT COUNT(*) AS item_count,
					COALESCE(SUM(quantity), 0) AS total_pieces,
					COALESCE(SUM(quantity * COALESCE(cost_per_day, 0)), 0) AS daily_value
			 FROM %i',
			$items
		) );

		return [
			'item_count'   => (int) $row->item_count,
			'total_pieces' => (int) $row->total_pieces,
			'out_today'    => $out_today,
			'daily_value'  => (float) $row->daily_value,
		];
	}

	/* ---------- Helpers ---------- */

	private static function dec( array $data, string $key ): ?float {
		return isset( $data[ $key ] ) && '' !== $data[ $key ] ? (float) $data[ $key ] : null;
	}

	private static function decode_item( object $row ): object {
		$row->out_now      = isset( $row->out_now ) ? (int) $row->out_now : 0;
		$row->tags         = json_decode( $row->tags ?? '[]' ) ?: [];
		$row->document_ids = json_decode( $row->document_ids ?? '[]' ) ?: [];
		$row->condition    = $row->item_condition;
		$row->image_url    = $row->image_id ? ( wp_get_attachment_image_url( (int) $row->image_id, 'medium' ) ?: null ) : null;
		$row->documents    = array_values( array_filter( array_map( static function ( $doc_id ) {
			$url = wp_get_attachment_url( (int) $doc_id );
			return $url ? [ 'id' => (int) $doc_id, 'url' => $url, 'title' => get_the_title( (int) $doc_id ) ] : null;
		}, $row->document_ids ) ) );
		return $row;
	}
}
