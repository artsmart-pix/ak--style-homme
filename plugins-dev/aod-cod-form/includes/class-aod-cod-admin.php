<?php
/**
 * Page d'administration : tarifs de livraison par wilaya.
 *
 * @package AOD_COD_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AOD_COD_Admin {

	/** @var AOD_COD_Admin|null */
	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Livraison COD (wilayas)', 'aod-cod-form' ),
			__( 'Livraison COD', 'aod-cod-form' ),
			'manage_woocommerce',
			'aod-cod-form',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Sauvegarde les tarifs si le formulaire est soumis.
	 */
	public function maybe_save() {
		if ( ! isset( $_POST['aod_cod_save'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'aod_cod_save_prices' );

		$prices = array();
		$home   = isset( $_POST['home'] ) ? (array) $_POST['home'] : array();
		$desk   = isset( $_POST['desk'] ) ? (array) $_POST['desk'] : array();

		foreach ( AOD_COD_Data::places() as $w ) {
			$code = (int) $w['code'];
			$h    = isset( $home[ $code ] ) ? wc_format_decimal( wp_unslash( $home[ $code ] ) ) : '';
			$d    = isset( $desk[ $code ] ) ? wc_format_decimal( wp_unslash( $desk[ $code ] ) ) : '';
			$prices[ $code ] = array(
				'home' => ( '' === $h ) ? '' : (float) $h,
				'desk' => ( '' === $d ) ? '' : (float) $d,
			);
		}
		update_option( AOD_COD_Data::OPTION_PRICES, $prices );

		// Seuil de livraison gratuite (même formulaire).
		$free = array(
			'enabled'   => isset( $_POST['fs_enabled'] ) ? 1 : 0,
			'threshold' => isset( $_POST['fs_threshold'] ) ? (float) wc_format_decimal( wp_unslash( $_POST['fs_threshold'] ) ) : 0,
		);
		update_option( AOD_COD_Data::OPTION_FREE_SHIP, $free );

		add_settings_error( 'aod_cod', 'saved', __( 'Tarifs de livraison enregistrés.', 'aod-cod-form' ), 'updated' );
	}

	public function render_page() {
		$prices = AOD_COD_Data::prices();
		$free   = AOD_COD_Data::free_shipping();
		$symbol = get_woocommerce_currency_symbol();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tarifs de livraison par wilaya (COD)', 'aod-cod-form' ); ?></h1>
			<?php settings_errors( 'aod_cod' ); ?>
			<form method="post">
				<?php wp_nonce_field( 'aod_cod_save_prices' ); ?>

				<h2><?php esc_html_e( 'Livraison gratuite', 'aod-cod-form' ); ?></h2>
				<table class="form-table" role="presentation" style="max-width:680px">
					<tr>
						<th scope="row"><?php esc_html_e( 'Activer', 'aod-cod-form' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="fs_enabled" value="1" <?php checked( ! empty( $free['enabled'] ) ); ?>>
								<?php esc_html_e( 'Offrir la livraison à partir d’un certain montant', 'aod-cod-form' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aod_fs_threshold"><?php esc_html_e( 'Seuil (sous-total produits)', 'aod-cod-form' ); ?></label></th>
						<td>
							<input type="number" id="aod_fs_threshold" min="0" step="any" name="fs_threshold" value="<?php echo '' !== (string) $free['threshold'] && (float) $free['threshold'] > 0 ? esc_attr( $free['threshold'] ) : ''; ?>" style="width:120px"> <?php echo esc_html( $symbol ); ?>
							<p class="description"><?php esc_html_e( 'Au-delà de ce montant de produits, les frais de livraison (domicile et stop-desk) passent à 0. Laissez vide ou décochez pour désactiver.', 'aod-cod-form' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Tarifs par wilaya', 'aod-cod-form' ); ?></h2>
				<p><?php esc_html_e( 'Laissez vide une wilaya pour la rendre non livrable (frais à 0).', 'aod-cod-form' ); ?></p>
				<table class="widefat striped" style="max-width:680px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Wilaya', 'aod-cod-form' ); ?></th>
							<th><?php echo esc_html( sprintf( __( 'Domicile (%s)', 'aod-cod-form' ), $symbol ) ); ?></th>
							<th><?php echo esc_html( sprintf( __( 'Stop-desk (%s)', 'aod-cod-form' ), $symbol ) ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( AOD_COD_Data::places() as $w ) : ?>
							<?php $code = (int) $w['code']; ?>
							<tr>
								<td><?php echo esc_html( sprintf( '%02d - %s', $code, $w['name'] ) ); ?></td>
								<td><input type="number" min="0" step="any" name="home[<?php echo esc_attr( $code ); ?>]" value="<?php echo isset( $prices[ $code ]['home'] ) ? esc_attr( $prices[ $code ]['home'] ) : ''; ?>" style="width:120px"></td>
								<td><input type="number" min="0" step="any" name="desk[<?php echo esc_attr( $code ); ?>]" value="<?php echo isset( $prices[ $code ]['desk'] ) ? esc_attr( $prices[ $code ]['desk'] ) : ''; ?>" style="width:120px"></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="submit" name="aod_cod_save" class="button button-primary"><?php esc_html_e( 'Enregistrer les tarifs', 'aod-cod-form' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
