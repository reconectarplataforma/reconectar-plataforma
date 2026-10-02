<?php
/**
 * Verificação das operações de escrita da Incubadora, por WP-CLI.
 *
 * Complementa o bloco HTTP de `verificar-acessos.sh`, que só alcança as
 * recusas: o nonce que o WP-CLI gera não vale no navegador (o token de sessão
 * entra na conta e no CLI é vazio), então o caminho feliz não é exercitável
 * por `curl`. Aqui as operações são chamadas direto, sem o handler — que
 * encerraria o processo em `wp_send_json()`.
 *
 * Roda sempre com `wp --user=<login> eval-file`, nunca com
 * `wp_set_current_user()`: o papel dinâmico do bbPress só se aplica no `init`,
 * e o `eval` chega depois dele (armadilha registrada no `CLAUDE.md`).
 *
 * - Com a capacidade, cria uma árvore de teste, exercita salvar, conflito,
 *   publicação, teto de profundidade e exclusão, e apaga tudo no fim.
 * - Sem ela, confere que as três operações recusam pela segunda camada — a
 *   que vale se a primeira, a do handler, regredir.
 *
 * Cada linha sai como `::ok rótulo` ou `::FALHA rótulo`, o formato que o
 * script de acessos já lê.
 *
 * Uso:
 *   docker compose run --rm wpcli wp --user=demo-moderador eval-file /var/www/scripts/verificar-incubadora.php
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Reconectar_Incubadora_Acoes' ) ) {
	echo "::FALHA classe-de-acoes-ausente\n";
	return;
}

/**
 * Imprime o resultado de um caso.
 *
 * @param bool   $condicao Resultado.
 * @param string $rotulo   Descrição, sem espaços (o leitor do shell separa no primeiro).
 * @return void
 */
function reconectar_verificar_incubadora_caso( $condicao, $rotulo ) {
	printf( "::%s %s\n", $condicao ? 'ok' : 'FALHA', $rotulo );
}

/**
 * Diz se o retorno de uma operação traz o código esperado.
 *
 * @param array|WP_Error $resultado Retorno da operação.
 * @param string         $codigo    Código esperado.
 * @return bool
 */
function reconectar_verificar_incubadora_codigo( $resultado, $codigo ) {
	if ( is_wp_error( $resultado ) ) {
		return $resultado->get_error_code() === $codigo;
	}

	return isset( $resultado['codigo'] ) && $resultado['codigo'] === $codigo;
}

$rc_caso   = 'reconectar_verificar_incubadora_caso';
$rc_codigo = 'reconectar_verificar_incubadora_codigo';

