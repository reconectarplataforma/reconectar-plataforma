<?php
/**
 * Comunidade, Fórum e Incubadora no painel da loja, fora da navegação de compra.
 *
 * Para quem tem papel `seller`, a comunidade e a Incubadora são ferramentas de
 * trabalho, não parte da vitrine: elas passam a morar no menu lateral do painel
 * do Dokan, e saem do menu principal, do rodapé e da home do site, onde
 * disputavam espaço com "Loja" e "Lojas". Moderador e Administrador não têm
 * painel do Dokan — para eles o menu do site é o único caminho, e fica como está.
 * O cliente não muda: a comunidade já lhe é negada, e a Incubadora, que ele lê,
 * continua no menu.
 *
 * Só a navegação muda. Nenhuma permissão é tocada: a loja segue abrindo as três
 * áreas pela URL, e é exatamente para lá que os itens novos do painel apontam.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Navegação da comunidade e da Incubadora para a loja.
 */
class Reconectar_Navegacao_Da_Loja {

	/**
	 * Registra os ganchos.
	 *
	 * Os dois filtros de ocultação rodam em prioridade 20, depois dos de
	 * `Reconectar_Permissoes` (10): para quem não participa da comunidade aqueles
	 * já tiraram os itens, e estes não têm o que fazer.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'dokan_get_dashboard_nav', array( __CLASS__, 'registrar_menu' ) );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'ocultar_itens_do_menu' ), 20 );
		add_filter( 'widget_custom_html_content', array( __CLASS__, 'ocultar_links_do_widget' ), 20 );
	}

	/**
	 * A comunidade e a Incubadora moram no painel da loja para o usuário corrente?
	 *
	 * Pelo papel, e não por `dokandar`: o Administrador também tem a capacidade de
	 * loja, e para ele o painel do Dokan não é a casa — tirar os itens do menu do
	 * site o deixaria sem caminho.
	 *
	 * @return bool
	 */
	public static function mora_no_painel() {
		return is_user_logged_in() && Reconectar_Permissoes::eh_vendedor( get_current_user_id() );
	}

	/**
	 * Acrescenta Comunidade, Fórum e Incubadora ao menu lateral do painel.
	 *
	 * São links para fora do painel, e por isso não têm query var nem pedem flush
	 * de reescrita: a chave do item só serve ao Dokan para marcar o ativo, e
	 * nenhuma tela do painel a usa. Cada item só entra se o destino existir —
	 * `get_page_by_path()` devolve rascunho e lixeira, e um link para o 404 é pior
	 * que um item a menos.
	 *
	 * As posições ficam depois de "Avaliações pendentes" (51) e antes de
	 * "Configurações", agrupando as três ferramentas que não são de venda.
	 *
	 * @param array $nav Itens do menu do painel.
	 * @return array
	 */
	public static function registrar_menu( $nav ) {
		$destinos = self::destinos();

		if ( '' !== $destinos['comunidade'] ) {
			$nav['rc-comunidade'] = array(
				'title'      => __( 'Comunidade', 'reconectar-core' ),
				'icon'       => '<i class="fas fa-users"></i>',
				'icon_name'  => 'Users',
				'url'        => $destinos['comunidade'],
				'pos'        => 160,
				'permission' => Reconectar_Permissoes::CAP_COMUNIDADE,
			);
		}

		if ( '' !== $destinos['forum'] ) {
			$nav['rc-forum'] = array(
				'title'      => __( 'Fórum', 'reconectar-core' ),
				'icon'       => '<i class="fas fa-comments"></i>',
				'icon_name'  => 'MessagesSquare',
				'url'        => $destinos['forum'],
				'pos'        => 161,
				'permission' => Reconectar_Permissoes::CAP_COMUNIDADE,
			);
		}

		if ( '' !== $destinos['incubadora'] ) {
			$nav['rc-incubadora'] = array(
				'title'      => __( 'Incubadora', 'reconectar-core' ),
				'icon'       => '<i class="fas fa-book-open"></i>',
				'icon_name'  => 'BookOpen',
				'url'        => $destinos['incubadora'],
				'pos'        => 162,
				'permission' => 'dokandar',
			);
		}

		return $nav;
	}

	/**
	 * Tira do menu do site os itens que, para a loja, moram no painel.
	 *
	 * A comparação é pelo caminho da URL, como em
	 * `Reconectar_Permissoes::ocultar_itens_da_comunidade()`: alcança o item
	 * `post_type` da página e o item `custom` do fórum, e sobrevive a alguém
	 * renomear o rótulo ou reordenar o menu pelo painel. Remover os itens do
	 * banco não serviria — o menu é o mesmo para todos os perfis.
	 *
	 * @param array $itens Itens do menu.
	 * @return array
	 */
	public static function ocultar_itens_do_menu( $itens ) {
		if ( ! self::mora_no_painel() ) {
			return $itens;
		}

		$caminhos = self::caminhos();

		foreach ( $itens as $indice => $item ) {
			$do_forum = in_array( $item->object, array( 'forum', 'topic' ), true );

			if ( $do_forum || in_array( Reconectar_Permissoes::caminho_de_url( $item->url ), $caminhos, true ) ) {
				unset( $itens[ $indice ] );
			}
		}

		return $itens;
	}

	/**
	 * Tira dos widgets de HTML (a coluna "Navegação" do rodapé) os mesmos links.
	 *
	 * @param string $conteudo HTML do widget.
	 * @return string
	 */
	public static function ocultar_links_do_widget( $conteudo ) {
		if ( ! self::mora_no_painel() ) {
			return $conteudo;
		}

		return Reconectar_Permissoes::remover_links_de_widget( $conteudo, self::caminhos() );
	}

	/**
	 * URLs das três áreas, cada uma vazia se o destino não estiver publicado.
	 *
	 * @return array{comunidade: string, forum: string, incubadora: string}
	 */
	private static function destinos() {
		$comunidade = get_page_by_path( 'comunidade' );
		$forum      = function_exists( 'bbp_get_forums_url' ) ? get_post_type_archive_link( 'forum' ) : '';

		return array(
			'comunidade' => ( $comunidade && 'publish' === $comunidade->post_status ) ? get_permalink( $comunidade ) : '',
			'forum'      => is_string( $forum ) ? $forum : '',
			'incubadora' => class_exists( 'Reconectar_Incubadora_Leitura' ) ? Reconectar_Incubadora_Leitura::url_da_ancora() : '',
		);
	}

	/**
	 * Os destinos reduzidos a caminho, prontos para comparar.
	 *
	 * @return string[]
	 */
	private static function caminhos() {
		return array_values(
			array_filter( array_map( array( 'Reconectar_Permissoes', 'caminho_de_url' ), self::destinos() ), 'strlen' )
		);
	}
}

/**
 * Fachada para o tema: a comunidade e a Incubadora moram no painel da loja?
 *
 * O tema consulta por `function_exists()` para não depender do plugin.
 *
 * @return bool
 */
function reconectar_comunidade_mora_no_painel_da_loja() {
	return Reconectar_Navegacao_Da_Loja::mora_no_painel();
}
