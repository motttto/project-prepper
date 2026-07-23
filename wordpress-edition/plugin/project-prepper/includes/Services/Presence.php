<?php
namespace ProjectPrepper\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Presence / „Wer ist online" — leichtgewichtiges Pendant zu den Supabase-
 * Realtime-Presence-Kanälen der App (use-presence.ts). WordPress hat kein
 * Realtime-Backend, daher ein Polling-Ansatz (Heartbeat):
 *
 *   - Das Portal-JS pingt regelmäßig einen REST-Endpoint (touch()), der pro User
 *     einen „zuletzt gesehen"-Zeitstempel als user_meta `pp_last_seen` ablegt.
 *   - is_online() / online_user_ids() lesen diesen Stempel; „online" = innerhalb
 *     der letzten WINDOW Sekunden (3 Minuten) gesehen.
 *
 * Bewusst schlank: keine eigene Tabelle (nur user_meta), keine Live-DOM-Updates.
 * Die Anzeige ist eine Momentaufnahme beim Seitenaufbau und aktualisiert sich
 * beim nächsten Seitenaufruf.
 *
 * Datenschutz: touch() setzt ausschließlich den eigenen Stempel; gelesen wird
 * Präsenz nur für eine vom Aufrufer übergebene Nutzerliste (in der Praxis die
 * Mitglieder der eigenen Kollektive) — kein instanzweites Leaken.
 */
class Presence {

	/** user_meta-Schlüssel für den Unix-Timestamp des letzten Heartbeats. */
	const META_KEY = 'pp_last_seen';

	/** Online-Fenster in Sekunden (3 Minuten). */
	const WINDOW = 180;

	/**
	 * Heartbeat: eigenen „zuletzt gesehen"-Stempel auf jetzt setzen.
	 */
	public static function touch( int $user_id ): void {
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::META_KEY, time() );
		}
	}

	/**
	 * Ist der einzelne User innerhalb des Online-Fensters gesehen worden?
	 */
	public static function is_online( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$last = (int) get_user_meta( $user_id, self::META_KEY, true );
		return $last > 0 && ( time() - $last ) <= self::WINDOW;
	}

	/**
	 * Batch: welche der übergebenen User sind gerade online? Ein einzelner
	 * Meta-Query statt N Einzelabfragen.
	 *
	 * @param array<int> $user_ids
	 * @return array<int> Teilmenge der Eingabe, die online ist.
	 */
	public static function online_user_ids( array $user_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( ! $ids ) {
			return [];
		}

		$cutoff       = time() - self::WINDOW;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// $placeholders besteht ausschließlich aus '%d' (array_fill), die IDs und
		// der Cutoff laufen als Platzhalter → voll prepared. Der interpolierte
		// Platzhalter-String triggert dennoch die PreparedSQL-Sniffs.
		// phpcs:disable WordPress.DB.PreparedSQL
		$sql = $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta}
			 WHERE meta_key = %s
			   AND user_id IN ($placeholders)
			   AND CAST( meta_value AS UNSIGNED ) >= %d",
			array_merge( [ self::META_KEY ], $ids, [ $cutoff ] )
		);
		$rows = $wpdb->get_col( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Anzahl gerade online befindlicher Mitglieder einer Gruppe.
	 */
	public static function online_count_for_group( int $group_id ): int {
		$members = \ProjectPrepper\Services\Groups::members( $group_id );
		$ids     = array_map( static fn( $m ) => (int) $m->user_id, $members );
		return count( self::online_user_ids( $ids ) );
	}
}
