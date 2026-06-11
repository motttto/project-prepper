=== Project Prepper ===
Contributors: motttto
Tags: inventory, rental, equipment, verleih, inventar
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Equipment-Inventar & Verleih für Teams — Kategorien mit Nummernkreisen, Verfügbarkeitsprüfung und Verleih-Verwaltung.

== Description ==

Project Prepper verwaltet Equipment-Inventar und Verleihvorgänge direkt in WordPress:

* Inventar-Artikel mit automatischen Inventarnummern (Nummernkreis pro Kategorie-Prefix)
* Kategorien mit Icon und Prefix
* Suche und Filter über Name, Nummer, Hersteller, Tags
* Verleih-Verwaltung: Leiher, Zeitraum, Kaution, Gebühr
* Verfügbarkeitsprüfung über überlappende Verleihzeiträume
* Status-Flow: Reserviert → Verliehen → Zurückgegeben (oder Storniert)
* Rollen & Capabilities: Prepper Manager und Prepper Mitglied
* Activity-Log aller Änderungen

Zielgruppe: Veranstaltungstechnik-Crews, Vereine, Verleiher, Werkstätten und Maker-Spaces.

== Installation ==

1. Plugin-Ordner nach `wp-content/plugins/` laden oder als ZIP installieren.
2. Plugin aktivieren — die Datenbank-Tabellen werden automatisch angelegt.
3. Menüpunkt "Project Prepper" im Admin öffnen.

== Changelog ==

= 0.4.0 =
* Verleih bearbeiten: Header-Felder und Positionen (Menge, Tagessatz) nachträglich änderbar — mit Verfügbarkeitsprüfung
* Tagessatz pro Position direkt beim Anlegen eines Verleihs
* Anfrage → Verleih übernehmen (ein Klick im Anfragen-Admin)
* Öffentliches Frontend blendet defekte/ausgemusterte Artikel aus (übersteuerbar via show_all="yes")

= 0.1.0 =
* Grundgerüst: Inventar, Kategorien, Verleih mit Verfügbarkeitsprüfung, REST-API, Rollen/Capabilities, Activity-Log.
