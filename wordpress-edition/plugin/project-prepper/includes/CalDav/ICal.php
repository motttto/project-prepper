<?php
namespace ProjectPrepper\CalDav;

defined( 'ABSPATH' ) || exit;

/**
 * Minimaler iCalendar-Helfer für den CalDAV-Server (CalDav\Server): parst ein
 * einzelnes VEVENT aus einem PUT-Body und serialisiert eine pp_calendar_events-
 * Zeile zurück zu VCALENDAR/VEVENT.
 *
 * Bewusst schlank — keine externe Bibliothek, keine VTIMEZONE-Verarbeitung.
 * Zeiten sind „floating local" wie der read-only iCal-Feed (CalendarController):
 * die Wandzeit aus DTSTART/DTEND wird 1:1 übernommen, ein Z/TZID-Suffix
 * pragmatisch ignoriert. VALUE=DATE (bzw. ein 8-stelliger Wert) = ganztägig.
 */
class ICal {

	/* ============ Parsen (iCal → Feld-Map) ============ */

	/**
	 * Erstes VEVENT eines iCal-Bodys nach Feldern auflösen.
	 *
	 * @return array<string,mixed>|null Felder uid/title/description/location/
	 *   date_from/date_to/time_start/time_end (date_to null bei Einzeltag,
	 *   time_* '' bei ganztägig). NULL wenn kein brauchbares VEVENT.
	 */
	public static function parse_vevent( string $body ): ?array {
		$lines = self::unfold( $body );
		$in    = false;
		$props = []; // KEY(uppercase) => [ 'params' => [...], 'value' => string ]

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			if ( 0 === strcasecmp( $trimmed, 'BEGIN:VEVENT' ) ) {
				$in = true;
				continue;
			}
			if ( 0 === strcasecmp( $trimmed, 'END:VEVENT' ) ) {
				break;
			}
			if ( ! $in || '' === $trimmed ) {
				continue;
			}
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$name_part = substr( $line, 0, $colon );
			$value     = substr( $line, $colon + 1 );
			$segments  = explode( ';', $name_part );
			$key       = strtoupper( trim( (string) array_shift( $segments ) ) );
			$params    = [];
			foreach ( $segments as $seg ) {
				$eq = strpos( $seg, '=' );
				if ( false !== $eq ) {
					$params[ strtoupper( substr( $seg, 0, $eq ) ) ] = strtoupper( trim( substr( $seg, $eq + 1 ), '"' ) );
				}
			}
			// Erstes Vorkommen gewinnt (DTSTART/UID/SUMMARY sind einmalig).
			if ( '' !== $key && ! isset( $props[ $key ] ) ) {
				$props[ $key ] = [ 'params' => $params, 'value' => $value ];
			}
		}

		if ( ! isset( $props['DTSTART'] ) ) {
			return null;
		}
		$dtstart = self::parse_dt( $props['DTSTART'] );
		if ( null === $dtstart ) {
			return null;
		}
		$dtend = isset( $props['DTEND'] ) ? self::parse_dt( $props['DTEND'] ) : null;

		$all_day    = $dtstart['all_day'];
		$date_from  = $dtstart['date'];
		$time_start = $all_day ? '' : $dtstart['time'];
		$date_to    = null;
		$time_end   = '';

		if ( $all_day ) {
			// DTEND ist bei ganztägig exklusiv → letzter Tag = DTEND − 1.
			if ( $dtend && $dtend['all_day'] ) {
				$last = gmdate( 'Y-m-d', (int) strtotime( $dtend['date'] . ' -1 day' ) );
				if ( $last > $date_from ) {
					$date_to = $last;
				}
			}
		} elseif ( $dtend && ! $dtend['all_day'] ) {
			$time_end = $dtend['time'];
			if ( $dtend['date'] > $date_from ) {
				$date_to = $dtend['date'];
			}
		}

