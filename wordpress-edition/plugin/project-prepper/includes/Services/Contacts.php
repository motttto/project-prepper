<?php
namespace ProjectPrepper\Services;

use ProjectPrepper\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Externe Kontakte/Ansprechpartner pro Projekt — Pendant zu project_contacts
 * der App (Felder: name, role, company).
 *
 * email/phone sind WP-Ergänzungen: externe Ansprechpartner haben Kontaktdaten.
 * email wird via sanitize_email normalisiert (falls gesetzt).
 */
class Contacts {

	public static function for_project( int $project_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE project_id = %d ORDER BY sort_order ASC, id ASC',
			Schema::table( 'project_contacts' ),
			$project_id
		) ) ?: [];
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			Schema::table( 'project_contacts' ),
			$id
		) );
	}

	/**
	 * @return int|WP_Error
	 */
	public static function create( int $project_id, array $data ) {
		global $wpdb;

		if ( empty( $data['name'] ) || '' === trim( (string) $data['name'] ) ) {
			return new WP_Error( 'pp_missing_name', __( 'Name is required.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		$wpdb->insert( Schema::table( 'project_contacts' ), [
			'project_id' => $project_id,
			'name'       => trim( (string) $data['name'] ),
			'role'       => isset( $data['role'] ) ? (string) $data['role'] : '',
			'company'    => isset( $data['company'] ) ? (string) $data['company'] : '',
			'email'      => isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '',
			'phone'      => isset( $data['phone'] ) ? (string) $data['phone'] : '',
			'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
		] );
		$id = (int) $wpdb->insert_id;

		ActivityLog::log( 'contact_created', 'project', $project_id, [ 'name' => trim( (string) $data['name'] ) ] );
		return $id;
	}

	/**
	 * Partielles Update — nur übergebene Keys werden geändert.
	 *
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$entry = self::get( $id );
		if ( ! $entry ) {
			return new WP_Error( 'pp_not_found', __( 'Contact not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}

		$fields = [];
		if ( array_key_exists( 'name', $data ) ) {
			if ( '' === trim( (string) $data['name'] ) ) {
				return new WP_Error( 'pp_missing_name', __( 'Name is required.', 'project-prepper' ), [ 'status' => 400 ] );
			}
			$fields['name'] = trim( (string) $data['name'] );
		}
		if ( array_key_exists( 'role', $data ) ) {
			$fields['role'] = (string) $data['role'];
		}
		if ( array_key_exists( 'company', $data ) ) {
			$fields['company'] = (string) $data['company'];
		}
		if ( array_key_exists( 'email', $data ) ) {
			$fields['email'] = sanitize_email( (string) $data['email'] );
		}
		if ( array_key_exists( 'phone', $data ) ) {
			$fields['phone'] = (string) $data['phone'];
		}
		if ( array_key_exists( 'sort_order', $data ) ) {
			$fields['sort_order'] = (int) $data['sort_order'];
		}
		if ( $fields ) {
			$wpdb->update( Schema::table( 'project_contacts' ), $fields, [ 'id' => $id ] );
			ActivityLog::log( 'contact_updated', 'project', (int) $entry->project_id, [
				'entry_id' => $id,
				'fields'   => array_keys( $fields ),
			] );
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;
		$entry = self::get( $id );
		if ( ! $entry ) {
			return new WP_Error( 'pp_not_found', __( 'Contact not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$wpdb->delete( Schema::table( 'project_contacts' ), [ 'id' => $id ], [ '%d' ] );
		ActivityLog::log( 'contact_deleted', 'project', (int) $entry->project_id, [ 'name' => $entry->name ] );
		return true;
	}
}
