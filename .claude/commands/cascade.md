# Profit-Cascade & Cooperation-Agreement System

Bearbeite Features rund um Cooperation-Agreements, Profit-Verteilung,
Item-Erträge oder Cascade-Logik.

## Anweisung

Befolge diese Architektur-Regeln, wenn du `$ARGUMENTS` umsetzt.

## Kern-Konzept (Stand Migration 092)

**Cascade-Idee:** Wer Equipment besitzt und in ein Gruppen-Projekt
einbringt, bekommt einen Anteil am dortigen Gewinn zurück.
"Profit kaskadiert von der Gruppe zum Item-Owner."

**Daten-Fluss:**

```
inventory_items (Owner, daily_rate, purchase_price)
        ↓
agreement_inventory_contributions  (item × agreement, daily_rate, qty)
        ↓
cooperation_agreements             (project × profit_formula × roles)
        ↓
project (revenue_actual, cost_items, profit_distribution_status)
        ↓ (bei status='distributed')
inventory_item_earnings            (Snapshot — Owner-Payout fixiert)
```

## Tabellen-Übersicht

| Tabelle | Wofür |
|---------|-------|
| `cooperation_agreements` | Pro Projekt: Formel + Rollen + Status (draft/active/terminated) |
| `agreement_roles` | Pro Person: Stunden, Stundensatz, Kapital, Festbetrag |
| `agreement_inventory_contributions` | Pro Item: wer bringt was mit welchem Tagessatz ein |
| `agreement_signatures` | Wer hat unterschrieben (für Aktivierung) |
| `inventory_item_earnings` | Bei Projektabschluss fixierter Owner-Payout pro Item |
| `project_profit_shares` | Verteilung pro User (alt/parallel — wird ggf. konsolidiert) |
| `org_decisions` (type='profit_distribution') | Voting-Mechanismus für die Verteilung |

## Berechnungslogik

`src/lib/agreement-calc.ts` — `calculateProfitShares()`:

```
netProfit = revenue - costs - sum(pre_deductions)
für jede Person:
  hoursValue   = hours_estimate × hourly_rate
  invValue     = sum(daily_rate × qty × projectDays für seine Items)
  capitalValue = capital_contribution
  fixedValue   = fixed_amount

  share = (hoursShare × w.hours + invShare × w.inventory + capShare × w.capital + fixedShare × w.fixed) / weightSum
  payout = netProfit × share
```

**Pro-Item-Anteil** (`src/lib/inventory-earnings.ts`):

```
gross  = item.daily_rate × qty × projectDays
share  = gross / sum(gross aller items im agreement)
payout = netProfit × (w.inventory / weightSum) × share
```

## Snapshot bei Distribution (Migration 092)

- Trigger `projects_snapshot_on_distribution` ruft `snapshot_project_earnings(project_id)`
- RPC schreibt eine Zeile pro `agreement_inventory_contributions` in `inventory_item_earnings`
- UNIQUE auf `(item_id, project_id)` — idempotent
- Nutzt `formula_snapshot` jsonb, damit spätere Formeländerungen die Historie nicht verfälschen

## Wenn du eine Frage zum Verteilungsmodus hast

**Frage NICHT die Engineering-Antwort, sondern lege eine Group-Umfrage an** (`org_polls` mit `project_id`). Die Gruppe entscheidet pro Projekt:

- "Verteilen wir nach Umsatz oder Netto-Gewinn?"
- "Soll Item X einen höheren Anteil bekommen?"
- "Wie gewichten wir Inventar vs. Stunden vs. Kapital?"

Das ist **kein Bug**, das ist **Feature** der kollaborativen Logik.

## Wo du anfasst, wenn ...

| Du willst | Fass an |
|-----------|---------|
| Eine neue Gewinn-Formel-Komponente | `ProfitFormula` type + `calculateProfitShares` + `agreement-calc.ts` |
| Item-Erträge anzeigen | `lib/inventory-earnings.ts` + `item-earnings-section.tsx` |
| Aggregat-Reporting | `inventory-earnings-overview.tsx` |
| Voting für Verteilung | `org_decisions` mit `decision_type='profit_distribution'` + `decision-panel.tsx` |
| Cooperation-Agreement bearbeiten | `tab-profit.tsx` + `agreement-wizard.tsx` |
| Snapshot manuell auslösen | RPC `snapshot_project_earnings(project_id)` aufrufen |

## Wichtige Invarianten

1. **Snapshot ist Wahrheit nach Distribution.** Sobald ein Projekt
   `profit_distribution_status='distributed'` ist, sind die `inventory_item_earnings`-Zeilen
   die historisch korrekten Werte. Live-Berechnung gilt nur für noch
   nicht ausgeschüttete Projekte.

2. **Item-Owner zur Snapshot-Zeit.** `inventory_item_earnings.contributor_id` ist
   die Person, die zum Zeitpunkt der Verteilung das Item ins Agreement
   eingebracht hat (`agreement_inventory_contributions.contributor_id`).
   Ändert sich der Owner später, bleibt der historische Eintrag gültig.

3. **Formel-Snapshot.** `formula_snapshot` jsonb wird gespeichert, damit
   die Historie auch nachvollziehbar bleibt, wenn das Agreement später
   geändert wird.

4. **Group-Items vs. User-Items:** Aktuell trägt der User (über die
   Membership in der Group) ein, deshalb ist `contributor_id` ein
   Profile. Group-Items haben `owner_group_id`, der "Contributor" beim
   Einbringen ist trotzdem ein User.

## Was NICHT in den Snapshot geht

- Aktuelles Inventar-`current_value` (verändert sich, ist nicht historisch)
- Berechnungen für nicht-aktive Agreements (nur `status='active'`)
- Items, die nicht in einem Agreement sind (auch wenn sie in `bookings`
  sind — Bookings ohne Agreement zählen nicht zur Cascade)

## Häufige Fallstricke

- **Status auf 'distributed' setzen ohne aktives Agreement** → Trigger
  läuft, RPC findet kein Agreement, schreibt 0 Zeilen, kein Fehler. Ist
  by-design, aber verwirrend. Im UI darauf hinweisen.
- **`projectDays`-Berechnung:** date_end - date_start + 1, mindestens 1.
  Wenn date_start/end fehlt → 1.
- **Negative netProfit:** Im Snapshot als 0 behandeln (kein negativer
  Owner-Payout).
- **Idempotenz:** RPC respektiert UNIQUE — zweiter Aufruf ändert nichts.
  Re-distribute braucht erst DELETE der Snapshots.

## Verwandte Skills

- `/anfragen` — Inquiry-Pipeline (oft Vorlauf zu Projekten)
- `/inventar` — Item-Verwaltung, Kategorien, Sharing
- `/migrate` — Neue Migration anlegen
- `/component` — UI-Komponente nach Projekt-Conventions
