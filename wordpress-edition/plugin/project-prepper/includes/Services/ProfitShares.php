<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Gewinnverteilung (v0.13.0, Gruppen-Phase 4) — Pendant zu `project_profit_shares`
 * der App. Je Zeile ein WP-User aus der besitzenden Gruppe mit einem Anteil am
 * Gewinn-Pool des Projekts.
 *
 * Gewinn-Pool = Costs::summary( $project_id )['profit']
 *   ( = revenue_actual − Σ Ist-Netto der nicht-ausgeschlossenen Kostenzeilen;
 *     NULL wenn kein Umsatz auf dem Projekt gesetzt ist ).
 *
 * ShareType (bewusst nur zwei von dreien der App):
 * - 'percentage' → share_value ist ein Prozentsatz auf den Pool.
 *      calculated_amount = round( pool * share_value/100, 2 ), wenn pool bekannt;
 *      NULL, wenn pool NULL (Prozentsatz steht, Betrag noch unbestimmt).
 * - 'fixed'      → share_value ist ein fester Eurobetrag.
 *      calculated_amount = share_value (immer, pool-unabhängig).
 * Der App-Typ 'hourly' entfällt — es gibt keine Stundenerfassung in der WP-Edition.
 *
 * Flaches Pool-Modell: Prozente beziehen sich auf den VOLLEN Gewinn-Pool, NICHT
 * auf den nach fixed-Anteilen verbleibenden Rest (so macht es die App ebenfalls
 * flach über calculated_amount). Über-Verteilung ist ein Nutzerfehler, der im UI
 * als over_allocated sichtbar gemacht wird — share_value wird NICHT hart begrenzt.
 *
 * Validierung wie ProjectMembers: das Projekt muss eine Eigentümer-Gruppe haben
 * (pp_no_group), der user_id muss aktives Gruppenmitglied sein
 * (pp_not_group_member). Siehe docs/03-GRUPPEN-ARCHITEKTUR.md §Phase 4.
 */
class ProfitShares {

	const SHARE_TYPES = [ 'percentage', 'fixed' ];

	/* ===================== Lesen ===================== */

