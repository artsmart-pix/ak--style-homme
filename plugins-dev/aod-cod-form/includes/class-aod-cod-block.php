<?php
/**
 * Intégrations éditeurs : bloc Gutenberg + widget Elementor.
 *
 * Les deux réutilisent AOD_COD_Form::get_form_html() : une seule source de vérité
 * pour le rendu (shortcode, bloc, widget). Le bloc est dynamique (rendu serveur),
 * donc aucune étape de build (npm/webpack) n'est nécessaire.
 *
 * @package AOD_COD_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AOD_COD_Block {

	/** @var AOD_COD_Block|null */
	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_block' ) );

		// Widget Elementor (Elementor 3.5+).
		add_action( 'elementor/widgets/register', array( $this, 'register_elementor' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Gutenberg                                                             */
	/* --------------------------------------------------------------------- */

	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return; // WordPress < 5.0.
		}

		// Script éditeur : enregistré avec ses dépendances avant le bloc
		// (block.json le référence par son handle « aod-cod-block »).
		wp_register_script(
			'aod-cod-block',
			AOD_COD_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
			AOD_COD_VERSION,
			true
		);

		register_block_type(
			AOD_COD_PATH . 'includes/block',
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Rendu serveur du bloc (et de l'aperçu ServerSideRender côté éditeur).
	 *
	 * @param array $attributes
	 * @return string
	 */
	public function render( $attributes ) {
		$pid = isset( $attributes['productId'] ) ? absint( $attributes['productId'] ) : 0;

		if ( ! $pid && function_exists( 'is_product' ) && is_product() ) {
			$pid = (int) get_the_ID();
		}

		if ( ! $pid ) {
			return '<div style="padding:16px;border:1px dashed #c3c4c7;border-radius:8px;color:#646970">'
				. esc_html__( 'Formulaire COD : indiquez un ID de produit dans les réglages du bloc, ou placez-le sur une page produit.', 'aod-cod-form' )
				. '</div>';
		}

		$html = AOD_COD_Form::instance()->get_form_html( $pid );

		if ( '' === $html ) {
			return '<div style="padding:16px;border:1px dashed #c3c4c7;border-radius:8px;color:#646970">'
				. esc_html__( 'Produit introuvable ou non disponible à la vente.', 'aod-cod-form' )
				. '</div>';
		}

		return $html;
	}

	/* --------------------------------------------------------------------- */
	/* Elementor                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Enregistre le widget Elementor.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager
	 */
	public function register_elementor( $widgets_manager ) {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		require_once AOD_COD_PATH . 'includes/class-aod-cod-elementor-widget.php';
		$widgets_manager->register( new AOD_COD_Elementor_Widget() );
	}
}
