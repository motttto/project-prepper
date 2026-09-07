# 05 — Member-Portal (Frontend-Self-Service) & Föderation

> Architektur + Roadmap für die geklärte Vision (User 2026-06-13/14, siehe Memory
> [[grundkonzept]]): jede WP-Instanz = **Plattform**, auf der Single-User Kollektive
> (= Gruppen) **selbst gründen/​beitreten**; **Mitglieder loggen sich im Frontend ein**
> (nicht wp-admin), besitzen **eigenes Inventar**, teilen in Gruppen; Beitritt per
> Mitglieder-Voting; nichtkommerzielles Ressourcen-Teilen; Fernziel **Föderation**.
> Verbindlich vor weiterem Frontend-Bau.

## Leitprinzip (präzisiert 2026-06-13)
Eine Installation = eine **Plattform/Architektur** (self-hosted, Datenhoheit, open source), auf der sich **mehrere Kollektive selbst bilden**. Ein **Kollektiv = eine vom User gegründete Gruppe** — die Instanz ist NICHT a priori einem Kollektiv zugeordnet; url.xyz ist der Startpunkt für Single-User, um ein Kollektiv zu gründen/​beizutreten. **Admin = Plattform-Betreiber** (stellt die Architektur, einziger mit wp-admin, Moderation). **Mitglieder erleben alles im Frontend** (Theme), kein wp-admin. → faktisch das **Multi-Tenant-Modell der App (User-First + selbst gegründete Gruppen), self-hosted** — es braucht eine vollwertige **Front-End-Mitglieder-App**.

## Architektur-Entscheidungen

### 1. Per-User-Inventar-Ownership (Fundament)
- Neue Spalte `owner_user_id bigint unsigned NULL` auf `pp_items` (und ggf. `pp_categories`).
  - `NULL` = **Kollektiv-/Haus-Inventar** (vom Admin verwaltet — bestehendes Verhalten, additiv/abwärtskompatibel).
  - gesetzt = **gehört diesem Mitglied**.
- Spiegelt das App-Modell (`owner_profile_id`), aber als additive Spalte — kein Bruch der bestehenden site-weiten Daten.

### 2. Mitglieder-Rolle ohne wp-admin
- Rolle `pp_member` (existiert schon als Cap-Träger) → **ohne `read`-Zugang zur wp-admin-UI**: bei `admin_init` Nicht-Admins (außer AJAX/REST) vom Backend auf die Frontend-Mitglieder-Seite umleiten. Admin behält wp-admin.
- Mitglieder-Caps: eigenes Inventar CRUD, eigene Gruppen, in Gruppen teilen, anfragen/leihen — alles **scoped auf `owner_user_id = aktueller User`**.

