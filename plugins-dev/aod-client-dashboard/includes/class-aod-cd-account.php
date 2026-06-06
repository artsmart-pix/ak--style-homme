<?php
/**
 * Page d'administration « Accès Gérant ».
 *
 * Donne à l'administrateur un endroit dédié pour créer le compte du gérant de
 * boutique (rôle restreint AOD_CD_ROLE) sans avoir à passer par l'écran
 * Utilisateurs et à choisir le bon rôle à la main. Liste aussi les gérants
 * existants avec un lien direct vers leur espace de gestion.
 *
 * @package AOD_Client_Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AOD_CD_Account {

	/** @var AOD_CD_Account|null */
	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
	}

	/**
	 * Sous-menu sous « Utilisateurs ». Réservé à qui peut créer des comptes.
	 */
	public function menu() {
		add_users_page(
			__( 'Accès Gérant Boutique', 'aod-client-dashboard' ),
			__( 'Accès Gérant', 'aod-client-dashboard' ),
			'create_users',
			'aod-cd-access',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Affiche le formulaire de création et la liste des gérants. Traite aussi
	 * la soumission du formulaire (POST + nonce).
	 */
	public function render_page() {
		if ( ! current_user_can( 'create_users' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'aod-client-dashboard' ) );
		}

		$notice = '';
		$error  = '';

		if ( isset( $_POST['aod_cd_create_client'] ) ) {
			list( $notice, $error ) = $this->handle_create();
		}

		$dashboard_url = home_url( '/' . AOD_CD_SLUG . '/' );
		$managers      = get_users( array( 'role' => AOD_CD_ROLE, 'orderby' => 'registered', 'order' => 'DESC' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Accès Gérant Boutique', 'aod-client-dashboard' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: %s: dashboard URL */
					esc_html__( 'Crée le compte que ton client utilisera pour gérer sa boutique. Il se connectera normalement puis sera dirigé vers son espace : %s — sans aucun accès au tableau de bord WordPress.', 'aod-client-dashboard' ),
					'<code>' . esc_html( $dashboard_url ) . '</code>'
				);
				?>
			</p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<?php if ( $error ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Créer un compte gérant', 'aod-client-dashboard' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'aod_cd_create_client' ); ?>
				<input type="hidden" name="aod_cd_create_client" value="1">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aod-cd-login"><?php esc_html_e( 'Identifiant', 'aod-client-dashboard' ); ?> <span class="description">(<?php esc_html_e( 'requis', 'aod-client-dashboard' ); ?>)</span></label></th>
						<td><input name="user_login" id="aod-cd-login" type="text" class="regular-text" required autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row"><label for="aod-cd-name"><?php esc_html_e( 'Nom affiché', 'aod-client-dashboard' ); ?></label></th>
						<td><input name="display_name" id="aod-cd-name" type="text" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="aod-cd-email"><?php esc_html_e( 'Adresse e-mail', 'aod-client-dashboard' ); ?></label></th>
						<td><input name="email" id="aod-cd-email" type="email" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="aod-cd-pass"><?php esc_html_e( 'Mot de passe', 'aod-client-dashboard' ); ?> <span class="description">(<?php esc_html_e( 'requis', 'aod-client-dashboard' ); ?>)</span></label></th>
						<td>
							<input name="password" id="aod-cd-pass" type="text" class="regular-text" required autocomplete="off" value="<?php echo esc_attr( wp_generate_password( 14, true ) ); ?>">
							<p class="description"><?php esc_html_e( 'Au moins 8 caractères. Un mot de passe fort est pré-rempli ; communique-le au client.', 'aod-client-dashboard' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Créer le compte gérant', 'aod-client-dashboard' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Gérants existants', 'aod-client-dashboard' ); ?></h2>
			<?php if ( empty( $managers ) ) : ?>
				<p><?php esc_html_e( 'Aucun compte gérant pour le moment.', 'aod-client-dashboard' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Identifiant', 'aod-client-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Nom', 'aod-client-dashboard' ); ?></th>
							<th><?php esc_html_e( 'E-mail', 'aod-client-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'aod-client-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $managers as $m ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $m->user_login ); ?></strong></td>
								<td><?php echo esc_html( $m->display_name ); ?></td>
								<td><?php echo esc_html( $m->user_email ); ?></td>
								<td>
									<a href="<?php echo esc_url( get_edit_user_link( $m->ID ) ); ?>"><?php esc_html_e( 'Modifier', 'aod-client-dashboard' ); ?></a>
									&nbsp;|&nbsp;
									<a href="<?php echo esc_url( $dashboard_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ouvrir la gestion', 'aod-client-dashboard' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Valide et crée le compte gérant à partir du POST.
	 *
	 * @return array{0:string,1:string} [ message de succès, message d'erreur ].
	 */
	protected function handle_create() {
		check_admin_referer( 'aod_cd_create_client' );

		if ( ! current_user_can( 'create_users' ) ) {
			return array( '', __( 'Accès refusé.', 'aod-client-dashboard' ) );
		}

		$login = isset( $_POST['user_login'] ) ? sanitize_user( wp_unslash( $_POST['user_login'] ), true ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$name  = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
		$pass  = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

		if ( '' === $login || '' === $pass ) {
			return array( '', __( 'L’identifiant et le mot de passe sont obligatoires.', 'aod-client-dashboard' ) );
		}
		if ( strlen( $pass ) < 8 ) {
			return array( '', __( 'Le mot de passe doit faire au moins 8 caractères.', 'aod-client-dashboard' ) );
		}
		if ( username_exists( $login ) ) {
			return array( '', __( 'Cet identifiant existe déjà.', 'aod-client-dashboard' ) );
		}
		if ( '' !== $email && ! is_email( $email ) ) {
			return array( '', __( 'Adresse e-mail invalide.', 'aod-client-dashboard' ) );
		}
		if ( '' !== $email && email_exists( $email ) ) {
			return array( '', __( 'Cette adresse e-mail est déjà utilisée.', 'aod-client-dashboard' ) );
		}

		$uid = wp_insert_user( array(
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => $pass,
			'display_name' => '' !== $name ? $name : $login,
			'role'         => AOD_CD_ROLE,
		) );

		if ( is_wp_error( $uid ) ) {
			return array( '', $uid->get_error_message() );
		}

		return array(
			sprintf(
				/* translators: %s: login of the created manager */
				__( 'Compte gérant « %s » créé. Il peut se connecter et sera dirigé vers son espace de gestion.', 'aod-client-dashboard' ),
				$login
			),
			'',
		);
	}
}
