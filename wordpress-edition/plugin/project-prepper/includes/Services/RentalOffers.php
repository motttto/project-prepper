<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Individuelle Angebote aus einem Verleih (Schema 0.46.0, User-Wunsch).
 *
 * Ein Angebot ist ein DOKUMENT, keine Buchung: Es startet mit den Daten des
 * Verleihs (Leiher als Empfänger, Positionen mit Tagessätzen, Rabatt, USt), und
 * danach ist alles frei editierbar — Aussteller, Empfänger, Nummer, Datum,
 * gültig bis, Betreff, Texte, Positionen, Preisangabe (brutto/netto) und USt.
 * Verfügbarkeit und Freigaben berührt es nicht.
 *
 * Rechte: wer den Verleih bearbeiten darf (sein Anleger, {@see MemberRentals::owns}).
 */
class RentalOffers {

	/** USt-Auswahl: Schlüssel => Satz in Prozent. „ku" = Kleinunternehmer (§ 19 UStG). */
	const VAT_OPTIONS = [ '19' => 19.0, '7' => 7.0, '0' => 0.0, 'ku' => 0.0 ];

	const PRICE_MODES = [ 'gross', 'net' ];

	const MAX_LINES = 100;

	/** User-Meta: zuletzt verwendeter Aussteller-Block (Vorbelegung fürs nächste Angebot). */
	const ISSUER_META = 'pp_offer_issuer';

	/** Anzeigenamen der USt-Auswahl. */
	public static function vat_labels(): array {
		return [
			'19' => __( '19 % VAT', 'project-prepper' ),
			'7'  => __( '7 % VAT', 'project-prepper' ),
			'0'  => __( '0 % VAT', 'project-prepper' ),
			'ku' => __( 'No VAT — small business (§ 19 UStG)', 'project-prepper' ),
		];
	}

