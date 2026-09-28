<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Angebote und Rechnungen aus einem Verleih (Schema 0.46.0, User-Wunsch).
 *
 * Ein Dokument ist KEINE Buchung: Es startet mit den Daten des Verleihs (Leiher
 * als Empfänger, Positionen mit Tagessätzen, Rabatt, USt), danach ist alles frei
 * editierbar. Verfügbarkeit und Freigaben berührt es nicht.
 *
 *  - Angebot (offer):  Nummer A-JJJJ-NNNN, „gültig bis".
 *  - Rechnung (invoice): Nummer R-JJJJ-NNNN — eindeutig (eine Rechnungsnummer
 *    darf nur einmal vorkommen), dazu die Pflichtangaben nach § 14 UStG, die ein
 *    Angebot nicht braucht: Steuernummer/USt-IdNr. des Ausstellers,
 *    Leistungszeitraum, Fälligkeit, Zahlungshinweis/Bankverbindung. Eine
 *    Rechnung lässt sich aus einem Angebot erzeugen (source_id).
 *
 * Aussteller, Steuernummer und Zahlungshinweis merkt sich das Portal je Nutzer
 * (User-Meta) als Vorbelegung fürs nächste Dokument.
 *
 * Rechte: wer den Verleih bearbeiten darf (sein Anleger, {@see MemberRentals::owns}).
 */
class RentalDocuments {

	const TYPES = [ 'offer', 'invoice' ];

	/** USt-Auswahl: Schlüssel => Satz in Prozent. „ku" = Kleinunternehmer (§ 19 UStG). */
	const VAT_OPTIONS = [ '19' => 19.0, '7' => 7.0, '0' => 0.0, 'ku' => 0.0 ];

	const PRICE_MODES = [ 'gross', 'net' ];

	const MAX_LINES = 100;

	/** User-Meta: zuletzt verwendete Angaben des Ausstellers. */
	const META_ISSUER  = 'pp_doc_issuer';
	const META_TAX_ID  = 'pp_doc_tax_id';
	const META_PAYMENT = 'pp_doc_payment';

	/** Anzeigenamen der USt-Auswahl. */
	public static function vat_labels(): array {
		return [
			'19' => __( '19 % VAT', 'project-prepper' ),
			'7'  => __( '7 % VAT', 'project-prepper' ),
			'0'  => __( '0 % VAT', 'project-prepper' ),
			'ku' => __( 'No VAT — small business (§ 19 UStG)', 'project-prepper' ),
		];
	}

	/** Bezeichnung eines Dokumenttyps (für Listen, Titel, Druck). */
	public static function type_label( string $type ): string {
		return 'invoice' === $type ? __( 'Invoice', 'project-prepper' ) : __( 'Offer', 'project-prepper' );
	}

