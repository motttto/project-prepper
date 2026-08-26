<?php
namespace ProjectPrepper\Email;

use ProjectPrepper\Services\Rentals;
use ProjectPrepper\Services\Groups;
use ProjectPrepper\Services\GroupGovernance;
use ProjectPrepper\Services\Borrowing;
use ProjectPrepper\Services\BookingApprovals;
use ProjectPrepper\Services\Inventory;
use ProjectPrepper\Frontend\MemberPortal;

defined( 'ABSPATH' ) || exit;

/**
 * E-Mail-Benachrichtigungen (§16) — wp_mail + editierbare Templates mit {{vars}}.
 *
 * Templates liegen als Option pp_email_templates; Defaults englisch, übersetzbar via __().
 * Abschaltbar über Option pp_email_notifications.
 */
class Notifications {

	const OPTION_ENABLED   = 'pp_email_notifications';
	const OPTION_TEMPLATES = 'pp_email_templates';

	public static function init(): void {
		add_action( 'pp_rental_status_changed', [ self::class, 'on_rental_status_changed' ], 10, 3 );
		add_action( 'pp_rental_created', [ self::class, 'on_rental_created' ], 10, 1 );
		add_action( 'pp_inquiry_created', [ self::class, 'on_inquiry_created' ], 10, 1 );
		// Member-Portal (Phase 4.1): Kollektiv-Einladung + Leih-Anfragen.
		add_action( 'pp_group_invited', [ self::class, 'on_group_invited' ], 10, 1 );
		// Voting-Phase: Erinnerung an die noch nicht abstimmenden Mitglieder.
		add_action( 'pp_group_voting_reminder', [ self::class, 'on_voting_reminder' ], 10, 2 );
		add_action( 'pp_borrow_requested', [ self::class, 'on_borrow_requested' ], 10, 1 );
		add_action( 'pp_borrow_decided', [ self::class, 'on_borrow_decided' ], 10, 2 );
		// Freigabe-Workflow für Technik-Buchungen (an Eigentümer / an Anfrager).
		add_action( 'pp_booking_approval_requested', [ self::class, 'on_booking_requested' ], 10, 3 );
		add_action( 'pp_booking_approval_decided', [ self::class, 'on_booking_decided' ], 10, 1 );
		// Sammel-Varianten: EIN Buchungsvorgang bzw. EIN Entscheidungs-Schwung =
		// EINE Mail mit allen Positionen (statt einer Mail pro Gerät).
		add_action( 'pp_booking_approvals_requested', [ self::class, 'on_booking_requested_batch' ], 10, 3 );
		add_action( 'pp_booking_approvals_decided', [ self::class, 'on_booking_decided_batch' ], 10, 2 );
	}

