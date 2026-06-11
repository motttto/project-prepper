<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Services\ActivityLog;
use ProjectPrepper\Services\Inventory;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Excel-/CSV-Import & -Export (§8.6).
 *
 * Export: CSV (Semikolon + BOM → öffnet sauber im deutschen Excel), 19 Spalten.
 * Import: Das Admin-UI parst die Datei client-seitig und schickt gemappte Zeilen
 * als JSON-Batch; Antwort enthält eine Fehlerliste pro Zeile.
 */
class ImportExportController extends BaseController {

	const EXPORT_COLUMNS = [
		'inventory_number' => 'Inventarnummer',
		'name'             => 'Name',
		'category_name'    => 'Kategorie',
		'description'      => 'Beschreibung',
		'manufacturer'     => 'Hersteller',
		'model'            => 'Modell',
		'serial_number'    => 'Seriennummer',
		'quantity'         => 'Menge',
		'condition'        => 'Zustand',
		'location'         => 'Lagerort',
		'cost_per_day'     => 'Tagessatz',
		'purchase_price'   => 'Kaufpreis',
		'purchase_date'    => 'Kaufdatum',
		'current_value'    => 'Aktueller Wert',
		'dimensions'       => 'Maße',
		'power_watts'      => 'Leistung (W)',
		'accessories'      => 'Zubehör',
		'tags'             => 'Tags',
		'notes'            => 'Notizen',
	];

	// Zustands-Mapping für den Import ("neu"→new, "defekt"→broken, … §8.6).
	const CONDITION_MAP = [
		'neu'          => 'new',
		'new'          => 'new',
		'gut'          => 'good',
		'good'         => 'good',
		'ok'           => 'fair',
		'mittel'       => 'fair',
		'gebraucht'    => 'fair',
		'fair'         => 'fair',
		'schlecht'     => 'poor',
		'poor'         => 'poor',
		'defekt'       => 'broken',
		'kaputt'       => 'broken',
		'broken'       => 'broken',
		'ausgemustert' => 'retired',
		'retired'      => 'retired',
	];

	const CONDITION_LABELS = [
		'new'     => 'Neu',
		'good'    => 'Gut',
		'fair'    => 'Gebraucht',
		'poor'    => 'Schlecht',
		'broken'  => 'Defekt',
		'retired' => 'Ausgemustert',
	];

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/export', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'export_csv' ],
			'permission_callback' => $this->require_cap( Capabilities::IMPORT_EXPORT ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/import', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'import' ],
			'permission_callback' => $this->require_cap( Capabilities::IMPORT_EXPORT ),
		] );
	}

	public function export_csv( WP_REST_Request $request ) {
		$items = Inventory::items( [
			'search'      => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'category_id' => (int) $request->get_param( 'category_id' ),
		] );

		$out = fopen( 'php://output', 'w' );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="inventar-' . current_time( 'Y-m-d' ) . '.csv"' );
		echo "\xEF\xBB\xBF"; // BOM für Excel

		fputcsv( $out, array_values( self::EXPORT_COLUMNS ), ';' );
		foreach ( $items as $item ) {
			$row = [];
			foreach ( array_keys( self::EXPORT_COLUMNS ) as $key ) {
				if ( 'condition' === $key ) {
					$row[] = self::CONDITION_LABELS[ $item->condition ] ?? $item->condition;
				} elseif ( 'tags' === $key ) {
					$row[] = implode( ', ', (array) $item->tags );
				} else {
					$row[] = $item->{$key} ?? '';
				}
			}
			fputcsv( $out, $row, ';' );
		}

		ActivityLog::log( 'inventory_exported', 'item', null, [ 'count' => count( $items ) ] );
		exit;
	}

	public function import( WP_REST_Request $request ): WP_REST_Response {
		$rows = (array) ( $request->get_json_params()['rows'] ?? [] );

		// Kategorien einmal laden, Name → ID (neue Kategorien werden angelegt).
		$categories = [];
		foreach ( Inventory::categories() as $cat ) {
			$categories[ mb_strtolower( $cat->name ) ] = (int) $cat->id;
		}

		$created = 0;
		$errors  = [];

		foreach ( $rows as $index => $row ) {
			$row  = (array) $row;
			$name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
			if ( '' === $name ) {
				$errors[] = [ 'row' => $index + 1, 'message' => __( 'Name fehlt.', 'project-prepper' ) ];
				continue;
			}

			$category_id = null;
			$cat_name    = sanitize_text_field( (string) ( $row['category'] ?? '' ) );
			if ( '' !== $cat_name ) {
				$cat_key = mb_strtolower( $cat_name );
				if ( ! isset( $categories[ $cat_key ] ) ) {
					$categories[ $cat_key ] = Inventory::create_category( [
						'name'   => $cat_name,
						'prefix' => mb_strtoupper( mb_substr( $cat_name, 0, 3 ) ),
					] );
				}
				$category_id = $categories[ $cat_key ];
			}

			$condition_raw = mb_strtolower( trim( (string) ( $row['condition'] ?? '' ) ) );

			try {
				$item_id = Inventory::create_item( [
					'name'             => $name,
					'inventory_number' => sanitize_text_field( (string) ( $row['inventory_number'] ?? '' ) ),
					'category_id'      => $category_id,
					'description'      => sanitize_textarea_field( (string) ( $row['description'] ?? '' ) ),
					'manufacturer'     => sanitize_text_field( (string) ( $row['manufacturer'] ?? '' ) ),
					'model'            => sanitize_text_field( (string) ( $row['model'] ?? '' ) ),
					'serial_number'    => sanitize_text_field( (string) ( $row['serial_number'] ?? '' ) ),
					'quantity'         => max( 1, (int) ( $row['quantity'] ?? 1 ) ),
					'condition'        => self::CONDITION_MAP[ $condition_raw ] ?? 'good',
					'location'         => sanitize_text_field( (string) ( $row['location'] ?? '' ) ),
					'cost_per_day'     => self::num( $row['cost_per_day'] ?? '' ),
					'purchase_price'   => self::num( $row['purchase_price'] ?? '' ),
					'current_value'    => self::num( $row['current_value'] ?? '' ),
					'dimensions'       => sanitize_text_field( (string) ( $row['dimensions'] ?? '' ) ),
					'power_watts'      => '' !== trim( (string) ( $row['power_watts'] ?? '' ) ) ? (int) $row['power_watts'] : '',
					'accessories'      => sanitize_textarea_field( (string) ( $row['accessories'] ?? '' ) ),
					'tags'             => array_filter( array_map( 'trim', explode( ',', (string) ( $row['tags'] ?? '' ) ) ) ),
					'notes'            => sanitize_textarea_field( (string) ( $row['notes'] ?? '' ) ),
				] );
				if ( $item_id ) {
					$created++;
				} else {
					$errors[] = [ 'row' => $index + 1, 'message' => __( 'Konnte nicht angelegt werden (Inventarnummer bereits vergeben?).', 'project-prepper' ) ];
				}
			} catch ( \Throwable $e ) {
				$errors[] = [ 'row' => $index + 1, 'message' => $e->getMessage() ];
			}
		}

		ActivityLog::log( 'inventory_imported', 'item', null, [ 'created' => $created, 'errors' => count( $errors ) ] );

		return new WP_REST_Response( [ 'created' => $created, 'errors' => $errors ] );
	}

	private static function num( $value ): string {
		$value = str_replace( ',', '.', trim( (string) $value ) );
		return '' === $value || ! is_numeric( $value ) ? '' : $value;
	}
}
