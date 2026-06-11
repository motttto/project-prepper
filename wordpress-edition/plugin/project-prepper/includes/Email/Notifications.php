<?php
namespace ProjectPrepper\Email;

use ProjectPrepper\Services\Rentals;

defined( 'ABSPATH' ) || exit;

/**
 * E-Mail-Benachrichtigungen (§16) — wp_mail + editierbare Templates mit {{vars}}.
 *
 * Templates liegen als Option pp_email_templates; Defaults auf Deutsch.
 * Abschaltbar über Option pp_email_notifications.
 */
class Notifications {

	const OPTION_ENABLED   = 'pp_email_notifications';
	const OPTION_TEMPLATES = 'pp_email_templates';

	public static function init(): void {
		add_action( 'pp_rental_status_changed', [ self::class, 'on_rental_status_changed' ], 10, 3 );
		add_action( 'pp_rental_created', [ self::class, 'on_rental_created' ], 10, 1 );
	}

	public static function default_templates(): array {
		return [
			'rental_reserved' => [
				'subject' => 'Reservierung {{rental_number}} — {{site_name}}',
				'body'    => "Hallo {{borrower_name}},\n\ndeine Reservierung {{rental_number}} ist eingetragen:\n\nZeitraum: {{date_from}} bis {{date_to}}\n\nPositionen:\n{{items}}\n\nViele Grüße\n{{site_name}}",
			],
			'rental_active'   => [
				'subject' => 'Equipment ausgegeben — {{rental_number}}',
				'body'    => "Hallo {{borrower_name}},\n\ndas Equipment zu {{rental_number}} wurde ausgegeben.\n\nRückgabe bis: {{date_to}}\n\nPositionen:\n{{items}}\n\nViele Grüße\n{{site_name}}",
			],
			'rental_returned' => [
				'subject' => 'Rückgabe bestätigt — {{rental_number}}',
				'body'    => "Hallo {{borrower_name}},\n\ndie Rückgabe zu {{rental_number}} ist bestätigt. Danke!\n\nViele Grüße\n{{site_name}}",
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
