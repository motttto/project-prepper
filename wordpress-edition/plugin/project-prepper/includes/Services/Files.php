<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Projekt-Dateien — Pendant zu project_files der App.
 *
 * Anders als die übrigen Listen-Tabs werden Dateien NICHT als Freitext
 * gespeichert, sondern als Verknüpfung auf ein WP-Attachment (Medienbibliothek):
 * eine Join-Zeile (project_id, attachment_id, title, sort_order). URL, Dateiname
 * und MIME-Typ kommen direkt aus dem Attachment (wp_get_attachment_url etc.),
 * werden also nicht dupliziert.
 *
 * Verwaiste Verknüpfungen (Attachment gelöscht → url = false) werden NICHT
 * stillschweigend übersprungen, sondern mit `missing = true` markiert — so kann
 * der Nutzer die tote Verknüpfung sichtbar entfernen, statt sie unbemerkt zu
 * verlieren.
 *
 * detach() löscht bewusst NUR die Join-Zeile, nie das Medium selbst (es könnte
 * anderswo genutzt werden).
 */
class Files {

	/**
	 * Verknüpfungen eines Projekts, je mit Attachment-Daten angereichert.
	 *
	 * @return array<int,object>
	 */
	public static function for_project( int $project_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE project_id = %d ORDER BY sort_order ASC, id ASC',
			Schema::table( 'project_files' ),
			$project_id
		) ) ?: [];

		foreach ( $rows as $row ) {
			$attachment_id = (int) $row->attachment_id;
			$url           = wp_get_attachment_url( $attachment_id );
			if ( false === $url ) {
				// Attachment wurde gelöscht — verwaiste Verknüpfung markieren.
				$row->missing  = true;
				$row->url      = false;
				$row->filename = '';
				$row->mime     = '';
				$row->filesize = null;
				continue;
			}
			$path             = get_attached_file( $attachment_id );
			$row->missing     = false;
			$row->url         = $url;
			$row->filename    = $path ? wp_basename( $path ) : get_the_title( $attachment_id );
			$row->mime        = get_post_mime_type( $attachment_id ) ?: '';
			$row->filesize    = ( $path && is_readable( $path ) ) ? (int) filesize( $path ) : null;
		}
		return $rows;
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_files' ),
			$id
		) );
	}

	/**
	 * Ein Attachment ans Projekt hängen.
	 *
	 * Validiert, dass $attachment_id ein existierendes Attachment ist; doppelte
	 * Verknüpfung desselben Attachments ans selbe Projekt wird vermieden (die
	 * vorhandene Zeilen-ID wird zurückgegeben — idempotent).
	 *
	 * @return int|WP_Error  Neue (oder vorhandene) Verknüpfungs-ID.
	 */
	public static function attach( int $project_id, int $attachment_id, string $title = '' ) {
		global $wpdb;

		$post = $attachment_id ? get_post( $attachment_id ) : null;
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'pp_invalid_attachment', __( 'Invalid attachment.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		// Doppelte Verknüpfung vermeiden.
		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM %i WHERE project_id = %d AND attachment_id = %d',
			Schema::table( 'project_files' ),
			$project_id,
			$attachment_id
		) );
		if ( $existing ) {
			return (int) $existing;
		}

		$wpdb->insert( Schema::table( 'project_files' ), [
			'project_id'    => $project_id,
			'attachment_id' => $attachment_id,
			'title'         => trim( $title ),
			'sort_order'    => 0,
			'created_at'    => current_time( 'mysql' ),
			'created_by'    => get_current_user_id() ?: null,
		] );
		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'file_attached', 'project', $project_id, [
			'attachment_id' => $attachment_id,
			'title'         => trim( $title ),
		] );
		return $id;
	}

	/**
	 * Partielles Update — nur Titel (und optional sort_order).
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$entry = self::get( $id );
		if ( ! $entry ) {
			return new WP_Error( 'pp_not_found', __( 'File not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}

		$fields = [];
		if ( array_key_exists( 'title', $data ) ) {
			$fields['title'] = trim( (string) $data['title'] );
		}
		if ( array_key_exists( 'sort_order', $data ) ) {
			$fields['sort_order'] = (int) $data['sort_order'];
		}
		if ( $fields ) {
			$wpdb->update( Schema::table( 'project_files' ), $fields, [ 'id' => $id ] );
			ActivityLog::log( 'file_updated', 'project', (int) $entry->project_id, [
				'entry_id' => $id,
				'fields'   => array_keys( $fields ),
			] );
		}
		return true;
	}

	/**
	 * Verknüpfung lösen — löscht NUR die Join-Zeile, das Medium bleibt in der
	 * Mediathek (könnte anderswo genutzt werden).
	 *
	 * @return true|WP_Error
	 */
	public static function detach( int $id ) {
		global $wpdb;
		$entry = self::get( $id );
		if ( ! $entry ) {
			return new WP_Error( 'pp_not_found', __( 'File not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$wpdb->delete( Schema::table( 'project_files' ), [ 'id' => $id ], [ '%d' ] );
		ActivityLog::log( 'file_detached', 'project', (int) $entry->project_id, [
			'attachment_id' => (int) $entry->attachment_id,
		] );
		return true;
	}
}