if ( ! current_user_can( Reconectar_Permissoes::CAP_GERIR_INCUBADORA ) ) {
	$rc_criada = Reconectar_Incubadora_Acoes::criar( array( 'titulo' => 'Forjada' ) );
	$rc_caso( $rc_codigo( $rc_criada, 'capacidade' ), 'sem-capacidade-nao-cria' );

	if ( ! is_wp_error( $rc_criada ) ) {
		wp_delete_post( $rc_criada['id'], true );
	}

	$rc_alvo = get_posts(
		array(
			'post_type'   => Reconectar_Incubadora::POST_TYPE,
			'post_status' => array( 'publish', 'draft' ),
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);

	if ( $rc_alvo ) {
		$rc_antes = get_post_field( 'post_content', $rc_alvo[0] );

		$rc_caso( $rc_codigo( Reconectar_Incubadora_Acoes::salvar( array( 'pagina' => $rc_alvo[0], 'conteudo' => '<p>forjado</p>', 'forcar' => true ) ), 'capacidade' ), 'sem-capacidade-nao-salva' );
		$rc_caso( $rc_codigo( Reconectar_Incubadora_Acoes::excluir( $rc_alvo[0] ), 'capacidade' ), 'sem-capacidade-nao-exclui' );
		$rc_caso( get_post_field( 'post_content', $rc_alvo[0] ) === $rc_antes && 'trash' !== get_post_status( $rc_alvo[0] ), 'sem-capacidade-nada-mudou' );
	}

	return;
}

$rc_rotulo  = 'Verificação automática ' . time();
$rc_criados = array();

// A limpeza vai no desligamento, e não no fim do arquivo: um fatal no meio
// deixaria a árvore de teste na Incubadora de quem rodou a verificação.
register_shutdown_function(
	function () use ( &$rc_criados ) {
		foreach ( array_reverse( $rc_criados ) as $rc_id ) {
			wp_delete_post( $rc_id, true );
		}
	}
);

$rc_raiz = Reconectar_Incubadora_Acoes::criar( array( 'titulo' => $rc_rotulo ) );
$rc_caso( $rc_codigo( $rc_raiz, 'criada' ) && 'draft' === $rc_raiz['status'], 'cria-na-raiz-em-rascunho' );

if ( is_wp_error( $rc_raiz ) ) {
	return;
}

$rc_criados[] = $rc_raiz['id'];

$rc_filha = Reconectar_Incubadora_Acoes::criar(
	array(
		'titulo' => $rc_rotulo . ' filha',
		'mae'    => $rc_raiz['id'],
	)
);
$rc_caso( $rc_codigo( $rc_filha, 'criada' ) && (int) get_post_field( 'post_parent', $rc_filha['id'] ) === $rc_raiz['id'], 'cria-subpagina-sob-a-mae' );

if ( is_wp_error( $rc_filha ) ) {
	return;
}

$rc_criados[] = $rc_filha['id'];

// Um caso de cada classe do sanitizador: script removido, iframe de provedor
// convertido em marcador, imagem externa removida (rastreador, LGPD).
$rc_hostil = '<p>texto</p><script>alert(1)</script><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe><img src="https://rastreador.example/p.gif">';
$rc_salvo  = Reconectar_Incubadora_Acoes::salvar(
	array(
		'pagina'     => $rc_filha['id'],
		'conteudo'   => $rc_hostil,
		'modificado' => $rc_filha['modificado'],
	)
);
$rc_gravado = get_post_field( 'post_content', $rc_filha['id'] );

$rc_caso( $rc_codigo( $rc_salvo, 'salva' ), 'salva-o-conteudo' );
$rc_caso( false === stripos( $rc_gravado, '<script' ) && false === stripos( $rc_gravado, 'rastreador' ) && false !== strpos( $rc_gravado, 'data-rc-provedor="youtube"' ), 'grava-sanitizado-com-marcador-de-video' );
$rc_caso( ! is_wp_error( $rc_salvo ) && ! empty( $rc_salvo['avisos'] ), 'devolve-avisos-do-que-removeu' );
$rc_caso( (int) get_post_meta( $rc_filha['id'], '_edit_last', true ) === get_current_user_id(), 'grava-quem-editou-por-ultimo' );

if ( is_wp_error( $rc_salvo ) ) {
	return;
}

$rc_revisoes = count( wp_get_post_revisions( $rc_filha['id'] ) );
$rc_igual    = Reconectar_Incubadora_Acoes::salvar(
	array(
		'pagina'     => $rc_filha['id'],
		'conteudo'   => $rc_gravado,
		'modificado' => $rc_salvo['modificado'],
	)
);
$rc_caso( $rc_codigo( $rc_igual, 'sem_alteracoes' ) && count( wp_get_post_revisions( $rc_filha['id'] ) ) === $rc_revisoes, 'sem-alteracao-nao-gera-revisao' );

$rc_velho = Reconectar_Incubadora_Acoes::salvar(
	array(
		'pagina'     => $rc_filha['id'],
		'conteudo'   => '<p>outro</p>',
		'modificado' => '2000-01-01 00:00:00',
	)
);
$rc_caso( $rc_codigo( $rc_velho, 'conflito' ) && 409 === $rc_velho->get_error_data()['status'], 'versao-desatualizada-recebe-409' );
$rc_caso( '<p>outro</p>' !== get_post_field( 'post_content', $rc_filha['id'] ), 'conflito-nao-grava' );

$rc_forcado = Reconectar_Incubadora_Acoes::salvar(
	array(
		'pagina'     => $rc_filha['id'],
		'conteudo'   => '<p>outro</p>',
		'modificado' => '2000-01-01 00:00:00',
		'forcar'     => true,
	)
);
$rc_caso( $rc_codigo( $rc_forcado, 'salva' ), 'forcar-sobrescreve-o-conflito' );

$rc_vazio = Reconectar_Incubadora_Acoes::salvar(
	array(
		'pagina' => $rc_filha['id'],
		'titulo' => '   ',
		'forcar' => true,
	)
);
$rc_caso( $rc_codigo( $rc_vazio, 'titulo_vazio' ), 'recusa-titulo-vazio' );

$rc_publicada = Reconectar_Incubadora_Acoes::salvar(
	array(
		'pagina'     => $rc_raiz['id'],
		'titulo'     => $rc_rotulo . ' publicada',
		'publicar'   => true,
		'modificado' => $rc_raiz['modificado'],
	)
);
$rc_caso( $rc_codigo( $rc_publicada, 'publicada' ) && 'publish' === get_post_status( $rc_raiz['id'] ) && get_post_field( 'post_name', $rc_raiz['id'] ) === sanitize_title( $rc_rotulo . ' publicada' ), 'publica-e-refaz-o-slug' );
$rc_caso( ! is_wp_error( $rc_publicada ) && 0 === strpos( $rc_publicada['url'], '/' ) && false === strpos( $rc_publicada['url'], '//' ), 'devolve-url-como-caminho' );

$rc_com_filha = Reconectar_Incubadora_Acoes::excluir( $rc_raiz['id'] );
$rc_caso( $rc_codigo( $rc_com_filha, 'tem_filhas' ) && 'publish' === get_post_status( $rc_raiz['id'] ), 'nao-exclui-pagina-com-subpaginas' );

// Escada até o teto: a raiz tem 0 ancestrais; a página com
// `PROFUNDIDADE_MAXIMA` ancestrais não pode ter filha.
$rc_mae = $rc_raiz['id'];

for ( $rc_nivel = 1; $rc_nivel <= Reconectar_Incubadora::PROFUNDIDADE_MAXIMA; $rc_nivel++ ) {
	$rc_degrau = Reconectar_Incubadora_Acoes::criar(
		array(
			'titulo' => $rc_rotulo . ' nível ' . $rc_nivel,
			'mae'    => $rc_mae,
		)
	);

	if ( is_wp_error( $rc_degrau ) ) {
		break;
	}

	$rc_criados[] = $rc_degrau['id'];
	$rc_mae       = $rc_degrau['id'];
}

$rc_alem = Reconectar_Incubadora_Acoes::criar(
	array(
		'titulo' => $rc_rotulo . ' além',
		'mae'    => $rc_mae,
	)
);
$rc_caso( Reconectar_Incubadora::PROFUNDIDADE_MAXIMA === count( get_post_ancestors( $rc_mae ) ) && $rc_codigo( $rc_alem, 'profundidade' ), 'recusa-alem-do-teto-de-niveis' );

if ( ! is_wp_error( $rc_alem ) ) {
	$rc_criados[] = $rc_alem['id'];
}

$rc_outro_tipo = get_posts(
	array(
		'post_type'   => 'page',
		'numberposts' => 1,
		'fields'      => 'ids',
	)
);

if ( $rc_outro_tipo ) {
	$rc_antes = get_post_field( 'post_content', $rc_outro_tipo[0] );
	$rc_fora  = Reconectar_Incubadora_Acoes::salvar(
		array(
			'pagina'   => $rc_outro_tipo[0],
			'conteudo' => '<p>x</p>',
			'forcar'   => true,
		)
	);
	$rc_caso( $rc_codigo( $rc_fora, 'inexistente' ) && get_post_field( 'post_content', $rc_outro_tipo[0] ) === $rc_antes, 'nao-grava-em-post-de-outro-tipo' );
}

$rc_excluida = Reconectar_Incubadora_Acoes::excluir( $rc_filha['id'] );
$rc_caso( $rc_codigo( $rc_excluida, 'excluida' ) && 'trash' === get_post_status( $rc_filha['id'] ), 'exclui-para-a-lixeira' );
$rc_caso(
	$rc_codigo(
		Reconectar_Incubadora_Acoes::salvar(
			array(
				'pagina' => $rc_filha['id'],
				'forcar' => true,
			)
		),
		'inexistente'
	),
	'nao-grava-na-lixeira'
);
