# Approval-Workflows

Vereinheitlichtes Vokabular und UI-Pattern für Approvals und Invitations in der App. Hilft, wenn du einen neuen Approval-Flow anlegst oder bestehende ergänzt — damit Status-Strings, Badge-Farben und Trigger-Logik nicht jedes Mal neu erfunden werden.

## Anweisung

Aufgabe: $ARGUMENTS

## Bestehende Approval-Flows (Inventar)

| Tabelle | Status-Feld | Werte | Wer entscheidet |
|---|---|---|---|
| `rental_items` | `approval_status` | `auto` / `pending` / `approved` / `rejected` | Item-Owner |
| `bookings` | `approval_status` | `pending` / `approved` / `rejected` | Item-Owner / Org-Admin (cross-org) |
| `equipment_requests` | `status` | `pending` / `approved` / `rejected` / `cancelled` / `returned` | Supplying-Org / -Profile |
| `project_invitations` | `status` | `pending` / `accepted` / `declined` | Eingeladener User |
| `inquiry_invitations` | `status` | `pending` / `accepted` / `declined` | Eingeladener User |
| `org_invitations` | `status` | `pending` / `accepted` / `expired` / `cancelled` | Eingeladener User |
| `org_decisions` | `status` | `open` / `approved` / `rejected` / `expired` | Mitglieder (Voting) |

**Pattern-Inkonsistenz**: bei *Item*-Approvals nennen wir's `approval_status`, bei *Invitations* nur `status`. Beides bewusst — Invitation-Tabellen haben **nur** Status, Approval-Tabellen haben Status *plus* fachliche Felder.

## Vokabular für Item-Approvals (neue Flows)

Wenn du eine neue Approval-Tabelle anlegst (z.B. Projekt-Equipment-Approval), nimm dieses Vokabular:

```sql
approval_status text NOT NULL DEFAULT 'auto'
  CHECK (approval_status IN ('auto','pending','approved','rejected'))
```

**Bedeutung:**
- `auto` — kein Approval nötig (eigenes Item / Modus `open` / group-owned). Versteckt das Badge in der UI.
- `pending` — wartet auf Owner-Entscheidung. Blockiert nachgelagerte Aktionen (z.B. "Ausgegeben" setzen).
- `approved` — Owner hat zugestimmt (manuell oder via `notify`-Modus auto-genehmigt).
- `rejected` — Owner hat abgelehnt. Item zählt nicht mehr in Verfügbarkeit, Konsument muss reagieren.

Plus die Audit-Felder:
```sql
approved_by uuid REFERENCES profiles(id) ON DELETE SET NULL,
approved_at timestamptz,
rejection_reason text
```

## Vokabular für Invitations (neue Flows)

```sql
status text NOT NULL DEFAULT 'pending'
  CHECK (status IN ('pending','accepted','declined'))
-- ggf. + 'expired' / 'cancelled' wenn das fachlich gebraucht wird
```

Plus `responded_at timestamptz` für Audit.

## Trigger-Pattern für initialen Status

Wenn der initiale Status nicht trivial ist (z.B. abhängig vom Owner-Modus), setze ihn per `BEFORE INSERT`-Trigger statt im Code:

```sql
CREATE OR REPLACE FUNCTION public.foo_init_approval()
RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE
  v_owner uuid; v_mode text; v_uid uuid := auth.uid();
BEGIN
  IF TG_OP <> 'INSERT' THEN RETURN NEW; END IF;
  SELECT owner_profile_id, loan_approval_mode INTO v_owner, v_mode
  FROM public.inventory_items WHERE id = NEW.inventory_item_id;

  IF v_owner IS NULL OR v_owner = v_uid THEN
    NEW.approval_status := 'auto';
  ELSE
    CASE COALESCE(v_mode,'manual')
      WHEN 'open'   THEN NEW.approval_status := 'auto';
      WHEN 'notify' THEN NEW.approval_status := 'approved';
                         NEW.approved_at := now();
      ELSE               NEW.approval_status := 'pending';
    END CASE;
  END IF;
  RETURN NEW;
END;
$$;
```

Vorbild: `rental_items_init_approval` (Migration 099, erweitert 101).

## RPC-Pattern für Approve/Reject

