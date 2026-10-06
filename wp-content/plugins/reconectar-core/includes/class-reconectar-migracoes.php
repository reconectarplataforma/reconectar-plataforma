<?php
/**
 * Migrações de dados já gravados.
 *
 * Renomear uma constante no código não move uma linha do banco. Quando a
 * plataforma passou a chamar de **Loja** o que chamava de **Vendedor**, duas
 * mudanças atravessaram essa fronteira: a chave de user meta
 * `_reconectar_vendedor_ativo` e a capacidade `reconectar_gerir_vendedores`.
 *
 * A capacidade é cuidada por `Reconectar_Permissoes` — ela já tinha o mecanismo
 * (`VERSAO_CAPACIDADES` mais a lista `CAPS_LEGADAS`), e separar a remoção da
 * concessão deixaria a história em dois lugares. O que sobra para cá é a meta,
 * que nenhum mecanismo existente alcançava.
 *
 * O molde é o mesmo das capacidades: uma opção com o número da versão, e cada
 * passo roda uma única vez. Não é otimização — é o que permite escrever um
 * `UPDATE` direto em `meta_key` sem que ele volte a rodar em toda requisição.
 * Ainda assim, cada passo é idempotente por construção: rodar de novo, se a
 * opção for perdida, não pode estragar nada.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Executa, uma vez por instalação, as migrações de dados do plugin.
 */
class Reconectar_Migracoes {

	/**
	 * Versão atual do conjunto de migrações.
	 *
	 * Incrementar aqui é o que faz uma instalação já provisionada rodar o passo
	 * novo na próxima carga de página.
	 *
	 * 1 — renomeia `_reconectar_vendedor_ativo` para `_reconectar_loja_ativa`.
	 * 2 — promove Moderadores e Administradores a `bbp_moderator` no fórum.
	 * 3 — apaga as metas de contagem favor/contra das enquetes.
	 * 4 — reescreve as regras de permalink pela aba de comprovantes do painel.
	 * 5 — reescreve as regras outra vez, pela remoção daquela aba.
	 * 6 — reescreve as regras pelo post type da Incubadora (`incubadora_pagina`).
	 * 7 — reescreve as regras pela aba de avaliações pendentes do painel.
	 * 8 — renomeia a página da loja do WooCommerce, e os links dela, para "Produtos".
	 */
	const VERSAO = 8;

	/**
	 * Opção que guarda a versão já aplicada.
	 *
	 * Própria, e não a de `Reconectar_Permissoes`: as duas séries evoluem em
	 * ritmos diferentes, e compartilhar o contador faria uma migração de dados
	 * rodar de novo só porque uma capacidade mudou.
	 */
	const OPCAO_VERSAO = 'reconectar_migracoes_versao';

	/**
	 * Registra os ganchos do módulo.
	 *
	 * Prioridade 5 em `init`, antes de `Reconectar_Permissoes::sincronizar_capacidades()`
	 * (prioridade 10): a sincronização de capacidades não depende da meta, mas a
	 * ordem inversa deixaria uma janela de uma requisição em que o painel já lê a
	 * chave nova e as linhas ainda estão na antiga.
	 *
	 * Em `init`, e não na ativação do plugin, pelo mesmo motivo das capacidades:
	 * um plugin ativado uma vez, antes da renomeação existir, nunca mais dispara
	 * o gancho de ativação.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'executar' ), 5 );
	}

	/**
	 * Aplica as migrações pendentes, se houver.
	 *
	 * @return void
	 */
	public static function executar() {
		if ( (int) get_option( self::OPCAO_VERSAO ) >= self::VERSAO ) {
			return;
		}

		self::renomear_meta_de_loja_ativa();
		self::promover_moderadores_no_forum();
		self::descartar_contagem_binaria_das_enquetes();
		self::reescrever_permalinks();
		self::renomear_loja_para_produtos();

		update_option( self::OPCAO_VERSAO, self::VERSAO );
	}

	/**
	 * Reescreve as regras de permalink, uma vez.
	 *
	 * Query var do painel do Dokan — criada ou removida — só passa a valer depois
	 * de um flush. Numa instalação recém-provisionada o `provision.sh` faz isso;
	 * numa que já está de pé, ninguém faz, e o sintoma é mudo nas duas direções:
	 * ao criar, o item aparece no menu lateral, o link existe e a tela responde
	 * 404 sem uma linha no log; ao remover, a regra segue gravada apontando para
	 * um template que não existe mais.
	 *
	 * O passo se repete por isso: a versão 4 acompanhou a criação da aba de
	 * comprovantes, a 5 acompanha a remoção dela em favor de uma coluna na lista
	 * de pedidos, a 6 acompanha o post type da Incubadora — que traz regra de
	 * reescrita própria, `/incubadora/<pai>/<filho>/`, e responderia 404 numa
	 * instalação já de pé pelo mesmo motivo —, e a 7 acompanha a aba de
	 * avaliações pendentes.
	 *
	 * `flush_rewrite_rules()` é caro e por isso mora aqui, no mecanismo que roda
	 * uma vez por instalação, e não num gancho de carga de página.
	 *
	 * **O flush não acontece aqui dentro.** As migrações rodam em `init`
	 * prioridade 5, e nessa altura nem o Dokan nem o bbPress registraram as
	 * regras deles — regenerar agora gravaria um conjunto sem o painel da loja e
	 * sem o fórum, derrubando as duas áreas de uma vez. `wp_loaded` é o primeiro
	 * gancho em que todo `init` já passou.
	 *
	 * @return void
	 */
	private static function reescrever_permalinks() {
		add_action( 'wp_loaded', 'flush_rewrite_rules' );
	}

