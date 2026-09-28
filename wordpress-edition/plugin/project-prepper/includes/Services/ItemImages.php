<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Mehrere Fotos je Artikel (Schema 0.45.0).
 *
 * Das Titelbild bleibt `items.image_id` — jede Liste, der Picker, die Packliste
 * und die öffentliche Ausgabe lesen weiterhin nur das. Hier liegen die
 * ZUSÄTZLICHEN Bilder (Tabelle item_images). Wird das Titelbild entfernt,
 * rückt das erste Zusatzbild nach; „Als Titelbild" tauscht die Plätze.
 *
 * Keine Rechteprüfung in diesem Service — die Gates sitzen in
 * MemberInventory (can_edit) bzw. im REST-Controller (Capability).
 */
class ItemImages {

	/** Obergrenze je Artikel, Titelbild mitgezählt. */
	const MAX_PER_ITEM = 12;

	/**
	 * Zusatzbilder eines Artikels (Attachment-IDs in Anzeige-Reihenfolge).
	 *
	 * @return array<int>
	 */
	public static function extras( int $item_id ): array {
		return self::extras_for( [ $item_id ] )[ $item_id ] ?? [];
	}

	/**
	 * Zusatzbilder mehrerer Artikel in EINER Abfrage.
	 *
	 * @param array<int> $item_ids
	 * @return array<int,array<int>> item_id => Attachment-IDs.
	 */
	public static function extras_for( array $item_ids ): array {
		global $wpdb;
		$item_ids = array_values( array_unique( array_filter( array_map( 'intval', $item_ids ) ) ) );
		if ( ! $item_ids ) {
			return [];
		}
		$place = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Platzhalter werden oben dynamisch erzeugt.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT item_id, attachment_id FROM %i WHERE item_id IN ($place) ORDER BY sort_order ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nur Platzhalter.
			array_merge( [ Schema::table( 'item_images' ) ], $item_ids )
		) ) ?: [];
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r->item_id ][] = (int) $r->attachment_id;
		}
		return $out;
	}

	/**
	 * Alle Fotos eines Artikels, Titelbild zuerst — fertig für die Anzeige.
	 *
	 * @param array<int>|null $extras Vorab geladene Zusatzbilder (spart die Abfrage).
	 * @return array<int,array{id:int,thumb:string,medium:string,large:string,cover:bool}>
	 */
	public static function gallery( object $item, ?array $extras = null ): array {
		$ids = [];
		if ( ! empty( $item->image_id ) ) {
			$ids[] = (int) $item->image_id;
		}
		foreach ( null === $extras ? self::extras( (int) $item->id ) : $extras as $att ) {
			if ( ! in_array( (int) $att, $ids, true ) ) {
				$ids[] = (int) $att;
			}
		}
		$out = [];
		foreach ( $ids as $i => $att ) {
			$large = wp_get_attachment_image_url( $att, 'large' );
			if ( ! $large ) {
				continue; // Attachment gelöscht — Zeile wird beim nächsten Speichern bereinigt.
			}
			$out[] = [
				'id'     => $att,
				'thumb'  => (string) ( wp_get_attachment_image_url( $att, 'thumbnail' ) ?: $large ),
				'medium' => (string) ( wp_get_attachment_image_url( $att, 'medium' ) ?: $large ),
				'large'  => (string) $large,
				'cover'  => 0 === $i && ! empty( $item->image_id ),
			];
		}
		return $out;
	}

	/** Anzahl Fotos inkl. Titelbild. */
	public static function count( object $item ): int {
		return ( empty( $item->image_id ) ? 0 : 1 ) + count( self::extras( (int) $item->id ) );
	}

	/**
	 * Foto anhängen: ohne Titelbild wird es das Titelbild, sonst Zusatzbild.
	 * Über der Obergrenze → false (das Attachment räumt der Aufrufer ab).
	 */
	public static function add( int $item_id, int $attachment_id ): bool {
		global $wpdb;
		$item = Inventory::get_item( $item_id );
		if ( ! $item || $attachment_id <= 0 ) {
			return false;
		}
		if ( self::count( $item ) >= self::MAX_PER_ITEM ) {
			return false;
		}
		if ( empty( $item->image_id ) ) {
			return Inventory::update_item( $item_id, [ 'image_id' => $attachment_id ] );
		}
		$table = Schema::table( 'item_images' );
		$next  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(sort_order),0)+1 FROM %i WHERE item_id = %d', $table, $item_id ) );
		return false !== $wpdb->insert( $table, [
			'item_id'       => $item_id,
			'attachment_id' => $attachment_id,
			'sort_order'    => $next,
			'created_at'    => current_time( 'mysql' ),
		], [ '%d', '%d', '%d', '%s' ] );
	}

	/**
	 * Foto entfernen. Ist es das Titelbild, rückt das erste Zusatzbild nach.
	 * Das Attachment wird gelöscht, sobald kein Artikel es mehr nutzt.
	 */
	public static function remove( int $item_id, int $attachment_id ): void {
		global $wpdb;
		$item = Inventory::get_item( $item_id );
		if ( ! $item || $attachment_id <= 0 ) {
			return;
		}
		$table = Schema::table( 'item_images' );
		if ( (int) $item->image_id === $attachment_id ) {
			$next = self::extras( $item_id )[0] ?? 0;
			Inventory::update_item( $item_id, [ 'image_id' => $next ?: null ] );
			if ( $next ) {
				$wpdb->delete( $table, [ 'item_id' => $item_id, 'attachment_id' => $next ], [ '%d', '%d' ] );
			}
		} else {
			$gone = $wpdb->delete( $table, [ 'item_id' => $item_id, 'attachment_id' => $attachment_id ], [ '%d', '%d' ] );
			if ( ! $gone ) {
				return; // gehört nicht zu diesem Artikel — nichts anfassen.
			}
		}
		self::drop_attachment( $attachment_id );
	}

	/** Zusatzbild zum Titelbild machen; das bisherige Titelbild wird Zusatzbild. */
	public static function set_cover( int $item_id, int $attachment_id ): void {
		global $wpdb;
		$item = Inventory::get_item( $item_id );
		if ( ! $item || (int) $item->image_id === $attachment_id ) {
			return;
		}
		$table = Schema::table( 'item_images' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT id, sort_order FROM %i WHERE item_id = %d AND attachment_id = %d', $table, $item_id, $attachment_id ) );
		if ( ! $row ) {
			return;
		}
		if ( ! empty( $item->image_id ) ) {
			// Plätze tauschen: das alte Titelbild übernimmt die Zeile des neuen.
			$wpdb->update( $table, [ 'attachment_id' => (int) $item->image_id ], [ 'id' => (int) $row->id ], [ '%d' ], [ '%d' ] );
		} else {
			$wpdb->delete( $table, [ 'id' => (int) $row->id ], [ '%d' ] );
		}
		Inventory::update_item( $item_id, [ 'image_id' => $attachment_id ] );
	}

	/**
	 * Beim Löschen eines Artikels: Zusatzbilder-Zeilen und alle Foto-Attachments
	 * (Titelbild + Zusatzbilder) entfernen, sofern kein anderer Artikel sie nutzt.
	 */
	public static function delete_for_item( object $item ): void {
		global $wpdb;
		$atts = self::extras( (int) $item->id );
		$wpdb->delete( Schema::table( 'item_images' ), [ 'item_id' => (int) $item->id ], [ '%d' ] );
		if ( ! empty( $item->image_id ) ) {
			$atts[] = (int) $item->image_id;
		}
		foreach ( array_unique( $atts ) as $att ) {
			self::drop_attachment( (int) $att, (int) $item->id );
		}
	}

	/**
	 * Attachment löschen, wenn es kein (anderer) Artikel mehr als Foto nutzt.
	 * Nur Bilder — Dokumente und andere Medien bleiben unangetastet.
	 */
	private static function drop_attachment( int $attachment_id, int $ignore_item = 0 ): void {
		global $wpdb;
		if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}
		$as_cover = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE image_id = %d AND id <> %d',
			Schema::table( 'items' ),
			$attachment_id,
			$ignore_item
		) );
		$as_extra = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE attachment_id = %d AND item_id <> %d',
			Schema::table( 'item_images' ),
			$attachment_id,
			$ignore_item
		) );
		if ( 0 === $as_cover + $as_extra ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}
}
