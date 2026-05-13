# Verleih-Feature bearbeiten

Externer Equipment-Verleih an Personen (nicht projekt-gebunden). Liegt zwischen "Anfragen" und "Projekte" in der Sidebar. Hat eine eigene Approval-Pipeline und Verrechnungs-Logik.

## Anweisung

Aufgabe: $ARGUMENTS

## Kontext

- **Tabellen:**
  - `rentals` (Header): Leiher-Kontaktdaten, Zeitraum, Kaution, Leihgebühr, Status, Notes
  - `rental_items` (Lines): pro Verleih mehrere Geräte mit `quantity`, Approval-Felder, Tagessätzen
- **Owner-XOR**: `owner_profile_id` (Solo) ↔ `owner_group_id` (Gruppe). Siehe `/ownership`.
- **Sidebar-Route**: `/rentals` (Liste) und `/rentals/[id]` (Detail).
- **Permissions**: `rentals_view`, `rentals_create`, `rentals_edit` (Migration 098 + Types).
- **Kalender-Integration**: Synthetische Events aus `rentals` als "Verleih"-Collection (`RENTALS_GROUP_ID = "__rentals__"`); klick auf Event → Detail-Seite.

## Schema (Stand: Migration 101)

```sql
rentals (
  id uuid PK,
  owner_profile_id uuid REFERENCES profiles,   -- XOR mit owner_group_id
  owner_group_id   uuid REFERENCES groups,
  borrower_name    text NOT NULL,
  borrower_email, borrower_phone, borrower_address text,
  borrower_profile_id uuid REFERENCES profiles,  -- optional: interner User als Leiher
  date_from, date_to date NOT NULL,              -- date_to >= date_from CHECK
  deposit_amount numeric(10,2) DEFAULT 0,
  rental_fee     numeric(10,2),
  status text CHECK ('reserved'|'active'|'returned'|'cancelled') DEFAULT 'reserved',
  notes text,
  created_by uuid, created_at, updated_at
)

rental_items (
  id uuid PK,
  rental_id uuid REFERENCES rentals ON DELETE CASCADE,
  inventory_item_id uuid REFERENCES inventory_items ON DELETE RESTRICT,
  unit_id uuid REFERENCES inventory_units ON DELETE SET NULL,
  quantity int CHECK > 0,
  notes text,
  -- Approval-System (Migration 099):
  approval_status text CHECK ('auto'|'pending'|'approved'|'rejected') DEFAULT 'auto',
  approved_by uuid, approved_at timestamptz, rejection_reason text,
  -- Tagessatz-Verhandlung (Migration 101):
  proposed_rate numeric(10,2),     -- vom Verleiher (Default: share.daily_rate, sonst items.cost_per_day)
  agreed_rate   numeric(10,2),     -- vom Owner bestätigt; 0 = Verzicht; NULL = noch nicht vereinbart
  created_at
)
```

## Approval-Pipeline (Migration 099-101)

Pro `rental_item` läuft beim Insert der Trigger `rental_items_init_approval` (BEFORE INSERT, SECURITY DEFINER):

1. **Item-Owner ermitteln** (`inventory_items.owner_profile_id`, `loan_approval_mode`).
2. **`approval_status`** setzen:
   - Eigenes Item (`v_owner = auth.uid()`) → `auto`
   - Group-owned Item (`v_owner IS NULL`) → `auto`
   - Solo-owned von anderem User:
     - Modus `open` → `auto`
     - Modus `notify` → `approved` (mit `approved_at = now()`)
     - Modus `manual` → `pending`
3. **`proposed_rate`** setzen (sofern nicht explizit gesetzt):
   - 1. Wahl: `inventory_group_shares.daily_rate` für (Item × Verleih-Gruppe)
   - 2. Wahl: `inventory_items.cost_per_day`
   - Fallback: `0`
4. **`agreed_rate`** bei `auto`/`approved` auf `proposed_rate` setzen, sonst NULL.

Siehe `/modi` für die zwei Approval-Ebenen (Generelle Gruppen-Freigabe vs Per-Verleih-Zustimmung).

## RPCs

| Funktion | Wer darf | Was tut sie |
|---|---|---|
| `check_inventory_availability(item_id, from, to, exclude_rental_id?, exclude_booking_id?)` | authenticated | Returns `(total, reserved, available)`. Reserved = SUM(`bookings.quantity`) + SUM(`rental_items.quantity` wo `approval_status != 'rejected'`). |
| `approve_rental_item(rental_item_id, agreed_rate?)` | authenticated, **nur Item-Owner** | Setzt `approval_status='approved'`, `approved_by=auth.uid()`, `agreed_rate` aus Parameter oder `proposed_rate`. |
| `reject_rental_item(rental_item_id, reason?)` | authenticated, **nur Item-Owner** | Setzt `approval_status='rejected'`, `approved_by=auth.uid()`, `rejection_reason`. |
| `user_owns_item_in_rental(rental_id)` | helper | Boolean für RLS. SECURITY DEFINER um Recursion zu vermeiden (Migration 100). |
| `user_is_rental_owner(rental_id)` | helper | Boolean: aktueller User ist Solo-Owner oder Mitglied der Gruppe. |
| `user_owns_rental_item(rental_item_id)` | helper | Boolean: aktueller User ist Solo-Owner des darin verknüpften Items. |

