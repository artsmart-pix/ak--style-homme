<?php
/**
 * Widget Elementor : formulaire COD (AOD).
 *
 * Chargé uniquement quand Elementor est actif (la classe parente existe alors).
 *
 * @package AOD_COD_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AOD_COD_Elementor_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'aod_cod_form';
	}

	public function get_title() {
		return __( 'Formulaire COD (AOD)', 'aod-cod-form' );
	}

	public function get_icon() {
		return 'eicon-cart-medium';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'cod', 'commande', 'livraison', 'wilaya', 'algerie' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array( 'label' => __( 'Réglages du formulaire COD', 'aod-cod-form' ) )
		);

		$this->add_control(
			'product_id',
			array(
				'label'       => __( 'ID du produit', 'aod-cod-form' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 0,
				'min'         => 0,
				'description' => __( 'Laissez 0 pour utiliser le produit de la page courante.', 'aod-cod-form' ),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$pid      = isset( $settings['product_id'] ) ? absint( $settings['product_id'] ) : 0;

		if ( ! $pid && function_exists( 'is_product' ) && is_product() ) {
			$pid = (int) get_the_ID();
		}

		if ( ! $pid ) {
			echo '<div style="padding:16px;border:1px dashed #c3c4c7;border-radius:8px;color:#646970">'
				. esc_html__( 'Formulaire COD : indiquez un ID de produit, ou placez le widget sur une page produit.', 'aod-cod-form' )
				. '</div>';
			return;
		}

		// get_form_html() est déjà échappé/contrôlé en interne.
		echo AOD_COD_Form::instance()->get_form_html( $pid ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
