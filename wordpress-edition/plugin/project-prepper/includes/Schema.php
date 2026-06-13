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

	const VERSION    = '0.9.0';
	const OPTION_KEY = 'pp_schema_version';

	// Nach Schema-/Versions-Upgrades einmalig die Rewrite-Rules flushen
	// (Artikel-Detailseite /equipment-item/{nummer}) — Abbau in Frontend\ItemDetail.
	const FLUSH_FLAG = 'pp_flush_rewrite_pending';

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
		$inquiries  = self::table( 'inquiries' );
		$projects   = self::table( 'projects' );
		$p_items    = self::table( 'project_items' );
		$p_lists    = self::table( 'project_checklists' );
		$p_checks   = self::table( 'project_checklist_items' );
		$p_tasks    = self::table( 'project_tasks' );
		$p_sched    = self::table( 'project_schedule' );
		$p_costs    = self::table( 'cost_items' );
		$p_consum   = self::table( 'project_consumables' );
		$p_team     = self::table( 'project_team' );
		$p_contacts = self::table( 'project_contacts' );
		$p_files    = self::table( 'project_files' );

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
			serial_number varchar(190) NOT NULL DEFAULT '',
			tags longtext,
			quantity int(11) NOT NULL DEFAULT 1,
			item_condition varchar(20) NOT NULL DEFAULT 'good',
			location varchar(190) NOT NULL DEFAULT '',
			cost_per_day decimal(10,2) DEFAULT NULL,
			purchase_price decimal(10,2) DEFAULT NULL,
			current_value decimal(10,2) DEFAULT NULL,
			purchase_date date DEFAULT NULL,
			dimensions varchar(190) NOT NULL DEFAULT '',
			power_watts int(11) DEFAULT NULL,
			accessories text,
			manufacturer_url varchar(255) NOT NULL DEFAULT '',
			manual_url varchar(255) NOT NULL DEFAULT '',
			ownership_type varchar(40) NOT NULL DEFAULT '',
			funding_source varchar(190) NOT NULL DEFAULT '',
			depreciation_method varchar(20) NOT NULL DEFAULT '',
			depreciation_years int(11) DEFAULT NULL,
			residual_value decimal(10,2) DEFAULT NULL,
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
			borrower_address text,
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

		dbDelta( "CREATE TABLE {$inquiries} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(64) NOT NULL DEFAULT '',
			message text,
			date_from date DEFAULT NULL,
			date_to date DEFAULT NULL,
			items longtext,
			status varchar(20) NOT NULL DEFAULT 'new',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};" );

		// Projekte (Kern + Planung, v0.9.0) — Subset der App-Tabelle `projects`:
		// Single-Site/Single-Owner, daher keine Owner-/Mitglieder-/Budget-Felder.
		dbDelta( "CREATE TABLE {$projects} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_number varchar(50) NOT NULL,
			name varchar(190) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			date_start date DEFAULT NULL,
			date_end date DEFAULT NULL,
			venue_name varchar(190) NOT NULL DEFAULT '',
			venue_address text,
			client_name varchar(190) NOT NULL DEFAULT '',
			client_email varchar(190) NOT NULL DEFAULT '',
			client_phone varchar(64) NOT NULL DEFAULT '',
			notes text,
			budget_planned decimal(10,2) DEFAULT NULL,
			revenue_actual decimal(10,2) DEFAULT NULL,
			created_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY project_number (project_number),
			KEY status (status),
			KEY date_start (date_start)
		) {$charset};" );

		// Equipment-Buchungen pro Projekt (Pendant zu `bookings` der App).
		// date_from/date_to NULL = Zeile erbt den Projekt-Zeitraum.
		dbDelta( "CREATE TABLE {$p_items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
			item_id bigint(20) unsigned NOT NULL,
			quantity int(11) NOT NULL DEFAULT 1,
			date_from date DEFAULT NULL,
			date_to date DEFAULT NULL,
			notes text,
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY item_id (item_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$p_lists} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
			name varchar(190) NOT NULL,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY project_id (project_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$p_checks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			checklist_id bigint(20) unsigned NOT NULL,
			label varchar(190) NOT NULL,
			is_checked tinyint(1) NOT NULL DEFAULT 0,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY checklist_id (checklist_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$p_tasks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
			title varchar(190) NOT NULL,
			task_status varchar(20) NOT NULL DEFAULT 'open',
			priority varchar(20) NOT NULL DEFAULT 'normal',
			due_date date DEFAULT NULL,
			assigned_user bigint(20) unsigned DEFAULT NULL,
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY task_status (task_status)
		) {$charset};" );

		// Zeitplan pro Projekt (Pendant zu `project_schedule` der App).
		// Datum + optionale Zeitspanne pro Programmpunkt; location/notes als
		// WP-Ergänzung. Alle Zeitfelder nullable (z. B. nur grobes Tagesdatum).
		dbDelta( "CREATE TABLE {$p_sched} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
			schedule_date date DEFAULT NULL,
			time_start time DEFAULT NULL,
			time_end time DEFAULT NULL,
			title varchar(190) NOT NULL,
			location varchar(190) NOT NULL DEFAULT '',
			notes text,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY project_id (project_id)
		) {$charset};" );

			// Kostenposten pro Projekt (Pendant zu `cost_items` der App).
			// amount_actual NULL = noch kein Ist-Wert erfasst. exclude_from_profit
			// nimmt die Zeile aus der Gewinnberechnung heraus (z. B. durchlaufende
			// Posten). Sub-Budgets der App (Honorar/Technik/Transport) entfallen —
			// Single-Site nutzt nur ein Gesamtbudget auf dem Projekt.
			dbDelta( "CREATE TABLE {$p_costs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL,
				category varchar(20) NOT NULL DEFAULT 'other',
				description varchar(190) NOT NULL DEFAULT '',
				amount_planned decimal(10,2) NOT NULL DEFAULT 0,
				amount_actual decimal(10,2) DEFAULT NULL,
				vat_rate decimal(5,2) NOT NULL DEFAULT 0,
				exclude_from_profit tinyint(1) NOT NULL DEFAULT 0,
				sort_order int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY project_id (project_id)
			) {$charset};" );

			// Verbrauchsmaterial pro Projekt (Pendant zu `project_consumables` der App).
			// cost NULL = kein Kostenwert erfasst.
			dbDelta( "CREATE TABLE {$p_consum} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL,
				name varchar(190) NOT NULL,
				quantity decimal(10,2) NOT NULL DEFAULT 1,
				unit varchar(40) NOT NULL DEFAULT '',
				cost decimal(10,2) DEFAULT NULL,
				sort_order int(11) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY project_id (project_id)
			) {$charset};" );

			// Team-Mitglieder pro Projekt (Pendant zu `project_team_members` der App).
			// Single-Site: keine Profil-Verknüpfung, nur Freitext-Felder.
			dbDelta( "CREATE TABLE {$p_team} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL,
				name varchar(190) NOT NULL,
				role varchar(190) NOT NULL DEFAULT '',
				department varchar(190) NOT NULL DEFAULT '',
				sort_order int(11) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY project_id (project_id)
			) {$charset};" );

			// Externe Kontakte/Ansprechpartner pro Projekt (Pendant zu `project_contacts`
			// der App). email/phone sind WP-Ergänzungen — externe Ansprechpartner
			// haben Kontaktdaten.
			dbDelta( "CREATE TABLE {$p_contacts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL,
				name varchar(190) NOT NULL,
				role varchar(190) NOT NULL DEFAULT '',
				company varchar(190) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				phone varchar(64) NOT NULL DEFAULT '',
				sort_order int(11) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY project_id (project_id)
			) {$charset};" );

			// Datei-Verknüpfungen pro Projekt (Pendant zu `project_files` der App).
			// Anders als die Listen-Tabs verweist jede Zeile auf ein WP-Attachment
			// (Medienbibliothek) — URL/Dateiname/MIME kommen aus dem Attachment selbst.
			// Detach löscht NUR diese Join-Zeile, nie das Medium.
			dbDelta( "CREATE TABLE {$p_files} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL,
				attachment_id bigint(20) unsigned NOT NULL,
				title varchar(190) NOT NULL DEFAULT '',
				sort_order int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				created_by bigint(20) unsigned DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY project_id (project_id),
				KEY attachment_id (attachment_id)
			) {$charset};" );

		self::upgrade_data();
		update_option( self::OPTION_KEY, self::VERSION );
		update_option( self::FLUSH_FLAG, 1 );
	}

	/**
	 * Daten-Migrationen zwischen Schema-Versionen.
	 */
	private static function upgrade_data(): void {
		global $wpdb;
		$from = get_option( self::OPTION_KEY, '0' );

		if ( version_compare( $from, '0.2.0', '<' ) ) {
			// Zustands-Enum an die App angleichen (§8.1): used→fair, defect→broken.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- einmalige Daten-Migration auf Plugin-eigenen Tabellen, Caching nicht anwendbar.
			$items = self::table( 'items' );
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET item_condition = %s WHERE item_condition = %s', $items, 'fair', 'used' ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET item_condition = %s WHERE item_condition = %s', $items, 'broken', 'defect' ) );

			$units = self::table( 'units' );
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET unit_condition = %s WHERE unit_condition = %s', $units, 'fair', 'used' ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET unit_condition = %s WHERE unit_condition = %s', $units, 'broken', 'defect' ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery
		}
	}
}
