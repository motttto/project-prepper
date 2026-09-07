<?php
namespace ProjectPrepper;

defined( 'ABSPATH' ) || exit;

/**
 * Betreiber-Einstellungen des Verleihs (wp-admin → Project Prepper → Einstellungen).
 *
 * Absichtlich `wp_options` statt eigener Tabelle: Es sind wenige, instanzweite
 * Schalter — sie ändern kein Schema und müssen bei einem Update nicht migriert
 * werden. Diese Klasse ist die EINZIGE Stelle, an der die Options-Keys und ihre
 * Standardwerte stehen; REST-Controller, Portal und Services lesen ausschließlich
 * über die Methoden hier.
 *
 * Standardwerte sind so gewählt, dass eine bestehende Instanz sich nach dem
 * Update wie erwartet verhält: Kollektiv-Verleihe und Zeitstatus sind AN (das
 * ist der Sinn des Updates), Rüstzeiten sind 0 (= aus, niemand hatte sie vorher).
 */
class Settings {

	/** Verleihe eines Kollektivs allen seinen Mitgliedern zeigen (nur lesend). */
	const COLLECTIVE_RENTALS = 'pp_collective_rentals_visible';

	/** Zeitstatus am Artikel („frei bis …", „verliehen bis …", „frei ab …"). */
	const ITEM_TIME_STATUS = 'pp_item_time_status';

	/** Rüstzeit VOR einer Ausleihe in Tagen (Vorbereitung, Aufbau, Test). */
	const BUFFER_BEFORE = 'pp_rental_buffer_before';

	/** Rüstzeit NACH einer Ausleihe in Tagen (Rückläufer prüfen, reinigen, laden). */
	const BUFFER_AFTER = 'pp_rental_buffer_after';

	/** Obergrenze je Rüstzeit — bewahrt vor Tippfehlern, die das Inventar lahmlegen. */
	const MAX_BUFFER_DAYS = 30;

	public static function collective_rentals_visible(): bool {
		return (bool) get_option( self::COLLECTIVE_RENTALS, true );
	}

	public static function item_time_status(): bool {
		return (bool) get_option( self::ITEM_TIME_STATUS, true );
	}

	/** Rüstzeit vor einer Ausleihe (0 = aus). */
	public static function buffer_before(): int {
		return self::clamp_days( get_option( self::BUFFER_BEFORE, 0 ) );
	}

	/** Rüstzeit nach einer Ausleihe (0 = aus). */
	public static function buffer_after(): int {
		return self::clamp_days( get_option( self::BUFFER_AFTER, 0 ) );
	}

	/** Sind überhaupt Rüstzeiten aktiv? Spart Arbeit an den Abfragen. */
	public static function has_buffer(): bool {
		return self::buffer_before() > 0 || self::buffer_after() > 0;
	}

	/** Wert für die Speicherung säubern (0 … MAX_BUFFER_DAYS). */
	public static function clamp_days( $value ): int {
		return max( 0, min( self::MAX_BUFFER_DAYS, (int) $value ) );
	}

	/* ===================== Funktionsbereiche an/aus ===================== */

	/**
	 * Feature-Schalter (v0.145.0): Jeder Funktionsbereich des Portals lässt sich
	 * im wp-admin abschalten. Ein Schalter greift an DREI Stellen — Menü,
	 * Ansicht per URL und Aktionen im Dispatcher — sonst wäre er nur Deko.
	 * Dashboard und Kollektive sind Grundgerüst und nicht schaltbar.
	 * Speicherung als EIN Array in wp_options; fehlende Schlüssel = an.
	 */
	const FEATURES = 'pp_features';

	/** @return array<string,bool> Schlüssel → Standard (alles an). */
	public static function feature_defaults(): array {
		return [
			'inventory' => true,
			'lending'   => true,
			'projects'  => true,
			'inquiries' => true,
			'calendar'  => true,
			'costs'     => true,
			'polls'     => true,
			'network'   => true,
			'howto'     => true,
		];
	}

	/** Menschenlesbare Namen für die Einstellungsseite (Reihenfolge = Anzeige). */
	public static function feature_labels(): array {
		return [
			'inventory' => __( 'Inventory', 'project-prepper' ),
			'lending'   => __( 'Lending & borrowing (rentals, loan requests, approvals)', 'project-prepper' ),
			'projects'  => __( 'Projects', 'project-prepper' ),
			'inquiries' => __( 'Inquiries', 'project-prepper' ),
			'calendar'  => __( 'Calendar', 'project-prepper' ),
			'costs'     => __( 'Costs', 'project-prepper' ),
			'polls'     => __( 'Polls', 'project-prepper' ),
			'network'   => __( 'Network (federation)', 'project-prepper' ),
			'howto'     => __( 'How the platform works', 'project-prepper' ),
		];
	}

	/** @return array<string,bool> Gespeicherte Schalter über den Standards. */
	public static function features(): array {
		$saved = get_option( self::FEATURES, [] );
		$out   = self::feature_defaults();
		if ( is_array( $saved ) ) {
			foreach ( $out as $key => $default ) {
				if ( array_key_exists( $key, $saved ) ) {
					$out[ $key ] = (bool) $saved[ $key ];
				}
			}
		}
		return $out;
	}

	/** Ist der Bereich an? Unbekannte Schlüssel gelten als an (nicht schaltbar). */
	public static function feature_on( string $key ): bool {
		$all = self::features();
		return array_key_exists( $key, $all ) ? $all[ $key ] : true;
	}

	/** Speichern — nur bekannte Schlüssel, alles andere wird ignoriert. */
	public static function save_features( array $in ): void {
		$clean = [];
		foreach ( array_keys( self::feature_defaults() ) as $key ) {
			$clean[ $key ] = ! empty( $in[ $key ] );
		}
		update_option( self::FEATURES, $clean );
	}
}
