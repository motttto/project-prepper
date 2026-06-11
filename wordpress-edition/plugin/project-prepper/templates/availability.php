<?php
/**
 * Template: Verfügbarkeits-Check — [pp_availability]
 * Überschreibbar via {theme}/project-prepper/availability.php
 *
 * @var bool       $fixed_item  Item per Shortcode-Attribut fest vorgegeben
 * @var int        $item_id
 * @var array      $items       Auswahl-Liste (nur wenn nicht fixed)
 * @var string     $from
 * @var string     $to
 * @var array|null $result      ['item_name' =>, 'available' =>] oder null
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="pp-front">
	<form class="pp-front-form pp-front-availability" method="get" action="">
		<?php if ( ! $fixed_item ) : ?>
			<label class="pp-front-field">
				<span><?php esc_html_e( 'Equipment', 'project-prepper' ); ?></span>
				<select name="pp_item" required>
					<option value=""><?php esc_html_e( '— wählen —', 'project-prepper' ); ?></option>
					<?php foreach ( $items as $item ) : ?>
						<option value="<?php echo esc_attr( $item['id'] ); ?>" <?php selected( $item_id, $item['id'] ); ?>><?php echo esc_html( $item['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		<?php else : ?>
			<input type="hidden" name="pp_item" value="<?php echo esc_attr( $item_id ); ?>">
		<?php endif; ?>

		<label class="pp-front-field">
			<span><?php esc_html_e( 'Von', 'project-prepper' ); ?></span>
			<input type="date" name="pp_from" value="<?php echo esc_attr( $from ); ?>" required>
		</label>
		<label class="pp-front-field">
			<span><?php esc_html_e( 'Bis', 'project-prepper' ); ?></span>
			<input type="date" name="pp_to" value="<?php echo esc_attr( $to ); ?>" required>
		</label>
		<button type="submit" class="pp-front-btn pp-front-btn-primary"><?php esc_html_e( 'Verfügbarkeit prüfen', 'project-prepper' ); ?></button>
	</form>

	<?php if ( null !== $result ) : ?>
		<div class="pp-front-notice <?php echo $result['available'] > 0 ? 'pp-front-notice-ok' : 'pp-front-notice-warn'; ?>">
			<?php if ( $result['available'] > 0 ) : ?>
				<?php
				/* translators: 1: Artikelname, 2: Anzahl */
				printf( esc_html__( '"%1$s" ist im gewählten Zeitraum %2$d× verfügbar.', 'project-prepper' ), esc_html( $result['item_name'] ), (int) $result['available'] );
				?>
			<?php else : ?>
				<?php
				/* translators: %s: Artikelname */
				printf( esc_html__( '"%s" ist im gewählten Zeitraum leider nicht verfügbar.', 'project-prepper' ), esc_html( $result['item_name'] ) );
				?>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
