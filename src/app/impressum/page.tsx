import type { Metadata } from "next";
import Link from "next/link";

export const metadata: Metadata = {
  title: "Impressum — Project Prepper",
  description: "Anbieterkennzeichnung nach § 5 TMG",
};

// HINWEIS: Default-Texte mit eingesetzten Daten wo bekannt. Adresse + USt-ID
// muss der Betreiber selbst ergaenzen. Vor Live-Gang anwaltlich pruefen.

export default function ImpressumPage() {
  return (
    <main className="min-h-screen px-4 py-12" style={{ background: "var(--color-background)" }}>
      <div className="max-w-2xl mx-auto">
        <Link href="/" className="text-sm" style={{ color: "var(--color-primary)" }}>
          ← Zurück
        </Link>
        <h1 className="text-3xl font-bold mt-6 mb-8">Impressum</h1>

        <div
          className="mb-8 p-4 rounded-lg text-sm"
          style={{
            background: "var(--color-warning-light)",
            border: "1px dashed var(--color-warning)",
            color: "var(--color-warning)",
          }}
        >
          <strong>Vorlage in Arbeit:</strong> Die mit <code>[ ]</code> markierten Felder müssen vor
          Veröffentlichung mit den echten Anbieter-Daten gefüllt werden. Anwaltliche Prüfung empfohlen.
        </div>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">Anbieter</h2>
          <p>[Betreiber-Name oder Firma]</p>
          <p>[Straße Hausnummer]</p>
          <p>[PLZ Ort]</p>
          <p>Deutschland</p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">Kontakt</h2>
          <p>
            E-Mail:{" "}
            <a href="mailto:post@michaotto.com" style={{ color: "var(--color-primary)" }}>
              post@michaotto.com
            </a>
          </p>
          <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
            Telefon wird ergänzt, sobald Support-Hotline eingerichtet ist.
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">Vertretungsberechtigt</h2>
          <p>[Vor- und Nachname]</p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">Umsatzsteuer</h2>
          <p className="text-sm">
            Aktuell besteht keine Umsatzsteuer-Identifikationsnummer (Kleinunternehmer gemäß § 19 UStG)
            bzw. die Plattform befindet sich noch in der Vor-Markt-Phase ohne Umsatz.
          </p>
          <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
            Sobald umsatzsteuerpflichtig: USt-ID hier eintragen (Format: DE123456789).
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">Redaktionell verantwortlich (§ 18 Abs. 2 MStV)</h2>
          <p>Wie oben (Anbieter-Anschrift).</p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">Streitschlichtung</h2>
          <p className="text-sm">
            Die Europäische Kommission stellt eine Plattform zur Online-Streitbeilegung (OS) bereit:{" "}
            <a
              href="https://ec.europa.eu/consumers/odr/"
              target="_blank"
              rel="noopener noreferrer"
              style={{ color: "var(--color-primary)" }}
            >
              https://ec.europa.eu/consumers/odr/
            </a>
            . Unsere E-Mail-Adresse finden Sie oben im Impressum.
          </p>
          <p className="text-sm">
            Wir sind nicht bereit oder verpflichtet, an Streitbeilegungsverfahren vor einer
            Verbraucherschlichtungsstelle teilzunehmen.
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">Haftungsausschluss</h2>
          <p className="text-sm">
            Trotz sorgfältiger inhaltlicher Kontrolle übernehmen wir keine Haftung für die Inhalte
            externer Links. Für den Inhalt der verlinkten Seiten sind ausschließlich deren Betreiber
            verantwortlich.
          </p>
        </section>

        <p className="text-xs mt-12" style={{ color: "var(--color-muted-foreground)" }}>
          Stand: Mai 2026
        </p>
      </div>
    </main>
  );
}