	/**
	 * Renomeia a página da loja do WooCommerce, e o que aponta para ela, para "Produtos".
	 *
	 * A página passou a ser o catálogo inteiro, com filtros, e "Loja" passou a
	 * ser o atalho da loja do usuário para a própria vitrine
	 * (`Reconectar_Navegacao_Da_Loja::acrescentar_item_loja_ao_menu()`). Dois itens
	 * "Loja" lado a lado, para destinos diferentes, seria o pior dos rótulos.
	 *
	 * Só o **texto** muda, e só quando ainda é o de fábrica — "Loja", ou "Shop"
	 * para a página nascida em inglês (armadilha do slug das páginas do
	 * WooCommerce no CLAUDE.md). Um rótulo que o administrador trocou pelo painel
	 * é escolha dele e fica. O slug não muda: `/shop/` e `/loja/` estão em links
	 * já compartilhados.
	 *
	 * A página é achada pela opção que guarda o ID, o item de menu pelo
	 * `object_id`, e o link do rodapé pelo caminho do `href` — nunca pelo rótulo
	 * sozinho, que é justamente o que se está trocando. Numa instalação nova a
	 * migração pode rodar antes de o WooCommerce criar a página; o
	 * `provision.sh` já nasce com o rótulo novo e cobre esse caso.
	 *
	 * @return void
	 */
	public static function renomear_loja_para_produtos() {
		$pagina = (int) get_option( 'woocommerce_shop_page_id' );

		if ( $pagina <= 0 || ! get_post( $pagina ) ) {
			return;
		}

		$rotulos_de_fabrica = array( 'Loja', 'Shop' );

		if ( in_array( get_post_field( 'post_title', $pagina ), $rotulos_de_fabrica, true ) ) {
			wp_update_post(
				array(
					'ID'         => $pagina,
					'post_title' => 'Produtos',
				)
			);
		}

		$itens = get_posts(
			array(
				'post_type'      => 'nav_menu_item',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- roda uma vez por instalação.
					array(
						'key'   => '_menu_item_object_id',
						'value' => (string) $pagina,
					),
					array(
						'key'   => '_menu_item_type',
						'value' => 'post_type',
					),
				),
			)
		);

		foreach ( $itens as $item ) {
			if ( in_array( $item->post_title, $rotulos_de_fabrica, true ) ) {
				wp_update_post(
					array(
						'ID'         => $item->ID,
						'post_title' => 'Produtos',
					)
				);
			}
		}

		$caminho  = Reconectar_Permissoes::caminho_de_url( get_permalink( $pagina ) );
		$widgets  = get_option( 'widget_custom_html' );
		$alterado = false;

		if ( '' === $caminho || ! is_array( $widgets ) ) {
			return;
		}

		foreach ( $widgets as $chave => $widget ) {
			if ( ! is_array( $widget ) || empty( $widget['content'] ) ) {
				continue;
			}

			$novo = preg_replace_callback(
				'#(<a\s[^>]*href="([^"]*)"[^>]*>)\s*(Loja|Shop)\s*(</a>)#',
				static function ( $casamento ) use ( $caminho ) {
					return Reconectar_Permissoes::caminho_de_url( $casamento[2] ) === $caminho
						? $casamento[1] . 'Produtos' . $casamento[4]
						: $casamento[0];
				},
				$widget['content']
			);

			if ( is_string( $novo ) && $novo !== $widget['content'] ) {
				$widgets[ $chave ]['content'] = $novo;
				$alterado                     = true;
			}
		}

