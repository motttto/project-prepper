# WordPress-Portierung — Strategie & Mapping

> Grundlage: [01-FUNKTIONSMAPPING.md](01-FUNKTIONSMAPPING.md)
> Ziel: Project Prepper als **WordPress-Produkt** anbieten/verkaufen.

---

## 1. Ehrliche Ausgangslage

Project Prepper ist eine **Next.js/React-App mit Supabase-Backend**. WordPress-Themes und -Plugins sind **PHP**. Es gibt also keinen Weg, den bestehenden Code "als WordPress-Vorlage" zu verpacken — eine WordPress-Version ist eine **Neuimplementierung der Funktionalität in PHP/JS auf WordPress-APIs**, bei der das Funktionsmapping (Dok 01) als Pflichtenheft dient.

Drei realistische Produktformen:

| Option | Was es ist | Aufwand | Markt |
|--------|-----------|---------|-------|
| **A — WordPress-Plugin (empfohlen)** | Funktionalität als Plugin neu in PHP (Custom Tables + REST + React-Admin-UI). Käufer installiert es in **sein** WordPress, Daten liegen in **seiner** DB. | Hoch (Neuentwicklung), aber modular schneidbar | Groß: CodeCanyon, eigene Site, wordpress.org (Free) + Pro-Lizenz. Zielgruppe: Veranstaltungstechnik, Vereine, Verleiher, Maker-Spaces |
| **B — SaaS + WordPress-Connector** | Die bestehende App bleibt das Produkt (Mandanten-fähig machen); ein kleines WP-Plugin bettet Buchungs-/Anfrage-Formulare und Inventar-Listen per Shortcode/Block in Kunden-Websites ein (API-Anbindung). | Niedrig–mittel (App größtenteils fertig) | "WordPress-kompatibel" statt "WordPress-Produkt"; wiederkehrende Einnahmen (Abo) |
| **C — Standalone-Template-Verkauf** | Next.js/Supabase-Codebase als Self-Hosting-Vorlage verkaufen (Gumroad, Lemon Squeezy) — kein WordPress. | Sehr niedrig (Doku + Setup-Skript) | Klein und technisch (Käufer brauchen Supabase + Vercel) |

**Empfehlung:** Option **B als schneller Markteinstieg** (geringer Aufwand, validiert Nachfrage), Option **A als eigentliches WordPress-Produkt** danach — beginnend mit einem schlanken MVP (siehe §4), nicht mit der vollen Funktionstiefe.

---

## 2. Konzept-Mapping: App-Konzept → WordPress-Äquivalent