Damit nur der Item-Owner zustimmen darf, baue Approve/Reject als `SECURITY DEFINER`-RPCs. RLS auf der Tabelle erlaubt den UPDATE, der RPC validiert den Owner zusätzlich:

```sql
CREATE OR REPLACE FUNCTION public.approve_foo_item(
  p_id uuid,
  p_agreed_rate numeric DEFAULT NULL  -- optionale Felder
) RETURNS public.foo_items
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE
  v_uid uuid := auth.uid();
  v_owner uuid;
  v_row public.foo_items;
BEGIN
  IF v_uid IS NULL THEN RAISE EXCEPTION 'not authenticated'; END IF;

  SELECT i.owner_profile_id INTO v_owner
  FROM public.foo_items ri
  JOIN public.inventory_items i ON i.id = ri.inventory_item_id
  WHERE ri.id = p_id;

  IF v_owner IS NULL OR v_owner <> v_uid THEN
    RAISE EXCEPTION 'only item owner can approve';
  END IF;

  UPDATE public.foo_items
     SET approval_status = 'approved',
         approved_by = v_uid,
         approved_at = now(),
         rejection_reason = NULL
   WHERE id = p_id
   RETURNING * INTO v_row;
  RETURN v_row;
END;
$$;
GRANT EXECUTE ON FUNCTION public.approve_foo_item(uuid, numeric) TO authenticated;
```

Vorbild: `approve_rental_item` / `reject_rental_item` in Migration 099+101.

## UI-Konventionen

### Status-Badge-Farben

Für Item-Approvals:
```ts
const approvalColors: Record<ApprovalStatus, { bg: string; color: string }> = {
  auto:     { bg: "var(--color-muted)",             color: "var(--color-muted-foreground)" },
  pending:  { bg: "var(--color-warning-light)",     color: "var(--color-warning)" },
  approved: { bg: "var(--color-success-light)",     color: "var(--color-success)" },
  rejected: { bg: "var(--color-destructive-light)", color: "var(--color-destructive)" },
};
```

**`auto` zeigt kein Badge** — Default-Zustand, kein Hinweis nötig.

Für Invitations: nutze dieselben Farben, mapping `pending` → warning, `accepted` → success, `declined` → destructive.

### Approve-Block-Layout

Owner-Aktion bei `pending`:

```tsx
<div className="flex flex-wrap items-center gap-2 text-xs px-3 py-2 rounded"
     style={{ background: "var(--color-warning-light)", border: "1px dashed var(--color-warning)" }}>
  <span style={{ color: "var(--color-warning)" }}>
    Vorgeschlagener Wert: <strong>{proposed} €/Tag</strong> — du kannst zustimmen, ändern oder ablehnen.
  </span>
  <div className="flex items-center gap-1 ml-auto">
    <input type="number" ... />
    <button onClick={() => onApprove(rate)}>Akzeptieren</button>
    <button onClick={() => onApprove(0)}>Verzicht</button>
    <button onClick={onReject}>Ablehnen</button>
  </div>
</div>
```

Vorbild: `OwnerApprovalBlock` / `MyItemApprovalControls` in `/rentals/[id]` und `/rentals`.

### Visuelle Item-Card-Zustände

Eine Approval-Zeile hat drei Zustände — Border/Background nur rot wenn wirklich blockierend:

```ts
const blocking = (missingPrecondition && status === "pending") || conflict;
border: blocking ? destructive : normal
```

**Nicht** rot nur weil `missingPrecondition` (= z.B. Share fehlt) wenn der Status schon `approved` ist — die Entscheidung ist gefallen.

Siehe `/modi` Visuelle Zustände + `/verleih` für das konkrete Beispiel.

## Blocker-Logik

Approval blockiert oft eine nachgelagerte Aktion. Beispiel: Verleih darf nicht auf `active` solange ein `rental_item` `pending` ist.

Im UI-Handler:
```ts
if (newStatus === "active") {
  const pending = items.filter((it) => it.approval_status === "pending");
  if (pending.length > 0) {
    showToast(`Ausgabe blockiert: ${pending.length} Gerät...`, "error");
    return;
  }
}
```

In der DB:
- Hard-Constraint (Trigger oder CHECK) wenn die Regel zwingend ist.
- Soft (UI-only) wenn Edge-Cases erlaubt sein sollen (z.B. Admin-Override).

## Notification-Hooks

