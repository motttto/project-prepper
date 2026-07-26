# App-Design — CSS-Design-System der alten Supabase-App

Referenz für das **Aussehen** der ursprünglichen Next.js/Supabase-App (Project Prepper).
Nutze diesen Skill, wenn eine neue Seite/Komponente **im gleichen Look** wie die alte App
gebaut werden soll — egal ob in der App selbst oder als Nachbau in der WordPress-Edition.

**Quelle:** `src/app/globals.css` (branch `main` / working tree). Alle Werte unten sind 1:1 daraus.

---

## Grundprinzipien

1. **Immer CSS-Variablen für Farben** — nie Hex direkt in Komponenten. `var(--color-primary)` etc.
2. **Light + Dark** über `prefers-color-scheme: dark`. Jede Farbe hat ein Dark-Pendant.
3. **Indigo** ist die Markenfarbe (`#6366f1`), die Sidebar ist tief-indigo-dunkel (`#1e1b4b`).
4. **Font:** `Inter`, dann System-Fallback. Antialiasing an.
5. **Subtiler Kanten-Glow** auf allen interaktiven Elementen beim Hover (Indigo-Schimmer, kein harter Rahmen).
6. **Abgerundete Ecken** durchgängig über `--radius-*`, weiche mehrstufige Schatten über `--shadow-*`.
7. **Barrierefrei:** sichtbarer Focus-Ring, `prefers-reduced-motion` respektiert.

---

## Design-Tokens (kopierbar)

```css
:root {
  /* Brand */
  --color-background: #fafafa;
  --color-foreground: #0f172a;
  --color-primary: #6366f1;
  --color-primary-hover: #4f46e5;
  --color-primary-light: #eef2ff;
  --color-primary-muted: #c7d2fe;

  /* Surfaces */
  --color-surface: #ffffff;
  --color-surface-hover: #f8fafc;
  --color-muted: #f1f5f9;
  --color-muted-foreground: #64748b;
  --color-border: #e2e8f0;
  --color-border-light: #f1f5f9;

  /* Semantic */
  --color-destructive: #ef4444;   --color-destructive-light: #fef2f2;
  --color-success: #10b981;       --color-success-light: #ecfdf5;
  --color-warning: #f59e0b;       --color-warning-light: #fffbeb;
  --color-info: #3b82f6;          --color-info-light: #eff6ff;

  /* Sidebar (dunkel-indigo) */
  --color-sidebar: #1e1b4b;
  --color-sidebar-hover: #312e81;
  --color-sidebar-text: #e0e7ff;
  --color-sidebar-text-muted: #a5b4fc;
  --color-sidebar-active: #4f46e5;

  /* Radien */
  --radius-sm: 6px;  --radius-md: 8px;  --radius-lg: 12px;  --radius-xl: 16px;

  /* Schatten */
  --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
  --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.07), 0 2px 4px -2px rgb(0 0 0 / 0.05);
  --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.08), 0 4px 6px -4px rgb(0 0 0 / 0.04);
}

@media (prefers-color-scheme: dark) {
  :root {
    --color-background: #0c0a1d;
    --color-foreground: #e2e8f0;
    --color-primary: #818cf8;
    --color-primary-hover: #a5b4fc;
    --color-primary-light: #1e1b4b;
    --color-primary-muted: #3730a3;

    --color-surface: #1a1833;
    --color-surface-hover: #221f3d;
    --color-muted: #1e1b4b;
    --color-muted-foreground: #b0bec9;
    --color-border: #312e81;
    --color-border-light: #1e1b4b;

    --color-destructive: #f87171;   --color-destructive-light: #450a0a;
    --color-success: #34d399;       --color-success-light: #022c22;
    --color-warning: #fbbf24;       --color-warning-light: #451a03;
    --color-info: #60a5fa;          --color-info-light: #172554;

    --color-sidebar: #0f0d24;
    --color-sidebar-hover: #1e1b4b;
    --color-sidebar-text: #c7d2fe;
    --color-sidebar-text-muted: #818cf8;
    --color-sidebar-active: #4f46e5;

    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.3);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.3), 0 2px 4px -2px rgb(0 0 0 / 0.2);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.3), 0 4px 6px -4px rgb(0 0 0 / 0.2);
  }
}
```

---

## Basis-Layer

