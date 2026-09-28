<?php
namespace ProjectPrepper\Frontend;

use ProjectPrepper\Settings;
use ProjectPrepper\Services\RentalDocuments;
use ProjectPrepper\Services\Rentals;

defined( 'ABSPATH' ) || exit;

/**
 * Druck-/PDF-Ansicht eines Angebots oder einer Rechnung (Schema 0.46.0).
 *
 * Eine eigenständige A4-Seite ohne Theme und Portal-Rahmen. „Drucken / als PDF
 * speichern" nutzt den Druckdialog des Browsers — keine PDF-Bibliothek, keine
 * externen Dienste. Zugriff nur für den Anleger des Verleihs (Nonce je Dokument).
 *
 * Rechnungen zeigen zusätzlich die Pflichtangaben nach § 14 UStG, die im
 * Dokument erfasst sind: Steuernummer/USt-IdNr., Leistungszeitraum, Fälligkeit
 * und den Zahlungshinweis. Fehlen sie, druckt die Seite trotzdem — der Editor
 * weist darauf hin.
 */
class RentalDocumentPage {

	public static function init(): void {
		add_action( 'admin_post_pp_rental_doc_print', [ self::class, 'handle' ] );
	}

	/** Adresse der Druck-/PDF-Ansicht (Nonce je Dokument). */
	public static function url( int $doc_id ): string {
		return wp_nonce_url( add_query_arg( [ 'action' => 'pp_rental_doc_print', 'doc' => $doc_id ], admin_url( 'admin-post.php' ) ), 'pp_rental_doc_print_' . $doc_id );
	}

	public static function handle(): void {
		$doc_id = isset( $_GET['doc'] ) ? (int) $_GET['doc'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce direkt darunter.
		check_admin_referer( 'pp_rental_doc_print_' . $doc_id );
		$doc    = RentalDocuments::get( $doc_id );
		$rental = $doc ? Rentals::get( (int) $doc->rental_id ) : null;
		if ( ! Settings::feature_on( 'lending' ) || ! $doc || ! RentalDocuments::can_manage( get_current_user_id(), $rental ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'project-prepper' ), '', [ 'response' => 403 ] );
		}
		nocache_headers();
		self::render( $doc, $rental );
		exit;
	}

