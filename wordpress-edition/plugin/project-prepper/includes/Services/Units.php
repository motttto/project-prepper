<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Einzelgeräte / Exemplare (§8.4, im Portal seit Schema 0.45.0).
 *
 * Ein Artikel mit Menge > 1 kann seine Stücke einzeln benennen („Isco 25 mm
 * 2.8", „Doctar 25 mm"), mit eigener Seriennummer, eigenem Zustand und Notiz.
 * Gebucht wird weiterhin pauschal nach Menge; wer ein bestimmtes Exemplar
 * braucht, wählt es in der Buchung aus.
 *
 * Bewusst KEINE abgeleitete Menge: Exemplare sind benannte Stücke DER Menge
 * (höchstens so viele wie die Menge; wer mehr anlegt, hebt die Menge mit an).
 * Unbenannte Reststücke sind erlaubt. So ändert das Update bestehende Bestände
 * nicht, auch wenn aus der Admin-Zeit schon Einzelstücke existieren.
 *
 * Ein Exemplar in einem gesperrten Zustand ({@see Inventory::BLOCKED_CONDITIONS})
 * zählt in der EINEN Verfügbarkeitsrechnung wie ein Stück, das unterwegs ist
 * ({@see Availability::available_quantity}, `out_now` in {@see Inventory::items}).
 */
class Units {

	/** Obergrenze je Artikel (Formular-Schutz). */
	const MAX_PER_ITEM = 200;

	public static function for_item( int $item_id ): array {
		return self::for_items( [ $item_id ] )[ $item_id ] ?? [];
	}

