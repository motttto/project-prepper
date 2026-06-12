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
					<option value=""><?php esc_html_e( '— select —', 'project-prepper' ); ?></option>
					<?php foreach ( $items as $item ) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Template läuft im Methoden-Scope, keine echte Globale. ?>
						<option value="<?php echo esc_attr( $item['id'] ); ?>" <?php selected( $item_id, $item['id'] ); ?>><?php echo esc_html( $item['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		<?php else : ?>
			<input type="hidden" name="pp_item" value="<?php echo esc_attr( $item_id ); ?>">
		<?php endif; ?>

		<label class="pp-front-field">
			<span><?php esc_html_e( 'From', 'project-prepper' ); ?></span>
			<input type="date" name="pp_from" value="<?php echo esc_attr( $from ); ?>" required>
		</label>
		<label class="pp-front-field">
			<span><?php esc_html_e( 'To', 'project-prepper' ); ?></span>
			<input type="date" name="pp_to" value="<?php echo esc_attr( $to ); ?>" required>
		</label>
		<button type="submit" class="pp-front-btn pp-front-btn-primary"><?php esc_html_e( 'Check availability', 'project-prepper' ); ?></button>
	</form>

	<?php if ( null !== $result ) : ?>
		<div class="pp-front-notice <?php echo $result['available'] > 0 ? 'pp-front-notice-ok' : 'pp-front-notice-warn'; ?>">
			<?php if ( $result['available'] > 0 ) : ?>
				<?php
				/* translators: 1: item name, 2: available quantity */
				printf( esc_html__( '"%1$s" is available %2$d× in the selected period.', 'project-prepper' ), esc_html( $result['item_name'] ), (int) $result['available'] );
				?>
			<?php else : ?>
				<?php
				/* translators: %s: item name */
				printf( esc_html__( 'Unfortunately, "%s" is not available in the selected period.', 'project-prepper' ), esc_html( $result['item_name'] ) );
				?>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
