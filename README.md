# Project Prepper

Projektmanagement-App für Veranstaltungstechnik — von der Anfrage bis zur Abrechnung.

**Live:** [project-prepper.dunkelstrom.net](https://project-prepper.dunkelstrom.net)

---

## Features

### Projekte
- Projektverwaltung mit Status-Workflow (Entwurf → Planung → Aktiv → Abgeschlossen)
- 8 Tabs: Übersicht, Zeitplan, Kosten, Equipment, Team, Material, Checklisten, Aufgaben
- Datei-Upload (Grundrisse, PDFs)
- Budget-Planung (Honorar, Technik, Transport) mit Netto/Brutto-Ansicht
- Echtzeit-Zusammenarbeit mit Presence-Anzeige

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
- Rollen-System (Admin / Manager / Mitglied)
- Feingranulare Permissions (13 Checkboxen pro User)
- Team-Freigabe per Abstimmung (Unanimous Vote)
- Org-Einladungen per Email
- Testuser-Erstellung für UI-Tests
- Admin-Impersonation

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
| **Supabase** | Auth, PostgreSQL, Realtime, Storage, Edge Functions |
| **Tailwind CSS 4** | Styling |
| **Vercel** | Hosting & Deployment |

---

## Architektur

```
src/
├── app/              # Next.js App Router (Pages & Layouts)
├── components/       # React Components (UI, Layout, Features)
├── contexts/         # React Contexts (Org, Impersonation)
├── hooks/            # Custom Hooks (Auth, Realtime, Presence)
├── lib/              # Supabase Client (Browser & Server)
├── types/            # TypeScript Definitionen
└── middleware.ts     # Auth Guard & Session Refresh
```

### Datenbank
- **34 Migrationen** — von Schema-Setup bis Feature-Erweiterungen
- **Row Level Security** auf allen Tabellen
- **Realtime** für Live-Updates (Projekte, Inventar, Aufgaben, etc.)
- **Edge Functions** für Email-Versand und Telegram-Bot

---

## Entwicklung

```bash
# Dependencies installieren
npm install

# Dev-Server starten
npm run dev

# Production Build
npm run build
```

### Umgebungsvariablen

```env
NEXT_PUBLIC_SUPABASE_URL=https://xxx.supabase.co
NEXT_PUBLIC_SUPABASE_ANON_KEY=eyJ...
```

---

## Deployment

Push auf `main` → Vercel deployed automatisch.

```bash
git add .
git commit -m "Beschreibung"
git push origin main
```

Erreichbar unter:
- https://project-prepper.dunkelstrom.net (Custom Domain)
- https://project-prepper.vercel.app (Vercel)

---

## Lizenz

Privates Projekt.