```css
* { box-sizing: border-box; }

body {
  background: var(--color-background);
  color: var(--color-foreground);
  font-family: 'Inter', system-ui, -apple-system, sans-serif;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

/* Dünne Scrollbars (6px, Border-Farbe, wird bei Hover dunkler) */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--color-border); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--color-muted-foreground); }

/* Focus-Ring — 2px Primary, 2px Offset */
*:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: 2px;
  border-radius: var(--radius-sm);
}

/* Input-Fokus: nur Border wird primary, kein Outline */
input:focus, textarea:focus, select:focus {
  border-color: var(--color-primary) !important;
  outline: none;
}
```

---

## Signature-Effekt: Kanten-Glow auf interaktiven Elementen

Das ist der wiedererkennbare Look — beim Hover ein weicher Indigo-Schimmer als `inset`-Ring + Halo,
beim Klick leichtes Einsinken (`scale(0.98)`). **Immer mitnehmen, wenn der App-Look gefragt ist.**

```css
button, a, select, option,
input[type="checkbox"], input[type="radio"],
[role="button"], [role="option"], [role="menuitem"], [role="listbox"] {
  transition: box-shadow 0.2s ease, transform 0.15s ease;
}

button:hover:not(:disabled), a:hover, select:hover, option:hover,
[role="button"]:hover, [role="option"]:hover, [role="menuitem"]:hover,
li:hover, tr:hover {
  box-shadow: inset 0 0 0 1px rgba(129,140,248,0.3), 0 0 6px 0px rgba(129,140,248,0.15);
}

button:active:not(:disabled), a:active, [role="button"]:active {
  transform: scale(0.98);
  box-shadow: inset 0 0 0 1px rgba(129,140,248,0.5), 0 0 4px 0px rgba(129,140,248,0.25);
}
```

---

## Animationen

```css
@keyframes fadeIn    { from { opacity:0; transform: translateY(4px);  } to { opacity:1; transform: translateY(0); } }
@keyframes slideDown { from { opacity:0; transform: translateY(-8px); } to { opacity:1; transform: translateY(0); } }
@keyframes slideUp   { from { opacity:0; transform: translateY(12px); } to { opacity:1; transform: translateY(0); } }
@keyframes shimmer   { 0% { background-position:-200% 0; } 100% { background-position:200% 0; } }

.animate-fadeIn    { animation: fadeIn 0.2s ease-out; }
.animate-slideDown { animation: slideDown 0.2s ease-out; }

/* Lade-Skeleton (Shimmer-Verlauf) */
.skeleton {
  background: linear-gradient(90deg, var(--color-muted) 25%, var(--color-border-light) 50%, var(--color-muted) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite;
  border-radius: var(--radius-sm);
}

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
  }
}
```

---

## Toasts (unten rechts)

```css
.toast-container {
  position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 9999;
  display: flex; flex-direction: column; gap: 0.5rem; pointer-events: none;
}
.toast {
  pointer-events: auto; padding: 0.75rem 1rem; border-radius: 0.75rem;
  font-size: 0.875rem; font-weight: 500;
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  animation: slideUp 0.25s ease-out; max-width: 360px;
}
.toast-success { background: var(--color-success);   color: #fff; }
.toast-error   { background: var(--color-destructive); color: #fff; }
.toast-info    { background: var(--color-primary);    color: #fff; }
```

---

## Anwenden auf eine neue Seite

1. Tokens-Block (`:root` + Dark-Media-Query) in das Stylesheet der Zielseite übernehmen.
   - In der **WordPress-Edition** heißen die Variablen `--pp-*` — dann 1:1 mappen
     (`--color-primary` → `--pp-primary` usw.) statt neue Namen zu erfinden.
2. Basis-Layer (`body`, Scrollbar, Focus-Ring, Input-Fokus) setzen.
3. Kanten-Glow-Block übernehmen — das trägt den meisten Wiedererkennungswert.
4. Flächen als `--color-surface` mit `--color-border` + `--shadow-sm/md`, Ecken `--radius-lg`.
5. Semantische Farben für Status (Erfolg/Fehler/Warnung/Info) + die `-light`-Variante als Hintergrund.
6. Keine Icons ungefragt einfügen (Projektregel).

**Merksatz:** Indigo-Primary, dunkle Sidebar, weiche Schatten, abgerundete Karten, Hover-Glow, Light/Dark gepaart.
