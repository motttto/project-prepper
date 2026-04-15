# Backups

Lokale JSON-Exports der Dunkelstrom-Org-Daten. Werden NICHT ins Git committed.

## Backup erstellen

```bash
cd backups
TOKEN=$(security find-generic-password -s "supabase-deploy-token" -w) node backup-dunkelstrom.mjs
```

Erstellt einen neuen Ordner mit Timestamp und legt pro Tabelle eine JSON-Datei an.

## Backup-Inhalt

- Org-Stammdaten: `organizations`, `org_memberships`, `profiles`, `roles`
- Inventar: `inventory_items`, `inventory_categories`, `inventory_units`
- Projekte: `projects` + alle Details (Tasks, Schedule, Checklists, Files, Costs, Bookings)
- Gewinnverteilung: `project_profit_shares`
- Beschlüsse: `org_decisions`, `org_decision_votes`
- Anfragen: `inquiries`, `inquiry_invitations`
- Umfragen: `org_polls` + Options + Votes
- Kooperationsvereinbarungen: `cooperation_agreements` + Zubehör
- Activity-Log, Kalender, Email-Config

## Restore

Nicht automatisiert. Bei Bedarf manuell via SQL INSERT aus den JSON-Files.
