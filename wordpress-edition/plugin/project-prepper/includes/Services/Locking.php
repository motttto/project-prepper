<?php
namespace ProjectPrepper\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Serialisiert Buchungsvorgänge (Skill /wp-audit, Fund AVAIL-04).
 *
 * Zwischen „Verfügbarkeit prüfen" und „Buchung schreiben" lag nichts: Zwei
 * gleichzeitige Anfragen lasen beide denselben freien Bestand und schrieben
 * beide — dasselbe Einzelstück war doppelt vergeben. Die Prüfung summiert über
 * vier Tabellen, lässt sich also nicht mit einer bedingten WHERE-Bedingung
 * absichern wie ein einzelner Statuswechsel.
 *
 * Bewusst EIN Schloss für alle Buchungen statt eines je Artikel: Eine Instanz
 * ist ein Kollektiv mit wenigen Mitgliedern — gleichzeitige Buchungen sind die
 * Ausnahme, und ein einziges Schloss braucht weder eine Sperr-Reihenfolge
 * (Deadlock-Gefahr bei mehreren Artikeln) noch Transaktionen.
 */
class Locking {

	/** Sekunden, die auf das Schloss gewartet wird. */
	const TIMEOUT = 5;

	/**
	 * Den Rückruf ausführen, während das Buchungs-Schloss gehalten wird.
	 *
	 * Lässt sich das Schloss nicht holen (Zeitüberschreitung, fehlende
	 * Datenbank-Unterstützung), läuft der Rückruf trotzdem — dann gilt wieder
	 * das bisherige Verhalten. Eine Buchung am Schloss scheitern zu lassen wäre
	 * für den seltenen Fall die schlechtere Antwort.
	 *
	 * @param callable $fn
	 * @return mixed Rückgabewert des Rückrufs.
	 */
	public static function serialized( callable $fn ) {
		global $wpdb;
		$name = self::lock_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Sperr-Primitive, kein Cache möglich.
		$got = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::TIMEOUT ) );
		try {
			return $fn();
		} finally {
			if ( 1 === $got ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- siehe oben.
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			}
		}
	}

	/**
	 * Schlossname, eindeutig je Datenbank und Tabellen-Präfix (mehrere
	 * Installationen auf einem Server stören sich nicht). MySQL erlaubt 64
	 * Zeichen.
	 */
	private static function lock_name(): string {
		global $wpdb;
		return 'pp_booking_' . substr( md5( $wpdb->prefix . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) ), 0, 20 );
	}
}