	/**
	 * Exemplare mehrerer Artikel in EINER Abfrage.
	 *
	 * @param array<int> $item_ids
	 * @return array<int,array<object>> item_id => Exemplare (nach Nummer).
	 */
	public static function for_items( array $item_ids ): array {
		global $wpdb;
		$item_ids = array_values( array_unique( array_filter( array_map( 'intval', $item_ids ) ) ) );
		if ( ! $item_ids ) {
			return [];
		}
		$place = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Platzhalter werden oben dynamisch erzeugt.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM %i WHERE item_id IN ($place) ORDER BY unit_number ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nur Platzhalter.
			array_merge( [ Schema::table( 'units' ) ], $item_ids )
		) ) ?: [];
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r->item_id ][] = $r;
		}
		return $out;
	}

	/** Anzeigename eines Exemplars: Bezeichnung, sonst „#Nummer". */
	public static function label( object $unit ): string {
		$label = trim( (string) ( $unit->label ?? '' ) );
		return '' !== $label ? $label : '#' . (int) $unit->unit_number;
	}

	/** Ist das Exemplar gesperrt (defekt, Wartung, verschollen, ausgemustert)? */
	public static function is_blocked( object $unit ): bool {
		return Inventory::is_blocked( (string) ( $unit->unit_condition ?? '' ) );
	}

	/** Anzahl gesperrter Exemplare eines Artikels — mindert die Verfügbarkeit. */
	public static function blocked_count( int $item_id ): int {
		global $wpdb;
		$place = implode( ',', array_fill( 0, count( Inventory::BLOCKED_CONDITIONS ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Platzhalter werden oben dynamisch erzeugt.
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE item_id = %d AND unit_condition IN ($place)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nur Platzhalter.
			array_merge( [ Schema::table( 'units' ), $item_id ], Inventory::BLOCKED_CONDITIONS )
		) );
	}

	public static function create( int $item_id, array $data ): int {
		global $wpdb;

		$next = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COALESCE(MAX(unit_number), 0) + 1 FROM %i WHERE item_id = %d',
			Schema::table( 'units' ),
			$item_id
		) );

		$wpdb->insert( Schema::table( 'units' ), [
			'item_id'        => $item_id,
			'unit_number'    => $next,
			'label'          => self::clean( $data['label'] ?? '' ),
			'serial_number'  => self::clean( $data['serial_number'] ?? '' ),
			'unit_condition' => in_array( $data['condition'] ?? '', Inventory::CONDITIONS, true ) ? $data['condition'] : 'good',
			'notes'          => $data['notes'] ?? '',
		], [ '%d', '%d', '%s', '%s', '%s', '%s' ] );

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
		foreach ( [ 'label', 'serial_number' ] as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$fields[ $key ] = self::clean( (string) $data[ $key ] );
				$formats[]      = '%s';
			}
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

	/**
	 * Exemplar-Abschnitt des Artikel-Formulars übernehmen. Rechte prüft der
	 * Aufrufer (MemberInventory::can_edit). Fremde Exemplar-IDs werden ignoriert
	 * (jede Änderung läuft mit item_id in der WHERE-Klausel).
	 *
	 * Legt der User mehr Exemplare an, als die Menge hergibt, wird die Menge
	 * mitgezogen — „Exemplar hinzufügen" heißt „ich habe noch eins".
	 *
	 * @param array<int,array> $existing unit_id => label, serial_number, condition, notes, remove.
	 * @param array<int,array> $new      Liste neuer Zeilen (label, serial_number, condition, notes).
	 */
	public static function save_from_form( int $item_id, array $existing, array $new ): void {
		global $wpdb;
		$table = Schema::table( 'units' );
		foreach ( $existing as $unit_id => $row ) {
			$unit_id = (int) $unit_id;
			$row     = is_array( $row ) ? $row : [];
			$owned   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %d AND item_id = %d', $table, $unit_id, $item_id ) );
			if ( ! $owned ) {
				continue;
			}
			if ( ! empty( $row['remove'] ) ) {
				self::delete( $unit_id );
				continue;
			}
			self::update( $unit_id, [
				'label'         => (string) ( $row['label'] ?? '' ),
				'serial_number' => (string) ( $row['serial_number'] ?? '' ),
				'condition'     => sanitize_key( (string) ( $row['condition'] ?? 'good' ) ),
				'notes'         => sanitize_textarea_field( (string) ( $row['notes'] ?? '' ) ),
			] );
		}
		$count = count( self::for_item( $item_id ) );
		foreach ( $new as $row ) {
			$row = is_array( $row ) ? $row : [];
			if ( '' === self::clean( (string) ( $row['label'] ?? '' ) ) && '' === self::clean( (string) ( $row['serial_number'] ?? '' ) ) ) {
				continue; // leere Zusatzzeile
			}
			if ( $count >= self::MAX_PER_ITEM ) {
				break;
			}
			self::create( $item_id, [
				'label'         => (string) ( $row['label'] ?? '' ),
				'serial_number' => (string) ( $row['serial_number'] ?? '' ),
				'condition'     => sanitize_key( (string) ( $row['condition'] ?? 'good' ) ),
				'notes'         => sanitize_textarea_field( (string) ( $row['notes'] ?? '' ) ),
			] );
			++$count;
		}
		// Menge mitziehen: nie weniger Stücke als benannte Exemplare.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET quantity = %d WHERE id = %d AND quantity < %d', Schema::table( 'items' ), $count, $item_id, $count ) );
	}

	/**
	 * Exemplare eines Artikels, die im Zeitraum schon GEZIELT gewählt sind —
	 * dieselben Status und dasselbe Buchungsfenster (Rüstzeiten) wie in
	 * Availability::available_quantity(): Verleihe reserved/active, Projekte
	 * confirmed/running mit geerbtem Zeitraum. Ausgenommen wird der eigene
	 * Verleih bzw. die eigene Projekt-Zeile (nicht das ganze Projekt — zwei
	 * Zeilen desselben Projekts dürfen nicht dasselbe Stück greifen).
	 *
	 * @return array<int> Exemplar-IDs.
	 */
	public static function taken( int $item_id, string $from, string $to, int $exclude_rental = 0, int $exclude_project_line = 0 ): array {
		global $wpdb;
		list( $from, $to ) = Availability::booking_window( $from, $to );
		$rows = array_merge(
			(array) $wpdb->get_col( $wpdb->prepare(
				"SELECT ri.unit_ids FROM %i ri INNER JOIN %i r ON r.id = ri.rental_id
				 WHERE ri.item_id = %d AND r.id <> %d AND r.status IN ('reserved', 'active')
				   AND r.date_from <= %s AND r.date_to >= %s
				   AND ri.unit_ids IS NOT NULL AND ri.unit_ids <> ''",
				Schema::table( 'rental_items' ),
				Schema::table( 'rentals' ),
				$item_id,
				$exclude_rental,
				$to,
				$from
			) ),
			(array) $wpdb->get_col( $wpdb->prepare(
				"SELECT pi.unit_ids FROM %i pi INNER JOIN %i p ON p.id = pi.project_id
				 WHERE pi.item_id = %d AND pi.id <> %d AND p.status IN ('confirmed', 'running')
				   AND COALESCE(pi.date_from, p.date_start) IS NOT NULL
				   AND COALESCE(pi.date_to, p.date_end) IS NOT NULL
				   AND COALESCE(pi.date_from, p.date_start) <= %s
				   AND COALESCE(pi.date_to, p.date_end) >= %s
				   AND pi.unit_ids IS NOT NULL AND pi.unit_ids <> ''",
				Schema::table( 'project_items' ),
				Schema::table( 'projects' ),
				$item_id,
				$exclude_project_line,
				$to,
				$from
			) )
		);
		$out = [];
		foreach ( $rows as $json ) {
			foreach ( self::decode_ids( $json ) as $uid ) {
				$out[ $uid ] = $uid;
			}
		}
		return array_values( $out );
	}

	/** JSON-Liste aus einer Buchungszeile → Exemplar-IDs. */
	public static function decode_ids( $json ): array {
		$ids = json_decode( (string) $json, true );
		return is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ) : [];
	}

	/**
	 * Gezielte Exemplar-Wahl einer Buchungszeile prüfen: gehören die Exemplare
	 * zu diesem Artikel, sind sie nicht gesperrt und im Zeitraum nicht schon
	 * anderweitig gewählt? Gerufen innerhalb der Buchungs-Serialisierung
	 * (Locking), damit zwei gleichzeitige Buchungen nicht dasselbe Stück greifen.
	 *
	 * @param array<int> $unit_ids
	 * @return array<int>|\WP_Error Bereinigte IDs.
	 */
	public static function validate_selection( int $item_id, array $unit_ids, string $from, string $to, int $exclude_rental = 0, int $exclude_project_line = 0 ) {
		$unit_ids = array_values( array_unique( array_filter( array_map( 'intval', $unit_ids ) ) ) );
		if ( ! $unit_ids ) {
			return [];
		}
		$own = [];
		foreach ( self::for_item( $item_id ) as $u ) {
			$own[ (int) $u->id ] = $u;
		}
		foreach ( $unit_ids as $uid ) {
			if ( ! isset( $own[ $uid ] ) ) {
				return new \WP_Error( 'pp_unit_invalid', __( 'A selected piece does not belong to this item.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			if ( self::is_blocked( $own[ $uid ] ) ) {
				return new \WP_Error(
					'pp_unit_blocked',
					/* translators: %s: name of the piece. */
					sprintf( __( 'The piece “%s” is not available (broken, in maintenance or lost).', 'project-prepper' ), self::label( $own[ $uid ] ) ),
					[ 'status' => 409 ]
				);
			}
		}
		if ( '' !== $from && '' !== $to ) {
			$busy = array_intersect( $unit_ids, self::taken( $item_id, $from, $to, $exclude_rental, $exclude_project_line ) );
			if ( $busy ) {
				return new \WP_Error(
					'pp_unit_taken',
					/* translators: %s: name of the piece. */
					sprintf( __( 'The piece “%s” is already booked in this period.', 'project-prepper' ), self::label( $own[ (int) reset( $busy ) ] ) ),
					[ 'status' => 409 ]
				);
			}
		}
		return $unit_ids;
	}

	/**
	 * Anzeigenamen gewählter Exemplare (für Karten, Packliste, Mails).
	 *
	 * @return array<string>
	 */
	public static function labels_for( $json ): array {
		global $wpdb;
		$ids = self::decode_ids( $json );
		if ( ! $ids ) {
			return [];
		}
		$place = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Platzhalter werden oben dynamisch erzeugt.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM %i WHERE id IN ($place) ORDER BY unit_number ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nur Platzhalter.
			array_merge( [ Schema::table( 'units' ) ], $ids )
		) ) ?: [];
		return array_map( [ self::class, 'label' ], $rows );
	}

	private static function clean( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 190 ) : substr( $value, 0, 190 );
	}
}
