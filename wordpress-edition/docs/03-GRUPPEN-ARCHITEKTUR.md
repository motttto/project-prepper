# 03 — Gruppen-Overlay (WP-Edition)

> Architektur-Entscheidung für das Mehrbenutzer-/Gruppenmodell, auf dem die vier
> blockierten Projekt-Tabs (Mitglieder, Umfragen, Gewinnverteilung, Vereinbarung)
> aufsetzen. Entscheidung des Users 2026-06-13: **echtes Gruppen-Overlay** statt
> Vereinfachung.

## Ausgangslage

Die Live-App ist „User-First + Gruppen-Overlay": jedes Kernobjekt hat
`owner_profile_id` **XOR** `owner_group_id`; Zugriff via Supabase-RLS; Gruppen-
Beitritt über Einladung + einstimmiges Voting. Das ist tief an Supabase-Auth/RLS
gekoppelt.

Die WP-Edition war bisher **Single-Operator**: der Site-Admin (+ Rollen Prepper
Manager/Member) verwaltet alles; alle Objekte sind implizit „der Site" zugeordnet.

## Entscheidung: Gruppen als Overlay über WP-Usern

Kein eigenes Profil-System — **Gruppenmitglieder sind WP-Benutzer der Site**. Eine
Gruppe ist ein Kollektiv von WP-Usern innerhalb der Installation. Ein Projekt
gehört **optional einer Gruppe** (sonst weiterhin „Site-Ebene" wie bisher).

Das ist NICHT das volle App-Modell (keine Cross-Org-Föderation, kein
Profil-XOR-Gruppe auf Inventar), aber ein echtes Mehrbenutzer-Overlay: mehrere
Personen teilen sich ein Projekt mit unterschiedlichen Beteiligungen.

### Bewusste Vereinfachungen ggü. App
- **Solo = Site**, nicht `owner_profile_id`. Inventar/Verleih/Anfragen bleiben
  site-weit (kein Gruppen-Besitz) — nur **Projekte** werden gruppen-besitzbar.
  (Equipment einer Gruppe = späterer Ausbau, falls je nötig.)
- **Mitgliedschaft**: Founder/Member. Beitritt fügt der Founder/Admin direkt hinzu
  (kein Voting in Phase 1 — das Voting kommt mit dem Umfragen-Tab und gilt dann
  projektbezogenen Beschlüssen, nicht dem Gruppen-Beitritt).
- **Zugriff** über PHP-Guards in den Services (kein RLS): „darf User X Projekt Y?"
  = Admin ODER (Projekt hat owner_group_id UND User ist aktives Mitglied dieser
  Gruppe). Zentrale Helper `Groups::user_can_access_project()` /
  `Groups::is_member()`.

## Datenmodell (Phase 1)

```
pp_groups
  id, name, slug (unique), description, created_by (WP user), created_at

pp_group_members
  id, group_id (KEY), user_id (WP user, KEY), member_role ENUM(founder,member),
  joined_at, UNIQUE(group_id,user_id)

pp_projects  (+ neue Spalte)
  owner_group_id bigint unsigned NULL   -- NULL = Site-Ebene (wie bisher)
```

`owner_group_id` defaultet NULL → **bestehendes Single-Site-Verhalten bleibt
unverändert**; die Änderung ist rein additiv.

## Zugriffslogik (Phase 1)

- Site-Admin (`manage_options`/Superadmin-Äquivalent) sieht/bearbeitet alles.
- `pp_projects_view`/`_edit` gelten weiterhin als Grundvoraussetzung.
- Zusätzlich: ein Projekt mit `owner_group_id` ist nur für **aktive Mitglieder
  dieser Gruppe** (oder Admins) sichtbar/bearbeitbar. `Projects::all()` /
  `get()` / REST filtern entsprechend für nicht-Admins.
- Projekte ohne `owner_group_id` verhalten sich wie heute (Cap-gesteuert).

## Phasen-Roadmap

1. **Fundament (dieser Lauf, v0.14.0, Schema 0.10.0):** pp_groups + pp_group_members,
   `owner_group_id` auf pp_projects, Groups/Membership-Service + REST + Zugriffs-
   Guards, Admin-UI „Gruppen" (Liste, anlegen, Mitglieder = WP-User verwalten) +
   Gruppen-Auswahl im Projekt. Bestehendes Verhalten unverändert (Default NULL).
2. **Mitglieder-Tab** (v0.15.0): Projekt-Beteiligte aus der Gruppe; Rolle/Anteil-
   Vorbereitung.
3. **Umfragen/Beschlüsse-Tab** (v0.16.0): Abstimmungen unter Gruppenmitgliedern
   (Ja/Nein/Enthaltung), Mehrheits-/Einstimmigkeits-Auflösung.
4. **Gewinnverteilung-Tab** (v0.17.0): Anteile je Mitglied auf Basis revenue/Kosten.
5. **Vereinbarung-Tab** (v0.18.0): formale Beteiligung + Signatur-Tracking.

## Risiken / offene Punkte

- **wordpress.org-Charakter:** Das Plugin wird vom Single-Operator-Tool zum
  Mehrbenutzer-Tool. Für die Verzeichnis-Tauglichkeit unkritisch (viele Plugins
  sind multi-user), aber die Zugriffs-Guards müssen sauber sein (kein RLS-Netz wie
  in Supabase — jede Query/REST-Route muss selbst prüfen). Sorgfältig testen.
- **Security-Fläche:** Cross-Group-Zugriff, Capability-Eskalation. Jede neue
  REST-Route braucht den Gruppen-Guard, nicht nur die Cap.
- **Reversibilität:** Phase 1 ist additiv (Default NULL). Die 4 Tabs danach
  zementieren das Modell — daher Phase 1 als Review-Punkt.
