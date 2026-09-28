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
	 */
	const VERSAO = 1;

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

		update_option( self::OPCAO_VERSAO, self::VERSAO );
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
