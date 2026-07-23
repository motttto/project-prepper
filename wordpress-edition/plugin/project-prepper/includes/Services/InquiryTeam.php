<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Team-Verfügbarkeit pro Anfrage (v0.104.0) — Pendant zu `inquiry_invitations`
 * der App (inquiry-team-section.tsx + inquiry-rsvp-banner.tsx).
 *
 * Nur für GRUPPEN-Anfragen (inquiries.owner_group_id): Mitglieder der
 * Eigentümer-Gruppe werden gefragt „Bist du dabei?" und antworten mit
 * Zusage/Vielleicht/Absage. Eine Zeile pro (Anfrage, User), Upsert.
 * status: invited | accepted | maybe | declined.
 *
 * Guards (RLS-Ersatz, IDOR-sicher): JEDE Mutation prüft, dass die Anfrage
 * existiert, eine Eigentümer-Gruppe hat und sowohl Handelnde:r als auch
 * Ziel-User aktive Mitglieder dieser Gruppe sind. Self-RSVP ohne vorherige
 * Einladung ist erlaubt (App-Migration 094 Self-RSVP).
 */
class InquiryTeam {

	const RESPONSES = [ 'accepted', 'maybe', 'declined' ];

	/* ===================== Lesen ===================== */

	/**
	 * Alle RSVP-Zeilen einer Anfrage als Map user_id → row.
	 *
	 * @return array<int,object>
	 */
	public static function for_inquiry( int $inquiry_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE inquiry_id = %d',
			Schema::table( 'inquiry_team' ),
			$inquiry_id
		) ) ?: [];
		$map = [];
		foreach ( $rows as $r ) {
			$map[ (int) $r->user_id ] = $r;
		}
		return $map;
	}

	/** RSVP-Status eines Users für eine Anfrage ('' = keine Zeile). */
	public static function my_status( int $inquiry_id, int $user_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT status FROM %i WHERE inquiry_id = %d AND user_id = %d',
			Schema::table( 'inquiry_team' ),
			$inquiry_id,
			$user_id
		) );
	}

	/**
	 * Offene Anfragen an MICH ('invited') innerhalb einer Gruppe — für den
	 * Hinweis in der Anfragen-Liste und die Benachrichtigungen.
	 *
	 * @return array<int> Anfrage-IDs.
	 */
	public static function pending_for_user( int $user_id, int $group_id = 0 ): array {
		global $wpdb;
		if ( $group_id > 0 ) {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT t.inquiry_id FROM %i t
				 INNER JOIN %i i ON i.id = t.inquiry_id
				 WHERE t.user_id = %d AND t.status = 'invited' AND i.owner_group_id = %d",
				Schema::table( 'inquiry_team' ),
				Schema::table( 'inquiries' ),
				$user_id,
				$group_id
			) );
		} else {
			// Über alle Gruppen des Users (Benachrichtigungs-Glocke).
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT t.inquiry_id FROM %i t
				 INNER JOIN %i i ON i.id = t.inquiry_id
				 INNER JOIN %i gm ON gm.group_id = i.owner_group_id AND gm.user_id = t.user_id
				 WHERE t.user_id = %d AND t.status = 'invited'",
				Schema::table( 'inquiry_team' ),
				Schema::table( 'inquiries' ),
				Schema::table( 'group_members' ),
				$user_id
			) );
		}
		return array_map( 'intval', $ids ?: [] );
	}

	/* ===================== Schreiben ===================== */

	/**
	 * Mitglied anfragen („Bist du dabei?"). Erneutes Anfragen einer Absage
	 * setzt zurück auf 'invited' (App: „Erneut"-Button).
	 *
	 * @return true|WP_Error
	 */
	public static function invite( int $inquiry_id, int $target_user, int $actor ) {
		$group_id = self::guard( $inquiry_id, $actor );
		if ( is_wp_error( $group_id ) ) {
			return $group_id;
		}
		if ( ! $target_user || ! Groups::is_member( $group_id, $target_user ) ) {
			return new WP_Error( 'pp_not_group_member', __( 'This user is not a member of the group that owns the inquiry.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		self::upsert( $inquiry_id, $target_user, 'invited', $actor, null );
		ActivityLog::log( 'inquiry_team_invited', 'inquiry', $inquiry_id, [ 'user_id' => $target_user ] );
		return true;
	}

	/**
	 * Offene Anfrage („invited") zurückziehen — löscht die Zeile. Beantwortete
	 * Zeilen bleiben stehen (die Antwort gehört dem Mitglied).
	 *
	 * @return true|WP_Error
	 */
	public static function revoke( int $inquiry_id, int $target_user, int $actor ) {
		$group_id = self::guard( $inquiry_id, $actor );
		if ( is_wp_error( $group_id ) ) {
			return $group_id;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE inquiry_id = %d AND user_id = %d',
			Schema::table( 'inquiry_team' ),
			$inquiry_id,
			$target_user
		) );
		if ( ! $row || 'invited' !== $row->status ) {
			return new WP_Error( 'pp_not_found', __( 'There is no open availability request for this member.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$wpdb->delete( Schema::table( 'inquiry_team' ), [ 'id' => (int) $row->id ], [ '%d' ] );
		ActivityLog::log( 'inquiry_team_revoked', 'inquiry', $inquiry_id, [ 'user_id' => $target_user ] );
		return true;
	}

	/**
	 * Eigene Antwort setzen (Zusage/Vielleicht/Absage) — Upsert, auch ohne
	 * vorherige Einladung (Self-RSVP wie die App).
	 *
	 * @return true|WP_Error
	 */
	public static function respond( int $inquiry_id, int $user_id, string $status ) {
		if ( ! in_array( $status, self::RESPONSES, true ) ) {
			return new WP_Error( 'pp_invalid_vote', __( 'Invalid vote.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$group_id = self::guard( $inquiry_id, $user_id );
		if ( is_wp_error( $group_id ) ) {
			return $group_id;
		}
		self::upsert( $inquiry_id, $user_id, $status, $user_id, current_time( 'mysql' ) );
		ActivityLog::log( 'inquiry_team_rsvp', 'inquiry', $inquiry_id, [ 'user_id' => $user_id, 'status' => $status ] );
		return true;
	}

	/* ===================== Intern ===================== */

	/**
	 * Gemeinsamer Zugriffs-Guard: Anfrage existiert, gehört einer Gruppe und
	 * $user_id ist Mitglied dieser Gruppe.
	 *
	 * @return int|WP_Error Gruppen-ID.
	 */
	private static function guard( int $inquiry_id, int $user_id ) {
		global $wpdb;
		$group_id = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT owner_group_id FROM %i WHERE id = %d',
			Schema::table( 'inquiries' ),
			$inquiry_id
		) );
		if ( ! $group_id ) {
			return new WP_Error( 'pp_no_group', __( 'Only group inquiries have a team section.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		if ( ! $user_id || ! Groups::is_member( $group_id, $user_id ) ) {
			return new WP_Error( 'pp_not_group_member', __( 'This user is not a member of the group that owns the inquiry.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		return $group_id;
	}

	/** Zeile anlegen oder aktualisieren (UNIQUE inquiry_user). */
	private static function upsert( int $inquiry_id, int $user_id, string $status, int $actor, ?string $responded_at ): void {
		global $wpdb;
		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM %i WHERE inquiry_id = %d AND user_id = %d',
			Schema::table( 'inquiry_team' ),
			$inquiry_id,
			$user_id
		) );
		if ( $existing ) {
			$wpdb->update(
				Schema::table( 'inquiry_team' ),
				[
					'status'       => $status,
					'invited_by'   => $actor ?: null,
					'responded_at' => $responded_at,
				],
				[ 'id' => (int) $existing ],
				[ '%s', '%d', '%s' ],
				[ '%d' ]
			);
			return;
		}
		$wpdb->insert( Schema::table( 'inquiry_team' ), [
			'inquiry_id'   => $inquiry_id,
			'user_id'      => $user_id,
			'status'       => $status,
			'invited_by'   => $actor ?: null,
			'created_at'   => current_time( 'mysql' ),
			'responded_at' => $responded_at,
		] );
	}
}
