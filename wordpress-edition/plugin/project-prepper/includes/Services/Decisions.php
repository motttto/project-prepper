<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Projekt-Beschlüsse (v0.12.0, Gruppen-Phase 3) — Pendant zu `org_decisions` /
 * `org_decision_votes` der App (projektbezogene Variante).
 *
 * Abstimmungen unter den aktiven Mitgliedern der besitzenden Gruppe eines
 * Projekts (pp_projects.owner_group_id). Stimmen: approve/reject/abstain. Nach
 * jeder Stimmabgabe/-änderung wird der Beschluss neu ausgewertet (resolve()):
 *
 *  - requires_unanimous = true (Einstimmig):
 *      approvals == total_active            → approved
 *      sonst rejections > 0                 → rejected
 *      sonst                                → bleibt open
 *  - requires_unanimous = false (Mehrheit):
 *      löst NUR auf, wenn total_votes == total_active (alle stimmten ab):
 *        approvals > rejections             → approved
 *        sonst                              → rejected
 *
 * abstain zählt als abgegebene Stimme (für „alle haben abgestimmt"), aber nicht
 * als approve/reject. „total_active" = Anzahl aktiver Gruppenmitglieder
 * (Groups::members). Diese Logik ist 1:1 aus check_decision_complete() der App
 * portiert (Migration 045) — einzige Abweichung: Stimmberechtigte sind die
 * Gruppenmitglieder, nicht project_members ∪ org_admins (WP-Modell aus Phase 1).
 *
 * Manuelles Schließen: Ersteller ODER Admin (Groups::user_is_admin) darf einen
 * offenen Beschluss vorzeitig auf 'cancelled' setzen (für festgefahrene
 * Einstimmig-Fälle, die sonst dauerhaft offen blieben). Kein Auto-Reopen.
 *
 * Validierung (Kernstück): cast_vote erfordert ein aktives Mitglied der
 * Eigentümer-Gruppe (Groups::is_member), sonst 403 pp_not_group_member.
 *
 * Siehe docs/03-GRUPPEN-ARCHITEKTUR.md §Phase 3.
 */
class Decisions {

	const VOTES    = [ 'approve', 'reject', 'abstain' ];
	const STATUSES = [ 'open', 'approved', 'rejected', 'cancelled' ];

	/* ===================== Lesen ===================== */

	/**
	 * Beschlüsse eines Projekts, je Zeile mit Tally (approve/reject/abstain
	 * Counts), total_active (Anzahl aktiver Gruppenmitglieder), can_vote (ob der
	 * aktuelle User abstimmen darf = aktives Gruppenmitglied) und my_vote (die
	 * eigene Stimme des aktuellen Users oder null).
	 *
	 * Sortierung: offene Beschlüsse zuerst, dann nach created_at desc.
	 *
	 * @param int      $project_id
	 * @param int|null $current_user_id Für my_vote/can_vote; 0/null = keiner.
	 * @return array<object>
	 */
	public static function for_project( int $project_id, ?int $current_user_id = null ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM %i WHERE project_id = %d
			 ORDER BY (status = 'open') DESC, created_at DESC, id DESC",
			Schema::table( 'project_decisions' ),
			$project_id
		) ) ?: [];

		if ( ! $rows ) {
			return [];
		}

		$group_id     = self::project_group_id( $project_id );
		$total_active = $group_id ? count( Groups::members( $group_id ) ) : 0;
		$can_vote     = ( $group_id && $current_user_id && Groups::is_member( $group_id, $current_user_id ) );

		foreach ( $rows as $row ) {
			$tally                     = self::tally( (int) $row->id );
			$row->approve_count        = $tally['approve'];
			$row->reject_count         = $tally['reject'];
			$row->abstain_count        = $tally['abstain'];
			$row->total_votes          = $tally['total'];
			$row->total_active         = $total_active;
			$row->requires_unanimous   = (int) $row->requires_unanimous ? true : false;
			$row->can_vote             = $can_vote;
			$row->my_vote              = null;
			if ( $current_user_id ) {
				$row->my_vote = $wpdb->get_var( $wpdb->prepare(
					'SELECT vote FROM %i WHERE decision_id = %d AND user_id = %d',
					Schema::table( 'project_decision_votes' ),
					(int) $row->id,
					$current_user_id
				) );
			}
			$row->voters = self::voters( (int) $row->id );
		}
		return $rows;
	}

	/**
	 * Ein Beschluss inkl. ->votes (user_id, display_name, vote, comment,
	 * voted_at; verwaiste WP-User → ->missing=true). Plus Tally + total_active.
	 */
	public static function get( int $id ): ?object {
		global $wpdb;
		$decision = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_decisions' ),
			$id
		) );
		if ( ! $decision ) {
			return null;
		}

		$decision->requires_unanimous = (int) $decision->requires_unanimous ? true : false;

		$votes = $wpdb->get_results( $wpdb->prepare(
			'SELECT user_id, vote, comment, voted_at FROM %i WHERE decision_id = %d ORDER BY voted_at ASC, id ASC',
			Schema::table( 'project_decision_votes' ),
			$id
		) ) ?: [];
		foreach ( $votes as $v ) {
			$user              = get_userdata( (int) $v->user_id );
			$v->user_id        = (int) $v->user_id;
			$v->display_name   = $user ? $user->display_name : sprintf( '#%d', (int) $v->user_id );
			$v->missing        = $user ? false : true;
		}
		$decision->votes = $votes;

		$tally                   = self::tally( $id );
		$decision->approve_count = $tally['approve'];
		$decision->reject_count  = $tally['reject'];
		$decision->abstain_count = $tally['abstain'];
		$decision->total_votes   = $tally['total'];
		$group_id                = self::project_group_id( (int) $decision->project_id );
		$decision->total_active  = $group_id ? count( Groups::members( $group_id ) ) : 0;

		return $decision;
	}

	/* ===================== Schreiben ===================== */

	/**
	 * Beschluss anlegen.
	 *
	 * Validierung:
	 * - Projekt MUSS eine Eigentümer-Gruppe haben (sonst 400 pp_no_group — ohne
	 *   Gruppe gibt es keine Stimmberechtigten).
	 * - title Pflicht (sonst 400 pp_missing_title).
	 *
	 * @return int|WP_Error  Neue Beschluss-ID.
	 */
	public static function create( int $project_id, array $data ) {
		global $wpdb;

		$group_id = self::project_group_id( $project_id );
		if ( ! $group_id ) {
			return new WP_Error(
				'pp_no_group',
				__( 'This project has no owning group. Assign a group first to hold votes.', 'project-prepper' ),
				[ 'status' => 400 ]
			);
		}

		$title = isset( $data['title'] ) ? trim( (string) $data['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'pp_missing_title', __( 'A title is required.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$unanimous = array_key_exists( 'requires_unanimous', $data ) ? (bool) $data['requires_unanimous'] : true;

		$wpdb->insert( Schema::table( 'project_decisions' ), [
			'project_id'         => $project_id,
			'title'              => $title,
			'description'        => isset( $data['description'] ) ? (string) $data['description'] : '',
			'requires_unanimous' => $unanimous ? 1 : 0,
			'status'             => 'open',
			'created_by'         => get_current_user_id() ?: null,
			'created_at'         => current_time( 'mysql' ),
			'resolved_at'        => null,
		] );
		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'decision_created', 'project', $project_id, [
			'decision_id'        => $id,
			'title'              => $title,
			'requires_unanimous' => $unanimous,
		] );
		return $id;
	}

	/**
	 * Stimme abgeben oder ändern (Upsert über UNIQUE decision_user). Danach wird
	 * der Beschluss neu ausgewertet (resolve()).
	 *
	 * Validierung:
	 * - Beschluss existiert + ist 'open' (sonst 409 pp_decision_closed).
	 * - vote ∈ {approve,reject,abstain} (sonst 400 pp_invalid_vote).
	 * - $user_id MUSS aktives Mitglied der Eigentümer-Gruppe sein (sonst 403
	 *   pp_not_group_member).
	 *
	 * @return true|WP_Error
	 */
	public static function cast_vote( int $decision_id, int $user_id, string $vote, string $comment = '' ) {
		global $wpdb;

		$decision = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_decisions' ),
			$decision_id
		) );
		if ( ! $decision ) {
			return new WP_Error( 'pp_not_found', __( 'Decision not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( 'open' !== $decision->status ) {
			return new WP_Error( 'pp_decision_closed', __( 'This decision is already closed.', 'project-prepper' ), [ 'status' => 409 ] );
		}
		if ( ! in_array( $vote, self::VOTES, true ) ) {
			return new WP_Error( 'pp_invalid_vote', __( 'Invalid vote.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$group_id = self::project_group_id( (int) $decision->project_id );
		if ( ! $group_id || ! Groups::is_member( $group_id, $user_id ) ) {
			return new WP_Error(
				'pp_not_group_member',
				__( 'Only members of the project group may vote.', 'project-prepper' ),
				[ 'status' => 403 ]
			);
		}

		$now      = current_time( 'mysql' );
		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM %i WHERE decision_id = %d AND user_id = %d',
			Schema::table( 'project_decision_votes' ),
			$decision_id,
			$user_id
		) );
		if ( $existing ) {
			$wpdb->update(
				Schema::table( 'project_decision_votes' ),
				[ 'vote' => $vote, 'comment' => $comment, 'voted_at' => $now ],
				[ 'id' => (int) $existing ],
				[ '%s', '%s', '%s' ],
				[ '%d' ]
			);
		} else {
			$wpdb->insert( Schema::table( 'project_decision_votes' ), [
				'decision_id' => $decision_id,
				'user_id'     => $user_id,
				'vote'        => $vote,
				'comment'     => $comment,
				'voted_at'    => $now,
			] );
		}

		ActivityLog::log( 'decision_vote_cast', 'project', (int) $decision->project_id, [
			'decision_id' => $decision_id,
			'user_id'     => $user_id,
			'vote'        => $vote,
		] );

		self::resolve( $decision_id );
		return true;
	}

	/**
	 * Titel/Beschreibung ändern — nur solange der Beschluss 'open' ist.
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$decision = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_decisions' ),
			$id
		) );
		if ( ! $decision ) {
			return new WP_Error( 'pp_not_found', __( 'Decision not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( 'open' !== $decision->status ) {
			return new WP_Error( 'pp_decision_closed', __( 'This decision is already closed.', 'project-prepper' ), [ 'status' => 409 ] );
		}

		$fields = [];
		if ( array_key_exists( 'title', $data ) ) {
			$title = trim( (string) $data['title'] );
			if ( '' === $title ) {
				return new WP_Error( 'pp_missing_title', __( 'A title is required.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['title'] = $title;
		}
		if ( array_key_exists( 'description', $data ) ) {
			$fields['description'] = (string) $data['description'];
		}
		if ( $fields ) {
			$wpdb->update( Schema::table( 'project_decisions' ), $fields, [ 'id' => $id ] );
			ActivityLog::log( 'decision_updated', 'project', (int) $decision->project_id, [
				'decision_id' => $id,
				'fields'      => array_keys( $fields ),
			] );
		}
		return true;
	}

	/**
	 * Offenen Beschluss vorzeitig schließen (status → 'cancelled'). Nur der
	 * Ersteller oder ein Admin (Groups::user_is_admin) darf das, nur wenn open.
	 *
	 * @return true|WP_Error
	 */
	public static function cancel( int $id, int $user_id ) {
		global $wpdb;

		$decision = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_decisions' ),
			$id
		) );
		if ( ! $decision ) {
			return new WP_Error( 'pp_not_found', __( 'Decision not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( 'open' !== $decision->status ) {
			return new WP_Error( 'pp_decision_closed', __( 'This decision is already closed.', 'project-prepper' ), [ 'status' => 409 ] );
		}
		if ( ! self::user_may_manage( $decision, $user_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only the author or an admin may close this decision.', 'project-prepper' ), [ 'status' => 403 ] );
		}

		$wpdb->update(
			Schema::table( 'project_decisions' ),
			[ 'status' => 'cancelled', 'resolved_at' => current_time( 'mysql' ) ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		ActivityLog::log( 'decision_cancelled', 'project', (int) $decision->project_id, [ 'decision_id' => $id ] );
		return true;
	}

	/**
	 * Beschluss + Stimmen löschen. Nur der Ersteller oder ein Admin
	 * (Groups::user_is_admin) — analog zum Schließen, damit ein Mitglied keine
	 * fremden Beschlüsse aus der Historie entfernen kann.
	 *
	 * @return true|WP_Error
	 */
	public static function delete( int $id, int $user_id ) {
		global $wpdb;

		$decision = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_decisions' ),
			$id
		) );
		if ( ! $decision ) {
			return new WP_Error( 'pp_not_found', __( 'Decision not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( ! self::user_may_manage( $decision, $user_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only the author or an admin may delete this decision.', 'project-prepper' ), [ 'status' => 403 ] );
		}

		$wpdb->delete( Schema::table( 'project_decision_votes' ), [ 'decision_id' => $id ], [ '%d' ] );
		$wpdb->delete( Schema::table( 'project_decisions' ), [ 'id' => $id ], [ '%d' ] );
		ActivityLog::log( 'decision_deleted', 'project', (int) $decision->project_id, [ 'decision_id' => $id ] );
		return true;
	}

	/* ===================== Intern ===================== */

	/**
	 * Auflösungslogik — exakt aus der App (check_decision_complete, Migration
	 * 045) portiert. Wird nur ausgeführt, solange der Beschluss 'open' ist.
	 * total_active = Anzahl aktiver Gruppenmitglieder.
	 */
	private static function resolve( int $decision_id ): void {
		global $wpdb;

		$decision = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_decisions' ),
			$decision_id
		) );
		if ( ! $decision || 'open' !== $decision->status ) {
			return;
		}

		$group_id     = self::project_group_id( (int) $decision->project_id );
		$total_active = $group_id ? count( Groups::members( $group_id ) ) : 0;
		$tally        = self::tally( $decision_id );
		$approvals    = $tally['approve'];
		$rejections   = $tally['reject'];
		$total_votes  = $tally['total'];

		$new_status = null;
		if ( (int) $decision->requires_unanimous ) {
			if ( $total_active > 0 && $approvals === $total_active ) {
				$new_status = 'approved';
			} elseif ( $rejections > 0 ) {
				$new_status = 'rejected';
			}
		} else {
			if ( $total_active > 0 && $total_votes === $total_active ) {
				$new_status = $approvals > $rejections ? 'approved' : 'rejected';
			}
		}

		if ( $new_status ) {
			$wpdb->update(
				Schema::table( 'project_decisions' ),
				[ 'status' => $new_status, 'resolved_at' => current_time( 'mysql' ) ],
				[ 'id' => $decision_id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
			ActivityLog::log( 'decision_resolved', 'project', (int) $decision->project_id, [
				'decision_id' => $decision_id,
				'status'      => $new_status,
			] );
		}
	}

	/**
	 * Stimm-Zähler eines Beschlusses.
	 *
	 * @return array{approve:int,reject:int,abstain:int,total:int}
	 */
	private static function tally( int $decision_id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(*) AS total,
				SUM(vote = 'approve') AS approve,
				SUM(vote = 'reject') AS reject,
				SUM(vote = 'abstain') AS abstain
			 FROM %i WHERE decision_id = %d",
			Schema::table( 'project_decision_votes' ),
			$decision_id
		) );
		return [
			'approve' => $row ? (int) $row->approve : 0,
			'reject'  => $row ? (int) $row->reject : 0,
			'abstain' => $row ? (int) $row->abstain : 0,
			'total'   => $row ? (int) $row->total : 0,
		];
	}

	/**
	 * Stimmen eines Beschlusses mit aufgelöstem WP-User (für die ausklappbare
	 * „wer wie"-Liste). Verwaiste user_id → ->missing=true.
	 *
	 * @return array<object>
	 */
	private static function voters( int $decision_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT user_id, vote, comment, voted_at FROM %i WHERE decision_id = %d ORDER BY voted_at ASC, id ASC',
			Schema::table( 'project_decision_votes' ),
			$decision_id
		) ) ?: [];
		foreach ( $rows as $r ) {
			$user            = get_userdata( (int) $r->user_id );
			$r->user_id      = (int) $r->user_id;
			$r->display_name = $user ? $user->display_name : sprintf( '#%d', (int) $r->user_id );
			$r->missing      = $user ? false : true;
		}
		return $rows;
	}

	/**
	 * owner_group_id eines Projekts (0 = Site-Ebene/keine Gruppe).
	 */
	private static function project_group_id( int $project_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT owner_group_id FROM %i WHERE id = %d',
			Schema::table( 'projects' ),
			$project_id
		) );
	}

	/**
	 * Darf $user_id den Beschluss verwalten (schließen/löschen)? Ersteller ODER
	 * Admin (Groups::user_is_admin).
	 */
	private static function user_may_manage( object $decision, int $user_id ): bool {
		if ( $user_id && (int) $decision->created_by === $user_id ) {
			return true;
		}
		return Groups::user_is_admin( $user_id );
	}
}
