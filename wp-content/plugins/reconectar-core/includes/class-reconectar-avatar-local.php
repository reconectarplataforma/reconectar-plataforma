<?php
/**
 * Avatares servidos pela própria instalação, sem Gravatar.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Troca o Gravatar por um avatar padrão hospedado no próprio domínio.
 *
 * O Gravatar era o único recurso externo que sobrava no site inteiro: as nove
 * páginas públicas auditadas não fazem uma única requisição fora de casa,
 * exceto a página de produto, onde cada avaliação carregava um `<img>` de
 * `secure.gravatar.com`. São duas consequências, e nenhuma é cosmética.
 *
 * A primeira é de proteção de dados. O caminho da imagem é o hash SHA-256 do
 * e-mail de quem avaliou; a requisição ainda entrega, de quebra, o IP e o
 * cabeçalho `Referer` de cada visitante — isto é, quem olhou qual produto.
 * Tudo isso vai para um terceiro sem base legal declarada e sem que o
 * visitante tenha como recusar, o que a LGPD não permite numa plataforma
 * pública de edital.
 *
 * A segunda é o compromisso de não depender de CDN, que vale aqui pelo mesmo
 * motivo que valeu para a fonte do tema pai: o site tem de funcionar igual
 * numa rede que não alcance servidores fora do país.
 *
 * O ganho visual é nenhum: nenhum e-mail da plataforma tem conta no Gravatar,
 * então o serviço devolvia sempre a silhueta genérica do `d=mm`. Trocá-la por
 * uma silhueta local nas cores do projeto custa uma requisição a menos e
 * devolve a identidade visual do Reconectar a um elemento que hoje é cinza.
 */
class Reconectar_Avatar_Local {

	/**
	 * Arquivo servido no lugar do Gravatar, relativo à raiz do plugin.
	 *
	 * É SVG de propósito: um arquivo só atende todos os tamanhos pedidos pelo
	 * WordPress — 60px nas avaliações, 32px na barra de administração, o dobro
	 * de cada um no `srcset` de 2x — sem gerar uma imagem por medida.
	 */
	const ARQUIVO = 'assets/img/avatar-padrao.svg';

	/**
	 * Registra o filtro que troca a URL do avatar.
	 *
	 * O gancho é `get_avatar_data`, e não `pre_get_avatar_data`, porque os dois
	 * ficam em pontos opostos da função: o `pre_` roda antes de qualquer coisa
	 * ser resolvida, e o outro roda por último, depois do filtro
	 * `get_avatar_url` onde o BuddyPress (prioridade 10) e o Dokan (99) trocam
	 * o avatar pelo que o usuário enviou no perfil ou na loja. Interceptar no
	 * `pre_` apagaria esses avatares reais; interceptar no fim deixa cada um no
	 * seu lugar e só recolhe o que sobrou apontando para fora.
	 *
	 * Vale para os dois caminhos de saída do núcleo: `get_avatar()`, que monta
	 * o `<img>`, e `get_avatar_url()`, usada por plugins que só querem o
	 * endereço. Ambas passam por `get_avatar_data()`.
	 *
	 * O BuddyPress tem um terceiro caminho, que não passa por ali:
	 * `bp_core_fetch_avatar()` monta a URL do Gravatar sozinho, e o diretório
	 * de atividade saía com `www.gravatar.com/avatar/<hash>` em cada item —
	 * medido, pela página da Comunidade. `bp_core_fetch_avatar_no_grav` desliga
	 * esse ramo, e o padrão que ele usa no lugar passa por `bp_core_avatar_default`,
	 * onde entra o mesmo arquivo local. A foto que alguém enviou no perfil não é
	 * tocada: os dois filtros só valem para quem não tem avatar próprio.
	 */
	public static function init() {
		add_filter( 'get_avatar_data', array( __CLASS__, 'substituir_gravatar' ) );
		add_filter( 'bp_core_fetch_avatar_no_grav', '__return_true' );
		add_filter( 'bp_core_avatar_default', array( __CLASS__, 'url' ) );
		add_filter( 'bp_core_avatar_default_thumb', array( __CLASS__, 'url' ) );
	}

	/**
	 * Endereço do avatar padrão servido pela instalação.
	 *
	 * @return string URL do arquivo local.
	 */
	public static function url() {
		return RECONECTAR_CORE_URL . self::ARQUIVO;
	}

	/**
	 * Aponta para o arquivo local quando a URL resolvida for do Gravatar.
	 *
	 * A substituição é condicionada à origem em vez de incondicional, e essa é
	 * a diferença entre corrigir e atropelar: o filtro roda para todo avatar do
	 * site, inclusive os que o BuddyPress e o Dokan já resolveram para um
	 * arquivo do próprio servidor. Sem a condição, a foto que o vendedor subiu
	 * na loja viraria silhueta.
	 *
	 * Também não se mexe em `found_avatar`. Ele diz se a pessoa *tem* avatar
	 * próprio, e continua sendo `false`: o que entregamos é um padrão, e há
	 * tema e plugin que decidem exibir ou não o bloco inteiro com base nisso.
	 *
	 * @param array $args Dados do avatar já resolvidos pelo núcleo.
	 * @return array Dados com a URL trocada, quando for o caso.
	 */
	public static function substituir_gravatar( $args ) {
		if ( empty( $args['url'] ) || ! is_string( $args['url'] ) ) {
			return $args;
		}

		if ( false === strpos( $args['url'], 'gravatar.com' ) ) {
			return $args;
		}

		$args['url'] = self::url();

		return $args;
	}
}
