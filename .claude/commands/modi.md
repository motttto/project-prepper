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

## Implementation-Status (Stand: nach den Commits 423cdb4..a0e7892)

Umgesetzt:
- [x] **Verleih-Picker im Gruppen-Modus** lädt jetzt auch via `inventory_group_shares` geteilte Items (`src/components/rentals/equipment-picker.tsx`).
- [x] **Owner-Approval-Buttons** in `/rentals/[id]` nur sichtbar wenn `isSolo`.
- [x] **Sidebar-Badge** für pending Rental-Approvals nur im Solo-Modus (`activeGroupId === null`).
- [x] **"Mein Equipment unterwegs"** Sektion nur im Solo-Modus gerendert.
- [x] **Inline "Jetzt freigeben"-Button** im Verleih-Detail (Pattern 3) — legt `inventory_group_shares`-Eintrag an und synct `proposed_rate`. Nur sichtbar bei `isMyItem && status === 'pending'`.
- [x] **Visuelle Item-Card-Zustände** richtig: Border/Background nur rot wenn `conflict || (missingShare && status === 'pending')`.

Noch offen:
- [ ] **Tab-Equipment im Projekt** (`src/components/projects/tab-equipment.tsx`): Picker-Query bezieht `inventory_group_shares` noch nicht ein — gleiches Problem wie Verleih hatte.
- [ ] **Projekt-Bookings nutzen Approval-Flow nicht**: rental_items hat das System, bookings nicht. Migration nötig wenn Owner-Approval auch bei Projekt-Buchungen greifen soll.
- [ ] **Hinweis bei abgelehnten Items im Verleiher-Workflow**: aktuell wird nur "Ausleihe abgelehnt"-Badge angezeigt; ein klarer Call-to-Action ("Alternative wählen oder Item entfernen") fehlt.
- [ ] **Sharing-UI im Inventar-Modal nur im Solo-Kontext** rendert ist zu prüfen — Section "Teilen mit Gruppen" wird unter `isEditable && myGroups.length > 0` gerendert, ist aber im Gruppen-Modus auch erreichbar wenn das Item dem User gehört.

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

## Die zwei Approval-Ebenen — nicht vermischen!

Es gibt **zwei unabhängige Konzepte**, die Solo-Owner und Gruppen-Verleih verknüpfen. Beim Code-Schreiben unbedingt sauber trennen:

### Ebene A: Generelle Gruppen-Freigabe (`inventory_group_shares`)
- **Wofür**: Das Item ist für *zukünftige* Verleihe der Gruppe verfügbar. Standard-Tagessatz, Bedingungen, Approval-Modus pro Share.
- **Sichtbar in**: Inventar-Picker, Verleih-Picker, evtl. Projekt-Booking-Picker.
- **Wer setzt**: Solo-Owner im Solo-Modus (oder im Gruppen-Modus inline beim konkreten Verleih, wenn er Owner ist — Ausnahme zur Solo-only-Regel).
- **Tabelle**: `inventory_group_shares` mit `daily_rate`, `conditions`, `revoked_at`.
- **Fehlt = Item taucht nicht im Picker auf**.

### Ebene B: Per-Verleih-Zustimmung (`rental_items.approval_status`)
- **Wofür**: Pro konkretem Verleih entscheidet der Owner — auch wenn das Item generell für die Gruppe freigegeben ist (Modus `manual`).
- **Sichtbar in**: `/rentals/[id]` (Pending-Block), `/rentals` "Mein Equipment unterwegs".
- **Wer setzt**: Item-Owner, nur im Solo-Modus (gemäß Leitprinzip).
- **Tabelle**: `rental_items` mit `approval_status` (`auto`/`pending`/`approved`/`rejected`), `proposed_rate`, `agreed_rate`, `approved_by`, `rejection_reason`.
- **Blockiert die Ausgabe** (Verleih-Status `active`) solange `pending`.

**Wichtig**: Approve auf Ebene B legt **nicht** automatisch einen Share auf Ebene A an. Wenn der Owner im konkreten Verleih zustimmt, ist das nur für *diesen* Verleih ok. Für die generelle Verfügbarkeit braucht's einen separaten `inventory_group_shares`-Eintrag (über den Inline-Button im Verleih-Detail oder über die Inventar-Detail-Seite).

## Visuelle Zustände der Item-Card im Verleih-Detail

Pro `rental_item` gibt es drei optische Zustände — checke beim Stylen *immer* die Kombination aus `missingShare`, `status` und `conflict`:

| Zustand | Bedingung | Border / Background |
|---|---|---|
| **Blockiert** (pending + keine Freigabe) | `missingShare && status === 'pending'` *oder* `quantity > available` | rot |
| **Neutral / OK** | sonst | normale Border, Standard-Background |
| **Überbucht** (Konflikt) | `conflict = quantity > av.available` | rot + "Überbucht"-Badge |

**Pattern** für die Bedingung:
```tsx
const missingShare = it.needsExternalApproval && it.hasShareForGroup === false;
const shareBlocks = missingShare && status === "pending"; // tatsaechlich blockierend
const conflict = av && it.quantity > av.available;
// Border/Background-Style:
border: conflict || shareBlocks ? destructive : normal
```

Approved/auto/rejected-Items: visuell **neutral**, auch wenn kein Share existiert — die Per-Verleih-Entscheidung ist gefallen.