	/** @return array<object> Dokumente eines Verleihs, neueste zuerst. */
	public static function for_rental( int $rental_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE rental_id = %d ORDER BY id DESC',
			Schema::table( 'rental_documents' ),
			$rental_id
		) ) ?: [];
		return array_map( [ self::class, 'decode' ], $rows );
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'rental_documents' ), $id ) );
		return $row ? self::decode( $row ) : null;
	}

	/** Darf der User Dokumente dieses Verleihs anlegen, sehen und ändern? */
	public static function can_manage( int $user_id, ?object $rental ): bool {
		return $rental && $user_id > 0 && MemberRentals::owns( $rental, $user_id );
	}

	/**
	 * Vorbelegung eines neuen Dokuments — aus dem Verleih oder (Rechnung aus
	 * Angebot) aus einem vorhandenen Angebot.
	 *
	 * @param object      $rental Rentals::get() — mit ->items und ->billing.
	 * @param object|null $source Angebot, aus dem eine Rechnung entsteht.
	 */
	public static function defaults( object $rental, int $user_id, string $type = 'offer', ?object $source = null ): object {
		$type  = in_array( $type, self::TYPES, true ) ? $type : 'offer';
		$user  = get_userdata( $user_id );
		$bill  = (array) ( $rental->billing ?? [] );
		$today = current_time( 'Y-m-d' );
		$event = trim( (string) ( $rental->event_name ?? '' ) );
		$what  = '' !== $event ? $event : (string) $rental->rental_number;

		if ( $source ) {
			$lines     = $source->lines;
			$recipient = (string) $source->recipient;
			$vat_key   = (string) $source->vat_key;
			$mode      = (string) $source->price_mode;
			$dtype     = $source->discount_type;
			$dval      = $source->discount_value;
		} else {
			$days  = (int) ( $bill['days'] ?? 1 );
			$flat  = ! empty( $bill['flat'] );
			$lines = [];
			foreach ( (array) ( $bill['lines'] ?? [] ) as $l ) {
				$lines[] = [
					'desc' => (string) $l['name'],
					'qty'  => (float) $l['quantity'],
					'days' => (float) $days,
					// Bei Pauschalpreis stehen die Geräte ohne Einzelpreis da; der Preis
					// kommt als eigene Zeile (sonst wäre er doppelt).
					'rate' => $flat || null === $l['daily_rate'] ? null : (float) $l['daily_rate'],
				];
			}
			if ( $flat ) {
				$lines[] = [ 'desc' => __( 'Rental fee (flat rate)', 'project-prepper' ), 'qty' => 1.0, 'days' => 1.0, 'rate' => (float) $bill['subtotal'] ];
			}
			$recipient = implode( "\n", array_filter( [
				(string) $rental->borrower_name,
				(string) ( $rental->borrower_address ?? '' ),
				(string) $rental->borrower_email,
				(string) $rental->borrower_phone,
			], static fn( $v ) => '' !== trim( $v ) ) );
			$vat_key = self::vat_key_for( (float) ( $bill['vat_rate'] ?? 19 ) );
			// Die Tagessätze des Verleihs sind Bruttopreise (Rentals::billing).
			$mode  = 'gross';
			$dtype = $bill['discount_type'] ?? null;
			$dval  = ! empty( $bill['discount_type'] ) ? (float) $bill['discount_value'] : null;
		}

		$issuer = (string) get_user_meta( $user_id, self::META_ISSUER, true );
		if ( '' === trim( $issuer ) && $user ) {
			$issuer = $user->display_name . "\n" . $user->user_email;
		}
		$period = [ mysql2date( 'd.m.Y', (string) $rental->date_from ), mysql2date( 'd.m.Y', (string) $rental->date_to ) ];

		$doc = (object) [
			'id'             => 0,
			'rental_id'      => (int) $rental->id,
			'doc_type'       => $type,
			'source_id'      => $source ? (int) $source->id : null,
			'doc_number'     => self::next_number( $type ),
			'doc_date'       => $today,
			'valid_until'    => null,
			'due_date'       => null,
			'service_from'   => (string) $rental->date_from,
			'service_to'     => (string) $rental->date_to,
			'issuer'         => $issuer,
			'tax_id'         => (string) get_user_meta( $user_id, self::META_TAX_ID, true ),
			'payment_info'   => (string) get_user_meta( $user_id, self::META_PAYMENT, true ),
			'recipient'      => $recipient,
			'subject'        => '',
			'intro'          => '',
			'outro'          => '',
			'price_mode'     => in_array( $mode, self::PRICE_MODES, true ) ? $mode : 'gross',
			'vat_key'        => array_key_exists( $vat_key, self::VAT_OPTIONS ) ? $vat_key : '19',
			'discount_type'  => $dtype,
			'discount_value' => $dval,
			'lines'          => $lines,
		];

		if ( 'invoice' === $type ) {
			$doc->due_date = gmdate( 'Y-m-d', strtotime( $today . ' +14 days' ) );
			/* translators: %s: event name or rental number. */
			$doc->subject = sprintf( __( 'Invoice: equipment rental — %s', 'project-prepper' ), $what );
			/* translators: 1: start date, 2: end date. */
			$doc->intro = sprintf( __( 'Thank you for your order. For the equipment rental from %1$s to %2$s we invoice the following:', 'project-prepper' ), $period[0], $period[1] );
			$doc->outro = __( 'Please transfer the amount by the due date, stating the invoice number.', 'project-prepper' );
		} else {
			$doc->valid_until = gmdate( 'Y-m-d', strtotime( $today . ' +14 days' ) );
			/* translators: %s: event name or rental number. */
			$doc->subject = sprintf( __( 'Offer: equipment rental — %s', 'project-prepper' ), $what );
			/* translators: 1: start date, 2: end date. */
			$doc->intro = sprintf( __( 'Thank you for your inquiry. We are pleased to offer you the following equipment for the period %1$s to %2$s:', 'project-prepper' ), $period[0], $period[1] );
			$outro = [];
			if ( ! empty( $bill['deposit'] ) ) {
				/* translators: %s: deposit amount, e.g. "150,00 €". */
				$outro[] = sprintf( __( 'Deposit: %s (refunded when the equipment is returned).', 'project-prepper' ), number_format_i18n( (float) $bill['deposit'], 2 ) . ' €' );
			}
			$outro[]    = __( 'We look forward to hearing from you.', 'project-prepper' );
			$doc->outro = implode( "\n\n", $outro );
		}
		return $doc;
	}

	/**
	 * Dokument anlegen ($id = 0) oder ändern. Der Typ steht beim Anlegen fest
	 * und ändert sich danach nicht.
	 *
	 * @param array $data Rohwerte aus dem Formular (siehe input-Namen im Portal).
	 * @return int|WP_Error Dokument-ID.
	 */
	public static function save( int $user_id, int $rental_id, array $data, int $id = 0 ) {
		global $wpdb;
		$rental = Rentals::get( $rental_id );
		if ( ! self::can_manage( $user_id, $rental ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only the person who created the rental can prepare offers and invoices for it.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$type = in_array( (string) ( $data['doc_type'] ?? '' ), self::TYPES, true ) ? (string) $data['doc_type'] : 'offer';
		if ( $id > 0 ) {
			$existing = self::get( $id );
			if ( ! $existing || (int) $existing->rental_id !== $rental_id ) {
				return new WP_Error( 'pp_not_found', __( 'Document not found.', 'project-prepper' ), [ 'status' => 404 ] );
			}
			$type = (string) $existing->doc_type;
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
			return new WP_Error( 'pp_doc_empty', __( 'A document needs at least one line with a description.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$number = mb_substr( trim( sanitize_text_field( (string) ( $data['doc_number'] ?? '' ) ) ), 0, 40 );
		if ( '' === $number ) {
			$number = self::next_number( $type );
		}
		// Eine Rechnungsnummer darf es nur einmal geben (§ 14 UStG: einmalig).
		if ( 'invoice' === $type && self::number_taken( $number, $id ) ) {
			return new WP_Error(
				'pp_invoice_number_taken',
				/* translators: %s: invoice number. */
				sprintf( __( 'The invoice number “%s” is already used by another invoice. Invoice numbers must be unique.', 'project-prepper' ), $number ),
				[ 'status' => 409 ]
			);
		}
		$dtype = in_array( (string) ( $data['discount_type'] ?? '' ), Rentals::DISCOUNT_TYPES, true ) ? (string) $data['discount_type'] : null;
		$row   = [
			'doc_number'     => $number,
			'doc_date'       => self::date( (string) ( $data['doc_date'] ?? '' ) ) ?? current_time( 'Y-m-d' ),
			'valid_until'    => 'offer' === $type ? self::date( (string) ( $data['valid_until'] ?? '' ) ) : null,
			'due_date'       => 'invoice' === $type ? self::date( (string) ( $data['due_date'] ?? '' ) ) : null,
			'service_from'   => self::date( (string) ( $data['service_from'] ?? '' ) ),
			'service_to'     => self::date( (string) ( $data['service_to'] ?? '' ) ),
			'issuer'         => sanitize_textarea_field( (string) ( $data['issuer'] ?? '' ) ),
			'tax_id'         => mb_substr( sanitize_text_field( (string) ( $data['tax_id'] ?? '' ) ), 0, 64 ),
			'payment_info'   => sanitize_textarea_field( (string) ( $data['payment_info'] ?? '' ) ),
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
		$table = Schema::table( 'rental_documents' );
		if ( $id > 0 ) {
			$wpdb->update( $table, $row, [ 'id' => $id ] );
		} else {
			$source = (int) ( $data['source_id'] ?? 0 );
			$src    = $source > 0 ? self::get( $source ) : null;
			$row['rental_id']  = $rental_id;
			$row['doc_type']   = $type;
			$row['source_id']  = ( $src && (int) $src->rental_id === $rental_id ) ? $source : null;
			$row['created_by'] = $user_id;
			$row['created_at'] = $row['updated_at'];
			if ( false === $wpdb->insert( $table, $row ) ) {
				return new WP_Error( 'pp_save_failed', __( 'The document could not be saved.', 'project-prepper' ), [ 'status' => 500 ] );
			}
			$id = (int) $wpdb->insert_id;
		}
		// Angaben des Ausstellers fürs nächste Dokument merken.
		foreach ( [ self::META_ISSUER => 'issuer', self::META_TAX_ID => 'tax_id', self::META_PAYMENT => 'payment_info' ] as $meta => $field ) {
			if ( '' !== trim( (string) $row[ $field ] ) ) {
				update_user_meta( $user_id, $meta, $row[ $field ] );
			}
		}
		ActivityLog::log( 'rental_document_saved', 'rental', $rental_id, [ 'doc_id' => $id, 'type' => $type, 'number' => $number ] );
		return $id;
	}

	/** @return true|WP_Error */
	public static function delete( int $user_id, int $id ) {
		global $wpdb;
		$doc = self::get( $id );
		if ( ! $doc || ! self::can_manage( $user_id, Rentals::get( (int) $doc->rental_id ) ) ) {
			return new WP_Error( 'pp_forbidden', __( 'Only the person who created the rental can prepare offers and invoices for it.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		$wpdb->delete( Schema::table( 'rental_documents' ), [ 'id' => $id ], [ '%d' ] );
		// Eine Rechnung, die aus einem Angebot entstand, verweist nicht mehr ins Leere.
		$wpdb->update( Schema::table( 'rental_documents' ), [ 'source_id' => null ], [ 'source_id' => $id ] );
		ActivityLog::log( 'rental_document_deleted', 'rental', (int) $doc->rental_id, [ 'doc_id' => $id, 'type' => $doc->doc_type, 'number' => $doc->doc_number ] );
		return true;
	}

	/** Verleih gelöscht → seine Dokumente mit. */
	public static function delete_for_rental( int $rental_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'rental_documents' ), [ 'rental_id' => $rental_id ], [ '%d' ] );
	}

	/**
	 * Summen eines Dokuments. Brutto-Modus wie beim Verleih: Preise enthalten die
	 * USt, sie wird herausgerechnet. Netto-Modus: USt kommt obendrauf.
	 *
	 * @return array{lines:array,sum:float,discount:float,net:float,vat:float,gross:float,vat_rate:float,small_business:bool}
	 */
	public static function totals( object $doc ): array {
		$lines = [];
		$sum   = 0.0;
		foreach ( (array) $doc->lines as $l ) {
			$total   = null === $l['rate'] ? 0.0 : round( (float) $l['qty'] * (float) $l['days'] * (float) $l['rate'], 2 );
			$sum    += $total;
			$lines[] = $l + [ 'total' => $total ];
		}
		$sum      = round( $sum, 2 );
		$discount = 0.0;
		if ( 'percent' === $doc->discount_type ) {
			$discount = round( $sum * max( 0.0, min( 100.0, (float) $doc->discount_value ) ) / 100, 2 );
		} elseif ( 'amount' === $doc->discount_type ) {
			$discount = round( max( 0.0, min( (float) $doc->discount_value, $sum ) ), 2 );
		}
		$base = round( max( 0.0, $sum - $discount ), 2 );
		$rate = self::VAT_OPTIONS[ $doc->vat_key ] ?? 19.0;
		if ( 'net' === $doc->price_mode ) {
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
			'small_business' => 'ku' === $doc->vat_key,
		];
	}

	/** Nächste Nummer: Angebot A-JJJJ-NNNN, Rechnung R-JJJJ-NNNN (je Jahr fortlaufend). */
	public static function next_number( string $type = 'offer' ): string {
		global $wpdb;
		$prefix = 'invoice' === $type ? 'R' : 'A';
		$year   = current_time( 'Y' );
		$max    = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(CAST(SUBSTRING_INDEX(doc_number, '-', -1) AS UNSIGNED)) FROM %i WHERE doc_type = %s AND doc_number LIKE %s",
			Schema::table( 'rental_documents' ),
			'invoice' === $type ? 'invoice' : 'offer',
			$wpdb->esc_like( $prefix . '-' . $year . '-' ) . '%'
		) );
		return sprintf( '%s-%s-%04d', $prefix, $year, $max + 1 );
	}

	/** Gibt es die Rechnungsnummer schon (instanzweit, außer dem Dokument selbst)? */
	private static function number_taken( string $number, int $except_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM %i WHERE doc_type = 'invoice' AND doc_number = %s AND id <> %d LIMIT 1",
			Schema::table( 'rental_documents' ),
			$number,
			$except_id
		) );
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
		$row->lines = [];
		foreach ( is_array( $lines ) ? $lines : [] as $l ) {
			$row->lines[] = [
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
