# Realtime & Presence

Supabase Realtime + Presence: wann nutzen, wie Subscriptions korrekt anlegen, wie Re-Subscribe-Schleifen vermeiden.

## Anweisung

Aufgabe: $ARGUMENTS

## Zwei Konzepte, ein System

| | Realtime (postgres_changes) | Presence |
|---|---|---|
| **Was** | DB-Änderungen pushen (INSERT/UPDATE/DELETE) | Online-Status / aktive User pro Channel |
| **Hook** | `useRealtimeTable` | `usePresence` |
| **Tabellen-bezogen** | Ja | Nein (virtuelle Channels) |
| **RLS-Filter** | RLS gilt — User sieht nur Events für Zeilen die er via RLS lesen darf | egal — Presence ist clientseitige State-Synchronisation |
| **Tabelle muss in Publication** | Ja — `ALTER PUBLICATION supabase_realtime ADD TABLE …` | Nein |

## `useRealtimeTable` — DB-Änderungen

Datei: `src/hooks/use-realtime-table.ts`

```ts
useRealtimeTable({
  table: "rentals",
  onDataChange: loadRentals,       // wird bei jedem Event aufgerufen
  filter: { column: "project_id", value: projectId },  // optional, PostgREST-Stil
  orgFilter: orgId,                // optional, Legacy: filtert org_id
  enabled: rentals.length > 0,     // optional, default true
});
```

### Wichtige Design-Entscheidungen

1. **`callbackRef` statt direkter Dep**: Der Callback wird in einem ref gehalten und nur dessen `.current` aufgerufen. So muss `onDataChange` **nicht** in `useEffect`-deps stehen, was sonst zu Re-Subscribe-Loops bei jedem Render führen würde.

2. **Filter geht in den Channel-Namen**: Damit zwei verschiedene Subscriptions auf derselben Tabelle (z.B. `bookings` einmal mit `project_id=X`, einmal mit `org_id=Y`) auf unterschiedlichen Channels landen und nicht überschreiben.

3. **Nur ein Filter pro Channel**: Supabase Realtime erlaubt nur ein `filter=` pro Subscription. Der Hook bevorzugt `filter` über `orgFilter`. Brauchst du UND-verknüpfte Filter: filter so wenig wie möglich serverseitig, mache den Rest im `onDataChange`-Callback (= Re-Fetch mit deinem normalen Query, der hat ja schon den Filter).

4. **`event: "*"`**: Wir hören auf INSERT/UPDATE/DELETE — der Callback re-fetcht ohnehin, also keine Unterscheidung nötig.

### Pattern: Loader + Realtime-Hook zusammen

```tsx
const loadItems = useCallback(async () => {
  if (!ownerId) { setItems([]); return; }
  const { data } = await supabase
    .from("items")
    .select("*")
    .eq("owner_profile_id", ownerId)
    .order("created_at", { ascending: false });
  setItems(data ?? []);
}, [supabase, ownerId]);

useEffect(() => { loadItems(); }, [loadItems]);

// Bei jedem Event in "items" wird loadItems neu aufgerufen,
// das den jeweils aktuellen Filter mitnimmt.
useRealtimeTable({ table: "items", onDataChange: loadItems });
```

**Der Filter lebt im Loader, nicht im Realtime-Channel.** Realtime triggert nur "irgendwas hat sich geändert" — der Loader entscheidet was relevant ist.

## `usePresence` — Online-User pro Channel

Datei: `src/hooks/use-presence.ts`

```ts
const { users } = usePresence({
  channelName: `presence:project:${projectId}`,   // oder presence:group:..., presence:global
  currentUser: { id, name, email },
});
```

- **`channelName`** ist ein freier String. Konvention: `presence:<scope>:<id>` oder `presence:global`.
- **`currentUser.id` ist der Presence-Key** — derselbe User kann mehrfach connecten (z.B. zwei Tabs), wird trotzdem nur einmal angezeigt.
- **`editingSection`** im Type, falls du in der UI zeigen willst "X bearbeitet gerade die Adresse" — muss explizit getrackt werden (siehe Projekt-Detail).

Presence-Channel automatisch removed wenn Component unmounted (cleanup im `useEffect`).

## Tabelle realtime-fähig machen

Neue Tabellen müssen in die Publication aufgenommen werden, sonst gibt's **keine** Events:

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

Beispiel: Migration 098 für `rentals` und `rental_items`.

