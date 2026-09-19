<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Eigene Artikel-Felder (Schema 0.44.0).
 *
 * Jedes Mitglied darf dem Artikel-Formular ein Feld hinzufügen („Gewicht",
 * „Stromanschluss" …). Die DEFINITION gilt instanzweit — sie erscheint danach
 * im Formular ALLER Mitglieder; der WERT hängt am einzelnen Artikel und ist für
 * jeden sichtbar, der den Artikel sehen darf.
 *
 * Bewusst schlicht: nur einzeilige Textfelder, keine Typen, keine Pflichtfelder.
 * Weil jeder anlegen darf, gibt es zwei Bremsen gegen Wildwuchs: gleiche
 * Bezeichnung (ohne Groß/Klein) wird wiederverwendet statt verdoppelt, und die
 * Zahl der Definitionen ist gedeckelt. Löschen darf nur der Betreiber — eine
 * Definition zu entfernen löscht die Werte ALLER Mitglieder.
 */
class ItemFields {

	const MAX_DEFS      = 40;
	const MAX_LABEL_LEN = 60;
	const MAX_VALUE_LEN = 500;

	/** @var array<int,object>|null Request-Cache der Definitionen. */
	private static $defs = null;

	/** @var array<int,array<int,string>> Request-Cache der Werte je Artikel (auch leere Treffer). */
	private static $values = [];

	/** @return array<int,object> Definitionen in Anzeige-Reihenfolge, Schlüssel = ID. */
	public static function defs(): array {
		if ( null !== self::$defs ) {
			return self::$defs;
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY sort_order ASC, id ASC', Schema::table( 'item_field_defs' ) ) ) ?: [];
		self::$defs = [];
		foreach ( $rows as $row ) {
			$row->id                     = (int) $row->id;
			self::$defs[ (int) $row->id ] = $row;
		}
		return self::$defs;
	}

	/**
	 * Definition anlegen — oder die vorhandene mit gleicher Bezeichnung liefern.
	 *
	 * @return int|WP_Error Feld-ID.
	 */
	public static function create_def( int $user_id, string $label ) {
		$label = self::clean_label( $label );
		if ( '' === $label ) {
			return new WP_Error( 'pp_field_label', __( 'Please enter a name for the new field.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		foreach ( self::defs() as $def ) {
			if ( 0 === strcasecmp( (string) $def->label, $label ) ) {
				return (int) $def->id;
			}
		}
		if ( count( self::defs() ) >= self::MAX_DEFS ) {
			return new WP_Error( 'pp_field_limit', __( 'The maximum number of custom fields is reached. Ask the operators to tidy up.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		global $wpdb;
		$ok = $wpdb->insert(
			Schema::table( 'item_field_defs' ),
			[
				'label'      => $label,
				'created_by' => $user_id,
				'sort_order' => count( self::defs() ),
				'created_at' => current_time( 'mysql' ),
			],
			[ '%s', '%d', '%d', '%s' ]
		);
		if ( ! $ok ) {
			return new WP_Error( 'pp_field_save', __( 'The field could not be created.', 'project-prepper' ), [ 'status' => 500 ] );
		}
		$id         = (int) $wpdb->insert_id;
		self::$defs = null;
		ActivityLog::log( 'item_field_created', 'item_field', $id, [ 'label' => $label ] );
		return $id;
	}

	/** Definition umbenennen (Betreiber). */
	public static function rename_def( int $id, string $label ) {
		if ( ! current_user_can( Capabilities::OPERATE ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only operators can change custom fields.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$label = self::clean_label( $label );
		if ( '' === $label || ! isset( self::defs()[ $id ] ) ) {
			return new WP_Error( 'pp_field_label', __( 'Please enter a name for the new field.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		foreach ( self::defs() as $def ) {
			if ( (int) $def->id !== $id && 0 === strcasecmp( (string) $def->label, $label ) ) {
				return new WP_Error( 'pp_field_exists', __( 'A field with this name already exists.', 'project-prepper' ), [ 'status' => 400 ] );
			}
		}
		global $wpdb;
		$wpdb->update( Schema::table( 'item_field_defs' ), [ 'label' => $label ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
		self::$defs = null;
		return true;
	}

	/** Definition samt ALLER Werte löschen (Betreiber). */
	public static function delete_def( int $id ) {
		if ( ! current_user_can( Capabilities::OPERATE ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only operators can change custom fields.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$def = self::defs()[ $id ] ?? null;
		if ( ! $def ) {
			return new WP_Error( 'pp_not_found', __( 'Field not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		global $wpdb;
		$wpdb->delete( Schema::table( 'item_field_values' ), [ 'field_id' => $id ], [ '%d' ] );
		$wpdb->delete( Schema::table( 'item_field_defs' ), [ 'id' => $id ], [ '%d' ] );
		self::$defs   = null;
		self::$values = [];
		ActivityLog::log( 'item_field_deleted', 'item_field', $id, [ 'label' => $def->label ] );
		return true;
	}

	/** @return array<int,int> field_id => Anzahl Artikel mit Wert (Betreiber-Übersicht). */
	public static function usage(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT field_id, COUNT(*) AS n FROM %i GROUP BY field_id', Schema::table( 'item_field_values' ) ) ) ?: [];
		$out  = [];
		foreach ( $rows as $row ) {
			$out[ (int) $row->field_id ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * Werte mehrerer Artikel in EINER Abfrage (Listen rendern viele Modals).
	 *
	 * @param array<int> $item_ids
	 * @return array<int,array<int,string>> item_id => [ field_id => value ].
	 */
	public static function values_for( array $item_ids ): array {
		$item_ids = array_values( array_unique( array_filter( array_map( 'intval', $item_ids ) ) ) );
		if ( ! $item_ids ) {
			return [];
		}
		// Eine Liste lädt alle Artikel vorab (ein Query); das Formular je Artikel
		// fragt danach einzeln und trifft nur noch den Cache.
		$missing = array_values( array_filter( $item_ids, static fn( $id ) => ! isset( self::$values[ $id ] ) ) );
		if ( $missing && self::defs() ) {
			global $wpdb;
			$in   = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT item_id, field_id, value FROM %i WHERE item_id IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- nur %d-Platzhalter.
				array_merge( [ Schema::table( 'item_field_values' ) ], $missing )
			) ) ?: [];
			foreach ( $missing as $id ) {
				self::$values[ $id ] = [];
			}
			foreach ( $rows as $row ) {
				self::$values[ (int) $row->item_id ][ (int) $row->field_id ] = (string) $row->value;
			}
		}
		$out = [];
		foreach ( $item_ids as $id ) {
			$out[ $id ] = self::$values[ $id ] ?? [];
		}
		return $out;
	}

	/**
	 * Ausgefüllte Felder eines Artikels für die Anzeige.
	 *
	 * @param array<int,string> $values field_id => value (aus values_for()).
	 * @return array<string,string> Bezeichnung => Wert, in Feld-Reihenfolge.
	 */
	public static function labelled( array $values ): array {
		$out = [];
		foreach ( self::defs() as $id => $def ) {
			if ( isset( $values[ $id ] ) && '' !== $values[ $id ] ) {
				$out[ (string) $def->label ] = $values[ $id ];
			}
		}
		return $out;
	}

	/**
	 * Werte eines Artikels speichern. Nur übergebene Felder werden angefasst;
	 * ein leerer Wert löscht die Zeile. Die Berechtigung (Eigentümer) prüft der
	 * Aufrufer — hier landet nur, was MemberInventory bereits durchgelassen hat.
	 *
	 * @param array<int,string> $values field_id => value.
	 */
	public static function save_values( int $item_id, array $values ): void {
		global $wpdb;
		$table = Schema::table( 'item_field_values' );
		$defs  = self::defs();
		unset( self::$values[ $item_id ] );
		foreach ( $values as $field_id => $value ) {
			$field_id = (int) $field_id;
			if ( ! isset( $defs[ $field_id ] ) ) {
				continue;
			}
			$value = self::clean_value( (string) $value );
			if ( '' === $value ) {
				$wpdb->delete( $table, [ 'item_id' => $item_id, 'field_id' => $field_id ], [ '%d', '%d' ] );
				continue;
			}
			// UNIQUE (item_id, field_id) → REPLACE ist das Upsert ohne Wettlauf.
			$wpdb->replace( $table, [ 'item_id' => $item_id, 'field_id' => $field_id, 'value' => $value ], [ '%d', '%d', '%s' ] );
		}
	}

	/** Aufräumen beim Löschen eines Artikels. */
	public static function delete_for_item( int $item_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'item_field_values' ), [ 'item_id' => $item_id ], [ '%d' ] );
	}

	public static function clean_label( string $label ): string {
		$label = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( $label ) ) );
		$label = rtrim( $label, ':' );
		return function_exists( 'mb_substr' ) ? mb_substr( $label, 0, self::MAX_LABEL_LEN ) : substr( $label, 0, self::MAX_LABEL_LEN );
	}

	public static function clean_value( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::MAX_VALUE_LEN ) : substr( $value, 0, self::MAX_VALUE_LEN );
	}
}
