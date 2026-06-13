<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Verbrauchsmaterial pro Projekt — Pendant zu project_consumables der App
 * (Felder: name, quantity, unit, cost).
 *
 * cost ist nullable (kein Kostenwert erfasst); quantity hat Default 1,
 * unit ist freier Text (z. B. "Stück", "m", "kg").
 */
class Consumables {

	public static function for_project( int $project_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE project_id = %d ORDER BY sort_order ASC, id ASC',
			Schema::table( 'project_consumables' ),
			$project_id
		) ) ?: [];
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_consumables' ),
			$id
		) );
	}

	/**
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, array $data ) {
		global $wpdb;

		if ( empty( $data['name'] ) || '' === trim( (string) $data['name'] ) ) {
			return new WP_Error( 'pp_missing_name', __( 'Name is required.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$cost = self::sanitize_cost( $data['cost'] ?? '' );
		if ( false === $cost ) {
			return new WP_Error( 'pp_invalid_amount', __( 'Invalid amount.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$wpdb->insert( Schema::table( 'project_consumables' ), [
			'project_id' => $project_id,
			'name'       => trim( (string) $data['name'] ),
			'quantity'   => self::sanitize_quantity( $data['quantity'] ?? null ),
			'unit'       => isset( $data['unit'] ) ? (string) $data['unit'] : '',
			'cost'       => $cost,
			'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
		] );
		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'consumable_created', 'project', $project_id, [ 'name' => trim( (string) $data['name'] ) ] );
		return $id;
	}

	/**
	 * Partielles Update — nur übergebene Keys werden geändert.
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$entry = self::get( $id );
		if ( ! $entry ) {
			return new WP_Error( 'pp_not_found', __( 'Consumable not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}

		$fields = [];
		if ( array_key_exists( 'name', $data ) ) {
			if ( '' === trim( (string) $data['name'] ) ) {
				return new WP_Error( 'pp_missing_name', __( 'Name is required.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['name'] = trim( (string) $data['name'] );
		}
		if ( array_key_exists( 'quantity', $data ) ) {
			$fields['quantity'] = self::sanitize_quantity( $data['quantity'] );
		}
		if ( array_key_exists( 'unit', $data ) ) {
			$fields['unit'] = (string) $data['unit'];
		}
		if ( array_key_exists( 'cost', $data ) ) {
			$cost = self::sanitize_cost( $data['cost'] );
			if ( false === $cost ) {
				return new WP_Error( 'pp_invalid_amount', __( 'Invalid amount.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['cost'] = $cost;
		}
		if ( array_key_exists( 'sort_order', $data ) ) {
			$fields['sort_order'] = (int) $data['sort_order'];
		}
		if ( $fields ) {
			$wpdb->update( Schema::table( 'project_consumables' ), $fields, [ 'id' => $id ] );
			ActivityLog::log( 'consumable_updated', 'project', (int) $entry->project_id, [
				'entry_id' => $id,
				'fields'   => array_keys( $fields ),
			] );
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;
		$entry = self::get( $id );
		if ( ! $entry ) {
			return new WP_Error( 'pp_not_found', __( 'Consumable not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$wpdb->delete( Schema::table( 'project_consumables' ), [ 'id' => $id ], [ '%d' ] );
		ActivityLog::log( 'consumable_deleted', 'project', (int) $entry->project_id, [ 'name' => $entry->name ] );
		return true;
	}

	/**
	 * Menge: nicht-numerisch oder negativ → Default 1, sonst die Zahl (≥0).
	 *
	 * @param mixed $value
	 */
	private static function sanitize_quantity( $value ): float {
		if ( ! is_numeric( $value ) ) {
			return 1.0;
		}
		$num = (float) $value;
		return $num < 0 ? 1.0 : round( $num, 2 );
	}

	/**
	 * Kosten: leer ('' / null) → NULL, sonst nicht-negative Dezimalzahl.
	 * Ungültig (nicht-numerisch / negativ) → false.
	 *
	 * @param mixed $value
	 * @return float|null|false
	 */
	private static function sanitize_cost( $value ) {
		if ( null === $value || '' === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return null;
		}
		if ( ! is_numeric( $value ) ) {
			return false;
		}
		$num = (float) $value;
		if ( $num < 0 ) {
			return false;
		}
		return round( $num, 2 );
	}
}
