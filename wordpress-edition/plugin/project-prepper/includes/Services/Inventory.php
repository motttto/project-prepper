<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Inventar-Service: Artikel + Kategorien (CRUD und Suche).
 */
class Inventory {

	const CONDITIONS = [ 'new', 'good', 'used', 'defect' ];

	/* ---------- Kategorien ---------- */

	public static function categories(): array {
		global $wpdb;
		return $wpdb->get_results(
			'SELECT * FROM ' . Schema::table( 'categories' ) . ' ORDER BY sort_order ASC, name ASC'
		) ?: [];
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

	/* ---------- Artikel ---------- */

	public static function items( array $args = [] ): array {
		global $wpdb;
		$items = Schema::table( 'items' );
		$cats  = Schema::table( 'categories' );

		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(i.name LIKE %s OR i.inventory_number LIKE %s OR i.manufacturer LIKE %s OR i.model LIKE %s OR i.tags LIKE %s)';
			array_push( $params, $like, $like, $like, $like, $like );
		}
		if ( ! empty( $args['category_id'] ) ) {
			$where[]  = 'i.category_id = %d';
			$params[] = (int) $args['category_id'];
		}

		$sql = "SELECT i.*, c.name AS category_name, c.icon AS category_icon
				FROM {$items} i
				LEFT JOIN {$cats} c ON c.id = i.category_id
				WHERE " . implode( ' AND ', $where ) . '
				ORDER BY i.inventory_number ASC';

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}
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

	public static function create_item( array $data ): int {
		global $wpdb;

		$category_id = ! empty( $data['category_id'] ) ? (int) $data['category_id'] : null;
		$number      = ! empty( $data['inventory_number'] )
			? $data['inventory_number']
			: Numbering::next_inventory_number( $category_id );

		$now = current_time( 'mysql' );
		$wpdb->insert( Schema::table( 'items' ), [
			'inventory_number' => $number,
			'category_id'      => $category_id,
			'name'             => $data['name'],
			'description'      => $data['description'] ?? '',
			'manufacturer'     => $data['manufacturer'] ?? '',
			'model'            => $data['model'] ?? '',
			'tags'             => wp_json_encode( $data['tags'] ?? [] ),
			'quantity'         => max( 1, (int) ( $data['quantity'] ?? 1 ) ),
			'item_condition'   => in_array( $data['condition'] ?? '', self::CONDITIONS, true ) ? $data['condition'] : 'good',
			'location'         => $data['location'] ?? '',
			'cost_per_day'     => isset( $data['cost_per_day'] ) && '' !== $data['cost_per_day'] ? (float) $data['cost_per_day'] : null,
			'current_value'    => isset( $data['current_value'] ) && '' !== $data['current_value'] ? (float) $data['current_value'] : null,
			'purchase_date'    => $data['purchase_date'] ?? null,
			'image_id'         => ! empty( $data['image_id'] ) ? (int) $data['image_id'] : null,
			'document_ids'     => wp_json_encode( $data['document_ids'] ?? [] ),
			'notes'            => $data['notes'] ?? '',
			'created_by'       => get_current_user_id() ?: null,
			'created_at'       => $now,
			'updated_at'       => $now,
		] );

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
			'quantity'         => '%d',
			'location'         => '%s',
			'cost_per_day'     => '%f',
			'current_value'    => '%f',
			'purchase_date'    => '%s',
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

	private static function decode_item( object $row ): object {
		$row->tags         = json_decode( $row->tags ?? '[]' ) ?: [];
		$row->document_ids = json_decode( $row->document_ids ?? '[]' ) ?: [];
		$row->condition    = $row->item_condition;
		$row->image_url    = $row->image_id ? ( wp_get_attachment_image_url( (int) $row->image_id, 'medium' ) ?: null ) : null;
		return $row;
	}
}
