<?php
/**
 * Fuso horário da plataforma.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fixa o relógio da aplicação no fuso de Maceió.
 *
 * O "Aberta agora" da página da loja vem de `dokan_is_store_open()`, que
 * compara o horário cadastrado com `current_datetime()` — e esse relógio é o da
 * opção `timezone_string`. Instalação nova nasce com ela **vazia**, o que no
 * núcleo quer dizer UTC: às 9h em Maceió a plataforma lia meio-dia, e uma loja
 * que abre das 8h às 11h aparecia fechada, com o horário certo impresso ao lado.
 * O `provision.sh` nunca a gravava, e o ambiente de desenvolvimento só acertava
 * porque alguém a escolheu à mão no painel.
 *
 * O valor é imposto por filtro, e não só gravado: a opção é editável em
 * Configurações → Geral, e trocá-la por engano lá (ou um dump restaurado com
 * outro valor) voltaria a errar o horário em silêncio. O projeto atende um
 * território só, no fuso de Alagoas.
 *
 * `gmt_offset` não precisa de filtro próprio: o núcleo já pendura
 * `wp_timezone_override_offset()` em `pre_option_gmt_offset`, e ela o deriva do
 * `timezone_string` — que passa a ser este.
 */
class Reconectar_Fuso {

	/**
	 * Fuso da plataforma, no nome da base IANA.
	 *
	 * `America/Maceio`, e não `America/Sao_Paulo`: hoje os dois coincidem em
	 * UTC−3, mas o horário de verão já valeu em um e não no outro, e o nome
	 * certo é o que acompanha a regra de Alagoas se ela mudar.
	 */
	const FUSO = 'America/Maceio';

	/**
	 * Registra o filtro.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'pre_option_timezone_string', array( __CLASS__, 'fuso' ) );
	}

	/**
	 * Devolve o fuso da plataforma no lugar da opção gravada.
	 *
	 * @return string Identificador do fuso.
	 */
	public static function fuso() {
		return self::FUSO;
	}
}