	public static function default_templates(): array {
		return [
			'rental_reserved' => [
				/* translators: Email subject. Keep the {{rental_number}} and {{site_name}} placeholders unchanged. */
				'subject' => __( 'Reservation {{rental_number}} — {{site_name}}', 'project-prepper' ),
				/* translators: Email body. Keep all {{…}} placeholders unchanged. */
				'body'    => __( "Hello {{borrower_name}},\n\nyour reservation {{rental_number}} has been recorded:\n\nPeriod: {{date_from}} to {{date_to}}\n\nItems:\n{{items}}\n\nBest regards\n{{site_name}}", 'project-prepper' ),
			],
			'rental_active'   => [
				/* translators: Email subject. Keep the {{rental_number}} placeholder unchanged. */
				'subject' => __( 'Equipment handed out — {{rental_number}}', 'project-prepper' ),
				/* translators: Email body. Keep all {{…}} placeholders unchanged. */
				'body'    => __( "Hello {{borrower_name}},\n\nthe equipment for {{rental_number}} has been handed out.\n\nReturn by: {{date_to}}\n\nItems:\n{{items}}\n\nBest regards\n{{site_name}}", 'project-prepper' ),
			],
			'rental_returned' => [
				/* translators: Email subject. Keep the {{rental_number}} placeholder unchanged. */
				'subject' => __( 'Return confirmed — {{rental_number}}', 'project-prepper' ),
				/* translators: Email body. Keep all {{…}} placeholders unchanged. */
				'body'    => __( "Hello {{borrower_name}},\n\nthe return for {{rental_number}} has been confirmed. Thank you!\n\nBest regards\n{{site_name}}", 'project-prepper' ),
			],
			'inquiry_received' => [
				/* translators: Email subject. Keep the {{name}} and {{site_name}} placeholders unchanged. */
				'subject' => __( 'New inquiry from {{name}} — {{site_name}}', 'project-prepper' ),
				/* translators: Email body. Keep all {{…}} placeholders unchanged. */
				'body'    => __( "New inquiry via the website:\n\nName: {{name}}\nEmail: {{email}}\nPhone: {{phone}}\nPeriod: {{date_from}} to {{date_to}}\n\nRequested equipment:\n{{items}}\n\nMessage:\n{{message}}\n\n→ Manage: {{admin_url}}", 'project-prepper' ),
			],
			'group_invitation' => [
				/* translators: Email subject. Keep the {{group_name}} and {{site_name}} placeholders unchanged. */
				'subject' => __( 'You have been invited to {{group_name}} — {{site_name}}', 'project-prepper' ),
				/* translators: Email body. Keep all {{…}} placeholders unchanged. {{message}} expands to the optional personal note (or nothing). */
				'body'    => __( "Hello,\n\n{{inviter_name}} has invited you to join the collective \"{{group_name}}\".{{message}}\n\nCreate your account (or sign in) here to accept the invitation:\n{{join_url}}\n\nBest regards\n{{site_name}}", 'project-prepper' ),
			],
			'group_vote_reminder' => [
				/* translators: Email subject. Keep the {{group_name}} and {{site_name}} placeholders unchanged. */
				'subject' => __( 'Reminder: please vote on a new member for {{group_name}} — {{site_name}}', 'project-prepper' ),
				/* translators: Email body. Keep all {{…}} placeholders unchanged. */
				'body'    => __( "Hello,\n\na new member (\"{{invitee_name}}\") is waiting to join the collective \"{{group_name}}\" and your vote is still missing.\n\nCast your vote here:\n{{portal_url}}\n\nBest regards\n{{site_name}}", 'project-prepper' ),
			],
			'borrow_requested' => [
				/* translators: Email subject. Keep the {{item_name}} placeholder unchanged. */
				'subject' => __( 'Borrow request for {{item_name}} — {{site_name}}', 'project-prepper' ),
				/* translators: Email body. Keep all {{…}} placeholders unchanged. */
				'body'    => __( "Hello,\n\n{{requester_name}} would like to borrow your item \"{{item_name}}\" ({{date_from}} to {{date_to}}).\n\nMessage:\n{{message}}\n\nReview the request:\n{{portal_url}}\n\nBest regards\n{{site_name}}", 'project-prepper' ),
			],
			'borrow_decided' => [
				/* translators: Email subject. Keep the {{item_name}} and {{status}} placeholders unchanged. */
				'subject' => __( 'Your borrow request for {{item_name}} was {{status}} — {{site_name}}', 'project-prepper' ),
				/* translators: Email body. Keep all {{…}} placeholders unchanged. */
				'body'    => __( "Hello,\n\nyour request to borrow \"{{item_name}}\" ({{date_from}} to {{date_to}}) was {{status}}.\n\nDetails:\n{{portal_url}}\n\nBest regards\n{{site_name}}", 'project-prepper' ),
			],
			'booking_requested' => [
				/* translators: Email subject. Keep the {{item_name}} and {{site_name}} placeholders unchanged. */
				'subject' => __( 'Approval needed for {{item_name}} — {{site_name}}', 'project-prepper' ),
				/* translators: Email body. Keep all {{…}} placeholders unchanged. */
				'body'    => __( "Hello,\n\n{{requester_name}} would like to use your equipment \"{{item_name}}\" ({{quantity}}×, {{date_from}} to {{date_to}}) for the project \"{{project_name}}\".\n\nApprove it on your own terms in the portal:\n{{portal_url}}\n\nBest regards\n{{site_name}}", 'project-prepper' ),
			],
			'booking_decided' => [
				/* translators: Email subject. Keep the {{item_name}}, {{status}} and {{site_name}} placeholders unchanged. */
				'subject' => __( 'Your booking of {{item_name}} was {{status}} — {{site_name}}', 'project-prepper' ),
				/* translators: Email body. Keep all {{…}} placeholders unchanged. */
				'body'    => __( "Hello,\n\nyour booking of \"{{item_name}}\" for the project \"{{project_name}}\" was {{status}}.\n\n{{portal_url}}\n\nBest regards\n{{site_name}}", 'project-prepper' ),
			],
			'booking_requested_list' => [
				/* translators: Email subject. Keep the {{count}} and {{site_name}} placeholders unchanged. */
				'subject' => __( 'Approval needed: {{count}} bookings — {{site_name}}', 'project-prepper' ),
				/* translators: Email body. Keep all {{…}} placeholders unchanged. */
				'body'    => __( "Hello,\n\n{{requester_name}} would like to use your equipment for the project \"{{project_name}}\":\n\n{{items}}\n\nApprove or reject each request on your own terms in the portal:\n{{portal_url}}\n\nBest regards\n{{site_name}}", 'project-prepper' ),
			],
			'booking_decided_list' => [
				/* translators: Email subject. Keep the {{site_name}} placeholder unchanged. */
				'subject' => __( 'Decisions on your equipment bookings — {{site_name}}', 'project-prepper' ),
				/* translators: Email body. Keep all {{…}} placeholders unchanged. */
				'body'    => __( "Hello,\n\nthe owner has decided on your booking requests:\n\n{{items}}\n\nDetails:\n{{portal_url}}\n\nBest regards\n{{site_name}}", 'project-prepper' ),
			],
			'member_2fa_code' => [
				/* translators: Email subject. Keep the {{site_name}} placeholder unchanged. */
				'subject' => __( 'Your login code — {{site_name}}', 'project-prepper' ),
				/* translators: Email body. Keep all {{…}} placeholders unchanged. */
				'body'    => __( "Your one-time login code is: {{code}}\n\nIt is valid for {{minutes}} minutes. If you did not try to sign in, you can ignore this email.", 'project-prepper' ),
			],
		];
	}