Wenn du eine Tabelle direkt auf `ALTER PUBLICATION` ergänzt und es schon drinsteht, bekommt der Migration-Push einen Fehler. Daher immer mit dem `DO`-Block oben absichern.

## Häufige Fallen

1. **Re-Subscribe-Loop** durch `onDataChange` in den deps eines manuell aufgebauten Channels. Lösung: `useRealtimeTable` benutzen oder das Callback-Ref-Pattern manuell nachbauen.

2. **Filter im Channel statt im Loader** — funktioniert für einen Filter, scheitert sobald du UND-verknüpfte Bedingungen brauchst. Lass das Filtering im Loader; Realtime ist nur Trigger.

3. **RLS blockiert das Event still**. Wenn die Realtime-Event nicht ankommt obwohl `INSERT` in der DB durch ist: ist der User per RLS überhaupt berechtigt die Zeile zu sehen? Siehe `/rls`.

4. **Tabelle nicht in Publication** — am häufigsten bei neu angelegten Tabellen vergessen.

5. **Mehrere Hooks auf derselben Tabelle** ohne unterschiedlichen Filter erzeugen mehrere Channels mit identischem Namen. Das ist okay (Supabase deduplizt nicht selbst), aber unnötig. Wenn möglich `enabled`-Flag nutzen um inaktive Subscriptions zu deaktivieren.

6. **Presence-Channel mit dynamischem Key, der wechselt** → ständiges Re-Mount. Den Channel-Namen so stabil wie möglich halten.

## Channel-Namen-Konventionen

Damit man im Dashboard Realtime-Aktivität nachvollziehen kann:

- `realtime:<table>` — komplette Tabelle, kein Filter
- `realtime:<table>:<col>=eq.<val>` — gefiltert
- `presence:project:<projectId>` — Projekt-Mitglieder im Detail
- `presence:group:<groupId>` — Gruppen-Mitglieder im Workspace
- `presence:global` — Gesamt-Online-Anzeige (z.B. "X Online" im Header)
- `<feature>-realtime` für gemerged-Channels im Code (z.B. `calendar-realtime` hört auf 4 Tabellen)

## Multi-Tabellen-Channel (Sonderfall)

Manchmal will man **einen** Channel der mehrere Tabellen abonniert, statt N Hooks. Z.B. der Kalender hört auf `calendar_events`, `calendar_groups`, `bookings`, `rentals`, `rental_items`:

```ts
useEffect(() => {
  if (!groupId) return;
  const channel = supabase
    .channel("calendar-realtime")
    .on("postgres_changes", { event: "*", schema: "public", table: "calendar_events" }, () => fetchEvents())
    .on("postgres_changes", { event: "*", schema: "public", table: "calendar_groups", filter: `group_id=eq.${groupId}` }, () => fetchGroups())
    .on("postgres_changes", { event: "*", schema: "public", table: "bookings" }, () => fetchLoans())
    .on("postgres_changes", { event: "*", schema: "public", table: "rentals" }, () => fetchRentals())
    .subscribe();
  return () => { supabase.removeChannel(channel); };
}, [supabase, groupId, fetchEvents, fetchGroups, fetchLoans, fetchRentals]);
```

Vorteil: ein Channel statt fünf. Nachteil: deps werden komplex; bei Callback-Identität-Wechsel re-subscribed der ganze Block. Für stabile Loader (in `useCallback`) ok.

## Beispiel-Stellen im Code

| Datei | Wofür |
|---|---|
| `src/components/layout/sidebar.tsx` | Badges-Counts für Invitations + Approvals |
| `src/app/(dashboard)/rentals/page.tsx` | Liste + "Mein Equipment unterwegs" |
| `src/app/(dashboard)/rentals/[id]/page.tsx` | Detail-Updates |
| `src/app/(dashboard)/calendar/page.tsx` | Multi-Tabellen-Channel |
| `src/components/projects/tab-tasks.tsx` | Tasks-Sub-Subscription mit Filter |
| `src/contexts/org-context.tsx` | `group_memberships` Realtime für Workspace-Switcher |

## Verwandte Skills

- `/rls` — RLS-Filter wirkt auch auf Realtime-Events.
- `/migrate` — Realtime-Publication als Standard-Block in neuen Migrations.
- `/modi` — State-Reset beim Workspace-Switch (`useEffect([] auf groupId)`) verhindert dass Realtime-Events vom alten Workspace nachträglich Items reinrenden.
