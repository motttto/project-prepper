<?php
namespace ProjectPrepper;

defined( 'ABSPATH' ) || exit;

/**
 * Capabilities — Pendant zu den feingranularen Permissions der App (MVP-Subset).
 *
 * Zugriffskontrolle läuft komplett über diese Caps (RLS-Ersatz):
 * jede REST-Route prüft sie im permission_callback.
 */
class Capabilities {

	const VIEW_INVENTORY  = 'pp_inventory_view';
	const EDIT_INVENTORY  = 'pp_inventory_edit';
	const VIEW_RENTALS    = 'pp_rentals_view';
	const EDIT_RENTALS    = 'pp_rentals_edit';
	const VIEW_INQUIRIES  = 'pp_inquiries_view';
	const EDIT_INQUIRIES  = 'pp_inquiries_edit';
	const IMPORT_EXPORT   = 'pp_import_export';
	const MANAGE_SETTINGS = 'pp_settings_manage';

	public static function all(): array {
		return [
			self::VIEW_INVENTORY,
			self::EDIT_INVENTORY,
			self::VIEW_RENTALS,
			self::EDIT_RENTALS,
			self::VIEW_INQUIRIES,
			self::EDIT_INQUIRIES,
			self::IMPORT_EXPORT,
			self::MANAGE_SETTINGS,
		];
	}

	/**
	 * Rollen anlegen + Administrator alle Caps geben. Idempotent.
	 */
	public static function install(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		// Manager: alles außer Einstellungen (≈ App-Rolle "Manager").
		// remove_role vor add_role, damit Cap-Erweiterungen bei Updates greifen
		// (add_role ist no-op, wenn die Rolle schon existiert).
		remove_role( 'pp_manager' );
		add_role( 'pp_manager', __( 'Prepper Manager', 'project-prepper' ), [
			'read'                => true,
			self::VIEW_INVENTORY  => true,
			self::EDIT_INVENTORY  => true,
			self::VIEW_RENTALS    => true,
			self::EDIT_RENTALS    => true,
			self::VIEW_INQUIRIES  => true,
			self::EDIT_INQUIRIES  => true,
			self::IMPORT_EXPORT   => true,
		] );

		// Member: nur lesen (≈ App-Rolle "Member" mit View-Permissions).
		remove_role( 'pp_member' );
		add_role( 'pp_member', __( 'Prepper Mitglied', 'project-prepper' ), [
			'read'               => true,
			self::VIEW_INVENTORY => true,
			self::VIEW_RENTALS   => true,
			self::VIEW_INQUIRIES => true,
		] );
	}

	public static function uninstall(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
		remove_role( 'pp_manager' );
		remove_role( 'pp_member' );
	}
}
