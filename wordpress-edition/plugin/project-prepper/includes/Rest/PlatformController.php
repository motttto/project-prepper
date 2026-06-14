<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Schema;
use ProjectPrepper\Services\Groups;
use ProjectPrepper\Services\GroupGovernance;
use ProjectPrepper\Services\Borrowing;
use ProjectPrepper\Services\ActivityLog;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Plattform-Übersicht fürs Backend (Member-Portal-Systemprozesse). Read-only:
 * KPIs, offene Beitritts-Einladungen, jüngste Leih-Anfragen, Aktivität und
 * Kollektive. Ersetzt die frühere server-gerenderte Seite (admin.js holt es).
 */
class PlatformController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/platform', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'show' ],
			'permission_callback' => $this->require_cap( Capabilities::OPERATE ),
		] );
	}

	public function show(): WP_REST_Response {
		$groups  = Groups::all();
		$invites = GroupGovernance::all_pending();
		$borrows = Borrowing::all_recent( 30 );
		$overdue = $this->overdue_loans();

		// „Braucht Aufmerksamkeit" — die aktionablen/auflauenden Posten des Betreibers.
		$unreachable = 0;
		foreach ( \ProjectPrepper\Federation::directory() as $entry ) {
			if ( empty( $entry['profile'] ) ) {
				$unreachable++;
			}
		}

		return new WP_REST_Response( [
			'attention' => [
				'open_votings'         => count( array_filter( $invites, static fn( $i ) => 'voting' === $i->status ) ),
				'open_requests'        => $this->count( 'borrow_requests', "status = 'requested'" ),
				'overdue'              => count( $overdue ),
				'fed_incoming'         => $this->count( 'fed_borrow_in', "status = 'requested'" ),
				'partners_unreachable' => $unreachable,
			],
			'overdue' => $overdue,
			'kpis' => [
				'collectives'    => count( $groups ),
				'member_items'   => $this->count( 'items', 'owner_user_id IS NOT NULL' ),
				'open_invites'   => count( $invites ),
				'active_loans'   => $this->count( 'borrow_requests', "status = 'approved'" ),
				'open_requests'  => $this->count( 'borrow_requests', "status = 'requested'" ),
			],
			'invitations' => array_map( static function ( $inv ) {
				return [
					'group'     => $inv->group_name,
					'email'     => $inv->invited_email,
					'status'    => $inv->status,
					'approvals' => 'voting' === $inv->status ? ( $inv->approvals . ' / ' . $inv->needed ) : '',
				];
			}, $invites ),
			'borrows' => array_map( static function ( $b ) {
				return [
					'item'      => $b->item_name,
					'owner'     => $b->owner_name,
					'borrower'  => $b->requester_name,
					'period'    => $b->date_from . ' – ' . $b->date_to,
					'status'    => $b->status,
				];
			}, $borrows ),
			'activity' => array_map( static function ( $row ) {
				$actor = $row->actor_id ? get_userdata( (int) $row->actor_id ) : null;
				return [
					'when'   => mysql2date( 'd.m.Y H:i', $row->created_at ),
					'who'    => $actor ? $actor->display_name : __( 'System', 'project-prepper' ),
					'action' => $row->action,
				];
			}, ActivityLog::recent( 20 ) ),
			'collectives' => array_map( static function ( $g ) {
				return [
					'name'    => $g->name,
					'members' => (int) $g->member_count,
				];
			}, $groups ),
		] );
	}

	/**
	 * Überfällige Ausleihen: genehmigt, Rückgabedatum in der Vergangenheit, noch
	 * nicht zurückgegeben. Für die Betreiber-Aufsicht (read-only).
	 *
	 * @return array<array<string,string>>
	 */
	private function overdue_loans(): array {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.item_id, b.owner_id, b.requester_id, b.date_to, i.name AS item_name
			 FROM %i b JOIN %i i ON i.id = b.item_id
			 WHERE b.status = 'approved' AND b.date_to IS NOT NULL AND b.date_to < %s
			 ORDER BY b.date_to ASC LIMIT 50",
			Schema::table( 'borrow_requests' ),
			Schema::table( 'items' ),
			$today
		) ) ?: [];
		$out = [];
		foreach ( $rows as $r ) {
			$owner    = get_userdata( (int) $r->owner_id );
			$borrower = get_userdata( (int) $r->requester_id );
			$out[] = [
				'item'     => (string) $r->item_name,
				'owner'    => $owner ? $owner->display_name : '',
				'borrower' => $borrower ? $borrower->display_name : '',
				'due'      => mysql2date( 'd.m.Y', (string) $r->date_to ),
			];
		}
		return $out;
	}

	private function count( string $table, string $where = '' ): int {
		global $wpdb;
		// $where ist ein statischer, im Code definierter String (kein User-Input).
		$sql = 'SELECT COUNT(*) FROM %i' . ( '' !== $where ? ' WHERE ' . $where : '' );
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, Schema::table( $table ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
