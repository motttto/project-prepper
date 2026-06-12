<?php
namespace ProjectPrepper;

use ProjectPrepper\Services\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * DSGVO (§17) über die WP-Core-Privacy-Hooks:
 * Export (Art. 15/20) und Löschung/Anonymisierung (Art. 17) der Leiher-Daten,
 * gesucht per E-Mail-Adresse über Werkzeuge → Personenbezogene Daten.
 */
class Privacy {

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'register_eraser' ] );
	}

	public static function register_exporter( array $exporters ): array {
		$exporters['project-prepper-rentals'] = [
			'exporter_friendly_name' => __( 'Project Prepper — Rental Data', 'project-prepper' ),
			'callback'               => [ self::class, 'export_rentals' ],
		];
		return $exporters;
	}

	public static function register_eraser( array $erasers ): array {
		$erasers['project-prepper-rentals'] = [
			'eraser_friendly_name' => __( 'Project Prepper — Rental Data', 'project-prepper' ),
			'callback'             => [ self::class, 'erase_rentals' ],
		];
		return $erasers;
	}

	public static function export_rentals( string $email, int $page = 1 ): array {
		global $wpdb;
		$rentals = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE borrower_email = %s ORDER BY id ASC',
			Schema::table( 'rentals' ),
			$email
		) ) ?: [];

		$export_items = [];
		foreach ( $rentals as $rental ) {
			$export_items[] = [
				'group_id'    => 'pp_rentals',
				'group_label' => __( 'Rental transactions', 'project-prepper' ),
				'item_id'     => 'pp-rental-' . $rental->id,
				'data'        => [
					[ 'name' => __( 'Rental number', 'project-prepper' ), 'value' => $rental->rental_number ],
					[ 'name' => __( 'Name', 'project-prepper' ), 'value' => $rental->borrower_name ],
					[ 'name' => __( 'Email', 'project-prepper' ), 'value' => $rental->borrower_email ],
					[ 'name' => __( 'Phone', 'project-prepper' ), 'value' => $rental->borrower_phone ],
					[ 'name' => __( 'Address', 'project-prepper' ), 'value' => $rental->borrower_address ?? '' ],
					[ 'name' => __( 'Period', 'project-prepper' ), 'value' => $rental->date_from . ' – ' . $rental->date_to ],
					[ 'name' => __( 'Status', 'project-prepper' ), 'value' => $rental->status ],
				],
			];
		}

		return [ 'data' => $export_items, 'done' => true ];
	}

	public static function erase_rentals( string $email, int $page = 1 ): array {
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE borrower_email = %s',
			Schema::table( 'rentals' ),
			$email
		) );

		if ( $count > 0 ) {
			// Anonymisieren statt löschen — die Verleih-Historie (Zahlen) bleibt erhalten.
			$wpdb->query( $wpdb->prepare(
				"UPDATE %i
				 SET borrower_name = %s, borrower_email = '', borrower_phone = '', borrower_address = '', notes = ''
				 WHERE borrower_email = %s",
				Schema::table( 'rentals' ),
				__( 'Anonymized (GDPR)', 'project-prepper' ),
				$email
			) );
			ActivityLog::log( 'gdpr_erasure', 'rental', null, [ 'count' => $count ] );
		}

		return [
			'items_removed'  => $count > 0,
			'items_retained' => false,
			'messages'       => $count > 0
				/* translators: %d: number of anonymized rentals */
				? [ sprintf( __( '%d rentals anonymized.', 'project-prepper' ), $count ) ]
				: [],
			'done'           => true,
		];
	}
}
