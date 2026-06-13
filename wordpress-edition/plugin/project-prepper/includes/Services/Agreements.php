<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Kooperationsvereinbarung pro Projekt (v0.14.0, Gruppen-Phase 5) — Pendant zu
 * `cooperation_agreements` / `agreement_signatures` der App.
 *
 * Vereinfachung ggü. App (dokumentiert): der Vertragsinhalt ist ein Freitext-
 * Body (`terms`), KEINE strukturierten profit_formula/exit_rules-JSON — die
 * Gewinnverteilung lebt bereits in Phase 4 (ProfitShares). Status-Set:
 * draft|signing|active|terminated (kein `amended` der App).
 *
 * Es gibt höchstens EINE Vereinbarung pro Projekt (UNIQUE project_id). Die
 * Routen lösen project→agreement auf; ein {agreement_id} ist nicht nötig.
 *
 * Lebenszyklus / Status-Maschine:
 *
 *  draft    — frei editierbar (title/terms). Ersteller/Admin gibt frei →
 *  signing  — terms gesperrt; aktive Gruppenmitglieder unterschreiben/lehnen ab.
 *             Nach jeder Unterschrift Auflösung (resolve): wenn ALLE aktiven
 *             Mitglieder signed_at gesetzt haben UND keine Ablehnung vorliegt →
 *  active    — activated_at gesetzt. (Eine Ablehnung verhindert die Aktivierung;
 *             Ersteller/Admin entscheidet dann via revise (→ draft, version++,
 *             Signaturen geleert) oder terminate (→ terminated).)
 *  terminated — Endstatus.
 *
 * BEWUSSTE ABWEICHUNG von der App: dort setzt eine Ablehnung (decline) den
 * Vertrag automatisch zurück auf draft. Im WP-Modell ist decline NICHT
 * destruktiv — die Vereinbarung bleibt in signing, der Ersteller/Admin
 * entscheidet manuell (revise/terminate). resolve() löst nur zu „active" auf,
 * nie zurück.
 *
 * Validierung (Kernstück): sign/decline erfordern status=signing und ein
 * aktives Mitglied der Eigentümer-Gruppe (Groups::is_member), sonst 403
 * pp_not_group_member. Analog zu Decisions::cast_vote.
 *
 * Siehe docs/03-GRUPPEN-ARCHITEKTUR.md §Phase 5.
 */
class Agreements {

	const STATUSES = [ 'draft', 'signing', 'active', 'terminated' ];

	/* ===================== Lesen ===================== */

