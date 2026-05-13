# RLS-Policies & Helper

Row-Level-Security in Supabase: wann reicht eine einfache `USING`-Klausel, wann braucht's einen `SECURITY DEFINER`-Helper, wie vermeidest du Endlosrekursion.

## Anweisung

Aufgabe: $ARGUMENTS

## Leitprinzip

- **Frontend traut dem User nichts** — RLS ist die Wahrheit, nicht der TS-Filter im React-Code.
- **JS-Queries filtern zusätzlich** (für Performance und State-Konsistenz), aber RLS muss auch ohne diese Filter korrekt sein.
- **Keine `using (true)`-Policies in Production-Tabellen** — die existierenden sind Legacy und werden schrittweise abgelöst.

## Bestehende Helper-Funktionen

Alle `SECURITY DEFINER STABLE`, in `public`-Schema. Nutzbar in Policies *und* Anwendungs-RPCs.

| Funktion | Returns | Beschreibung |
|---|---|---|
| `is_group_member(group_id uuid)` | bool | User ist aktives Mitglied der Gruppe ODER Superadmin (`profiles.is_system = true`). Migration 069. |
| `is_group_founder(group_id uuid)` | bool | User ist Founder der Gruppe. |
| `is_org_admin(org_id uuid)` | bool | User ist Admin (Rolle) der Org ODER Superadmin. |
| `is_org_member(org_id uuid)` | bool | User ist Mitglied der Org ODER Superadmin. |
| `is_admin()` | bool | Backward-Compat: Admin-Rolle ODER `is_system`. Vermeide in neuen Policies. |
| `user_owns_item_in_rental(rental_id uuid)` | bool | Aktueller User besitzt ein `inventory_item` in dem rental. Migration 100. |
| `user_owns_rental_item(rental_item_id uuid)` | bool | Aktueller User besitzt das Item in dieser Zeile. Migration 100. |
| `user_is_rental_owner(rental_id uuid)` | bool | Solo-Owner ODER Mitglied der Gruppe. Migration 100. |

Wenn die Liste hier nicht stimmt: `grep -rn "CREATE OR REPLACE FUNCTION" supabase/migrations | grep -i "is_\|user_"`.

## Pattern: User-First mit Group-Fallback

Für Tabellen mit XOR-Owner (`owner_profile_id` ↔ `owner_group_id` — siehe `/ownership`):

```sql
CREATE POLICY tablename_select ON public.tablename
  FOR SELECT TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND public.is_group_member(owner_group_id))
  );

CREATE POLICY tablename_insert ON public.tablename
  FOR INSERT TO authenticated WITH CHECK (
    (owner_profile_id = auth.uid() AND owner_group_id IS NULL)
    OR (owner_group_id IS NOT NULL AND public.is_group_member(owner_group_id) AND owner_profile_id IS NULL)
  );

CREATE POLICY tablename_update ON public.tablename
  FOR UPDATE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND public.is_group_member(owner_group_id))
  );

CREATE POLICY tablename_delete ON public.tablename
  FOR DELETE TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND public.is_group_member(owner_group_id))
  );
```

`is_group_member` enthält bereits den Superadmin-Check (`is_system`). Daher kein separater `OR EXISTS (SELECT … is_system)` nötig.

## Pattern: Parent-Child (Header-Lines)

Lines wie `rental_items` / `inquiry_invitations` brauchen Zugriff via Parent. **Falsch** wäre, RLS direkt auf der Lines-Tabelle gegen eine andere Lines-Tabelle prüfen zu lassen — das führt zu Recursion (s.u.).

```sql
CREATE POLICY lines_select ON public.lines
  FOR SELECT TO authenticated USING (
    EXISTS (
      SELECT 1 FROM public.headers h
      WHERE h.id = lines.header_id
        AND (h.owner_profile_id = auth.uid()
             OR (h.owner_group_id IS NOT NULL AND public.is_group_member(h.owner_group_id)))
    )
  );
```

Funktioniert solange die Parent-RLS nicht ihrerseits auf Lines verweist.

## Falle: Cross-Table-Rekursion

**Beobachtet in Migration 099/100:** wenn Tabelle A's Policy auf Tabelle B verweist (per `EXISTS`), und B's Policy verweist zurück auf A → Postgres meldet `infinite recursion detected in policy for relation`.

**Konkret damals:**
- `rentals_select`: `EXISTS (SELECT 1 FROM rental_items ri JOIN inventory_items i ON ... WHERE ri.rental_id = rentals.id AND i.owner_profile_id = auth.uid())`
- `rental_items_select`: `EXISTS (SELECT 1 FROM rentals r WHERE r.id = rental_id AND r.owner_profile_id = auth.uid())`

→ A liest B, B liest A, beide checken Policies → Endlosschleife.

**Lösung (Migration 100):** `SECURITY DEFINER`-Helper, der die RLS umgeht weil er als Funktions-Owner läuft:

