<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Anfragen im Member-Portal (docs/06 §10.1).
 *
 * Jede Anfrage gehört genau EINEM Arbeitsbereich: Solo (owner_user_id) ODER
 * Gruppe (owner_group_id). Mitglieder pflegen ihre Anfragen vorne (Buchhaltung);
 * der Betreiber sieht im Backend nur Aggregate. Der Lifecycle Anfrage→Projekt
 * folgt in einem weiteren Slice. Diese Schicht prüft Eigentum selbst (RLS-Ersatz).
 */
class MemberInquiries {

	/** Anfragen des aktiven Arbeitsbereichs (Solo: eigene; Gruppe: der Gruppe). */
	public static function for_owner( int $user_id, int $group_id ): array {
		global $wpdb;
		$t = Schema::table( 'inquiries' );
		if ( $group_id > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE owner_group_id = %d ORDER BY id DESC', $t, $group_id ) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE owner_user_id = %d AND owner_group_id IS NULL ORDER BY id DESC', $t, $user_id ) );
		}
		return array_map( [ self::class, 'decode' ], $rows ?: [] );
	}

	public static function count_for_owner( int $user_id, int $group_id ): int {
		global $wpdb;
		$t = Schema::table( 'inquiries' );
		if ( $group_id > 0 ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE owner_group_id = %d', $t, $group_id ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE owner_user_id = %d AND owner_group_id IS NULL', $t, $user_id ) );
	}

	public static function owns( object $inq, int $user_id, int $group_id ): bool {
		if ( $group_id > 0 ) {
			return (int) ( $inq->owner_group_id ?? 0 ) === $group_id;
		}
		return (int) ( $inq->owner_user_id ?? 0 ) === $user_id && empty( $inq->owner_group_id );
	}

	public static function get_owned( int $id, int $user_id, int $group_id ): ?object {
		$row = Inquiries::get( $id );
		return ( $row && self::owns( $row, $user_id, $group_id ) ) ? $row : null;
	}

	/**
	 * Pipeline-Felder (App-Parität inquiries): sanitisierte Spaltenwerte aus dem
	 * Formular-Input — Geldwerte leer→NULL, Wahrscheinlichkeit auf 0–100 geklemmt.
	 *
	 * @return array<string,mixed>
	 */
	private static function fields( array $data ): array {
		$money = static function ( $v ) {
			return ( null === $v || '' === $v ) ? null : round( (float) $v, 2 );
		};
		$prob = null;
		if ( isset( $data['probability'] ) && '' !== (string) $data['probability'] ) {
			$prob = max( 0, min( 100, (int) $data['probability'] ) );
		}
		return [
			'title'            => (string) ( $data['title'] ?? '' ),
			'contact_person'   => (string) ( $data['contact_person'] ?? '' ),
			'email'            => (string) ( $data['email'] ?? '' ),
			'phone'            => (string) ( $data['phone'] ?? '' ),
			'message'          => (string) ( $data['message'] ?? '' ),
			'venue_name'       => (string) ( $data['venue_name'] ?? '' ),
			'date_from'        => ! empty( $data['date_from'] ) ? $data['date_from'] : null,
			'date_to'          => ! empty( $data['date_to'] ) ? $data['date_to'] : null,
			'estimated_budget' => $money( $data['estimated_budget'] ?? null ),
			'offer_amount'     => $money( $data['offer_amount'] ?? null ),
			'probability'      => $prob,
			'follow_up'        => ! empty( $data['follow_up'] ) ? $data['follow_up'] : null,
		];
	}

	/**
	 * Neue Anfrage im aktiven Arbeitsbereich anlegen.
	 *
	 * @return int|WP_Error
	 */
	public static function create( int $user_id, int $group_id, array $data ) {
		if ( ! $user_id || ! current_user_can( Capabilities::COLLECTIVES ) ) {
			return new WP_Error( 'pp_forbidden', __( 'You are not allowed to add inquiries.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'pp_missing_name', __( 'Please enter a client name for the inquiry.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert( Schema::table( 'inquiries' ), array_merge( self::fields( $data ), [
			'name'           => $name,
			'items'          => wp_json_encode( [] ),
			'status'         => 'new',
			'owner_user_id'  => $group_id > 0 ? null : $user_id,
			'owner_group_id' => $group_id > 0 ? $group_id : null,
			'created_at'     => $now,
			'updated_at'     => $now,
		] ) );
		$id = (int) $wpdb->insert_id;
		ActivityLog::log( 'inquiry_created', 'inquiry', $id, [ 'name' => $name, 'owner_group' => $group_id ] );
		return $id;
	}

	/**
	 * Editierbare Felder einer eigenen Anfrage ändern (nicht den Status — dafür
	 * set_status mit Übergangs-Guard).
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, int $user_id, int $group_id, array $data ) {
		if ( ! self::get_owned( $id, $user_id, $group_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This inquiry is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'pp_missing_name', __( 'Please enter a client name for the inquiry.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		global $wpdb;
		$wpdb->update(
			Schema::table( 'inquiries' ),
			array_merge( self::fields( $data ), [
				'name'       => $name,
				'updated_at' => current_time( 'mysql' ),
			] ),
			[ 'id' => $id ],
			null,
			[ '%d' ]
		);
		return true;
	}

	/** Status setzen (mit Übergangs-Guard aus Inquiries::TRANSITIONS). */
	public static function set_status( int $id, int $user_id, int $group_id, string $status ) {
		if ( ! self::get_owned( $id, $user_id, $group_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This inquiry is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		return Inquiries::set_status( $id, $status );
	}

	public static function delete( int $id, int $user_id, int $group_id ) {
		if ( ! self::get_owned( $id, $user_id, $group_id ) ) {
			return new WP_Error( 'pp_forbidden', __( 'This inquiry is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		return Inquiries::delete( $id );
	}

	/**
	 * Lifecycle Anfrage→Projekt (docs/06 §10.1): aus einer Gruppen-Anfrage ein
	 * Gruppen-Projekt erzeugen und die Anfrage auf „won" setzen. Nur im
	 * Gruppen-Arbeitsbereich (Solo hat kein Projekt-Modell → Buchhaltung bleibt).
	 *
	 * @return int|WP_Error Neue Projekt-ID.
	 */
	public static function to_project( int $id, int $user_id, int $group_id ) {
		if ( $group_id <= 0 ) {
			return new WP_Error( 'pp_solo_no_project', __( 'Solo inquiries cannot become a project — switch to a group workspace.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$inq = self::get_owned( $id, $user_id, $group_id );
		if ( ! $inq ) {
			return new WP_Error( 'pp_forbidden', __( 'This inquiry is not yours.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		if ( in_array( $inq->status, [ 'won', 'lost', 'closed' ], true ) ) {
			return new WP_Error( 'pp_already_closed', __( 'This inquiry is already closed.', 'project-prepper' ), [ 'status' => 409 ] );
		}
		// Budget-Mapping wie die App (inquiries/[id] handleCreateProject):
		// estimated_budget → budget_planned (Fallback offer_amount),
		// offer_amount → revenue_actual.
		$budget  = $inq->estimated_budget ?? $inq->offer_amount ?? null;
		$revenue = $inq->offer_amount ?? null;
		$title   = trim( (string) ( $inq->title ?? '' ) );

		$project_id = Projects::create( [
			'name'           => '' !== $title ? $title : $inq->name,
			'status'         => 'planned',
			'date_start'     => $inq->date_from,
			'date_end'       => $inq->date_to,
			'venue_name'     => (string) ( $inq->venue_name ?? '' ),
			'client_name'    => $inq->name,
			'client_email'   => $inq->email,
			'client_phone'   => $inq->phone,
			'notes'          => $inq->message,
			'budget_planned' => null !== $budget ? (string) $budget : '',
			'revenue_actual' => null !== $revenue ? (string) $revenue : '',
			'owner_group_id' => $group_id,
		] );
		if ( is_wp_error( $project_id ) ) {
			return $project_id;
		}
		Inquiries::set_status( $id, 'won' );
		// Projekt-Verknüpfung merken — das Detail zeigt dann „Zum Projekt".
		global $wpdb;
		$wpdb->update(
			Schema::table( 'inquiries' ),
			[ 'project_id' => (int) $project_id ],
			[ 'id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);
		ActivityLog::log( 'inquiry_to_project', 'inquiry', $id, [ 'project_id' => $project_id ] );
		return $project_id;
	}

	private static function decode( object $row ): object {
		$row->items = json_decode( $row->items ?? '[]', true ) ?: [];
		return $row;
	}
}
