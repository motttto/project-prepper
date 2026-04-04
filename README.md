# Project Prepper

Projektmanagement-App für Veranstaltungstechnik — von der Anfrage bis zur Abrechnung.

**Live:** [project-prepper.vercel.app](https://project-prepper.vercel.app)

---

## Features

### Projekte
- Projektverwaltung mit Status-Workflow (Entwurf → Planung → Aktiv → Abgeschlossen)
- 8 Tabs: Übersicht, Zeitplan, Kosten, Equipment, Team, Material, Checklisten, Aufgaben
- Datei-Upload (Grundrisse, PDFs)
- Budget-Planung (Honorar, Technik, Transport) mit Netto/Brutto-Ansicht
- Echtzeit-Zusammenarbeit mit Presence-Anzeige

### Kalender
- Eigener Kalender mit Gruppen und Farbcodes
- Monats- und Wochenansicht
- **CalDAV-Server** — Zwei-Wege-Sync mit Apple Calendar, Thunderbird, Android (DAVx5), Outlook (Plugin)
- **iCal-Feed** — Nur-Lesen-Abo für Google Calendar, Outlook Web, etc.
- ETag/CTag-basierte Synchronisation

### Inventar
- Equipment-Verwaltung mit dynamischen Kategorien
- Einzelstück-Tracking (pro Gerät: Zustand, Notizen)
- Foto-Upload mit automatischer Komprimierung
- Excel-Import / Export
- Buchungssystem mit Verfügbarkeitsprüfung

### Anfragen-Pipeline
- Projektanfragen von der Erstanfrage bis zum Angebot
- Team-Verfügbarkeit abfragen (Zusage/Absage)
- Wahrscheinlichkeits-Tracking
- Telegram-Bot-Integration für Benachrichtigungen

### Team
- Multi-Tenant mit Organisationen
- Rollen-System (Admin / Manager / Mitglied)
- Feingranulare Permissions (13 Checkboxen pro User)
- Team-Freigabe per Abstimmung (Unanimous Vote)
- Org-Einladungen per Email
- Testuser-Erstellung für UI-Tests
- Admin-Impersonation
- MFA (TOTP) für alle User

### Kosten
- Kostenposten pro Projekt (Personal, Material, Inventar, Extern)
- USt-Sätze inline editierbar (0%, 7%, 19%)
- Globale Kostenübersicht über alle Projekte
- Budget vs. Ist-Vergleich

---

## Tech Stack

| Technologie | Einsatz |
|-------------|---------|
| **Next.js 16** | App Router, Turbopack |
| **React 19** | UI Components |
| **TypeScript 5.9** | Type Safety |
| **Supabase** | Auth, PostgreSQL, Realtime, Storage |
| **Tailwind CSS 4** | Styling |
| **Vercel** | Hosting & Deployment |
| **Cloudflare Worker** | CalDAV-Proxy (PROPFIND/REPORT → POST) |

---

## Architektur

```
src/
├── app/              # Next.js App Router (Pages & API Routes)
├── components/       # React Components (UI, Layout, Features)
├── contexts/         # React Contexts (Org, Impersonation)
├── hooks/            # Custom Hooks (Auth, Realtime, Presence)
├── lib/              # Supabase Client, CalDAV-Server
│   └── caldav/       # CalDAV Protocol (Auth, Handlers, iCal, XML)
├── types/            # TypeScript Definitionen
└── middleware.ts     # Auth Guard & Session Refresh

supabase/
└── migrations/       # 53 SQL-Migrationen

cloudflare-caldav-proxy/
└── worker.js         # Proxy: PROPFIND/REPORT → POST (Vercel-Kompatibilität)
```

### Datenbank
- **53 Migrationen** — von Schema-Setup bis CalDAV-Support
- **Row Level Security** auf allen Tabellen
- **Realtime** für Live-Updates (Projekte, Inventar, Kalender, Aufgaben, etc.)
- **Service-Role Client** für CalDAV (RLS-Bypass mit Token-Auth)

### CalDAV-Server
- Vollständiges CalDAV-Protokoll (PROPFIND, REPORT, GET, PUT, DELETE)
- Token-basierte Auth (im URL + Basic Auth)
- ETag für optimistic concurrency, CTag für Collection-Sync
- Cloudflare Worker als Proxy, da Vercel non-standard HTTP-Methoden blockt

---

## Entwicklung

```bash
npm install
npm run dev
```

### Umgebungsvariablen

```env
NEXT_PUBLIC_SUPABASE_URL=https://xxx.supabase.co
NEXT_PUBLIC_SUPABASE_ANON_KEY=eyJ...
SUPABASE_SERVICE_ROLE_KEY=eyJ...
```

---

## Deployment

Push auf `main` → Vercel deployed automatisch.

```bash
git push origin main
```

CalDAV-Proxy (Cloudflare Worker):
```bash
cd cloudflare-caldav-proxy
npx wrangler deploy
```

---

## Lizenz

Privates Projekt — alle Rechte vorbehalten.
