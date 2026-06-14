# Zweite wp-env-Instanz (Föderations-Tests)

Eine zweite, eigenständige WordPress-Instanz auf **Port 8890**, die dasselbe
Plugin + Theme mountet wie die Hauptinstanz (Port 8888). Damit lässt sich das
**föderierte Leihen** zwischen zwei echten Instanzen testen (Slice 4).

## Starten / Stoppen

```bash
cd wordpress-edition/second-instance
npx @wordpress/env start     # Instanz B auf http://localhost:8890
npx @wordpress/env stop
```

Die Hauptinstanz (A) läuft unverändert in `plugin/project-prepper/` auf 8888.

## Cross-Instance-HTTP — der Trick

Aus einem wp-env-Container ist der **Host** über `host.docker.internal`
erreichbar (verifiziert: `host.docker.internal:8888` → 200), `localhost`
dagegen **nicht** (das ist der Container selbst). Damit Instanz A die
Instanz B erreicht, trägt man B daher als Partner mit
`http://host.docker.internal:8890` ein — nicht `http://localhost:8890`.

## Beispiel-Setup für einen Leih-Test (A = Anfrager, B = Anbieter)

- **B (8890):** Föderation an, „Föderiertes Leihen" an, Partner-Liste enthält
  die **origin von A** (`http://localhost:8888`, A's home_url), ein paar
  nutzbare Artikel.
- **A (8888):** Föderation an, Partner-Liste enthält
  `http://host.docker.internal:8890`.
- Im Netzwerk-Tab von A erscheint B's Katalog → „Anfragen" → B erhält die
  Anfrage → Eigentümer/Betreiber genehmigt → A pollt den Status (Token).

> `origin` (was A mitschickt) = A's `home_url()` = `http://localhost:8888`.
> B prüft, ob diese URL in **B's** Partner-Liste steht (Trust-Gate). B verbindet
> sich nie zu A zurück (Polling-Modell), daher ist der String-Abgleich
> ausreichend.

Dieser Ordner enthält nur `.wp-env.json` + diese README; keine Laufzeitdaten.
