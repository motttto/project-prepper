# Organisation verwalten

Änderungen an Org-Einstellungen, Einladungen, Rollen und Berechtigungen.

## Anweisung

Aufgabe: $ARGUMENTS

### Kontext
- **Tabellen:** `organizations`, `org_memberships`, `roles`, `org_invitations`
- **Org-Rollen:** `admin` (alles), `manager` (kein Team-Manage), `member` (eingeschränkt)
- **Permissions:** JSONB auf `org_memberships` — 13 feingranulare Checkboxen
- **Permission-Keys:** `projects_view/edit`, `inventory_view/edit`, `excel_export/import`, `costs_view/edit`, `team_view/manage`, `inquiries_view/edit/create`
- **Einladungen:** `org_invitations` — E-Mail + Rolle, Auto-Join bei Registrierung (Trigger `handle_org_invitation_auto_join`)
- **Multi-Tenant:** Alles org-scoped via `org_id`, Cookie `pp_org_id` für aktive Org
- **Org-Switcher:** `src/contexts/org-context.tsx`

### Relevante Dateien
- `src/app/(dashboard)/org/page.tsx` — Org-Einstellungen
- `src/app/(dashboard)/team/page.tsx` — Team + Einladungen + Permissions
- `src/contexts/org-context.tsx` — Org-Context (switchOrg, Cookie)
- `src/hooks/use-current-user.ts` — Rolle + Permissions auflösen
- `src/types/database.ts` — `Organization`, `OrgMembership`, `OrgInvitation`, `UserPermissions`, `permissionGroups`

### Permission-System
```typescript
// Prüfung: hasPermission(currentUser, "costs_view")
// Admin hat immer alle Rechte
// null permissions → Rollen-Default
// Sidebar filtert Nav-Items nach modulePermissionMap
```

### Migrations
- `019_organizations.sql` — Multi-Tenant Setup
- `020_cross_org_collaboration.sql` — Cross-Org Freelancer
- `030_org_invitations_and_testusers.sql` — Org-Einladungen + Auto-Join
- `031_testuser_function.sql` — `create_test_user()` RPC
- `032_delete_user_function.sql` — `remove_org_member()` RPC
- `033_user_permissions.sql` — Permissions JSONB

### Häufige Aufgaben
- Neue Permission hinzufügen → `permissionGroups` + `defaultPermissionsByRole` in database.ts
- Rolle anpassen → Default-Permissions in `defaultPermissionsByRole` ändern
- Org-Einstellung hinzufügen → `organizations` Tabelle erweitern + org/page.tsx
- Cross-Org Feature → `project_orgs` Tabelle nutzen
