# Kosten & Budget bearbeiten

Hilfe bei Kostenposten, Budget-Kalkulation und MwSt-Berechnung.

## Anweisung

Aufgabe: $ARGUMENTS

### Kontext
- **Kosten-Tabelle:** `cost_items` mit Kategorien: `personnel`, `material`, `inventory`, `external`, `other`
- **Budget-Felder auf `projects`:** `budget_planned`, `budget_honorar`, `budget_technik`, `budget_transport`
- **MwSt:** `vat_rate` auf `cost_items` (0%, 7%, 19%)
- **RLS:** Kosten nur sichtbar für Projektmitglieder + Admins (`is_project_member()` / `is_admin()`)
- **UI-Sichtbarkeit:** Budget wird im UI über `canViewBudget` Prop gesteuert

### Relevante Dateien
- `src/app/(dashboard)/costs/page.tsx` — Globale Kostenübersicht (alle Projekte)
- `src/components/projects/tab-costs.tsx` — Kosten-Tab im Projekt-Detail
- `src/app/(dashboard)/dashboard/page.tsx` — KPI-Cards mit Budget-Summen

### Formatierung
- Euro-Beträge: `toLocaleString("de-DE", { minimumFractionDigits: 2 })`
- Kategorien mit deutschen Labels und Farb-Badges
- MwSt-Optionen: 19%, 7%, 0% (befreit)

### Häufige Aufgaben
- Neue Kosten-Kategorie hinzufügen → `categoryLabels` + `categoryColors` in beiden Dateien
- Budget-Warnung anpassen → Dashboard KPI-Section
- Export-Funktion bauen → CSV/Excel aus `cost_items` generieren
- Kalkulations-Logik → Netto/Brutto mit MwSt berechnen
