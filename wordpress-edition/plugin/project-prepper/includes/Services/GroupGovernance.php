<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Schema;
use ProjectPrepper\Security;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Kollektiv-Selbstverwaltung im Frontend (Member-Portal Phase 2) — Pendant zum
 * Beitritts-Voting der App (Supabase-Migration 072).
 *
 * Ablauf:
 *  1. Ein Mitglied **lädt** per E-Mail ein  → Einladung `pending`.
 *  2. Der/die Eingeladene **nimmt an**      → bei nur 1 aktivem Mitglied sofort
 *     `approved` (Auto-Join), sonst `voting`.
 *  3. Die aktiven Mitglieder **stimmen ab** (approve/reject).
 *  4. **Auflösung:** eine Ablehnung → `rejected`; **alle** aktiven Mitglieder
 *     approve → `approved` + Mitgliedschaft aktiv (Einstimmigkeit).
 *
 * Override: Administrator/Manager nehmen im wp-admin direkt auf/entfernen
 * (Groups::add_member/remove_member) — übergeht das Voting.
 *
 * Zugriff: Aktionen erfordern die Cap COLLECTIVES (haben auch reine Mitglieder)
 * und — bei Einladen/Abstimmen/Abbrechen — aktive Mitgliedschaft in der Gruppe.
 */
class GroupGovernance {

	const STATUSES = [ 'pending', 'voting', 'approved', 'rejected', 'cancelled' ];

	/* ===================== Gründen ===================== */

