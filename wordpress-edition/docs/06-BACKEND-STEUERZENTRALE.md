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

## 7. Offene Punkte — beantwortet

> Diese vier Punkte sind durch den Fragebogen vom 2026-06-14 entschieden, siehe
> **§9**. Die Antworten haben zusätzlich drei neue Konzept-Stränge ausgelöst (§10).

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

## 9. Entscheidungen aus dem Fragebogen (2026-06-14)

| Frage | Entscheidung |
|---|---|
| **Anfragen** | Auf **Aggregat** reduzieren (siehe §10.1 — wird eigentlich ein Frontend-Feature) |
| **Projekt-Mechanik** | **Eigene Mechanik-Seite** (+ Monetarisierungs-/Tracking-Bezug, §10.2) |
| **Aufsichts-Lesezugriff** | **Ja — read-only Moderationssicht** auf Domänen-Inhalte (siehe §10.5) |
| **Rollen** | **Auf eine Betreiber-Capability bündeln.** O-Ton: „jede WP-Instanz ist ein eigenes Universum" |
| **Superadmin-Features** | **Benutzer & Rechte · E-Mail-Templates · Monetarisierung.** Feedback **nicht** gewählt → vorerst raus |
| **Kategorien** | Mitglieder legen **eigene** Kategorien an, bekommen aber **Template-Kategorien** vorgeschlagen (§10.3) |
| **Reihenfolge** | **Superadmin-Features zuerst** (additiv) |

### Korrektur am Prinzip (wichtig)
§2/§4 sagten ursprünglich „keine Einzelinhalte im Backend". Der Aufsichts-Punkt
hebt das gezielt auf: Der Betreiber bekommt **read-only Moderationssichten** auf
Domänen-Inhalte (sehen, eingreifen, löschen) — **aber nicht editieren**. Die
Mechanik-Seiten bleiben getrennt davon (Konfiguration, keine Datensätze). Das
Backend hat damit **zwei Linsen**: *Mechanik konfigurieren* + *Inhalte moderieren*.

## 10. Neue Konzept-Stränge (aus den Kommentaren — zu vertiefen)

### 10.1 Anfragen sind ein Member-Frontend-Feature (mit Lifecycle Anfrage→Projekt)
O-Ton: Anfragen sind **externe Bekundungen** an einen Solo-User oder eine Gruppe.
- Der **Solo-User/die Gruppe** pflegt/editiert seine Anfragen vorne (Buchhaltung).
- Ein Solo-User kann eine Anfrage **an eine Gruppe** richten.
- Aus einer Anfrage kann ein **Projekt** generiert werden → die Anfrage **wandelt
  sich in ein Projekt** um.
- Der **Superadmin sieht keine Details**, sondern höchstens die **Anzahl** der
  Anfragen je Solo-User/Gruppe (Aggregat).

→ Das ist deutlich mehr als „Backend reduzieren": Anfragen gehören als
**Mitglieder-Funktion ins Frontend-Portal** (heute existiert nur die alte
Backend-Variante). Eigener Bau-Strang, parallel zu §5 „Member-Portal".

### 10.2 Monetarisierung + Admin-Tracking (neuer Pfeiler)
O-Ton (zu Projekt-Mechanik): Ein **Pro-User** des Templates kann vermutlich
**vermitteln und damit Gewinn erwirtschaften**. Der **Superadmin muss nicht
dieser User sein**. Deshalb **Admin-Tracking über User-Prozesse** ermöglichen.
- Implikation: Plattform-Betreiber (Superadmin) ≠ der verdienende Pro-User.
- Der Betreiber will **Vermittlungs-/Transaktions-Prozesse der User nachvollziehen**
  (Aufsicht / mögliche Abrechnung / Tier-Gating).
- Berührt: Projekt-Mechanik-Seite, die Monetarisierungs-Seite und ggf. ein
  „Pro-User"-Tier-Konzept.
- **Noch undefiniert** und am ehesten ein eigenes Konzept-Dokument wert (Modell:
  Was wird getrackt? Nimmt der Betreiber eine Gebühr? Reine Transparenz?).

### 10.3 Template-Kategorien (Inventar-Mechanik)
Backend-Mechanik = ein Satz **vorgeschlagener Template-Kategorien**, die der
Betreiber pflegt; Mitglieder übernehmen sie oder legen **eigene** an. Saubere
Backend-Funktion für die „Inventar-Mechanik"-Seite.

### 10.4 Rechtliche Grundlage / AGB (Superadmin-Settings)
O-Ton: „wahrscheinlich brauchen wir auch eine rechtliche Grundlage für die User,
eine AGB in den Superadmin-Settings?" — Ja, sinnvoll:
- AGB-Text in den Betreiber-Einstellungen hinterlegbar (+ Versionierung).
- Mitglieder müssen sie akzeptieren (Analogon Live-App: `collaboration_acceptances`).
- Neuer Planpunkt für die Superadmin-Seiten.

### 10.5 Read-only Moderationssichten
Pro Domäne eine **schlanke read-only Liste** im Backend (Inventar/Projekte/
Anfragen-Aggregat), mit der der Betreiber sehen + moderieren (löschen/sperren)
kann — ohne Editierfelder. Getrennt von den Mechanik-Seiten.

## 11. Aktualisierte Roadmap

