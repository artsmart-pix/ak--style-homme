<?php
/**
 * Pixels de tracking publicitaire : Meta (Facebook), TikTok, Snapchat, Google Ads.
 *
 * - PageView injecté dans le <head> sur tout le site.
 * - Événement d'achat/conversion déclenché sur la page de remerciement
 *   WooCommerce (woocommerce_thankyou), une seule fois par commande.
 *
 * Comme le formulaire COD crée la commande puis redirige vers la page de
 * remerciement, c'est là que la conversion réelle est mesurée.
 *
 * @package AOD_COD_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AOD_COD_Pixels {

	/** @var AOD_COD_Pixels|null */
	protected static $instance = null;

	const OPTION = 'aod_cod_pixels';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Pixels de base + PageView (front uniquement).
		add_action( 'wp_head', array( $this, 'head_pixels' ), 5 );

		// Conversion sur la page de remerciement.
		add_action( 'woocommerce_thankyou', array( $this, 'purchase_pixels' ), 10, 1 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ) );
			add_action( 'admin_init', array( $this, 'maybe_save' ) );
		}
	}

	/**
	 * Réglages enregistrés (avec valeurs par défaut).
	 *
	 * @return array
	 */
	public function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( (array) $saved, array(
			'meta'                     => '',
			'tiktok'                   => '',
			'snapchat'                 => '',
			'google_ads'               => '',
			'google_label'             => '',
			'meta_domain_verification' => '',
		) );
	}

	/**
	 * Nettoie un identifiant de pixel (caractères sûrs uniquement).
	 *
	 * @param string $v
	 * @return string
	 */
	protected static function clean_id( $v ) {
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $v );
	}

	/**
	 * Extrait le code de vérification de domaine Meta.
	 *
	 * Accepte aussi bien le code brut que la balise complète collée telle quelle :
	 * <meta name="facebook-domain-verification" content="XXXX" />. Ne conserve
	 * que le contenu de l’attribut content, nettoyé.
	 *
	 * @param string $v
	 * @return string
	 */
	protected static function clean_domain_verification( $v ) {
		$v = (string) $v;
		if ( preg_match( '/content\s*=\s*[\'"]([^\'"]+)[\'"]/i', $v, $m ) ) {
			$v = $m[1];
		}
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', trim( $v ) );
	}

	/* --------------------------------------------------------------------- */
	/* Sortie front                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Pixels de base + PageView dans le <head>.
	 */
	public function head_pixels() {
		if ( is_admin() ) {
			return;
		}
		$s = $this->settings();

		// --- Vérification de domaine Meta ---
		// Balise statique dans le <head> (jamais injectée par JS, sinon Meta ne la
		// trouve pas). Présente sur toutes les pages, donc sur l’accueil exigé.
		if ( '' !== $s['meta_domain_verification'] ) {
			printf(
				"<meta name=\"facebook-domain-verification\" content=\"%s\" />\n",
				esc_attr( $s['meta_domain_verification'] )
			);
		}

		// --- Meta (Facebook) Pixel ---
		if ( '' !== $s['meta'] ) {
			$id = self::clean_id( $s['meta'] );
			?>
<!-- Meta Pixel (AOD) -->
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', <?php echo wp_json_encode( $id ); ?>);
fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=<?php echo esc_attr( $id ); ?>&ev=PageView&noscript=1"/></noscript>
<!-- /Meta Pixel -->
			<?php
		}

		// --- TikTok Pixel ---
		if ( '' !== $s['tiktok'] ) {
			$id = self::clean_id( $s['tiktok'] );
			?>
<!-- TikTok Pixel (AOD) -->
<script>
!function (w, d, t) {w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"];ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js",o=n&&n.partner;ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=r,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};n=document.createElement("script");n.type="text/javascript",n.async=!0,n.src=r+"?sdkid="+e+"&lib="+t;e=document.getElementsByTagName("script")[0];e.parentNode.insertBefore(n,e)};
ttq.load(<?php echo wp_json_encode( $id ); ?>);
ttq.page();
}(window, document, 'ttq');
</script>
<!-- /TikTok Pixel -->
			<?php
		}

		// --- Snapchat Pixel ---
		if ( '' !== $s['snapchat'] ) {
			$id = self::clean_id( $s['snapchat'] );
			?>
<!-- Snapchat Pixel (AOD) -->
<script>
(function(e,t,n){if(e.snaptr)return;var a=e.snaptr=function(){a.handleRequest?
a.handleRequest.apply(a,arguments):a.queue.push(arguments)};a.queue=[];var s='script';
var r=t.createElement(s);r.async=!0;r.src=n;var u=t.getElementsByTagName(s)[0];
u.parentNode.insertBefore(r,u);})(window,document,'https://sc-static.net/scevent.min.js');
snaptr('init', <?php echo wp_json_encode( $id ); ?>);
snaptr('track', 'PAGE_VIEW');
</script>
<!-- /Snapchat Pixel -->
			<?php
		}

		// --- Google Ads (gtag) ---
		if ( '' !== $s['google_ads'] ) {
			$id = self::clean_id( $s['google_ads'] );
			?>
<!-- Google Ads (AOD) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $id ); ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', <?php echo wp_json_encode( $id ); ?>);
</script>
<!-- /Google Ads -->
			<?php
		}
	}

	/**
	 * Événement d'achat/conversion sur la page de remerciement.
	 *
	 * @param int $order_id
	 */
	public function purchase_pixels( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Anti double comptage : une seule fois par commande.
		if ( $order->get_meta( '_aod_pixels_fired' ) ) {
			return;
		}

		$s = $this->settings();
		if ( '' === $s['meta'] && '' === $s['tiktok'] && '' === $s['snapchat'] && '' === $s['google_ads'] ) {
			return;
		}

		$value    = (float) $order->get_total();
		$currency = $order->get_currency();
		$tx       = (string) $order->get_order_number();
		// Identifiant d'événement stable (= n° de commande) pour la déduplication
		// Meta « pixel ↔ Conversions API » : même event_name + même event_id =
		// un seul achat comptabilisé. Cf. fbq(..., { eventID }).
		$event_id = $tx;

		$content_ids = array();
		foreach ( $order->get_items() as $item ) {
			$pid = $item->get_product_id();
			if ( $pid ) {
				$content_ids[] = (string) $pid;
			}
		}

		// Marque tout de suite pour éviter un re-déclenchement (refresh).
		$order->update_meta_data( '_aod_pixels_fired', 1 );
		$order->save();

		echo "\n<!-- AOD COD : conversions -->\n<script>\n";

		if ( '' !== $s['meta'] ) {
			printf(
				"if(window.fbq){fbq('track','Purchase',{value:%s,currency:%s,content_ids:%s,content_type:'product'},{eventID:%s});}\n",
				wp_json_encode( $value ),
				wp_json_encode( $currency ),
				wp_json_encode( $content_ids ),
				wp_json_encode( $event_id )
			);
		}

		if ( '' !== $s['tiktok'] ) {
			printf(
				"if(window.ttq){ttq.track('CompletePayment',{value:%s,currency:%s,content_type:'product',content_id:%s});}\n",
				wp_json_encode( $value ),
				wp_json_encode( $currency ),
				wp_json_encode( $content_ids ? $content_ids[0] : '' )
			);
		}

		if ( '' !== $s['snapchat'] ) {
			printf(
				"if(window.snaptr){snaptr('track','PURCHASE',{price:%s,currency:%s,transaction_id:%s});}\n",
				wp_json_encode( $value ),
				wp_json_encode( $currency ),
				wp_json_encode( $tx )
			);
		}

		if ( '' !== $s['google_ads'] ) {
			$send_to = self::clean_id( $s['google_ads'] );
			if ( '' !== $s['google_label'] ) {
				$send_to .= '/' . self::clean_id( $s['google_label'] );
			}
			printf(
				"if(window.gtag){gtag('event','conversion',{send_to:%s,value:%s,currency:%s,transaction_id:%s});}\n",
				wp_json_encode( $send_to ),
				wp_json_encode( $value ),
				wp_json_encode( $currency ),
				wp_json_encode( $tx )
			);
		}

		echo "</script>\n<!-- /AOD COD : conversions -->\n";
	}

	/**
	 * Charge utile de conversion (achat) prête à être déclenchée côté client.
	 *
	 * Le formulaire COD ne redirige PAS vers la page « commande reçue » de
	 * WooCommerce (tunnel checkout exclu de ce template) : le hook
	 * woocommerce_thankyou ne se déclenche donc jamais et purchase_pixels() ne
	 * mesurerait aucun achat. On expose ici les mêmes événements pour qu'ils
	 * soient émis en JS juste après la création de la commande (réponse AJAX du
	 * formulaire).
	 *
	 * Marque la commande comme déjà comptée — anti double comptage partagé avec
	 * purchase_pixels(). Retourne null si déjà comptée ou si aucun pixel n'est
	 * configuré.
	 *
	 * @param WC_Order $order
	 * @return array|null
	 */
	public function purchase_payload( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		// Anti double comptage : une seule fois par commande.
		if ( $order->get_meta( '_aod_pixels_fired' ) ) {
			return null;
		}

		$s = $this->settings();
		if ( '' === $s['meta'] && '' === $s['tiktok'] && '' === $s['snapchat'] && '' === $s['google_ads'] ) {
			return null;
		}

		$content_ids = array();
		foreach ( $order->get_items() as $item ) {
			$pid = $item->get_product_id();
			if ( $pid ) {
				$content_ids[] = (string) $pid;
			}
		}

		// Marque tout de suite pour éviter un re-déclenchement (refresh, ou hook
		// woocommerce_thankyou s'il venait à s'exécuter).
		$order->update_meta_data( '_aod_pixels_fired', 1 );
		$order->save();

		$payload = array(
			'value'          => (float) $order->get_total(),
			'currency'       => $order->get_currency(),
			'transaction_id' => (string) $order->get_order_number(),
			// Déduplication Meta pixel ↔ Conversions API (même event_id côté serveur).
			'event_id'       => (string) $order->get_order_number(),
			'content_ids'    => array_values( $content_ids ),
			'meta'           => ( '' !== $s['meta'] ),
			'tiktok'         => ( '' !== $s['tiktok'] ),
			'snapchat'       => ( '' !== $s['snapchat'] ),
		);

		if ( '' !== $s['google_ads'] ) {
			$send_to = self::clean_id( $s['google_ads'] );
			if ( '' !== $s['google_label'] ) {
				$send_to .= '/' . self::clean_id( $s['google_label'] );
			}
			$payload['google_send_to'] = $send_to;
		}

		return $payload;
	}

	/* --------------------------------------------------------------------- */
	/* Admin                                                                 */
	/* --------------------------------------------------------------------- */

	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Pixels & Tracking', 'aod-cod-form' ),
			__( 'Pixels & Tracking', 'aod-cod-form' ),
			'manage_woocommerce',
			'aod-cod-pixels',
			array( $this, 'render_page' )
		);
	}

	public function maybe_save() {
		if ( ! isset( $_POST['aod_cod_pixels_save'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'aod_cod_pixels_save' );

		$settings = array(
			'meta'         => isset( $_POST['meta'] ) ? self::clean_id( wp_unslash( $_POST['meta'] ) ) : '',
			'tiktok'       => isset( $_POST['tiktok'] ) ? self::clean_id( wp_unslash( $_POST['tiktok'] ) ) : '',
			'snapchat'     => isset( $_POST['snapchat'] ) ? self::clean_id( wp_unslash( $_POST['snapchat'] ) ) : '',
			'google_ads'   => isset( $_POST['google_ads'] ) ? self::clean_id( wp_unslash( $_POST['google_ads'] ) ) : '',
			'google_label' => isset( $_POST['google_label'] ) ? self::clean_id( wp_unslash( $_POST['google_label'] ) ) : '',
			'meta_domain_verification' => isset( $_POST['meta_domain_verification'] ) ? self::clean_domain_verification( wp_unslash( $_POST['meta_domain_verification'] ) ) : '',
		);
		update_option( self::OPTION, $settings );

		add_settings_error( 'aod_cod_pixels', 'saved', __( 'Pixels enregistrés.', 'aod-cod-form' ), 'updated' );
	}

	public function render_page() {
		$s = $this->settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pixels & Tracking publicitaire', 'aod-cod-form' ); ?></h1>
			<?php settings_errors( 'aod_cod_pixels' ); ?>

			<p><?php esc_html_e( 'Colle l’identifiant de chaque régie. Laisse vide pour désactiver. Le PageView est suivi sur tout le site ; l’achat est mesuré sur la page de remerciement (une fois par commande).', 'aod-cod-form' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'aod_cod_pixels_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aod_px_meta"><?php esc_html_e( 'Meta (Facebook) Pixel ID', 'aod-cod-form' ); ?></label></th>
						<td>
							<input type="text" id="aod_px_meta" name="meta" value="<?php echo esc_attr( $s['meta'] ); ?>" class="regular-text" placeholder="1234567890">
							<p class="description"><?php esc_html_e( 'Gestionnaire d’événements Meta → Paramètres → ID du pixel.', 'aod-cod-form' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aod_px_tiktok"><?php esc_html_e( 'TikTok Pixel ID', 'aod-cod-form' ); ?></label></th>
						<td>
							<input type="text" id="aod_px_tiktok" name="tiktok" value="<?php echo esc_attr( $s['tiktok'] ); ?>" class="regular-text" placeholder="CXXXXXXXXXXXXXXXXXXX">
							<p class="description"><?php esc_html_e( 'TikTok Ads → Outils → Événements → Pixel Web.', 'aod-cod-form' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aod_px_snapchat"><?php esc_html_e( 'Snapchat Pixel ID', 'aod-cod-form' ); ?></label></th>
						<td>
							<input type="text" id="aod_px_snapchat" name="snapchat" value="<?php echo esc_attr( $s['snapchat'] ); ?>" class="regular-text" placeholder="00000000-0000-0000-0000-000000000000">
							<p class="description"><?php esc_html_e( 'Snapchat Ads → Gestionnaire d’événements → Pixel ID.', 'aod-cod-form' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aod_px_google"><?php esc_html_e( 'Google Ads — ID de conversion', 'aod-cod-form' ); ?></label></th>
						<td>
							<input type="text" id="aod_px_google" name="google_ads" value="<?php echo esc_attr( $s['google_ads'] ); ?>" class="regular-text" placeholder="AW-123456789">
							<p class="description"><?php esc_html_e( 'Commence par AW-. Google Ads → Objectifs → Conversions.', 'aod-cod-form' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aod_px_google_label"><?php esc_html_e( 'Google Ads — Libellé de conversion', 'aod-cod-form' ); ?></label></th>
						<td>
							<input type="text" id="aod_px_google_label" name="google_label" value="<?php echo esc_attr( $s['google_label'] ); ?>" class="regular-text" placeholder="AbC-D_efG-h12">
							<p class="description"><?php esc_html_e( 'La partie après la barre « / » dans send_to (étiquette de l’action de conversion « Achat »). Sans elle, seule la balise de base est posée.', 'aod-cod-form' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aod_px_meta_dv"><?php esc_html_e( 'Meta — Vérification de domaine', 'aod-cod-form' ); ?></label></th>
						<td>
							<input type="text" id="aod_px_meta_dv" name="meta_domain_verification" value="<?php echo esc_attr( $s['meta_domain_verification'] ); ?>" class="regular-text" placeholder="q7tv7ppepuk3m4z9op7bjcr3747t93">
							<p class="description"><?php esc_html_e( 'Colle la balise <meta name="facebook-domain-verification" …> fournie par Meta (ou juste son code). Injectée dans le <head> du site pour valider le domaine.', 'aod-cod-form' ); ?></p>
						</td>
					</tr>
				</table>
				<p><button type="submit" name="aod_cod_pixels_save" class="button button-primary"><?php esc_html_e( 'Enregistrer', 'aod-cod-form' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
