<?php
namespace ProjectPrepper\Email;

use ProjectPrepper\Services\Rentals;

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
		];
	}

	public static function templates(): array {
		$saved = get_option( self::OPTION_TEMPLATES, [] );
		return array_replace_recursive( self::default_templates(), is_array( $saved ) ? $saved : [] );
	}

	public static function enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, true );
	}

	public static function render( string $template, array $vars ): string {
		foreach ( $vars as $key => $value ) {
			$template = str_replace( '{{' . $key . '}}', (string) $value, $template );
		}
		return $template;
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
			'admin_url' => admin_url( 'admin.php?page=pp-inquiries' ),
		];

		wp_mail(
			get_option( 'admin_email' ),
			self::render( $template['subject'], $vars ),
			self::render( $template['body'], $vars )
		);
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
