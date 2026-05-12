# Solo-Modus vs Gruppen-Modus — Trennung der Verantwortlichkeiten

Architektur-Guideline für UI- und Daten-Filterung je nach aktivem Workspace.
Lies und befolge das, wenn du Code anfasst, der Inventar, Verleih, Anfragen,
Projekte, Kalender oder Sidebar/Badge-Logik berührt.

## Anweisung

Aufgabe: $ARGUMENTS

## Leitprinzip

**Solo-Modus = persönlicher Workspace, Gruppen-Modus = kollektiver Workspace.**

- **Owner-Entscheidungen** (Approval, Sharing, Approval-Modus, Item-Eigenschaften) gehören **immer** in den Solo-Modus.
- **Operative Aktionen für eine Gruppe** (Verleih, Projekt, Anfrage anlegen / Status ändern) gehören **immer** in den Gruppen-Modus.
- Wechsel zwischen den Modi ist trivial (`useWorkspace().switchWorkspace(...)`), aber jeder Modus hat klare, getrennte Zuständigkeiten.

## Workspace-Detection im Code

```ts
import { useWorkspace } from "@/contexts/org-context";
const { groupId, isSolo } = useWorkspace();
// groupId: null im Solo-Modus, UUID im Gruppen-Modus
// isSolo: boolean alias (groupId === null)
```

Im Code **immer** `groupId`/`isSolo` zur Modus-Unterscheidung nutzen — niemals den User-Owner direkt vergleichen.

## Sicht-Matrix (was wird wo geladen)

| Bereich | Solo | Gruppe |
|---|---|---|
| Inventar-Liste (`/inventory`) | `WHERE owner_profile_id = me` | `WHERE owner_group_id = group` |
| Inventar-Picker (Verleih, Projekt) | `WHERE owner_profile_id = me` | `WHERE owner_group_id = group` **∪** Items via `inventory_group_shares.group_id = group AND revoked_at IS NULL` |
| Verleihe-Liste (`/rentals`) | `WHERE owner_profile_id = me` | `WHERE owner_group_id = group` |
| "Mein Equipment unterwegs" (auf `/rentals`) | `rental_items` mit `inventory_items.owner_profile_id = me` **AND** `rentals.owner_profile_id != me` (clientseitig wegen NULL) | ❌ nicht rendern |
| Anfragen | `owner_profile_id = me` | `owner_group_id = group` (plus Legacy `group_id`) |
| Projekte | `owner_profile_id = me` | `owner_group_id = group` |
| Kalender | Solo-Items + Solo-Verleihe + Solo-Bookings | Gruppen-Items + Gruppen-Verleihe + Gruppen-Bookings |

**Doppelte Isolation:** Bei XOR-Tabellen (rentals, inquiries, projects) im Solo-Query zusätzlich `.is("owner_group_id", null)` und im Gruppen-Query `.is("owner_profile_id", null)` setzen — verhindert Stale-State beim Workspace-Switch.

## Aktion-Matrix (was darf wo passieren)

| Aktion | Solo | Gruppe | Begründung |
|---|---|---|---|
| Eigenes Item anlegen / bearbeiten | ✅ Owner | ❌ | Items mit `owner_profile_id` sind Solo-Eigentum |
| Gruppen-Item anlegen / bearbeiten | ❌ | ✅ Founder/Mitglied | Items mit `owner_group_id` sind kollektiv |
| `loan_approval_mode` setzen | ✅ Owner | ❌ | Owner-Eigenschaft pro Item |
| `inventory_group_shares` setzen / revoken | ✅ Owner | ❌ | Owner gibt Item für Gruppe frei |
| `approve_rental_item` / `reject_rental_item` | ✅ Owner | ❌ | Owner entscheidet, egal wo Item benutzt wird |
| Eigenen Solo-Verleih anlegen | ✅ | — | rental.owner_profile_id = me |
| Gruppen-Verleih anlegen / Status ändern | ❌ | ✅ Mitglied mit `rentals_edit` | rental.owner_group_id = group |
| Projekt-Buchung freigeben (cross-org) | ✅ Owner falls Solo-Item | ✅ falls Gruppen-Item | Same Owner-Regel |

## UI-Regeln

1. **Approval-Controls** (Akzeptieren/Verzicht/Ablehnen) für ein `rental_item`:
   ```tsx
   const canApprove = isSolo
     && currentUser?.id === inventory_items?.owner_profile_id
     && approval_status === "pending";
   ```
   Im Gruppen-Modus **nicht zeigen**, auch wenn das Item dem User gehört.

2. **"Mein Equipment unterwegs"-Sektion** auf `/rentals` nur rendern wenn `isSolo === true`.

3. **Sidebar-Badge** für Pending-Rental-Approvals nur im Solo-Modus laden/zeigen.

4. **Inventar-Detail-Modal** — `loan_approval_mode` Dropdown + "Teilen mit Gruppen"-Section nur im Solo-Kontext (Item gehört dem aktuellen User).

5. **Verleih-Picker** im Verleih-Form bzw. `tab-equipment`:
   - Solo: `.from("inventory_items").eq("owner_profile_id", me)`
   - Gruppe: union aus `.eq("owner_group_id", group)` und Items aus `inventory_group_shares.group_id = group`

