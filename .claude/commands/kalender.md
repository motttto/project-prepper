# Kalender-Feature bearbeiten

Änderungen am Kalender-System (Darstellung, Gruppen, Events, CalDAV, iCal-Feed).

## Anweisung

Aufgabe: $ARGUMENTS

### Kontext
- **Tabellen:** `calendar_groups` (Kalendergruppen) + `calendar_events` (Termine) + `calendar_feed_tokens` (Auth-Tokens)
- **Event-Felder:** summary, description, location, all_day, start_at, end_at, group_id, org_id, etag, caldav_uid
- **Group-Felder:** name, color, sort_order, org_id, ctag
- **Token-Felder:** token, org_id, profile_id, read_write, last_used_at

### Kalender-Darstellung
- **Monatsansicht:** Grid mit Tagen, Events als farbige Chips
- **Wochenansicht:** Zeitraster (Apple Calendar Style), HOUR_HEIGHT = 60px
- **Toolbar:** Navigations-Buttons (‹ › Heute), Monat/Jahr-Anzeige, Ansicht-Toggle (Monat/Woche)
- **Gruppen-Filter:** Farbige Badges zum Ein-/Ausblenden von Gruppen
- **Event-Überlappung:** layoutEvents() Algorithmus für Spalten-Aufteilung
- **Modals:** EventModal (erstellen/bearbeiten), GroupManager, SubscribeInfoModal

### CalDAV-Server (Zwei-Wege-Sync)
- **Route:** `src/app/api/caldav/[token]/[[...path]]/route.ts` — Catch-All Handler
- **Handlers:** `src/lib/caldav/handlers.ts` — PROPFIND, REPORT, GET, PUT, DELETE
- **Auth:** `src/lib/caldav/auth.ts` — Token-Validierung (URL + Basic Auth)
- **iCal:** `src/lib/caldav/ical.ts` — parseVEvent(), generateVCalendar()
- **XML:** `src/lib/caldav/xml.ts` — XML Response Builder + Request Parser
- **Proxy:** `cloudflare-caldav-proxy/worker.js` — Cloudflare Worker (PROPFIND/REPORT → POST)
- **Service Client:** `src/lib/supabase-service.ts` — RLS-Bypass für CalDAV

### CalDAV-Protokoll
- Vercel blockt non-standard HTTP-Methoden (PROPFIND, REPORT)
- Cloudflare Worker empfängt echte CalDAV-Methoden, leitet als POST + X-HTTP-Method Header weiter
- Worker URL: `caldav-proxy.post-cd8.workers.dev`
- Apple Calendar Discovery: Worker beantwortet `.well-known/caldav` direkt
- ETag: Auto-Update bei jedem Event-UPDATE (Trigger)
- CTag: Inkrementiert auf calendar_groups bei jeder Event-Änderung (Trigger)

### iCal-Feed (Nur-Lesen)
- **Route:** `src/app/api/calendar/feed/route.ts`
- Token-basierte Auth via Query-Parameter
- Optional: Filterung nach group_id

### Einbinden-Modal (SubscribeInfoModal)
- **Tab 1 — CalDAV:** Kompatibilitäts-Grid, Zugangsdaten (Server/Benutzer/Passwort), Anleitungen (Apple, Android DAVx5, Thunderbird, Outlook Plugin)
- **Tab 2 — iCal:** Kompatibilitäts-Grid, Direkt-Abo Button (webcal:), URL, Gruppen-URLs, Anleitungen (Apple, Google, Outlook, Android, Thunderbird)

### Relevante Dateien
- `src/app/(dashboard)/calendar/page.tsx` — Kalender-Seite (Monats-/Wochenansicht + Modals)
- `src/app/api/caldav/[token]/[[...path]]/route.ts` — CalDAV Route Handler
- `src/lib/caldav/` — CalDAV-Server (auth, handlers, ical, xml)
- `src/app/api/calendar/feed/route.ts` — iCal-Feed
- `cloudflare-caldav-proxy/worker.js` — Cloudflare Worker Proxy
- `src/lib/supabase-service.ts` — Service-Role Client

### Migrationen
- `051_calendar.sql` — calendar_groups + calendar_events Tabellen
- `052_calendar_feed_tokens.sql` — Token-Tabelle für Feed-Auth
- `053_caldav_support.sql` — etag, caldav_uid, ctag, read_write + Trigger

### Middleware
- CalDAV- und Feed-Routen sind von der Auth-Middleware ausgenommen (`src/middleware.ts`)
- Eigene Token-basierte Auth statt Session-Cookies

### Monetarisierung (geplant)
- Ein Kalender pro Org als Basis
- Mehr Kalender gegen Bezahlung

### Häufige Aufgaben
- Darstellung anpassen → calendar/page.tsx (MonthView / WeekView Komponenten)
- Neues Event-Feld → Migration + ical.ts (parse/generate) + handlers.ts + UI
- CalDAV-Debugging → Cloudflare Worker Logs + Vercel Function Logs
- Worker deployen → `cd cloudflare-caldav-proxy && npx wrangler deploy`
- Sync-Probleme → ETags/CTags prüfen, PROPFIND/REPORT Responses checken
