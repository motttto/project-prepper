<?php
namespace ProjectPrepper\Email;

use ProjectPrepper\Services\Rentals;
use ProjectPrepper\Services\Groups;
use ProjectPrepper\Services\GroupGovernance;
use ProjectPrepper\Services\Borrowing;
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
		add_action( 'pp_borrow_requested', [ self::class, 'on_borrow_requested' ], 10, 1 );
		add_action( 'pp_borrow_decided', [ self::class, 'on_borrow_decided' ], 10, 2 );
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
				/* translators: Email body. Keep all {{…}} placeholders unchanged. */
				'body'    => __( "Hello,\n\n{{inviter_name}} has invited you to join the collective \"{{group_name}}\".\n\nSign in to accept the invitation:\n{{portal_url}}\n\nBest regards\n{{site_name}}", 'project-prepper' ),
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
			'borrow_requested' => __( 'Borrow request (to owner)', 'project-prepper' ),
			'borrow_decided'   => __( 'Borrow decision (to requester)', 'project-prepper' ),
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
		$vars    = [
			'group_name'   => $group ? $group->name : '',
			'inviter_name' => $inviter ? $inviter->display_name : get_bloginfo( 'name' ),
			'portal_url'   => MemberPortal::portal_url(),
			'site_name'    => get_bloginfo( 'name' ),
		];
		wp_mail( $inv->invited_email, self::render( $tpl['subject'], $vars ), self::render( $tpl['body'], $vars ) );
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
