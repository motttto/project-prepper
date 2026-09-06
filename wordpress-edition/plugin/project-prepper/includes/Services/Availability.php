<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use ProjectPrepper\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Verfügbarkeitsprüfung — Pendant zu check_inventory_availability() der App.
 *
 * EINE Quelle der Wahrheit für ALLE Wege, auf denen ein Artikel das Regal
 * verlässt (v0.41.0). Verfügbar = Artikel-Menge
 *   − Σ Positionen überlappender Verleihe mit Status reserved/active
 *   − Σ Buchungen aus Projekten mit Status confirmed/running, deren effektiver
 *     Zeitraum (Zeilen-Datum, sonst geerbt vom Projekt) überlappt
 *   − Σ genehmigter Kollektiv-Leihen (borrow_requests) im Zeitraum
 *   − Σ genehmigter föderierter Leihen (fed_borrow_in, je Anfrage 1 Einheit).
 *
 * Vorgelagert: Ein Artikel in einem GESPERRTEN Zustand ({@see Inventory::BLOCKED_CONDITIONS}
 * — defekt, in Wartung, verschollen, ausgemustert) ist immer 0 verfügbar.
 *
 * RÜSTZEITEN (Betreiber-Einstellung): Braucht ein Artikel Zeit vor einer Ausleihe
 * (vorbereiten, testen) oder danach (prüfen, reinigen, laden), zählen diese Tage
 * als belegt. Umgesetzt NICHT über SQL-Datumsfunktionen an den Spalten — das
 * würde jeden Index auf date_from/date_to aushebeln —, sondern durch Verschieben
 * des ANGEFRAGTEN Fensters. Ein Vorgang X bindet den Artikel effektiv von
 * (X.von − vorher) bis (X.bis + nachher).
 *
 * Daraus folgen ZWEI verschiedene Fenster — die Unterscheidung ist der Kern:
 *
 * 1. {@see booking_window} — „Passt eine NEUE Ausleihe hier hinein?" Beide
 *    Vorgänge bringen Rüstzeit mit, also muss zwischen ihnen (vorher + nachher)
 *    liegen; das Fenster weitet sich auf beiden Seiten um diese Summe:
 *      (B.von − vorher) ≤ (N.bis + nachher)  ∧  (B.bis + nachher) ≥ (N.von − vorher)
 *      ⟺  B.von ≤ N.bis + (vorher+nachher)  ∧  B.bis ≥ N.von − (vorher+nachher)
 *    Beispiel vorher=2, nachher=1: Rückgabe am 10., nächste Ausgabe frühestens
 *    am 14. — der 11. gehört der Nachbereitung, 12./13. der Vorbereitung.
 *
 * 2. {@see occupancy_window} — „Ist der Artikel an DIESEM Tag gebunden?" Der
 *    Stichtag ist kein Vorgang und bringt keine eigene Rüstzeit mit:
 *      B.von ≤ (Tag + vorher)  ∧  B.bis ≥ (Tag − nachher)
 *    Das ist die Frage, die `out_now` und der Zeitstatus beantworten — ein Gerät,
 *    das morgen rausgeht und heute vorbereitet wird, ist heute gebunden, aber es
 *    ist nicht „schon vier Tage vorher weg".
 *
 * Vorher rechneten zwei Schichten getrennt — {@see Borrowing::available_units}
 * kannte Verleihe/Projekte nicht, diese Klasse kannte Leihen nicht. Derselbe
 * Artikel konnte dadurch im selben Zeitraum extern verliehen UND ans Kollektiv
 * verliehen werden. `Borrowing::available_units()` delegiert jetzt hierher.
 *
 * Buchungen ohne bestimmbaren Zeitraum (Zeile UND Projekt ohne Termine)
 * blockieren nichts.
 */
class Availability {

