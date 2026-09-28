<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Verbrauchsmaterial: Bestand automatisch reduzieren (Feedback „Verbrauchs-
 * materialien anders einbetten: nach Verleih wird der Bestand reduziert").
 *
 * Ein Artikel mit `is_consumable = 1` (Klebeband, Fluid, Batterien …) wird wie
 * jedes Gerät gebucht und verliehen — die Verfügbarkeit bleibt die EINE
 * berechnete Zahl aus {@see Availability::available_quantity}. Neu ist nur,
 * dass nach dem Ende eines Vorgangs die verbrauchte Menge vom Bestand abgeht:
 *
 *   - externer Verleih → `returned` (pp_rental_status_changed; der Wechsel ist
 *     ein bedingtes UPDATE und `returned` ein Endstatus → genau ein Lauf),
 *   - Kollektiv-Leihe → zurückgegeben (pp_borrow_returned; übergeben werden nur
 *     die Zeilen, die DIESER Aufruf per bedingtem UPDATE umgestellt hat),
 *   - Projekt → `done` (pp_project_status_changed; je Buchungszeile ein
 *     Einmal-Marker `consumed_at`, damit done → running → done nicht doppelt
 *     abzieht).
 *
 * Set-Positionen sind bereits in Teil-Zeilen expandiert — abgezogen wird je
 * Teil, sofern DAS TEIL Verbrauchsmaterial ist. Föderierte Leihen bleiben außen
 * vor (keine Mengenangabe, fremde Instanz — der Eigentümer korrigiert von Hand).
 *
 * Der Abzug selbst ist EIN atomares UPDATE mit Untergrenze 0 und der Bedingung
 * `is_consumable = 1` — ein normales Gerät verliert nie Bestand, auch wenn der
 * Haken zwischen Buchung und Rückgabe entfernt wurde.
 */
class ConsumableStock {

	public static function init(): void {
		add_action( 'pp_rental_status_changed', [ self::class, 'on_rental_status_changed' ], 10, 3 );
		add_action( 'pp_borrow_returned', [ self::class, 'on_borrow_returned' ], 10, 3 );
		add_action( 'pp_project_status_changed', [ self::class, 'on_project_status_changed' ], 10, 3 );
	}

	/** Externer Verleih zurückgegeben: freigegebene Verbrauchs-Positionen abziehen. */
	public static function on_rental_status_changed( int $rental_id, string $from, string $to ): void {
		global $wpdb;
		if ( 'returned' !== $to ) {
			return;
		}
		// Nur freigegebene Positionen: Was beim Zurückgeben noch auf die Freigabe
		// seines Eigentümers wartete, hat das Haus nie verlassen.
		$lines = $wpdb->get_results( $wpdb->prepare(
			"SELECT ri.id, ri.item_id, ri.quantity
			 FROM %i ri
			 INNER JOIN %i i ON i.id = ri.item_id
			 WHERE ri.rental_id = %d AND ri.approval_status = 'approved' AND i.is_consumable = 1
			 ORDER BY ri.id ASC",
			Schema::table( 'rental_items' ),
			Schema::table( 'items' ),
			$rental_id
		) ) ?: [];
		foreach ( $lines as $line ) {
			self::deduct( (int) $line->item_id, (int) $line->quantity, 'rental', $rental_id, [ 'line_id' => (int) $line->id ] );
		}
	}

	/**
	 * Kollektiv-Leihe zurückgegeben.
	 *
	 * @param int   $request_id Vorgang (bei Sets die klammernde Zeile).
	 * @param int   $actor_id   Wer die Rückgabe gemeldet hat.
	 * @param int[] $row_ids    Die Zeilen, die dieser Aufruf auf `returned` gesetzt hat.
	 */
	public static function on_borrow_returned( int $request_id, int $actor_id = 0, array $row_ids = [] ): void {
		global $wpdb;
		// Wenige Zeilen (eine, bei Sets je Teil eine) — je Zeile eine Abfrage.
		foreach ( array_unique( array_filter( array_map( 'intval', $row_ids ) ) ) as $row_id ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT b.id, b.item_id, b.quantity
				 FROM %i b
				 INNER JOIN %i i ON i.id = b.item_id
				 WHERE b.id = %d AND b.status = 'returned' AND i.is_consumable = 1",
				Schema::table( 'borrow_requests' ),
				Schema::table( 'items' ),
				$row_id
			) );
			if ( $row ) {
				self::deduct( (int) $row->item_id, max( 1, (int) $row->quantity ), 'borrow', $request_id, [ 'line_id' => (int) $row->id ] );
			}
		}
	}

	/** Projekt abgeschlossen: jede freigegebene Verbrauchs-Zeile genau einmal abziehen. */
	public static function on_project_status_changed( int $project_id, string $from, string $to ): void {
		global $wpdb;
		if ( 'done' !== $to ) {
			return;
		}
		$lines = $wpdb->get_results( $wpdb->prepare(
			"SELECT pi.id, pi.item_id, pi.quantity
			 FROM %i pi
			 INNER JOIN %i i ON i.id = pi.item_id
			 WHERE pi.project_id = %d AND pi.approval_status = 'approved'
			   AND pi.consumed_at IS NULL AND i.is_consumable = 1
			 ORDER BY pi.id ASC",
			Schema::table( 'project_items' ),
			Schema::table( 'items' ),
			$project_id
		) ) ?: [];
		foreach ( $lines as $line ) {
			// Zeile zuerst für sich beanspruchen (bedingt auf „noch nicht
			// verbraucht"): Nur wer den Marker setzt, zieht ab — ein zweiter
			// Abschluss oder ein paralleler Lauf findet 0 betroffene Zeilen.
			$claimed = $wpdb->query( $wpdb->prepare(
				'UPDATE %i SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL',
				Schema::table( 'project_items' ),
				current_time( 'mysql' ),
				(int) $line->id
			) );
			if ( 1 !== (int) $claimed ) {
				continue;
			}
			self::deduct( (int) $line->item_id, (int) $line->quantity, 'project', $project_id, [ 'line_id' => (int) $line->id ] );
		}
	}

	/**
	 * Bestand eines Verbrauchsartikels um $qty senken (nie unter 0).
	 *
	 * `updated_at` wird mitgesetzt: Ein offenes Bearbeiten-Formular mit dem alten
	 * Bestand scheitert dann an der Konflikterkennung (pp_seen), statt den Abzug
	 * beim Speichern still zu überschreiben.
	 */
	private static function deduct( int $item_id, int $qty, string $source, int $source_id, array $meta = [] ): void {
		global $wpdb;
		if ( $item_id <= 0 || $qty <= 0 ) {
			return;
		}
		$items   = Schema::table( 'items' );
		$changed = $wpdb->query( $wpdb->prepare(
			'UPDATE %i SET quantity = GREATEST(quantity - %d, 0), updated_at = %s WHERE id = %d AND is_consumable = 1',
			$items,
			$qty,
			current_time( 'mysql' ),
			$item_id
		) );
		if ( ! $changed ) {
			// Kein Verbrauchsmaterial (mehr) oder Artikel gelöscht — nichts zu tun.
			return;
		}
		$stock = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT quantity FROM %i WHERE id = %d', $items, $item_id ) );
		ActivityLog::log( 'consumable_used', 'item', $item_id, array_merge( [
			'quantity'    => $qty,
			'stock_after' => $stock,
			'source'      => $source,
			'source_id'   => $source_id,
		], $meta ) );
	}
}