## Cross-Modus-Aktionen: drei Patterns

Wenn eine Owner-Aktion (Solo-Modus-Domäne) in einem Gruppen-Kontext angeboten werden soll, gibt's drei mögliche Patterns. Wähle je nach UX-Kontext:

### Pattern 1: Reine Anzeige im Gruppen-Modus, Aktion nur Solo
Die UI zeigt einen Hinweis ("Eigentümer muss zustimmen"), keine Aktion. Owner muss aktiv in Solo wechseln. Default-Pattern für Approve/Reject im `/rentals/[id]`.

### Pattern 2: Modus-Switch beim Klick
Klick auf den Aktion-Button ruft `switchWorkspace(null)` und navigiert ggf. zur passenden Solo-Seite. Sinnvoll bei komplexen Aktionen, die mehrere Felder brauchen (z.B. komplette Share-Konfig mit Bedingungen, Multi-Group-Sharing).

### Pattern 3: Inline-Aktion mit Mini-Form
Ein kleiner Inline-Form direkt im Gruppen-Detail (z.B. nur Tagessatz-Input + "Jetzt freigeben"-Button) führt die Owner-Aktion direkt aus. RLS prüft serverseitig per `auth.uid()` — der Workspace ist ohnehin nur Daten-Filter, kein Auth-Switch. Sinnvoll bei punktuellen Owner-Aktionen mit klarem Kontext (z.B. "Item für diese Gruppe freigeben, weil ich gerade hier reinverliehen werde").

**Ausnahme dokumentieren**: Pattern 3 ist eine Abweichung von "Owner-Aktionen nur im Solo". Begründe es im Code-Kommentar ("inline-Aktion im konkreten Verleih-Kontext").

## Approval-Flow-Diagramm (Owner-Sicht)

```
1. Verleih wird angelegt (Gruppen-Modus)
   ├─ Item gehört Gruppe       → approval_status = 'auto'         ✅ fertig
   ├─ Item gehört Solo, Modus open    → approval_status = 'auto'   ✅ fertig
   ├─ Item gehört Solo, Modus notify  → approval_status = 'approved' ✅ Info an Owner
   └─ Item gehört Solo, Modus manual  → approval_status = 'pending' ⏸️ wartet auf Owner

2. Owner-Sicht (Solo-Modus):
   /rentals → "Mein Equipment unterwegs"
   Pending-Items: Input mit proposed_rate + [Akzeptieren] [Verzicht] [Ablehnen]

3. Approval-Aktion (Solo):
   - Akzeptieren (mit rate)  → approval_status = 'approved', agreed_rate = rate
   - Verzicht                → approval_status = 'approved', agreed_rate = 0
   - Ablehnen (mit Grund)    → approval_status = 'rejected', rejection_reason = ?

4. Gruppen-Sicht (nach Approval):
   /rentals/[id] → Item ist neutral/grün, Status-Badge "Freigegeben"
   Verleih kann auf 'active' (Ausgegeben) gesetzt werden
```

Status-Badge-Mapping (`rentalItemApprovalLabels`):
- `auto` — versteckt (kein Badge)
- `pending` — gelb "Freigabe ausstehend"
- `approved` — grün "Freigegeben"
- `rejected` — rot "Ausleihe abgelehnt"

## Verrechnung pro Eigentümer (Kostenbox)

In `/rentals/[id]` zeigt die Kostenzusammenfassung pro externem Owner einen Auszahlungs-Block:
- `agreed_amount = Σ (agreed_rate × tage × quantity)` über alle eigenen Items des Owners
- Items mit `agreed_rate IS NULL` (= pending): in `pending_amount` zählen, mit gelbem "+X ausstehend"-Hinweis
- "Verbleibend bei Verleih-Org" = `rental_fee - Σ owner_payouts.agreed`

Wenn `agreed_rate = 0` (Verzicht): Item taucht im Block auf mit `0 €`, ist also explizit dokumentiert. Items mit `approval_status = 'rejected'` werden komplett ignoriert.

## Häufige Fallen

1. **PostgREST `.neq` mit NULL matched nicht.** Filter wie `.neq("rentals.owner_profile_id", me)` schließen Gruppen-Verleihe (owner_profile_id=NULL) versehentlich aus. Lösung: clientseitig filtern oder `.or("col.is.null,col.neq.value")`.

2. **State bei Workspace-Switch nicht leeren** → vorheriger Workspace bleibt sichtbar bis neuer Load durch ist. Lösung: `useEffect(() => setItems([]), [groupId])`.

3. **`useCallback`-Identity ändert sich nicht wenn nur `groupId` fehlt** → deps müssen `[supabase, ownerId, groupId]` enthalten.

4. **Rote Optik nach Approval bleibt hängen.** `missingShare`-basierte Styles müssen mit `status === 'pending'` kombiniert werden (siehe Visuelle Zustände).

5. **Approve auf Ebene B legt keinen Ebene-A-Share an** — bewusst getrennt. Owner muss separat freigeben falls er das Item generell für die Gruppe verfügbar haben will.

6. **RLS-Rekursion bei Cross-Table-Policies**: rentals_select referenzierte rental_items_select und umgekehrt → Endlosrekursion. Lösung in Migration 100: SECURITY DEFINER-Helper (`user_owns_item_in_rental`, `user_is_rental_owner`) entkoppeln.
