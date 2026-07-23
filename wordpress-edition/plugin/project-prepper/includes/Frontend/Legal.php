<?php
namespace ProjectPrepper\Frontend;

use ProjectPrepper\Platform;
use ProjectPrepper\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Erzeugt Impressum und Datenschutzerklärung dynamisch aus den im Backend
 * gepflegten Betreiber-/Rechtsangaben (Platform::imprint()).
 *
 *   [pp_impressum]    → Impressum (§ 5 DDG, § 18 Abs. 2 MStV)
 *   [pp_datenschutz]  → Datenschutzerklärung (Art. 13 DSGVO)
 *
 * Die konkreten Betreiberdaten kommen aus der DB; der rechtliche Vorlagentext ist
 * auf deutsches Recht / nichtkommerziellen Betrieb zugeschnitten und bewusst NICHT
 * übersetzbar (jurisdiktionsspezifisch). KEIN Ersatz für anwaltliche Prüfung.
 */
class Legal {

	public static function init(): void {
		add_shortcode( 'pp_impressum', [ self::class, 'impressum' ] );
		add_shortcode( 'pp_datenschutz', [ self::class, 'datenschutz' ] );
	}

	/**
	 * URLs der Rechtstexte (Impressum, Datenschutz) für die Verlinkung z. B. unter
	 * dem Login. Leerer String, wenn die jeweilige Seite (noch) nicht existiert.
	 *
	 * @return array{impressum:string,datenschutz:string}
	 */
	public static function links(): array {
		return [
			'impressum'   => self::page_url_for( 'pp_impressum', 'impressum' ),
			'datenschutz' => get_privacy_policy_url() ?: self::page_url_for( 'pp_datenschutz', 'datenschutz' ),
		];
	}