	public static function templates(): array {
		$saved = get_option( self::OPTION_TEMPLATES, [] );
		return array_replace_recursive( self::default_templates(), is_array( $saved ) ? $saved : [] );
	}

	/** Menschenlesbare Labels je Template-Schlüssel (für die Backend-Seite). */
	public static function template_labels(): array {
		return [
			'rental_reserved'  => __( 'Rental: reservation confirmed', 'project-prepper' ),
			'rental_active'    => __( 'Rental: equipment handed out', 'project-prepper' ),
			'rental_returned'  => __( 'Rental: return confirmed', 'project-prepper' ),
			'inquiry_received' => __( 'Inquiry received (operator)', 'project-prepper' ),
			'group_invitation' => __( 'Group invitation', 'project-prepper' ),
			'group_vote_reminder' => __( 'Group: reminder to vote on a new member', 'project-prepper' ),
			'borrow_requested' => __( 'Borrow request (to owner)', 'project-prepper' ),
			'borrow_decided'   => __( 'Borrow decision (to requester)', 'project-prepper' ),
			'booking_requested' => __( 'Equipment approval request (to owner)', 'project-prepper' ),
			'booking_decided'   => __( 'Equipment approval decision (to requester)', 'project-prepper' ),
			'booking_requested_list' => __( 'Equipment approval request — several items in one email (to owner)', 'project-prepper' ),
			'booking_decided_list'   => __( 'Equipment approval decisions — several in one email (to requester)', 'project-prepper' ),
			'member_2fa_code'  => __( 'Member login code (2FA)', 'project-prepper' ),
		];
	}

	/**
	 * Katalog für die E-Mail-Templates-Seite: pro Template Label + die verfügbaren
	 * {{platzhalter}} (automatisch aus dem Default-Subject/-Body extrahiert, immer
	 * korrekt). Reihenfolge wie default_templates().
	 *
	 * @return array<array{key:string,label:string,vars:string[]}>
	 */
	public static function catalog(): array {
		$labels = self::template_labels();
		$out    = [];
		foreach ( self::default_templates() as $key => $tpl ) {
			preg_match_all( '/\{\{(\w+)\}\}/', (string) $tpl['subject'] . ' ' . (string) $tpl['body'], $m );
			$out[] = [
				'key'   => $key,
				'label' => $labels[ $key ] ?? $key,
				'vars'  => array_values( array_unique( $m[1] ) ),
			];
		}
		return $out;
	}

