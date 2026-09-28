<?php
namespace ProjectPrepper\Frontend;

use ProjectPrepper\Settings;
use ProjectPrepper\Services\RentalOffers;
use ProjectPrepper\Services\Rentals;

defined( 'ABSPATH' ) || exit;

/**
 * Druck-/PDF-Ansicht eines Angebots (Schema 0.46.0).
 *
 * Eine eigenständige A4-Seite ohne Theme und Portal-Rahmen: Aussteller,
 * Empfänger, Angebotsdaten, Positionen, Summen, Texte. „Drucken / als PDF
 * speichern" nutzt den Druckdialog des Browsers — keine PDF-Bibliothek, keine
 * externen Dienste. Zugriff nur für den Anleger des Verleihs (Nonce je Angebot).
 */
class OfferDocument {

	public static function init(): void {
		add_action( 'admin_post_pp_offer_print', [ self::class, 'handle' ] );
	}

	public static function handle(): void {
		$offer_id = isset( $_GET['offer'] ) ? (int) $_GET['offer'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce direkt darunter.
		check_admin_referer( 'pp_offer_print_' . $offer_id );
		$offer  = RentalOffers::get( $offer_id );
		$rental = $offer ? Rentals::get( (int) $offer->rental_id ) : null;
		if ( ! Settings::feature_on( 'lending' ) || ! $offer || ! RentalOffers::can_manage( get_current_user_id(), $rental ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'project-prepper' ), '', [ 'response' => 403 ] );
		}
		nocache_headers();
		self::render( $offer, $rental );
		exit;
	}

	private static function render( object $offer, object $rental ): void {
		$tot   = RentalOffers::totals( $offer );
		$money = static fn( $v ) => number_format_i18n( (float) $v, 2 ) . ' €';
		$num   = static fn( $v ) => rtrim( rtrim( number_format_i18n( (float) $v, 2 ), '0' ), ',.' );
		$event = trim( (string) ( $rental->event_name ?? '' ) );
		$rate  = number_format_i18n( (float) $tot['vat_rate'], 0 );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $offer->offer_number . ' — ' . $offer->subject ); ?></title>
<style>
	@page { size: A4; margin: 18mm 18mm 20mm; }
	* { box-sizing: border-box; }
	html { background: #e5e7eb; }
	body { margin: 0; font: 10.5pt/1.45 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: #111827; }
	.bar { position: sticky; top: 0; display: flex; gap: 8px; justify-content: center; padding: 10px; background: #1e1b4b; }
	.bar button { font: inherit; font-weight: 600; padding: 8px 16px; border-radius: 8px; border: 0; background: #6366f1; color: #fff; cursor: pointer; }
	.bar button.ghost { background: transparent; border: 1px solid #c7d2fe; color: #e0e7ff; }
	.page { width: 210mm; min-height: 297mm; margin: 16px auto; padding: 18mm; background: #fff; box-shadow: 0 2px 12px rgb(0 0 0 / .12); }
	.head { display: flex; justify-content: space-between; gap: 12mm; margin-bottom: 14mm; }
	.from { text-align: right; white-space: pre-line; font-size: 9.5pt; color: #374151; margin-left: auto; }
	.to { white-space: pre-line; min-height: 30mm; }
	.meta { border-collapse: collapse; font-size: 9.5pt; }
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
	@media print {
		html { background: none; }
		.bar { display: none; }
		.page { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
	}
	@media (max-width: 820px) {
		.page { width: auto; min-height: 0; margin: 0; padding: 16px; }
		.head { flex-direction: column-reverse; }
		.from { text-align: left; margin-left: 0; }
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
			<div class="to"><?php echo esc_html( (string) $offer->recipient ); ?></div>
			<div>
				<div class="from"><?php echo esc_html( (string) $offer->issuer ); ?></div>
			</div>
		</div>
		<table class="meta" style="margin: 0 0 8mm auto;">
			<tr><td><?php esc_html_e( 'Offer number', 'project-prepper' ); ?></td><td><?php echo esc_html( $offer->offer_number ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Date', 'project-prepper' ); ?></td><td><?php echo esc_html( mysql2date( 'd.m.Y', (string) $offer->offer_date ) ); ?></td></tr>
			<?php if ( ! empty( $offer->valid_until ) ) : ?>
				<tr><td><?php esc_html_e( 'Valid until', 'project-prepper' ); ?></td><td><?php echo esc_html( mysql2date( 'd.m.Y', (string) $offer->valid_until ) ); ?></td></tr>
			<?php endif; ?>
			<?php if ( '' !== $event ) : ?>
				<tr><td><?php esc_html_e( 'Event', 'project-prepper' ); ?></td><td><?php echo esc_html( $event ); ?></td></tr>
			<?php endif; ?>
			<tr><td><?php esc_html_e( 'Rental period', 'project-prepper' ); ?></td><td><?php echo esc_html( mysql2date( 'd.m.Y', (string) $rental->date_from ) . ' – ' . mysql2date( 'd.m.Y', (string) $rental->date_to ) ); ?></td></tr>
		</table>

		<h1><?php echo esc_html( '' !== trim( (string) $offer->subject ) ? (string) $offer->subject : __( 'Offer', 'project-prepper' ) ); ?></h1>
		<?php if ( '' !== trim( (string) $offer->intro ) ) : ?>
			<p class="text"><?php echo esc_html( (string) $offer->intro ); ?></p>
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
					echo esc_html( 'percent' === $offer->discount_type
						/* translators: %s: discount percentage. */
						? sprintf( __( 'Discount %s %%', 'project-prepper' ), $num( $offer->discount_value ) )
						: __( 'Discount', 'project-prepper' ) );
					?>
				</td><td>− <?php echo esc_html( $money( $tot['discount'] ) ); ?></td></tr>
			<?php endif; ?>
			<?php if ( $tot['small_business'] ) : ?>
				<tr class="sum"><td><?php esc_html_e( 'Total', 'project-prepper' ); ?></td><td><?php echo esc_html( $money( $tot['gross'] ) ); ?></td></tr>
			<?php elseif ( 'net' === $offer->price_mode ) : ?>
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

		<?php if ( '' !== trim( (string) $offer->outro ) ) : ?>
			<p class="text" style="margin-top: 8mm;"><?php echo esc_html( (string) $offer->outro ); ?></p>
		<?php endif; ?>
	</main>
</body>
</html>
		<?php
	}
}