	private static function render( object $doc, object $rental ): void {
		$invoice = 'invoice' === $doc->doc_type;
		$tot     = RentalDocuments::totals( $doc );
		$money   = static fn( $v ) => number_format_i18n( (float) $v, 2 ) . ' €';
		$num     = static fn( $v ) => rtrim( rtrim( number_format_i18n( (float) $v, 2 ), '0' ), ',.' );
		$day     = static fn( $v ) => mysql2date( 'd.m.Y', (string) $v );
		$event   = trim( (string) ( $rental->event_name ?? '' ) );
		$rate    = number_format_i18n( (float) $tot['vat_rate'], 0 );
		$from    = ! empty( $doc->service_from ) ? $doc->service_from : $rental->date_from;
		$to      = ! empty( $doc->service_to ) ? $doc->service_to : $rental->date_to;
		$meta    = [];
		$meta[ $invoice ? __( 'Invoice number', 'project-prepper' ) : __( 'Offer number', 'project-prepper' ) ] = (string) $doc->doc_number;
		$meta[ $invoice ? __( 'Invoice date', 'project-prepper' ) : __( 'Date', 'project-prepper' ) ]            = $day( $doc->doc_date );
		$meta[ $invoice ? __( 'Service period', 'project-prepper' ) : __( 'Rental period', 'project-prepper' ) ] = $day( $from ) . ' – ' . $day( $to );
		if ( '' !== $event ) {
			$meta[ __( 'Event', 'project-prepper' ) ] = $event;
		}
		if ( ! $invoice && ! empty( $doc->valid_until ) ) {
			$meta[ __( 'Valid until', 'project-prepper' ) ] = $day( $doc->valid_until );
		}
		if ( $invoice && ! empty( $doc->due_date ) ) {
			$meta[ __( 'Due date', 'project-prepper' ) ] = $day( $doc->due_date );
		}
		if ( '' !== trim( (string) $doc->tax_id ) ) {
			$meta[ __( 'Tax number / VAT ID', 'project-prepper' ) ] = (string) $doc->tax_id;
		}
		$title = '' !== trim( (string) $doc->subject ) ? (string) $doc->subject : RentalDocuments::type_label( (string) $doc->doc_type );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $doc->doc_number . ' — ' . $title ); ?></title>
<style>
	@page { size: A4; margin: 18mm 18mm 20mm; }
	* { box-sizing: border-box; }
	html { background: #e5e7eb; }
	body { margin: 0; font: 10.5pt/1.45 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: #111827; }
	.bar { position: sticky; top: 0; display: flex; gap: 8px; justify-content: center; padding: 10px; background: #1e1b4b; }
	.bar button { font: inherit; font-weight: 600; padding: 8px 16px; border-radius: 8px; border: 0; background: #6366f1; color: #fff; cursor: pointer; }
	.bar button.ghost { background: transparent; border: 1px solid #c7d2fe; color: #e0e7ff; }
	.page { width: 210mm; min-height: 297mm; margin: 16px auto; padding: 18mm; background: #fff; box-shadow: 0 2px 12px rgb(0 0 0 / .12); }
	.head { display: flex; justify-content: space-between; gap: 12mm; margin-bottom: 10mm; }
	.from { text-align: right; white-space: pre-line; font-size: 9.5pt; color: #374151; margin-left: auto; }
	.to { white-space: pre-line; min-height: 30mm; }
	.meta { border-collapse: collapse; font-size: 9.5pt; margin: 0 0 8mm auto; }
	.meta td { padding: 1px 0 1px 8mm; vertical-align: top; }
	.meta td:first-child { color: #6b7280; padding-left: 0; }
	h1 { font-size: 15pt; margin: 0 0 5mm; }
	.text { white-space: pre-line; margin: 0 0 5mm; }
	table.lines { width: 100%; border-collapse: collapse; margin: 3mm 0 4mm; }
	table.lines th { text-align: left; font-size: 9pt; color: #6b7280; font-weight: 600; border-bottom: 1.5px solid #111827; padding: 4px 6px; }
	table.lines td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
	table.lines .n { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
	table.lines .desc { white-space: pre-line; }
	table.totals { margin-left: auto; border-collapse: collapse; min-width: 70mm; font-variant-numeric: tabular-nums; }
	table.totals td { padding: 3px 6px; }
	table.totals td:last-child { text-align: right; white-space: nowrap; }
	table.totals tr.sum td { border-top: 1.5px solid #111827; font-weight: 700; font-size: 11.5pt; }
	table.totals tr.muted td { color: #6b7280; font-size: 9.5pt; }
	.note { font-size: 9pt; color: #374151; margin-top: 3mm; }
	.pay { margin-top: 8mm; padding-top: 3mm; border-top: 1px solid #e5e7eb; font-size: 9.5pt; white-space: pre-line; }
	.pay strong { display: block; margin-bottom: 1mm; }
	@media print {
		html { background: none; }
		.bar { display: none; }
		.page { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
	}
	@media (max-width: 820px) {
		.page { width: auto; min-height: 0; margin: 0; padding: 16px; }
		.head { flex-direction: column-reverse; }
		.from { text-align: left; margin-left: 0; }
		.meta { margin-left: 0; }
		table.lines { font-size: 8.5pt; }
		table.lines th, table.lines td { padding: 4px 3px; }
		table.lines th { white-space: normal; }
		table.lines .pos { display: none; }
		table.lines .desc { overflow-wrap: anywhere; }
	}
	@media (max-width: 400px) {
		.page { padding: 10px; }
		table.lines { font-size: 7.5pt; }
		table.lines th, table.lines td { padding: 3px 2px; }
	}
</style>
</head>
<body>
	<div class="bar">
		<button type="button" onclick="window.print()"><?php esc_html_e( 'Print / save as PDF', 'project-prepper' ); ?></button>
		<button type="button" class="ghost" onclick="window.close()"><?php esc_html_e( 'Close', 'project-prepper' ); ?></button>
	</div>
	<main class="page">
		<div class="head">
			<div class="to"><?php echo esc_html( (string) $doc->recipient ); ?></div>
			<div class="from"><?php echo esc_html( (string) $doc->issuer ); ?></div>
		</div>
		<table class="meta">
			<?php foreach ( $meta as $label => $value ) : ?>
				<tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( $value ); ?></td></tr>
			<?php endforeach; ?>
		</table>

		<h1><?php echo esc_html( $title ); ?></h1>
		<?php if ( '' !== trim( (string) $doc->intro ) ) : ?>
			<p class="text"><?php echo esc_html( (string) $doc->intro ); ?></p>
		<?php endif; ?>

		<table class="lines">
			<thead>
				<tr>
					<th class="n pos">#</th>
					<th><?php esc_html_e( 'Description', 'project-prepper' ); ?></th>
					<th class="n"><?php esc_html_e( 'Qty', 'project-prepper' ); ?></th>
					<th class="n"><?php esc_html_e( 'Days', 'project-prepper' ); ?></th>
					<th class="n"><?php esc_html_e( 'Price per day', 'project-prepper' ); ?></th>
					<th class="n"><?php esc_html_e( 'Total', 'project-prepper' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tot['lines'] as $i => $l ) : ?>
					<tr>
						<td class="n pos"><?php echo (int) ( $i + 1 ); ?></td>
						<td class="desc"><?php echo esc_html( (string) $l['desc'] ); ?></td>
						<td class="n"><?php echo esc_html( $num( $l['qty'] ) ); ?></td>
						<td class="n"><?php echo esc_html( $num( $l['days'] ) ); ?></td>
						<td class="n"><?php echo null === $l['rate'] ? '—' : esc_html( $money( $l['rate'] ) ); ?></td>
						<td class="n"><?php echo null === $l['rate'] ? '—' : esc_html( $money( $l['total'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<table class="totals">
			<?php if ( $tot['discount'] > 0 ) : ?>
				<tr><td><?php esc_html_e( 'Items total', 'project-prepper' ); ?></td><td><?php echo esc_html( $money( $tot['sum'] ) ); ?></td></tr>
				<tr><td>
					<?php
					echo esc_html( 'percent' === $doc->discount_type
						/* translators: %s: discount percentage. */
						? sprintf( __( 'Discount %s %%', 'project-prepper' ), $num( $doc->discount_value ) )
						: __( 'Discount', 'project-prepper' ) );
					?>
				</td><td>− <?php echo esc_html( $money( $tot['discount'] ) ); ?></td></tr>
			<?php endif; ?>
			<?php if ( $tot['small_business'] ) : ?>
				<tr class="sum"><td><?php esc_html_e( 'Total', 'project-prepper' ); ?></td><td><?php echo esc_html( $money( $tot['gross'] ) ); ?></td></tr>
			<?php elseif ( 'net' === $doc->price_mode ) : ?>
				<tr><td><?php esc_html_e( 'Net', 'project-prepper' ); ?></td><td><?php echo esc_html( $money( $tot['net'] ) ); ?></td></tr>
				<?php /* translators: %s: VAT rate percent. */ ?>
				<tr><td><?php echo esc_html( sprintf( __( 'plus VAT %s %%', 'project-prepper' ), $rate ) ); ?></td><td><?php echo esc_html( $money( $tot['vat'] ) ); ?></td></tr>
				<tr class="sum"><td><?php esc_html_e( 'Total', 'project-prepper' ); ?></td><td><?php echo esc_html( $money( $tot['gross'] ) ); ?></td></tr>
			<?php else : ?>
				<tr class="sum"><td><?php esc_html_e( 'Total incl. VAT', 'project-prepper' ); ?></td><td><?php echo esc_html( $money( $tot['gross'] ) ); ?></td></tr>
				<?php /* translators: %s: VAT rate percent. */ ?>
				<tr class="muted"><td><?php echo esc_html( sprintf( __( 'included VAT %s %%', 'project-prepper' ), $rate ) ); ?></td><td><?php echo esc_html( $money( $tot['vat'] ) ); ?></td></tr>
				<tr class="muted"><td><?php esc_html_e( 'Net', 'project-prepper' ); ?></td><td><?php echo esc_html( $money( $tot['net'] ) ); ?></td></tr>
			<?php endif; ?>
		</table>
		<?php if ( $tot['small_business'] ) : ?>
			<p class="note"><?php esc_html_e( 'According to § 19 UStG, no VAT is charged.', 'project-prepper' ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== trim( (string) $doc->outro ) ) : ?>
			<p class="text" style="margin-top: 8mm;"><?php echo esc_html( (string) $doc->outro ); ?></p>
		<?php endif; ?>

		<?php if ( $invoice && '' !== trim( (string) $doc->payment_info ) ) : ?>
			<div class="pay"><strong><?php esc_html_e( 'Payment details', 'project-prepper' ); ?></strong><?php echo esc_html( (string) $doc->payment_info ); ?></div>
		<?php endif; ?>
	</main>
</body>
</html>
		<?php
	}
}
