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
			'SELECT * FROM ' . Schema::table( 'categories' ) . ' ORDER BY sort_order ASC, name ASC'
		) ?: [];
	}

	private static function maybe_seed_categories(): void {
		global $wpdb;
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'categories' ) );
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
			return new WP_Error( 'pp_merge_self', __( 'Quelle und Ziel sind identisch.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$cats   = Schema::table( 'categories' );
		$source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$cats} WHERE id = %d", $source_id ) );
		$target = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$cats} WHERE id = %d", $target_id ) );
		if ( ! $source || ! $target ) {
			return new WP_Error( 'pp_not_found', __( 'Kategorie nicht gefunden.', 'project-prepper' ), [ 'status' => 404 ] );
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
		$today   = current_time( 'Y-m-d' );

		$where  = [ '1=1' ];
		// Die ersten beiden Platzhalter gehören zum out_now-Subquery (heutiges Datum).
		$params = [ $today, $today ];

		if ( ! empty( $args['search'] ) ) {
			// Volltextsuche über die wichtigsten Felder (§8.5).
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$fields  = [ 'i.name', 'i.inventory_number', 'i.description', 'i.manufacturer', 'i.model', 'i.serial_number', 'i.location', 'i.tags', 'i.accessories', 'i.notes' ];
			$where[] = '(' . implode( ' LIKE %s OR ', $fields ) . ' LIKE %s)';
			$params  = array_merge( $params, array_fill( 0, count( $fields ), $like ) );
		}
		if ( ! empty( $args['category_id'] ) ) {
			$where[]  = 'i.category_id = %d';
			$params[] = (int) $args['category_id'];
		}
		if ( ! empty( $args['usable_only'] ) ) {
			// Öffentliches Frontend: defekte/ausgemusterte Artikel ausblenden.
			$where[] = "i.item_condition NOT IN ('broken', 'retired')";
		}
		if ( ! empty( $args['out_only'] ) ) {
			// Filter "Ausgeliehen" (§8.5): nur Artikel, die heute unterwegs sind.
			$where[] = 'COALESCE(o.out_now, 0) > 0';
		}

		// out_now = heute unterwegs (Summe Mengen aus überlappenden reserved/active-Verleihen),
		// als ein Subquery-JOIN berechnet — kein N+1, nichts gespeichert.
		$sql = "SELECT i.*, c.name AS category_name, c.icon AS category_icon,
					COALESCE(o.out_now, 0) AS out_now
				FROM {$items} i
				LEFT JOIN {$cats} c ON c.id = i.category_id
				LEFT JOIN (
					SELECT ri.item_id, SUM(ri.quantity) AS out_now
					FROM {$lines} ri
					INNER JOIN {$rentals} r ON r.id = ri.rental_id
					WHERE r.status IN ('reserved', 'active')
					  AND r.date_from <= %s AND r.date_to >= %s
					GROUP BY ri.item_id
				) o ON o.item_id = i.id
				WHERE " . implode( ' AND ', $where ) . '
				ORDER BY i.inventory_number ASC';

		$sql = $wpdb->prepare( $sql, $params );
		return array_map( [ self::class, 'decode_item' ], $wpdb->get_results( $sql ) ?: [] );
	}

	public static function get_item( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT i.*, c.name AS category_name, c.icon AS category_icon
			 FROM ' . Schema::table( 'items' ) . ' i
			 LEFT JOIN ' . Schema::table( 'categories' ) . ' c ON c.id = i.category_id
			 WHERE i.id = %d',
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
			 FROM ' . Schema::table( 'items' ) . ' i
			 LEFT JOIN ' . Schema::table( 'categories' ) . ' c ON c.id = i.category_id
			 WHERE i.inventory_number = %s',
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
			'cost_per_day'     => '%f',
			'purchase_price'   => '%f',
			'current_value'    => '%f',
			'purchase_date'    => '%s',
			'dimensions'       => '%s',
			'power_watts'      => '%d',
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
		$today   = current_time( 'Y-m-d' );

		$out_today = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(ri.quantity), 0)
			 FROM {$lines} ri
			 INNER JOIN {$rentals} r ON r.id = ri.rental_id
			 WHERE r.status IN ('reserved', 'active')
			   AND r.date_from <= %s AND r.date_to >= %s",
			$today,
			$today
		) );

		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS item_count,
					COALESCE(SUM(quantity), 0) AS total_pieces,
					COALESCE(SUM(quantity * COALESCE(cost_per_day, 0)), 0) AS daily_value
			 FROM {$items}"
		);

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
