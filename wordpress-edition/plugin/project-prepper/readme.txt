=== Project Prepper ===
Contributors: motttto
Tags: inventory, rental, equipment, verleih, inventar
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.7.0
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

== Credits ==

Dieses Plugin bündelt die SheetJS Community Edition (https://sheetjs.com, Datei `admin/js/vendor/xlsx.full.min.js`)
für den XLSX-Import/-Export im Admin. SheetJS CE ist lizenziert unter der Apache License 2.0
(https://www.apache.org/licenses/LICENSE-2.0), Copyright (C) 2013-present SheetJS LLC.

== Changelog ==

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
