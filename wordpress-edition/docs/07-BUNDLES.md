# 07 — Konzept: Gebündelte Artikel (Sets)

> Stand: 2026-08-27 · Status: **UMGESETZT wie beschrieben** (Commit `612eb46`, alle drei Entscheidungen mit „ja" beantwortet) · v0.130.0, Schema 0.38.0 → 0.39.0
> **Phase 2 ebenfalls umgesetzt** (Schema 0.39.0 → 0.40.0): Set-Expansion jetzt auch im externen Verleih und in den Kollektiv-Leihanfragen — siehe § 6.
> Anlass: Mitglieder-Feedback (Jan, 24.08.): „gebündelte Artikel (Lichterkette hat 10m Glieder steckbar und Einspeiser) — Möglichkeit, Teile zusammenzustückeln eines gebündelten Artikels"

## 1. Ausgangspunkt

Manche Technik besteht aus kombinierbaren Teilen, die zusammen ein Ganzes ergeben:

- **Lichterkette:** 10-m-Glieder (steckbar) + Einspeiser → „eine Lichterkette" sind z. B. 3 Glieder + 1 Einspeiser.
- Weitere typische Fälle: PA-Set (2 Tops + 2 Stative + Mischpult), Funkstrecken-Rack, Kabelkoffer.

Heute muss man entweder jedes Teil einzeln buchen (fehleranfällig: Einspeiser vergessen) oder das Ganze als einen Artikel führen (dann stimmen Bestand und Packliste nicht mit der Realität überein).

## 2. Grundidee: Set = Artikel mit Stückliste, Buchung = Buchungs-Makro

Ein **Set** ist ein normaler Inventar-Artikel, der zusätzlich eine **Stückliste** trägt („besteht aus: 3× 10-m-Glied, 1× Einspeiser"). Die Teile sind ganz normale eigene Artikel mit eigenem Bestand.

Der entscheidende Architektur-Kniff: **Beim Buchen eines Sets werden serverseitig die Teil-Zeilen angelegt** (3× Glied, 1× Einspeiser als `pp_project_items`-Zeilen, markiert mit „stammt aus Set X"). Das Set selbst wird nie als Zeile gebucht.

Warum das die richtige Lösung ist:

- **Verfügbarkeit bleibt exakt und unverändert.** `Availability::available_quantity()` zählt weiter nur echte Artikel-Zeilen — kein Umbau der Kern-Logik, keine Doppelzählung. Wer 2 Glieder einzeln bucht, reduziert automatisch die buchbaren Sets.
- **Die Packliste zeigt die Realität.** Gepackt und getestet werden die Teile (3 Glieder, 1 Einspeiser) — genau so erscheinen sie in der Packliste, gruppiert unter dem Set-Namen.
- **Der Freigabe-Workflow funktioniert unverändert.** Freigabepflichtige Teil-Zeilen werden `pending`; dank der Sammel-Mails (v0.128.0) bekommt der Eigentümer **eine** Mail mit allen Positionen des Sets.

## 3. Datenmodell (Schema 0.39.0)

Zwei kleine Änderungen, kein Umbau bestehender Daten:

**Neue Tabelle `pp_item_bundle_parts`** — die Stückliste:

| Spalte | Typ | Bedeutung |
|---|---|---|
| `id` | bigint PK | — |
| `bundle_item_id` | bigint, KEY | der Set-Artikel (`pp_items.id`) |
| `part_item_id` | bigint, KEY | ein Teil (`pp_items.id`) |
| `quantity` | int ≥ 1 | benötigte Stückzahl pro Set |
| `sort_order` | int | Anzeige-Reihenfolge |
| | | UNIQUE (`bundle_item_id`,`part_item_id`) |

**Neue Spalte `bundle_item_id` (nullable) auf `pp_project_items`** — markiert, aus welchem Set eine Buchungszeile stammt. `NULL` = normale Einzel-Buchung. Dient nur der gruppierten Anzeige (Equipment-Tab, Packliste); Verfügbarkeit und Freigaben ignorieren sie.

Ein Artikel **ist** ein Set, sobald er Stücklisten-Zeilen hat (kein extra Flag). Seine eigene `quantity` wird für Sets ignoriert — die verfügbare Set-Anzahl wird berechnet (§ 4).

## 4. Regeln

1. **Nur eigene Artikel als Teile.** Set und alle Teile gehören demselben Eigentümer (`owner_user_id`). Sets über fremde Artikel gäbe es sonst mit unauflösbaren Freigabe-Konflikten.
2. **Kein Set im Set.** Ein Artikel mit Stückliste kann nicht Teil eines anderen Sets sein (eine Ebene, bewusst simpel).
3. **Set-Verfügbarkeit** im Zeitraum = `min` über alle Teile von `floor( frei(Teil) / Bedarf(Teil) )`. Beispiel: 7 Glieder frei, 2 Einspeiser frei, Stückliste 3 + 1 → `min(floor(7/3), floor(2/1))` = **2 Sets frei**.
4. **Buchung:** „2× Set" legt pro Teil eine Zeile mit `Teilbedarf × 2` an (6× Glied, 2× Einspeiser), alle mit `bundle_item_id` = Set. Zeitraum/Notiz wie bei jeder Buchung (vorbelegter Projektzeitraum, v0.128.0). Scheitert ein Teil an der Verfügbarkeit, wird **nichts** aus dem Set gebucht (alles-oder-nichts pro Set, mit klarer Meldung).
5. **Sichtbarkeit im Kollektiv:** Das **Set** wird wie jeder Artikel mit der Gruppe geteilt (inkl. Tagessatz/Bedingungen/Freigabepflicht des Set-Shares). Die Teile müssen dafür **nicht** einzeln geteilt sein — die Teil-Zeilen entstehen serverseitig aus der Stückliste (kontrolliert, kein IDOR: der Nutzer wählt nur das Set). Freigabepflicht der Teil-Zeilen folgt dem Set-Share.
6. **Entfernen/Ändern gebuchter Sets:** Im Equipment-Tab erscheint das Set als Gruppe; „Entfernen" entfernt alle zugehörigen Teil-Zeilen. Mengen-/Zeitraum-Änderung läuft über die Gruppe (Re-Approval-Logik je Teil-Zeile wie bisher).
7. **Integrität:** Teil-Artikel löschen → seine Stücklisten-Zeilen werden mitgelöscht (Set bleibt, ist dann eben kleiner — mit Hinweis im Modal). Set-Artikel löschen → nur die Stückliste verschwindet, Teile bleiben unberührt.

## 5. UI

- **Verwalten-Modal + Anlege-Formular („Mein Inventar"):** neuer Abschnitt **„Set-Inhalt"** unter den Stammdaten — Liste der eigenen Artikel mit Mengenfeld (0 = nicht enthalten), gleiche Bedienung wie die Freigabe-Blöcke. Speichert mit dem einen Formular (Auto-Speichern beim Schließen greift mit, v0.128.0).
- **Inventar-Listen (Solo + Kollektiv):** Set-Artikel bekommen einen Chip **„Set"**; die Verfügbar-Spalte zeigt die **berechnete Set-Anzahl**. Im Modal/Detail steht die Stückliste.
- **Technik-Picker im Projekt:** das Set erscheint als eine Position — „Lichterkette komplett · Set aus 3× 10-m-Glied, 1× Einspeiser · 2 Sets frei". Anhaken + Menge wie gewohnt.
- **Equipment-Tab:** gebuchte Teil-Zeilen werden unter dem Set-Namen gruppiert dargestellt (einklappbar), mit einer gemeinsamen Entfernen-/Ändern-Aktion.
- **Packliste:** zeigt die Teile einzeln (jedes Glied wird real gepackt/getestet), mit Set-Zugehörigkeit als Vermerk.

## 6. Phase 2 — Verleih & Leihanfragen (umgesetzt, Schema 0.40.0)

Die Set-Expansion ist konzeptionell identisch zur Projekt-Buchung und trägt jetzt beide restlichen Wege. Gemeinsame Basis ist `Bundles::expand()` (Stückliste × Anzahl Sets → Positionszeilen).

**Externer Verleih (`pp_rental_items` + Spalte `bundle_item_id`).** Im Verleih-Formular steht das Set als eigene Zeile mit Mengenfeld „Sets"; gespeichert werden Teil-Positionen (`MemberRentals::expand_sets`). Damit greifen der bestehende Verfügbarkeits-Guard und die Abrechnung unverändert auf echte Artikel. Reicht die Set-Verfügbarkeit nicht, bricht der ganze Vorgang mit einer Meldung auf Set-Ebene ab („… nur 2× zusammenstellbar"), es entsteht keine halbe Position. Beim Bearbeiten werden die Teil-Zeilen wieder zu „n× Set" zusammengefasst; die Teile erscheinen dabei NICHT zusätzlich als Einzelzeilen (sonst würde beim Speichern doppelt verliehen).

**Geld:** Ein Set hat im Verleih keinen eigenen Tagessatz — abgerechnet wird über die **Teile** (Σ Teil-Satz × Bedarf), im Formular als „11,00 €/Tag (aus den Teilen)" ausgewiesen. Das ist exakt (keine Rundungsreste durch Verteilen eines Paketpreises auf Positionen) und bleibt konsistent damit, dass die Teile normale Artikel mit eigenem Satz sind. Wer einen Paketpreis will, nutzt wie bisher das Feld **„Leihgebühr (brutto)"** am Verleih — das überschreibt die Positions-Summe ohnehin.

**Kollektiv-Leihanfragen (`pp_borrow_requests` + `quantity`, `bundle_item_id`, `bundle_ref`).** Angefragt wird das Set (mit „Anzahl Sets"), gespeichert wird eine Zeile je Teil; `bundle_ref` (= ID der ersten Zeile) klammert den Vorgang. Folgen:

- **Verfügbarkeit** zählt echte Artikel — `available_units()` summiert jetzt `quantity` statt Zeilen zu zählen (Bestandszeilen haben `quantity` = 1, zählen also unverändert). Frei = min über alle Teile von ⌊frei/Bedarf⌋.
- **Der Eigentümer entscheidet EINMAL über das ganze Set** (alles-oder-nichts): Vor dem Genehmigen müssen alle Teile frei sein, dann werden alle Zeilen gemeinsam gesetzt. Abbrechen und „zurückgegeben" gelten ebenso für den ganzen Vorgang.
- **Eine Mail je Vorgang**, nicht je Teil — Betreff nennt das Set samt Stückliste.
- Im Portal (Meine Leihen / Leih-Anfragen / Historie) erscheint der Vorgang als EIN Eintrag mit Set-Chip und den Teilen als Unterzeile.
- **Set-Share genügt** wie bei der Projekt-Buchung: geteilt sein muss nur das Set, die Teil-Zeilen entstehen serverseitig.

**Föderation bleibt außen vor:** Sets stehen nicht im föderierten Katalog (`Federation::public_inventory` filtert sie), und `FederatedBorrow::create_inbound` weist sie ab — die anfragende Instanz kennt die Teile nicht.

## 6a. Bewusst NICHT umgesetzt

- **„Stückeln" im Set-Dialog** (z. B. „ich will 50 m"): Wer vom Standard-Set abweichen will, bucht die Teile einzeln — die sind ja normale Artikel. Das Set ist die Bequemlichkeit für den Standardfall. (Erweiterbar: mehrere Set-Varianten anlegen — „Lichterkette 30 m", „Lichterkette 50 m" — teilen sich denselben Teile-Pool und konkurrieren automatisch korrekt um die Glieder.)
- **Verschachtelte Sets.**

## 7. Jans Beispiel durchgespielt

Jan legt an: „LK 10-m-Glied" (Menge 10), „LK Einspeiser" (Menge 3), Set **„Lichterkette 30 m"** = 3× Glied + 1× Einspeiser, geteilt mit dem Kollektiv (Freigabe erforderlich ✓).

- Picker zeigt: „Lichterkette 30 m · Set … · 3 Sets frei" (min(⌊10/3⌋, ⌊3/1⌋) = 3).
- portaltest bucht 1 Set fürs Sommerfest → Zeilen „3× Glied, 1× Einspeiser (aus Set Lichterkette 30 m)", beide `pending` → Jan bekommt **eine** Mail, gibt gesammelt frei.
- Danach zeigt der Picker „2 Sets frei"; wer nur 20 m braucht, bucht 2 Glieder + 1 Einspeiser einzeln — die Set-Anzahl sinkt automatisch mit.
- Packliste des Projekts: 3 Glieder + 1 Einspeiser einzeln abhakbar.

## 8. Aufwand & offene Entscheidungen

**Aufwand:** eine volle Session (vergleichbar Packliste v0.119 / Freigabe-Workflow v0.114): Schema-Migration 0.39.0, Service (`Bundles`: CRUD + Set-Verfügbarkeit + Buch-Expansion in `member_book_equipment`), UI an 5 Stellen, i18n (~15 Strings), wp-env-E2E.

**Vom Betreiber zu entscheiden:**

1. ~~**V1-Scope ok?** Nur Projekt-Buchung; Verleih/Leihanfragen als Phase 2.~~ *(mit „ja" entschieden — Phase 2 inzwischen ebenfalls umgesetzt, § 6)*
2. **Alles-oder-nichts pro Set** bei Teil-Engpass? *(Empfehlung: ja — halbe Sets sind der Fehler, den das Feature verhindern soll)*
3. **Set-Share genügt** (Teile müssen nicht einzeln geteilt sein)? *(Empfehlung: ja — einfachste Eigentümer-UX, Freigabepflicht hängt am Set)*
