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
- **Monatskalender: mehrtägige Einträge als durchgehender Balken (2026-08-27, Schema unverändert 0.41.0):**
  - Vorher lag derselbe Eintrag als eigener Chip in JEDER Tageszelle — ein Projekt vom 10.–14. sah aus wie fünf Vorgänge. Jetzt rendert `render_month_weeks()` je Woche EIN Raster; ein Eintrag wird zu einem Segment über `grid-column: start / span n`. An Wochengrenzen zerfällt er in mehrere Segmente, deren Kanten flach bleiben (`--cont-left/right`), damit die Fortsetzung erkennbar ist.
  - **Aufbau je Woche:** Tageszellen liegen als Hintergrund über alle Zeilen (`grid-row: 1 / -1`), Zeile 1 trägt die Tageszahlen, ab Zeile 2 folgen die Balken-Spuren. Überlappende Einträge bekommen je eine eigene Spur (Sortierung: lange Balken zuerst, dann von links nach rechts) und bleiben dadurch über die ganze Woche auf derselben Höhe.
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
