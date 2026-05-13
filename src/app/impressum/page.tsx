import type { Metadata } from "next";
import Link from "next/link";

export const metadata: Metadata = {
  title: "Impressum — Project Prepper",
  description: "Anbieterkennzeichnung nach § 5 TMG",
};

// HINWEIS: Vorlage. Bitte alle [PLATZHALTER] durch echte Daten ersetzen
// und anwaltlich prüfen lassen, bevor das Produkt monetarisiert wird.

export default function ImpressumPage() {
  return (
    <main className="min-h-screen px-4 py-12" style={{ background: "var(--color-background)" }}>
      <div className="max-w-2xl mx-auto">
        <Link href="/" className="text-sm" style={{ color: "var(--color-primary)" }}>
          ← Zurück
        </Link>
        <h1 className="text-3xl font-bold mt-6 mb-8">Impressum</h1>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">Anbieter</h2>
          <p>[BETREIBER NAME]</p>
          <p>[STRASSE HAUSNR]</p>
          <p>[PLZ ORT]</p>
          <p>[LAND]</p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">Kontakt</h2>
          <p>
            Telefon: <a href="tel:[TELEFON]" style={{ color: "var(--color-primary)" }}>[TELEFON]</a>
          </p>
          <p>
            E-Mail:{" "}
            <a href="mailto:[KONTAKT-EMAIL]" style={{ color: "var(--color-primary)" }}>
              [KONTAKT-EMAIL]
            </a>
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">Vertretungsberechtigt</h2>
          <p>[NAME DER VERTRETUNGSBERECHTIGTEN PERSON]</p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">Umsatzsteuer-ID</h2>
          <p>
            Umsatzsteuer-Identifikationsnummer gemäß § 27 a Umsatzsteuergesetz:
            <br />
            [USt-ID, z.B. DE123456789]
          </p>
          <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
            Falls nicht umsatzsteuerpflichtig, diesen Abschnitt entfernen.
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">Redaktionell verantwortlich (§ 18 Abs. 2 MStV)</h2>
          <p>[NAME], [STRASSE], [PLZ ORT]</p>
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
          Stand: [DATUM, z.B. Mai 2026]
        </p>
      </div>
    </main>
  );
}
