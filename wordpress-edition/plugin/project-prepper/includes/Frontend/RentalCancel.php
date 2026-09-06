<?php
namespace ProjectPrepper\Frontend;

use ProjectPrepper\Services\ActivityLog;
use ProjectPrepper\Services\Rentals;

defined( 'ABSPATH' ) || exit;

/**
 * Storno-Link für externe Leiher (v0.141.0) — der einzige Weg, auf dem eine
 * Person OHNE Konto etwas an einem Verleih ändert.
 *
 * Zwei Schritte, bewusst: GET zeigt nur eine Bestätigungsseite, erst POST
 * storniert. Mail-Programme und Virenscanner rufen Links vorab auf — wäre
 * schon der Klick das Storno, würden Reservierungen verschwinden, ohne dass
 * je ein Mensch den Knopf gedrückt hat.
 *
 * Storniert werden kann nur, was noch RESERVIERT ist. Ausgegebenes Equipment
 * ist außer Haus — dafür muss der Leiher die Person erreichen, die es ihm
 * gegeben hat; die Seite sagt ihm das.
 */
class RentalCancel {

	public static function init(): void {
		add_action( 'admin_post_nopriv_pp_rental_cancel', [ self::class, 'handle' ] );
		add_action( 'admin_post_pp_rental_cancel', [ self::class, 'handle' ] );
	}

	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- der Link kommt aus einer Mail ohne Session; die Legitimation ist der HMAC-Schlüssel (Rentals::cancel_token), Nonce gibt es hier nicht.
		$id  = isset( $_REQUEST['rental'] ) ? absint( $_REQUEST['rental'] ) : 0;
		$key = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$rental = $id ? Rentals::get( $id ) : null;

		// Ohne Leiher-Adresse wurde nie ein Link verschickt — solche Verleihe
		// erreichen den Endpunkt gar nicht erst.
		if ( ! $rental || '' === $key || empty( $rental->borrower_email ) || ! hash_equals( Rentals::cancel_token( $rental ), $key ) ) {
			self::page( __( 'This link is not valid', 'project-prepper' ), '<p>' . esc_html__( 'The cancellation link is invalid or belongs to a different reservation. Please use the link from your confirmation email.', 'project-prepper' ) . '</p>' );
		}

		if ( 'cancelled' === $rental->status ) {
			self::page( __( 'Already cancelled', 'project-prepper' ), '<p>' . esc_html( sprintf( /* translators: %s: rental number. */ __( 'Reservation %s has already been cancelled.', 'project-prepper' ), $rental->rental_number ) ) . '</p>' );
		}
		if ( 'reserved' !== $rental->status ) {
			self::page( __( 'Cancellation not possible online', 'project-prepper' ), '<p>' . esc_html( sprintf( /* translators: %s: rental number. */ __( 'Reservation %s can no longer be cancelled here — the equipment has already been handed out or returned. Please contact the person you received it from.', 'project-prepper' ), $rental->rental_number ) ) . '</p>' );
		}

		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			$result = Rentals::set_status( $id, 'cancelled' );
			if ( is_wp_error( $result ) ) {
				self::page( __( 'Cancellation failed', 'project-prepper' ), '<p>' . esc_html( $result->get_error_message() ) . '</p>' );
			}
			ActivityLog::log( 'rental_cancelled_by_borrower', 'rental', $id, [ 'borrower' => $rental->borrower_name ] );
			do_action( 'pp_rental_cancelled_by_borrower', $id );
			self::page(
				__( 'Reservation cancelled', 'project-prepper' ),
				'<p>' . esc_html( sprintf( /* translators: %s: rental number. */ __( 'Reservation %s has been cancelled. You will receive a short confirmation by email.', 'project-prepper' ), $rental->rental_number ) ) . '</p>'
			);
		}

		// GET: erst fragen.
		$period = mysql2date( 'd.m.Y', $rental->date_from ) . ' – ' . mysql2date( 'd.m.Y', $rental->date_to );
		$lines  = '';
		foreach ( (array) $rental->items as $line ) {
			$lines .= '<li>' . esc_html( sprintf( '%d× %s', (int) $line->quantity, $line->item_name ?: '#' . (int) $line->item_id ) ) . '</li>';
		}
		$body  = '<p>' . esc_html( sprintf( /* translators: 1: rental number, 2: period. */ __( 'Do you want to cancel reservation %1$s (%2$s)?', 'project-prepper' ), $rental->rental_number, $period ) ) . '</p>';
		$body .= '<ul>' . $lines . '</ul>';
		$body .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$body .= '<input type="hidden" name="action" value="pp_rental_cancel">';
		$body .= '<input type="hidden" name="rental" value="' . (int) $id . '">';
		$body .= '<input type="hidden" name="key" value="' . esc_attr( $key ) . '">';
		$body .= '<p><button type="submit" style="padding:.6em 1.2em;font-size:1em;cursor:pointer">' . esc_html__( 'Yes, cancel this reservation', 'project-prepper' ) . '</button></p>';
		$body .= '</form>';
		$body .= '<p><small>' . esc_html__( 'Nothing happens until you press the button.', 'project-prepper' ) . '</small></p>';
		self::page( __( 'Cancel reservation?', 'project-prepper' ), $body );
	}

	/** Schlichte Seite im WordPress-Standardrahmen — braucht kein Theme, kein Login. */
	private static function page( string $title, string $html ): void {
		wp_die(
			'<h1>' . esc_html( $title ) . '</h1>' . $html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html wird oben Stück für Stück escaped zusammengesetzt.
			esc_html( $title . ' — ' . get_bloginfo( 'name' ) ),
			[ 'response' => 200 ]
		);
	}
}
