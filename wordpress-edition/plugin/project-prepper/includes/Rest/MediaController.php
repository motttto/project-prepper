<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Services\Inventory;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Foto + PDF-Dokumente pro Artikel (§8.1) — Uploads in die Media Library.
 */
class MediaController extends BaseController {

	const MAX_DOCUMENT_BYTES = 20 * 1024 * 1024; // 20 MB wie in der App

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/items/(?P<id>\d+)/image', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'upload_image' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_INVENTORY ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_image' ],
				'permission_callback' => $this->require_cap( Capabilities::EDIT_INVENTORY ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/items/(?P<id>\d+)/documents', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'upload_document' ],
			'permission_callback' => $this->require_cap( Capabilities::EDIT_INVENTORY ),
		] );

		register_rest_route( self::REST_NAMESPACE, '/items/(?P<id>\d+)/documents/(?P<doc_id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'delete_document' ],
			'permission_callback' => $this->require_cap( Capabilities::EDIT_INVENTORY ),
		] );
	}

	public function upload_image( WP_REST_Request $request ) {
		$item = Inventory::get_item( (int) $request['id'] );
		if ( ! $item ) {
			return new WP_Error( 'pp_not_found', __( 'Item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}

		$attachment_id = $this->handle_upload( $request, [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ] );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Altes Foto ersetzen.
		if ( $item->image_id ) {
			wp_delete_attachment( (int) $item->image_id, true );
		}
		Inventory::update_item( (int) $item->id, [ 'image_id' => $attachment_id ] );

		return new WP_REST_Response( Inventory::get_item( (int) $item->id ) );
	}

	public function delete_image( WP_REST_Request $request ) {
		$item = Inventory::get_item( (int) $request['id'] );
		if ( ! $item ) {
			return new WP_Error( 'pp_not_found', __( 'Item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		if ( $item->image_id ) {
			wp_delete_attachment( (int) $item->image_id, true );
			Inventory::update_item( (int) $item->id, [ 'image_id' => 0 ] );
		}
		return new WP_REST_Response( Inventory::get_item( (int) $item->id ) );
	}

	public function upload_document( WP_REST_Request $request ) {
		$item = Inventory::get_item( (int) $request['id'] );
		if ( ! $item ) {
			return new WP_Error( 'pp_not_found', __( 'Item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}

		$files = $request->get_file_params();
		if ( ! empty( $files['file']['size'] ) && $files['file']['size'] > self::MAX_DOCUMENT_BYTES ) {
			return new WP_Error( 'pp_too_large', __( 'File too large (max. 20 MB).', 'project-prepper' ), [ 'status' => 413 ] );
		}

		$attachment_id = $this->handle_upload( $request, [ 'application/pdf' ] );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$docs   = array_map( 'intval', (array) $item->document_ids );
		$docs[] = $attachment_id;
		Inventory::update_item( (int) $item->id, [ 'document_ids' => $docs ] );

		return new WP_REST_Response( Inventory::get_item( (int) $item->id ) );
	}

	public function delete_document( WP_REST_Request $request ) {
		$item = Inventory::get_item( (int) $request['id'] );
		if ( ! $item ) {
			return new WP_Error( 'pp_not_found', __( 'Item not found.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$doc_id  = (int) $request['doc_id'];
		$current = array_map( 'intval', (array) $item->document_ids );
		// BOLA-Schutz: nur ein Attachment löschen, das WIRKLICH zu diesem Artikel
		// gehört — sonst könnte über eine beliebige doc_id jedes Medium der
		// Mediathek hart gelöscht werden.
		if ( ! in_array( $doc_id, $current, true ) ) {
			return new WP_Error( 'pp_not_found', __( 'Document not found for this item.', 'project-prepper' ), [ 'status' => 404 ] );
		}
		$docs = array_values( array_filter( $current, static function ( $id ) use ( $doc_id ) {
			return $id !== $doc_id;
		} ) );
		wp_delete_attachment( $doc_id, true );
		Inventory::update_item( (int) $item->id, [ 'document_ids' => $docs ] );

		return new WP_REST_Response( Inventory::get_item( (int) $item->id ) );
	}

	/**
	 * Multipart-Upload ("file") → Media Library, MIME-geprüft.
	 *
	 * @return int|WP_Error Attachment-ID.
	 */
	private function handle_upload( WP_REST_Request $request, array $allowed_mimes ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! empty( $files['file']['error'] ) ) {
			return new WP_Error( 'pp_no_file', __( 'No file submitted.', 'project-prepper' ), [ 'status' => 400 ] );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$check = wp_check_filetype_and_ext( $files['file']['tmp_name'], $files['file']['name'] );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed_mimes, true ) ) {
			return new WP_Error( 'pp_invalid_type', __( 'File type not allowed.', 'project-prepper' ), [ 'status' => 415 ] );
		}

		$attachment_id = media_handle_sideload( $files['file'] );
		if ( is_wp_error( $attachment_id ) ) {
			$attachment_id->add_data( [ 'status' => 500 ] );
			return $attachment_id;
		}
		return (int) $attachment_id;
	}
}
