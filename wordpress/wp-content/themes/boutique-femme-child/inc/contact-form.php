<?php
/**
 * Formulaire de contact bilingue (FR/AR) — rendu + traitement AJAX.
 *
 * Disponible via le shortcode [bf_contact_form] et utilisé par la page Contact.
 *
 * @package BoutiqueFemme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rendu du formulaire.
 *
 * @return string HTML.
 */
function bf_contact_form_html() {
	ob_start();
	?>
	<form class="bf-form" id="bf-contact-form" novalidate>
		<div class="bf-form__row">
			<label class="bf-field">
				<span class="bf-field__label"><?php esc_html_e( 'Nom complet', 'boutique-femme' ); ?> *</span>
				<input type="text" name="name" required autocomplete="name">
			</label>
			<label class="bf-field">
				<span class="bf-field__label"><?php esc_html_e( 'Téléphone', 'boutique-femme' ); ?> *</span>
				<input type="tel" name="phone" required inputmode="tel" autocomplete="tel" placeholder="0xxxxxxxxx">
			</label>
		</div>
		<label class="bf-field">
			<span class="bf-field__label"><?php esc_html_e( 'E-mail', 'boutique-femme' ); ?></span>
			<input type="email" name="email" autocomplete="email">
		</label>
		<label class="bf-field">
			<span class="bf-field__label"><?php esc_html_e( 'Votre message', 'boutique-femme' ); ?> *</span>
			<textarea name="message" rows="5" required></textarea>
		</label>
		<?php // Anti-spam : champ piège invisible. ?>
		<div class="bf-hp" aria-hidden="true">
			<label><?php esc_html_e( 'Ne pas remplir', 'boutique-femme' ); ?>
				<input type="text" name="website" tabindex="-1" autocomplete="off">
			</label>
		</div>
		<button type="submit" class="bf-btn bf-btn--primary bf-form__submit">
			<?php esc_html_e( 'Envoyer le message', 'boutique-femme' ); ?>
		</button>
		<p class="bf-form__msg" role="status" aria-live="polite"></p>
	</form>
	<?php
	return ob_get_clean();
}
add_shortcode( 'bf_contact_form', 'bf_contact_form_html' );

/**
 * Traitement AJAX de l'envoi.
 */
function bf_contact_submit() {
	check_ajax_referer( 'bf_contact', 'nonce' );

	// Honeypot : si rempli → bot.
	if ( ! empty( $_POST['website'] ) ) {
		wp_send_json_success( array( 'message' => __( 'Message envoyé.', 'boutique-femme' ) ) );
	}

	$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
	$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

	if ( '' === $name || '' === $phone || '' === $message ) {
		wp_send_json_error( array( 'message' => __( 'Merci de remplir les champs obligatoires.', 'boutique-femme' ) ), 422 );
	}

	$to      = bf_info( 'bf_email' ) ?: get_option( 'admin_email' );
	$subject = sprintf(
		/* translators: %s: site name */
		__( '[%s] Nouveau message de contact', 'boutique-femme' ),
		wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
	);
	$body = sprintf(
		"%s : %s\n%s : %s\n%s : %s\n\n%s\n%s",
		__( 'Nom', 'boutique-femme' ), $name,
		__( 'Téléphone', 'boutique-femme' ), $phone,
		__( 'E-mail', 'boutique-femme' ), ( $email ?: '—' ),
		__( 'Message', 'boutique-femme' ), $message
	);
	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
	if ( $email && is_email( $email ) ) {
		$headers[] = 'Reply-To: ' . $name . ' <' . $email . '>';
	}

	// On enregistre aussi le message en brouillon privé pour ne rien perdre
	// si l'e-mail (SMTP) n'est pas configuré sur le serveur.
	wp_insert_post( array(
		'post_type'    => 'bf_contact_msg',
		'post_status'  => 'private',
		'post_title'   => $name . ' — ' . $phone,
		'post_content' => $message . "\n\n" . ( $email ?: '' ),
	) );

	$sent = wp_mail( $to, $subject, $body, $headers );

	// Même si l'e-mail échoue (pas de SMTP en local), le message est sauvegardé.
	wp_send_json_success( array(
		'message' => __( 'Message envoyé. Merci, nous vous répondrons vite.', 'boutique-femme' ),
		'mailed'  => (bool) $sent,
	) );
}
add_action( 'wp_ajax_bf_contact', 'bf_contact_submit' );
add_action( 'wp_ajax_nopriv_bf_contact', 'bf_contact_submit' );

/**
 * Type de contenu privé pour archiver les messages de contact (visible en
 * admin uniquement, jamais public).
 */
add_action( 'init', function () {
	register_post_type( 'bf_contact_msg', array(
		'labels'              => array(
			'name'          => __( 'Messages de contact', 'boutique-femme' ),
			'singular_name' => __( 'Message', 'boutique-femme' ),
		),
		'public'              => false,
		'show_ui'             => true,
		'show_in_menu'        => true,
		'menu_icon'           => 'dashicons-email',
		'capability_type'     => 'post',
		'exclude_from_search' => true,
		'supports'            => array( 'title', 'editor' ),
	) );
} );
