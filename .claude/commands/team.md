# Team verwalten

Änderungen an Team-Verwaltung, Einladungen, Testuser, Berechtigungen und Impersonation.

## Anweisung

Aufgabe: $ARGUMENTS

### Kontext
- **Mitglieder:** `org_memberships` — Org-scoped, mit `is_active`, `approved_at`, `permissions` (JSONB)
- **Rollen:** `roles` — admin/manager/member pro Org
- **Freigabe-System:** Unanimous Vote via `team_votes` oder Admin-Override
- **Einladungen:** `org_invitations` — E-Mail + Rolle, Auto-Join bei Registrierung
- **Testuser:** `create_test_user()` RPC — erstellt auth.users + profiles + org_membership
- **Entfernen:** `remove_org_member()` RPC — löscht Membership, bei Testusern komplett
- **Impersonation:** `ImpersonateProvider` Context — Admin sieht App als anderer User
- **Permissions:** 13 Checkboxen in 5 Gruppen (Projekte, Inventar, Finanzen, Team, Anfragen)

### Relevante Dateien
- `src/app/(dashboard)/team/page.tsx` — Hauptseite (Mitglieder, Einladungen, Permissions, Testuser)
- `src/contexts/impersonate-context.tsx` — Impersonation Context
- `src/components/layout/impersonate-banner.tsx` — "Du siehst als..." Banner
- `src/hooks/use-current-user.ts` — `hasPermission()` Helper
- `src/types/database.ts` — `permissionGroups`, `defaultPermissionsByRole`, `allPermissionKeys`
- `src/components/layout/sidebar.tsx` — Nav-Filter nach Permissions
- `src/app/(auth)/pending/page.tsx` — Warteseite für unfreigegebene User

### Permission-Keys
```
projects_view, projects_edit
inventory_view, inventory_edit, excel_export, excel_import
costs_view, costs_edit
team_view, team_manage
inquiries_view, inquiries_edit, inquiries_create
```

### Migrations
- `015_team_approval.sql` — team_votes, handle_new_user Trigger
- `030_org_invitations_and_testusers.sql` — Org-Einladungen + Auto-Join
- `031_testuser_function.sql` — create_test_user()
- `032_delete_user_function.sql` — remove_org_member()
- `033_user_permissions.sql` — permissions JSONB

### Häufige Aufgaben
- Neue Permission → `permissionGroups` + `defaultPermissionsByRole` + PermissionKey Type
- Freigabe-Logik ändern → team/page.tsx `checkAndAutoApprove()`
- Testuser-Logik → `create_test_user()` SQL-Funktion
- Impersonation erweitern → impersonate-context.tsx + Sidebar/Seiten anpassen