| Project Prepper | WordPress-Äquivalent | Anmerkung |
|---|---|---|
| Supabase Auth (E-Mail+Passwort, Recovery) | `wp_users` + Core-Login/Recovery | Geschenkt — WP bringt alles mit |
| MFA/TOTP + globales MFA-Flag | Plugin-Abhängigkeit (z. B. Two-Factor) oder eigene TOTP-Implementierung | Eigenbau nur für Pro-Tier sinnvoll |
| `profiles` (Avatar, Suffix, Prefs) | User-Meta + WP-Avatare | trivial |
| Rollen (Superadmin/Admin/Manager/Member) | WP-Rollen + **Capabilities** | `manage_options` ≈ Superadmin; eigene Caps wie `pp_manage_inventory` |
| 15 feingranulare Permissions (JSONB) | Eigene Capabilities pro User (`$user->add_cap()`) | 1:1 abbildbar |
| Gruppen (Solo XOR Gruppe, Workspace-Switcher) | Eigene Tabellen `pp_groups`, `pp_group_members`; Workspace als User-Meta/Session | **Kein** Multisite nötig; XOR-Logik in PHP-Service-Layer |
| Einstimmigkeits-Voting (Einladungen, Beschlüsse) | Eigene Tabellen + Status-Maschine in PHP, Cron für Expiry | Logik 1:1 portierbar (Dok 01 §4) |
| Kern-Objekte (Projekte, Inventar, Anfragen, Verleihe) | **Custom Tables** (`dbDelta`), NICHT Custom Post Types | Relationale Struktur (60 Tabellen, FK, Constraints) sprengt CPT/Meta; Custom Tables + eigene REST-Endpoints sind der saubere Weg |
| RLS (DB-seitige Zugriffskontrolle) | **Entfällt** — Zugriffskontrolle wandert in die PHP-Schicht (Capability-Checks + WHERE-Filter in jedem Query) | Größter Architektur-Unterschied; jede Abfrage braucht explizite Owner-/Member-Filter, sonst Datenleck |
| SECURITY-DEFINER-RPCs (approve_rental_item, …) | REST-Endpoints (`register_rest_route`) mit `permission_callback` | Geschäftslogik in PHP-Services |
| DB-Trigger (Voting-Auswertung, Snapshots) | PHP-Hooks nach Schreiboperationen (`do_action`) | Kein DB-Trigger-Zwang in MySQL nötig |
| Realtime (postgres_changes) | **Polling** (Admin: 15–30 s) oder WP Heartbeat API; echtes Realtime nur via externem Dienst (Pusher/Ably) | Bewusst abspecken — Realtime ist Nice-to-have, kein Kern-Nutzen |
| Presence / "wer editiert gerade" | WP-Post-Lock-Pattern (Heartbeat) oder weglassen | MVP: weglassen |
| Auto-Save mit Field-Tracking | REST-PATCH mit Debounce im Admin-React-UI | portierbar, einfacher ohne Merge-Logik |
| Storage-Buckets + Bildkomprimierung | Media Library (`wp_handle_upload`), WP-Bildgrößen; private PDFs: geschützter Upload-Ordner + Nonce-URL | WebP-Konvertierung kann WP 6.x nativ |
| Edge Functions (E-Mail) | `wp_mail()` + SMTP-Konfiguration (oder WP Mail SMTP); Templates als eigene Settings-Seite | deutlich einfacher als Supabase-Variante |
| E-Mail-Templates mit `{{vars}}` | Eigene Options/Tabelle + `str_replace`-Renderer | 1:1 |
| Telegram-Bot | REST-Webhook-Endpoint + `wp_remote_post` an Telegram-API | 1:1 portierbar |
| iCal-Feed (Token) | Eigener Rewrite-Endpoint `/pp-calendar/{token}.ics` | einfach |
| CalDAV-Zweiwege-Sync (Cloudflare Worker) | **Nicht portieren.** iCal-Export (read-only) reicht; CalDAV-Server in WP ist unverhältnismäßig | bewusster Funktionsverzicht |
| Excel-Import/Export | PhpSpreadsheet (Composer) oder SheetJS im Admin-UI | gut machbar |
| DSGVO Export/Löschung | **WP-Core-Hooks!** `wp_privacy_personal_data_exporters` / `_erasers` | WP ist hier sogar besser vorbereitet |
| Kooperationsvereinbarung + Gewinnformel | Eigene Tabellen + PHP-Berechnungsservice (Formel aus Dok 01 §7.3) | reine Rechenlogik, gut portierbar |
| Activity-Log | Eigene Tabelle + Hook in alle Services | einfach |
| Health-Endpoint | WP Site Health + eigener REST-Ping | trivial |
| Frontend (React, Tailwind, Dark Mode) | Admin: React via `@wordpress/scripts`/`@wordpress/components`; Frontend-Ausgabe: Gutenberg-Blöcke + Shortcodes | UI-Konzepte übernehmbar, Code nicht |

---

## 3. Komplexität pro Modul

| Modul (Dok 01) | Komplexität | Portierungs-Risiko / Hinweis |
|---|---|---|
| §2 Auth & Profil | **S** | WP-Core erledigt 80 % |
| §3 Workspace Solo/Gruppe | **M** | Service-Layer-Disziplin nötig (jeder Query gefiltert) |
| §4 Gruppen + Voting | **M–L** | Status-Maschine + Mails; gut spezifiziert |
| §5 Team/Permissions/Activity | **M** | Capabilities + eigene Tabelle |
| §6 Projekte (12 Tabs) | **XL** | Größtes Modul; in Teil-Releases schneiden (Übersicht+Kosten+Checklisten zuerst; Tasks/Files danach) |
| §7 Vereinbarung + Gewinn | **L** | Rechenlogik einfach, UI (Wizard, Signaturen) aufwendig |
| §8 Inventar | **L** | Kern-Verkaufsargument; Nummernkreise + Excel sauber bauen |
| §9 Verleih | **L** | Verfügbarkeits-Query (Overlap) + Approval-Trigger-Logik in PHP |
| §10 Sharing & Erträge | **L–XL** | Erst nach Gruppen-Modul sinnvoll |
| §11 Anfragen | **M** | Pipeline + Konvertierung gut machbar |
| §12 Telegram | **M** | optionales Pro-Add-on |
| §13 Kalender | **M** (ohne CalDAV) | iCal-Export ja, CalDAV nein |
| §14 Umfragen | **M** | in sich geschlossen |
| §15 Dashboard/Kosten | **S–M** | Aggregationen |
| §16 E-Mail | **S** | wp_mail + Templates |
| §17 Admin/Feedback/DSGVO | **S–M** | DSGVO via Core-Hooks |
| §18 Realtime/Presence | **—** | im MVP ersatzlos streichen (Polling) |

