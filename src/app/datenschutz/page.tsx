import type { Metadata } from "next";
import Link from "next/link";

export const metadata: Metadata = {
  title: "Datenschutzerklärung — Project Prepper",
  description: "Informationen zur Verarbeitung personenbezogener Daten nach Art. 13 DSGVO",
};

// HINWEIS: Default-Texte mit eingesetzten Daten wo bekannt. Anbieter-Anschrift
// muss ergaenzt werden. Vor Live-Gang anwaltlich pruefen lassen, insbesondere
// die DPAs mit Supabase, Vercel, Cloudflare und ggf. SMTP-Anbietern.

export default function DatenschutzPage() {
  return (
    <main className="min-h-screen px-4 py-12" style={{ background: "var(--color-background)" }}>
      <div className="max-w-2xl mx-auto">
        <Link href="/" className="text-sm" style={{ color: "var(--color-primary)" }}>
          ← Zurück
        </Link>
        <h1 className="text-3xl font-bold mt-6 mb-8">Datenschutzerklärung</h1>

        <div
          className="mb-8 p-4 rounded-lg text-sm"
          style={{
            background: "var(--color-warning-light)",
            border: "1px dashed var(--color-warning)",
            color: "var(--color-warning)",
          }}
        >
          <strong>Vorlage in Arbeit:</strong> Adresse des Verantwortlichen und Datum müssen vor
          Veröffentlichung gefüllt werden. Anwaltliche Prüfung empfohlen.
        </div>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">1. Verantwortlicher</h2>
          <p className="text-sm">
            Verantwortlich für die Datenverarbeitung auf dieser Plattform ist:
          </p>
          <p>[Betreiber-Name]</p>
          <p>[Straße], [PLZ Ort]</p>
          <p>
            E-Mail:{" "}
            <a href="mailto:post@michaotto.com" style={{ color: "var(--color-primary)" }}>
              post@michaotto.com
            </a>
          </p>
          <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
            Weitere Angaben siehe <Link href="/impressum" style={{ color: "var(--color-primary)" }}>Impressum</Link>.
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">2. Erhobene Daten und Zwecke</h2>
          <h3 className="font-medium mt-3">a) Registrierung & Konto</h3>
          <p className="text-sm">
            Bei der Registrierung werden Name, E-Mail-Adresse und Passwort (gehasht) gespeichert.
            Zweck: Bereitstellung des Nutzerkontos und der zugehörigen Funktionen. Rechtsgrundlage:
            Art. 6 Abs. 1 lit. b DSGVO (Vertragserfüllung).
          </p>

          <h3 className="font-medium mt-3">b) Inventar-, Verleih- und Projektdaten</h3>
          <p className="text-sm">
            Vom Nutzer hinterlegte Inhalte (Inventarlisten, Verleihe, Projekte, Anfragen, Dateien,
            Kalendereinträge) werden gespeichert, um die Funktionen der Plattform anzubieten.
            Rechtsgrundlage: Art. 6 Abs. 1 lit. b DSGVO.
          </p>

          <h3 className="font-medium mt-3">c) Technische Daten</h3>
          <p className="text-sm">
            Beim Aufruf der Plattform werden Server-Logs (IP-Adresse, Zeitstempel, aufgerufene URL,
            User-Agent) bei unserem Hosting-Anbieter erfasst. Zweck: Stabilität und Sicherheit.
            Rechtsgrundlage: Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse).
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">3. Auftragsverarbeiter</h2>
          <p className="text-sm">
            Wir setzen folgende Dienstleister zur Verarbeitung personenbezogener Daten ein
            (Auftragsverarbeitungsverträge gemäß Art. 28 DSGVO liegen vor):
          </p>
          <ul className="list-disc ml-6 text-sm space-y-1">
            <li>
              <strong>Supabase Inc.</strong> (Datenbank, Authentifizierung, Storage) — Server-Standort:
              [Frankfurt/EU bei Pro-Plan, sonst USA].{" "}
              <a
                href="https://supabase.com/privacy"
                target="_blank"
                rel="noopener noreferrer"
                style={{ color: "var(--color-primary)" }}
              >
                Datenschutzhinweise
              </a>
            </li>
            <li>
              <strong>Vercel Inc.</strong> (Hosting, CDN). Server-Standort: weltweit.{" "}
              <a
                href="https://vercel.com/legal/privacy-policy"
                target="_blank"
                rel="noopener noreferrer"
                style={{ color: "var(--color-primary)" }}
              >
                Datenschutzhinweise
              </a>
            </li>
            <li>
              <strong>Cloudflare Inc.</strong> (CalDAV-Worker). [Nur falls verwendet.]
            </li>
            <li>
              <strong>Telegram FZ-LLC</strong> (optionale Bot-Integration für Gruppen-Notifications) —
              nur wenn vom Nutzer aktiviert.
            </li>
            <li>
              <strong>SMTP-Anbieter</strong> (E-Mail-Versand, vom Nutzer pro Organisation
              konfiguriert).
            </li>
          </ul>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">4. Speicherdauer</h2>
          <p className="text-sm">
            Personenbezogene Daten werden gespeichert, solange Ihr Konto aktiv ist. Nach Löschung
            des Kontos werden alle direkt zuordenbaren Daten gelöscht; gesetzliche Aufbewahrungsfristen
            (z.B. Handelsgesetzbuch, Abgabenordnung) bleiben unberührt.
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">5. Ihre Rechte</h2>
          <p className="text-sm">Sie haben gemäß DSGVO folgende Rechte:</p>
          <ul className="list-disc ml-6 text-sm space-y-1">
            <li>Auskunft über gespeicherte Daten (Art. 15 DSGVO)</li>
            <li>Berichtigung unrichtiger Daten (Art. 16 DSGVO)</li>
            <li>Löschung Ihrer Daten (Art. 17 DSGVO) — direkt im Profil-Bereich nutzbar</li>
            <li>Einschränkung der Verarbeitung (Art. 18 DSGVO)</li>
            <li>Datenübertragbarkeit (Art. 20 DSGVO) — Export im Profil-Bereich</li>
            <li>Widerspruch gegen die Verarbeitung (Art. 21 DSGVO)</li>
            <li>Beschwerderecht bei einer Datenschutz-Aufsichtsbehörde (Art. 77 DSGVO)</li>
          </ul>
          <p className="text-sm mt-2">
            Zur Wahrnehmung Ihrer Rechte wenden Sie sich an{" "}
            <a href="mailto:post@michaotto.com" style={{ color: "var(--color-primary)" }}>
              post@michaotto.com
            </a>{" "}
            oder nutzen die Funktionen unter <Link href="/profile" style={{ color: "var(--color-primary)" }}>Profil</Link>.
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">6. Cookies & lokale Speicherung</h2>
          <p className="text-sm">
            Wir setzen technisch notwendige Cookies / Local-Storage-Einträge für Authentifizierung
            und Workspace-Auswahl ein. Diese sind nicht zustimmungspflichtig (§ 25 Abs. 2 TTDSG).
            Tracking-Cookies oder Marketing-Cookies werden nicht eingesetzt.
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">7. Datensicherheit</h2>
          <p className="text-sm">
            Die Datenübertragung erfolgt verschlüsselt (HTTPS/TLS). Datenbankzugriffe sind durch
            Row-Level-Security auf den jeweils berechtigten Nutzer/die jeweilige Gruppe beschränkt.
            Passwörter werden ausschließlich als Hash gespeichert.
          </p>
        </section>

        <p className="text-xs mt-12" style={{ color: "var(--color-muted-foreground)" }}>
          Stand: Mai 2026. Bitte regelmäßig prüfen — diese Erklärung kann sich ändern.
        </p>
      </div>
    </main>
  );
}