1. **Superadmin-Features zuerst (additiv):**
   1. **Benutzer & Rechte** (users-overview: Mitglieder, Rollen, Permissions, Impersonation) ← Startpunkt
   2. **E-Mail-Templates** (eigene Seite, aus Einstellungen herausgelöst)
   3. **AGB / Recht** (§10.4) + **Monetarisierung/Tracking** (§10.2, braucht vorher Konzept)
2. **Betreiber-Capability** einführen + alle Steuerzentrale-Seiten darauf bündeln (§9).
3. **Domänen-Seiten** umbauen: Mechanik-Seite **+** read-only Moderationssicht (§10.5)
   — Reihenfolge Inventar (inkl. Template-Kategorien §10.3) → Kalender → Projekte.
4. **Anfragen** ins Frontend heben (§10.1) + Backend-Aggregat.
5. **Verleih** separat entscheiden (§6).

## 12. Distributions- & Erlösmodell (Ebene 1, Entwickler) — ⚠️ anwaltlich zu prüfen

> Betrifft NUR die Entwickler-/Distributor-Ebene (wie *du* das Plugin
> herausgibst), unabhängig von Betreiber-Ökonomie (Ebene 2) und Pro-User-
> Vermittlung (Ebene 3, §10.2). Stand 2026-06-15, Recherche-Synthese.

### Entscheidung / Empfehlung
- **Kern gratis & GPL auf GitHub** (optional zusätzlich wordpress.org) — minimaler
  Aufwand, minimale Haftung, maximale Verbreitung (passt zum Föderations-Fernziel).
- **Den „hohen Wert" auf einen Service legen, nicht auf den Code.** GPL erlaubt
  Verkauf, aber Käufer dürfen den Code legal weitergeben → ein hoher Einmalpreis
  nur auf den Bits ist fragil. Premium-Erlös daher über **Managed Hosting** oder
  ein **Pro-Add-on**, nicht über den Core.
- **Gegen „Einzelabrechnungs-Aufwand": Merchant of Record** (z.B. Lemon Squeezy,
  Gumroad, oder WP-spezifisch Freemius). Die Plattform stellt jede Rechnung,
  erledigt **EU-USt/OSS**, Rückerstattungen, Chargebacks — du bekommst nur die
  Auszahlung. Ein Produkt, ein Preis, keine Einzelrechnung von dir.
- **Spenden/Sponsoring** (GitHub Sponsors / Open Collective) als no-effort-Baseline.

### Optionen im Vergleich

| Modell | Aufwand | Einnahmen | Haftung |
|---|---|---|---|
| Gratis GitHub (GPL) | ~0 | indirekt (Service/Support später) | am niedrigsten |
| Gratis wordpress.org | gering, Community-Support-Erwartung | indirekt + Sichtbarkeit | niedrig |
| Spenden/Sponsoring | ~0 | freiwillig, gering | niedrig (kein Kaufvertrag) |
| Verkauf via Merchant of Record | gering (Plattform rechnet ab) | direkt, hoch möglich | **höher** (kommerzieller Verkauf) |

### Gewährleistung / Haftung (⚠️ KEINE Rechtsberatung — Fachanwalt IT-Recht klären)
- Open-Source-Lizenzen schließen Gewährleistung aus („AS IS"). Das ist die Lizenz-Ebene.
- **Verkauf an EU-Verbraucher** kann **gesetzliche** Gewährleistung (Digital-Content-
  Richtlinie) + Informationspflichten auslösen, die per Lizenz nicht wegdrückbar sind.
  B2B ist flexibler als B2C.
- **EU Cyber Resilience Act**: trifft *kommerziell* in Verkehr gebrachte Software
  (u.a. Pflicht zu Sicherheits-Updates); nicht-kommerzielle OSS weitgehend ausgenommen.
  → Verkaufen erhöht Pflichten, Gratis-OSS minimiert sie.
- DSGVO/Daten liegt beim **Betreiber** (er hostet = Verantwortlicher), nicht beim Entwickler.

### Konsequenz fürs Produkt
- **Update-Auslieferung** wird zur Pflicht-Mechanik: Da der Vertrieb über GitHub läuft
  (nicht wordpress.org), brauchen Instanzen einen **eigenen Auto-Updater**, der neue
  Releases zieht (sonst lädt jeder Betreiber manuell ZIPs hoch — bei Föderation
  unhaltbar). Deckt zugleich die CRA-Sicherheits-Update-Erwartung ab.
  - **Gebaut (v0.64.0):** `includes/Updater.php` hängt sich in den WP-Update-
    Mechanismus, prüft das letzte GitHub-Release (`/releases/latest`, 6 h gecacht)
    und zeigt „Update verfügbar" + Ein-Klick-Update. Bevorzugt das gebaute
    `project-prepper-x.y.z.zip` als Release-Asset (Top-Ordner stimmt); Quell-Tarball
    als Fallback (`upgrader_source_selection` benennt um). Repo per `PP_UPDATE_REPO`/
    Filter überschreibbar; abschaltbar via `PP_DISABLE_UPDATER`.
  - ⚠️ **Plugin Check meldet hierfür `plugin_updater_detected` (ERROR) — by design.**
    wordpress.org verbietet Selbst-Updater. Für den GitHub-Vertrieb ist das korrekt.
    Ein optionaler wordpress.org-Build müsste den Updater **ausschließen**
    (`PP_DISABLE_UPDATER` reicht zur Laufzeit nicht — PCP prüft statisch; Datei weglassen).
