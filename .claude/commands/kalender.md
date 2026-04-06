# Kalender & CalDAV bearbeiten

Änderungen am Kalender-System (Darstellung, Gruppen, Events, CalDAV, iCal-Feed, Cloudflare Worker).

## Anweisung

Aufgabe: $ARGUMENTS

### Kontext
- **Tabellen:** `calendar_groups` (Kalendergruppen) + `calendar_events` (Termine) + `calendar_feed_tokens` (Auth-Tokens)
- **Event-Felder:** summary, description, location, all_day, start_at, end_at, group_id, org_id, etag, caldav_uid
- **Group-Felder:** name, color, sort_order, org_id, ctag
- **Token-Felder:** token, org_id, profile_id, read_write, last_used_at

---

## Architektur-Überblick

```
Apple Calendar ←→ Cloudflare Worker (standalone CalDAV-Server)
                       ↕ Supabase REST API (Service Role)
Prepper Web    ←→ Supabase (Realtime + RLS)
```

**Wichtig:** Der Cloudflare Worker ist ein **eigenständiger CalDAV-Server**, KEIN Proxy!
Er redet direkt mit Supabase und hat KEINE Abhängigkeit zu Vercel/Next.js für CalDAV.

---

## Cloudflare Worker (`cloudflare-caldav-proxy/worker.js`)

### Was er tut
- Eigenständiger CalDAV-Server (~650 Zeilen Vanilla JS)
- Beantwortet PROPFIND, REPORT, GET, PUT, DELETE, PROPPATCH, OPTIONS nativ
- Queries Supabase REST API direkt mit Service Role Key
- Enthält eigene Kopien von: XML-Builder, iCal-Parser/Generator, Auth, Routing

### Warum eigenständig (nicht Proxy)
Vercel blockt PROPFIND/REPORT auf CDN-Level. Ein Proxy-Ansatz funktioniert nicht zuverlässig weil:
1. Apple Calendar cached Server-URLs und umgeht den Proxy
2. Vercel 308-Redirects bei Trailing Slashes verlieren den PROPFIND-Body
3. Response-Header leaken die Vercel-Origin an den Client

### Deployment
```bash
cd cloudflare-caldav-proxy
npx wrangler deploy
```

### Secrets (einmalig setzen)
```bash
echo "https://wiywvuurxzkctvpwkncj.supabase.co" | npx wrangler secret put SUPABASE_URL
echo "SERVICE_ROLE_KEY_HERE" | npx wrangler secret put SUPABASE_SERVICE_ROLE_KEY
```

### Worker-URL
`caldav-proxy.post-cd8.workers.dev`

### Debugging
```bash
npx wrangler tail --format pretty   # Live Request-Logs
```
**Achtung:** `wrangler tail` muss aus dem `cloudflare-caldav-proxy/` Verzeichnis laufen!

---

## CalDAV-Protokoll — Bekannte Fallstricke

### Apple Calendar Discovery-Flow
```
1. PROPFIND /.well-known/caldav         → 401 (keine Auth)
2. PROPFIND /.well-known/caldav + Auth  → 301 → /api/caldav/{token}/
3. PROPFIND /api/caldav/{token}/ Depth:0 → Principal (current-user-principal, calendar-home-set)
4. OPTIONS
5. PROPFIND /api/caldav/{token}/calendars/ Depth:1 → Kalender-Liste mit CTags
6. PROPPATCH × N (Kalender-Farben setzen)
7. PROPFIND calendar Depth:0 (CTag-Check)
8. PROPFIND calendar Depth:1 (Event-Listing mit ETags)
9. REPORT calendar-multiget (Events abrufen)
```

### Kritische Gotchas (diese Session!)

1. **Apple Calendar Namespace-Prefix:** Verwendet `<A:href xmlns:A="DAV:">` statt `<d:href>`.
   HREF-Parser muss `[^>]*` nach dem Tag-Namen erlauben: `/<(?:\w+:)?href[^>]*>([^<]+)/gi`

2. **URL-Encoding:** Apple Calendar encoded `@` als `%40` in UIDs.
   ALLE Event-Handler brauchen `decodeURIComponent()` auf dem UID.

