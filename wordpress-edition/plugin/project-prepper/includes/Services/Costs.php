<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Projekt-Kostenposten — Pendant zu `cost_items` der App.
 *
 * Felder: Kategorie, Beschreibung, geplanter/Ist-Betrag (netto), MwSt-Satz,
 * exclude_from_profit. Beträge sind netto; MwSt wird pro Zeile als
 * amount * vat_rate/100 berechnet. amount_actual NULL = noch nicht erfasst.
 *
 * Sub-Budgets der App (Honorar/Technik/Transport) entfallen bewusst — die
 * WP-Edition ist Single-Site und nutzt ein Gesamtbudget auf dem Projekt
 * (`projects.budget_planned`). Die Summen-Mathe liefert summary().
 */
class Costs {

	/** Gültige Kategorien (gespiegelt aus der App: personnel/material/inventory/external/other). */
	const CATEGORIES = [ 'personnel', 'material', 'inventory', 'external', 'other' ];

	/**
	 * Sortiert nach manueller Reihenfolge, dann id.
	 */
	public static function for_project( int $project_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE project_id = %d ORDER BY sort_order ASC, id ASC',
			Schema::table( 'cost_items' ),
			$project_id
		) ) ?: [];
	}

	/**
	 * Kostenposten über mehrere Projekte aggregiert (Pendant zur globalen
	 * `/costs`-Seite der App). Jede Zeile bekommt den Projektnamen mitgeliefert,
	 * sortiert nach Erstellung (neueste zuerst, wie die App). Aufrufer ist für
	 * die Leak-Sicherheit verantwortlich: nur IDs von Projekten übergeben, die
	 * der aktuelle User erreichen darf (im Portal die Projekte seiner Gruppe).
	 *
	 * @param int[] $project_ids
	 * @return array<object>
	 */
	public static function for_projects( array $project_ids ): array {
		$project_ids = array_values( array_filter( array_map( 'intval', $project_ids ) ) );
		if ( ! $project_ids ) {
			return [];
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $project_ids ), '%d' ) );
		$costs        = Schema::table( 'cost_items' );
		$projects     = Schema::table( 'projects' );
		// $placeholders ist ausschließlich aus '%d' zusammengesetzt (array_fill),
		// Tabellennamen laufen über %i, IDs als Platzhalter → voll prepared. Der
		// interpolierte Platzhalter-String triggert dennoch mehrere PreparedSQL-
		// Sniffs, daher die ganze Kategorie hier unterdrücken.
		// phpcs:disable WordPress.DB.PreparedSQL
		$sql = $wpdb->prepare(
			"SELECT c.*, p.name AS project_name
			 FROM %i c
			 LEFT JOIN %i p ON p.id = c.project_id
			 WHERE c.project_id IN ($placeholders)
			 ORDER BY c.created_at DESC, c.id DESC",
			array_merge( [ $costs, $projects ], $project_ids )
		);
		return $wpdb->get_results( $sql ) ?: [];
		// phpcs:enable WordPress.DB.PreparedSQL
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'cost_items' ),
			$id
		) );
	}

	/**
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, array $data ) {
		global $wpdb;

		$category = isset( $data['category'] ) ? (string) $data['category'] : 'other';
		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			return new WP_Error( 'pp_invalid_category', __( 'Invalid cost category.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		// Beträge: netto, >= 0. amount_planned default 0, amount_actual NULL erlaubt.
		$planned = self::sanitize_amount( $data['amount_planned'] ?? 0, true );
		if ( false === $planned ) {
			return new WP_Error( 'pp_invalid_amount', __( 'Invalid amount.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$actual = self::sanitize_amount( $data['amount_actual'] ?? '', false );
		if ( false === $actual ) {
			return new WP_Error( 'pp_invalid_amount', __( 'Invalid amount.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$vat = self::sanitize_amount( $data['vat_rate'] ?? 0, true );
		if ( false === $vat ) {
			return new WP_Error( 'pp_invalid_amount', __( 'Invalid amount.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$wpdb->insert( Schema::table( 'cost_items' ), [
			'project_id'          => $project_id,
			'category'            => $category,
			'description'         => isset( $data['description'] ) ? (string) $data['description'] : '',
			'amount_planned'      => null === $planned ? 0 : $planned,
			'amount_actual'       => $actual,
			'vat_rate'            => null === $vat ? 0 : $vat,
			'exclude_from_profit' => ! empty( $data['exclude_from_profit'] ) ? 1 : 0,
			'sort_order'          => (int) ( $data['sort_order'] ?? 0 ),
			'created_at'          => current_time( 'mysql' ),
		] );
		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'cost_item_created', 'project', $project_id, [
			'category' => $category,
			'planned'  => null === $planned ? 0 : $planned,
		] );
		return $id;
	}

	/**
	 * Partielles Update — nur übergebene Keys werden geändert (wie Schedule::update).
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$entry = self::get( $id );
		if ( ! $entry ) {
			return new WP_Error( 'pp_not_found', __( 'Cost item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}

		$fields = [];
		if ( array_key_exists( 'category', $data ) ) {
			if ( ! in_array( (string) $data['category'], self::CATEGORIES, true ) ) {
				return new WP_Error( 'pp_invalid_category', __( 'Invalid cost category.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['category'] = (string) $data['category'];
		}
		if ( array_key_exists( 'description', $data ) ) {
			$fields['description'] = (string) $data['description'];
		}
		if ( array_key_exists( 'amount_planned', $data ) ) {
			$planned = self::sanitize_amount( $data['amount_planned'], true );
			if ( false === $planned ) {
				return new WP_Error( 'pp_invalid_amount', __( 'Invalid amount.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['amount_planned'] = null === $planned ? 0 : $planned;
		}
		if ( array_key_exists( 'amount_actual', $data ) ) {
			$actual = self::sanitize_amount( $data['amount_actual'], false );
			if ( false === $actual ) {
				return new WP_Error( 'pp_invalid_amount', __( 'Invalid amount.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['amount_actual'] = $actual;
		}
		if ( array_key_exists( 'vat_rate', $data ) ) {
			$vat = self::sanitize_amount( $data['vat_rate'], true );
			if ( false === $vat ) {
				return new WP_Error( 'pp_invalid_amount', __( 'Invalid amount.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['vat_rate'] = null === $vat ? 0 : $vat;
		}
		if ( array_key_exists( 'exclude_from_profit', $data ) ) {
			$fields['exclude_from_profit'] = ! empty( $data['exclude_from_profit'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'sort_order', $data ) ) {
			$fields['sort_order'] = (int) $data['sort_order'];
		}
		if ( $fields ) {
			$wpdb->update( Schema::table( 'cost_items' ), $fields, [ 'id' => $id ] );
			ActivityLog::log( 'cost_item_updated', 'project', (int) $entry->project_id, [
				'cost_id' => $id,
				'fields'  => array_keys( $fields ),
			] );
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;
		$entry = self::get( $id );
		if ( ! $entry ) {
			return new WP_Error( 'pp_not_found', __( 'Cost item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$wpdb->delete( Schema::table( 'cost_items' ), [ 'id' => $id ], [ '%d' ] );
		ActivityLog::log( 'cost_item_deleted', 'project', (int) $entry->project_id, [ 'cost_id' => $id ] );
		return true;
	}

	/**
	 * Serverseitige Summen + Budget-/Gewinn-Kennzahlen (testbar).
	 *
	 * MwSt pro Zeile = Betrag * vat_rate / 100 (netto-basiert, wie die App).
	 * - planned_net/_vat/_gross: Σ über alle Zeilen (geplant).
	 * - actual_net/_vat/_gross: Σ über alle Zeilen mit gesetztem Ist-Wert.
	 * - budget_planned/revenue_actual: aus dem Projekt (NULL wenn nicht gesetzt).
	 * - budget_variance: budget_planned − planned_net (NULL ohne Budget).
	 * - profit: revenue_actual − Σ Ist-Netto der NICHT ausgeschlossenen Zeilen
	 *   (NULL wenn kein Umsatz gesetzt).
	 *
	 * @return array<string,?float>
	 */
	public static function summary( int $project_id ): array {
		global $wpdb;

		$rows = self::for_project( $project_id );

		$planned_net   = 0.0;
		$planned_vat   = 0.0;
		$actual_net    = 0.0;
		$actual_vat    = 0.0;
		$profit_actual = 0.0; // Ist-Netto der nicht ausgeschlossenen Zeilen.
		foreach ( $rows as $row ) {
			$p_net = (float) $row->amount_planned;
			$rate  = (float) $row->vat_rate / 100;
			$planned_net += $p_net;
			$planned_vat += $p_net * $rate;

			if ( null !== $row->amount_actual ) {
				$a_net = (float) $row->amount_actual;
				$actual_net += $a_net;
				$actual_vat += $a_net * $rate;
				if ( empty( $row->exclude_from_profit ) ) {
					$profit_actual += $a_net;
				}
			}
		}

		$project = $wpdb->get_row( $wpdb->prepare(
			'SELECT budget_planned, revenue_actual FROM %i WHERE id = %d',
			Schema::table( 'projects' ),
			$project_id
		) );
		$budget  = ( $project && null !== $project->budget_planned ) ? (float) $project->budget_planned : null;
		$revenue = ( $project && null !== $project->revenue_actual ) ? (float) $project->revenue_actual : null;

		return [
			'planned_net'     => round( $planned_net, 2 ),
			'planned_vat'     => round( $planned_vat, 2 ),
			'planned_gross'   => round( $planned_net + $planned_vat, 2 ),
			'actual_net'      => round( $actual_net, 2 ),
			'actual_vat'      => round( $actual_vat, 2 ),
			'actual_gross'    => round( $actual_net + $actual_vat, 2 ),
			'budget_planned'  => null === $budget ? null : round( $budget, 2 ),
			'budget_variance' => null === $budget ? null : round( $budget - $planned_net, 2 ),
			'revenue_actual'  => null === $revenue ? null : round( $revenue, 2 ),
			'profit'          => null === $revenue ? null : round( $revenue - $profit_actual, 2 ),
		];
	}

	/**
	 * Betrag als nicht-negative Dezimalzahl validieren.
	 *
	 * Leer ('' / null) → NULL, wenn $required_zero false (z. B. amount_actual);
	 * bei $required_zero true → NULL (Aufrufer ersetzt durch 0).
	 * Negative oder nicht-numerische Werte → false (= ungültig).
	 *
	 * @param mixed $value
	 * @param bool  $required_zero  Pflichtfeld mit 0-Default (planned/vat) vs. nullable (actual).
	 * @return float|null|false
	 */
	private static function sanitize_amount( $value, bool $required_zero ) {
		if ( null === $value || '' === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return null; // Aufrufer entscheidet: 0 (planned/vat) oder NULL (actual).
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