```sql
CREATE OR REPLACE FUNCTION public.user_owns_item_in_rental(p_rental_id uuid)
RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
  SELECT EXISTS (
    SELECT 1 FROM public.rental_items ri
    JOIN public.inventory_items i ON i.id = ri.inventory_item_id
    WHERE ri.rental_id = p_rental_id
      AND i.owner_profile_id = auth.uid()
  );
$$;
GRANT EXECUTE ON FUNCTION public.user_owns_item_in_rental(uuid) TO authenticated;
```

Dann in der Policy:
```sql
CREATE POLICY rentals_select ON public.rentals
  FOR SELECT TO authenticated USING (
    owner_profile_id = auth.uid()
    OR (owner_group_id IS NOT NULL AND public.is_group_member(owner_group_id))
    OR public.user_owns_item_in_rental(id)
  );
```

Der Helper liest `rental_items` *außerhalb* der RLS-Kette → keine Recursion mehr.

## Pattern: Service-Role in Edge Functions

Edge Functions, die als Service-Role laufen, **umgehen RLS komplett**. Das heißt:
- `auth.uid()` ist NULL → eigene Checks gehen ins Leere.
- Beim Insert nicht auf RLS-`WITH CHECK` verlassen; explizite Validierung im Funktions-Code.

Wenn die Edge Function eine bestimmte User-Identität braucht (z.B. `created_by`), den User-Token mitschicken und in der Function decoden, oder die ID explizit als Parameter durchreichen.

## Auth-User vs Profile-ID

`auth.uid()` returnt die ID des angemeldeten Users — das matched immer `profiles.id` (1:1-Beziehung). Daher in Policies einfach:
- `owner_profile_id = auth.uid()` (User-Eigentum)
- `created_by = auth.uid()` (Erstellung-Tracking)
- `profile_id = auth.uid()` (Membership-Check via Subquery)

**Niemals** `auth.uid()` in einer Application-Query verwenden — das ist serverseitig. Im Frontend stattdessen `(await supabase.auth.getUser()).data.user.id`.

## Tipps fürs Debuggen

1. **Postgres-Logs lesen**: bei Recursion meldet Postgres exakt die Policy. Über Dashboard → Logs → Postgres.
2. **Als anderer User testen**: mit `SET LOCAL role authenticated` und `SET LOCAL request.jwt.claims TO '{"sub":"<uuid>","role":"authenticated"}'` in einer SQL-Query simulieren. Geht über das Supabase Dashboard SQL Editor.
   ```sql
   SET LOCAL role authenticated;
   SET LOCAL request.jwt.claims TO '{"sub":"4a94b14d-...","role":"authenticated"}';
   SELECT id FROM public.rentals WHERE owner_group_id = '...';
   ```
3. **Policies inspecten:**
   ```sql
   SELECT polname, pg_get_expr(polqual, polrelid) AS using_expr
   FROM pg_policy
   WHERE polrelid = 'public.rentals'::regclass;
   ```
4. **Realtime + RLS**: Realtime respektiert RLS. Wenn ein Realtime-Event nicht ankommt obwohl die Datenbank-Änderung passiert ist, ist es meistens RLS.

## Realtime-Publication

Neue Tabellen müssen explizit zur Publication hinzugefügt werden, sonst kommen keine Events:

```sql
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_publication_tables
    WHERE pubname = 'supabase_realtime' AND tablename = 'mytable'
  ) THEN
    ALTER PUBLICATION supabase_realtime ADD TABLE public.mytable;
  END IF;
END $$;
```

Siehe Migration 098 als Beispiel.

## Checkliste für neue RLS-Policies

- [ ] `ALTER TABLE … ENABLE ROW LEVEL SECURITY` nicht vergessen
- [ ] SELECT/INSERT/UPDATE/DELETE jeweils explizit definieren — nichts impliziert
- [ ] Bei XOR-Owner-Tabellen das User-First-Pattern (s.o.) nutzen
- [ ] Bei Cross-Table-Checks erst prüfen ob ein bestehender Helper passt; sonst neuen `SECURITY DEFINER`-Helper anlegen statt direktem EXISTS
- [ ] `DROP POLICY IF EXISTS … ON public.tablename;` vor `CREATE POLICY` (idempotent, falls Migration nochmal läuft)
- [ ] Realtime-Publication ergänzen, wenn die Tabelle live im UI angezeigt werden soll
- [ ] Im Dashboard SQL-Editor mit fremder User-ID simulieren bevor du committest

## Migrations-Referenzen

| # | Inhalt |
|---|---|
| 069 | `is_group_member`, `is_group_founder` Helper |
| 086 | User-First-Pattern für Inventar (`owner_profile_id` XOR `owner_group_id`) |
| 100 | `user_owns_*` / `user_is_rental_owner` Helper zur Recursion-Vermeidung |
| 042-050 | Diverse RLS-Fixes nach Org-Migration |

## Verwandte Skills

- `/ownership` — Owner-XOR-Schema, das die meisten Policies referenzieren.
- `/verleih` — konkretes Beispiel für Helper + Parent-Child + Recursion-Fix.
- `/modi` — wie sich Solo- vs Gruppen-Sicht auf Queries auswirkt (nicht auf RLS — die ist gleich).
