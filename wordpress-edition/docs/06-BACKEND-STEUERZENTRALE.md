# 06 — Backend als Steuerzentrale

> **Status:** Zielbild / Entscheidungsgrundlage (2026-06-14). Noch nicht umgesetzt.
> Querverweise: [[grundkonzept]], `05-MEMBER-PORTAL.md`, `03-GRUPPEN-ARCHITEKTUR.md`, `04-WEBAPP-ABGLEICH.md`.

## 1. Zweck

Das wp-admin-Backend soll **Steuerzentrale der Plattform** sein: es betreut die
**Mechaniken** der Funktionen und die **Betreiber-Objekte**, nicht die
Domänen-Einzelinhalte. Einzelne Inventar-Artikel, Projekte usw. gehören den
**Mitgliedern** und werden im **Frontend-Portal** gepflegt — sie werden im
Backend nicht (mehr) abgebildet.

Damit folgt das Backend endlich konsequent dem Member-Plattform-Modell
([[grundkonzept]]) statt der ursprünglichen Single-Agentur-Herkunft, in der eine
Firma ihr komplettes Equipment im wp-admin verwaltete.

## 2. Die Trennlinie (Litmus-Test)

> **Editiere ich hier einen einzelnen Datensatz, den ein Mitglied besitzt?**
> → gehört ins **Frontend**.
> **Stelle ich hier eine Regel / ein Schema / eine Vorlage / eine Konfiguration ein, die plattformweit gilt — oder verwalte ich ein Betreiber-Objekt (User, Gruppe, Template)?**
> → gehört ins **Backend**.

Daraus ergeben sich drei Klassen:

| Klasse | Beispiele | Ort |
|---|---|---|
| **Domänen-Inhalt** | Gerät xy, Projekt xy, Kalender-Event | Frontend (Mitglied) |
| **Mechanik / Konfiguration** | Kategorien, Nummernschema, Zustände, Sharing-Regeln, Status-Workflow, Sicherheits-Schalter, Föderation, iCal-Feed | Backend |
| **Betreiber-/Verwaltungs-Objekt** | User, Rollen/Rechte, Gruppen, E-Mail-Templates, Feedback | Backend (= Superadmin-Features der Web-App) |

**Wichtig:** „Keine Einzelinhalte" meint die **Domänen-Daten**. Betreiber-Objekte
(User, Gruppen, Templates) sind ihrer Natur nach Einzeldatensätze und bleiben
selbstverständlich im Backend verwaltbar — das *ist* Plattformsteuerung.

## 3. Ziel-Menüstruktur

WordPress-Submenüs sind flach unter einem Top-Level; die Gruppierung ist
logisch (Reihenfolge + ggf. Trenn-Überschriften):

**A — Übersicht & Aufsicht**
- **Dashboard / Plattform** — Aggregat-KPIs + Aktivitäts-Log (read-only Betreiber-Sicht)

**B — Mechaniken (Funktions-Konfiguration, keine Einzelinhalte)**
- **Inventar-Mechanik** — Kategorien · Nummernschema/Präfixe · Zustands-Set · Standard-Sharing-/Freigabe-Regeln · Import/Export-Defaults
- **Projekt-Mechanik** — Status-Workflow · Vorlagen/Defaults
- **Kalender-Mechanik** — iCal-Feed/Token · Anzeigeoptionen
- **Sicherheit** — Frontend-Härtung (schon reine Mechanik)
- **Föderation** — Discovery + Partner (schon reine Mechanik)
- **Einstellungen** — globale Optionen, E-Mail-Versand (schon Mechanik)

**C — Betreiber / Superadmin (neu aus der Web-App)**
- **Benutzer & Rechte** — Mitglieder, Rollen, feingranulare Permissions, Impersonation
- **Gruppen** — Governance-Regeln + Moderation (Gruppe = Betreiber-Objekt)
- **E-Mail-Templates** — alle editierbaren Vorlagen zentral
- **Feedback** — Eingang der Nutzer-Rückmeldungen
- **Monetarisierung** *(optional, später)* — Roadmap/Tier-Übersicht

**D — Ausnahme (bewusst gegen das Prinzip, siehe §6)**
- **Verleih** — bleibt **vollumfänglich** mit Einzeldatensätzen
- **Anfragen** — Status offen (siehe §7)

## 4. Seite-für-Seite-Disposition

| Heutige Seite | Wird zu | Einzelinhalt, der verschwindet |
|---|---|---|
| Dashboard | bleibt Aggregat-Dashboard | — |
| Plattform | bleibt Aufsicht (KPIs + Aktivitäts-Log) | ggf. Tabellen entschlacken |
| Inventar | **Inventar-Mechanik** (Kategorien/Nummern/Zustände/Sharing) | **Artikelliste + CRUD** |
| Kategorien | geht in „Inventar-Mechanik" auf | — (bleibt als Sektion) |
| Projekte | **Projekt-Mechanik** (Workflow/Defaults) | **Projektliste + Detail-Modal** |
| Kalender | **Kalender-Mechanik** (iCal/Feed) | **Monatsraster** |
| Gruppen | bleibt (Governance + Moderation) | — |
| Sicherheit / Föderation / Einstellungen | bleiben (schon Mechanik) | — |
| **Verleih** | **bleibt unverändert** (Ausnahme §6) | — |
| Anfragen | offen (§7) | offen |
| *(neu)* Benutzer & Rechte | Web-App-Superadmin portieren | — |
| *(neu)* E-Mail-Templates | Web-App-Superadmin portieren | — |
| *(neu)* Feedback | Web-App-Superadmin portieren | — |

