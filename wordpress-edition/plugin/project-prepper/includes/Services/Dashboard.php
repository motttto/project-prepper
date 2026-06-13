<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard-Service: serverseitig aggregierte KPI-Übersicht (read-only).
 *
 * Pendant zur Start-/Übersichtsseite der App (src/app/(dashboard)/dashboard).
 * Nutzt vorhandene Services (Inventory::stats, ActivityLog::recent) und schlanke
 * COUNT-Queries. Projekte laufen über Projects::all(), damit der Gruppen-Zugriff
 * (Phase 1) respektiert wird; Inventar/Verleih/Anfragen sind site-weit (rohe Counts).
 */
class Dashboard {

	// Vorschau-Fenster für "Anstehend" (in Tagen).
	const UPCOMING_DAYS = 14;

	public static function overview(): array {
		return [
			'inventory'       => Inventory::stats(),
			'rentals'         => self::rentals(),
			'projects'        => self::projects(),
			'inquiries'       => self::inquiries(),
			'recent_activity' => self::recent_activity(),
		];
	}

	/**
	 * Verleih: Zählung je Status + anstehende (reserved/active, date_from in den
	 * nächsten UPCOMING_DAYS Tagen). Site-weit, daher rohe COUNT-Query.
	 */
	private static function rentals(): array {
		global $wpdb;
		$table = Schema::table( 'rentals' );

		$counts = [ 'reserved' => 0, 'active' => 0, 'returned' => 0, 'cancelled' => 0 ];
		$rows   = $wpdb->get_results( $wpdb->prepare(
			'SELECT status, COUNT(*) AS n FROM %i GROUP BY status',
			$table
		) ) ?: [];
		foreach ( $rows as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->n;
			}
		}

		$today    = current_time( 'Y-m-d' );
		$horizon  = gmdate( 'Y-m-d', strtotime( $today . ' +' . self::UPCOMING_DAYS . ' days' ) );
		$upcoming = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, borrower_name, date_from, date_to, status
			 FROM %i
			 WHERE status IN ('reserved', 'active')
			   AND date_from >= %s AND date_from <= %s
			 ORDER BY date_from ASC, id ASC
			 LIMIT 20",
			$table,
			$today,
			$horizon
		) ) ?: [];

		return [
			'reserved'  => $counts['reserved'],
			'active'    => $counts['active'],
			'returned'  => $counts['returned'],
			'cancelled' => $counts['cancelled'],
			'upcoming'  => array_map( static function ( $r ) {
				return [
					'id'            => (int) $r->id,
					'borrower_name' => $r->borrower_name,
					'date_from'     => $r->date_from,
					'date_to'       => $r->date_to,
					'status'        => $r->status,
				];
			}, $upcoming ),
		];
	}

	/**
	 * Projekte: Zählung je Status + anstehende (date_start in den nächsten
	 * UPCOMING_DAYS Tagen, Status nicht cancelled/done). Über Projects::all(),
	 * damit der Gruppen-Zugriff respektiert wird (Nicht-Admins sehen keine
	 * fremden Gruppen-Projekte).
	 */
	private static function projects(): array {
		$counts = [ 'draft' => 0, 'planned' => 0, 'confirmed' => 0, 'running' => 0, 'done' => 0, 'cancelled' => 0 ];
		$all    = Projects::all();

		$today    = current_time( 'Y-m-d' );
		$horizon  = gmdate( 'Y-m-d', strtotime( $today . ' +' . self::UPCOMING_DAYS . ' days' ) );
		$upcoming = [];

		foreach ( $all as $p ) {
			if ( isset( $counts[ $p->status ] ) ) {
				$counts[ $p->status ]++;
			}
			if (
				! in_array( $p->status, [ 'cancelled', 'done' ], true )
				&& ! empty( $p->date_start )
				&& $p->date_start >= $today
				&& $p->date_start <= $horizon
			) {
				$upcoming[] = [
					'id'         => (int) $p->id,
					'name'       => $p->name,
					'status'     => $p->status,
					'date_start' => $p->date_start,
					'date_end'   => $p->date_end,
				];
			}
		}

		usort( $upcoming, static function ( $a, $b ) {
			return strcmp( $a['date_start'], $b['date_start'] );
		} );

		return [
			'draft'     => $counts['draft'],
			'planned'   => $counts['planned'],
			'confirmed' => $counts['confirmed'],
			'running'   => $counts['running'],
			'done'      => $counts['done'],
			'cancelled' => $counts['cancelled'],
			'upcoming'  => $upcoming,
		];
	}

	/**
	 * Anfragen: offene (status = 'new'). Site-weit, rohe COUNT-Query.
	 */
	private static function inquiries(): array {
		global $wpdb;
		$new = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE status = 'new'",
			Schema::table( 'inquiries' )
		) );
		return [ 'new' => $new ];
	}

	/**
	 * Letzte Aktivität: die jüngsten Log-Einträge mit aufgelöstem Akteur-Namen.
	 */
	private static function recent_activity(): array {
		$entries = ActivityLog::recent( 8 );
		return array_map( static function ( $entry ) {
			$actor = __( '—', 'project-prepper' );
			if ( $entry->actor_id ) {
				$user  = get_userdata( (int) $entry->actor_id );
				$actor = $user ? $user->display_name : __( '—', 'project-prepper' );
			} else {
				$actor = __( 'System', 'project-prepper' );
			}
			return [
				'action'      => $entry->action,
				'entity_type' => $entry->entity_type,
				'entity_id'   => $entry->entity_id !== null ? (int) $entry->entity_id : null,
				'created_at'  => $entry->created_at,
				'actor'       => $actor,
			];
		}, $entries );
	}
}