3. **PROPPATCH:** Apple Calendar sendet PROPPATCH für Kalender-Farben.
   Muss mit 207 OK beantwortet werden (auch wenn nichts gespeichert wird), sonst Loop.

4. **`.well-known/caldav`:** Muss 301 Redirect zurückgeben, NICHT den Principal inline.
   Inline-Response verursacht Discovery-Loop weil `<d:href>` nicht zur Request-URL passt.

5. **`principal-URL` Property:** Apple Calendar erwartet `<d:principal-URL>` im Principal-Response.

6. **`current-user-principal` auf Calendar-Home:** Muss auch auf der Calendar-Home Collection gesetzt sein.

7. **sync-collection REPORT:** Apple Calendar versucht das als erstes. Muss mit DAV:error
   `<d:valid-sync-token>` abgelehnt werden (403), damit Fallback auf CTag-Sync.

8. **`supported-report-set`:** Muss calendar-multiget und calendar-query listen, aber NICHT sync-collection.

9. **`current-user-privilege-set`:** Muss read + write + write-content + bind + unbind enthalten.

10. **CTag-Bump:** Passiert automatisch via DB-Trigger (`bump_calendar_group_ctag`).
    Der Worker muss das NICHT manuell machen.

11. **ETag-Regeneration:** Passiert automatisch via DB-Trigger bei jedem UPDATE.

---

## Sync-Verhalten

### Apple Calendar → Prepper
- Events kommen sofort in Supabase an (PUT/DELETE via Worker)
- CTag-Trigger bumpt `calendar_groups.ctag`
- Prepper-Web aktualisiert automatisch via Realtime-Subscription auf `calendar_groups`

### Prepper → Apple Calendar
- Events werden in Supabase gespeichert, CTag bumpt automatisch
- Apple Calendar pollt alle ~15 Minuten
- **Cmd+Shift+R** in Apple Calendar erzwingt sofortigen Sync
- Kein Push-Mechanismus möglich (CalDAV ist Polling-basiert)

### CTag-basierter Sync
```
Apple Calendar cached CTag pro Kalender.
Bei Sync: PROPFIND Depth:0 → CTag vergleichen
→ Wenn gleich: keine Änderungen, fertig
→ Wenn anders: PROPFIND Depth:1 (ETags) → REPORT multiget (geänderte Events)
```

---

## Apple Calendar Account-Setup (Erweitert-Modus)

| Feld | Wert |
|------|------|
| Accounttyp | CalDAV → **Erweitert** (nicht Manuell!) |
| Benutzername | `caldav` |
| Passwort | Token aus `calendar_feed_tokens` (read_write=true) |
| Serveradresse | `caldav-proxy.post-cd8.workers.dev` |
| Serverpfad | `/api/caldav/{TOKEN}/` |
| Port | `443` |
| SSL | ✅ |

**Wichtig:** "Manuell" macht eigene Discovery die nicht zuverlässig funktioniert. Immer **Erweitert** verwenden!

---

## Kalender-Darstellung

- **Monatsansicht:** Grid mit Tagen, Events als farbige Chips
- **Wochenansicht:** Zeitraster (Apple Calendar Style), HOUR_HEIGHT = 60px
- **Toolbar:** Navigations-Buttons (‹ › Heute), Monat/Jahr-Anzeige, Ansicht-Toggle
- **Gruppen-Filter:** Farbige Badges zum Ein-/Ausblenden
- **Event-Überlappung:** layoutEvents() Algorithmus für Spalten-Aufteilung
- **Modals:** EventModal (erstellen/bearbeiten), GroupManager, SubscribeInfoModal

---

## iCal-Feed (Nur-Lesen)

- **Route:** `src/app/api/calendar/feed/route.ts`
- Token-basierte Auth via Query-Parameter
- Optional: Filterung nach group_id
- Für Google Calendar, Outlook Web etc. (die kein CalDAV können)

---

## Relevante Dateien