## 5. Neue Superadmin-Features aus der Web-App

Referenz: `src/components/admin/` der Live-App (Superadmin-Tabs,
`profiles.is_system = true`):

| Web-App-Tab | Bringt ins WP-Backend |
|---|---|
| `users-overview-tab` | **Benutzer & Rechte:** Mitglieder + Gruppen, Rollen, feingranulare Permissions, Impersonation/„als User ansehen" |
| `email-templates-tab` | **E-Mail-Templates:** alle Vorlagen editierbar (heute nur Verleih-Mails in den Einstellungen) |
| `feedback-tab` | **Feedback-Eingang** (Tabelle `app_feedback` der Live-App; in WP neu) |
| `monetisation-tab` | **Monetarisierungs-Roadmap** *(optional, niedrige Prio)* |
| `app_settings.mfa_enabled` | globaler 2FA-Schalter — in WP bereits unter **Sicherheit** abgebildet |

> Hinweis: Es gibt Überschneidungen. Die heutigen **Einstellungen** enthalten
> schon E-Mail-Templates (Verleih-Mails) und den iCal-Feed; beim Umbau
> konsolidieren (Templates → eigene Seite, iCal → Kalender-Mechanik).

## 6. Die Verleih-Ausnahme (offener Widerspruch)

**Entscheidung des Betreibers (2026-06-14):** *Verleih bleibt vollumfänglich
erhalten* — inkl. einzelner Verleih-Datensätze im Backend. Das widerspricht dem
Steuerzentrale-Prinzip (das wäre „Domänen-Inhalt → Frontend").

Begründung / offene Frage zum späteren Nachdenken:
- Verleih ist im Member-Modell eigentlich durch das **frontseitige Leihen
  zwischen Mitgliedern** (Borrowing) abgelöst — die Backend-Rentals sind die
  kommerzielle Agentur-Variante (externe Leiher, Kaution, Tagessätze, USt).
- Mögliche spätere Auflösungen: (a) Rentals als reine Betreiber-Funktion
  rechtfertigen (die Instanz selbst verleiht an Externe), (b) ins Frontend
  überführen, (c) als optionales „Kommerz-Modul" abtrennen.
- **Bis dahin: nicht anfassen.** Dieser Punkt ist bewusst markiert und wird
  separat entschieden.

## 7. Offene Punkte (vor dem Bau zu klären)

1. **Anfragen (Inquiries):** im Member-Modell nichtkommerziell = out-of-scope.
   Entfernen, auf Mechanik reduzieren, oder wie Verleih stehen lassen? *(offen)*
2. **Projekt-Mechanik:** Wie viel Mechanik hat „Projekte" überhaupt (Status-Set
   ist heute hartkodiert)? Evtl. minimal → Seite ggf. zusammenlegen oder als
   reine Aufsicht. *(offen)*
3. **Aufsichts-Lesezugriff:** Soll der Betreiber Domänen-Inhalte wenigstens
   **read-only zur Moderation** sehen können (Missbrauch/Löschen), oder
   strikt gar nicht im Backend? Aktuelle Vorgabe: **gar nicht** („ohne
   Einzelinhalte abzubilden"). Bei Bedarf später separate Moderations-Sicht. *(offen)*
4. **Rollen:** Wer sieht die Steuerzentrale? Heute Mischung aus
   `manage_settings` / `manage_groups` / View-Caps. Mit den neuen
   Betreiber-Seiten sauber auf Betreiber-/Superadmin-Cap bündeln. *(offen)*

## 8. Umbau-Roadmap (gestaffelt, je 1 Lauf)

> Reihenfolge nach Klärung der offenen Punkte (§7). Vorschlag:

1. **Superadmin-Features zuerst** (additiv, nichts wird kaputt):
   1. Benutzer & Rechte (users-overview)
   2. E-Mail-Templates (eigene Seite, aus Einstellungen herauslösen)
   3. Feedback-Eingang (+ `app_feedback`-Tabelle)
2. **Domänen-Seiten auf Mechanik reduzieren** (Vorlage = Inventar):
   1. Inventar → Inventar-Mechanik (Kategorien/Nummern/Zustände/Sharing)
   2. Kalender → Kalender-Mechanik
   3. Projekte → Projekt-Mechanik (nach Klärung §7.2)
3. **Plattform/Dashboard** als Aufsichts-Landing finalisieren.
4. **Verleih / Anfragen** separat entscheiden (§6, §7.1).

Jeder Lauf: Backend + REST + admin.js + Browser-Test + Plugin Check + Build +
Commit + PARITY-Log (wie bei der Backend-Vereinheitlichung v0.63.0).