6. **Wenn der User im falschen Modus eine Aktion versucht** (z.B. im Gruppen-Modus auf ein Item-Owner-Approval-Element klickt, das er nicht sehen sollte), zeige einen Hinweis-Toast mit Wechsel-Vorschlag — niemals stillschweigend die Aktion durchführen.

## Cross-Modus-Workflow (Vergleich am ROLAND)

1. **Solo M** öffnet Inventar → ROLAND-Item:
   - Setzt `loan_approval_mode = manual` (Freigabe pro Verleih nötig)
   - Teilt ROLAND mit Dunkelstrom (`inventory_group_shares.daily_rate = 20`)
2. **Workspace-Wechsel zu Dunkelstrom**, User X legt Verleih an Theo an:
   - Picker zeigt ROLAND (wegen Share)
   - Beim Insert in `rental_items`: Trigger setzt `proposed_rate = 20` (aus Share), `approval_status = pending` (wegen `manual`)
3. **Wechsel zurück zu Solo M**:
   - `/rentals` → "Mein Equipment unterwegs" zeigt ROLAND mit "Freigabe ausstehend"
   - Block: Input "20 €/Tag" + Akzeptieren / Verzicht (=0) / Ablehnen
   - RPC `approve_rental_item(p_rental_item_id, p_agreed_rate)` setzt `agreed_rate` und Status
4. **Wechsel zu Dunkelstrom**:
   - Verleih-Detail zeigt ROLAND nun als "Freigegeben" mit zugestimmtem Tagessatz
   - Verleih kann jetzt auf `active` gesetzt werden (war vorher blockiert)

## Implementation-Lücken (Stand: Migration 101)

Diese Stellen entsprechen aktuell **nicht** der Guideline und sollten beim nächsten Touchen gefixt werden:

- [ ] **Verleih-Picker im Gruppen-Modus**: lädt nur `owner_group_id = group`-Items, nicht via `inventory_group_shares` geteilte Items. → `src/components/rentals/equipment-picker.tsx` Query erweitern.
- [ ] **Owner-Approval-Buttons** in `/rentals/[id]` und in "Mein Equipment unterwegs" werden auch im Gruppen-Modus gezeigt — sollte `&& isSolo` Check bekommen.
- [ ] **Sidebar-Badge** an "Verleih"/"Mein Verleih" zählt Pending-Approvals immer (auch im Gruppen-Modus). → `src/components/layout/sidebar.tsx` `loadCounts()` an `groupId === null` koppeln.
- [ ] **"Mein Equipment unterwegs"** wird zwar nur mit Solo-Filter geladen (`owner_profile_id = ownerId`), aber das `<section>` rendert sich auch im Gruppen-Modus, wenn Daten irgendwie reinkommen. Expliziter `{isSolo && ...}` Guard.
- [ ] **Inline-Sharing-Button** beim "Nicht für die Gruppe freigegeben"-Hinweis im Verleih-Detail: aktuell nur Anzeige. → Button "Jetzt freigeben" der einen `inventory_group_shares`-Eintrag anlegt. **Aber:** Wenn der User im Gruppen-Modus ist und das Item ihm als Solo-User gehört, muss der Button via `switchWorkspace(null)` erst in den Solo-Modus springen und dort den Share öffnen. Cross-Modus-Aktion immer erst Modus wechseln, dann ausführen.
- [ ] **Tab-Equipment im Projekt**: gleiches Picker-Problem wie Verleih.

## Realtime-Hinweis

`useRealtimeTable` ohne Filter triggert bei jeder DB-Änderung; die `onDataChange`-Callbacks holen aktuell ohnehin per `groupId`-Filter neu. **Nicht** den Filter im Subscription-Channel ergänzen — die Re-Fetch-Logik im Callback ist die Quelle der Wahrheit.

## State-Reset beim Workspace-Switch

Bei jedem Page-Component, das per Workspace gefiltert lädt, einen Reset-Effect ergänzen:

```ts
useEffect(() => {
  setItems([]);
  setLoading(true);
}, [groupId]);
```

Verhindert, dass stale Daten vom vorherigen Workspace beim Switch kurz sichtbar bleiben.

## Permissions vs Modus

Permissions (`rentals_edit`, `inventory_edit` etc.) auf `org_memberships.permissions` sind **gruppenspezifisch**. Im Solo-Modus existieren sie nicht — Solo-User hat per Definition alle Rechte an seinen eigenen Daten. Daher:

- Im Gruppen-Modus: `hasPermission(currentUser, "rentals_edit")` für Aktionen prüfen.
- Im Solo-Modus: Solo-Owner darf immer alles an seinen Items/Verleihen.

## Glossar

- **Solo-Owner**: User, dessen Profil eine Ressource direkt besitzt (`owner_profile_id = profile.id`).
- **Gruppen-Owner**: Gruppe besitzt die Ressource (`owner_group_id = group.id`). Verwaltet wird kollektiv durch Mitglieder.
- **Share**: Solo-Owner gibt sein Item explizit für eine Gruppe frei via `inventory_group_shares` (mit Tagessatz, Bedingungen, Approval-Modus).
- **Approval-Modus** (`inventory_items.loan_approval_mode`): `open` (keine Freigabe), `notify` (Info an Owner), `manual` (Owner muss zustimmen).
- **Tagessatz-Verhandlung**: `rental_items.proposed_rate` (vom Verleiher vorgeschlagen) ↔ `rental_items.agreed_rate` (vom Owner bestätigt, ggf. Override oder Verzicht=0).
