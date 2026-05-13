# Owner-Modell — Solo XOR Gruppe

Jede besitzbare Ressource in der App gehört entweder einem Solo-User (`owner_profile_id`) ODER einer Gruppe (`owner_group_id`) — exklusiv, niemals beides. Dieser Skill ist die Vorlage für neue Tabellen, die diesem Modell folgen.

## Anweisung

Aufgabe: $ARGUMENTS

## Was unter "Owner-Modell" fällt

Aktuelle Tabellen mit XOR-Owner:
- `inventory_items` (Migration 086)
- `inventory_categories` (Migration 086)
- `inquiries` (Migration 086)
- `projects` (Migration 086)
- `rentals` (Migration 098)

**Faustregel**: wenn eine neue Tabelle eine *primäre Ressource* repräsentiert (User-facing CRUD, eigenständige Detail-Seite), soll sie diesem Modell folgen — sonst Single-Owner (z.B. nur `created_by`).

**Nicht** mit XOR ausstatten:
- Lines / Children einer Header-Tabelle (z.B. `rental_items`, `inquiry_invitations`) — die erben Owner über die Parent-Tabelle.
- Reine Metadaten-Tabellen ohne Workspace-Sicht (z.B. `task_notifications`).
- Cross-Owner-Tabellen wie `inventory_group_shares`, die explizit Solo→Gruppe verbinden.

## Migrations-Boilerplate

Beispiel: neue Tabelle `widgets`, die einem Solo-User ODER einer Gruppe gehört.

```sql
-- Migration NNN: widgets table mit XOR-Owner

CREATE TABLE IF NOT EXISTS public.widgets (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),

  -- Owner XOR
  owner_profile_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE,
  owner_group_id   uuid REFERENCES public.groups(id)   ON DELETE CASCADE,

  -- … fachliche Felder hier …
  name text NOT NULL,
  notes text,

  created_by uuid REFERENCES public.profiles(id) ON DELETE SET NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT widgets_owner_xor CHECK (
    (owner_profile_id IS NOT NULL AND owner_group_id IS NULL) OR
    (owner_profile_id IS NULL AND owner_group_id IS NOT NULL)
  )
);

-- Indizes pro Owner-Typ (Performance + Filter)
CREATE INDEX IF NOT EXISTS idx_widgets_owner_profile
  ON public.widgets(owner_profile_id) WHERE owner_profile_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_widgets_owner_group
  ON public.widgets(owner_group_id) WHERE owner_group_id IS NOT NULL;

-- updated_at-Trigger (Pattern aus Migration 098)
CREATE OR REPLACE FUNCTION public.widgets_set_updated_at()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN NEW.updated_at = now(); RETURN NEW; END;
$$;
DROP TRIGGER IF EXISTS trg_widgets_updated_at ON public.widgets;
CREATE TRIGGER trg_widgets_updated_at
  BEFORE UPDATE ON public.widgets
  FOR EACH ROW EXECUTE FUNCTION public.widgets_set_updated_at();

-- RLS (siehe /rls für Helper-Details)
ALTER TABLE public.widgets ENABLE ROW LEVEL SECURITY;

CREATE POLICY widgets_select ON public.widgets
  FOR SELECT TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND public.is_group_member(owner_group_id))
  );

CREATE POLICY widgets_insert ON public.widgets
  FOR INSERT TO authenticated WITH CHECK (
    (owner_profile_id = auth.uid() AND owner_group_id IS NULL)
    OR (owner_group_id IS NOT NULL AND public.is_group_member(owner_group_id) AND owner_profile_id IS NULL)
  );

CREATE POLICY widgets_update ON public.widgets
  FOR UPDATE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND public.is_group_member(owner_group_id))
  );

CREATE POLICY widgets_delete ON public.widgets
  FOR DELETE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND public.is_group_member(owner_group_id))
  );

-- Realtime
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_publication_tables
    WHERE pubname = 'supabase_realtime' AND tablename = 'widgets'
  ) THEN
    ALTER PUBLICATION supabase_realtime ADD TABLE public.widgets;
  END IF;
END $$;
```

## Eindeutigkeit pro Owner (falls Constraint nötig)

Wenn pro Owner ein Feld unique sein soll (z.B. `inventory_number` pro Owner), zwei partielle UNIQUE-Indizes:

```sql
CREATE UNIQUE INDEX widgets_user_name_unique
  ON public.widgets (owner_profile_id, name)
  WHERE owner_profile_id IS NOT NULL;

CREATE UNIQUE INDEX widgets_group_name_unique
  ON public.widgets (owner_group_id, name)
  WHERE owner_group_id IS NOT NULL;
```

**Nicht** ein einzelner UNIQUE-Index über beide Spalten — der toleriert keine NULLs zuverlässig und kollidiert mit dem XOR-Constraint.

Siehe Migration 086 für das Inventar-Beispiel.

## TypeScript-Type

```ts
export type Widget = {
  id: string;
  /** XOR mit owner_group_id */
  owner_profile_id: string | null;
  /** XOR mit owner_profile_id */
  owner_group_id: string | null;
  name: string;
  notes: string | null;
  created_by: string | null;
  created_at: string;
  updated_at: string;
};
```