		return [
			'uid'         => isset( $props['UID'] ) ? self::unescape( $props['UID']['value'] ) : '',
			'title'       => isset( $props['SUMMARY'] ) ? self::unescape( $props['SUMMARY']['value'] ) : '',
			'description' => isset( $props['DESCRIPTION'] ) ? self::unescape( $props['DESCRIPTION']['value'] ) : '',
			'location'    => isset( $props['LOCATION'] ) ? self::unescape( $props['LOCATION']['value'] ) : '',
			'date_from'   => $date_from,
			'date_to'     => $date_to,
			'time_start'  => $time_start,
			'time_end'    => $time_end,
		];
	}

	/**
	 * Zeilen entfalten (RFC 5545: eine Fortsetzungszeile beginnt mit Space/Tab).
	 *
	 * @return array<string>
	 */
	private static function unfold( string $raw ): array {
		$raw   = str_replace( [ "\r\n", "\r" ], "\n", $raw );
		$lines = explode( "\n", $raw );
		$out   = [];
		foreach ( $lines as $line ) {
			if ( '' !== $line && ( ' ' === $line[0] || "\t" === $line[0] ) && $out ) {
				$out[ count( $out ) - 1 ] .= substr( $line, 1 );
			} else {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * DTSTART/DTEND parsen.
	 *
	 * @param array{params:array<string,string>,value:string} $prop
	 * @return array{all_day:bool,date:string,time:string}|null
	 */
	private static function parse_dt( array $prop ): ?array {
		$val     = trim( $prop['value'] );
		$is_date = isset( $prop['params']['VALUE'] ) && 'DATE' === $prop['params']['VALUE'];

		if ( $is_date || preg_match( '/^\d{8}$/', $val ) ) {
			if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})/', $val, $m ) ) {
				return null;
			}
			return [ 'all_day' => true, 'date' => "{$m[1]}-{$m[2]}-{$m[3]}", 'time' => '' ];
		}
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})/', $val, $m ) ) {
			return [ 'all_day' => false, 'date' => "{$m[1]}-{$m[2]}-{$m[3]}", 'time' => "{$m[4]}:{$m[5]}" ];
		}
		return null;
	}

	/** RFC-5545-Escapes eines Textwerts zurücknehmen. */
	private static function unescape( string $v ): string {
		return str_replace(
			[ '\\N', '\\n', '\\,', '\\;', '\\\\' ],
			[ "\n", "\n", ',', ';', '\\' ],
			$v
		);
	}

	/* ============ Serialisieren (Feld-Map → iCal) ============ */

	/** Textwert für iCal escapen (Komma/Semikolon/Backslash/Zeilenumbruch). */
	public static function escape( string $v ): string {
		return str_replace( [ "\r\n", "\n", "\r" ], '\\n', addcslashes( $v, ",;\\" ) );
	}

	/** Deterministische UID aus der Event-ID (Portal-Termine ohne eigene UID). */
	public static function derive_uid( int $id, string $host ): string {
		return 'pp-event-' . $id . '@' . $host;
	}

	/** UID eines Termins: gespeicherte UID, sonst aus der ID abgeleitet. */
	public static function event_uid( object $e, string $host ): string {
		$uid = isset( $e->uid ) ? (string) $e->uid : '';
		return '' !== $uid ? $uid : self::derive_uid( (int) $e->id, $host );
	}

	/** Stabiler ETag aus ID + updated_at (in Anführungszeichen = strong). */
	public static function etag( object $e ): string {
		return '"' . md5( (int) $e->id . '|' . (string) ( $e->updated_at ?? '' ) ) . '"';
	}

	/** Eine pp_calendar_events-Zeile als komplettes VCALENDAR (ein VEVENT). */
	public static function to_vcalendar( object $e, string $host ): string {
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Project Prepper//CalDAV//EN',
			'CALSCALE:GREGORIAN',
		];
		$lines   = array_merge( $lines, self::vevent_lines( $e, $host ) );
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", array_map( [ self::class, 'fold' ], $lines ) ) . "\r\n";
	}

	/**
	 * VEVENT-Zeilen eines Termins (ohne VCALENDAR-Rahmen).
	 *
	 * @return array<string>
	 */
	public static function vevent_lines( object $e, string $host ): array {
		$lines   = [ 'BEGIN:VEVENT' ];
		$lines[] = 'UID:' . self::event_uid( $e, $host );
		$stamp   = strtotime( (string) ( $e->updated_at ?? 'now' ) ) ?: time();
		$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z', $stamp );

		$date_from = (string) $e->date_from;
		$date_to   = (string) ( $e->date_to ?: $e->date_from );
		$has_time  = '' !== (string) $e->time_start;

		if ( $has_time ) {
			$start_ts = strtotime( $date_from . ' ' . $e->time_start );
			$end_ts   = '' !== (string) $e->time_end
				? strtotime( $date_to . ' ' . $e->time_end )
				: strtotime( '+1 hour', strtotime( $date_to . ' ' . $e->time_start ) );
			if ( $end_ts <= $start_ts ) {
				$end_ts = strtotime( '+1 hour', $start_ts );
			}
			// Floating local — Clients lesen die Zeit in ihrer Zeitzone.
			$lines[] = 'DTSTART:' . gmdate( 'Ymd\THis', $start_ts );
			$lines[] = 'DTEND:' . gmdate( 'Ymd\THis', $end_ts );
		} else {
			$lines[] = 'DTSTART;VALUE=DATE:' . gmdate( 'Ymd', strtotime( $date_from ) );
			$lines[] = 'DTEND;VALUE=DATE:' . gmdate( 'Ymd', strtotime( $date_to . ' +1 day' ) ); // exklusiv
		}

		$lines[] = 'SUMMARY:' . self::escape( (string) $e->title );
		if ( '' !== (string) $e->location ) {
			$lines[] = 'LOCATION:' . self::escape( (string) $e->location );
		}
		if ( '' !== trim( (string) $e->description ) ) {
			$lines[] = 'DESCRIPTION:' . self::escape( (string) $e->description );
		}
		$lines[] = 'END:VEVENT';
		return $lines;
	}

	/** Zeile auf 75 Oktett falten (RFC 5545, Fortsetzung mit führendem Space). */
	private static function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out  = substr( $line, 0, 75 );
		$rest = substr( $line, 75 );
		while ( '' !== (string) $rest ) {
			$out .= "\r\n " . substr( $rest, 0, 74 );
			$rest = substr( $rest, 74 );
		}
		return $out;
	}
}