	/**
	 * Permalink der veröffentlichten Seite, die den gegebenen Rechts-Shortcode
	 * enthält (slug-unabhängig); Fallback über den üblichen Slug. 1 h gecacht.
	 */
	private static function page_url_for( string $shortcode, string $fallback_slug ): string {
		$cache_key = 'pp_legal_url_' . $shortcode;
		$id        = get_transient( $cache_key );
		if ( false === $id ) {
			global $wpdb;
			$id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1",
				'%[' . $wpdb->esc_like( $shortcode ) . ']%'
			) );
			if ( ! $id ) {
				$page = get_page_by_path( $fallback_slug );
				$id   = $page ? (int) $page->ID : 0;
			}
			set_transient( $cache_key, $id, HOUR_IN_SECONDS );
		}
		return $id ? (string) get_permalink( $id ) : '';
	}

	/** Hinweis, falls noch nichts gepflegt ist — nur für den Betreiber sichtbar. */
	private static function not_configured(): string {
		if ( current_user_can( Capabilities::OPERATE ) ) {
			return '<p><em>' . esc_html__( 'Legal details are not filled in yet — open Manage → Instance → Legal details (imprint/privacy).', 'project-prepper' ) . '</em></p>';
		}
		return '';
	}

	/** Mehrzeilige Adresse (Name/Straße/PLZ Ort/Land), HTML-escaped. */
	private static function address_block(): string {
		$i     = Platform::imprint();
		$lines = [];
		if ( '' !== $i['operator'] ) { $lines[] = '<strong>' . esc_html( $i['operator'] ) . '</strong>'; }
		if ( '' !== $i['street'] )   { $lines[] = esc_html( $i['street'] ); }
		$pc = trim( $i['postal_code'] . ' ' . $i['city'] );
		if ( '' !== $pc )            { $lines[] = esc_html( $pc ); }
		if ( '' !== $i['country'] )  { $lines[] = esc_html( $i['country'] ); }
		return implode( '<br>', $lines );
	}

	/** Einzeilige Adresse, HTML-escaped. */
	private static function inline_address(): string {
		$i     = Platform::imprint();
		$parts = array_filter( [ $i['street'], trim( $i['postal_code'] . ' ' . $i['city'] ), $i['country'] ] );
		return esc_html( implode( ', ', $parts ) );
	}

	public static function impressum(): string {
		if ( ! Platform::imprint_ready() ) {
			return self::not_configured();
		}
		$i = Platform::imprint();
		ob_start();
		?>
		<div class="pp-legal pp-legal--imprint">
			<h2>Angaben gemäß § 5 DDG</h2>
			<p><?php echo wp_kses_post( self::address_block() ); // Teile intern via esc_html, nur <br>/<strong> ?></p>
			<?php if ( '' !== $i['legal_form'] ) : ?>
				<p><?php echo nl2br( esc_html( $i['legal_form'] ) ); ?></p>
			<?php endif; ?>

			<h2>Kontakt</h2>
			<p>
				<?php if ( '' !== $i['email'] ) : ?>E-Mail: <?php echo esc_html( $i['email'] ); ?><?php endif; ?>
				<?php if ( '' !== $i['phone'] ) : ?><br>Telefon: <?php echo esc_html( $i['phone'] ); ?><?php endif; ?>
			</p>

			<h2>Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV</h2>
			<p><?php echo esc_html( $i['operator'] ); ?>, Anschrift wie oben</p>

			<h2>Hinweis zum nichtkommerziellen Betrieb</h2>
			<p>Diese Plattform wird nicht zu kommerziellen Zwecken und ohne Gewinnerzielungsabsicht betrieben. Sie dient ausschließlich dem privaten, nichtkommerziellen Teilen von Ausrüstung innerhalb eines Kollektivs.</p>

			<h2>Haftung für Inhalte</h2>
			<p>Als Diensteanbieter sind wir für eigene Inhalte nach den allgemeinen Gesetzen verantwortlich. Wir sind nicht verpflichtet, übermittelte oder gespeicherte fremde Informationen zu überwachen (§§ 7–10 DDG). Von Mitgliedern eingestellte Inhalte sind fremde Inhalte; bei Bekanntwerden von Rechtsverletzungen entfernen wir diese umgehend.</p>

			<h2>Haftung für Links</h2>
			<p>Unsere Seiten können Links zu externen Websites Dritter enthalten, auf deren Inhalte wir keinen Einfluss haben. Für diese fremden Inhalte ist stets der jeweilige Anbieter verantwortlich.</p>

			<h2>Urheberrecht</h2>
			<p>Die durch den Betreiber erstellten Inhalte unterliegen dem deutschen Urheberrecht. Von Mitgliedern beigetragene Inhalte verbleiben bei den jeweiligen Rechteinhabern.</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function datenschutz(): string {
		if ( ! Platform::imprint_ready() ) {
			return self::not_configured();
		}
		$i       = Platform::imprint();
		// Rohwerte — Escaping erfolgt an der Ausgabestelle (esc_html), damit der
		// EscapeOutput-Sniff greift und keine Doppel-Escapes entstehen.
		$email   = '' !== $i['email'] ? $i['email'] : '—';
		$hosting = '' !== $i['hosting'] ? $i['hosting'] : 'unserem Hosting-Anbieter';
		ob_start();
		?>
		<div class="pp-legal pp-legal--privacy">
			<h2>1. Verantwortlicher</h2>
			<p>Verantwortlich im Sinne der DSGVO ist:<br><strong><?php echo esc_html( $i['operator'] ); ?></strong>, <?php echo wp_kses_post( self::inline_address() ); ?>, E-Mail: <?php echo esc_html( $email ); ?>.</p>

			<h2>2. Hosting</h2>
			<p>Die Plattform wird bei <?php echo esc_html( $hosting ); ?> gehostet. Der Anbieter verarbeitet in unserem Auftrag Daten, die beim Betrieb der Website anfallen (insbesondere Server-Logfiles), auf Grundlage eines Vertrags zur Auftragsverarbeitung (Art. 28 DSGVO). Rechtsgrundlage ist unser berechtigtes Interesse an einem sicheren Betrieb (Art. 6 Abs. 1 lit. f DSGVO).</p>

			<h2>3. Server-Logfiles</h2>
			<p>Beim Aufruf der Seiten werden automatisch Informationen in Server-Logfiles gespeichert (IP-Adresse, Datum/Uhrzeit, abgerufene URL, Referrer, Browser/Betriebssystem). Diese Daten dienen der Sicherheit und Stabilität und werden nach kurzer Frist gelöscht. Rechtsgrundlage: Art. 6 Abs. 1 lit. f DSGVO.</p>

			<h2>4. Cookies / Sitzung</h2>
			<p>Die Plattform setzt technisch notwendige Cookies, um die Anmeldung und Sitzung eingeloggter Mitglieder zu ermöglichen. Diese sind für den Betrieb erforderlich (Art. 6 Abs. 1 lit. f DSGVO bzw. § 25 Abs. 2 TDDDG). Es werden keine Tracking- oder Marketing-Cookies eingesetzt.</p>

			<h2>5. Mitgliedskonto / Registrierung</h2>
			<p>Für die Nutzung des Mitgliederportals legen wir ein Konto an. Verarbeitet werden Anzeigename, E-Mail-Adresse, Passwort (verschlüsselt), Zeitpunkt der letzten Anmeldung sowie optional ein Profilbild. Rechtsgrundlage ist die Erfüllung des Nutzungsverhältnisses (Art. 6 Abs. 1 lit. b DSGVO). Die Daten werden bis zur Löschung des Kontos gespeichert.</p>

			<h2>6. Inventar-, Gruppen- und Verleihdaten</h2>
			<p>Wir verarbeiten die von Mitgliedern eingestellten Inhalte: Inventar/Equipment (Bezeichnung, Beschreibung, Fotos, Zustand), Gruppen-/Kollektiv-Zugehörigkeit und Einladungen, Leih-/Verleihvorgänge (Zeiträume, Status, Nachrichten zwischen Mitgliedern) sowie Projekte, Kalender und Umfragen/Beschlüsse, soweit genutzt. Rechtsgrundlage: Art. 6 Abs. 1 lit. b DSGVO.</p>

			<h2>7. E-Mail-Versand</h2>
			<p>Wir versenden funktionale E-Mails (z. B. Einladungen, Leih-Benachrichtigungen, ggf. Codes zur Zwei-Faktor-Anmeldung). Verarbeitet wird die E-Mail-Adresse des Mitglieds. Rechtsgrundlage: Art. 6 Abs. 1 lit. b und lit. f DSGVO.</p>

			<h2>8. Zwei-Faktor-Authentifizierung</h2>
			<p>Ist die Zwei-Faktor-Anmeldung aktiviert, senden wir einen Einmal-Code an die hinterlegte E-Mail-Adresse. Der Code dient ausschließlich der Anmeldesicherheit und wird zeitnah ungültig.</p>

			<h2>9. Föderation / verbundene Instanzen</h2>
			<p>Ist die Föderation aktiviert, können freigegebene Inventar-Informationen (ohne sensible personenbezogene Daten wie Eigentümer-Klarnamen, Seriennummern oder Kaufpreise) für verbundene Partner-Instanzen abrufbar sein. Eine föderierte Leih-Anfrage übermittelt die dafür notwendigen Kontaktangaben an die Partner-Instanz. Rechtsgrundlage: Art. 6 Abs. 1 lit. b/f DSGVO.</p>

			<h2>10. Profilbilder / Uploads</h2>
			<p>Hochgeladene Bilder (Profil, Equipment) werden in der WordPress-Mediathek auf dem Server der Plattform gespeichert. Ein externer Avatar-Dienst (z. B. Gravatar) wird nicht verwendet, sofern lokale Profilbilder aktiv sind.</p>

			<h2>11. Aktivitätsprotokoll</h2>
			<p>Zur Nachvollziehbarkeit administrativer Vorgänge wird ein internes Aktivitätsprotokoll geführt. Rechtsgrundlage: berechtigtes Interesse an Sicherheit und Aufsicht (Art. 6 Abs. 1 lit. f DSGVO).</p>

			<h2>12. Speicherdauer</h2>
			<p>Personenbezogene Daten werden gelöscht, sobald der Zweck entfällt — spätestens mit Löschung des Mitgliedskontos, soweit keine gesetzlichen Aufbewahrungspflichten entgegenstehen.</p>

			<h2>13. Ihre Rechte</h2>
			<p>Sie haben das Recht auf Auskunft (Art. 15), Berichtigung (Art. 16), Löschung (Art. 17), Einschränkung (Art. 18), Datenübertragbarkeit (Art. 20) und Widerspruch (Art. 21). Die Plattform bietet hierfür teils Selbstbedienungs-Funktionen (Datenexport, Konto-Löschung) im Mitgliederbereich. Zudem besteht ein Beschwerderecht bei einer Aufsichtsbehörde (Art. 77 DSGVO).</p>

			<h2>14. Kontakt in Datenschutzfragen</h2>
			<p>Bei Fragen oder zur Ausübung Ihrer Rechte: <?php echo esc_html( $email ); ?>.</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