Konvention: JSDoc `/** XOR mit owner_group_id */` über jeder Owner-Spalte — macht das Schema-Pattern an der Type-Definition sofort erkennbar.

## Query-Pattern im Frontend

```ts
const { groupId } = useWorkspace();
const currentUser = useCurrentUser();
const ownerId = currentUser?.id ?? null;

const query = groupId
  ? supabase
      .from("widgets")
      .select("*")
      .eq("owner_group_id", groupId)
      .is("owner_profile_id", null)  // doppelte Isolation, siehe /modi
  : supabase
      .from("widgets")
      .select("*")
      .eq("owner_profile_id", ownerId)
      .is("owner_group_id", null);
```

Beim Insert:
```ts
await supabase.from("widgets").insert({
  owner_profile_id: groupId ? null : ownerId,
  owner_group_id:   groupId ?? null,
  name, notes,
  created_by: currentUser?.id,
});
```

**State-Reset bei Workspace-Switch** (siehe `/modi`):
```ts
useEffect(() => {
  setItems([]);
  setLoading(true);
}, [groupId]);
```

## Legacy-Felder

Ältere Tabellen hatten `org_id` (Multi-Tenant-Org-Modell) oder `group_id` (Vor-XOR). Beide sind in `inquiries`, `inventory_items`, etc. noch vorhanden, aber `@deprecated`. Bei neuen Queries **nicht** verwenden:

```ts
// ❌ Legacy
.eq("group_id", groupId)
// ✅ Aktuell
.eq("owner_group_id", groupId)
```

Migration 086 hat die Daten umgezogen; die alten Spalten bleiben für Rollback-Sicherheit.

Mehrere RLS-Policies haben noch Legacy-`group_id`-Branches drin (z.B. `inv_grants_select` in Migration 071) — beim nächsten Anfassen auf `owner_group_id` umstellen, wenn möglich.

## Verschieben / Umziehen zwischen Solo und Gruppe

Direktes `UPDATE` ist okay solange XOR eingehalten wird. Beispiel: ROLAND von Gruppe → Solo:

```sql
UPDATE public.inventory_items
SET owner_profile_id = '<user-id>',
    owner_group_id   = NULL,
    inventory_number = 'VID-002-MOT'  -- Nummer-Schema anpassen
WHERE id = '<item-id>';
```

Vor dem Verschieben prüfen:
- Inventarnummer-Eindeutigkeit (Solo-Suffix `-MOT` vs Group-Suffix `-DKS`)
- Abhängige Tabellen (`rental_items`, `bookings`, `inventory_group_shares`) bleiben über FK intakt — meistens kein zusätzliches Update nötig
- RLS muss den neuen Owner-Branch erlauben (User-First-Pattern macht das automatisch)

Bei UI-getriebener Verschiebung evtl. ein RPC einführen, der auch das Numbering-Schema sauber aktualisiert.

## Cross-Owner: Shares & Grants

Wenn ein Solo-Item für eine Gruppe nutzbar werden soll, gibt's separate Tabellen — *kein* zweiter Owner:

- `inventory_group_shares` — Item × Gruppe + `daily_rate`, `conditions`, `revoked_at`
- `inventory_project_grants` — Item × Projekt + `quantity_allowed`, `daily_rate`-Override

Beide haben `shared_by`/`granted_by` (= Solo-Owner, RLS-relevanter Insert-Check).

Siehe Migration 071. Verleih-Picker im Gruppen-Modus zieht Items zusätzlich aus `inventory_group_shares`, siehe `/verleih`.

## Checkliste: neue XOR-Tabelle anlegen

1. [ ] Migration mit Schema-Block oben kopieren
2. [ ] CHECK-Constraint `_owner_xor` nicht vergessen
3. [ ] Partielle Indizes pro Owner-Typ
4. [ ] RLS aktivieren, 4 Policies (SELECT/INSERT/UPDATE/DELETE) nach Pattern
5. [ ] `updated_at`-Trigger falls UPDATE-Aktion erlaubt ist
6. [ ] Realtime-Publication wenn UI live brauchen wird
7. [ ] TypeScript-Type in `src/types/database.ts` mit JSDoc-XOR-Marker
8. [ ] Frontend-Queries mit doppelter Isolation (`.is(other_owner, null)`)
9. [ ] State-Reset-Effect auf `groupId`
10. [ ] Permissions (Module-Key in `PermissionKey`, `permissionGroups`, `defaultPermissionsByRole`, `modulePermissionMap`)
11. [ ] Sidebar-Nav-Item nur wenn `hasPermission(currentUser, "<module>_view")` reicht

## Verwandte Skills

- `/modi` — Workspace-Filter, Visuelle Zustände, Cross-Modus-Patterns.
- `/rls` — Helper, Recursion-Vermeidung.
- `/migrate` — Migration-Boilerplate (Filename, Sequenz).
- `/db-types` — TypeScript-Types regenerieren.

## Referenz-Migrationen

| # | Inhalt |
|---|---|
| 086 | User-First (`owner_profile_id`/`owner_group_id`) für inventory_items, inquiries, projects, inventory_categories |
| 098 | Rentals mit XOR-Owner (frisches Beispiel) |
| 069 | Groups-Schema, `is_group_member` Helper |
| 070+ | RLS-Umbau auf User-First |