---

## 4. MVP-Schnitt (Plugin v1.0)

**These:** Verkauft wird zuerst das, was es als WP-Plugin so nicht gut gibt: **Equipment-Inventar + Verleih für Teams** (Vereine, Technik-Crews, Verleiher, Werkstätten).

**v1.0 (verkaufsfähig):**
1. Inventar: Artikel, Kategorien mit Prefix, Auto-Inventarnummern, Fotos, PDF-Dokumente, Suche/Filter, Einzelstücke
2. Excel-Import/-Export
3. Verleih: Leiher, Zeitraum, Verfügbarkeitsprüfung, Status-Flow, Kaution/Gebühr, USt-Ausweis
4. Rollen/Capabilities + einfache Team-Verwaltung
5. E-Mail-Benachrichtigungen (wp_mail), iCal-Export der Verleihe
6. DSGVO-Hooks, Activity-Log light

**v1.x (Pro-Features):** Projekte (schrittweise Tabs), Anfragen-Pipeline, Umfragen, Telegram, Gruppen-Workspaces
**v2.x:** Voting/Beschlüsse, Kooperationsvereinbarung + Gewinnverteilung, Sharing/Erträge (das "Kollektiv-Paket" — Alleinstellungsmerkmal, aber erklärungsbedürftig)

---

## 5. Technisches Grundgerüst (Option A)

```
project-prepper/                  ← Plugin-Slug
├── project-prepper.php           ← Header, Aktivierung (dbDelta), Deaktivierung
├── includes/
│   ├── Schema.php                ← Custom Tables (aus Dok 01 §19.1 abgeleitet)
│   ├── Capabilities.php          ← Rollen + 15 Caps
│   ├── Services/                 ← Inventory, Rental, Availability, Numbering, …
│   ├── Rest/                     ← register_rest_route-Controller (ersetzt RPCs)
│   └── Email/                    ← Templates + Renderer + wp_mail
├── admin/                        ← React-Admin-UI (@wordpress/scripts)
├── blocks/                       ← Gutenberg-Blöcke (Inventarliste, Anfrage-Formular)
├── templates/                    ← Frontend-Templates (überschreibbar im Theme)
└── languages/                    ← i18n (de_DE zuerst, en_US für den Verkauf)
```

Leitplanken:
- **Tabellen-Präfix `{$wpdb->prefix}pp_`**, Schema-Versionierung über Option + Migrations-Runner (Pendant zu den 105 Supabase-Migrationen).
- **Jede REST-Route mit `permission_callback`** — das ist der RLS-Ersatz; zusätzlich zentrale Query-Builder, die Owner-Filter erzwingen.
- **Uploads:** Fotos in Media Library; private PDFs in `wp-content/uploads/pp-protected/` mit .htaccess-Schutz + Stream-Endpoint.
- **Cron:** `wp_schedule_event` für Einladungs-Expiry, Reminder, Status-Checks.

---

## 6. Verkauf & Lizenzierung

- **Freemium:** Basis (Inventar + einfacher Verleih) kostenlos auf wordpress.org → Reichweite/SEO; **Pro** (Excel, Projekte, Anfragen, Gruppen, Telegram) als kostenpflichtige Lizenz.
- **Lizenz-/Update-Infrastruktur:** Freemius (am wenigsten Eigenaufwand, übernimmt Checkout/Steuern) oder Easy Digital Downloads + Software Licensing auf eigener Site.
- **Marktplatz-Alternative:** CodeCanyon (Envato) — Reichweite gegen ~30–50 % Provision und Exklusivitätsregeln.
- **Preisanker vergleichbarer Plugins:** Verleih-/Booking-Plugins liegen bei 49–199 €/Jahr pro Site.
- **Aufwandstreiber für den Verkauf:** englische Übersetzung, Doku/Onboarding, Support-Kanal, Demo-Site, Datenschutz-Doku (das Produkt speichert personenbezogene Daten der Leiher).

---

## 7. Nächste Schritte

1. **Entscheidung Produktform** (A, B oder C — oder B→A gestaffelt). ← steht aus
2. Bei A: Schema-Entwurf `pp_*`-Tabellen aus Dok 01 §19.1 ableiten (MVP-Subset).
3. Plugin-Grundgerüst auf Branch `wordpress-edition` anlegen (`wordpress-edition/plugin/`).
4. Vertical Slice: Inventar-Artikel anlegen → Liste → Verleih mit Verfügbarkeitsprüfung (beweist Architektur).
5. Demo-Site + Landingpage, dann Freemium-Submission.
