import type { Metadata } from "next";
import Link from "next/link";

export const metadata: Metadata = {
  title: "AGB — Project Prepper",
  description: "Allgemeine Geschäftsbedingungen",
};

// HINWEIS: Default-Texte; Tarif-Details und Widerrufsregelung muessen mit
// dem konkreten Stripe/Paddle-Setup abgeglichen werden. Anwaltliche Pruefung
// vor Live-Gang.

export default function AGBPage() {
  return (
    <main className="min-h-screen px-4 py-12" style={{ background: "var(--color-background)" }}>
      <div className="max-w-2xl mx-auto">
        <Link href="/" className="text-sm" style={{ color: "var(--color-primary)" }}>
          ← Zurück
        </Link>
        <h1 className="text-3xl font-bold mt-6 mb-8">Allgemeine Geschäftsbedingungen</h1>

        <div
          className="mb-8 p-4 rounded-lg text-sm"
          style={{
            background: "var(--color-warning-light)",
            border: "1px dashed var(--color-warning)",
            color: "var(--color-warning)",
          }}
        >
          <strong>Vorlage in Arbeit:</strong> Anbieter-Name, Tarifdetails und Zahlungs­dienstleister
          sind als Default eingesetzt. Vor Veröffentlichung mit echten Daten füllen und
          anwaltlich prüfen lassen.
        </div>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">§ 1 Geltungsbereich</h2>
          <p className="text-sm">
            Diese Allgemeinen Geschäftsbedingungen (AGB) gelten für die Nutzung der Plattform
            &quot;Project Prepper&quot; (im Folgenden &quot;Plattform&quot;), bereitgestellt von
            [Betreiber-Name] (im Folgenden &quot;Anbieter&quot;). Mit der Registrierung erkennen Sie
            diese AGB an.
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">§ 2 Leistungsbeschreibung</h2>
          <p className="text-sm">
            Der Anbieter stellt eine webbasierte Software zur Verwaltung von Inventar, Verleih,
            Anfragen, Projekten, Terminen und gruppenbezogenen Workflows bereit. Der konkrete
            Leistungsumfang ergibt sich aus dem jeweils gewählten Tarif.
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">§ 3 Vertragsschluss</h2>
          <p className="text-sm">
            Der Vertrag kommt durch die Registrierung und Bestätigung des E-Mail-Links zustande.
            Mit dem Upgrade auf einen kostenpflichtigen Tarif kommt zusätzlich ein entgeltlicher
            Vertrag über die jeweils gewählten Leistungen zustande.
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">§ 4 Preise und Zahlungsbedingungen</h2>
          <p className="text-sm">
            Die Preise der kostenpflichtigen Tarife sind auf der Pricing-Seite einsehbar. Die
            Abrechnung erfolgt im Voraus für den jeweiligen Abrechnungszeitraum (monatlich oder
            jährlich). Zahlungsdienstleister: [Stripe Payments Europe Ltd., Dublin, Irland].
          </p>
          <p className="text-sm">
            Alle Preise verstehen sich [inkl./zzgl.] der gesetzlichen Umsatzsteuer.
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">§ 5 Widerrufsrecht für Verbraucher</h2>
          <p className="text-sm">
            Verbraucher (§ 13 BGB) haben das Recht, den Vertrag binnen 14 Tagen ohne Angabe von
            Gründen zu widerrufen. Der Widerruf ist zu richten an: post@michaotto.com.
          </p>
          <p className="text-sm">
            Mit der Bereitstellung kostenpflichtiger Funktionen vor Ablauf der Widerrufsfrist
            erlischt das Widerrufsrecht, sofern der Nutzer ausdrücklich zugestimmt hat.
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">§ 6 Laufzeit und Kündigung</h2>
          <p className="text-sm">
            Monatstarife können jederzeit zum Ende des laufenden Monats gekündigt werden,
            Jahrestarife zum Ende der Laufzeit. Die Kündigung erfolgt über die Profil-Einstellungen
            oder per E-Mail an post@michaotto.com.
          </p>
          <p className="text-sm">
            Bei Kündigung bleibt der Account bis zum Ende des bezahlten Zeitraums aktiv. Danach
            werden Daten gemäß Datenschutzerklärung gelöscht, sofern keine gesetzlichen
            Aufbewahrungsfristen entgegenstehen.
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">§ 7 Pflichten des Nutzers</h2>
          <ul className="list-disc ml-6 text-sm space-y-1">
            <li>Wahre und aktuelle Angaben bei der Registrierung</li>
            <li>Geheimhaltung der Zugangsdaten</li>
            <li>Keine rechtswidrigen, beleidigenden oder schädlichen Inhalte hochladen</li>
            <li>Keine Versuche, die Plattform-Infrastruktur oder Daten anderer Nutzer zu kompromittieren</li>
          </ul>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">§ 8 Verfügbarkeit</h2>
          <p className="text-sm">
            Der Anbieter strebt eine Verfügbarkeit von 99 % im Jahresmittel an. Wartungsarbeiten
            werden nach Möglichkeit angekündigt. Ein Anspruch auf permanente Verfügbarkeit besteht
            nicht.
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">§ 9 Haftung</h2>
          <p className="text-sm">
            Der Anbieter haftet unbeschränkt für Vorsatz und grobe Fahrlässigkeit. Für leichte
            Fahrlässigkeit haftet er nur bei Verletzung wesentlicher Vertragspflichten
            (Kardinalpflichten) und begrenzt auf den typischerweise vorhersehbaren Schaden.
            Die Haftung für Datenverlust ist auf den typischen Wiederherstellungsaufwand bei
            ordnungsgemäßer Datensicherung begrenzt.
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">§ 10 Änderungen der AGB</h2>
          <p className="text-sm">
            Der Anbieter behält sich vor, die AGB zu ändern. Änderungen werden per E-Mail
            mitgeteilt und gelten als angenommen, wenn der Nutzer nicht innerhalb von 6 Wochen
            widerspricht.
          </p>
        </section>

        <section className="space-y-2 mb-8">
          <h2 className="text-lg font-semibold">§ 11 Schlussbestimmungen</h2>
          <p className="text-sm">
            Es gilt deutsches Recht unter Ausschluss des UN-Kaufrechts. Gerichtsstand ist
            [SITZ DES ANBIETERS], soweit der Nutzer Unternehmer ist. Sollten einzelne Bestimmungen
            unwirksam sein, bleibt die Wirksamkeit der übrigen unberührt.
          </p>
        </section>

        <p className="text-xs mt-12" style={{ color: "var(--color-muted-foreground)" }}>
          Stand: Mai 2026
        </p>
      </div>
    </main>
  );
}
