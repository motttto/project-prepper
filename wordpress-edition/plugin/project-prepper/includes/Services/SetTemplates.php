<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Set-Vorlagen (Schema 0.45.0) — Feedback: „Systembundles anlegen, z. B.
 * Bubble, Haze, Eurokiste".
 *
 * Anders als ein Set (docs/07, {@see Bundles}) gehört eine Vorlage keinem
 * Gerät und keinem einzelnen Eigentümer: Sie ist eine Packliste des Kollektivs
 * („Bubble-System: 1 Bubble-Maschine, 1 Haze, 1 Eurokiste"), deren Zeilen aus
 * Geräten VERSCHIEDENER Mitglieder erfüllt werden dürfen. Erst beim Einbuchen
 * wählt man je Zeile ein konkretes Gerät aus dem Pool; danach sind es ganz
 * normale Buchungszeilen — Verfügbarkeit, Freigaben durch die Eigentümer und
 * Packliste laufen unverändert.
 *
 * Eigentum: owner_group_id (Vorlage des Kollektivs, jedes Mitglied darf sie
 * pflegen) XOR owner_user_id (persönliche Vorlage im Solo-Bereich).
 */
class SetTemplates {

	const MAX_LINES = 30;

	/** Persönliche Vorlagen verschwinden mit ihrem Mitglied (Gruppe/Artikel: direkte Aufrufe). */
	public static function init(): void {
		add_action( 'deleted_user', [ self::class, 'on_user_deleted' ], 20, 1 );
	}

	/**
	 * Vorlagen eines Arbeitsbereichs inkl. Zeilen.
	 *
	 * @return array<object> je Vorlage ->lines (array<object>).
	 */
	public static function for_workspace( int $group_id, int $user_id ): array {
		global $wpdb;
		$rows = $group_id > 0
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE owner_group_id = %d ORDER BY name ASC', Schema::table( 'set_templates' ), $group_id ) )
			: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE owner_user_id = %d AND owner_group_id IS NULL ORDER BY name ASC', Schema::table( 'set_templates' ), $user_id ) );
		$rows = $rows ?: [];
		if ( ! $rows ) {
			return [];
		}
		$ids   = array_map( static fn( $r ) => (int) $r->id, $rows );
		$place = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Platzhalter werden oben dynamisch erzeugt.
		$lines = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM %i WHERE template_id IN ($place) ORDER BY sort_order ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nur Platzhalter.
			array_merge( [ Schema::table( 'set_template_lines' ) ], $ids )
		) ) ?: [];
		$by = [];
		foreach ( $lines as $l ) {
			$by[ (int) $l->template_id ][] = $l;
		}
		foreach ( $rows as $r ) {
			$r->lines = $by[ (int) $r->id ] ?? [];
		}
		return $rows;
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'set_templates' ), $id ) );
		if ( ! $row ) {
			return null;
		}
		$row->lines = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE template_id = %d ORDER BY sort_order ASC, id ASC',
			Schema::table( 'set_template_lines' ),
			$id
		) ) ?: [];
		return $row;
	}

	/** Darf der User die Vorlage sehen, einbuchen und bearbeiten? */
	public static function can_use( int $user_id, object $tpl ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( ! empty( $tpl->owner_group_id ) ) {
			return Groups::is_member( (int) $tpl->owner_group_id, $user_id );
		}
		return (int) $tpl->owner_user_id === $user_id;
	}

	/**
	 * Vorlage anlegen ($id = 0) oder ändern. Die Zeilen werden komplett ersetzt
	 * (das Formular schickt immer den Wunschzustand).
	 *
	 * @param array $data name, description, lines[] (label, quantity, match_term, item_id).
	 * @return int|WP_Error Vorlagen-ID.
	 */
	public static function save( int $user_id, int $group_id, array $data, int $id = 0 ) {
		global $wpdb;
		$name = trim( sanitize_text_field( (string) ( $data['name'] ?? '' ) ) );
		if ( '' === $name ) {
			return new WP_Error( 'pp_missing_name', __( 'Please enter a name for the template.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$lines = [];
		foreach ( (array) ( $data['lines'] ?? [] ) as $line ) {
			$label = trim( sanitize_text_field( (string) ( $line['label'] ?? '' ) ) );
			if ( '' === $label ) {
				continue;
			}
			$lines[] = [
				'label'      => mb_substr( $label, 0, 190 ),
				'quantity'   => max( 1, min( 999, (int) ( $line['quantity'] ?? 1 ) ) ),
				'match_term' => mb_substr( trim( sanitize_text_field( (string) ( $line['match_term'] ?? '' ) ) ), 0, 190 ),
				'item_id'    => ( (int) ( $line['item_id'] ?? 0 ) ) ?: null,
			];
			if ( count( $lines ) >= self::MAX_LINES ) {
				break;
			}
		}
		if ( ! $lines ) {
			return new WP_Error( 'pp_template_empty', __( 'A template needs at least one line with a name.', 'project-prepper' ), [ 'status' => 400 ] );
		}
		$table = Schema::table( 'set_templates' );
		$now   = current_time( 'mysql' );
		if ( $id > 0 ) {
			$tpl = self::get( $id );
			if ( ! $tpl || ! self::can_use( $user_id, $tpl ) ) {
				return new WP_Error( 'pp_forbidden', __( 'You are not allowed to change this template.', 'project-prepper' ), [ 'status' => 403 ] );
			}
			$group_id = (int) ( $tpl->owner_group_id ?? 0 );
		}
		// Bevorzugtes Gerät nur aus dem Pool der Vorlage (Audit ACC-20): Kollektiv =
		// mit ihm geteilt, Solo = eigenes. Alles andere wird still verworfen.
		foreach ( $lines as $i => $line ) {
			$iid = (int) ( $line['item_id'] ?? 0 );
			if ( $iid <= 0 ) {
				continue;
			}
			$ok = $group_id > 0
				? in_array( $group_id, MemberInventory::shared_group_ids( $iid ), true )
				: MemberInventory::owns( $user_id, $iid );
			if ( ! $ok ) {
				$lines[ $i ]['item_id'] = null;
			}
		}
		if ( $id > 0 ) {
			$wpdb->update( $table, [
				'name'        => mb_substr( $name, 0, 190 ),
				'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
				'updated_at'  => $now,
			], [ 'id' => $id ] );
		} else {
			if ( $group_id > 0 && ! Groups::is_member( $group_id, $user_id ) ) {
				return new WP_Error( 'pp_forbidden', __( 'You are not allowed to change this template.', 'project-prepper' ), [ 'status' => 403 ] );
			}
			$wpdb->insert( $table, [
				'owner_group_id' => $group_id > 0 ? $group_id : null,
				'owner_user_id'  => $group_id > 0 ? null : $user_id,
				'name'           => mb_substr( $name, 0, 190 ),
				'description'    => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
				'created_by'     => $user_id,
				'created_at'     => $now,
				'updated_at'     => $now,
			] );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) {
				return new WP_Error( 'pp_create_failed', __( 'The template could not be saved.', 'project-prepper' ), [ 'status' => 500 ] );
			}
		}
		$lt = Schema::table( 'set_template_lines' );
		$wpdb->delete( $lt, [ 'template_id' => $id ], [ '%d' ] );
		foreach ( $lines as $i => $line ) {
			$wpdb->insert( $lt, array_merge( $line, [ 'template_id' => $id, 'sort_order' => $i ] ) );
		}
		ActivityLog::log( 'set_template_saved', 'set_template', $id, [ 'lines' => count( $lines ) ] );
		return $id;
	}

	/** @return true|WP_Error */
	public static function delete( int $user_id, int $id ) {
		$tpl = self::get( $id );
		if ( ! $tpl || ! self::can_use( $user_id, $tpl ) ) {
			return new WP_Error( 'pp_forbidden', __( 'You are not allowed to change this template.', 'project-prepper' ), [ 'status' => 403 ] );
		}
		self::purge( [ $id ] );
		ActivityLog::log( 'set_template_deleted', 'set_template', $id, [ 'name' => $tpl->name ] );
		return true;
	}

	/**
	 * Geräte aus dem Pool, die zu einer Zeile passen (Suchbegriff, sonst die
	 * Bezeichnung, gegen Name/Modell/Hersteller/Tags/Kategorie). Das bevorzugte
	 * Gerät steht immer vorne.
	 *
	 * @param array<object> $pool
	 * @return array<object>
	 */
	public static function matches( object $line, array $pool ): array {
		$term = mb_strtolower( trim( (string) ( '' !== trim( (string) $line->match_term ) ? $line->match_term : $line->label ) ) );
		$out  = [];
		foreach ( $pool as $it ) {
			if ( (int) $it->id === (int) $line->item_id ) {
				array_unshift( $out, $it );
				continue;
			}
			if ( '' === $term ) {
				continue;
			}
			$hay = mb_strtolower( implode( ' ', [ $it->name ?? '', $it->model ?? '', $it->manufacturer ?? '', $it->category_name ?? '', implode( ' ', (array) ( $it->tags ?? [] ) ) ] ) );
			foreach ( preg_split( '/\s+/u', $term ) as $word ) {
				if ( '' !== $word && false !== mb_strpos( $hay, $word ) ) {
					$out[] = $it;
					break;
				}
			}
		}
		return $out;
	}

	/* ---------- Aufräumen ---------- */

	public static function on_user_deleted( int $user_id ): void {
		global $wpdb;
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE owner_user_id = %d', Schema::table( 'set_templates' ), $user_id ) ) );
		self::purge( $ids );
	}

	public static function on_group_deleted( int $group_id ): void {
		global $wpdb;
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE owner_group_id = %d', Schema::table( 'set_templates' ), $group_id ) ) );
		self::purge( $ids );
	}

	/** Gelöschtes Gerät: als bevorzugtes Gerät austragen (die Zeile bleibt). */
	public static function on_item_deleted( int $item_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET item_id = NULL WHERE item_id = %d', Schema::table( 'set_template_lines' ), $item_id ) );
	}

	/** @param array<int> $ids */
	private static function purge( array $ids ): void {
		global $wpdb;
		foreach ( array_filter( array_map( 'intval', $ids ) ) as $id ) {
			$wpdb->delete( Schema::table( 'set_template_lines' ), [ 'template_id' => $id ], [ '%d' ] );
			$wpdb->delete( Schema::table( 'set_templates' ), [ 'id' => $id ], [ '%d' ] );
		}
	}
}
