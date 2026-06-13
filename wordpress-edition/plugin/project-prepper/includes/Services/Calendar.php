<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Kalender-Service: read-only Aggregation vorhandener Daten für die
 * Monatsansicht (Pendant zur App-Seite /calendar, OHNE CalDAV/Zwei-Wege-Sync).
 *
 * Führt drei Quellen zusammen, die einen Datumsbereich [from..to] überlappen:
 *   - Verleihe  (Rentals, Status reserved/active/returned; cancelled entfällt)
 *   - Projekte  (über Projects::all() → Gruppen-Zugriff respektiert; nur mit date_start)
 *   - Zeitplan  (Schedule::for_project() der zugänglichen Projekte, im Bereich)
 *
 * Datumsformate durchgehend Y-m-d. Rückgabe ist ein flaches, nach Datum
 * sortiertes Array; das Frontend gruppiert pro Tag. KEIN Schema-Change.
 */
class Calendar {

	/**
	 * Alle Termine im Bereich [from..to] (Y-m-d), zusammengeführt + sortiert.
	 *
	 * @param string $from Y-m-d (inklusive).
	 * @param string $to   Y-m-d (inklusive).
	 * @return array<int,array<string,mixed>>
	 */
	public static function events( string $from, string $to ): array {
		$events = array_merge(
			self::rental_events( $from, $to ),
			self::project_and_schedule_events( $from, $to )
		);

		usort( $events, static function ( $a, $b ) {
			$ka = $a['date_from'] ?? $a['date'] ?? '';
			$kb = $b['date_from'] ?? $b['date'] ?? '';
			$cmp = strcmp( (string) $ka, (string) $kb );
			if ( 0 !== $cmp ) {
				return $cmp;
			}
			// Innerhalb eines Tages: Verleih, Projekt, Zeitplan (stabil genug).
			return strcmp( (string) ( $a['type'] ?? '' ), (string) ( $b['type'] ?? '' ) );
		} );

		return $events;
	}

	/**
	 * Verleihe, deren Zeitraum den Bereich überlappt. cancelled entfällt.
	 * Site-weit (rohe Query, wie Dashboard::rentals); Verleihe haben in der
	 * WP-Edition keinen Gruppen-Owner.
	 */
	private static function rental_events( string $from, string $to ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, rental_number, borrower_name, date_from, date_to, status
			 FROM %i
			 WHERE status IN ('reserved', 'active', 'returned')
			   AND date_from <= %s AND date_to >= %s
			 ORDER BY date_from ASC, id ASC",
			Schema::table( 'rentals' ),
			$to,
			$from
		) ) ?: [];

		return array_map( static function ( $r ) {
			$title = trim( (string) $r->rental_number . ' · ' . (string) $r->borrower_name, ' ·' );
			return [
				'type'      => 'rental',
				'id'        => (int) $r->id,
				'title'     => $title,
				'date_from' => $r->date_from,
				'date_to'   => $r->date_to,
				'status'    => $r->status,
			];
		}, $rows );
	}

	/**
	 * Projekt- und Zeitplan-Events. Projekte einmal über Projects::all() laden
	 * (Gruppen-Zugriff!), Schedule nur für die zugänglichen Projekte abfragen
	 * und auf den Bereich filtern — kein N+1-Exzess.
	 */
	private static function project_and_schedule_events( string $from, string $to ): array {
		$events   = [];
		$projects = Projects::all();

		foreach ( $projects as $p ) {
			// Projekt-Event: nur mit gesetztem date_start und Überlappung.
			$start = $p->date_start ?? '';
			if ( '' !== (string) $start && null !== $start ) {
				$end = ! empty( $p->date_end ) ? $p->date_end : $start; // ohne Ende = eintägig
				if ( $start <= $to && $end >= $from ) {
					$events[] = [
						'type'      => 'project',
						'id'        => (int) $p->id,
						'title'     => $p->name,
						'date_from' => $start,
						'date_to'   => $end,
						'status'    => $p->status,
					];
				}
			}

			// Zeitplan-Einträge dieses Projekts im Bereich.
			foreach ( Schedule::for_project( (int) $p->id ) as $s ) {
				$date = $s->schedule_date ?? '';
				if ( '' === (string) $date || null === $date ) {
					continue;
				}
				if ( $date < $from || $date > $to ) {
					continue;
				}
				$events[] = [
					'type'       => 'schedule',
					'id'         => (int) $s->id,
					'project_id' => (int) $p->id,
					'title'      => $s->title,
					'date'       => $date,
					'time_start' => $s->time_start,
					'time_end'   => $s->time_end,
				];
			}
		}

		return $events;
	}
}
