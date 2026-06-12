=== Project Prepper ===
Contributors: motttto
Tags: inventory, rental, equipment, availability, booking
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Equipment inventory & rentals for teams — categories with number ranges, availability checks and rental management.

== Description ==

Project Prepper manages equipment inventory and rentals directly in WordPress:

* Inventory items with automatic inventory numbers (number range per category prefix)
* Categories with icon and prefix
* Search and filters across name, number, manufacturer, tags
* Rental management: borrower, period, deposit, fee
* Availability checks across overlapping rental periods
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

== Credits ==

This plugin bundles the SheetJS Community Edition (https://sheetjs.com, file `admin/js/vendor/xlsx.full.min.js`)
for the XLSX import/export in the admin. SheetJS CE is licensed under the Apache License 2.0
(https://www.apache.org/licenses/LICENSE-2.0), Copyright (C) 2013-present SheetJS LLC.

== Changelog ==

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