	/**
	 * Anteilszeilen eines Projekts, je Zeile mit aufgelöstem WP-User
	 * (display_name + user_email, ->missing bei verwaistem User) UND dem
	 * serverseitig berechneten calculated_amount (float oder null).
	 *
	 * @return array<object>
	 */
	public static function for_project( int $project_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE project_id = %d ORDER BY sort_order ASC, id ASC',
			Schema::table( 'project_profit_shares' ),
			$project_id
		) ) ?: [];

		$pool = self::pool( $project_id );
		foreach ( $rows as $row ) {
			$user                   = get_userdata( (int) $row->user_id );
			$row->user_id           = (int) $row->user_id;
			$row->display_name      = $user ? $user->display_name : sprintf( '#%d', (int) $row->user_id );
			$row->user_email        = $user ? $user->user_email : '';
			$row->missing           = $user ? false : true;
			$row->share_value       = (float) $row->share_value;
			$row->calculated_amount = self::calc_amount( (string) $row->share_type, (float) $row->share_value, $pool );
		}
		return $rows;
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_profit_shares' ),
			$id
		) );
	}

	/**
	 * Serverseitige Verteil-Kennzahlen (testbar, Kernstück).
	 *
	 * - pool: Gewinn-Pool aus Costs::summary.profit (kann NULL sein).
	 * - total_percent: Σ share_value der percentage-Zeilen.
	 * - total_fixed: Σ share_value der fixed-Zeilen.
	 * - total_allocated: Σ calculated_amount über alle Zeilen, wenn pool bekannt;
	 *   wenn pool NULL → nur total_fixed (Prozent-Zeilen tragen keinen Betrag bei).
	 * - unallocated: pool − total_allocated (NULL wenn pool NULL).
	 * - over_allocated: total_allocated > pool (false wenn pool NULL).
	 *
	 * @return array<string,?float|bool>
	 */
	public static function summary( int $project_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT share_type, share_value FROM %i WHERE project_id = %d',
			Schema::table( 'project_profit_shares' ),
			$project_id
		) ) ?: [];

		$pool = self::pool( $project_id );

		$total_percent = 0.0;
		$total_fixed   = 0.0;
		foreach ( $rows as $row ) {
			$value = (float) $row->share_value;
			if ( 'fixed' === $row->share_type ) {
				$total_fixed += $value;
			} else {
				$total_percent += $value;
			}
		}

		// Zugeteilt: fixed-Summe + (wenn Pool bekannt) der Prozent-Anteil am Pool.
		$total_allocated = $total_fixed;
		if ( null !== $pool ) {
			$total_allocated += $pool * $total_percent / 100;
		}
		$total_allocated = round( $total_allocated, 2 );

		return [
			'pool'            => null === $pool ? null : round( $pool, 2 ),
			'total_percent'   => round( $total_percent, 2 ),
			'total_fixed'     => round( $total_fixed, 2 ),
			'total_allocated' => $total_allocated,
			'unallocated'     => null === $pool ? null : round( $pool - $total_allocated, 2 ),
			'over_allocated'  => null === $pool ? false : ( $total_allocated > $pool ),
		];
	}

	/* ===================== Schreiben ===================== */

	/**
	 * Anteil hinzufügen.
	 *
	 * Validierung:
	 * - Projekt muss eine Eigentümer-Gruppe haben (400 pp_no_group).
	 * - $user_id MUSS aktives Mitglied dieser Gruppe sein (400 pp_not_group_member).
	 * - share_type ∈ {percentage,fixed} (sonst 400 pp_invalid_share_type).
	 * - share_value: nicht-negative Dezimalzahl (sonst 400 pp_invalid_amount).
	 * - Doppel-Eintrag pro user → idempotent (übergebene Felder werden
	 *   angeglichen, kein Fehler), Rückgabe der bestehenden Zeile.
	 *
	 * @return int|WP_Error  ID der (neuen oder bestehenden) Anteilszeile.
	 */
	public static function add( int $project_id, int $user_id, array $data = [] ) {
		global $wpdb;

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'pp_invalid_user', __( 'Unknown user.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$group_id = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT owner_group_id FROM %i WHERE id = %d',
			Schema::table( 'projects' ),
			$project_id
		) );
		if ( ! $group_id ) {
			return new WP_Error(
				'pp_no_group',
				__( 'This project has no owning group. Assign a group first to distribute profit.', 'project-prepper' ),
				[ 'status' => 400 ]
			);
		}
		if ( ! Groups::is_member( $group_id, $user_id ) ) {
			return new WP_Error(
				'pp_not_group_member',
				__( 'This user is not a member of the project group.', 'project-prepper' ),
				[ 'status' => 400 ]
			);
		}

		$type = isset( $data['share_type'] ) ? (string) $data['share_type'] : 'percentage';
		if ( ! in_array( $type, self::SHARE_TYPES, true ) ) {
			return new WP_Error( 'pp_invalid_share_type', __( 'Invalid share type.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$value = self::sanitize_amount( $data['share_value'] ?? 0 );
		if ( false === $value ) {
			return new WP_Error( 'pp_invalid_amount', __( 'Invalid amount.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$note = isset( $data['note'] ) ? (string) $data['note'] : '';

		$existing = $wpdb->get_row( $wpdb->prepare(
			'SELECT id FROM %i WHERE project_id = %d AND user_id = %d',
			Schema::table( 'project_profit_shares' ),
			$project_id,
			$user_id
		) );
		if ( $existing ) {
			// Idempotent: nur übergebene Felder angleichen, kein Fehler.
			$fields = [
				'share_type'  => $type,
				'share_value' => $value,
			];
			if ( array_key_exists( 'note', $data ) ) {
				$fields['note'] = $note;
			}
			if ( array_key_exists( 'sort_order', $data ) ) {
				$fields['sort_order'] = (int) $data['sort_order'];
			}
			$wpdb->update( Schema::table( 'project_profit_shares' ), $fields, [ 'id' => (int) $existing->id ] );
			return (int) $existing->id;
		}

		$wpdb->insert( Schema::table( 'project_profit_shares' ), [
			'project_id'  => $project_id,
			'user_id'     => $user_id,
			'share_type'  => $type,
			'share_value' => $value,
			'note'        => $note,
			'sort_order'  => (int) ( $data['sort_order'] ?? 0 ),
			'created_at'  => current_time( 'mysql' ),
		] );
		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'profit_share_added', 'project', $project_id, [
			'user_id'    => $user_id,
			'share_type' => $type,
			'value'      => $value,
		] );
		return $id;
	}

	/**
	 * Partielles Update — share_type/share_value/note/sort_order. user_id/
	 * project_id sind unveränderlich (eine Zeile = ein Beteiligter pro Projekt).
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$row = self::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'pp_not_found', __( 'Profit share not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}

		$fields = [];
		if ( array_key_exists( 'share_type', $data ) ) {
			if ( ! in_array( (string) $data['share_type'], self::SHARE_TYPES, true ) ) {
				return new WP_Error( 'pp_invalid_share_type', __( 'Invalid share type.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['share_type'] = (string) $data['share_type'];
		}
		if ( array_key_exists( 'share_value', $data ) ) {
			$value = self::sanitize_amount( $data['share_value'] );
			if ( false === $value ) {
				return new WP_Error( 'pp_invalid_amount', __( 'Invalid amount.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['share_value'] = $value;
		}
		if ( array_key_exists( 'note', $data ) ) {
			$fields['note'] = (string) $data['note'];
		}
		if ( array_key_exists( 'sort_order', $data ) ) {
			$fields['sort_order'] = (int) $data['sort_order'];
		}
		if ( $fields ) {
			$wpdb->update( Schema::table( 'project_profit_shares' ), $fields, [ 'id' => $id ] );
			ActivityLog::log( 'profit_share_updated', 'project', (int) $row->project_id, [
				'share_id' => $id,
				'fields'   => array_keys( $fields ),
			] );
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function remove( int $id ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'pp_not_found', __( 'Profit share not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$wpdb->delete( Schema::table( 'project_profit_shares' ), [ 'id' => $id ], [ '%d' ] );
		ActivityLog::log( 'profit_share_removed', 'project', (int) $row->project_id, [
			'user_id' => (int) $row->user_id,
		] );
		return true;
	}

	/* ===================== Intern ===================== */

	/**
	 * Gewinn-Pool des Projekts (= Costs::summary.profit). NULL wenn kein Umsatz.
	 */
	private static function pool( int $project_id ): ?float {
		$profit = Costs::summary( $project_id )['profit'];
		return null === $profit ? null : (float) $profit;
	}

	/**
	 * Berechneter Betrag einer Zeile.
	 * - fixed → share_value (immer).
	 * - percentage → pool*value/100, wenn pool bekannt; sonst null.
	 */
	private static function calc_amount( string $type, float $value, ?float $pool ): ?float {
		if ( 'fixed' === $type ) {
			return round( $value, 2 );
		}
		// percentage
		if ( null === $pool ) {
			return null;
		}
		return round( $pool * $value / 100, 2 );
	}

	/**
	 * share_value validieren: leer ('' / null) → 0.0; sonst nicht-negative
	 * Dezimalzahl. Nicht-numerisch / negativ → false (= ungültig). Prozentsätze
	 * über 100 sind erlaubt (Über-Verteilung wird im Summen-Block sichtbar).
	 *
	 * @param mixed $value
	 * @return float|false
	 */
	private static function sanitize_amount( $value ) {
		if ( null === $value || '' === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return 0.0;
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
