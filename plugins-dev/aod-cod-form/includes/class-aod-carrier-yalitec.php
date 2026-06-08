<?php
/**
 * Livreur Yalitec.
 *
 * Même API que Yalidine (en-têtes X-API-ID / X-API-TOKEN, JSON) mais sur un
 * autre domaine. On hérite donc de tout le client Yalidine en ne changeant
 * que l'URL de base et la marque.
 *
 * @package AOD_COD_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AOD_Carrier_Yalitec extends AOD_Carrier_Yalidine {

	const API_BASE = 'https://api.yalitec.me/v1/';

	public function id() {
		return 'yalitec';
	}

	public function label() {
		return 'Yalitec';
	}

	public function brand_color() {
		return '#7c3aed';
	}

	public function initials() {
		return 'YT';
	}

	protected function api_base() {
		return self::API_BASE;
	}
}
