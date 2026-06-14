<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Projekt-Umfragen (v0.15.0) — Pendant zu `org_polls` / `org_poll_options` /
 * `org_poll_votes` der App (projektbezogene Variante, tab-polls).
 *
 * Termin- (date) oder Auswahl-Umfragen (choice) unter den aktiven Mitgliedern
 * der besitzenden Gruppe eines Projekts (pp_projects.owner_group_id). Jede
 * Umfrage hat MEHRERE Optionen; je Option stimmt ein Mitglied mit
 * Ja/Nein/Vielleicht (yes|no|maybe) ab. Anders als die Beschlüsse (Decisions)
 * gibt es KEIN Auto-Resolve — eine Umfrage bleibt offen, bis sie manuell
 * geschlossen wird (Ersteller/Admin).
 *
 * Validierung (Kernstück): cast_vote erfordert ein aktives Mitglied der
 * Eigentümer-Gruppe (Groups::is_member), sonst 403 pp_not_group_member, und
 * eine offene Umfrage, sonst 409 pp_poll_closed.
 *
 * Siehe docs/03-GRUPPEN-ARCHITEKTUR.md.
 */
class Polls {

	const VOTES    = [ 'yes', 'no', 'maybe' ];
	const TYPES    = [ 'date', 'choice' ];
	const STATUSES = [ 'open', 'closed' ];

	/* ===================== Lesen ===================== */