Pending-Approvals sollten in der **Sidebar-Glocke** angezeigt werden:

```ts
// Im sidebar.tsx loadCounts():
const { data } = activeGroupId
  ? Promise.resolve({ data: [] })   // /modi: Owner-Aktionen nur im Solo-Modus
  : await supabase
      .from("rental_items")
      .select("id, inventory_items!inner(owner_profile_id)")
      .eq("approval_status", "pending")
      .eq("inventory_items.owner_profile_id", userId);
counts["/rentals"] = data?.length || 0;
```

Plus Realtime-Subscription auf die Approval-Tabelle damit der Counter live updated.

Wenn der Approval-Flow auch Push-Notifications (Email/Telegram) auslösen soll: über Trigger oder Edge-Function. Aktuell nur in-App.

## Audit & History

Approval-Aktionen sind auditierungs-pflichtig. Pflichtfelder:

- `approved_by uuid` — wer hat (de)approved
- `approved_at timestamptz` — wann
- `rejection_reason text` — bei `rejected`: Grund (optional)

Bei wiederholten Approvals (z.B. Re-Submit nach Ablehnung) den alten Eintrag *nicht* löschen, sondern überschreiben — oder eine separate History-Tabelle anlegen (`*_approval_history`). Bisher haben wir das nirgends gebraucht.

## Cross-Modus-Hinweis

Owner-Approvals gehören in den Solo-Modus (siehe `/modi`). Im Gruppen-Modus dürfen Owner-Buttons nicht erscheinen, auch wenn der User Mitglied der Gruppe + Item-Owner ist:

```tsx
const showApproval = isSolo && isMyItem && status === "pending";
```

Inline-Owner-Aktionen *im Gruppen-Modus* sind Cross-Modus-Pattern 3 (siehe `/modi`) — explizit zu begründen, nicht Default.

## Häufige Fallen

1. **Approve auf Ebene B legt nichts auf Ebene A an** — Beispiel: `approve_rental_item` setzt nur `rental_items.approval_status`, legt aber keinen `inventory_group_shares`-Eintrag an. Beide Konzepte sind getrennt (siehe `/modi`).

2. **`rejected` Items in Verfügbarkeit nicht ignorieren** — wenn ein Item abgelehnt ist, blockiert es trotzdem den Bestand? Nein. RPC `check_inventory_availability` filtert `approval_status != 'rejected'` raus.

3. **Trigger ändert Status bei UPDATE** — der Init-Trigger sollte nur bei `TG_OP = 'INSERT'` greifen, sonst überschreibt er manuelle Approve-Operationen. Siehe Migration 099.

4. **Visueller Zustand hängt am `missingPrecondition`-Flag, nicht am Status** — führt zu "Box bleibt rot obwohl approved". Immer `&& status === 'pending'` kombinieren.

5. **RPC ohne Owner-Check** — wenn nur RLS prüft, kann jeder mit UPDATE-Recht den Status setzen. Im RPC immer eigene Owner-Validation, weil `SECURITY DEFINER` RLS umgeht.

## Checkliste neuer Approval-Flow

1. [ ] Status-Feld mit CHECK-Constraint + Default
2. [ ] Audit-Felder (`approved_by`, `approved_at`, `rejection_reason`)
3. [ ] BEFORE-INSERT-Trigger für Initial-Status (wenn nicht trivial)
4. [ ] Approve- und Reject-RPCs mit Owner-Validation
5. [ ] RLS: Owner sieht/aktualisiert auch fremde Verleihe seiner Items (siehe `/rls`)
6. [ ] Realtime-Publication erweitern
7. [ ] Sidebar-Badge mit Counter
8. [ ] UI: Approval-Block bei `pending`, Status-Badge bei !`auto`, Border-rot nur bei blockierend
9. [ ] `isSolo`-Guard für Owner-Buttons (siehe `/modi`)
10. [ ] Blocker-Logik im Status-Wechsel-Handler

## Verwandte Skills

- `/verleih` — `rental_items.approval_status` als ausführliches Beispiel.
- `/rls` — Owner-Sicht via SECURITY DEFINER-Helper.
- `/modi` — Owner-Aktion = Solo-Modus.
- `/ownership` — XOR-Owner-Modell, das hinter den meisten Approvals steht.
- `/realtime` — Counter live halten.
