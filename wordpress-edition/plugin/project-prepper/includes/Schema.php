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

	// v0.39.0: Sets/Bundles (docs/07) — neue Tabelle item_bundle_parts (Stückliste
	// je Set-Artikel) + additive Spalte project_items.bundle_item_id (markiert,
	// aus welchem Set eine Buchungszeile expandiert wurde). Beides via dbDelta,
	// keine Datenmigration.
	const VERSION    = '0.39.0';
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
		$groups     = self::table( 'groups' );
		$g_members  = self::table( 'group_members' );
		$p_members  = self::table( 'project_members' );
		$p_decis    = self::table( 'project_decisions' );
		$p_votes    = self::table( 'project_decision_votes' );
		$p_shares   = self::table( 'project_profit_shares' );
		$p_agree    = self::table( 'project_agreements' );
		$p_agree_s  = self::table( 'project_agreement_signatures' );
		$p_polls    = self::table( 'project_polls' );
		$p_poll_o   = self::table( 'project_poll_options' );
		$p_poll_v   = self::table( 'project_poll_votes' );
		$g_invites  = self::table( 'group_invitations' );
		$g_inv_v    = self::table( 'group_invitation_votes' );
		$item_share = self::table( 'item_group_shares' );
		$bundle_p   = self::table( 'item_bundle_parts' );
		$borrows    = self::table( 'borrow_requests' );
		$fed_in     = self::table( 'fed_borrow_in' );
		$fed_out    = self::table( 'fed_borrow_out' );
		$feedback   = self::table( 'app_feedback' );
		$cal_groups = self::table( 'calendar_groups' );
		$cal_events = self::table( 'calendar_events' );
		$inq_team   = self::table( 'inquiry_team' );

		dbDelta( "CREATE TABLE {$categories} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			icon varchar(16) NOT NULL DEFAULT '',
			prefix varchar(10) NOT NULL DEFAULT '',
			sort_order int(11) NOT NULL DEFAULT 0,
			owner_user_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY sort_order (sort_order),
			KEY owner_user_id (owner_user_id)
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
			owner_user_id bigint(20) unsigned DEFAULT NULL,
			created_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY inventory_number (inventory_number),
			KEY category_id (category_id),
			KEY name (name),
			KEY owner_user_id (owner_user_id)
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
			owner_user_id bigint(20) unsigned DEFAULT NULL,
			owner_group_id bigint(20) unsigned DEFAULT NULL,
			created_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY rental_number (rental_number),
			KEY status (status),
			KEY date_from (date_from),
			KEY date_to (date_to),
			KEY owner_user_id (owner_user_id),
			KEY owner_group_id (owner_group_id)
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

		// Anfragen — v0.28.0 zieht die Pipeline-Felder der App nach
		// (inquiries: title/client_contact_person/venue_name/estimated_budget/
		// offer_amount/probability/next_follow_up/project_id).
		dbDelta( "CREATE TABLE {$inquiries} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			title varchar(190) NOT NULL DEFAULT '',
			contact_person varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(64) NOT NULL DEFAULT '',
			message text,
			venue_name varchar(190) NOT NULL DEFAULT '',
			date_from date DEFAULT NULL,
			date_to date DEFAULT NULL,
			estimated_budget decimal(10,2) DEFAULT NULL,
			offer_amount decimal(10,2) DEFAULT NULL,
			probability int(3) DEFAULT NULL,
			follow_up date DEFAULT NULL,
			project_id bigint(20) unsigned DEFAULT NULL,
			items longtext,
			status varchar(20) NOT NULL DEFAULT 'new',
			owner_user_id bigint(20) unsigned DEFAULT NULL,
			owner_group_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at),
			KEY owner_user_id (owner_user_id),
			KEY owner_group_id (owner_group_id)
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
			owner_group_id bigint(20) unsigned DEFAULT NULL,
			created_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY project_number (project_number),
			KEY status (status),
			KEY date_start (date_start),
			KEY owner_group_id (owner_group_id)
		) {$charset};" );

		// Equipment-Buchungen pro Projekt (Pendant zu `bookings` der App).
		// date_from/date_to NULL = Zeile erbt den Projekt-Zeitraum.
		// approval_status (v0.34.0) — Pendant zu bookings.approval_status der App:
		// approved | pending | rejected. Default 'approved' = Altbuchungen +
		// auto-freigegebene bleiben gültig. requested_by = Anfrager (bei pending),
		// decided_at = Zeitpunkt der Eigentümer-Entscheidung. Pending-Zeilen zählen
		// weiterhin gegen die Verfügbarkeit (Availability zählt alle Zeilen).
		// packed_at (v0.36.0) — Packlisten-Status: NULL = noch nicht gepackt,
		// datetime = als „gepackt" markiert. Additiv via dbDelta, keine Migration.
		// tested_at (v0.37.0) — analog: NULL = noch nicht getestet, datetime = getestet.
		// bundle_item_id (v0.39.0) — Set-Herkunft: die Zeile wurde beim Buchen eines
		// Sets (docs/07) aus dessen Stückliste expandiert. NULL = normale Einzel-
		// Buchung. Nur für die gruppierte Anzeige; Verfügbarkeit/Freigaben zählen
		// weiterhin rein über item_id.
		dbDelta( "CREATE TABLE {$p_items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
			item_id bigint(20) unsigned NOT NULL,
			quantity int(11) NOT NULL DEFAULT 1,
			date_from date DEFAULT NULL,
			date_to date DEFAULT NULL,
			notes text,
			approval_status varchar(20) NOT NULL DEFAULT 'approved',
			requested_by bigint(20) unsigned DEFAULT NULL,
			decided_at datetime DEFAULT NULL,
			packed_at datetime DEFAULT NULL,
			tested_at datetime DEFAULT NULL,
			bundle_item_id bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY item_id (item_id),
			KEY approval_status (approval_status),
			KEY bundle_item_id (bundle_item_id)
		) {$charset};" );

		// Sets/Bundles (v0.39.0, docs/07): Stückliste eines Set-Artikels — ein
		// Artikel IST ein Set, sobald er hier Zeilen hat. part_item_id = normaler
		// eigener Artikel desselben Eigentümers (Gates im Bundles-Service), quantity
		// = benötigte Stückzahl pro Set. Beim Buchen wird das Set serverseitig in
		// Teil-Buchungszeilen expandiert (Buchungs-Makro) — das Set selbst wird nie
		// als project_items-Zeile gebucht.
		dbDelta( "CREATE TABLE {$bundle_p} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bundle_item_id bigint(20) unsigned NOT NULL,
			part_item_id bigint(20) unsigned NOT NULL,
			quantity int(11) NOT NULL DEFAULT 1,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY bundle_part (bundle_item_id,part_item_id),
			KEY part_item_id (part_item_id)
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

		// assignment_status (v0.31.0) — Pendant zu project_tasks.assignment_status
		// der App: pending (Zuweisung angefragt) | accepted | declined. Bestehende
		// Zeilen erhalten den Default 'accepted' (Alt-Zuweisungen gelten als
		// angenommen). Ablehnen behält assigned_user (wie die App: Badge
		// „Abgelehnt" beim Namen); eine NEUE Zuweisung setzt wieder 'pending'.
		dbDelta( "CREATE TABLE {$p_tasks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
			title varchar(190) NOT NULL,
			task_status varchar(20) NOT NULL DEFAULT 'open',
			priority varchar(20) NOT NULL DEFAULT 'normal',
			due_date date DEFAULT NULL,
			assigned_user bigint(20) unsigned DEFAULT NULL,
			assignment_status varchar(20) NOT NULL DEFAULT 'accepted',
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

			// Gruppen-Overlay (v0.10.0, Phase 1) — Pendant zu `groups` der App, aber
			// stark vereinfacht: Gruppenmitglieder sind WP-Benutzer der Site, ein
			// Projekt gehört optional einer Gruppe (sonst Site-Ebene). Siehe
			// docs/03-GRUPPEN-ARCHITEKTUR.md. Rein additiv: owner_group_id auf
			// pp_projects defaultet NULL → bestehendes Single-Site-Verhalten bleibt.
			dbDelta( "CREATE TABLE {$groups} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL,
				slug varchar(190) NOT NULL,
				description text,
				logo_id bigint(20) unsigned NOT NULL DEFAULT 0,
				telegram_chat_id varchar(64) NOT NULL DEFAULT '',
				created_by bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug)
			) {$charset};" );

			// Mitgliedschaft: WP-User ↔ Gruppe, Rolle founder|member.
			dbDelta( "CREATE TABLE {$g_members} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				group_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				member_role varchar(20) NOT NULL DEFAULT 'member',
				joined_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY group_user (group_id,user_id),
				KEY user_id (user_id)
			) {$charset};" );

			// Projekt-Beteiligte (v0.11.0, Phase 2) — Pendant zu `project_members`
			// der App, bewusst schlank: das Roster verweist auf WP-User aus der
			// besitzenden Gruppe des Projekts (owner_group_id) + freie Rolle + Notiz.
			// KEINE Beträge/Raten (kommen mit Gewinn-/Vereinbarungs-Phase), KEINE
			// zweite Zugriffsebene — Zugriff bleibt gruppen-basiert (Phase 1). Rein
			// dokumentarisch: wer ist am Projekt beteiligt und in welcher Rolle.
			dbDelta( "CREATE TABLE {$p_members} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				role_title varchar(190) NOT NULL DEFAULT '',
				note text,
				sort_order int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY project_id (project_id),
				UNIQUE KEY project_user (project_id,user_id)
			) {$charset};" );

			// Beschlüsse pro Projekt (v0.12.0, Phase 3) — Pendant zu `org_decisions`
			// der App (projektbezogen). Abstimmungen unter den aktiven Mitgliedern
			// der besitzenden Gruppe (owner_group_id): approve/reject/abstain mit
			// Mehrheits- oder Einstimmigkeits-Auflösung (requires_unanimous).
			// status: open|approved|rejected|cancelled. resolved_at = Auflösungs-
			// Zeitpunkt. created_by = Ersteller (für vorzeitiges Schließen).
			// Siehe docs/03-GRUPPEN-ARCHITEKTUR.md §Phase 3.
			dbDelta( "CREATE TABLE {$p_decis} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL,
				title varchar(190) NOT NULL,
				description text,
				requires_unanimous tinyint(1) NOT NULL DEFAULT 1,
				status varchar(20) NOT NULL DEFAULT 'open',
				created_by bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL,
				resolved_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY project_id (project_id),
				KEY status (status)
			) {$charset};" );

			// Stimmen je Beschluss (Pendant zu `org_decision_votes`). Eine Stimme
			// pro Mitglied (UNIQUE decision_user) — Upsert ändert die vorhandene.
			// vote: approve|reject|abstain. comment optional.
			dbDelta( "CREATE TABLE {$p_votes} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				decision_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				vote varchar(10) NOT NULL,
				comment text,
				voted_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY decision_id (decision_id),
				UNIQUE KEY decision_user (decision_id,user_id)
			) {$charset};" );

			// Gewinnverteilung pro Projekt (v0.13.0, Phase 4) — Pendant zu
			// `project_profit_shares` der App. Je Zeile ein WP-User (aus der
			// besitzenden Gruppe) mit einem Anteil am Gewinn-Pool des Projekts
			// (= Costs::summary.profit). share_type: percentage|fixed. Bei
			// percentage ist share_value ein Prozentsatz auf den Pool, bei fixed
			// ein fester Eurobetrag (pool-unabhängig). Der ShareType hourly der
			// App entfällt bewusst — es gibt keine Stundenerfassung in der WP-
			// Edition. Flaches Pool-Modell: Prozente beziehen sich auf den vollen
			// Gewinn-Pool, NICHT auf den nach fixed verbleibenden Rest (wie die
			// App über calculated_amount). Siehe docs/03-GRUPPEN-ARCHITEKTUR.md §Phase 4.
			dbDelta( "CREATE TABLE {$p_shares} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				share_type varchar(20) NOT NULL DEFAULT 'percentage',
				share_value decimal(12,2) NOT NULL DEFAULT 0,
				note varchar(190) NOT NULL DEFAULT '',
				sort_order int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY project_id (project_id),
				UNIQUE KEY project_user (project_id,user_id)
			) {$charset};" );

			// Kooperationsvereinbarung pro Projekt (v0.14.0, Phase 5) — Pendant zu
			// `cooperation_agreements` der App, vereinfacht: der Vertragsinhalt ist
			// ein Freitext-Body (terms), KEINE strukturierten profit_formula/
			// exit_rules-JSON (die Gewinnverteilung lebt bereits in Phase 4). Eine
			// aktuelle Vereinbarung pro Projekt (UNIQUE project). status:
			// draft|signing|active|terminated (kein `amended` der App — Überarbeiten
			// erhöht version und löscht alte Signaturen). version steigt beim
			// Überarbeiten. activated_at = Zeitpunkt der Aktivierung (alle
			// Gruppenmitglieder unterschrieben). Siehe docs/03-GRUPPEN-ARCHITEKTUR.md
			// §Phase 5.
			dbDelta( "CREATE TABLE {$p_agree} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				project_id bigint(20) unsigned NOT NULL,
				title varchar(190) NOT NULL DEFAULT '',
				terms longtext,
				status varchar(20) NOT NULL DEFAULT 'draft',
				version int(11) NOT NULL DEFAULT 1,
				created_by bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL,
				activated_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY project (project_id)
			) {$charset};" );

			// Signaturen je Vereinbarung (Pendant zu `agreement_signatures`). Eine
			// Zeile pro Gruppenmitglied (UNIQUE agreement_user) — Upsert. signed_at
			// gesetzt = unterschrieben; declined_at gesetzt = abgelehnt (jeweils das
			// andere Feld NULL). Beim Überarbeiten (revise) werden ALLE Zeilen
			// gelöscht, da die alten Unterschriften für die neue Fassung ungültig
			// sind.
			dbDelta( "CREATE TABLE {$p_agree_s} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				agreement_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				signed_at datetime DEFAULT NULL,
				declined_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY agreement_id (agreement_id),
				UNIQUE KEY agreement_user (agreement_id,user_id)
			) {$charset};" );

				// Umfragen pro Projekt (v0.15.0) — Pendant zu `org_polls` der App,
				// hier projektbezogen. Termin- (date) oder Auswahl-Umfrage (choice)
				// unter den aktiven Mitgliedern der besitzenden Gruppe. Anders als die
				// Beschlüsse (approve/reject/abstain mit Auflösungs-Trigger) gibt es
				// hier mehrere Optionen und je Option Ja/Nein/Vielleicht — KEIN Auto-
				// Resolve, eine Umfrage bleibt offen bis manuell geschlossen.
				// status: open|closed. created_by = Ersteller (für Schließen/Löschen).
				dbDelta( "CREATE TABLE {$p_polls} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					project_id bigint(20) unsigned NOT NULL DEFAULT 0,
					group_id bigint(20) unsigned NOT NULL DEFAULT 0,
					title varchar(190) NOT NULL,
					description text,
					poll_type varchar(10) NOT NULL DEFAULT 'date',
					status varchar(10) NOT NULL DEFAULT 'open',
					deadline datetime DEFAULT NULL,
					created_by bigint(20) unsigned DEFAULT NULL,
					created_at datetime NOT NULL,
					closed_at datetime DEFAULT NULL,
					PRIMARY KEY  (id),
					KEY project_id (project_id),
					KEY group_id (group_id)
				) {$charset};" );

				// Optionen je Umfrage (Pendant zu `org_poll_options`). Bei date-
				// Umfragen ist option_date gesetzt (+ optional option_time HH:MM),
				// bei choice-Umfragen das label. sort_order für choice-Reihenfolge.
				dbDelta( "CREATE TABLE {$p_poll_o} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					poll_id bigint(20) unsigned NOT NULL,
					label varchar(190) NOT NULL DEFAULT '',
					option_date date DEFAULT NULL,
					option_time varchar(5) NOT NULL DEFAULT '',
					sort_order int(11) NOT NULL DEFAULT 0,
					PRIMARY KEY  (id),
					KEY poll_id (poll_id)
				) {$charset};" );

				// Stimmen je Option (Pendant zu `org_poll_votes`). Eine Stimme pro
				// (Option, User) — UNIQUE option_user, Upsert ändert die vorhandene.
				// vote: yes|no|maybe. Ein Mitglied stimmt pro Option getrennt ab.
				dbDelta( "CREATE TABLE {$p_poll_v} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					option_id bigint(20) unsigned NOT NULL,
					user_id bigint(20) unsigned NOT NULL,
					vote varchar(8) NOT NULL,
					PRIMARY KEY  (id),
					KEY option_id (option_id),
					UNIQUE KEY option_user (option_id,user_id)
				) {$charset};" );

			// Gruppen-Einladungen mit Beitritts-Voting (v0.17.0, Member-Portal Phase 2)
			// — Pendant zu `group_invitations` der App (Migration 072). Ein Mitglied
			// lädt per E-Mail ein; nimmt der/die Eingeladene an, stimmen die aktiven
			// Mitglieder einstimmig ab. invited_user_id wird gesetzt, sobald ein
			// WP-User mit der E-Mail existiert (beim Einladen oder bei Registrierung).
			// status: pending → voting → approved | rejected | cancelled.
			// v0.35.0: message (optionale Einladungs-Nachricht, wie App
			// group_invitations.invited_message), reminder_count (E-Mail-Erinnerungen
			// in der pending-Phase) und voting_reminder_count (Erinnerungen an die
			// noch nicht abstimmenden Mitglieder in der voting-Phase, wie App
			// voting_reminder_count).
			dbDelta( "CREATE TABLE {$g_invites} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				group_id bigint(20) unsigned NOT NULL,
				invited_email varchar(190) NOT NULL DEFAULT '',
				invited_user_id bigint(20) unsigned DEFAULT NULL,
				invited_by bigint(20) unsigned DEFAULT NULL,
				message text,
				status varchar(20) NOT NULL DEFAULT 'pending',
				reminder_count int(10) unsigned NOT NULL DEFAULT 0,
				voting_reminder_count int(10) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				resolved_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY group_id (group_id),
				KEY invited_user_id (invited_user_id),
				KEY status (status)
			) {$charset};" );

			// Stimmen je Einladung (Pendant zu `group_invitation_votes`). Eine Stimme
			// pro aktivem Mitglied (UNIQUE invitation_user), Upsert. vote:
			// approve|reject|abstain. Auflösung: eine Ablehnung → rejected; alle
			// aktiven Mitglieder approve → approved (Einstimmigkeit).
			dbDelta( "CREATE TABLE {$g_inv_v} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				invitation_id bigint(20) unsigned NOT NULL,
				voter_id bigint(20) unsigned NOT NULL,
				vote varchar(10) NOT NULL,
				voted_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY invitation_id (invitation_id),
				UNIQUE KEY invitation_user (invitation_id,voter_id)
			) {$charset};" );

			// Inventar-Freigabe in Kollektive (v0.18.0, Member-Portal Phase 3) —
			// Pendant zu `inventory_group_shares` der App: ein Mitglied teilt ein
			// EIGENES Item (owner_user_id) mit einer Gruppe, in der es Mitglied ist.
			// Eine Zeile pro (Item, Gruppe). Seit v0.26.0 mit Konditionen wie in der
			// App: Tagessatz, Freigabe-Pflicht, Bedingungs-Tags + Freitext.
			dbDelta( "CREATE TABLE {$item_share} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				item_id bigint(20) unsigned NOT NULL,
				group_id bigint(20) unsigned NOT NULL,
				shared_by bigint(20) unsigned DEFAULT NULL,
				daily_rate decimal(10,2) DEFAULT NULL,
				requires_approval tinyint(1) NOT NULL DEFAULT 0,
				conditions_tags longtext,
				conditions text,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY item_group (item_id,group_id),
				KEY group_id (group_id)
			) {$charset};" );

			// Leih-Anfragen zwischen Mitgliedern (v0.19.0, Member-Portal Phase 4) —
			// nichtkommerziell: ein Mitglied fragt ein im Kollektiv geteiltes Item
			// für einen Zeitraum an, der Eigentümer (owner_id) entscheidet. KEINE
			// Gebühren/Kaution. status: requested → approved | declined | cancelled
			// → returned. decided_at/decided_by = Entscheidung des Eigentümers.
			dbDelta( "CREATE TABLE {$borrows} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				item_id bigint(20) unsigned NOT NULL,
				group_id bigint(20) unsigned NOT NULL,
				requester_id bigint(20) unsigned NOT NULL,
				owner_id bigint(20) unsigned DEFAULT NULL,
				date_from date DEFAULT NULL,
				date_to date DEFAULT NULL,
				message text,
				status varchar(20) NOT NULL DEFAULT 'requested',
				created_at datetime NOT NULL,
				decided_at datetime DEFAULT NULL,
				decided_by bigint(20) unsigned DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY item_id (item_id),
				KEY group_id (group_id),
				KEY requester_id (requester_id),
				KEY owner_id (owner_id),
				KEY status (status)
			) {$charset};" );

			// Föderiertes Leihen (v0.20.0, Slice 4) — instanzübergreifende Leih-
			// Anfragen, moderiert. EINGEHEND (diese Instanz = Anbieter): eine andere
			// Instanz (origin_url, MUSS in der Partner-Liste stehen) fragt für ein
			// Mitglied einen lokalen Artikel an; der Eigentümer moderiert.
			// request_token = unerratbares Token, mit dem die anfragende Instanz den
			// Status pollt. status: requested → approved | declined | cancelled.
			dbDelta( "CREATE TABLE {$fed_in} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				origin_url varchar(255) NOT NULL,
				origin_name varchar(190) NOT NULL DEFAULT '',
				item_id bigint(20) unsigned NOT NULL,
				requester_name varchar(190) NOT NULL,
				requester_contact varchar(190) NOT NULL,
				date_from date DEFAULT NULL,
				date_to date DEFAULT NULL,
				message text,
				status varchar(20) NOT NULL DEFAULT 'requested',
				request_token varchar(64) NOT NULL,
				created_at datetime NOT NULL,
				decided_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY request_token (request_token),
				KEY item_id (item_id)
			) {$charset};" );

			// AUSGEHEND (diese Instanz = Anfrager): ein lokales Mitglied fragt einen
			// Artikel einer Partner-Instanz an; status_token pollt den Status drüben.
			// (Frontend folgt in Slice-4-Run-2.) status wie oben.
			dbDelta( "CREATE TABLE {$fed_out} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				requester_id bigint(20) unsigned NOT NULL,
				partner_url varchar(255) NOT NULL,
				item_label varchar(190) NOT NULL,
				item_detail_url varchar(255) NOT NULL DEFAULT '',
				date_from date DEFAULT NULL,
				date_to date DEFAULT NULL,
				message text,
				status varchar(20) NOT NULL DEFAULT 'requested',
				status_token varchar(64) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY requester_id (requester_id)
			) {$charset};" );

			// Mitglieder-Feedback an die Betreiber:innen (v0.27.0) — Pendant zur
			// App-Tabelle `app_feedback`: Bug/Idee/Sonstiges aus dem Portal.
			dbDelta( "CREATE TABLE {$feedback} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned DEFAULT NULL,
				feedback_type varchar(20) NOT NULL DEFAULT 'other',
				message text NOT NULL,
				route varchar(190) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'new',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status)
			) {$charset};" );

			// Kalender (v0.29.0) — Pendant zu `calendar_groups`/`calendar_events`
			// der App: farbige Kalender + eigene Termine pro Arbeitsbereich
			// (Solo: owner_user_id, Gruppe: owner_group_id — genau eines gesetzt,
			// das andere 0). KEIN CalDAV/Zwei-Wege-Sync (bewusst v2.x).
			dbDelta( "CREATE TABLE {$cal_groups} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				owner_group_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(190) NOT NULL,
				color varchar(7) NOT NULL DEFAULT '#0066FF',
				sort_order int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY owner_user_id (owner_user_id),
				KEY owner_group_id (owner_group_id)
			) {$charset};" );

			// Termine: Datum + optionale Uhrzeiten (leer = ganztägig, App: all_day).
			// calendar_group_id 0 = keinem Kalender zugeordnet (App: group_id NULL).
			// uid (v0.33.0): iCal-UID für den CalDAV-Zweiweg-Server. Additiv, leer bei
			// im Portal angelegten Terminen (dann wird beim Export eine stabile UID
			// pp-event-<id>@<host> abgeleitet); von CalDAV-Clients per PUT gesetzt.
			dbDelta( "CREATE TABLE {$cal_events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				calendar_group_id bigint(20) unsigned NOT NULL DEFAULT 0,
				owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				owner_group_id bigint(20) unsigned NOT NULL DEFAULT 0,
				title varchar(190) NOT NULL,
				description text,
				location varchar(190) NOT NULL DEFAULT '',
				date_from date NOT NULL,
				date_to date DEFAULT NULL,
				time_start varchar(5) NOT NULL DEFAULT '',
				time_end varchar(5) NOT NULL DEFAULT '',
				uid varchar(255) NOT NULL DEFAULT '',
				created_by bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY calendar_group_id (calendar_group_id),
				KEY owner_user_id (owner_user_id),
				KEY owner_group_id (owner_group_id),
				KEY date_from (date_from),
				KEY uid (uid)
			) {$charset};" );

			// Team-Verfügbarkeit pro Anfrage (v0.31.0) — Pendant zu
			// `inquiry_invitations` der App: Mitglieder der Eigentümer-Gruppe einer
			// Anfrage werden gefragt „Bist du dabei?" und antworten mit Zusage/
			// Vielleicht/Absage. Eine Zeile pro (Anfrage, User) — UNIQUE, Upsert.
			// status: invited (angefragt) | accepted | maybe | declined. Self-RSVP
			// ohne vorherige Einladung ist erlaubt (App-Migration 094). Nur für
			// Gruppen-Anfragen (owner_group_id) — Solo-Anfragen haben kein Team.
			dbDelta( "CREATE TABLE {$inq_team} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				inquiry_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'invited',
				invited_by bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL,
				responded_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY inquiry_user (inquiry_id,user_id),
				KEY user_id (user_id)
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