	/**
	 * @param int    $item_id             Artikel.
	 * @param string $from                Y-m-d.
	 * @param string $to                  Y-m-d.
	 * @param int    $exclude_rental_id   Eigener Verleih beim Bearbeiten ausnehmen.
	 * @param int    $exclude_project_id  Eigenes Projekt beim Bearbeiten von Buchungszeilen ausnehmen.
	 * @param int    $exclude_borrow_id   Eigene Leih-Anfrage beim Entscheiden ausnehmen.
	 * @param int    $exclude_bundle_ref  Ganzen Set-Leih-Vorgang ausnehmen (alle Zeilen dieser bundle_ref).
	 */
	public static function available_quantity( int $item_id, string $from, string $to, int $exclude_rental_id = 0, int $exclude_project_id = 0, int $exclude_borrow_id = 0, int $exclude_bundle_ref = 0 ): int {
		global $wpdb;

		$item = Inventory::get_item( $item_id );
		if ( ! $item ) {
			return 0;
		}

		// Gesperrter Artikel (defekt, in Wartung, verschollen, ausgemustert) geht
		// gar nicht raus — unabhängig davon, was rechnerisch frei wäre. Der Guard
		// sitzt hier, weil ALLE vier Wege durch diese Methode laufen: externer
		// Verleih, Projekt-Buchung, Kollektiv-Leihe und Netzwerk-Anfrage.
		if ( Inventory::is_blocked( $item->item_condition ?? '' ) ) {
			return 0;
		}

		// Rüstzeiten einrechnen: ab hier arbeiten alle vier Zweige mit dem
		// geweiteten Fenster — sonst wäre ein Artikel je nach Weg unterschiedlich
		// verfügbar (siehe Klassendoku „EINE Quelle der Wahrheit"). Hier gilt das
		// BUCHUNGSfenster: die geprüfte Anfrage ist selbst ein Vorgang mit Rüstzeit.
		list( $from, $to ) = self::booking_window( $from, $to );

		$rented = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(ri.quantity), 0)
			 FROM %i ri
			 INNER JOIN %i r ON r.id = ri.rental_id
			 WHERE ri.item_id = %d
			   AND r.id != %d
			   AND r.status IN ('reserved', 'active')
			   AND r.date_from <= %s
			   AND r.date_to >= %s",
			Schema::table( 'rental_items' ),
			Schema::table( 'rentals' ),
			$item_id,
			$exclude_rental_id,
			$to,
			$from
		) );

		// Projekt-Buchungen: nur confirmed/running blockieren; effektiver Zeitraum
		// = Zeilen-Datum mit Fallback auf den Projekt-Zeitraum (COALESCE).
		$booked = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(pi.quantity), 0)
			 FROM %i pi
			 INNER JOIN %i p ON p.id = pi.project_id
			 WHERE pi.item_id = %d
			   AND p.id != %d
			   AND p.status IN ('confirmed', 'running')
			   AND COALESCE(pi.date_from, p.date_start) IS NOT NULL
			   AND COALESCE(pi.date_to, p.date_end) IS NOT NULL
			   AND COALESCE(pi.date_from, p.date_start) <= %s
			   AND COALESCE(pi.date_to, p.date_end) >= %s",
			Schema::table( 'project_items' ),
			Schema::table( 'projects' ),
			$item_id,
			$exclude_project_id,
			$to,
			$from
		) );

		// Kollektiv-Leihen: nur genehmigte halten eine Einheit. `quantity` ist seit
		// v0.40.0 die angefragte Stückzahl (Einzel-Artikel: 1), Set-Teile tragen
		// Bedarf × Anzahl Sets. $exclude_bundle_ref klammert einen ganzen
		// Set-Vorgang aus (alle Zeilen mit dieser bundle_ref).
		$borrowed = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(quantity), 0) FROM %i
			 WHERE item_id = %d
			   AND status = 'approved'
			   AND id <> %d
			   AND ( bundle_ref IS NULL OR bundle_ref <> %d )
			   AND date_from <= %s
			   AND date_to >= %s",
			Schema::table( 'borrow_requests' ),
			$item_id,
			$exclude_borrow_id,
			$exclude_bundle_ref,
			$to,
			$from
		) );

		// Föderierte Leihen (Slice 5): eine genehmigte Anfrage einer Partner-Instanz
		// hält eine Einheit, bis sie zurückgegeben ist.
		$federated = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i
			 WHERE item_id = %d
			   AND status = 'approved'
			   AND date_from <= %s
			   AND date_to >= %s",
			Schema::table( 'fed_borrow_in' ),
			$item_id,
			$to,
			$from
		) );

		return max( 0, (int) $item->quantity - $rented - $booked - $borrowed - $federated );
	}

	/**
	 * Zeitfenster je Artikel: Wann ist er (heute) gebunden, wann wird er wieder
	 * frei, und wann geht er das nächste Mal raus?
	 *
	 * Beantwortet die Frage, die `out_now` NICHT beantworten kann: Dieser Zähler
	 * rechnet auf HEUTE, ein Artikel mit einer Buchung in zehn Tagen sieht darin
	 * unbeschäftigt aus. Genau daran ist in der Praxis eine Ausleihe vorbeigelaufen
	 * — der Eigentümer sah erst am Ausleihtag, dass sein Gerät weg ist.
	 *
	 * EINE Query für alle übergebenen Artikel (kein N+1), über dieselben vier
	 * Wege wie {@see available_quantity} — wird ein fünfter Weg ergänzt, gehört
	 * er auch hierher.
	 *
	 * Die Rüstzeiten sind eingerechnet: `busy_until` erfasst auch eine Buchung,
	 * die erst morgen beginnt, wenn heute schon die Vorbereitung läuft.
	 *
	 * GRENZE — bewusst mengenblind: `busy_until` ist das späteste Ende ALLER
	 * heute laufenden Bindungen, `next_from` der früheste künftige Beginn; beide
	 * halten keine Stückzahlen gegen den Bestand. Bei Artikeln mit mehreren Stück
	 * sind das deshalb konservative Eckdaten, keine exakten Aussagen über den
	 * Gesamtbestand — {@see MemberPortal::when_chip} formuliert entsprechend
	 * vorsichtig, sobald mehr als ein Stück im Spiel ist. Eine mengengenaue
	 * Antwort bräuchte eine Belegungskurve über den Zeitstrahl statt zweier
	 * Aggregate; das ist die Anzeige nicht wert.
	 *
	 * @param int[] $item_ids
	 * @return array<int,array{busy_until:?string,free_from:?string,next_from:?string,free_until:?string}>
	 */
	public static function timeline( array $item_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $item_ids ) ) ) );
		if ( ! $ids ) {
			return [];
		}
		$today = current_time( 'Y-m-d' );
		list( $from_pad, $to_pad ) = self::occupancy_window( $today, $today );

		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// Reihenfolge exakt wie die Platzhalter im SQL: erst die drei Datumswerte
		// des SELECT, dann die sechs Tabellen der UNION, zuletzt die Artikel-IDs.
		$params = array_merge(
			[ $to_pad, $from_pad, $to_pad ],
			[
				Schema::table( 'rental_items' ),
				Schema::table( 'rentals' ),
				Schema::table( 'project_items' ),
				Schema::table( 'projects' ),
				Schema::table( 'borrow_requests' ),
				Schema::table( 'fed_borrow_in' ),
			],
			$ids
		);

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT u.item_id,
					MAX(CASE WHEN u.date_from <= %s AND u.date_to >= %s THEN u.date_to END) AS busy_until,
					MIN(CASE WHEN u.date_from > %s THEN u.date_from END) AS next_from
			 FROM (
				SELECT ri.item_id, r.date_from, r.date_to
				FROM %i ri
				INNER JOIN %i r ON r.id = ri.rental_id
				WHERE r.status IN ('reserved', 'active')
				UNION ALL
				SELECT pi.item_id,
					   COALESCE(pi.date_from, p.date_start) AS date_from,
					   COALESCE(pi.date_to, p.date_end) AS date_to
				FROM %i pi
				INNER JOIN %i p ON p.id = pi.project_id
				WHERE p.status IN ('confirmed', 'running')
				  AND COALESCE(pi.date_from, p.date_start) IS NOT NULL
				  AND COALESCE(pi.date_to, p.date_end) IS NOT NULL
				UNION ALL
				SELECT br.item_id, br.date_from, br.date_to
				FROM %i br
				WHERE br.status = 'approved'
				UNION ALL
				SELECT fb.item_id, fb.date_from, fb.date_to
				FROM %i fb
				WHERE fb.status = 'approved'
			 ) u
			 WHERE u.item_id IN ( {$in} )
			 GROUP BY u.item_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$in} ist eine erzeugte %d-Platzhalterliste, die Werte stehen in $params.
			$params
		) ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- via prepare() oben.

		$before = Settings::buffer_before();
		$after  = Settings::buffer_after();
		$out    = [];
		foreach ( $rows as $row ) {
			$busy = $row->busy_until ? (string) $row->busy_until : null;
			$next = $row->next_from ? (string) $row->next_from : null;
			$pad  = $before + $after;
			$free = $busy ? self::shift_date( $busy, $pad + 1 ) : null;
			// Schließt direkt die nächste Bindung an, ist dieser Tag gar nicht frei.
			// Dann lieber KEIN Datum nennen als ein falsches — die Anzeige fällt auf
			// „unterwegs bis …" zurück, was in jedem Fall stimmt.
			if ( $free && $next && self::shift_date( $next, -$pad ) <= $free ) {
				$free = null;
			}
			$out[ (int) $row->item_id ] = [
				'busy_until' => $busy,
				// Beide Enden beantworten dieselbe BUCHUNGSfrage und müssen deshalb
				// denselben Abstand benutzen wie booking_window() — mit nur `nachher`
				// nennte der Chip einen Tag, den das Buchungsformular ablehnt.
				'free_from'  => $free,
				'next_from'  => $next,
				// „Frei bis" beantwortet eine BUCHUNGSfrage („bis wann kann ich es
				// noch haben?"), deshalb der beidseitige Buchungsabstand — sonst
				// nennt der Chip einen Tag, den das Buchungsformular ablehnt.
				'free_until' => $next ? self::shift_date( $next, -( $before + $after + 1 ) ) : null,
			];
		}
		return $out;
	}

	/**
	 * Fenster für die Prüfung einer NEUEN Ausleihe (Fall 1 der Klassendoku):
	 * beidseitig um (vorher + nachher) geweitet, weil beide beteiligten Vorgänge
	 * Rüstzeit brauchen.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function booking_window( string $from, string $to ): array {
		$pad = Settings::buffer_before() + Settings::buffer_after();
		if ( ! $pad ) {
			return [ $from, $to ];
		}
		return [ self::shift_date( $from, -$pad ), self::shift_date( $to, $pad ) ];
	}

	/**
	 * Fenster für die Frage „ist der Artikel an diesen Tagen gebunden?" (Fall 2
	 * der Klassendoku): der Stichtag ist kein Vorgang und bringt keine eigene
	 * Rüstzeit mit, deshalb asymmetrisch.
	 *
	 * Ohne eingestellte Rüstzeiten liefern beide Fenster die Eingabe unverändert —
	 * eine Instanz, die die Einstellung nie anfasst, rechnet exakt wie zuvor.
	 *
	 * @return array{0:string,1:string} [von − nachher, bis + vorher]
	 */
	public static function occupancy_window( string $from, string $to ): array {
		$before = Settings::buffer_before();
		$after  = Settings::buffer_after();
		if ( ! $before && ! $after ) {
			return [ $from, $to ];
		}
		return [ self::shift_date( $from, -$after ), self::shift_date( $to, $before ) ];
	}

	/** Datum um $days Tage verschieben; ungültige Eingaben bleiben unverändert. */
	public static function shift_date( string $date, int $days ): string {
		if ( ! $days || ! self::is_valid_date( $date ) ) {
			return $date;
		}
		// „!" nullt die Uhrzeit — sonst rechnet DateTime mit der aktuellen Zeit und
		// ein Tagessprung über die Sommerzeitumstellung kann daneben liegen.
		$d = \DateTime::createFromFormat( '!Y-m-d', $date );
		if ( ! $d ) {
			return $date;
		}
		$d->modify( ( $days > 0 ? '+' : '-' ) . abs( $days ) . ' days' );
		return $d->format( 'Y-m-d' );
	}

	public static function is_valid_range( string $from, string $to ): bool {
		$d_from = \DateTime::createFromFormat( 'Y-m-d', $from );
		$d_to   = \DateTime::createFromFormat( 'Y-m-d', $to );
		return $d_from && $d_to
			&& $d_from->format( 'Y-m-d' ) === $from
			&& $d_to->format( 'Y-m-d' ) === $to
			&& $from <= $to;
	}

	public static function is_valid_date( string $date ): bool {
		$d = \DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