| Datei | Zweck |
|-------|-------|
| `cloudflare-caldav-proxy/worker.js` | **Standalone CalDAV-Server** (alle Methoden) |
| `cloudflare-caldav-proxy/wrangler.toml` | Worker-Config |
| `src/app/(dashboard)/calendar/page.tsx` | Kalender-UI (1800 Zeilen) |
| `src/app/api/caldav/[token]/[[...path]]/route.ts` | Next.js CalDAV Route (legacy, für lokales Dev) |
| `src/lib/caldav/handlers.ts` | CalDAV Handler (Next.js-Version) |
| `src/lib/caldav/auth.ts` | Token-Validierung |
| `src/lib/caldav/ical.ts` | iCal Parse/Generate (shared) |
| `src/lib/caldav/xml.ts` | XML Builder + Parser |
| `src/lib/supabase-service.ts` | Service-Role Client |
| `src/app/api/calendar/feed/route.ts` | iCal-Feed (read-only) |

### Migrationen
- `051_calendar.sql` — calendar_groups + calendar_events + RLS + Realtime
- `052_calendar_feed_tokens.sql` — Token-Tabelle
- `053_caldav_support.sql` — etag, caldav_uid, ctag, read_write + Trigger
- `054_calendar_delete_policy.sql` — Alle Org-Mitglieder dürfen Events löschen
- `055_ctag_security_definer.sql` — CTag-Trigger als SECURITY DEFINER (RLS-Fix)

### Middleware
- CalDAV- und Feed-Routen sind von der Auth-Middleware ausgenommen (`src/middleware.ts`)

---

## Code-Duplikation Beachten!

Der Worker (`worker.js`) enthält **eigene Kopien** von:
- XML-Builder (identisch zu `xml.ts`)
- iCal-Parser/Generator (identisch zu `ical.ts`)
- Auth-Logik (identisch zu `auth.ts`)
- Supabase-Client (REST statt SDK)

**Bei Änderungen an der Parse/Generate-Logik müssen BEIDE Stellen aktualisiert werden!**

---

## Häufige Aufgaben

- **Darstellung anpassen** → `calendar/page.tsx` (MonthView / WeekView)
- **Neues Event-Feld** → Migration + `ical.ts` + `worker.js` + `handlers.ts` + UI
- **CalDAV-Debugging** → `npx wrangler tail --format pretty` (aus cloudflare-caldav-proxy/)
- **Worker deployen** → `cd cloudflare-caldav-proxy && npx wrangler deploy`
- **Sync-Probleme** → ETags/CTags prüfen, PROPFIND/REPORT Responses mit curl testen
- **Apple Calendar Loop** → PROPPATCH-Response prüfen, `.well-known` muss 301 sein
- **Events nicht sichtbar** → HREF-Parser prüfen (Namespace-Prefix), URL-Decoding prüfen
- **CTag manuell bumpen** → `UPDATE calendar_groups SET ctag = ctag + 1 WHERE org_id = '...'`

### curl-Tests
```bash
TOKEN="..."
BASE="https://caldav-proxy.post-cd8.workers.dev/api/caldav/$TOKEN"

# PROPFIND Principal
curl -s -X PROPFIND "$BASE/" -H "Depth: 0" -H "Content-Type: application/xml" \
  -d '<d:propfind xmlns:d="DAV:"><d:allprop/></d:propfind>'

# PROPFIND Calendar Home
curl -s -X PROPFIND "$BASE/calendars/" -H "Depth: 1" -H "Content-Type: application/xml" \
  -d '<d:propfind xmlns:d="DAV:"><d:allprop/></d:propfind>'

# PROPFIND Calendar Events
curl -s -X PROPFIND "$BASE/calendars/{GROUP_ID}/" -H "Depth: 1" \
  -H "Content-Type: application/xml" \
  -d '<d:propfind xmlns:d="DAV:"><d:prop><d:getetag/></d:prop></d:propfind>'

# REPORT calendar-multiget
curl -s -X REPORT "$BASE/calendars/{GROUP_ID}/" -H "Content-Type: application/xml" \
  -d '<cal:calendar-multiget xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
  <d:prop><d:getetag/><cal:calendar-data/></d:prop>
  <d:href>/api/caldav/{TOKEN}/calendars/{GROUP_ID}/{UID}.ics</d:href>
</cal:calendar-multiget>'
```