	/**
	 * Aktueller User gründet ein Kollektiv und wird Gründer.
	 *
	 * @return int|WP_Error Neue Gruppen-ID.
	 */
	public static function found( string $name, string $description = '' ) {
		$uid = get_current_user_id();
		if ( ! $uid || ! current_user_can( Capabilities::COLLECTIVES ) ) {
			return new WP_Error( 'pp_forbidden', __( 'You are not allowed to found a collective.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		// Schneeball-Schutz (nur wenn aktiviert): max. Kollektive pro User.
		$limit = Security::int( 'groups_per_user' );
		if ( $limit > 0 && count( Groups::user_group_ids( $uid ) ) >= $limit ) {
			return new WP_Error( 'pp_group_limit', __( 'You have reached the maximum number of collectives.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		// Groups::create trägt den aktuellen User automatisch als founder ein.
		return Groups::create( [ 'name' => $name, 'description' => $description ] );
	}

	/* ===================== Einladen ===================== */

	/**
	 * Aktives Mitglied lädt jemanden per E-Mail in seine Gruppe ein.
	 *
	 * @param string $message Optionale Nachricht an die eingeladene Person
	 *                        (wird auf der Karte + in der Einladungs-Mail gezeigt).
	 * @return int|WP_Error Einladungs-ID.
	 */
	public static function invite( int $group_id, string $email, string $message = '' ) {
		global $wpdb;
		$uid = get_current_user_id();

		if ( ! $uid || ! current_user_can( Capabilities::COLLECTIVES ) ) {
			return new WP_Error( 'pp_forbidden', __( 'You are not allowed to invite members.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		if ( ! Groups::is_member( $group_id, $uid ) && ! Groups::user_is_admin( $uid ) ) {
			return new WP_Error( 'pp_not_member', __( 'Only members of this collective can invite others.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'pp_bad_email', __( 'Please enter a valid email address.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		// Schneeball-Schutz (nur wenn aktiviert): max. Einladungen pro User / 24 h.
		$per_day = Security::int( 'invites_per_day' );
		if ( $per_day > 0 ) {
			$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
			$today = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE invited_by = %d AND created_at >= %s',
				Schema::table( 'group_invitations' ),
				$uid,
				$since
			) );
			if ( $today >= $per_day ) {
				return new WP_Error( 'pp_invite_limit', __( 'You have reached your invitation limit for today.', 'project-prepper' ), [ 'status' => 429 ] );
			}
		}

		$invited_user    = get_user_by( 'email', $email );
		$invited_user_id = $invited_user ? (int) $invited_user->ID : null;

		// Schon Mitglied?
		if ( $invited_user_id && Groups::is_member( $group_id, $invited_user_id ) ) {
			return new WP_Error( 'pp_already_member', __( 'That person is already a member of this collective.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		// Offene Einladung (pending/voting) für dieselbe E-Mail?
		$open = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM %i WHERE group_id = %d AND invited_email = %s AND status IN (%s,%s)',
			Schema::table( 'group_invitations' ),
			$group_id,
			$email,
			'pending',
			'voting'
		) );
		if ( $open ) {
			return new WP_Error( 'pp_already_invited', __( 'There is already an open invitation for this address.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$message = trim( wp_strip_all_tags( $message ) );
		$wpdb->insert( Schema::table( 'group_invitations' ), [
			'group_id'        => $group_id,
			'invited_email'   => $email,
			'invited_user_id' => $invited_user_id,
			'invited_by'      => $uid,
			'message'         => '' !== $message ? $message : null,
			'status'          => 'pending',
			'created_at'      => current_time( 'mysql' ),
		] );
		$invitation_id = (int) $wpdb->insert_id;

		ActivityLog::log( 'group_invited', 'group', $group_id, [ 'email' => $email, 'invitation_id' => $invitation_id ] );
		do_action( 'pp_group_invited', $invitation_id );
		return $invitation_id;
	}

	/* ===================== Annehmen / Ablehnen ===================== */

	/**
	 * Eingeladene Person nimmt an → Auto-Join (1 aktives Mitglied) oder Voting.
	 *
	 * @return true|WP_Error
	 */
	public static function accept( int $invitation_id ) {
		global $wpdb;
		$inv = self::get_for_invitee( $invitation_id );
		if ( is_wp_error( $inv ) ) {
			return $inv;
		}
		if ( 'pending' !== $inv->status ) {
			return new WP_Error( 'pp_bad_state', __( 'This invitation can no longer be accepted.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$uid = get_current_user_id();
		// invited_user_id festschreiben (falls per E-Mail eingeladen).
		if ( ! $inv->invited_user_id ) {
			$wpdb->update( Schema::table( 'group_invitations' ), [ 'invited_user_id' => $uid ], [ 'id' => $invitation_id ], [ '%d' ], [ '%d' ] );
			$inv->invited_user_id = $uid;
		}

		$active = self::active_member_count( (int) $inv->group_id );
		if ( $active <= 1 ) {
			// Nur der Gründer → Einstimmigkeit trivial erfüllt: sofort aufnehmen.
			return self::approve_and_join( $inv );
		}

		$wpdb->update( Schema::table( 'group_invitations' ), [ 'status' => 'voting' ], [ 'id' => $invitation_id ], [ '%s' ], [ '%d' ] );
		ActivityLog::log( 'group_invitation_accepted', 'group', (int) $inv->group_id, [ 'invitation_id' => $invitation_id ] );
		return true;
	}

	/**
	 * Eingeladene Person lehnt ab → cancelled.
	 *
	 * @return true|WP_Error
	 */
	public static function decline( int $invitation_id ) {
		global $wpdb;
		$inv = self::get_for_invitee( $invitation_id );
		if ( is_wp_error( $inv ) ) {
			return $inv;
		}
		if ( ! in_array( $inv->status, [ 'pending', 'voting' ], true ) ) {
			return new WP_Error( 'pp_bad_state', __( 'This invitation is already resolved.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$wpdb->update( Schema::table( 'group_invitations' ), [ 'status' => 'cancelled', 'resolved_at' => current_time( 'mysql' ) ], [ 'id' => $invitation_id ], [ '%s', '%s' ], [ '%d' ] );
		ActivityLog::log( 'group_invitation_declined', 'group', (int) $inv->group_id, [ 'invitation_id' => $invitation_id ] );
		return true;
	}

	/**
	 * Aktives Mitglied (oder Admin) bricht eine offene Einladung ab.
	 *
	 * @return true|WP_Error
	 */
	public static function cancel( int $invitation_id ) {
		global $wpdb;
		$inv = self::get( $invitation_id );
		if ( ! $inv ) {
			return new WP_Error( 'pp_not_found', __( 'Invitation not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$uid = get_current_user_id();
		if ( ! Groups::is_member( (int) $inv->group_id, $uid ) && ! Groups::user_is_admin( $uid ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only members of this collective can cancel invitations.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		if ( ! in_array( $inv->status, [ 'pending', 'voting' ], true ) ) {
			return new WP_Error( 'pp_bad_state', __( 'This invitation is already resolved.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$wpdb->update( Schema::table( 'group_invitations' ), [ 'status' => 'cancelled', 'resolved_at' => current_time( 'mysql' ) ], [ 'id' => $invitation_id ], [ '%s', '%s' ], [ '%d' ] );
		ActivityLog::log( 'group_invitation_cancelled', 'group', (int) $inv->group_id, [ 'invitation_id' => $invitation_id ] );
		return true;
	}

	/**
	 * Einladungs-E-Mail erneut versenden (nur solange noch offen / nicht
	 * angenommen). Mitglied der Gruppe oder Admin. Feuert denselben Hook wie
	 * beim Anlegen, sodass genau die Einladungs-Mail nochmal rausgeht.
	 *
	 * @return true|WP_Error
	 */
	public static function resend( int $invitation_id ) {
		global $wpdb;
		$inv = self::get( $invitation_id );
		if ( ! $inv ) {
			return new WP_Error( 'pp_not_found', __( 'Invitation not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$uid = get_current_user_id();
		if ( ! Groups::is_member( (int) $inv->group_id, $uid ) && ! Groups::user_is_admin( $uid ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only members of this collective can resend invitations.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		// Erneut senden ergibt nur Sinn, solange die Person noch nicht angenommen
		// hat (pending = Einladungs-Mail-Phase; voting = bereits angenommen).
		if ( 'pending' !== $inv->status ) {
			return new WP_Error( 'pp_bad_state', __( 'This invitation can no longer be resent.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		// Erinnerungs-Zähler der pending-Phase hochsetzen (App: reminder_count).
		$wpdb->update(
			Schema::table( 'group_invitations' ),
			[ 'reminder_count' => (int) $inv->reminder_count + 1 ],
			[ 'id' => $invitation_id ],
			[ '%d' ],
			[ '%d' ]
		);
		ActivityLog::log( 'group_invitation_resent', 'group', (int) $inv->group_id, [ 'invitation_id' => $invitation_id ] );
		do_action( 'pp_group_invited', $invitation_id );
		return true;
	}

	/**
	 * Aktives Mitglied erinnert die noch nicht abstimmenden Mitglieder in der
	 * Voting-Phase per E-Mail (Pendant zur App-Aktion „Erinnern" bei laufendem
	 * Beitritts-Voting). Erhöht voting_reminder_count. Der/die Eingeladene selbst
	 * stimmt nicht mit und wird nicht erinnert.
	 *
	 * @return true|WP_Error
	 */
	public static function remind_voters( int $invitation_id ) {
		global $wpdb;
		$inv = self::get( $invitation_id );
		if ( ! $inv ) {
			return new WP_Error( 'pp_not_found', __( 'Invitation not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$uid = get_current_user_id();
		if ( ! current_user_can( Capabilities::COLLECTIVES ) ||
			( ! Groups::is_member( (int) $inv->group_id, $uid ) && ! Groups::user_is_admin( $uid ) ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only members of this collective can send voting reminders.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		if ( 'voting' !== $inv->status ) {
			return new WP_Error( 'pp_bad_state', __( 'This invitation is not open for voting.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		// Abstimmberechtigte = aktive Mitglieder ohne den/die Eingeladene/n.
		$eligible = array_values( array_diff(
			self::active_member_ids( (int) $inv->group_id ),
			[ (int) $inv->invited_user_id ]
		) );
		$voted = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			'SELECT voter_id FROM %i WHERE invitation_id = %d',
			Schema::table( 'group_invitation_votes' ),
			$invitation_id
		) ) );
		$non_voters = array_values( array_diff( $eligible, $voted ) );
		if ( ! $non_voters ) {
			// Alle haben schon abgestimmt — nichts zu erinnern (Button ist dann aus).
			return new WP_Error( 'pp_no_voters', __( 'Everyone has already voted.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$wpdb->update(
			Schema::table( 'group_invitations' ),
			[ 'voting_reminder_count' => (int) $inv->voting_reminder_count + 1 ],
			[ 'id' => $invitation_id ],
			[ '%d' ],
			[ '%d' ]
		);
		ActivityLog::log( 'group_voting_reminded', 'group', (int) $inv->group_id, [ 'invitation_id' => $invitation_id, 'recipients' => count( $non_voters ) ] );
		do_action( 'pp_group_voting_reminder', $invitation_id, $non_voters );
		return true;
	}

	/* ===================== Abstimmen ===================== */

	/**
	 * Aktives Mitglied stimmt über eine Einladung ab (approve/reject/abstain).
	 * Upsert. Danach Auflösung (Einstimmigkeit).
	 *
	 * @return true|WP_Error
	 */
	public static function vote( int $invitation_id, string $vote ) {
		global $wpdb;
		$inv = self::get( $invitation_id );
		if ( ! $inv ) {
			return new WP_Error( 'pp_not_found', __( 'Invitation not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( 'voting' !== $inv->status ) {
			return new WP_Error( 'pp_bad_state', __( 'This invitation is not open for voting.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$uid = get_current_user_id();
		if ( ! current_user_can( Capabilities::COLLECTIVES ) || ! Groups::is_member( (int) $inv->group_id, $uid ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only members of this collective can vote.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$vote = in_array( $vote, [ 'approve', 'reject', 'abstain' ], true ) ? $vote : '';
		if ( '' === $vote ) {
			return new WP_Error( 'pp_bad_vote', __( 'Invalid vote.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$table    = Schema::table( 'group_invitation_votes' );
		$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE invitation_id = %d AND voter_id = %d', $table, $invitation_id, $uid ) );
		if ( $existing ) {
			$wpdb->update( $table, [ 'vote' => $vote, 'voted_at' => current_time( 'mysql' ) ], [ 'id' => (int) $existing ], [ '%s', '%s' ], [ '%d' ] );
		} else {
			$wpdb->insert( $table, [ 'invitation_id' => $invitation_id, 'voter_id' => $uid, 'vote' => $vote, 'voted_at' => current_time( 'mysql' ) ] );
		}

		return self::resolve( (int) $inv->id );
	}

	/**
	 * Auflösung: eine Ablehnung → rejected; alle aktiven Mitglieder approve →
	 * approved + Beitritt. Sonst bleibt es bei `voting`.
	 *
	 * @return true|WP_Error
	 */
	private static function resolve( int $invitation_id ) {
		global $wpdb;
		$inv = self::get( $invitation_id );
		if ( ! $inv || 'voting' !== $inv->status ) {
			return true;
		}

		$votes = $wpdb->get_results( $wpdb->prepare(
			'SELECT voter_id, vote FROM %i WHERE invitation_id = %d',
			Schema::table( 'group_invitation_votes' ),
			$invitation_id
		) ) ?: [];

		$active_ids = self::active_member_ids( (int) $inv->group_id );
		$approve    = 0;
		foreach ( $votes as $v ) {
			// Nur Stimmen aktiver Mitglieder zählen.
			if ( ! in_array( (int) $v->voter_id, $active_ids, true ) ) {
				continue;
			}
			if ( 'reject' === $v->vote ) {
				$wpdb->update( Schema::table( 'group_invitations' ), [ 'status' => 'rejected', 'resolved_at' => current_time( 'mysql' ) ], [ 'id' => $invitation_id ], [ '%s', '%s' ], [ '%d' ] );
				ActivityLog::log( 'group_invitation_rejected', 'group', (int) $inv->group_id, [ 'invitation_id' => $invitation_id ] );
				return true;
			}
			if ( 'approve' === $v->vote ) {
				$approve++;
			}
		}

		// Einstimmig: alle aktiven Mitglieder haben mit approve gestimmt.
		if ( $approve >= count( $active_ids ) && count( $active_ids ) > 0 ) {
			return self::approve_and_join( $inv );
		}
		return true;
	}

	/** Einladung genehmigen + Eingeladene/n als Mitglied aufnehmen. */
	private static function approve_and_join( object $inv ) {
		global $wpdb;
		if ( $inv->invited_user_id ) {
			$res = Groups::add_member( (int) $inv->group_id, (int) $inv->invited_user_id, 'member' );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		$wpdb->update( Schema::table( 'group_invitations' ), [ 'status' => 'approved', 'resolved_at' => current_time( 'mysql' ) ], [ 'id' => (int) $inv->id ], [ '%s', '%s' ], [ '%d' ] );
		ActivityLog::log( 'group_invitation_approved', 'group', (int) $inv->group_id, [ 'invitation_id' => (int) $inv->id, 'user_id' => (int) $inv->invited_user_id ] );
		return true;
	}

	/* ===================== Lesen (fürs Portal) ===================== */

	/**
	 * Offene Einladungen für den aktuellen/angegebenen User (per user_id ODER
	 * passender E-Mail). Inklusive Gruppenname + Voting-Stand.
	 *
	 * @return array<object>
	 */
	public static function my_pending_invitations( int $user_id ): array {
		global $wpdb;
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return [];
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT i.*, g.name AS group_name
			 FROM %i i JOIN %i g ON g.id = i.group_id
			 WHERE i.status IN (%s,%s) AND ( i.invited_user_id = %d OR i.invited_email = %s )
			 ORDER BY i.created_at DESC',
			Schema::table( 'group_invitations' ),
			Schema::table( 'groups' ),
			'pending',
			'voting',
			$user_id,
			$user->user_email
		) ) ?: [];
		return $rows;
	}

	/**
	 * Einladungen einer Gruppe in einem bestimmten Status (fürs Mitglieder-Panel).
	 *
	 * @return array<object>
	 */
	public static function invitations_for_group( int $group_id, array $statuses = [ 'pending', 'voting' ] ): array {
		global $wpdb;
		$statuses = array_values( array_intersect( $statuses, self::STATUSES ) );
		if ( ! $statuses ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM %i WHERE group_id = %d AND status IN ($placeholders) ORDER BY created_at DESC",
			Schema::table( 'group_invitations' ),
			$group_id,
			...$statuses
		) ) ?: [];

		$uid = get_current_user_id();
		foreach ( $rows as $row ) {
			$row->my_vote   = self::vote_of( (int) $row->id, $uid );
			$row->approvals = self::approve_count( (int) $row->id, (int) $row->group_id );
			$row->needed    = self::active_member_count( (int) $row->group_id );
			$row->pending_voter_names = ( 'voting' === $row->status )
				? self::non_voter_names( (int) $row->id, (int) $row->group_id, (int) $row->invited_user_id )
				: [];
			self::decorate_names( $row );
		}
		return $rows;
	}

	/**
	 * Zuletzt aufgenommene Einladungen einer Gruppe (status approved) — für die
	 * read-only „Aufgenommen"-Anzeige im Kollektiv-Detail (App zeigt approved
	 * Einladungen weiter mit). Begrenzt und nach Auflösungszeitpunkt sortiert.
	 *
	 * @return array<object>
	 */
	public static function recent_approved_for_group( int $group_id, int $limit = 15 ): array {
		global $wpdb;
		$limit = max( 1, min( 50, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM %i WHERE group_id = %d AND status = 'approved'
			 ORDER BY resolved_at DESC, id DESC LIMIT %d",
			Schema::table( 'group_invitations' ),
			$group_id,
			$limit
		) ) ?: [];
		foreach ( $rows as $row ) {
			self::decorate_names( $row );
		}
		return $rows;
	}

	/**
	 * Namen der abstimmberechtigten Mitglieder, deren Stimme noch fehlt (Voting-
	 * Phase). Der/die Eingeladene stimmt nicht mit.
	 *
	 * @return string[]
	 */
	private static function non_voter_names( int $invitation_id, int $group_id, int $invitee_id ): array {
		global $wpdb;
		$eligible = array_values( array_diff( self::active_member_ids( $group_id ), [ $invitee_id ] ) );
		if ( ! $eligible ) {
			return [];
		}
		$voted = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			'SELECT voter_id FROM %i WHERE invitation_id = %d',
			Schema::table( 'group_invitation_votes' ),
			$invitation_id
		) ) );
		$names = [];
		foreach ( array_diff( $eligible, $voted ) as $uid ) {
			$user    = get_userdata( (int) $uid );
			$names[] = $user ? $user->display_name : ( '#' . (int) $uid );
		}
		return $names;
	}

	/** Einlader-/Eingeladenen-Namen an eine Einladungszeile hängen (fürs Portal). */
	private static function decorate_names( object $row ): void {
		$inviter = $row->invited_by ? get_userdata( (int) $row->invited_by ) : null;
		$row->inviter_name = $inviter ? $inviter->display_name : '';
		$invitee = $row->invited_user_id ? get_userdata( (int) $row->invited_user_id ) : null;
		$row->invitee_name = $invitee ? $invitee->display_name : (string) $row->invited_email;
	}

	/**
	 * Alle offenen Einladungen plattformweit (Backend-Übersicht/Moderation).
	 *
	 * @return array<object>
	 */
	public static function all_pending(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT i.*, g.name AS group_name
			 FROM %i i JOIN %i g ON g.id = i.group_id
			 WHERE i.status IN ('pending','voting')
			 ORDER BY i.created_at DESC",
			Schema::table( 'group_invitations' ),
			Schema::table( 'groups' )
		) ) ?: [];
		foreach ( $rows as $row ) {
			$row->approvals = self::approve_count( (int) $row->id, (int) $row->group_id );
			$row->needed    = self::active_member_count( (int) $row->group_id );
		}
		return $rows;
	}

	/* ===================== Registrierungs-Verknüpfung ===================== */

	/**
	 * Bei Registrierung offene E-Mail-Einladungen mit dem neuen User verknüpfen
	 * (Pendant zum link-on-signup-Trigger der App).
	 */
	public static function link_user_on_register( int $user_id ): void {
		global $wpdb;
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$wpdb->update(
			Schema::table( 'group_invitations' ),
			[ 'invited_user_id' => $user_id ],
			[ 'invited_email' => $user->user_email, 'invited_user_id' => null ],
			[ '%d' ],
			[ '%s', '%d' ]
		);
	}

	/* ===================== Intern ===================== */

	public static function get( int $invitation_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'group_invitations' ), $invitation_id ) ) ?: null;
	}

	/** Einladung holen + prüfen, dass der aktuelle User die/der Eingeladene ist. */
	private static function get_for_invitee( int $invitation_id ) {
		$inv = self::get( $invitation_id );
		if ( ! $inv ) {
			return new WP_Error( 'pp_not_found', __( 'Invitation not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$user = wp_get_current_user();
		$is_invitee = ( (int) $inv->invited_user_id === (int) $user->ID )
			|| ( $inv->invited_email && strtolower( $inv->invited_email ) === strtolower( $user->user_email ) );
		if ( ! $is_invitee ) {
			return new WP_Error( 'pp_forbidden', __( 'This invitation is not addressed to you.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		return $inv;
	}

	private static function active_member_ids( int $group_id ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM %i WHERE group_id = %d', Schema::table( 'group_members' ), $group_id ) );
		return array_map( 'intval', $ids ?: [] );
	}

	private static function active_member_count( int $group_id ): int {
		return count( self::active_member_ids( $group_id ) );
	}

	private static function vote_of( int $invitation_id, int $user_id ): string {
		global $wpdb;
		if ( ! $user_id ) {
			return '';
		}
		return (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT vote FROM %i WHERE invitation_id = %d AND voter_id = %d',
			Schema::table( 'group_invitation_votes' ),
			$invitation_id,
			$user_id
		) );
	}

	private static function approve_count( int $invitation_id, int $group_id ): int {
		global $wpdb;
		$active = self::active_member_ids( $group_id );
		if ( ! $active ) {
			return 0;
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT voter_id FROM %i WHERE invitation_id = %d AND vote = %s',
			Schema::table( 'group_invitation_votes' ),
			$invitation_id,
			'approve'
		) ) ?: [];
		$count = 0;
		foreach ( $rows as $r ) {
			if ( in_array( (int) $r->voter_id, $active, true ) ) {
				$count++;
			}
		}
		return $count;
	}
}
