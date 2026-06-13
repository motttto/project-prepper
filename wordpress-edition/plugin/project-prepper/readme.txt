=== Project Prepper ===
Contributors: motttto
Tags: inventory, rental, equipment, availability, booking
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.14.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Equipment inventory, projects & rentals for teams — categories with number ranges, availability checks and rental management.

== Description ==

Project Prepper manages equipment inventory and rentals directly in WordPress:

* Inventory items with automatic inventory numbers (number range per category prefix)
* Categories with icon and prefix
* Search and filters across name, number, manufacturer, tags
* Projects: plan events with equipment bookings, a schedule, checklists, tasks, a cost breakdown with VAT, budget and revenue, materials, a team & contacts list and attached files from the media library — confirmed projects block inventory just like rentals
* Groups: share projects with a team of WordPress users — group projects are only visible to their members, site-level projects keep working as before
* Rental management: borrower, period, deposit, fee
* Availability checks across overlapping rental and project booking periods
* Status flow: Reserved → On loan → Returned (or Cancelled)
* Inquiry pipeline: public request form feeds an admin pipeline (New → Contacted → Offer → Won/Lost), one-click conversion into a rental
* Public frontend via shortcodes and Gutenberg blocks: equipment list, availability check, request form, item detail pages
* XLSX/CSV import and export
* Email notifications with editable templates
* iCal calendar feed of all reserved/active rentals
* Roles & capabilities: Prepper Manager and Prepper Member
* GDPR tools: export and anonymization of borrower data via the WordPress privacy tools
* Activity log of all changes

Target audience: event technology crews, clubs and associations, rental businesses, workshops and maker spaces.