	/**
	 * Umfragen eines Projekts, je Zeile mit options (label/option_date/
	 * option_time/sort_order), pro Option Tally {yes,no,maybe}, total_members
	 * (aktive Gruppenmitglieder), can_vote (ob der aktuelle User abstimmen darf =
	 * aktives Gruppenmitglied) und my_votes (option_id → vote des aktuellen
	 * Users). Bei date-Umfragen sind die Optionen nach option_date/option_time
	 * sortiert, sonst nach sort_order. Offene Umfragen zuerst, dann created_at
	 * desc.
	 *
	 * @param int      $project_id
	 * @param int|null $current_user_id Für my_votes/can_vote; 0/null = keiner.
	 * @return array<object>
	 */
	public static function for_project( int $project_id, ?int $current_user_id = null ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM %i WHERE project_id = %d
			 ORDER BY (status = 'open') DESC, created_at DESC, id DESC",
			Schema::table( 'project_polls' ),
			$project_id
		) ) ?: [];

		if ( ! $rows ) {
			return [];
		}

		$group_id      = self::project_group_id( $project_id );
		$total_members = $group_id ? count( Groups::members( $group_id ) ) : 0;
		$can_vote      = ( $group_id && $current_user_id && Groups::is_member( $group_id, $current_user_id ) );

		foreach ( $rows as $row ) {
			$row->id            = (int) $row->id;
			$row->project_id    = (int) $row->project_id;
			$row->total_members = $total_members;
			$row->can_vote      = $can_vote;
			$row->options       = self::options( $row->id, $row->poll_type );
			$row->my_votes      = $current_user_id ? self::my_votes( $row->id, $current_user_id ) : new \stdClass();
		}
		return $rows;
	}

	/**
	 * Gruppen-weite Umfragen (v0.21.0) — nicht an ein Projekt gebunden, sondern
	 * direkt an eine Gruppe (group_id). Gleiche Optionen/Stimmen-Mechanik wie die
	 * Projekt-Umfragen; Stimmberechtigte = aktive Mitglieder der Gruppe.
	 *
	 * @return array<object>
	 */
	public static function for_group( int $group_id, ?int $current_user_id = null ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM %i WHERE group_id = %d
			 ORDER BY (status = 'open') DESC, created_at DESC, id DESC",
			Schema::table( 'project_polls' ),
			$group_id
		) ) ?: [];
		if ( ! $rows ) {
			return [];
		}

		$total_members = count( Groups::members( $group_id ) );
		$can_vote      = ( $current_user_id && Groups::is_member( $group_id, $current_user_id ) );

		foreach ( $rows as $row ) {
			$row->id            = (int) $row->id;
			$row->group_id      = (int) $row->group_id;
			$row->total_members = $total_members;
			$row->can_vote      = $can_vote;
			$row->options       = self::options( $row->id, $row->poll_type );
			$row->my_votes      = $current_user_id ? self::my_votes( $row->id, $current_user_id ) : new \stdClass();
		}
		return $rows;
	}

	/**
	 * Eine Umfrage (ohne Optionen/Stimmen) — für nested-Route-Guards.
	 */
	public static function get( int $id ): ?object {
		global $wpdb;
		$poll = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_polls' ),
			$id
		) );
		if ( ! $poll ) {
			return null;
		}
		$poll->id         = (int) $poll->id;
		$poll->project_id = (int) $poll->project_id;
		return $poll;
	}

	/* ===================== Schreiben ===================== */

	/**
	 * Umfrage anlegen.
	 *
	 * Validierung:
	 * - Projekt MUSS eine Eigentümer-Gruppe haben (sonst 400 pp_no_group).
	 * - title Pflicht (sonst 400 pp_missing_title).
	 * - poll_type ∈ {date,choice}.
	 * - mind. 2 Optionen (sonst 400 pp_need_options); für date jede mit gültigem
	 *   option_date (Availability::is_valid_date), für choice mit nicht-leerem
	 *   label.
	 *
	 * @param int   $project_id
	 * @param array $data title, description, poll_type, options[]
	 * @return int|WP_Error  Neue Umfrage-ID.
	 */
	public static function create( int $project_id, array $data ) {
		global $wpdb;

		$group_id = self::project_group_id( $project_id );
		if ( ! $group_id ) {
			return new WP_Error(
				'pp_no_group',
				__( 'This project has no owning group. Assign a group first to run polls.', 'project-prepper' ),
				[ 'status' => 400 ]
			);
		}

		$title = isset( $data['title'] ) ? trim( (string) $data['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'pp_missing_title', __( 'A title is required.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$poll_type = isset( $data['poll_type'] ) ? (string) $data['poll_type'] : 'date';
		if ( ! in_array( $poll_type, self::TYPES, true ) ) {
			$poll_type = 'date';
		}

		// Optionen normalisieren + validieren.
		$options = self::normalize_options( $poll_type, $data['options'] ?? [] );
		if ( count( $options ) < 2 ) {
			return new WP_Error(
				'pp_need_options',
				__( 'A poll needs at least two valid options.', 'project-prepper' ),
				[ 'status' => 400 ]
			);
		}

		$wpdb->insert( Schema::table( 'project_polls' ), [
			'project_id'  => $project_id,
			'title'       => $title,
			'description' => isset( $data['description'] ) ? (string) $data['description'] : '',
			'poll_type'   => $poll_type,
			'status'      => 'open',
			'created_by'  => get_current_user_id() ?: null,
			'created_at'  => current_time( 'mysql' ),
			'closed_at'   => null,
		] );
		$poll_id = (int) $wpdb->insert_id;

		$sort = 0;
		foreach ( $options as $opt ) {
			$wpdb->insert( Schema::table( 'project_poll_options' ), [
				'poll_id'     => $poll_id,
				'label'       => $opt['label'],
				'option_date' => $opt['option_date'],
				'option_time' => $opt['option_time'],
				'sort_order'  => $sort++,
			] );
		}

		ActivityLog::log( 'poll_created', 'project', $project_id, [
			'poll_id'   => $poll_id,
			'title'     => $title,
			'poll_type' => $poll_type,
			'options'   => count( $options ),
		] );
		return $poll_id;
	}

	/**
	 * Gruppen-weite Umfrage anlegen (v0.21.0). group_id direkt, project_id = 0.
	 * Nur ein aktives Mitglied der Gruppe darf anlegen.
	 *
	 * @return int|WP_Error
	 */
	public static function create_group( int $group_id, array $data ) {
		global $wpdb;

		if ( ! $group_id || ! Groups::is_member( $group_id, get_current_user_id() ) ) {
			return new WP_Error( 'pp_not_group_member', __( 'Only members of the project group may vote.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$title = isset( $data['title'] ) ? trim( (string) $data['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'pp_missing_title', __( 'A title is required.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$poll_type = ( isset( $data['poll_type'] ) && in_array( $data['poll_type'], self::TYPES, true ) ) ? (string) $data['poll_type'] : 'date';
		$options   = self::normalize_options( $poll_type, $data['options'] ?? [] );
		if ( count( $options ) < 2 ) {
			return new WP_Error( 'pp_need_options', __( 'A poll needs at least two valid options.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$wpdb->insert( Schema::table( 'project_polls' ), [
			'project_id'  => 0,
			'group_id'    => $group_id,
			'title'       => $title,
			'description' => isset( $data['description'] ) ? (string) $data['description'] : '',
			'poll_type'   => $poll_type,
			'status'      => 'open',
			'created_by'  => get_current_user_id() ?: null,
			'created_at'  => current_time( 'mysql' ),
			'closed_at'   => null,
		] );
		$poll_id = (int) $wpdb->insert_id;

		$sort = 0;
		foreach ( $options as $opt ) {
			$wpdb->insert( Schema::table( 'project_poll_options' ), [
				'poll_id'     => $poll_id,
				'label'       => $opt['label'],
				'option_date' => $opt['option_date'],
				'option_time' => $opt['option_time'],
				'sort_order'  => $sort++,
			] );
		}

		ActivityLog::log( 'poll_created', 'group', $group_id, [ 'poll_id' => $poll_id, 'title' => $title, 'poll_type' => $poll_type, 'options' => count( $options ) ] );
		return $poll_id;
	}

	/**
	 * Optionen aus dem Eingabe-Array normalisieren + validieren (date|choice).
	 *
	 * @param mixed $raw
	 * @return array<array{label:string,option_date:?string,option_time:string}>
	 */
	private static function normalize_options( string $poll_type, $raw ): array {
		$raw     = is_array( $raw ) ? $raw : [];
		$options = [];
		foreach ( $raw as $opt ) {
			if ( ! is_array( $opt ) ) {
				continue;
			}
			if ( 'date' === $poll_type ) {
				$date = isset( $opt['option_date'] ) ? trim( (string) $opt['option_date'] ) : '';
				if ( '' === $date || ! Availability::is_valid_date( $date ) ) {
					continue;
				}
				$time = isset( $opt['option_time'] ) ? trim( (string) $opt['option_time'] ) : '';
				if ( '' !== $time && ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
					$time = '';
				}
				$options[] = [ 'label' => '', 'option_date' => $date, 'option_time' => $time ];
			} else {
				$label = isset( $opt['label'] ) ? trim( (string) $opt['label'] ) : '';
				if ( '' === $label ) {
					continue;
				}
				$options[] = [ 'label' => $label, 'option_date' => null, 'option_time' => '' ];
			}
		}
		return $options;
	}

	/**
	 * Stimme zu EINER Option abgeben oder ändern (Upsert über UNIQUE
	 * option_user). KEIN Auto-Resolve.
	 *
	 * Validierung:
	 * - Option existiert + zugehörige Umfrage ist 'open' (sonst 409
	 *   pp_poll_closed).
	 * - vote ∈ {yes,no,maybe} (sonst 400 pp_invalid_vote).
	 * - $user_id MUSS aktives Mitglied der Eigentümer-Gruppe sein (sonst 403
	 *   pp_not_group_member).
	 *
	 * @return true|WP_Error
	 */
	public static function cast_vote( int $option_id, int $user_id, string $vote ) {
		global $wpdb;

		$option = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_poll_options' ),
			$option_id
		) );
		if ( ! $option ) {
			return new WP_Error( 'pp_not_found', __( 'Poll option not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}

		$poll = self::get( (int) $option->poll_id );
		if ( ! $poll ) {
			return new WP_Error( 'pp_not_found', __( 'Poll not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( 'open' !== $poll->status ) {
			return new WP_Error( 'pp_poll_closed', __( 'This poll is already closed.', 'project-prepper' ), [ 'status' => 409 ] );
		}
		if ( ! in_array( $vote, self::VOTES, true ) ) {
			return new WP_Error( 'pp_invalid_vote', __( 'Invalid vote.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$group_id = self::owning_group( $poll );
		if ( ! $group_id || ! Groups::is_member( $group_id, $user_id ) ) {
			return new WP_Error(
				'pp_not_group_member',
				__( 'Only members of the project group may vote.', 'project-prepper' ),
				[ 'status' => 403 ]
			);
		}

		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM %i WHERE option_id = %d AND user_id = %d',
			Schema::table( 'project_poll_votes' ),
			$option_id,
			$user_id
		) );
		if ( $existing ) {
			$wpdb->update(
				Schema::table( 'project_poll_votes' ),
				[ 'vote' => $vote ],
				[ 'id' => (int) $existing ],
				[ '%s' ],
				[ '%d' ]
			);
		} else {
			$wpdb->insert( Schema::table( 'project_poll_votes' ), [
				'option_id' => $option_id,
				'user_id'   => $user_id,
				'vote'      => $vote,
			] );
		}

		ActivityLog::log( 'poll_vote_cast', 'project', $poll->project_id, [
			'poll_id'   => $poll->id,
			'option_id' => $option_id,
			'user_id'   => $user_id,
			'vote'      => $vote,
		] );
		return true;
	}

	/**
	 * Titel/Beschreibung ändern — nur solange die Umfrage 'open' ist. Optionen
	 * werden bewusst NICHT nachträglich geändert (würde bereits abgegebene
	 * Stimmen invalidieren) — eine neue Umfrage ist der vorgesehene Weg.
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$poll = self::get( $id );
		if ( ! $poll ) {
			return new WP_Error( 'pp_not_found', __( 'Poll not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( 'open' !== $poll->status ) {
			return new WP_Error( 'pp_poll_closed', __( 'This poll is already closed.', 'project-prepper' ), [ 'status' => 409 ] );
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
			$wpdb->update( Schema::table( 'project_polls' ), $fields, [ 'id' => $id ] );
			ActivityLog::log( 'poll_updated', 'project', $poll->project_id, [
				'poll_id' => $id,
				'fields'  => array_keys( $fields ),
			] );
		}
		return true;
	}

	/**
	 * Umfrage schließen (open → closed). Nur der Ersteller oder ein Admin.
	 *
	 * @return true|WP_Error
	 */
	public static function close( int $id, int $user_id ) {
		return self::set_status( $id, $user_id, 'closed' );
	}

	/**
	 * Umfrage wieder öffnen (closed → open). Nur der Ersteller oder ein Admin.
	 *
	 * @return true|WP_Error
	 */
	public static function reopen( int $id, int $user_id ) {
		return self::set_status( $id, $user_id, 'open' );
	}

	/**
	 * Umfrage + Optionen + Stimmen löschen. Nur der Ersteller oder ein Admin.
	 *
	 * @return true|WP_Error
	 */
	public static function delete( int $id, int $user_id ) {
		global $wpdb;

		$poll = self::get( $id );
		if ( ! $poll ) {
			return new WP_Error( 'pp_not_found', __( 'Poll not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( ! self::user_may_manage( $poll, $user_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only the author or an admin may delete this poll.', 'project-prepper' ), [ 'status' => 403 ] );
		}

		$option_ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM %i WHERE poll_id = %d',
			Schema::table( 'project_poll_options' ),
			$id
		) );
		foreach ( array_map( 'intval', $option_ids ) as $option_id ) {
			$wpdb->delete( Schema::table( 'project_poll_votes' ), [ 'option_id' => $option_id ], [ '%d' ] );
		}
		$wpdb->delete( Schema::table( 'project_poll_options' ), [ 'poll_id' => $id ], [ '%d' ] );
		$wpdb->delete( Schema::table( 'project_polls' ), [ 'id' => $id ], [ '%d' ] );
		ActivityLog::log( 'poll_deleted', 'project', $poll->project_id, [ 'poll_id' => $id ] );
		return true;
	}

	/* ===================== Intern ===================== */

	/**
	 * Status setzen (close/reopen). Nur Ersteller/Admin.
	 *
	 * @return true|WP_Error
	 */
	private static function set_status( int $id, int $user_id, string $status ) {
		global $wpdb;

		$poll = self::get( $id );
		if ( ! $poll ) {
			return new WP_Error( 'pp_not_found', __( 'Poll not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( ! self::user_may_manage( $poll, $user_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only the author or an admin may change this poll.', 'project-prepper' ), [ 'status' => 403 ] );
		}

		$wpdb->update(
			Schema::table( 'project_polls' ),
			[ 'status' => $status, 'closed_at' => 'closed' === $status ? current_time( 'mysql' ) : null ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		ActivityLog::log( 'closed' === $status ? 'poll_closed' : 'poll_reopened', 'project', $poll->project_id, [ 'poll_id' => $id ] );
		return true;
	}

	/**
	 * Optionen einer Umfrage, je Option mit Tally {yes,no,maybe}. Sortierung:
	 * date → option_date/option_time, sonst sort_order.
	 *
	 * @return array<object>
	 */
	private static function options( int $poll_id, string $poll_type ): array {
		global $wpdb;
		$order = 'date' === $poll_type
			? 'option_date ASC, option_time ASC, id ASC'
			: 'sort_order ASC, id ASC';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, label, option_date, option_time, sort_order FROM %i WHERE poll_id = %d ORDER BY {$order}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $order is a fixed internal whitelist, not user input.
			Schema::table( 'project_poll_options' ),
			$poll_id
		) ) ?: [];
		foreach ( $rows as $r ) {
			$r->id         = (int) $r->id;
			$r->sort_order = (int) $r->sort_order;
			$tally         = self::tally( $r->id );
			$r->yes        = $tally['yes'];
			$r->no         = $tally['no'];
			$r->maybe      = $tally['maybe'];
		}
		return $rows;
	}

	/**
	 * Stimm-Zähler einer Option.
	 *
	 * @return array{yes:int,no:int,maybe:int}
	 */
	private static function tally( int $option_id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				SUM(vote = 'yes') AS yes,
				SUM(vote = 'no') AS no,
				SUM(vote = 'maybe') AS maybe
			 FROM %i WHERE option_id = %d",
			Schema::table( 'project_poll_votes' ),
			$option_id
		) );
		return [
			'yes'   => $row ? (int) $row->yes : 0,
			'no'    => $row ? (int) $row->no : 0,
			'maybe' => $row ? (int) $row->maybe : 0,
		];
	}

	/**
	 * Die Stimmen des aktuellen Users über alle Optionen einer Umfrage:
	 * option_id → vote.
	 *
	 * @return object  Map option_id(string) → vote.
	 */
	private static function my_votes( int $poll_id, int $user_id ): object {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT v.option_id, v.vote FROM %i v
			 INNER JOIN %i o ON o.id = v.option_id
			 WHERE o.poll_id = %d AND v.user_id = %d',
			Schema::table( 'project_poll_votes' ),
			Schema::table( 'project_poll_options' ),
			$poll_id,
			$user_id
		) ) ?: [];
		$map = new \stdClass();
		foreach ( $rows as $r ) {
			$map->{(string) (int) $r->option_id} = $r->vote;
		}
		return $map;
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
	 * Stimmberechtigte Gruppe einer Umfrage: direkt gebundene group_id (gruppen-
	 * weite Umfrage, v0.21.0) ODER die Eigentümer-Gruppe des Projekts.
	 */
	private static function owning_group( object $poll ): int {
		$gid = (int) ( $poll->group_id ?? 0 );
		return $gid ? $gid : self::project_group_id( (int) ( $poll->project_id ?? 0 ) );
	}

	/**
	 * Darf $user_id die Umfrage verwalten (schließen/öffnen/löschen)? Ersteller
	 * ODER Admin (Groups::user_is_admin).
	 */
	private static function user_may_manage( object $poll, int $user_id ): bool {
		if ( $user_id && (int) $poll->created_by === $user_id ) {
			return true;
		}
		return Groups::user_is_admin( $user_id );
	}
}
