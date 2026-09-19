<?php
namespace ProjectPrepper\Rest;

use ProjectPrepper\Capabilities;
use ProjectPrepper\Services\ItemFields;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Betreiber-Verwaltung der eigenen Artikel-Felder (Einstellungen → Eigene
 * Felder). ANLEGEN passiert im Portal durch die Mitglieder; hier wird nur
 * aufgeräumt — umbenennen und löschen (löscht die Werte aller Mitglieder).
 */
class ItemFieldsController extends BaseController {

	public function register_routes(): void {
		register_rest_route( self::REST_NAMESPACE, '/item-fields', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'index' ],
			'permission_callback' => $this->require_cap( Capabilities::OPERATE ),
		] );
		register_rest_route( self::REST_NAMESPACE, '/item-fields/(?P<id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update' ],
				'permission_callback' => $this->require_cap( Capabilities::OPERATE ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete' ],
				'permission_callback' => $this->require_cap( Capabilities::OPERATE ),
			],
		] );
	}

	public function index(): WP_REST_Response {
		return new WP_REST_Response( $this->payload() );
	}

	public function update( WP_REST_Request $request ) {
		$json   = $request->get_json_params() ?: [];
		$result = ItemFields::rename_def( (int) $request['id'], (string) ( $json['label'] ?? '' ) );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $this->payload() );
	}

	public function delete( WP_REST_Request $request ) {
		$result = ItemFields::delete_def( (int) $request['id'] );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $this->payload() );
	}

	private function payload(): array {
		$usage = ItemFields::usage();
		$out   = [];
		foreach ( ItemFields::defs() as $id => $def ) {
			$creator = $def->created_by ? get_userdata( (int) $def->created_by ) : false;
			$out[]   = [
				'id'         => (int) $id,
				'label'      => (string) $def->label,
				'created_by' => $creator ? $creator->display_name : '',
				'items'      => (int) ( $usage[ $id ] ?? 0 ),
			];
		}
		return $out;
	}
}