		if ( $alterado ) {
			update_option( 'widget_custom_html', $widgets );
		}
	}

	/**
	 * Apaga as metas de contagem do esqueleto binário das enquetes.
	 *
	 * `_reconectar_votos_favor` e `_reconectar_votos_contra` vieram do MVP em que
	 * a proposta era a favor ou contra. A enquete passou a ter alternativas
	 * cadastradas pelo moderador, e o placar deixou de ser um par de contadores:
	 * hoje sai do mapa de votantes, recalculado a cada leitura.
	 *
	 * **Não é conversão de dados, e não precisa ser.** Nenhum produtor jamais
	 * gravou nessas chaves — `grep` no repositório inteiro só encontra leitura, no
	 * painel de transparência —, e a carga de demonstração nunca criou proposta.
	 * Não há voto antigo a preservar; há duas chaves mortas a remover, para que a
	 * próxima leitura do banco não sugira um formato que o código não fala mais.
	 *
	 * A ressalva do `CLAUDE.md` sobre cache — colher os IDs antes do `UPDATE` e
	 * chamar `clean_user_cache()` — é da **usermeta** e não se aplica aqui:
	 * `delete_post_meta_by_key()` invalida o cache de post por conta própria.
	 *
	 * @return void
	 */
	public static function descartar_contagem_binaria_das_enquetes() {
		delete_post_meta_by_key( '_reconectar_votos_favor' );
		delete_post_meta_by_key( '_reconectar_votos_contra' );
	}

	/**
	 * Põe os perfis administrativos no papel de fórum que a moderação exige.
	 *
	 * `Reconectar_Permissoes::sincronizar_papel_no_forum()` cuida de quem mudar
	 * de papel daqui para frente; quem já está gravado no banco não passa por
	 * `set_user_role` nunca mais. É a assimetria de sempre: o gancho resolve o
	 * futuro, a migração resolve o passado.
	 *
	 * Roda antes das capacidades (`init` 5 contra 10) e isso não é problema: o
	 * papel do WordPress pode ainda ser o da versão anterior, mas a chave dele
	 * — `content_moderator`, `company_admin` — não muda, e é só por ela que a
	 * consulta filtra.
	 *
	 * @return void
	 */
	public static function promover_moderadores_no_forum() {
		if ( ! function_exists( 'bbp_set_user_role' ) ) {
			return;
		}

		$usuarios = get_users(
			array(
				'role__in' => array(
					Reconectar_Permissoes::PAPEL_MODERADOR,
					Reconectar_Permissoes::PAPEL_ADMIN_EMPRESAS,
				),
				'fields'   => 'ID',
			)
		);

		foreach ( $usuarios as $usuario_id ) {
			Reconectar_Permissoes::aplicar_moderacao_no_forum( (int) $usuario_id );
		}
	}

	/**
	 * Move as linhas de `_reconectar_vendedor_ativo` para `_reconectar_loja_ativa`.
	 *
	 * São três passos, e a ordem entre eles importa:
	 *
	 * 1. Apagar as linhas legadas de quem **já** tem a chave nova. Sem isso, o
	 *    `UPDATE` do passo 2 criaria uma segunda linha com a mesma `meta_key`
	 *    para o mesmo usuário, e `get_user_meta( …, true )` passaria a devolver
	 *    uma das duas arbitrariamente — uma loja desativada voltaria a vender
	 *    dependendo da ordem de leitura do MySQL.
	 * 2. Renomear o restante.
	 * 3. Limpar o cache de usermeta dos usuários afetados. O WordPress guarda a
	 *    meta do usuário em cache de objeto por requisição (e, com cache
	 *    persistente, entre requisições): um `UPDATE` feito por baixo do
	 *    `update_user_meta()` não invalida nada, e a leitura seguinte entregaria
	 *    a chave antiga — que já não existe — como ausente, fazendo toda loja
	 *    parecer ativa até o cache expirar.
	 *
	 * Os IDs do passo 3 são colhidos **antes** do `UPDATE`: depois dele a chave
	 * antiga não existe mais, e a consulta voltaria vazia.
	 *
	 * @global wpdb $wpdb
	 * @return void
	 */
	public static function renomear_meta_de_loja_ativa() {
		global $wpdb;

		$antiga = Reconectar_Empresa::META_LOJA_ATIVA_LEGADA;
		$nova   = Reconectar_Empresa::META_LOJA_ATIVA;

		$afetados = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$antiga
			)
		);

		if ( ! $afetados ) {
			return;
		}

		// Passo 1: quem já tem a chave nova perde a legada, sem virar duplicata.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE legada FROM {$wpdb->usermeta} AS legada
				 INNER JOIN {$wpdb->usermeta} AS atual
				         ON atual.user_id = legada.user_id AND atual.meta_key = %s
				 WHERE legada.meta_key = %s",
				$nova,
				$antiga
			)
		);

		// Passo 2: o restante muda de nome, preservando o valor.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->usermeta} SET meta_key = %s WHERE meta_key = %s",
				$nova,
				$antiga
			)
		);

		// Passo 3: ver o PHPDoc — sem isto, o valor migrado fica invisível.
		foreach ( $afetados as $usuario_id ) {
			clean_user_cache( (int) $usuario_id );
		}
	}
}
