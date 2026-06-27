<?php
namespace ProjectPrepper;

defined( 'ABSPATH' ) || exit;

/**
 * Plattform-/Instanz-Konfiguration (docs/06 — „Einstellen"-Seite, die der Admin
 * direkt nach der Installation sucht): Identität & Zweck, Ökonomie-Modell und
 * Rechtliches (AGB). Bewusst getrennt von der „Plattform"-Aufsichtsseite (KPIs/
 * Aktivität).
 *
 * Identität (Name/Thema/PLZ/Kontakt) wohnt jetzt HIER; die Föderation liest sie
 * von hier (`public_profile`). Solange nichts gespeichert ist, werden Thema/PLZ/
 * Kontakt als Default aus der alten Föderations-Option übernommen (Kontinuität).
 *
 * Ökonomie ist vorerst **deklarativ** (das Modell wird festgelegt) — Zahlungs-
 * abwicklung/Tracking ist ein eigener späterer Schritt (§10.2). AGB-Text + Version
 * werden hier gepflegt; die Akzeptanz-Durchsetzung im Portal folgt separat (§10.4).
 */
class Platform {

	const OPTION         = 'pp_platform';
	const ECONOMY_MODELS = [ 'free', 'donation', 'membership', 'fees', 'pro' ];

	public static function defaults(): array {
		$fed = get_option( 'pp_federation', [] );
		$fed = is_array( $fed ) ? $fed : [];
		return [
			'name'          => get_bloginfo( 'name' ),
			'purpose'       => '',
			'topic'         => (string) ( $fed['topic'] ?? '' ),
			'postal_code'   => (string) ( $fed['postal_code'] ?? '' ),
			'contact_email' => (string) ( $fed['contact_email'] ?? '' ),
			'economy'       => [
				'model'    => 'free',
				'amount'   => '',
				'interval' => 'year',
				'currency' => 'EUR',
				'note'     => '',
			],
			'legal'         => [
				'agb_text'           => '',
				'agb_version'        => 0,
				'require_acceptance' => false,
			],
			// Betreiber-/Rechtsangaben — speisen Impressum + Datenschutz (Shortcodes
			// [pp_impressum] / [pp_datenschutz]). Daten liegen in der DB, nicht im
			// Seiten- oder Theme-Text.
			'imprint'       => [
				'operator'    => '',          // Name/Firma/Verein (§ 5 DDG)
				'street'      => '',          // Straße + Hausnummer
				'postal_code' => '',          // PLZ
				'city'        => '',          // Ort
				'country'     => 'Deutschland',
				'email'       => '',          // Kontakt-E-Mail (Impressum/Datenschutz)
				'phone'       => '',          // optional
				'legal_form'  => '',          // optional: Rechtsform/Register/Verein/Vertretung
				'hosting'     => '',          // optional: Hosting-Anbieter (Datenschutz)
			],
		];
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, [] );
		$saved = is_array( $saved ) ? $saved : [];
		$d     = self::defaults();
		// Zwei Ebenen tief mergen (economy/legal sind verschachtelt).
		$out = array_merge( $d, $saved );
		$out['economy'] = array_merge( $d['economy'], is_array( $saved['economy'] ?? null ) ? $saved['economy'] : [] );
		$out['legal']   = array_merge( $d['legal'], is_array( $saved['legal'] ?? null ) ? $saved['legal'] : [] );
		$out['imprint'] = array_merge( $d['imprint'], is_array( $saved['imprint'] ?? null ) ? $saved['imprint'] : [] );
		return $out;
	}

	/**
	 * Einmalige Übernahme der Identität aus der alten Föderations-Option, falls die
	 * Plattform noch nie gespeichert wurde (Migration v0.70.0). Verhindert, dass
	 * Thema/PLZ/Kontakt verloren gehen, wenn die Föderation zuerst gespeichert wird.
	 */
	public static function maybe_seed(): void {
		if ( null !== get_option( self::OPTION, null ) ) {
			return; // bereits konfiguriert.
		}
		$fed = get_option( 'pp_federation', [] );
		$fed = is_array( $fed ) ? $fed : [];
		if ( '' === (string) ( $fed['topic'] ?? '' ) && '' === (string) ( $fed['postal_code'] ?? '' ) && '' === (string) ( $fed['contact_email'] ?? '' ) ) {
			return; // nichts zu übernehmen.
		}
		update_option( self::OPTION, self::defaults() ); // defaults() zieht die Werte aus pp_federation.
	}

	/* ---------- Von der Föderation genutzte Identitäts-Getter ---------- */

	public static function name(): string {
		$n = trim( (string) self::all()['name'] );
		return '' !== $n ? $n : (string) get_bloginfo( 'name' );
	}
	public static function topic(): string {
		return (string) self::all()['topic'];
	}
	public static function postal_code(): string {
		return (string) self::all()['postal_code'];
	}
	public static function contact_email(): string {
		return (string) self::all()['contact_email'];
	}

	/* ---------- AGB / Bedingungen ---------- */

	public static function terms_required(): bool {
		$l = self::all()['legal'];
		return ! empty( $l['require_acceptance'] ) && '' !== trim( (string) $l['agb_text'] );
	}
	public static function terms_version(): int {
		return (int) self::all()['legal']['agb_version'];
	}
	public static function terms_text(): string {
		return (string) self::all()['legal']['agb_text'];
	}

	/* ---------- Betreiber-/Rechtsangaben (Impressum/Datenschutz) ---------- */

	public static function imprint(): array {
		return self::all()['imprint'];
	}
	/** True, wenn die Pflichtfelder (Betreiber + Ort) gepflegt sind. */
	public static function imprint_ready(): bool {
		$i = self::imprint();
		return '' !== trim( (string) $i['operator'] ) && '' !== trim( (string) $i['city'] );
	}

	/**
	 * Eingabe (JSON oder $_POST-artig) säubern + speichern. Die AGB-Version wird
	 * automatisch erhöht, wenn sich der AGB-Text ändert (für die spätere Akzeptanz-
	 * Prüfung). Gibt die gespeicherten Werte zurück. Permission prüft der Aufrufer.
	 *
	 * @return array
	 */
	public static function save_from( array $in ): array {
		$cur = self::all();

		$eco_in   = is_array( $in['economy'] ?? null ) ? $in['economy'] : [];
		$model    = (string) ( $eco_in['model'] ?? 'free' );
		if ( ! in_array( $model, self::ECONOMY_MODELS, true ) ) {
			$model = 'free';
		}
		$interval = (string) ( $eco_in['interval'] ?? 'year' );
		if ( ! in_array( $interval, [ 'year', 'month' ], true ) ) {
			$interval = 'year';
		}

		$legal_in = is_array( $in['legal'] ?? null ) ? $in['legal'] : [];
		$agb_text = sanitize_textarea_field( (string) ( $legal_in['agb_text'] ?? '' ) );
		$version  = (int) ( $cur['legal']['agb_version'] ?? 0 );
		// Version hochzählen, wenn sich der Text geändert hat (und nicht leer ist).
		if ( $agb_text !== (string) ( $cur['legal']['agb_text'] ?? '' ) && '' !== $agb_text ) {
			$version = max( 1, $version + 1 );
		}

		$data = [
			'name'          => sanitize_text_field( (string) ( $in['name'] ?? '' ) ),
			'purpose'       => sanitize_textarea_field( (string) ( $in['purpose'] ?? '' ) ),
			'topic'         => sanitize_text_field( (string) ( $in['topic'] ?? '' ) ),
			'postal_code'   => sanitize_text_field( (string) ( $in['postal_code'] ?? '' ) ),
			'contact_email' => sanitize_email( (string) ( $in['contact_email'] ?? '' ) ),
			'economy'       => [
				'model'    => $model,
				'amount'   => self::clean_amount( (string) ( $eco_in['amount'] ?? '' ) ),
				'interval' => $interval,
				'currency' => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $eco_in['currency'] ?? 'EUR' ) ), 0, 3 ) ) ?: 'EUR',
				'note'     => sanitize_textarea_field( (string) ( $eco_in['note'] ?? '' ) ),
			],
			'legal'         => [
				'agb_text'           => $agb_text,
				'agb_version'        => $version,
				'require_acceptance' => ! empty( $legal_in['require_acceptance'] ),
			],
			'imprint'       => self::clean_imprint( is_array( $in['imprint'] ?? null ) ? $in['imprint'] : [] ),
		];
		update_option( self::OPTION, $data );
		return self::all();
	}

	private static function clean_amount( string $v ): string {
		$v = trim( str_replace( ',', '.', $v ) );
		return is_numeric( $v ) ? (string) round( (float) $v, 2 ) : '';
	}

	private static function clean_imprint( array $in ): array {
		return [
			'operator'    => sanitize_text_field( (string) ( $in['operator'] ?? '' ) ),
			'street'      => sanitize_text_field( (string) ( $in['street'] ?? '' ) ),
			'postal_code' => sanitize_text_field( (string) ( $in['postal_code'] ?? '' ) ),
			'city'        => sanitize_text_field( (string) ( $in['city'] ?? '' ) ),
			'country'     => sanitize_text_field( (string) ( $in['country'] ?? '' ) ),
			'email'       => sanitize_email( (string) ( $in['email'] ?? '' ) ),
			'phone'       => sanitize_text_field( (string) ( $in['phone'] ?? '' ) ),
			'legal_form'  => sanitize_textarea_field( (string) ( $in['legal_form'] ?? '' ) ),
			'hosting'     => sanitize_text_field( (string) ( $in['hosting'] ?? '' ) ),
		];
	}
}