	/**
	 * Templates aus einem Eingabe-Array (key => {subject, body}) säubern + speichern.
	 * Nur bekannte Schlüssel; pro Feld einzeln sanitisiert. Gibt die Werte zurück.
	 *
	 * @return array Die effektiven Templates (= templates()).
	 */
	public static function save_templates( array $input ): array {
		$clean = [];
		foreach ( self::default_templates() as $key => $default ) {
			if ( isset( $input[ $key ] ) && is_array( $input[ $key ] ) ) {
				$clean[ $key ] = [
					'subject' => sanitize_text_field( (string) ( $input[ $key ]['subject'] ?? $default['subject'] ) ),
					'body'    => sanitize_textarea_field( (string) ( $input[ $key ]['body'] ?? $default['body'] ) ),
				];
			}
		}
		update_option( self::OPTION_TEMPLATES, $clean );
		return self::templates();
	}

	public static function enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, true );
	}

	public static function render( string $template, array $vars ): string {
		foreach ( $vars as $key => $value ) {
			$template = str_replace( '{{' . $key . '}}', (string) $value, $template );
		}
		// Nicht ersetzte Platzhalter entfernen, damit kein rohes {{foo}} in der Mail landet.
		$template = preg_replace( '/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/', '', $template );
		return (string) $template;
	}

	public static function on_rental_created( int $rental_id ): void {
		self::send_for_rental( $rental_id, 'rental_reserved' );
	}

	public static function on_inquiry_created( int $inquiry_id ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$inquiry = \ProjectPrepper\Services\Inquiries::get( $inquiry_id );
		if ( ! $inquiry ) {
			return;
		}
		$template = self::templates()['inquiry_received'];

		$item_lines = array_map( static function ( $line ) {
			return sprintf( '- %s× %s', $line['quantity'] ?? 1, $line['name'] ?? ( '#' . ( $line['item_id'] ?? '?' ) ) );
		}, $inquiry->items );

		$vars = [
			'name'      => $inquiry->name,
			'email'     => $inquiry->email ?: '—',
			'phone'     => $inquiry->phone ?: '—',
			'date_from' => $inquiry->date_from ? mysql2date( 'd.m.Y', $inquiry->date_from ) : '—',
			'date_to'   => $inquiry->date_to ? mysql2date( 'd.m.Y', $inquiry->date_to ) : '—',
			'items'     => $item_lines ? implode( "\n", $item_lines ) : '—',
			'message'   => $inquiry->message ?: '—',
			'site_name' => get_bloginfo( 'name' ),
			'admin_url' => admin_url( 'admin.php?page=pp-manage&tab=inquiries' ),
		];

		wp_mail(
			get_option( 'admin_email' ),
			self::render( $template['subject'], $vars ),
			self::render( $template['body'], $vars )
		);
	}

	/* ---------- Member-Portal (Phase 4.1) ---------- */

	public static function on_group_invited( int $invitation_id ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$inv = GroupGovernance::get( $invitation_id );
		if ( ! $inv || empty( $inv->invited_email ) || ! is_email( $inv->invited_email ) ) {
			return;
		}
		$group   = Groups::get( (int) $inv->group_id );
		$inviter = $inv->invited_by ? get_userdata( (int) $inv->invited_by ) : null;
		$tpl     = self::templates()['group_invitation'];
		// Optionale Nachricht als eigener Block (nur wenn gesetzt) — so bleibt die
		// Mail sauber, wenn keine Nachricht hinterlegt wurde.
		$note = trim( (string) ( $inv->message ?? '' ) );
		$vars = [
			'group_name'   => $group ? $group->name : '',
			'inviter_name' => $inviter ? $inviter->display_name : get_bloginfo( 'name' ),
			'message'      => '' !== $note ? "\n\n" . __( 'Personal note:', 'project-prepper' ) . "\n" . $note : '',
			'portal_url'   => MemberPortal::portal_url(),
			// Persönlicher Beitritts-Link: öffnet im Portal die Registrierung mit
			// vorausgefüllter (per Token verifizierter) E-Mail-Adresse.
			'join_url'     => add_query_arg(
				[ 'pp_join' => (int) $inv->id, 'pp_key' => GroupGovernance::invite_token( $inv ) ],
				MemberPortal::portal_url()
			),
			'site_name'    => get_bloginfo( 'name' ),
		];
		wp_mail( $inv->invited_email, self::render( $tpl['subject'], $vars ), self::render( $tpl['body'], $vars ) );
	}

	/**
	 * Voting-Erinnerung: benachrichtigt die aktiven Mitglieder, die noch nicht
	 * über eine laufende Beitritts-Einladung abgestimmt haben. Eine Mail je
	 * Empfänger (mit gültiger Adresse). Ohne SMTP scheitert wp_mail still.
	 *
	 * @param int   $invitation_id Einladung in der Voting-Phase.
	 * @param int[] $recipient_ids Aktive Mitglieder, deren Stimme noch fehlt.
	 */
	public static function on_voting_reminder( int $invitation_id, array $recipient_ids ): void {
		if ( ! self::enabled() || ! $recipient_ids ) {
			return;
		}
		$inv = GroupGovernance::get( $invitation_id );
		if ( ! $inv ) {
			return;
		}
		$group   = Groups::get( (int) $inv->group_id );
		$invitee = $inv->invited_user_id ? get_userdata( (int) $inv->invited_user_id ) : null;
		$tpl     = self::templates()['group_vote_reminder'];
		$vars    = [
			'group_name'   => $group ? $group->name : '',
			'invitee_name' => $invitee ? $invitee->display_name : (string) $inv->invited_email,
			'portal_url'   => add_query_arg( 'pp_view', 'collectives', MemberPortal::portal_url() ),
			'site_name'    => get_bloginfo( 'name' ),
		];
		$subject = self::render( $tpl['subject'], $vars );
		$body    = self::render( $tpl['body'], $vars );
		foreach ( array_unique( array_map( 'intval', $recipient_ids ) ) as $uid ) {
			$user = get_userdata( $uid );
			if ( $user && is_email( $user->user_email ) ) {
				wp_mail( $user->user_email, $subject, $body );
			}
		}
	}

	public static function on_borrow_requested( int $request_id ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$req = Borrowing::get( $request_id );
		if ( ! $req || ! $req->owner_id ) {
			return;
		}
		$owner = get_userdata( (int) $req->owner_id );
		if ( ! $owner || ! is_email( $owner->user_email ) ) {
			return;
		}
		$item      = Inventory::get_item( (int) $req->item_id );
		$requester = get_userdata( (int) $req->requester_id );
		$tpl       = self::templates()['borrow_requested'];
		$vars      = [
			'item_name'      => $item ? $item->name : '',
			'requester_name' => $requester ? $requester->display_name : '',
			'date_from'      => $req->date_from ? mysql2date( 'd.m.Y', $req->date_from ) : '—',
			'date_to'        => $req->date_to ? mysql2date( 'd.m.Y', $req->date_to ) : '—',
			'message'        => trim( (string) $req->message ) !== '' ? $req->message : '—',
			'portal_url'     => MemberPortal::portal_url(),
			'site_name'      => get_bloginfo( 'name' ),
		];
		wp_mail( $owner->user_email, self::render( $tpl['subject'], $vars ), self::render( $tpl['body'], $vars ) );
	}

	public static function on_borrow_decided( int $request_id, string $status ): void {
		if ( ! self::enabled() || ! in_array( $status, [ 'approved', 'declined' ], true ) ) {
			return;
		}
		$req = Borrowing::get( $request_id );
		if ( ! $req ) {
			return;
		}
		$requester = get_userdata( (int) $req->requester_id );
		if ( ! $requester || ! is_email( $requester->user_email ) ) {
			return;
		}
		$item        = Inventory::get_item( (int) $req->item_id );
		$status_text = 'approved' === $status ? __( 'approved', 'project-prepper' ) : __( 'declined', 'project-prepper' );
		$tpl         = self::templates()['borrow_decided'];
		$vars        = [
			'item_name'  => $item ? $item->name : '',
			'status'     => $status_text,
			'date_from'  => $req->date_from ? mysql2date( 'd.m.Y', $req->date_from ) : '—',
			'date_to'    => $req->date_to ? mysql2date( 'd.m.Y', $req->date_to ) : '—',
			'portal_url' => MemberPortal::portal_url(),
			'site_name'  => get_bloginfo( 'name' ),
		];
		wp_mail( $requester->user_email, self::render( $tpl['subject'], $vars ), self::render( $tpl['body'], $vars ) );
	}

	/**
	 * Freigabe-Anfrage an den Eigentümer: ein Mitglied hat seinen Artikel für ein
	 * Projekt gebucht, das Freigabe verlangt. Portal-Link zur Freigaben-Ansicht
	 * (KEIN anonymer Zustimmen-Link — Freigabe passiert eingeloggt im Portal).
	 * Ohne SMTP scheitert wp_mail still — der Portal-Eintrag ist verbindlich.
	 */
	public static function on_booking_requested( int $line_id, int $owner_id, int $requester_id ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$owner = get_userdata( $owner_id );
		if ( ! $owner || ! is_email( $owner->user_email ) ) {
			return;
		}
		$ctx = BookingApprovals::get_line_context( $line_id );
		if ( ! $ctx ) {
			return;
		}
		$requester = get_userdata( $requester_id );
		$tpl       = self::templates()['booking_requested'];
		$vars      = [
			'item_name'      => (string) $ctx->item_name,
			'project_name'   => (string) $ctx->project_name,
			'requester_name' => $requester ? $requester->display_name : '',
			'quantity'       => (int) $ctx->quantity,
			'date_from'      => $ctx->date_from_eff ? mysql2date( 'd.m.Y', $ctx->date_from_eff ) : '—',
			'date_to'        => $ctx->date_to_eff ? mysql2date( 'd.m.Y', $ctx->date_to_eff ) : '—',
			'portal_url'     => add_query_arg( 'pp_view', 'approvals', MemberPortal::portal_url() ),
			'site_name'      => get_bloginfo( 'name' ),
		];
		wp_mail( $owner->user_email, self::render( $tpl['subject'], $vars ), self::render( $tpl['body'], $vars ) );
	}

	/**
	 * Sammel-Anfrage: EIN Buchungsvorgang hat mehrere freigabepflichtige Artikel
	 * DESSELBEN Eigentümers erzeugt → EINE Mail mit allen Positionen statt einer
	 * Mail pro Gerät (Feedback: „nicht für jedes Gerät eine eigene Mail"). Bei nur
	 * einer Position kommt weiterhin die gewohnte Einzel-Mail.
	 *
	 * @param int[] $line_ids Buchungszeilen (alle im selben Projekt, gleicher Owner).
	 */
	public static function on_booking_requested_batch( array $line_ids, int $owner_id, int $requester_id ): void {
		$line_ids = array_values( array_filter( array_map( 'intval', $line_ids ) ) );
		if ( ! self::enabled() || ! $line_ids ) {
			return;
		}
		if ( 1 === count( $line_ids ) ) {
			self::on_booking_requested( $line_ids[0], $owner_id, $requester_id );
			return;
		}
		$owner = get_userdata( $owner_id );
		if ( ! $owner || ! is_email( $owner->user_email ) ) {
			return;
		}
		$items        = [];
		$project_name = '';
		foreach ( $line_ids as $line_id ) {
			$ctx = BookingApprovals::get_line_context( $line_id );
			if ( ! $ctx ) {
				continue;
			}
			$project_name = (string) $ctx->project_name;
			$from         = $ctx->date_from_eff ? mysql2date( 'd.m.Y', $ctx->date_from_eff ) : '—';
			$to           = $ctx->date_to_eff ? mysql2date( 'd.m.Y', $ctx->date_to_eff ) : '—';
			/* translators: List line in the approval email. 1: quantity, 2: item name, 3: start date, 4: end date. */
			$items[] = sprintf( __( '- %1$d× %2$s (%3$s to %4$s)', 'project-prepper' ), (int) $ctx->quantity, (string) $ctx->item_name, $from, $to );
		}
		if ( ! $items ) {
			return;
		}
		$requester = get_userdata( $requester_id );
		$tpl       = self::templates()['booking_requested_list'];
		$vars      = [
			'count'          => count( $items ),
			'requester_name' => $requester ? $requester->display_name : '',
			'project_name'   => $project_name,
			'items'          => implode( "\n", $items ),
			'portal_url'     => add_query_arg( 'pp_view', 'approvals', MemberPortal::portal_url() ),
			'site_name'      => get_bloginfo( 'name' ),
		];
		wp_mail( $owner->user_email, self::render( $tpl['subject'], $vars ), self::render( $tpl['body'], $vars ) );
	}

	/**
	 * Sammel-Entscheidung: der Eigentümer hat mehrere Anfragen DESSELBEN Anfragers
	 * in einem Schwung entschieden → EINE Mail mit der Ergebnis-Liste (freigegeben/
	 * abgelehnt). Bei nur einer Entscheidung greift die gewohnte Einzel-Mail.
	 *
	 * @param array<array> $decisions Je Eintrag requester_id/item_name/project_name/project_id/status.
	 */
	public static function on_booking_decided_batch( int $requester_id, array $decisions ): void {
		if ( ! self::enabled() || ! $decisions ) {
			return;
		}
		if ( 1 === count( $decisions ) ) {
			self::on_booking_decided( (array) reset( $decisions ) );
			return;
		}
		$requester = get_userdata( $requester_id );
		if ( ! $requester || ! is_email( $requester->user_email ) ) {
			return;
		}
		$items = [];
		foreach ( $decisions as $d ) {
			$status_text = 'approved' === (string) ( $d['status'] ?? '' )
				? __( 'approved', 'project-prepper' )
				: __( 'rejected', 'project-prepper' );
			/* translators: List line in the decisions email. 1: item name, 2: project name, 3: decision (approved/rejected). */
			$items[] = sprintf( __( '- %1$s (%2$s): %3$s', 'project-prepper' ), (string) ( $d['item_name'] ?? '' ), (string) ( $d['project_name'] ?? '' ), $status_text );
		}
		$tpl  = self::templates()['booking_decided_list'];
		$vars = [
			'items'      => implode( "\n", $items ),
			'portal_url' => add_query_arg( 'pp_view', 'projects', MemberPortal::portal_url() ),
			'site_name'  => get_bloginfo( 'name' ),
		];
		wp_mail( $requester->user_email, self::render( $tpl['subject'], $vars ), self::render( $tpl['body'], $vars ) );
	}

	/**
	 * Entscheidungs-Info an den Anfrager (angenommen/abgelehnt). Der Payload trägt
	 * alle nötigen Felder, weil die Buchungszeile bei einer Ablehnung entfernt
	 * wurde und nicht mehr nachgeladen werden kann.
	 *
	 * @param array $payload requester_id, item_name, project_name, project_id, status.
	 */
	public static function on_booking_decided( array $payload ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$status = (string) ( $payload['status'] ?? '' );
		if ( ! in_array( $status, [ 'approved', 'rejected' ], true ) ) {
			return;
		}
		$requester = get_userdata( (int) ( $payload['requester_id'] ?? 0 ) );
		if ( ! $requester || ! is_email( $requester->user_email ) ) {
			return;
		}
		$status_text = 'approved' === $status
			? __( 'approved', 'project-prepper' )
			: __( 'rejected', 'project-prepper' );
		$tpl  = self::templates()['booking_decided'];
		$vars = [
			'item_name'    => (string) ( $payload['item_name'] ?? '' ),
			'project_name' => (string) ( $payload['project_name'] ?? '' ),
			'status'       => $status_text,
			'portal_url'   => add_query_arg(
				[ 'pp_view' => 'projects', 'pp_project' => (int) ( $payload['project_id'] ?? 0 ), 'pp_tab' => 'equipment' ],
				MemberPortal::portal_url()
			),
			'site_name'    => get_bloginfo( 'name' ),
		];
		wp_mail( $requester->user_email, self::render( $tpl['subject'], $vars ), self::render( $tpl['body'], $vars ) );
	}

	public static function on_rental_status_changed( int $rental_id, string $from, string $to ): void {
		$key = [ 'active' => 'rental_active', 'returned' => 'rental_returned' ][ $to ] ?? null;
		if ( $key ) {
			self::send_for_rental( $rental_id, $key );
		}
	}

	private static function send_for_rental( int $rental_id, string $template_key ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$rental = Rentals::get( $rental_id );
		if ( ! $rental || empty( $rental->borrower_email ) || ! is_email( $rental->borrower_email ) ) {
			return;
		}

		$templates = self::templates();
		$template  = $templates[ $template_key ] ?? null;
		if ( ! $template ) {
			return;
		}

		$item_lines = array_map( static function ( $line ) {
			return sprintf( '- %s× %s (%s)', $line->quantity, $line->item_name ?: '#' . $line->item_id, $line->inventory_number ?: '—' );
		}, $rental->items );

		$vars = [
			'borrower_name' => $rental->borrower_name,
			'rental_number' => $rental->rental_number,
			'date_from'     => mysql2date( 'd.m.Y', $rental->date_from ),
			'date_to'       => mysql2date( 'd.m.Y', $rental->date_to ),
			'items'         => implode( "\n", $item_lines ),
			'site_name'     => get_bloginfo( 'name' ),
		];

		wp_mail(
			$rental->borrower_email,
			self::render( $template['subject'], $vars ),
			self::render( $template['body'], $vars )
		);
	}
}