## RLS-Setup (Migration 100, nach Recursion-Fix)

- `rentals_select`: `owner_profile_id = auth.uid()` ∨ `is_group_member(owner_group_id)` ∨ `user_owns_item_in_rental(id)` → Owner sieht auch externe Verleihe mit seinen Items.
- `rental_items_select/update`: `user_is_rental_owner(rental_id)` ∨ `user_owns_rental_item(id)` → Verleih-Owner ODER Item-Owner.
- `rental_items_insert/delete`: nur `user_is_rental_owner(rental_id)`.

## Relevante Dateien

- `src/app/(dashboard)/rentals/page.tsx` — Liste + Create-Form + Sektion "Mein Equipment unterwegs" (nur Solo).
- `src/app/(dashboard)/rentals/[id]/page.tsx` — Detail mit Inline-Edit (Quantity-Stepper, X-Delete), Kostenzusammenfassung, Approval-Block, Inline-Share-Button.
- `src/components/rentals/equipment-picker.tsx` — Picker mit Verfügbarkeits-RPC + Owner-Labels + Shares im Gruppen-Modus.
- `src/types/database.ts` — Types `Rental`, `RentalItem`, `RentalStatus`, `RentalItemApprovalStatus`, `InventoryAvailability`, Permission-Keys.

## UI-Konventionen

- **Status-Badge-Farben** (`approvalColors` in `[id]/page.tsx`):
  - `auto` (= versteckt) — grau
  - `pending` — gelb (`warning-light` / `warning`)
  - `approved` — grün (`success-light` / `success`)
  - `rejected` — rot (`destructive-light` / `destructive`)
- **Item-Card-Border**: nur rot bei `conflict || (missingShare && status === 'pending')` — *nicht* nur bei `missingShare`. Siehe `/modi` Visuelle Zustände.
- **Quantity-Stepper**: `[−] N× [+]`, `−` disabled bei `quantity ≤ 1`, `+` disabled bei `quantity >= av.total`.
- **Verfügbarkeits-Anzeige** im Read-View: `Bestand: Y · verfügbar: X` wo X = `max(0, av.available - it.quantity)`. Tooltip erklärt die Rechnung.
- **Kostenposition pro Item**: `agreed_rate ?? proposed_rate` × Tage × Quantity. Bei `agreed_rate IS NULL` Tooltip-Hinweis "(vorgeschlagen)".

## Verrechnungs-Logik (Kostenzusammenfassung)

```ts
for (item of rental_items where approval_status != 'rejected') {
  rate = item.agreed_rate ?? item.proposed_rate ?? 0
  amount = rate × days × quantity
  if (item.needsExternalApproval) {  // = Solo-Item in Gruppen-Verleih
    if (item.agreed_rate != null) ownerPayouts[ownerLabel].agreed += amount
    else                          ownerPayouts[ownerLabel].pending += amount
  }
}
groupRemainder = rental_fee - Σ ownerPayouts.agreed
// USt-Berechnung: brutto = rental_fee, netto = brutto/1.19, ust = brutto - netto
// Kaution = umsatzsteuerfrei, separat
```

## Häufige Fallen

1. **Approval auf Ebene B legt KEINEN `inventory_group_shares`-Eintrag an.** Für die generelle Verfügbarkeit im Gruppen-Picker braucht's einen separaten Share. Siehe `/modi`.
2. **`p_exclude_rental_id` doppelt addiert.** Wenn der RPC den eigenen Rental excludiert, ist `av.available` schon "wenn dieser Verleih nicht wäre". Nicht `+ it.quantity` rechnen.
3. **State leeren bei Workspace-Switch** (siehe `/modi`).
4. **PostgREST `.neq` mit NULL** (für "Mein Equipment unterwegs"): clientseitig filtern.
5. **Verleih-Status auf `active` blockieren** wenn irgend ein `rental_item` noch `pending` ist — sonst kann ausgegeben werden ohne Owner-Freigabe.

## Verwandte Skills

- `/modi` — Solo vs Gruppen-Trennung, Approval-Ebenen, Cross-Modus-Patterns.
- `/ownership` — XOR-Owner-Pattern in Migrationen.
- `/rls` — RLS-Helper, Recursion-Vermeidung.
- `/inventar` — Item-Schema, `cost_per_day`, `loan_approval_mode`, `is_shareable`.

## Migrationen-Überblick

| # | Inhalt |
|---|---|
| 098 | rentals + rental_items + RPC check_inventory_availability + Realtime |
| 099 | loan_approval_mode auf items + approval-Felder auf rental_items + Trigger + RPCs approve/reject |
| 100 | Fix RLS-Recursion mit SECURITY DEFINER helpers |
| 101 | proposed_rate / agreed_rate auf rental_items, Trigger erweitert, approve_rental_item nimmt p_agreed_rate |