	/** @return array<object> Angebote eines Verleihs, neueste zuerst. */
	public static function for_rental( int $rental_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE rental_id = %d ORDER BY id DESC',
			Schema::table( 'rental_offers' ),
			$rental_id
		) ) ?: [];
		return array_map( [ self::class, 'decode' ], $rows );
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'rental_offers' ), $id ) );
		return $row ? self::decode( $row ) : null;
	}

	/** Darf der User Angebote dieses Verleihs anlegen, sehen und ändern? */
	public static function can_manage( int $user_id, ?object $rental ): bool {
		return $rental && $user_id > 0 && MemberRentals::owns( $rental, $user_id );
	}

	/**
	 * Vorbelegung eines neuen Angebots aus dem Verleih.
	 *
	 * @param object $rental Rentals::get() — mit ->items und ->billing.
	 */
	public static function defaults( object $rental, int $user_id ): object {
		$user   = get_userdata( $user_id );
		$bill   = (array) ( $rental->billing ?? [] );
		$days   = (int) ( $bill['days'] ?? 1 );
		$flat   = ! empty( $bill['flat'] );
		$lines  = [];
		foreach ( (array) ( $bill['lines'] ?? [] ) as $l ) {
			$lines[] = [
				'desc' => (string) $l['name'],
				'qty'  => (int) $l['quantity'],
				'days' => $days,
				// Bei Pauschalpreis stehen die Geräte ohne Einzelpreis da; der Preis
				// kommt als eigene Zeile (sonst wäre er doppelt).
				'rate' => $flat || null === $l['daily_rate'] ? null : (float) $l['daily_rate'],
			];
		}
		if ( $flat ) {
			$lines[] = [ 'desc' => __( 'Rental fee (flat rate)', 'project-prepper' ), 'qty' => 1, 'days' => 1, 'rate' => (float) $bill['subtotal'] ];
		}
		$recipient = array_filter( [
			(string) $rental->borrower_name,
			(string) ( $rental->borrower_address ?? '' ),
			(string) $rental->borrower_email,
			(string) $rental->borrower_phone,
		], static fn( $v ) => '' !== trim( $v ) );
		$issuer = (string) get_user_meta( $user_id, self::ISSUER_META, true );
		if ( '' === trim( $issuer ) && $user ) {
			$issuer = $user->display_name . "\n" . $user->user_email;
		}
		$event   = trim( (string) ( $rental->event_name ?? '' ) );
		$outro   = [];
		if ( ! empty( $bill['deposit'] ) ) {
			/* translators: %s: deposit amount, e.g. "150,00 €". */
			$outro[] = sprintf( __( 'Deposit: %s (refunded when the equipment is returned).', 'project-prepper' ), number_format_i18n( (float) $bill['deposit'], 2 ) . ' €' );
		}
		$outro[] = __( 'We look forward to hearing from you.', 'project-prepper' );
		$vat_key = self::vat_key_for( (float) ( $bill['vat_rate'] ?? 19 ) );
		return (object) [
			'id'             => 0,
			'rental_id'      => (int) $rental->id,
			'offer_number'   => self::next_number(),
			'offer_date'     => current_time( 'Y-m-d' ),
			'valid_until'    => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +14 days' ) ),
			'issuer'         => $issuer,
			'recipient'      => implode( "\n", $recipient ),
			/* translators: %s: event name or rental number. */
			'subject'        => sprintf( __( 'Offer: equipment rental — %s', 'project-prepper' ), '' !== $event ? $event : (string) $rental->rental_number ),
			'intro'          => sprintf(
				/* translators: 1: start date, 2: end date. */
				__( 'Thank you for your inquiry. We are pleased to offer you the following equipment for the period %1$s to %2$s:', 'project-prepper' ),
				mysql2date( 'd.m.Y', (string) $rental->date_from ),
				mysql2date( 'd.m.Y', (string) $rental->date_to )
			),
			'outro'          => implode( "\n\n", $outro ),
			// Die Tagessätze des Verleihs sind Bruttopreise (Rentals::billing).
			'price_mode'     => 'gross',
			'vat_key'        => $vat_key,
			'discount_type'  => $bill['discount_type'] ?? null,
			'discount_value' => ! empty( $bill['discount_type'] ) ? (float) $bill['discount_value'] : null,
			'lines'          => $lines,
		];
	}

	/**
	 * Angebot anlegen ($id = 0) oder ändern.
	 *
	 * @param array $data Rohwerte aus dem Formular (siehe input-Namen im Portal).
	 * @return int|WP_Error Angebots-ID.
	 */
	public static function save( int $user_id, int $rental_id, array $data, int $id = 0 ) {
		global $wpdb;
		$rental = Rentals::get( $rental_id );
		if ( ! self::can_manage( $user_id, $rental ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only the person who created the rental can prepare offers for it.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		if ( $id > 0 ) {
			$existing = self::get( $id );
			if ( ! $existing || (int) $existing->rental_id !== $rental_id ) {
				return new WP_Error( 'pp_not_found', __( 'Offer not found.', 'project-prepper' ), [ 'status' => 404 ] );
			}
		}
		$lines = [];
		foreach ( (array) ( $data['lines'] ?? [] ) as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$desc = trim( sanitize_textarea_field( (string) ( $l['desc'] ?? '' ) ) );
			if ( '' === $desc ) {
				continue; // leere Zeile
			}
			$rate    = self::num( $l['rate'] ?? '' );
			$qty     = self::num( $l['qty'] ?? '' );
			$days    = self::num( $l['days'] ?? '' );
			$lines[] = [
				'desc' => mb_substr( $desc, 0, 500 ),
				'qty'  => null === $qty ? 1.0 : max( 0.0, round( $qty, 2 ) ),
				'days' => null === $days ? 1.0 : max( 0.0, round( $days, 2 ) ),
				'rate' => null === $rate ? null : round( $rate, 2 ),
			];
			if ( count( $lines ) >= self::MAX_LINES ) {
				break;
			}
		}
		if ( ! $lines ) {
			return new WP_Error( 'pp_offer_empty', __( 'An offer needs at least one line with a description.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$number = trim( sanitize_text_field( (string) ( $data['offer_number'] ?? '' ) ) );
		$dtype  = in_array( (string) ( $data['discount_type'] ?? '' ), Rentals::DISCOUNT_TYPES, true ) ? (string) $data['discount_type'] : null;
		$row    = [
			'offer_number'   => mb_substr( '' !== $number ? $number : self::next_number(), 0, 40 ),
			'offer_date'     => self::date( (string) ( $data['offer_date'] ?? '' ) ) ?? current_time( 'Y-m-d' ),
			'valid_until'    => self::date( (string) ( $data['valid_until'] ?? '' ) ),
			'issuer'         => sanitize_textarea_field( (string) ( $data['issuer'] ?? '' ) ),
			'recipient'      => sanitize_textarea_field( (string) ( $data['recipient'] ?? '' ) ),
			'subject'        => mb_substr( sanitize_text_field( (string) ( $data['subject'] ?? '' ) ), 0, 190 ),
			'intro'          => sanitize_textarea_field( (string) ( $data['intro'] ?? '' ) ),
			'outro'          => sanitize_textarea_field( (string) ( $data['outro'] ?? '' ) ),
			'price_mode'     => in_array( (string) ( $data['price_mode'] ?? '' ), self::PRICE_MODES, true ) ? (string) $data['price_mode'] : 'gross',
			'vat_key'        => array_key_exists( (string) ( $data['vat_key'] ?? '' ), self::VAT_OPTIONS ) ? (string) $data['vat_key'] : '19',
			'discount_type'  => $dtype,
			'discount_value' => $dtype ? max( 0.0, (float) self::num( $data['discount_value'] ?? 0 ) ) : null,
			'line_items'     => wp_json_encode( $lines ),
			'updated_at'     => current_time( 'mysql' ),
		];
		$table = Schema::table( 'rental_offers' );
		if ( $id > 0 ) {
			$wpdb->update( $table, $row, [ 'id' => $id ] );
		} else {
			$row['rental_id']  = $rental_id;
			$row['created_by'] = $user_id;
			$row['created_at'] = $row['updated_at'];
			if ( false === $wpdb->insert( $table, $row ) ) {
				return new WP_Error( 'pp_save_failed', __( 'The offer could not be saved.', 'project-prepper' ), [ 'status' => 500 ] );
			}
			$id = (int) $wpdb->insert_id;
		}
		// Aussteller fürs nächste Angebot merken.
		if ( '' !== trim( $row['issuer'] ) ) {
			update_user_meta( $user_id, self::ISSUER_META, $row['issuer'] );
		}
		ActivityLog::log( 'rental_offer_saved', 'rental', $rental_id, [ 'offer_id' => $id, 'number' => $row['offer_number'] ] );
		return $id;
	}

	/** @return true|WP_Error */
	public static function delete( int $user_id, int $id ) {
		global $wpdb;
		$offer = self::get( $id );
		if ( ! $offer || ! self::can_manage( $user_id, Rentals::get( (int) $offer->rental_id ) ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only the person who created the rental can prepare offers for it.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$wpdb->delete( Schema::table( 'rental_offers' ), [ 'id' => $id ], [ '%d' ] );
		ActivityLog::log( 'rental_offer_deleted', 'rental', (int) $offer->rental_id, [ 'offer_id' => $id, 'number' => $offer->offer_number ] );
		return true;
	}

	/** Verleih gelöscht → seine Angebote mit. */
	public static function delete_for_rental( int $rental_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'rental_offers' ), [ 'rental_id' => $rental_id ], [ '%d' ] );
	}

	/**
	 * Summen eines Angebots. Brutto-Modus wie beim Verleih: Preise enthalten die
	 * USt, sie wird herausgerechnet. Netto-Modus: USt kommt obendrauf.
	 *
	 * @return array{lines:array,sum:float,discount:float,net:float,vat:float,gross:float,vat_rate:float,small_business:bool}
	 */
	public static function totals( object $offer ): array {
		$lines = [];
		$sum   = 0.0;
		foreach ( (array) $offer->lines as $l ) {
			$total   = null === $l['rate'] ? 0.0 : round( (float) $l['qty'] * (float) $l['days'] * (float) $l['rate'], 2 );
			$sum    += $total;
			$lines[] = $l + [ 'total' => $total ];
		}
		$sum      = round( $sum, 2 );
		$discount = 0.0;
		if ( 'percent' === $offer->discount_type ) {
			$discount = round( $sum * max( 0.0, min( 100.0, (float) $offer->discount_value ) ) / 100, 2 );
		} elseif ( 'amount' === $offer->discount_type ) {
			$discount = round( max( 0.0, min( (float) $offer->discount_value, $sum ) ), 2 );
		}
		$base = round( max( 0.0, $sum - $discount ), 2 );
		$rate = self::VAT_OPTIONS[ $offer->vat_key ] ?? 19.0;
		if ( 'net' === $offer->price_mode ) {
			$net   = $base;
			$vat   = round( $net * $rate / 100, 2 );
			$gross = round( $net + $vat, 2 );
		} else {
			$gross = $base;
			$net   = round( $gross / ( 1 + $rate / 100 ), 2 );
			$vat   = round( $gross - $net, 2 );
		}
		return [
			'lines'          => $lines,
			'sum'            => $sum,
			'discount'       => $discount,
			'net'            => $net,
			'vat'            => $vat,
			'gross'          => $gross,
			'vat_rate'       => $rate,
			'small_business' => 'ku' === $offer->vat_key,
		];
	}

	/** Nächste Angebotsnummer A-JJJJ-NNNN (fortlaufend je Jahr, editierbar). */
	public static function next_number(): string {
		global $wpdb;
		$year = current_time( 'Y' );
		$max  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(CAST(SUBSTRING_INDEX(offer_number, '-', -1) AS UNSIGNED)) FROM %i WHERE offer_number LIKE %s",
			Schema::table( 'rental_offers' ),
			$wpdb->esc_like( 'A-' . $year . '-' ) . '%'
		) );
		return sprintf( 'A-%s-%04d', $year, $max + 1 );
	}

	/** USt-Schlüssel zum Satz des Verleihs (19/7/0). */
	private static function vat_key_for( float $rate ): string {
		foreach ( [ '19', '7', '0' ] as $key ) {
			if ( abs( self::VAT_OPTIONS[ $key ] - $rate ) < 0.01 ) {
				return $key;
			}
		}
		return '19';
	}

	private static function decode( object $row ): object {
		$lines      = json_decode( (string) ( $row->line_items ?? '' ), true );
		$row->lines = is_array( $lines ) ? $lines : [];
		foreach ( $row->lines as $i => $l ) {
			$row->lines[ $i ] = [
				'desc' => (string) ( $l['desc'] ?? '' ),
				'qty'  => (float) ( $l['qty'] ?? 0 ),
				'days' => (float) ( $l['days'] ?? 0 ),
				'rate' => isset( $l['rate'] ) && null !== $l['rate'] ? (float) $l['rate'] : null,
			];
		}
		$row->discount_value = null !== $row->discount_value ? (float) $row->discount_value : null;
		return $row;
	}

	/** Zahl aus Formulareingabe („1.234,50", „12,5", „12.5") — leer = null. */
	private static function num( $value ): ?float {
		$v = trim( (string) $value );
		if ( '' === $v ) {
			return null;
		}
		$v = str_replace( [ ' ', '€' ], '', $v );
		if ( false !== strpos( $v, ',' ) ) {
			$v = str_replace( [ '.', ',' ], [ '', '.' ], $v );
		}
		return is_numeric( $v ) ? (float) $v : null;
	}

	private static function date( string $value ): ?string {
		$value = trim( $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
	}
}