	/**
	 * Die (einzige) Vereinbarung eines Projekts oder null, angereichert mit:
	 *  - signatures: je aktivem Gruppenmitglied { user_id, display_name,
	 *    status (signed|declined|pending), signed_at, declined_at, missing }.
	 *    Signatur-Zeilen verwaister WP-User → missing=true.
	 *  - total_members: Anzahl aktiver Gruppenmitglieder (Signatur-Soll).
	 *  - signed_count / declined_count.
	 *  - my_signature (signed|declined|pending) + can_sign (Gruppenmitglied UND
	 *    status=signing) für den aktuellen User.
	 *
	 * @param int      $project_id
	 * @param int|null $current_user_id Für my_signature/can_sign; 0/null = keiner.
	 */
	public static function for_project( int $project_id, ?int $current_user_id = null ): ?object {
		global $wpdb;

		$agreement = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE project_id = %d',
			Schema::table( 'project_agreements' ),
			$project_id
		) );
		if ( ! $agreement ) {
			return null;
		}

		$agreement->version = (int) $agreement->version;

		$group_id = self::project_group_id( $project_id );
		$members  = $group_id ? Groups::members( $group_id ) : [];

		// Bestehende Signatur-Zeilen nach user_id indizieren.
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT user_id, signed_at, declined_at FROM %i WHERE agreement_id = %d',
			Schema::table( 'project_agreement_signatures' ),
			(int) $agreement->id
		) ) ?: [];
		$by_user = [];
		foreach ( $rows as $r ) {
			$by_user[ (int) $r->user_id ] = $r;
		}

		$signatures    = [];
		$signed_count  = 0;
		$declined_count = 0;
		$seen          = [];

		// Eine Zeile je aktivem Gruppenmitglied (Signatur-Soll).
		foreach ( $members as $m ) {
			$uid           = (int) $m->user_id;
			$seen[ $uid ]  = true;
			$sig           = isset( $by_user[ $uid ] ) ? $by_user[ $uid ] : null;
			$status        = self::sig_status( $sig );
			if ( 'signed' === $status ) {
				$signed_count++;
			} elseif ( 'declined' === $status ) {
				$declined_count++;
			}
			$signatures[] = (object) [
				'user_id'      => $uid,
				'display_name' => $m->display_name,
				'status'       => $status,
				'signed_at'    => $sig ? $sig->signed_at : null,
				'declined_at'  => $sig ? $sig->declined_at : null,
				'missing'      => false,
			];
		}

		// Verwaiste Signaturen (User nicht mehr aktives Gruppenmitglied) als
		// missing anhängen — zählen NICHT zum Soll, werden aber sichtbar gemacht.
		foreach ( $by_user as $uid => $sig ) {
			if ( isset( $seen[ $uid ] ) ) {
				continue;
			}
			$user         = get_userdata( $uid );
			$signatures[] = (object) [
				'user_id'      => $uid,
				'display_name' => $user ? $user->display_name : sprintf( '#%d', $uid ),
				'status'       => self::sig_status( $sig ),
				'signed_at'    => $sig->signed_at,
				'declined_at'  => $sig->declined_at,
				'missing'      => true,
			];
		}

		$agreement->signatures     = $signatures;
		$agreement->total_members  = count( $members );
		$agreement->signed_count   = $signed_count;
		$agreement->declined_count = $declined_count;

		// Eigener Status + ob der aktuelle User unterschreiben darf.
		$my = 'pending';
		if ( $current_user_id && isset( $by_user[ $current_user_id ] ) ) {
			$my = self::sig_status( $by_user[ $current_user_id ] );
		}
		$agreement->my_signature = $my;
		$agreement->can_sign     = ( 'signing' === $agreement->status && $group_id
			&& $current_user_id && Groups::is_member( $group_id, $current_user_id ) );

		return $agreement;
	}

	/**
	 * Rohzeile der Vereinbarung (ohne Anreicherung) — für die REST-Guards
	 * (Zugehörigkeit project→agreement).
	 */
	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_agreements' ),
			$id
		) );
	}

	/* ===================== Schreiben ===================== */

	/**
	 * Vereinbarung anlegen.
	 *
	 * Validierung:
	 * - Projekt MUSS eine Eigentümer-Gruppe haben (sonst 400 pp_no_group — ohne
	 *   Gruppe gibt es keine Unterzeichner).
	 * - Nur EINE Vereinbarung pro Projekt (zweites create → 409 pp_agreement_exists).
	 *
	 * status=draft, version=1, created_by=aktueller User.
	 *
	 * @return int|WP_Error  Neue Vereinbarungs-ID.
	 */
	public static function create( int $project_id, array $data ) {
		global $wpdb;

		$group_id = self::project_group_id( $project_id );
		if ( ! $group_id ) {
			return new WP_Error(
				'pp_no_group',
				__( 'This project has no owning group. Assign a group first to set up an agreement.', 'project-prepper' ),
				[ 'status' => 400 ]
			);
		}

		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM %i WHERE project_id = %d',
			Schema::table( 'project_agreements' ),
			$project_id
		) );
		if ( $existing ) {
			return new WP_Error(
				'pp_agreement_exists',
				__( 'This project already has an agreement.', 'project-prepper' ),
				[ 'status' => 409 ]
			);
		}

		$title = isset( $data['title'] ) ? trim( (string) $data['title'] ) : '';

		$wpdb->insert( Schema::table( 'project_agreements' ), [
			'project_id'   => $project_id,
			'title'        => $title,
			'terms'        => isset( $data['terms'] ) ? (string) $data['terms'] : '',
			'status'       => 'draft',
			'version'      => 1,
			'created_by'   => get_current_user_id() ?: null,
			'created_at'   => current_time( 'mysql' ),
			'activated_at' => null,
		] );
		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'agreement_created', 'project', $project_id, [
			'agreement_id' => $id,
			'title'        => $title,
		] );
		return $id;
	}

	/**
	 * Titel/Vertragstext ändern — NUR solange status=draft (sonst 409
	 * pp_agreement_locked).
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$agreement = self::get( $id );
		if ( ! $agreement ) {
			return new WP_Error( 'pp_not_found', __( 'Agreement not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( 'draft' !== $agreement->status ) {
			return new WP_Error( 'pp_agreement_locked', __( 'The agreement can only be edited while it is a draft.', 'project-prepper' ), [ 'status' => 409 ] );
		}

		$fields = [];
		if ( array_key_exists( 'title', $data ) ) {
			$fields['title'] = trim( (string) $data['title'] );
		}
		if ( array_key_exists( 'terms', $data ) ) {
			$fields['terms'] = (string) $data['terms'];
		}
		if ( $fields ) {
			$wpdb->update( Schema::table( 'project_agreements' ), $fields, [ 'id' => $id ] );
			ActivityLog::log( 'agreement_updated', 'project', (int) $agreement->project_id, [
				'agreement_id' => $id,
				'fields'       => array_keys( $fields ),
			] );
		}
		return true;
	}

	/**
	 * Zur Unterzeichnung freigeben (draft→signing). Ersteller/Admin. Ab jetzt
	 * sind die terms gesperrt.
	 *
	 * @return true|WP_Error
	 */
	public static function open_for_signing( int $id, int $user_id ) {
		global $wpdb;

		$agreement = self::get( $id );
		if ( ! $agreement ) {
			return new WP_Error( 'pp_not_found', __( 'Agreement not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( ! self::user_may_manage( $agreement, $user_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only the author or an admin may manage this agreement.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		if ( 'draft' !== $agreement->status ) {
			return new WP_Error( 'pp_agreement_not_draft', __( 'Only a draft can be opened for signing.', 'project-prepper' ), [ 'status' => 409 ] );
		}

		$wpdb->update(
			Schema::table( 'project_agreements' ),
			[ 'status' => 'signing' ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);
		ActivityLog::log( 'agreement_opened', 'project', (int) $agreement->project_id, [ 'agreement_id' => $id ] );
		return true;
	}

	/**
	 * Unterschreiben.
	 *
	 * Validierung:
	 * - status=signing (sonst 409 pp_agreement_not_signing).
	 * - $user_id MUSS aktives Mitglied der Eigentümer-Gruppe sein (sonst 403
	 *   pp_not_group_member).
	 *
	 * Upsert in signatures (signed_at=jetzt, declined_at=NULL). Danach Auflösung.
	 *
	 * @return true|WP_Error
	 */
	public static function sign( int $id, int $user_id ) {
		return self::record_signature( $id, $user_id, true );
	}

	/**
	 * Ablehnen. Validierung wie sign(); Upsert (declined_at=jetzt, signed_at=NULL).
	 * Verhindert die Aktivierung (declined_count>0), löst aber NICHT zurück —
	 * bewusste Abweichung von der App (siehe Klassen-Doc).
	 *
	 * @return true|WP_Error
	 */
	public static function decline( int $id, int $user_id ) {
		return self::record_signature( $id, $user_id, false );
	}

	/**
	 * Überarbeiten (signing→draft). Ersteller/Admin. version++, ALLE Signaturen
	 * gelöscht (die alten Unterschriften gelten für die neue Fassung nicht mehr).
	 * Danach können die terms wieder geändert werden.
	 *
	 * @return true|WP_Error
	 */
	public static function revise( int $id, int $user_id ) {
		global $wpdb;

		$agreement = self::get( $id );
		if ( ! $agreement ) {
			return new WP_Error( 'pp_not_found', __( 'Agreement not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( ! self::user_may_manage( $agreement, $user_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only the author or an admin may manage this agreement.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		if ( 'signing' !== $agreement->status ) {
			return new WP_Error( 'pp_agreement_not_signing', __( 'Only an agreement in signing can be revised.', 'project-prepper' ), [ 'status' => 409 ] );
		}

		$wpdb->delete( Schema::table( 'project_agreement_signatures' ), [ 'agreement_id' => $id ], [ '%d' ] );
		$wpdb->update(
			Schema::table( 'project_agreements' ),
			[ 'status' => 'draft', 'version' => (int) $agreement->version + 1, 'activated_at' => null ],
			[ 'id' => $id ],
			[ '%s', '%d', '%s' ],
			[ '%d' ]
		);
		ActivityLog::log( 'agreement_revised', 'project', (int) $agreement->project_id, [
			'agreement_id' => $id,
			'version'      => (int) $agreement->version + 1,
		] );
		return true;
	}

	/**
	 * Beenden (→ terminated). Ersteller/Admin. Aus jedem Status erlaubt außer
	 * bereits terminated.
	 *
	 * @return true|WP_Error
	 */
	public static function terminate( int $id, int $user_id ) {
		global $wpdb;

		$agreement = self::get( $id );
		if ( ! $agreement ) {
			return new WP_Error( 'pp_not_found', __( 'Agreement not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( ! self::user_may_manage( $agreement, $user_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only the author or an admin may manage this agreement.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		if ( 'terminated' === $agreement->status ) {
			return new WP_Error( 'pp_agreement_terminated', __( 'The agreement is already terminated.', 'project-prepper' ), [ 'status' => 409 ] );
		}

		$wpdb->update(
			Schema::table( 'project_agreements' ),
			[ 'status' => 'terminated' ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);
		ActivityLog::log( 'agreement_terminated', 'project', (int) $agreement->project_id, [ 'agreement_id' => $id ] );
		return true;
	}

	/**
	 * Vereinbarung + Signaturen löschen. Ersteller/Admin.
	 *
	 * @return true|WP_Error
	 */
	public static function delete( int $id, int $user_id ) {
		global $wpdb;

		$agreement = self::get( $id );
		if ( ! $agreement ) {
			return new WP_Error( 'pp_not_found', __( 'Agreement not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( ! self::user_may_manage( $agreement, $user_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only the author or an admin may delete this agreement.', 'project-prepper' ), [ 'status' => 403 ] );
		}

		$wpdb->delete( Schema::table( 'project_agreement_signatures' ), [ 'agreement_id' => $id ], [ '%d' ] );
		$wpdb->delete( Schema::table( 'project_agreements' ), [ 'id' => $id ], [ '%d' ] );
		ActivityLog::log( 'agreement_deleted', 'project', (int) $agreement->project_id, [ 'agreement_id' => $id ] );
		return true;
	}

	/* ===================== Intern ===================== */

	/**
	 * Gemeinsamer Pfad für sign/decline: Validierung, Upsert, Auflösung.
	 *
	 * @param bool $signed true=Unterschrift, false=Ablehnung.
	 * @return true|WP_Error
	 */
	private static function record_signature( int $id, int $user_id, bool $signed ) {
		global $wpdb;

		$agreement = self::get( $id );
		if ( ! $agreement ) {
			return new WP_Error( 'pp_not_found', __( 'Agreement not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( 'signing' !== $agreement->status ) {
			return new WP_Error( 'pp_agreement_not_signing', __( 'This agreement is not open for signing.', 'project-prepper' ), [ 'status' => 409 ] );
		}

		$group_id = self::project_group_id( (int) $agreement->project_id );
		if ( ! $group_id || ! Groups::is_member( $group_id, $user_id ) ) {
			return new WP_Error(
				'pp_not_group_member',
				__( 'Only members of the project group may sign.', 'project-prepper' ),
				[ 'status' => 403 ]
			);
		}

		$now      = current_time( 'mysql' );
		$signed_at   = $signed ? $now : null;
		$declined_at = $signed ? null : $now;

		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM %i WHERE agreement_id = %d AND user_id = %d',
			Schema::table( 'project_agreement_signatures' ),
			$id,
			$user_id
		) );
		if ( $existing ) {
			$wpdb->update(
				Schema::table( 'project_agreement_signatures' ),
				[ 'signed_at' => $signed_at, 'declined_at' => $declined_at ],
				[ 'id' => (int) $existing ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
		} else {
			$wpdb->insert( Schema::table( 'project_agreement_signatures' ), [
				'agreement_id' => $id,
				'user_id'      => $user_id,
				'signed_at'    => $signed_at,
				'declined_at'  => $declined_at,
			] );
		}

		ActivityLog::log( $signed ? 'agreement_signed' : 'agreement_declined', 'project', (int) $agreement->project_id, [
			'agreement_id' => $id,
			'user_id'      => $user_id,
		] );

		self::resolve( $id );
		return true;
	}

	/**
	 * Auflösung: wenn status=signing UND alle aktiven Gruppenmitglieder
	 * unterschrieben haben (signed_count == total_members, total_members > 0)
	 * UND declined_count == 0 → status=active, activated_at=jetzt.
	 *
	 * Eine Ablehnung (declined_count>0) verhindert die Aktivierung. Es wird NIE
	 * zurück aufgelöst (decline ist nicht destruktiv — bewusste Abweichung von
	 * der App, siehe Klassen-Doc).
	 */
	private static function resolve( int $id ): void {
		global $wpdb;

		$agreement = self::get( $id );
		if ( ! $agreement || 'signing' !== $agreement->status ) {
			return;
		}

		$group_id = self::project_group_id( (int) $agreement->project_id );
		$members  = $group_id ? Groups::members( $group_id ) : [];
		$total    = count( $members );
		if ( $total < 1 ) {
			return;
		}

		$signed   = 0;
		$declined = 0;
		foreach ( $members as $m ) {
			$sig = $wpdb->get_row( $wpdb->prepare(
				'SELECT signed_at, declined_at FROM %i WHERE agreement_id = %d AND user_id = %d',
				Schema::table( 'project_agreement_signatures' ),
				$id,
				(int) $m->user_id
			) );
			$status = self::sig_status( $sig );
			if ( 'signed' === $status ) {
				$signed++;
			} elseif ( 'declined' === $status ) {
				$declined++;
			}
		}

		if ( 0 === $declined && $signed === $total ) {
			$wpdb->update(
				Schema::table( 'project_agreements' ),
				[ 'status' => 'active', 'activated_at' => current_time( 'mysql' ) ],
				[ 'id' => $id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
			ActivityLog::log( 'agreement_activated', 'project', (int) $agreement->project_id, [ 'agreement_id' => $id ] );
		}
	}

	/**
	 * Signatur-Status aus einer Zeile ableiten: signed > declined > pending.
	 *
	 * @param object|null $sig Zeile mit signed_at/declined_at oder null.
	 */
	private static function sig_status( $sig ): string {
		if ( ! $sig ) {
			return 'pending';
		}
		if ( ! empty( $sig->signed_at ) ) {
			return 'signed';
		}
		if ( ! empty( $sig->declined_at ) ) {
			return 'declined';
		}
		return 'pending';
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
	 * Darf $user_id die Vereinbarung verwalten (freigeben/überarbeiten/beenden/
	 * löschen)? Ersteller ODER Admin (Groups::user_is_admin).
	 */
	private static function user_may_manage( object $agreement, int $user_id ): bool {
		if ( $user_id && (int) $agreement->created_by === $user_id ) {
			return true;
		}
		return Groups::user_is_admin( $user_id );
	}
}
