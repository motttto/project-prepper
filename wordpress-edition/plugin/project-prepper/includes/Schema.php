<?php
namespace ProjectPrepper;

defined( 'ABSPATH' ) || exit;

/**
 * Custom Tables (MVP-Subset aus Dok 01 §19.1).
 *
 * Tabellen-Präfix: {$wpdb->prefix}pp_
 * Versionierung über Option — dbDelta gleicht Schema-Änderungen ab.
 */
class Schema {

	const VERSION    = '0.1.0';
	const OPTION_KEY = 'pp_schema_version';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'pp_' . $name;
	}

	public static function migrate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset    = $wpdb->get_charset_collate();
		$categories = self::table( 'categories' );
		$items      = self::table( 'items' );
		$units      = self::table( 'units' );
		$rentals    = self::table( 'rentals' );
		$lines      = self::table( 'rental_items' );
		$log        = self::table( 'activity_log' );

		dbDelta( "CREATE TABLE {$categories} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			icon varchar(16) NOT NULL DEFAULT '',
			prefix varchar(10) NOT NULL DEFAULT '',
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY sort_order (sort_order)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			inventory_number varchar(50) NOT NULL,
			category_id bigint(20) unsigned DEFAULT NULL,
			name varchar(190) NOT NULL,
			description text,
			manufacturer varchar(190) NOT NULL DEFAULT '',
			model varchar(190) NOT NULL DEFAULT '',
			tags longtext,
			quantity int(11) NOT NULL DEFAULT 1,
			item_condition varchar(20) NOT NULL DEFAULT 'good',
			location varchar(190) NOT NULL DEFAULT '',
			cost_per_day decimal(10,2) DEFAULT NULL,
			current_value decimal(10,2) DEFAULT NULL,
			purchase_date date DEFAULT NULL,
			image_id bigint(20) unsigned DEFAULT NULL,
			document_ids longtext,
			notes text,
			created_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY inventory_number (inventory_number),
			KEY category_id (category_id),
			KEY name (name)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$units} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			item_id bigint(20) unsigned NOT NULL,
			unit_number int(11) NOT NULL,
			unit_condition varchar(20) NOT NULL DEFAULT 'good',
			notes text,
			PRIMARY KEY  (id),
			UNIQUE KEY item_unit (item_id,unit_number)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$rentals} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rental_number varchar(50) NOT NULL,
			borrower_name varchar(190) NOT NULL,
			borrower_email varchar(190) NOT NULL DEFAULT '',
			borrower_phone varchar(64) NOT NULL DEFAULT '',
			date_from date NOT NULL,
			date_to date NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'reserved',
			deposit_amount decimal(10,2) DEFAULT NULL,
			rental_fee decimal(10,2) DEFAULT NULL,
			vat_rate decimal(5,2) DEFAULT NULL,
			notes text,
			created_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY rental_number (rental_number),
			KEY status (status),
			KEY date_from (date_from),
			KEY date_to (date_to)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$lines} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rental_id bigint(20) unsigned NOT NULL,
			item_id bigint(20) unsigned NOT NULL,
			unit_id bigint(20) unsigned DEFAULT NULL,
			quantity int(11) NOT NULL DEFAULT 1,
			daily_rate decimal(10,2) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY rental_id (rental_id),
			KEY item_id (item_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_id bigint(20) unsigned DEFAULT NULL,
			action varchar(64) NOT NULL,
			entity_type varchar(32) NOT NULL,
			entity_id bigint(20) unsigned DEFAULT NULL,
			metadata longtext,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY entity (entity_type,entity_id),
			KEY created_at (created_at)
		) {$charset};" );

		update_option( self::OPTION_KEY, self::VERSION );
	}
}
