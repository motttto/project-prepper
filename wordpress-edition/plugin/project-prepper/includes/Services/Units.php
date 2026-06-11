<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Einzelstücke (§8.4): optionales Tracking pro Stück bei Menge > 1 —
 * fortlaufende unit_number, eigener Zustand, Notizen.
 */
class Units {

	public static function for_item( int $item_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . Schema::table( 'units' ) . ' WHERE item_id = %d ORDER BY unit_number ASC',
			$item_id
		) ) ?: [];
	}

	public static function create( int $item_id, array $data ): int {
		global $wpdb;

		$next = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COALESCE(MAX(unit_number), 0) + 1 FROM ' . Schema::table( 'units' ) . ' WHERE item_id = %d',
			$item_id
		) );

		$wpdb->insert( Schema::table( 'units' ), [
			'item_id'        => $item_id,
			'unit_number'    => $next,
			'unit_condition' => in_array( $data['condition'] ?? '', Inventory::CONDITIONS, true ) ? $data['condition'] : 'good',
			'notes'          => $data['notes'] ?? '',
		], [ '%d', '%d', '%s', '%s' ] );

		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$fields  = [];
		$formats = [];
		if ( array_key_exists( 'condition', $data ) && in_array( $data['condition'], Inventory::CONDITIONS, true ) ) {
			$fields['unit_condition'] = $data['condition'];
			$formats[]                = '%s';
		}
		if ( array_key_exists( 'notes', $data ) ) {
			$fields['notes'] = $data['notes'];
			$formats[]       = '%s';
		}
		if ( ! $fields ) {
			return false;
		}
		return false !== $wpdb->update( Schema::table( 'units' ), $fields, [ 'id' => $id ], $formats, [ '%d' ] );
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( Schema::table( 'units' ), [ 'id' => $id ], [ '%d' ] );
	}
}