### 3. Front-End-Mitglieder-App (Theme + Plugin)
- Auslieferung über das **Theme „Prepper Site"** (Seiten/Templates) + Plugin-**Shortcodes/Blöcke** für die App-Bereiche (z. B. `[pp_member_dashboard]`, `[pp_my_inventory]`, `[pp_my_groups]`, `[pp_browse]`).
- Nutzt die **bestehende REST-API**, aber mit **per-User-Authorisierung in PHP** (jede Route prüft owner_user_id / Gruppen-Mitgliedschaft — kein RLS-Netz, Sicherheitsfläche sorgfältig).
- Login/Registrierung im Frontend (WP-Auth, Custom-Login-Seiten; Registrierungs-Modus konfigurierbar, Default Admin-Freigabe — s. „Offene Detail-Entscheidungen").

### 4. Sharing-Modell (Kern der Vision)
- Mitglied teilt **eigenes** Inventar in eine **Gruppe** (vorhandenes Gruppen-Overlay erweitern: Items mit owner_user_id, sichtbar/leihbar für Gruppenmitglieder).
- **Nichtkommerziell:** Fokus auf Leihen/Tauschen (ggf. „Gegenleistung"-Freitext statt Preis), nicht Verkauf.

### 5. Föderation (Fernziel, NICHT jetzt)
- Eigene **REST-API zur Instanz-Erkennung**: Instanzen finden sich (opt-in), eingrenzbar per **PLZ**, sortiert nach **Thema**; instanzübergreifende Inventar-Sichtbarkeit. Eigener Lauf weit später; Datenschutz/Opt-in kritisch.

## Umsetzungsstand
- **Phase 0 + 1 umgesetzt (Plugin v0.25.0 / Schema 0.16.0 / Theme „Prepper Site" v0.3.0, 2026-06-14):**
  - Theme-Frontpage als Plattform-Landing (Hero „Ressourcen als Kollektiv teilen", 3-Schritte „Gründen/Beitreten · Inventar einbringen · Verleihen", CTA → `/portal/`).
  - `owner_user_id` (NULL = Kollektiv) auf `pp_items` + `pp_categories` — additiv, Bestandsdaten unverändert.
  - Rolle `pp_member` wird vom wp-admin ferngehalten (Redirect auf Portal + Admin-Bar aus); Admin/Manager behalten Backend (`MemberPortal::is_member_only()`).
  - Front-End-Portal `[pp_member_portal]` (Auto-Seite „portal"): Login (WP-Auth) + Einladungs-Hinweis · für Mitglieder Begrüßung + eigene Kollektive + ehrliche „In Vorbereitung"-Kacheln (Gründen/Beitreten/Mein Inventar).
  - Einladungs-only by design (kein offenes Signup); security by design (Portal liest nur eigene Daten).
  - de_DE vollständig (POMO-kompiliert), Plugin Check sauber (nur dev-only `.wp-env.json` + erwarteter Stable-Tag, beide ok).
- **Phase 2 umgesetzt (Plugin v0.26.0 / Schema 0.17.0, 2026-06-14):**
  - `GroupGovernance`-Service + Tabellen `pp_group_invitations` / `pp_group_invitation_votes` (Pendant zu App-Migration 072).
  - Frontend im Portal: **Kollektiv gründen** (Mitglied wird Gründer), **per E-Mail einladen**, **Einladungen annehmen/ablehnen**, **Beitritts-Voting** (Zustimmen/Ablehnen, Zähler `X/Y Zustimmungen`).
  - **Einstimmigkeit** wie App: bei Annahme mit nur 1 aktivem Mitglied sofort Beitritt; sonst Voting, eine Ablehnung → rejected, alle approve → approved + Beitritt. E-Mail-Einladungen werden bei Registrierung verknüpft (`user_register`).
  - **Superadmin/Admin-Override** bleibt das bestehende Add/Remove im Groups-Admin (umgeht Voting).
  - Cap `pp_collectives` (auch Mitglieder); de_DE per POMO; Plugin Check 0 echte Errors; Voting-Lifecycle in wp-env getestet (Gründen/Auto-Join/Einstimmig/Ablehnung/Zugriffsschutz) + Screenshot.
- **Phase 3 umgesetzt (Plugin v0.27.0 / Schema 0.18.0, 2026-06-14):**
  - `MemberInventory`-Service (scoped auf `owner_user_id`) + Tabelle `pp_item_group_shares` (Pendant zu `inventory_group_shares` der App, vereinfacht).
  - Portal „Mein Inventar": eigene Artikel **anlegen/bearbeiten/löschen** (Name, Kategorie, Menge, Zustand, Tagessatz, Beschreibung) und **mit Kollektiven teilen/zurücknehmen** (Chip-Toggle pro Gruppe).
  - Strikte per-User-Scoping-Guards (nur eigene Items sicht-/änderbar; Teilen nur mit eigenen Gruppen); Löschen entfernt auch die Freigaben. `Inventory::create_item/items()` um `owner_user_id` erweitert.
  - de_DE per POMO; Plugin Check 0 echte Errors; Lifecycle in wp-env getestet (anlegen/scope-guard/teilen/Liste-mit-Owner/Fremd-Gruppe-blockiert/zurücknehmen/ändern/löschen-mit-cascade) + Screenshot.
- **Phase 4 umgesetzt (Plugin v0.28.0 / Schema 0.19.0, 2026-06-14):**
  - `Borrowing`-Service + Tabelle `pp_borrow_requests`. Nichtkommerziell (keine Gebühren).
  - Portal: „In deinen Kollektiven verfügbar" (geteilte Items je Gruppe, Owner sichtbar) + **Leih-Anfrage** (Zeitraum + Nachricht); „Meine Leih-Anfragen" (abbrechen) und „Leih-Anfragen für deine Artikel" (annehmen/ablehnen/zurückgegeben). Status requested→approved|declined|cancelled→returned.
  - Guards: nur im eigenen Kollektiv + nur dort geteilte Items, nie eigene; nur der Eigentümer entscheidet; Eigentümer/Leiher markieren Rückgabe.
  - de_DE per POMO; Plugin Check 0 echte Errors; Leih-Lifecycle in wp-env getestet (Anfrage/Guards/Annahme/Listen/Rückgabe/eigenes-Item-blockiert) + Screenshot.
- **Feinschliff-Lauf umgesetzt (Plugin v0.29.0, 2026-06-14):**
  - **Verfügbarkeit:** Leih-Anfrage nur genehmigbar, wenn im Zeitraum eine Einheit frei ist (`Borrowing::available_units()`, überlappende genehmigte Leihen zählen gegen die Menge → keine Überbuchung).
  - **E-Mail-Benachrichtigungen** (über bestehendes `Email\Notifications` + editierbare Templates + on/off-Schalter): Einladung → Eingeladene/r; neue Leih-Anfrage → Eigentümer; Entscheidung → Anfragende/r. Hooks `pp_group_invited` / `pp_borrow_requested` / `pp_borrow_decided`. de_DE/POMO; in wp-env verifiziert (Überlappung blockiert/erlaubt bei qty 1↔2, Mail-Versand an korrekte Empfänger, deutsche Betreffs).
- **Feature-Schalter und Mehrbenutzer-Härtung (2026-09-07, v0.145.0, Schema unverändert 0.43.0):**
  - **Funktionen abschaltbar:** `Settings::features()` (Option `pp_features`, fehlende Schlüssel = an; Dashboard und Kollektive nicht schaltbar). Ein Schalter greift an allen Einstiegen: `nav_items()` filtert, `current_view()` fällt aufs Dashboard zurück, der Dispatcher weist Aktionen über das Präfix ab (`action_feature()`: `item_*`/`category_*`/`inventory_*` → inventory, `rental_*`/`borrow_*`/`booking_*` → lending, `project_*`/`sched_*`/… → projects, `inquiry_*`/`inqteam_*`, `calevent_*`, `poll_*`, `fed_*`) mit Meldung `feature_off`, die vier Standalone-Handler (Export/Import/Foto/Dokument, Projektdatei) sind gegated, Dashboard-Kacheln und Schnellaktionen fallen weg, die öffentlichen Shortcodes (`pp_inventory`, `pp_availability`, `pp_request_form` + Submit) geben nichts aus. Karte „Funktionen" in den Einstellungen, Labels aus PHP (`feature_labels()`). Daten bleiben — nur die Sicht verschwindet (Muster wie beim Kollektiv-Verleih-Schalter).
  - **Optimistic Locking:** Formulare für Artikel (Verwalten-Modal, Autosave), Projekt und Verleih tragen `pp_seen` = gelesener `updated_at`; `Inventory::update_item()`, `Projects::update()`, `Rentals::update()` nehmen `?string $expect` und schreiben `WHERE updated_at = $expect` — 0 Zeilen → `WP_Error('pp_stale')` → Meldung `stale`. Beim Verleih sitzt die Prüfung VOR dem Positions-Diff (sonst löschte ein Bearbeiter mit alter Seite fremde neue Positionen). Ohne `pp_seen` (Anlegen, alte Aufrufer, REST) unverändertes Verhalten. Vorbild war das bedingte `set_status()` aus v0.141.0.
  - **Doppelklick-Schutz:** `BookingApprovals/RentalApprovals::approve()` (`WHERE approval_status = 'pending'`) und `Borrowing::decide()` (`WHERE status = 'requested'`) gewinnen nur einmal → `pp_not_pending`; vorher feuerten Log und Mails doppelt.
  - **Checkliste:** Formular postet den Wunschzustand (`pp_checked`) statt serverseitig zu kippen — mit veralteter Seite kippte der Haken in die falsche Richtung.
  - **Autosave-Modal:** `beforeunload`-Warnung bei ungespeichertem Formular (das Modal speichert nur beim Schließen; Tab zu = Verlust). Bewusst NICHT gebaut: Presence „X bearbeitet gerade" (bis zu 3 Minuten veraltet, als Sperre unbrauchbar) und Verfügbarkeits-Transaktionen (TOCTOU beim letzten Gerät bleibt; braucht Sperren, die auf Shared Hosting nicht verlässlich sind).

- **Aufgabenliste des Users, Releases 1–3 (2026-09-07, v0.142.0–v0.144.0; Schema 0.42.0 → 0.43.0 in v0.144.0):**
  - **Kopfzeile (v0.142.0):** Der Seitentitel wandert in die Topbar. Mechanik in `render_app()`: der Inhalt wird gepuffert, das erste `<h1 class="pp-app__page-title">` herausgehoben und der Topbar übergeben — die Views behalten ihre Köpfe (auch Detail-Titel aus geladenen Objekten), ohne 16 Umbauten. Dazu Begrüßung „Hallo Name" und eine Uhr (Server: `date_i18n` in WP-Zeitzone; `portal.js` tickt minütlich mit `Intl.DateTimeFormat` in `ppPortal.tz`/`locale`, Datum und Uhrzeit getrennt formatiert und mit „ · " verbunden — sonst schöbe Intl ein „um" dazwischen; bei Offset-Zeitzonen in alten Browsern bleibt der Servertext stehen). Profilbild = POST-Formular `set_workspace pp_ws=solo pp_view=dashboard` (Arbeitsbereich wechselt nur per Nonce-POST). „So funktioniert die Plattform" ist View `howto` + Menüpunkt, das Dashboard-Banner ist weg.
  - **Dashboard (v0.143.0):** Zwei Zähler statt einem — `MemberInventory::own_count()` und `accessible_count( $uid, $group_ids )` (fremde, mit einer meiner Gruppen geteilte, nicht ausgemusterte Artikel; ein COUNT statt Zeilenlisten). Schnellaktionen als Kacheln `.pp-qa`; dafür der Mechanismus `?pp_open=<id>` in `portal.js`: öffnet `<details>`/`<dialog>` mit dieser ID beim Laden, setzt den Fokus, entfernt den Parameter per `replaceState`. IDs: `pp-item-new`, `pp-rental-new`, `pp-project-new`, `pp-invite-member`, `pp-inquiry-new`, `pp-event-create`, `pp-poll-create`. Gruppen als Kachelraster mit Link, Profil kompakt, beide in `.pp-dash-cols`.
  - **Verleih wie eine Rechnung (v0.144.0):** `Rentals::billing()` liefert `lines[]` (Zeilensumme = Tagessatz × Tage × Menge), `subtotal` (Σ oder Pauschale `rental_fee` — auch 0,00 € ist eine Pauschale; der frühere Falsy-Check fiel bei 0 auf die Summe zurück), `discount` (Prozent vom Zwischenbetrag oder Betrag, gedeckelt), `gross/net/vat` daraus. Neue Spalten `discount_type`/`discount_value` (Schema 0.43.0); USt nutzt die bestehende `vat_rate`, das Portal schreibt sie jetzt (0 als 0.00, nicht NULL). Formularfelder `pp_vat`, `pp_discount_type`, `pp_discount`; REST nimmt `discount_type`/`discount_value` an. Karte rendert die Positionen im neuen Listen-Baukasten `.pp-list` (Kopf-, Gruppen-, Unterzeile, `--num`-Zellen, Summenfuß); `item_owner`-Karten bleiben ohne Beträge. „Neuer Verleih" und „Bearbeiten" als `.pp-modal--full` (UA-Grenzen des `<dialog>` explizit aufgehoben). Inventar-Listen (`.pp-inv-row`, `.pp-ginv`) auf denselben Kopf-/Zeilenrhythmus gebracht.

- **Storno-Link für externe Leiher, Verleih-Karten, atomarer Statuswechsel (2026-09-07, Schema unverändert 0.42.0):**
  - **Storno ohne Konto:** Die Reservierungsmail trägt `{{cancel_url}}` → `admin-post.php?action=pp_rental_cancel&rental=ID&key=…`. Der Schlüssel ist ein HMAC aus Verleih-ID und Leiher-Adresse (`Rentals::cancel_token`, dasselbe Schema wie der Beitritts-Link) — kein Datenbankfeld, eine Adressänderung entwertet den alten Link. `Frontend\RentalCancel` (admin_post_nopriv): **GET zeigt nur eine Bestätigungsseite, erst POST storniert** — Mailprogramme und Virenscanner rufen Links vorab auf. Nur `reserved` ist stornierbar; bei ausgegebenem Equipment verweist die Seite an den Anleger. Danach zwei editierbare Mails: `rental_cancelled` (Leiher) und `rental_cancelled_by_borrower` (Anleger, ohne Anleger an `admin_email`). Angepasste alte Reservierungsvorlagen ohne den Platzhalter bekommen den Link angehängt.
  - **Statuswechsel atomar:** `Rentals::set_status()` schreibt `UPDATE … WHERE id AND status = <gelesener Status>` und bricht bei 0 Zeilen VOR Log und Hooks ab. Vorher lösten parallele Requests (Review-Fund, mit 12 gleichzeitigen POSTs reproduziert) Log und Mails doppelt aus.
  - **Karte:** Summenzeile `.pp-rsum` (Stückzahl · Tage · Endbetrag inkl. USt) unter dem Kopf; Positionen im festen Raster `.pp-rl__name / __qty / __rate` statt Flex mit `margin-left:auto`, das Eigentümer, Menge und Preis je Zeile verschob. Auf `item_owner`-Karten bleiben Betrag und Tagessatz verborgen.
  - **Dark Mode:** `.pp-app__user-name` hatte keine eigene Farbe und erbte die Theme-Farbe — jetzt explizit `var(--pp-foreground)`.

- **Verleih-Sichtbarkeit, Rüstzeiten und Zeitstatus (2026-09-06/07, Schema 0.41.0 → 0.42.0 — nur ein Datenlauf, keine Strukturänderung):**
  - **Anlass aus dem Betrieb:** Ein Mitglied legte im Kollektiv einen externen Verleih an, der für alle anderen unsichtbar blieb — weder in der Verleih-Liste noch im Kalender noch im iCal-Abo. Ursache waren drei zusammenwirkende Entscheidungen: `MemberRentals::create()` schrieb `owner_group_id` nie, und Liste, Kalender und Feed lasen ausschließlich `for_owner()`.
  - **Drei Sichtbarkeitswege**, gebündelt in `MemberRentals::visible( $user, $group )` (bzw. `visible_everywhere()` fürs Abo, das keinen aktiven Arbeitsbereich kennt): `own` (selbst angelegt, arbeitsbereichs-übergreifend), `group` (im Kollektiv angelegt) und `item_owner` (fremder Vorgang mit eigenem Equipment — wirkt rückwirkend, weil er an den Positionen hängt). Jede Zeile trägt `pp_relation` + `pp_can_edit`.
  - **Schreiben bleibt beim Anleger**: `owns()` prüft nur `owner_user_id`; wer den Vorgang aufgesetzt hat, haftet gegenüber dem externen Leiher und kassiert die Kaution. Alle anderen sehen eine Lese-Karte. Auf `item_owner`-Karten bleiben Preise, Kaution und die interne Notiz verborgen — der Eigentümer soll wissen, wo sein Gerät ist, nicht wie der Anleger kalkuliert.
  - **Der Auswahl-Pool folgt dem VERLEIH**, nicht dem offenen Arbeitsbereich: Kollektiv-Verleih + Mitgliedschaft → Kollektiv-Pool, sonst eigenes Inventar. Sonst verlöre ein persönlicher Verleih, der im Gruppen-Arbeitsbereich gespeichert wird, seine Positionen; und ein ausgetretenes Mitglied sähe weiter das geteilte Inventar.
  - **Rüstzeiten** (Betreiber-Einstellung, Tage vor/nach einer Ausleihe) über ZWEI Fenster in `Availability`: `booking_window()` weitet beidseitig um (vorher+nachher) — beide beteiligten Vorgänge brauchen Zeit —, `occupancy_window()` asymmetrisch `[tag−nachher, tag+vorher]` für „ist heute gebunden" (`out_now`, `stats`, `timeline`). Umgesetzt durch Verschieben des Fensters statt SQL-Datumsfunktionen, damit die Indizes auf `date_from`/`date_to` greifen.
  - **Zeitstatus am Artikel** (`Availability::timeline()`, EINE Query je Liste): Chip `.pp-when` in der Spalte „Verfügbar" — „frei bis …", „unterwegs bis …", „frei ab …". Bewusst zurückhaltend formuliert, sobald mehr als ein Stück im Spiel ist, und ohne Rückkehrdatum bei Sets: deren Verfügbarkeit ist `min(floor(frei/Bedarf))`, ein einzelnes Teil-Datum beschreibt sie nicht. Nennt eine Anschlussbuchung das Datum wertlos, bleibt es leer.
  - **Einstellungen** (`ProjectPrepper\Settings`, wp_options, kein Schema): Kollektiv-Sichtbarkeit an/aus, Zeitstatus an/aus, Rüstzeit vorher/nachher (0–30 Tage). Abgeschaltete Sichtbarkeit blendet auch bestehende Zuordnungen aus und vergibt keine neuen.
  - **Einmalige Zuordnung von Alt-Verleihen** (`Rentals::backfill_group_owner()`, ausgelöst über die Schema-Version): trägt `owner_group_id` nach, wenn der Anleger Mitglied ist, JEDE Position mit genau dieser Gruppe geteilt ist und mindestens eine Position jemand anderem gehört. Mehrdeutige Fälle bleiben unangetastet — lieber unzugeordnet als falsch. Jede Änderung steht im Aktivitätsprotokoll (`rental_group_backfilled`).

- **Monatskalender: mehrtägige Einträge als durchgehender Balken (2026-08-27, Schema unverändert 0.41.0):**
  - Vorher lag derselbe Eintrag als eigener Chip in JEDER Tageszelle — ein Projekt vom 10.–14. sah aus wie fünf Vorgänge. Jetzt rendert `render_month_weeks()` je Woche EIN Raster; ein Eintrag wird zu einem Segment über `grid-column: start / span n`. An Wochengrenzen zerfällt er in mehrere Segmente, deren Kanten flach bleiben (`--cont-left/right`), damit die Fortsetzung erkennbar ist.
  - **Aufbau:** EIN Raster für den ganzen Monat mit fester Zeilenvorlage — je Woche ein Block aus Tageszahl-Zeile, Spur-Zeilen und (nur falls nötig) „+n"-Zeile. Jede Kachel spannt explizit über die Zeilen IHRER Woche, jeder Balken sitzt in einer im Voraus bekannten Zeile. Überlappende Einträge bekommen je eine eigene Spur (Sortierung: lange Balken zuerst, dann von links nach rechts) und bleiben dadurch über die ganze Woche auf derselben Höhe.
  - ⚠️ **Gelernt (Fehler in v0.137.0):** Der erste Wurf nutzte je Woche ein eigenes Raster mit `grid-row: 1 / -1` für die Kacheln. `-1` zeigt aber auf die letzte EXPLIZITE Rasterlinie — sobald ein Balken (oder die „+n"-Zeile mit fester Zeilennummer) in einer IMPLIZIT erzeugten Zeile landete, spannte die Kachel nicht mehr darüber: Die Balken hingen unter den Kacheln und liefen seitlich aus dem Raster. Lehre: bei Grid-Overlays jede Zeile im Voraus deklarieren und nie mit `-1` gegen implizite Zeilen arbeiten.
  - Sichtbar sind drei Spuren (`CAL_LANES`); was darüber hinausgeht, erscheint pro betroffenem Tag als „+n weitere" — gezählt wird spurgenau, nicht pauschal.
  - **Datenseite entkoppelt:** neue Methode `calendar_entries()` liefert die vier Quellen als FLACHE Liste mit `from`/`to`; `calendar_events()` (Tages-Map für die Wochenansicht) baut jetzt darauf auf. Damit gibt es die Quellen weiterhin nur an einer Stelle.

- **Kalender-Abo enthält jetzt alles (2026-08-27, Schema unverändert 0.41.0):**
  - **Fund:** Der persönliche iCal-Feed enthielt NUR die von Hand angelegten Termine. Projekte, Zeitplan-Einträge, Kollektiv-Ausleihen und externe Verleihe zeichnet die Kalender-Ansicht selbst (`MemberPortal::calendar_events`), im Export fehlten sie. Wer im Portal überwiegend Projekte sieht, abonnierte damit einen praktisch leeren Kalender — das Abo „kam nicht an".
  - `CalendarController::personal_lines()` liefert jetzt dieselben vier Quellen, je Eintrag mit `CATEGORIES` (Projekt · Zeitplan · Ausleihe · Verleih) plus Gruppen- bzw. Projektname und einer lesbaren `DESCRIPTION`. Ganztägig mit exklusivem DTEND, Zeitplan-Einträge mit Uhrzeit als floating local time.
  - **Wichtig dabei:** Der Feed läuft ohne WP-Session (Token-Auth). `Projects::all()` bildet seinen Gruppen-Filter aber über `get_current_user_id()` — ohne gesetzten User lieferte es NUR Site-Ebene und keine Kollektiv-Projekte. `personal_lines()` setzt deshalb den Token-User per `wp_set_current_user()`; die Anfrage endet direkt danach in `emit()`, Auth-Cookies werden nicht gesetzt.
  - **Aktualisierung:** `REFRESH-INTERVAL;VALUE=DURATION:PT1H` + `X-PUBLISHED-TTL:PT1H` in beiden Feeds — vorher entschied der Client allein, wie oft er nachlädt (bei Apple „Automatisch" sehr träge).
  - **Abonnieren-Button:** `webcal://`-Variante derselben URL (`user_feed_webcal()`) — ein Klick öffnet auf Mac/iPhone direkt den Abo-Dialog, statt die .ics einmalig herunterzuladen.
  - **Nebenbei gehärtet:** `emit()` faltet Zeilen nach RFC 5545 §3.1 (max. 75 Oktetts, Fortsetzung mit führendem Leerzeichen, an Zeichengrenzen — UTF-8 bleibt heil). Lange Projektnamen erzeugten vorher überlange Zeilen; Apple verzeiht das, striktere Parser nicht.

- **Kollektiv-Verleih mit Eigentümer-Freigabe (2026-08-27, Schema 0.40.0 → 0.41.0):**
  - Im **Gruppen-Arbeitsbereich** stehen im externen Verleih jetzt dieselben Artikel zur Wahl wie in der Projekt-Buchung — der Kollektiv-Pool (`MemberInventory::items_shared_with_group`). Im **Solo-Arbeitsbereich** bleibt es beim eigenen Inventar. Vorher war der Verleih grundsätzlich auf eigene Artikel beschränkt (siehe Klassendoku `MemberRentals`, jetzt neu gefasst).
  - **Freigabe wie bei Buchungen:** Trägt die Kollektiv-Freigabe eines fremden Artikels `requires_approval`, entsteht die Verleih-Position mit `approval_status = 'pending'`; entscheiden darf nur der Eigentümer (`RentalApprovals`, Schwester von `BookingApprovals` auf `pp_rental_items`). Schema 0.41.0 ergänzt dafür `rental_items.approval_status/requested_by/decided_at` (additiv, DEFAULT `approved` → Bestandsdaten bleiben gültig).
  - **Ausgabe gesperrt, solange etwas offen ist:** `MemberRentals::set_status()` weist `active` ab, solange eine Position pending ist (`pp_rental_pending`); die Oberfläche bietet „Ausgegeben" dann gar nicht erst an. Zurückgeben/Stornieren bleibt jederzeit möglich.
  - **Erteilte Freigaben bleiben:** Ein erneutes Speichern des Verleihs setzt sie NICHT zurück. Erneut gefragt wird nur bei einer materiellen Änderung — mehr Stück oder anderer Zeitraum (dieselbe Regel wie `BookingApprovals::is_material_change`).
  - **Freigaben-Ansicht** zeigt beide Arten getrennt („Für ein Kollektiv-Projekt" / „Für einen externen Verleih") mit demselben Sammel-Formular; je Anfrager EINE Ergebnis-Mail (`pp_rental_approvals_requested` / `pp_rental_approvals_decided`, Templates `rental_requested_list` / `rental_decided_list`).
  - **Preis:** Der Tagessatz einer fremden Position kommt aus der Freigabe des Eigentümers (`share_daily_rate`), sonst vom Artikel — der Eigentümer sieht ihn in der Anfrage und kann ablehnen. **Bewusst offen gelassen:** wohin die Einnahmen fließen (Eigentümer / Gruppenkasse / anteilig). Der Verleih selbst bleibt persönlich (`owner_user_id`), weil Haftung und Kassieren gegenüber dem externen Leiher an einer Person hängen.

- **Gesperrte Artikel: ein Zustand, der überall gilt (2026-08-27, Schema unverändert 0.40.0):**
  - Auf die Frage „können wir nicht einen Status pro Artikel führen, der global gilt?": **Verfügbarkeit** bleibt berechnet (sie hängt am Zeitraum und an der Menge — eine Spalte könnte nur „jetzt gerade" beantworten und müsste von jedem Schreibweg UND von der vergehenden Zeit gepflegt werden). Was dagegen wirklich am Artikel gehört, ist die **Sperre**: „defekt", „in Wartung", „verschollen", „ausgemustert" lassen sich aus keiner Buchungstabelle ableiten.
  - `Inventory::CONDITIONS` um `maintenance` (in Wartung) und `lost` (verschollen) erweitert — dasselbe Feld, kein zweiter Status; `item_condition` ist `varchar(20)`, also **keine Schema-Änderung**. `Inventory::BLOCKED_CONDITIONS` = `maintenance · broken · lost · retired`; `poor` sperrt bewusst NICHT (abgenutzt ≠ unbenutzbar).
  - Durchgesetzt an EINER Stelle: `Availability::available_quantity()` liefert für gesperrte Artikel 0 — dadurch greift es zugleich in externem Verleih, Projekt-Buchung, Kollektiv-Leihe, Netzwerk-Anfrage und in allen „Verfügbar"-Spalten. Zusätzliche Guards mit eigener Meldung (`pp_item_blocked`) dort, wo der Verfügbarkeits-Zweig übersprungen werden konnte: `Projects::add_item()` (Buchung OHNE Zeitraum — hier fehlte die Prüfung bisher ganz) sowie `Borrowing::request()`/`request_bundle()`, damit die Meldung den GRUND nennt statt „nichts frei".
  - **Sichtbarkeit wie gewünscht:** defekt / in Wartung / verschollen bleiben in allen Listen stehen (Zustands-Chip in der Spalte „Zustand", Verfügbar = 0, nicht wählbar, kein „Ausleihen"-Button). **Ausgemustert verschwindet** aus Portal-Listen, Verleih-Auswahl, Kollektiv-Pool und öffentlicher Liste — erreichbar bleibt es für den Eigentümer über den Filter „Ausgemustert (n)" in „Mein Inventar" (gelöscht wird nichts).
  - Grenze: Der Zustand hängt am ARTIKEL, nicht am Einzelstück. „1 von 6 defekt" lässt sich damit nicht abbilden — dafür bräuchte es die Einzelstück-Ebene (`pp_units`, im Portal nicht angebunden). Behelf bis dahin: Menge reduzieren oder den defekten Teil als eigenen Artikel führen.

- **Verleih-Umbau + eine Verfügbarkeitsrechnung (2026-08-27, Schema unverändert 0.40.0):**
  - **Reiter „Stöbern" entfällt.** Er zeigte exakt das Kollektiv-Inventar ein zweites Mal. Der „Ausleihen"-Button (mit Leih-Modal inkl. Sets) sitzt jetzt in der **Inventar-Ansicht des Gruppen-Workspaces**, zusammen mit deren Suche, Kategorie-Pills und dem übernommenen Zeitraum-Filter (`pp_bfrom`/`pp_bto`: ohne ihn zeigt „Verfügbar" den heutigen Stand aus `out_now` = eine Abfrage, mit ihm wird je Artikel exakt gerechnet). Verleih hat noch drei Reiter (Externe Verleihe · Leih-Anfragen · Meine Leihen).
  - **Gemeinsame Artikel-Zeile** `MemberPortal::picker_row()` für alle Auswahl-Listen: Foto ganz links, Name + Inventarnummer + Chips, Meta-Zeile, rechts die Steuerelemente des Kontexts. Genutzt vom Technik-Picker im Projekt und vom Verleih-Formular, das dadurch Live-Suche, Auswahl-Anzeige („Ausgewählt: …", auch während die Suche filtert) und Verfügbarkeit je Artikel bekommt. Zeitraum beim Anlegen vorbelegt (heute → +7 Tage); 0 frei → Zeile gedimmt und nicht wählbar.
  - **EINE Quelle für Verfügbarkeit:** `Availability::available_quantity()` zählt Verleihe, Projekt-Buchungen, genehmigte Kollektiv-Leihen UND genehmigte föderierte Leihen; `Borrowing::available_units()` delegiert nur noch dorthin. Vorher kannte keine der beiden Rechnungen die andere — derselbe Artikel konnte im selben Zeitraum extern verliehen und ans Kollektiv verliehen werden. Derselbe Vier-Wege-Zähler steckt jetzt auch im `out_now`-Subquery von `Inventory::items()` und in `Inventory::stats()`.
  - **Layout:** Portal-Inhalt nutzt die volle Fensterbreite (kein `max-width` mehr), dafür kompaktere Polster; Formulare mit vielen Kopffeldern stehen ab 900 px im Raster, damit keine überlangen Eingabefelder entstehen.
- **Backend-Überarbeitung + Sicherheits-Grundgerüst (Plugin v0.30.0, 2026-06-14):**
  - **Admin-Seite „Plattform"** (`Menu::render_platform`, Cap MANAGE_GROUPS, server-gerendert): KPIs (Kollektive, Mitglieder-Inventar, offene Einladungen, aktive Leihen, offene Anfragen) + Tabellen offene Beitritts-Einladungen (mit Voting-Stand), letzte Leih-Anfragen, alle Kollektive. Hier laufen die Member-Portal-Systemprozesse für Betreiber/Moderation sichtbar zusammen. Neue Reader: `GroupGovernance::all_pending()`, `Borrowing::all_recent()`.
  - **Admin-Seite „Sicherheit"** (`Menu::render_security`, Cap MANAGE_SETTINGS) + Klasse `Security` (Option `pp_security`): **alle Schalter per Default AUS**. Login-Drosselung (IP-Lockout, voll durchgesetzt), Kollektive-pro-User-Limit (Schneeball, in `found()`), Einladungen-pro-Tag-Limit (in `invite()`), Selbst-Registrierungs-Schalter (aus = invite-only; an = `users_can_register`+Default-Rolle `pp_member`), 2FA-Schalter (gespeichert, **noch nicht durchgesetzt** — eigener Lauf). Off = unverändertes Verhalten.
  - de_DE/POMO (41 neue Strings); Plugin Check 0 echte Errors; in wp-env getestet (Defaults aus, Limits greifen bei Aktivierung, beide Seiten rendern) + 2 Screenshots.
- **2FA-Login umgesetzt (Plugin v0.31.0, 2026-06-14):**
  - `Frontend\MemberAuth`: portal-eigener 2-Schritt-Login per E-Mail-Code. Nur aktiv wenn `Security::on('member_2fa')` UND reines Mitglied (Admin/Manager → normaler wp-admin-Login). Schalter weiter default AUS.
  - Schritt 1 `pp_member_login` (nopriv): `wp_authenticate` → Code (6-stellig) mailen, Pending-Token als HttpOnly-Cookie, KEIN Auth-Cookie. Schritt 2 `pp_member_2fa`: Code prüfen → `wp_set_auth_cookie`. `render_login` verzweigt (2FA aus → `wp_login_form` unverändert; an → eigenes Formular + Code-Schritt).
  - Härtung: Code nur als `wp_hash`, 10 Min. gültig, max. 5 Versuche, generische Fehler (keine Enumeration).
  - **End-to-End in wp-env verifiziert** (curl: Schritt 1 → 302 ?pp_2fa=1 + Token-Cookie + Code-Mail; Schritt 2 mit Code → `wordpress_logged_in`-Cookie gesetzt; falscher Code abgewiesen). de_DE/POMO; Plugin Check 0 echte Errors; Screenshot.
- **Self-Service-Registrierung umgesetzt (Plugin v0.32.0, 2026-06-14):** Handler `MemberAuth::handle_register` (nopriv) + Registrierungsformular im Portal-Login, **nur sichtbar/aktiv wenn `allow_self_registration` an** (default aus = invite-only). Legt `pp_member`-User an, loggt direkt ein, verknüpft offene Einladungen (user_register-Hook). Guards: gültige/eindeutige E-Mail, min. 8 Zeichen, Honeypot. de_DE/POMO; Plugin Check 0 Errors; Logik in wp-env getestet.
- **2FA-Polish umgesetzt (Plugin v0.33.0, 2026-06-14):** „Code erneut senden" auf dem Code-Schritt (`MemberAuth::handle_resend`, max. 3 Resends/Anmeldung); 2FA-Mail nutzt jetzt ein **editierbares Template** (`member_2fa_code` in `Notifications`, {{code}}/{{minutes}}/{{site_name}}) — wird immer gesendet (sicherheitskritisch, unabhängig vom Notifications-Schalter). de_DE/POMO; Plugin Check 0 Errors.
- **Föderation Slice 1 umgesetzt (Plugin v0.34.0, 2026-06-14):** Klasse `Federation` (Option `pp_federation`, **Opt-in default AUS**) + Admin-Seite „Föderation" (PLZ/Thema/Kontakt/Auffindbar) + öffentlicher Discovery-Endpoint `GET /project-prepper/v1/federation/info` (`Rest\FederationController`). Aus → 404; an → grobes öffentliches Profil (Name, PLZ, Thema, Kollektive-/Mitglieder-Anzahl, Kontakt) — keine personenbezogenen Daten. In wp-env getestet (aus=404, an=200+Profil), Plugin Check 0 Errors.
- **Föderation Slice 2 umgesetzt (Plugin v0.35.0, 2026-06-14):** Partner-Verzeichnis — Betreiber pflegt Partner-Instanz-URLs (Föderations-Seite, eine pro Zeile, http(s)-Validierung DNS-unabhängig); `Federation::fetch()` ruft je `…/federation/info` ab (wp_remote_get, 1 h Transient-Cache), `directory()` listet sie. Admin-Tabelle „Bekannte Instanzen" (Name/PLZ/Thema/Anzahlen, „nicht erreichbar"-Flag). Outbound nur an Betreiber-URLs, nur öffentliches Profil. In wp-env getestet (HTTP gemockt: erreichbar/nicht erreichbar/Cache, URL-Parsing). Plugin Check 0 Errors.
- **Aktivitäts-Feed im Backend (Plugin v0.36.0, 2026-06-14):** Plattform-Seite zeigt jetzt „Letzte Aktivität" (`ActivityLog::recent`, 20 Einträge: Wann/Wer/Aktion mit lesbaren, übersetzten Labels via `Menu::action_label`). Damit ist „Systemprozesse im Backend sichtbar" vollständig. Plugin Check 0 Errors; Render in wp-env getestet + Screenshot.
- **Offen / als Nächstes (Föderation):** Slice 3 = **instanzübergreifendes Inventar im Frontend** (Mitglieder sehen geteiltes Inventar von Partner-Instanzen, PLZ/Thema-Filter) — größerer Brocken. Optional Backup-Codes für 2FA. Damit ist das **Member-Portal-Kernpaket (Phase 0–4) vollständig**: Plattform-Landing, Login, Kollektiv gründen/beitreten+Voting, eigenes Inventar+Teilen, Stöbern+Leihen. Sinnvolle Zwischenschritte vor Föderation: Sicherheits-Lauf (2FA/Härtung, [[grundkonzept]]-Backlog), Verfügbarkeits-/Überlappungsprüfung bei Leihen, E-Mail-Benachrichtigungen für Einladungen/Leih-Anfragen.

## Roadmap (gestuft, jede Phase testbar/ausrollbar)

| Phase | Inhalt | Aufwand |
|---|---|---|
| **0 — Ehrliche Startseite** | Theme-Frontpage als **Plattform-Landing**: „Gründe ein Kollektiv oder tritt einem bei" + Registrieren/Login-CTA (statt Single-Anbieter-Katalog). Klein, sofort. | S |
| **1 — Fundament** | `owner_user_id` auf pp_items; `pp_member`-Rolle ohne wp-admin (Redirect); **Einladungs-basierter Zugang** (Admin lädt Seed-User, kein offenes Signup) + Front-End-Login + Mitglieder-Landingpage. Security by design (scoped REST, kein wp-admin-Leak). | M |
| **2 — Kollektiv gründen/​beitreten (Frontend)** | Onboarding: registrierter User **gründet eine Gruppe (= Kollektiv)** oder tritt einer bei — vorne, ohne wp-admin. (Gruppen-Overlay existiert backend-seitig; hier Front-End + User-darf-gründen.) | M |
| **3 — Mein Inventar + Teilen (Frontend)** | Mitglied verwaltet **eigenes** Inventar vorne (anlegen/Foto/bearbeiten, REST scoped auf owner_user_id) und **teilt es in seine Gruppe(n)**. | L |
| **4 — Stöbern & Leihen (Frontend)** | Mitglieder durchsuchen geteiltes Inventar des Kollektivs, **Leih-Anfrage** (an Verleih/Leihgaben gekoppelt), nichtkommerziell. | L |
| **5+ — Föderation** | Instanz-Discovery-API (PLZ/Thema), instanzübergreifend. Weit später. | XL |

## Offene Detail-Entscheidungen (vor Phase 1/2 zu klären)
- **Bestandsinventar**: bleibt `owner_user_id=NULL` (Kollektiv-Inventar, Admin verwaltet) — ok? Oder Admin-User zuweisen?
- **Registrierung: ENTSCHIEDEN = (c) nur per Einladung** (2026-06-14). Plattform-Account: Admin lädt Seed-User ein (kein offenes Signup). Gruppen-Beitritt: Mitglieder laden ein + Gruppen-Voting (einstimmig) + Superadmin-Override. (a/b später denkbar/konfigurierbar.)
- **Front-End-App im Theme vs. Plugin**: UI-Templates im Theme, Logik/Daten im Plugin (Shortcodes/Blöcke + REST) — Theme bleibt der „Skin", Plugin das Backend. So bleibt das Plugin theme-unabhängig nutzbar.
- **„vollwertiges Theme zum Testen"** (User-Wunsch): Theme „Prepper Site" wird zum Träger der Member-App ausgebaut; nach Phase 1–2 mit dem eigenen Kollektiv testbar.

## Status der bisherigen Arbeit (Einordnung)
Plugin v0.24.0 hat: Inventar/Verleih/Anfragen/Projekte/Gruppen/Beschlüsse/Umfragen/Gewinn/Vereinbarung — aber **Admin-zentriert, site-weites Inventar**. Das bleibt als **Admin-/Kollektiv-Backend** wertvoll; das Member-Portal setzt **davor/darauf** (Frontend + per-User-Ownership). Kein Wegwerfen — Erweiterung.

## Governance & Mechaniken (aus der Next.js-App verifiziert, 2026-06-14)

### Gruppen-Beitritt = Mitglieder-Voting (App-Mechanik, deckt User-Vorgabe)
Quelle: `supabase/migrations/072_voting_triggers.sql`.
1. **Mitglied lädt ein** → `group_invitations` status `pending`.
2. Eingeladener **akzeptiert** → wenn nur 1 aktives Mitglied (Gründer): sofort `approved` (Auto-Join); sonst `voting_in_progress`.
3. Bestehende aktive Mitglieder stimmen (`group_invitation_votes`, approve/reject).
4. Auflösung: **eine Ablehnung → rejected_by_member**; **alle aktiven Mitglieder approve (approvals ≥ total_active) → approved + Mitgliedschaft aktiv** → **Einstimmigkeit**.
- **WP-Umsetzung (neu, Phase 2/3):** Mitglieder (nicht nur Admin) laden im Frontend ein; Gruppe stimmt ab (gleiche Einstimmigkeits-Logik wie der bereits gebaute Beschlüsse-Service); **Superadmin/wp-admin kann im Backend overrulen** (hart aufnehmen/entfernen, Voting übergehen). Aktuell ist Gruppen-/Mitglieder-Verwaltung nur admin-seitig → muss ins Frontend + User-darf-einladen.

### Abstimmungen/Beschlüsse — bereits WP-konform
Einstimmig (alle approve / eine reject) ODER Mehrheit (alle abgestimmt, approve>reject). In WP gebaut (Beschlüsse-Tab). ✓

### Umfragen — bereits WP-konform
Termin/Auswahl, Ja/Nein/Vielleicht pro Option. In WP gebaut (Umfragen-Tab). ✓

### Gewinnverteilung — App reicher als aktuelle WP-Variante
Quelle: `066_cooperation_agreements.sql` + `database.ts` (ProfitFormula).
- Vereinbarung: `profit_formula` jsonb, `method` ∈ hours|inventory|capital|fixed|**mixed**; mixed-Default-Gewichte `{hours:0.5, inventory:0.3, capital:0.1, fixed:0.1}`. Plus `exit_rules` (forfeit_if_exit_before_event, inventory_return_window_days).
- Beteiligten-Daten: `agreement_roles` (hourly_rate, hours_estimate, capital_contribution, fixed_amount) + `agreement_inventory_contributions` (daily_rate × quantity).
- **System verteilt den Pool automatisch** je Dimension gewichtet → contribution-based, transparent.
- **WP aktuell:** manuell Prozent/Fest (pp_project_profit_shares). Für App-Treue: **formelbasiertes Modell** (Dimensionen Stunden/Inventar/Kapital/Fix + Gewichte, Auto-Berechnung aus Beiträgen) als Erweiterung der Gewinn-Phase. → eigener späterer Lauf.

### Konsequenz für die Roadmap
- Phase 2 „Kollektiv gründen/​beitreten" wird zu **„Gründen + Einladen + Beitritts-Voting"** (Mitglieder laden ein, Gruppe stimmt ab, Superadmin-Override).
- Gewinn-Phase (später) bekommt das **formelbasierte Verteilmodell** statt nur Prozent/Fest.

## Sicherheit & Missbrauch (Backlog — User-Vorgabe 2026-06-14)
- **Sicherheit „unbedingt", aber später als eigener Lauf:** 2FA, Verschlüsselung (at rest / in transit), Härtung. **JETZT beim Member-Portal-Bau aber schon sauber** (security by design): Frontend-Auth korrekt, **per-User-REST-Scoping** (Mitglied sieht/ändert NUR Eigenes + Gruppen-Geteiltes), **kein wp-admin-Zugang für Mitglieder**, Nonces + Capability-Checks auf jeder Route, keine ID-enumerierbaren Leaks.
- **Schneeball/Missbrauch:** Einladungen (Plattform + Gruppe) können sich schneeballartig ausbreiten → evtl. später **Limit: Anzahl Gruppen pro User** und/oder Einladungs-Kontingente. Erst beobachten, Hook/Setting offenhalten.
