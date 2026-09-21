<?php
/**
 * Plugin Name: Reconectar Core
 * Plugin URI: https://github.com/reconectar/reconectar-plataforma
 * Description: Funcionalidades autorais do módulo de Governança Digital da plataforma Reconectar — Custom Post Type "Proposta de Votação" e painel público de transparência. MVP/esqueleto: as regras de funcionamento da votação ainda dependem de definição participativa com os beneficiários (Atividade 2.10 do edital).
 * Version: 0.1.0
 * Author: Reconectar
 * License: GPL-2.0-or-later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: reconectar-core
 */

defined( 'ABSPATH' ) || exit;

define( 'RECONECTAR_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'RECONECTAR_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-proposta-votacao.php';
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-painel-transparencia.php';

function reconectar_core_init() {
	Reconectar_Proposta_Votacao::init();
	Reconectar_Painel_Transparencia::init();
}
add_action( 'plugins_loaded', 'reconectar_core_init' );