The plugin is fully translatable; a complete German (de_DE) translation is bundled.

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/` or install the ZIP via Plugins → Add New.
2. Activate the plugin — the database tables are created automatically.
3. Open the "Project Prepper" menu item in the admin.

== Frequently Asked Questions ==

= How do visitors request equipment? =

Add the `[pp_request_form]` shortcode (or the "PP: Request form" block) to any page. Submissions land in the inquiry pipeline under Project Prepper → Inquiries and can be converted into a rental with one click.

= Can I show my inventory publicly? =

Yes. Use the `[pp_inventory]` shortcode or the "PP: Equipment list" block. Only non-sensitive fields are shown — purchase price, serial numbers and borrower data are never exposed. Each item also gets a public detail page under `/equipment-item/{number}`.

= Does the plugin send emails? =

Yes, optionally: borrowers receive confirmation emails on reservation, handout and return, and the site admin is notified about new inquiries. Templates are editable under Project Prepper → Settings. Emails are sent via `wp_mail()`, so SMTP plugins work out of the box.

= Is the plugin GDPR-friendly? =

Borrower data can be exported and anonymized via the WordPress core privacy tools (Tools → Export/Erase Personal Data, search by email address).

== Screenshots ==

1. Inventory admin: KPI cards, quick-add form, search, category filters and the item list with "on loan" badges.
2. Rentals admin: create a rental with line items, hand out, take back or cancel — with status badges.
3. Inquiry pipeline: requests from the public form move through New → Contacted → Offer → Won/Lost and convert into rentals with one click.
4. Public equipment list via the [pp_inventory] shortcode or block — search, category badges and daily rates.
5. Public request form via the [pp_request_form] shortcode or block — visitors pick equipment and a period.
6. Public item detail page with description, tags, condition and (optionally) the daily rate.

== Credits ==

This plugin bundles the SheetJS Community Edition (https://sheetjs.com, file `admin/js/vendor/xlsx.full.min.js`)
for the XLSX import/export in the admin. SheetJS CE is licensed under the Apache License 2.0
(https://www.apache.org/licenses/LICENSE-2.0), Copyright (C) 2013-present SheetJS LLC.

== Changelog ==

= 0.14.0 =
* New Groups feature (foundation): create groups of WordPress users and assign a project to a group
* Group projects are only visible to their members (and admins); site-level projects (no group) keep behaving exactly as before — the change is fully additive
* New "Groups" admin page: list groups, create them, manage members (add site users as founder/member, remove; the last founder is protected)
* New "Group" selector in the project detail and create form ("— site level —" by default)
* New capability "pp_groups_manage" granted to Administrator and Prepper Manager
* Deleting a group returns its projects to site level (projects are never deleted)

= 0.13.1 =
* Inventory item detail now lists the projects an item is booked in (name, status, quantity and period) — the named counterpart to the aggregated "out" badge
* Items are deep-linkable in the admin via #pp-item-{id} (used by the project booking links)

= 0.13.0 =
* New Files section in the project detail: attach files from the WordPress media library to a project; each file shows as a link with its MIME type and can be removed
* Detaching a file only removes the link — the media item stays in the library; orphaned links (deleted media) are flagged

= 0.12.0 =
* New Materials section in the project detail: consumables with name, quantity, unit and optional cost, plus a total material cost
* New Team & Contacts section in the project detail: team members (name, role, department) and external contacts (name, role, company, email, phone)

= 0.11.0 =
* New Costs section in the project detail: cost items with category (personnel, material, inventory, external services, other), description, planned and actual net amounts, VAT rate per line and an "exclude from profit" flag
* Project budget and revenue (net) fields with a summary block: planned/actual net and gross, budget variance (highlighted when over budget) and profit (revenue minus actual costs)

= 0.10.0 =
* New Schedule section in the project detail: chronological run-of-show entries with date, optional time range (from/to), title and location
* Add and remove schedule entries inline; entries are sorted by date and start time, undated entries last

= 0.9.1 =
* Inventory overview now reflects projects too: the "out today" KPI, the per-item badge and the "out" filter count confirmed/running project bookings in addition to rentals (same logic as the availability check)
* Renamed the inventory badge/filter wording from "on loan" to the more accurate "out", since items can now be out for a project as well as a rental

= 0.9.0 =
* New Projects module: projects with their own number range (P-YYYY-NNNN), status flow Draft → Planned → Confirmed → Running → Done (or Cancelled), venue and client data
* Equipment bookings per project — line items with optional own period (otherwise inheriting the project period) and availability checks
* Availability is now checked across rentals AND confirmed/running projects: a confirmed project blocks inventory for overlapping rentals and vice versa
* Checklists per project: multiple lists with checkable entries
* Tasks per project: title, priority, due date and an open → in progress → done status toggle
* New capabilities pp_projects_view and pp_projects_edit (Administrator and Prepper Manager get both, Prepper Member can view)

= 0.8.2 =
* Directory assets for wordpress.org (banner, icon, screenshots) and a Screenshots section in the readme

= 0.8.1 =
* Plugin Check compliance: all database queries use %i identifier placeholders with $wpdb->prepare()
* All request parameters are unslashed and sanitized before use
* "Tested up to" bumped to WordPress 7.0

= 0.8.0 =
* Internationalization: all source strings are now in English; a complete German (de_DE) translation is bundled (admin UI, frontend, emails, blocks)
* JavaScript admin UI and block editor use wp.i18n with script translations
* Email default templates, export column headers and condition labels are translatable
* Import column auto-mapping now recognizes German and English headers (and no longer mismatches "Serial number" columns)
* readme.txt rewritten in English for the wordpress.org directory

= 0.7.0 =
* Öffentliche Artikel-Detailseite unter /equipment-item/{inventarnummer} — Theme-überschreibbares Template, Inventar-Karten verlinken darauf; defekte/ausgemusterte Artikel liefern 404 (außer für eingeloggte Inventar-Nutzer)
* Neue Einstellung "Tagessätze öffentlich zeigen" für die Detailseite (Kaufpreis und Seriennummer sind öffentlich nie sichtbar)
* Anfragen-Pipeline wie in der App: Neu → Kontaktiert → Angebot → Gewonnen | Verloren (mit erzwungenen Übergängen); "In Verleih übernehmen" markiert die Anfrage als Gewonnen
* "PDF anzeigen"-Link direkt in der Inventar-Liste: ein Dokument öffnet sofort, mehrere öffnen das Detail-Modal
* Neue Artikel-Felder "Eigentum & Abschreibung": Eigentumsart, Finanzierungsquelle, Abschreibungsmethode, Nutzungsdauer, Restwert (reine Dokumentation)
* Kategorien zusammenführen: alle Artikel wandern in eine Ziel-Kategorie, die Quelle wird gelöscht

= 0.6.0 =
* Excel-Import/-Export im XLSX-Format (SheetJS, lokal gebündelt — kein CDN): Export-Button erzeugt inventar-JJJJ-MM-TT.xlsx mit den aktuellen Filtern, CSV-Export bleibt als zweiter Button erhalten
* Import-Dialog akzeptiert zusätzlich .xlsx/.xls — gleiche Spalten-Zuordnung wie beim CSV-Import, Datums-Zellen werden automatisch als JJJJ-MM-TT übernommen
* Import übernimmt jetzt auch das Kaufdatum (wurde bisher ignoriert), auch im Format TT.MM.JJJJ
* CSV-Export berücksichtigt den Filter "Ausgeliehen"

= 0.5.0 =
* Foto und PDF-Dokumente direkt beim Anlegen eines Artikels hochladen
* Inventar-Filter "Ausgeliehen": Toggle neben den Kategorie-Pills, Badge "n unterwegs" in der Liste
* Anfrage-Detail: Zeile klickbar, Modal mit allen Feldern, vollständiger Nachricht, Equipment-Liste und Aktionen

= 0.4.0 =
* Verleih bearbeiten: Header-Felder und Positionen (Menge, Tagessatz) nachträglich änderbar — mit Verfügbarkeitsprüfung
* Tagessatz pro Position direkt beim Anlegen eines Verleihs
* Anfrage → Verleih übernehmen (ein Klick im Anfragen-Admin)
* Öffentliches Frontend blendet defekte/ausgemusterte Artikel aus (übersteuerbar via show_all="yes")

= 0.1.0 =
* Grundgerüst: Inventar, Kategorien, Verleih mit Verfügbarkeitsprüfung, REST-API, Rollen/Capabilities, Activity-Log.
