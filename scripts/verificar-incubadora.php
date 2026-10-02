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
 *   publicação, teto de profundidade, mover, envio de arquivo, histórico de
 *   versões e exclusão, e
 *   apaga tudo no fim — os arquivos enviados inclusive.
 * - Sem ela, confere que as cinco operações recusam pela segunda camada — a
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

	// O alvo é provisório, criado direto por `wp_insert_post()` — que não
	// consulta capacidade — e não uma página qualquer do banco: numa
	// Incubadora vazia os quatro casos abaixo seriam pulados em silêncio, e a
	// contagem do `verificar-acessos.sh` mudaria conforme o estado do banco.
	$rc_alvo = array(
		wp_insert_post(
			array(
				'post_type'    => Reconectar_Incubadora::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Verificação automática sem capacidade ' . time(),
				'post_content' => '<p>original</p>',
			)
		),
	);

	register_shutdown_function(
		static function () use ( $rc_alvo ) {
			wp_delete_post( $rc_alvo[0], true );
		}
	);

	if ( $rc_alvo[0] ) {
		$rc_antes = get_post_field( 'post_content', $rc_alvo[0] );

		$rc_caso( $rc_codigo( Reconectar_Incubadora_Acoes::salvar( array( 'pagina' => $rc_alvo[0], 'conteudo' => '<p>forjado</p>', 'forcar' => true ) ), 'capacidade' ), 'sem-capacidade-nao-salva' );
		$rc_caso( $rc_codigo( Reconectar_Incubadora_Acoes::excluir( $rc_alvo[0] ), 'capacidade' ), 'sem-capacidade-nao-exclui' );
		$rc_caso( $rc_codigo( Reconectar_Incubadora_Arquivos::guardar( get_post( $rc_alvo[0] ), __FILE__, 'a.png' ), 'capacidade' ), 'sem-capacidade-nao-anexa' );

		// A versão vem direto de `wp_save_post_revision()`, que não consulta
		// capacidade: sem ela o caso pararia na versão inexistente, e não na
		// trava que se quer medir.
		$rc_versao_alvo = (int) wp_save_post_revision( $rc_alvo[0] );
		$rc_caso( $rc_versao_alvo && $rc_codigo( Reconectar_Incubadora_Acoes::restaurar( array( 'pagina' => $rc_alvo[0], 'versao' => $rc_versao_alvo, 'modificado' => get_post_field( 'post_modified_gmt', $rc_alvo[0] ) ) ), 'capacidade' ), 'sem-capacidade-nao-restaura' );

		$rc_mae_antes = (int) get_post_field( 'post_parent', $rc_alvo[0] );
		$rc_caso( $rc_codigo( Reconectar_Incubadora_Acoes::mover( array( 'pagina' => $rc_alvo[0], 'pai' => 0, 'ordem' => array( $rc_alvo[0] ) ) ), 'capacidade' ), 'sem-capacidade-nao-move' );
		$rc_caso( get_post_field( 'post_content', $rc_alvo[0] ) === $rc_antes && 'trash' !== get_post_status( $rc_alvo[0] ) && (int) get_post_field( 'post_parent', $rc_alvo[0] ) === $rc_mae_antes, 'sem-capacidade-nada-mudou' );
	} else {
		$rc_caso( false, 'sem-capacidade-alvo-provisorio' );
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

/*
 * Mover. Neste ponto a árvore de teste é:
 *
 *   raiz
 *   ├── filha
 *   └── nível 1 › nível 2 › … › nível 8
 *
 * O primeiro degrau da escada é o primeiro ID depois da filha em `$rc_criados`.
 */
$rc_degraus = array_slice( $rc_criados, 2, Reconectar_Incubadora::PROFUNDIDADE_MAXIMA );
$rc_nivel1  = $rc_degraus[0];
$rc_ultimo  = end( $rc_degraus );

/**
 * Lê `menu_order` e `post_parent` direto do banco, sem o cache de objeto.
 *
 * @param int $id ID.
 * @return array{0: int, 1: int}
 */
function reconectar_verificar_incubadora_lugar( $id ) {
	global $wpdb;

	$linha = $wpdb->get_row( $wpdb->prepare( "SELECT post_parent, menu_order FROM {$wpdb->posts} WHERE ID = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	return array( (int) $linha->post_parent, (int) $linha->menu_order );
}

$rc_lugar          = 'reconectar_verificar_incubadora_lugar';
$rc_modificado     = get_post_field( 'post_modified_gmt', $rc_filha['id'] );
$rc_revisoes_filha = count( wp_get_post_revisions( $rc_filha['id'] ) );

$rc_reordena = Reconectar_Incubadora_Acoes::mover(
	array(
		'pagina' => $rc_filha['id'],
		'pai'    => $rc_raiz['id'],
		'ordem'  => array( $rc_nivel1, $rc_filha['id'] ),
	)
);
$rc_caso( $rc_codigo( $rc_reordena, 'movida' ) && array( $rc_raiz['id'], 1 ) === $rc_lugar( $rc_filha['id'] ) && array( $rc_raiz['id'], 0 ) === $rc_lugar( $rc_nivel1 ), 'reordena-entre-irmas' );
$rc_caso( get_post_field( 'post_modified_gmt', $rc_filha['id'] ) === $rc_modificado && count( wp_get_post_revisions( $rc_filha['id'] ) ) === $rc_revisoes_filha, 'mover-nao-gera-revisao-nem-muda-a-data' );

$rc_caso( $rc_codigo( Reconectar_Incubadora_Acoes::mover( array( 'pagina' => $rc_raiz['id'], 'pai' => $rc_raiz['id'], 'ordem' => array( $rc_raiz['id'] ) ) ), 'pai_invalido' ), 'recusa-mover-para-dentro-de-si' );
$rc_caso( $rc_codigo( Reconectar_Incubadora_Acoes::mover( array( 'pagina' => $rc_raiz['id'], 'pai' => $rc_ultimo, 'ordem' => array( $rc_raiz['id'] ) ) ), 'pai_invalido' ) && 0 === $rc_lugar( $rc_raiz['id'] )[0], 'recusa-mover-para-dentro-de-descendente' );

// O nível 1 tem sete níveis abaixo; sob a filha (um ancestral), ele ficaria
// com dois, e a ponta da escada com nove.
$rc_fundo = Reconectar_Incubadora_Acoes::mover(
	array(
		'pagina' => $rc_nivel1,
		'pai'    => $rc_filha['id'],
		'ordem'  => array( $rc_nivel1 ),
	)
);
$rc_caso( $rc_codigo( $rc_fundo, 'profundidade' ) && $rc_raiz['id'] === $rc_lugar( $rc_nivel1 )[0], 'recusa-subarvore-alem-do-teto' );

$rc_faltando = Reconectar_Incubadora_Acoes::mover(
	array(
		'pagina' => $rc_filha['id'],
		'pai'    => $rc_raiz['id'],
		'ordem'  => array( $rc_filha['id'] ),
	)
);
$rc_caso( $rc_codigo( $rc_faltando, 'conflito' ) && 409 === $rc_faltando->get_error_data()['status'], 'ordem-sem-uma-irma-recebe-409' );
$rc_caso( $rc_codigo( Reconectar_Incubadora_Acoes::mover( array( 'pagina' => $rc_filha['id'], 'pai' => $rc_raiz['id'], 'ordem' => array( $rc_filha['id'], $rc_filha['id'], $rc_nivel1 ) ) ), 'conflito' ), 'ordem-com-repeticao-recebe-409' );
$rc_caso( $rc_codigo( Reconectar_Incubadora_Acoes::mover( array( 'pagina' => $rc_filha['id'], 'pai' => $rc_raiz['id'], 'ordem' => array( $rc_nivel1, $rc_filha['id'], $rc_ultimo ) ) ), 'conflito' ), 'ordem-com-estranha-recebe-409' );

// Uma página sob a filha com o mesmo slug do nível 1: irmãs diferentes, então
// o slug é igual. Levada para a raiz, ela passaria a dividir o endereço com
// o nível 1 se o slug não mudasse.
$rc_xara = Reconectar_Incubadora_Acoes::criar(
	array(
		'titulo' => get_the_title( $rc_nivel1 ),
		'mae'    => $rc_filha['id'],
	)
);

if ( ! is_wp_error( $rc_xara ) ) {
	$rc_criados[] = $rc_xara['id'];
	$rc_slug_xara = get_post_field( 'post_name', $rc_xara['id'] );

	$rc_sobe = Reconectar_Incubadora_Acoes::mover(
		array(
			'pagina' => $rc_xara['id'],
			'pai'    => $rc_raiz['id'],
			'ordem'  => array( $rc_xara['id'], $rc_nivel1, $rc_filha['id'] ),
		)
	);
	$rc_caso( get_post_field( 'post_name', $rc_nivel1 ) === $rc_slug_xara && $rc_codigo( $rc_sobe, 'movida' ) && array( $rc_raiz['id'], 0 ) === $rc_lugar( $rc_xara['id'] ), 'muda-de-mae-no-inicio-da-lista' );
	$rc_caso( get_post_field( 'post_name', $rc_xara['id'] ) !== get_post_field( 'post_name', $rc_nivel1 ) && get_permalink( $rc_xara['id'] ) !== get_permalink( $rc_nivel1 ), 'slug-colidente-ganha-sufixo' );
	$rc_caso( array( $rc_raiz['id'], 1 ) === $rc_lugar( $rc_nivel1 ) && array( $rc_raiz['id'], 2 ) === $rc_lugar( $rc_filha['id'] ), 'renumera-as-irmas-do-destino' );

	$rc_volta = Reconectar_Incubadora_Acoes::mover(
		array(
			'pagina' => $rc_xara['id'],
			'pai'    => 0,
			'ordem'  => array_merge( wp_list_pluck( get_posts( array( 'post_type' => Reconectar_Incubadora::POST_TYPE, 'post_status' => array( 'publish', 'draft' ), 'post_parent' => 0, 'numberposts' => -1, 'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC' ) ) ), 'ID' ), array( $rc_xara['id'] ) ),
		)
	);
	$rc_caso( $rc_codigo( $rc_volta, 'movida' ) && 0 === $rc_lugar( $rc_xara['id'] )[0], 'move-para-a-raiz' );
}

// Por último: `mapa_visivel()` guarda o mapa por requisição, e chamá-lo antes
// congelaria a árvore dos casos acima.
$rc_fragmentos = Reconectar_Incubadora_Leitura::fragmentos( $rc_filha['id'], array() );
$rc_caso( false !== strpos( $rc_fragmentos['arvore'], 'data-rc-id="' . $rc_filha['id'] . '"' ) && false !== strpos( $rc_fragmentos['trilha'], 'aria-current="page"' ), 'fragmentos-trazem-arvore-e-trilha' );

// Arquivos. `guardar()` recebe caminho, e não `$_FILES`, justamente para ser
// medível aqui; `is_uploaded_file()` fica no handler, e o envio de verdade
// pelo navegador é medido no Chrome headless.
$rc_arquivos = array();
$rc_temp     = trailingslashit( get_temp_dir() ) . 'rc-verificacao-' . wp_generate_password( 8, false );
wp_mkdir_p( $rc_temp );

register_shutdown_function(
	function () use ( &$rc_arquivos, $rc_temp ) {
		$rc_pasta = Reconectar_Incubadora_Arquivos::diretorio();

		foreach ( $rc_arquivos as $rc_nome ) {
			wp_delete_file( trailingslashit( $rc_pasta ) . $rc_nome );
		}

		foreach ( (array) glob( $rc_temp . '/*' ) as $rc_temporario ) {
			wp_delete_file( $rc_temporario );
		}

		rmdir( $rc_temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
);

/**
 * Grava uma imagem de teste pelo GD.
 *
 * @param string $caminho Destino.
 * @param string $tipo    `png`, `jpeg` ou `gif`.
 * @param int    $largura Largura.
 * @param int    $altura  Altura.
 * @return string O caminho.
 */
function reconectar_verificar_incubadora_imagem( $caminho, $tipo, $largura = 40, $altura = 30 ) {
	$imagem = imagecreatetruecolor( $largura, $altura );
	imagefill( $imagem, 0, 0, imagecolorallocate( $imagem, 49, 190, 177 ) );
	call_user_func( 'image' . $tipo, $imagem, $caminho );
	imagedestroy( $imagem );

	return $caminho;
}

/**
 * Envia um arquivo de teste à página e registra o nome para a limpeza.
 *
 * @param WP_Post  $pagina    Página.
 * @param string   $caminho   Arquivo.
 * @param string   $nome      Nome declarado.
 * @param string[] $arquivos  Lista de nomes gravados, por referência.
 * @return array|WP_Error
 */
function reconectar_verificar_incubadora_enviar( $pagina, $caminho, $nome, &$arquivos ) {
	$resultado = Reconectar_Incubadora_Arquivos::guardar( $pagina, $caminho, $nome );

	if ( ! is_wp_error( $resultado ) ) {
		$arquivos[] = $resultado['arquivo'];
	}

	return $resultado;
}

$rc_imagem  = 'reconectar_verificar_incubadora_imagem';
$rc_pagina  = get_post( $rc_filha['id'] );
$rc_pasta   = Reconectar_Incubadora_Arquivos::diretorio();

$rc_png = reconectar_verificar_incubadora_enviar( $rc_pagina, $rc_imagem( $rc_temp . '/a.png', 'png' ), 'Foto da feira.png', $rc_arquivos );
$rc_caso(
	$rc_codigo( $rc_png, 'enviado' )
	&& preg_match( Reconectar_Incubadora_Conteudo::PADRAO_IMAGEM, $rc_png['arquivo'] )
	&& '.png' === substr( $rc_png['arquivo'], -4 )
	&& is_file( $rc_pasta . '/' . $rc_png['arquivo'] )
	&& Reconectar_Incubadora_Conteudo::url_de_arquivo( $rc_png['arquivo'] ) === $rc_png['url'],
	'arquivo-png-valido-e-gravado'
);
$rc_caso( is_file( $rc_pasta . '/.htaccess' ) && false !== strpos( (string) file_get_contents( $rc_pasta . '/.htaccess' ), 'Require all denied' ) && is_file( $rc_pasta . '/index.php' ), 'arquivo-pasta-protegida' );

$rc_registros = get_post_meta( $rc_pagina->ID, Reconectar_Incubadora_Arquivos::META );
$rc_registro  = $rc_png && ! is_wp_error( $rc_png ) ? wp_list_filter( $rc_registros, array( 'arquivo' => $rc_png['arquivo'] ) ) : array();
$rc_registro  = $rc_registro ? reset( $rc_registro ) : array();
$rc_caso( $rc_registro && 'Foto da feira.png' === $rc_registro['nome'] && get_current_user_id() === (int) $rc_registro['enviado_por'], 'arquivo-registrado-na-pagina' );

// Um PNG chamado `.jpg` é gravado como `.png`: a extensão sai do conteúdo.
$rc_disfarcado = reconectar_verificar_incubadora_enviar( $rc_pagina, $rc_imagem( $rc_temp . '/b.png', 'png' ), 'disfarce.jpg', $rc_arquivos );
$rc_caso( ( $rc_codigo( $rc_disfarcado, 'enviado' ) && '.png' === substr( $rc_disfarcado['arquivo'], -4 ) ) || $rc_codigo( $rc_disfarcado, 'tipo' ), 'arquivo-extensao-sai-do-conteudo' );

// EXIF com marcador: a regravação tem de tirá-lo. O segmento APP1 vai logo
// depois do SOI, onde a câmera o põe.
$rc_jpeg  = $rc_imagem( $rc_temp . '/c.jpg', 'jpeg' );
$rc_tiff  = "II*\x00\x08\x00\x00\x00\x00\x00\x00\x00" . 'GPSMARCADORDETESTE';
$rc_app1  = "Exif\x00\x00" . $rc_tiff;
$rc_bytes = (string) file_get_contents( $rc_jpeg );
file_put_contents( $rc_jpeg, substr( $rc_bytes, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $rc_app1 ) + 2 ) . $rc_app1 . substr( $rc_bytes, 2 ) );
$rc_com_exif = reconectar_verificar_incubadora_enviar( $rc_pagina, $rc_jpeg, 'celular.jpg', $rc_arquivos );
$rc_caso( false !== strpos( (string) file_get_contents( $rc_jpeg ), 'GPSMARCADOR' ), 'arquivo-exif-de-teste-montado' );
$rc_caso(
	$rc_codigo( $rc_com_exif, 'enviado' )
	&& false === strpos( (string) file_get_contents( $rc_pasta . '/' . $rc_com_exif['arquivo'] ), 'GPSMARCADOR' ),
	'arquivo-jpeg-perde-o-exif'
);

$rc_grande = reconectar_verificar_incubadora_enviar( $rc_pagina, $rc_imagem( $rc_temp . '/d.png', 'png', 3000, 1200 ), 'grande.png', $rc_arquivos );
$rc_caso( $rc_codigo( $rc_grande, 'enviado' ) && 2000 === $rc_grande['largura'] && 800 === $rc_grande['altura'], 'arquivo-imagem-grande-reduzida' );

$rc_gif = reconectar_verificar_incubadora_enviar( $rc_pagina, $rc_imagem( $rc_temp . '/e.gif', 'gif' ), 'animacao.gif', $rc_arquivos );
$rc_caso( $rc_codigo( $rc_gif, 'enviado' ) && '.gif' === substr( $rc_gif['arquivo'], -4 ), 'arquivo-gif-aceito' );

file_put_contents( $rc_temp . '/f.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n" );
$rc_pdf = reconectar_verificar_incubadora_enviar( $rc_pagina, $rc_temp . '/f.pdf', 'Relatório final.pdf', $rc_arquivos );
$rc_caso( $rc_codigo( $rc_pdf, 'enviado' ) && '.pdf' === substr( $rc_pdf['arquivo'], -4 ) && ! $rc_pdf['imagem'], 'arquivo-pdf-valido' );
$rc_caso( ! is_wp_error( $rc_pdf ) && 'Relatório final.pdf' === $rc_pdf['nome'] && 'PDF, 77 B' === $rc_pdf['descricao'], 'arquivo-nome-conserva-acento' );

// O nome original vai ao texto do link e ao cabeçalho do download: caminho,
// marcação e aspas não podem sobreviver a ele.
$rc_hostil = reconectar_verificar_incubadora_enviar( $rc_pagina, $rc_temp . '/f.pdf', "..\\pasta/<b>x</b>Ata \"final\"\r\n.pdf", $rc_arquivos );
$rc_caso( ! is_wp_error( $rc_hostil ) && ! preg_match( '#[\\\\/<>"\r\n]#', $rc_hostil['nome'] ) && '.pdf' === substr( $rc_hostil['nome'], -4 ), 'arquivo-nome-hostil-limpo' );

file_put_contents( $rc_temp . '/g.png', "<?php echo 'invasao'; ?>" );
$rc_caso( $rc_codigo( reconectar_verificar_incubadora_enviar( $rc_pagina, $rc_temp . '/g.png', 'foto.png', $rc_arquivos ), 'tipo' ), 'arquivo-php-renomeado-recusado' );

file_put_contents( $rc_temp . '/h.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' );
$rc_caso( $rc_codigo( reconectar_verificar_incubadora_enviar( $rc_pagina, $rc_temp . '/h.svg', 'logo.svg', $rc_arquivos ), 'tipo' ), 'arquivo-svg-recusado' );

file_put_contents( $rc_temp . '/i.pdf', "não sou PDF\n" );
$rc_caso( $rc_codigo( reconectar_verificar_incubadora_enviar( $rc_pagina, $rc_temp . '/i.pdf', 'falso.pdf', $rc_arquivos ), 'tipo' ), 'arquivo-pdf-falso-recusado' );

$rc_teto = static function () {
	return 100;
};
add_filter( 'upload_size_limit', $rc_teto );
$rc_excesso = reconectar_verificar_incubadora_enviar( $rc_pagina, $rc_imagem( $rc_temp . '/j.png', 'png', 400, 400 ), 'pesada.png', $rc_arquivos );
remove_filter( 'upload_size_limit', $rc_teto );
$rc_caso( $rc_codigo( $rc_excesso, 'tamanho' ) && 413 === $rc_excesso->get_error_data()['status'], 'arquivo-acima-do-teto-recebe-413' );

$rc_caso( $rc_codigo( Reconectar_Incubadora_Arquivos::guardar( $rc_pagina, '', 'nada.png', UPLOAD_ERR_INI_SIZE ), 'tamanho' ), 'arquivo-erro-ini-size-vira-413' );

// O sanitizador tem de manter a imagem e o link da rota — e só eles.
if ( ! is_wp_error( $rc_png ) && ! is_wp_error( $rc_pdf ) ) {
	$rc_com_arquivos = Reconectar_Incubadora_Acoes::salvar(
		array(
			'pagina'   => $rc_filha['id'],
			'conteudo' => '<p><img src="' . $rc_png['url'] . '" alt="Feira" width="40" height="30"></p><p><a href="' . $rc_pdf['url'] . '">Relatório</a></p>',
			'forcar'   => true,
		)
	);
	$rc_gravado = get_post_field( 'post_content', $rc_filha['id'] );
	$rc_caso(
		$rc_codigo( $rc_com_arquivos, 'salva' ) && empty( $rc_com_arquivos['avisos'] )
		&& false !== strpos( $rc_gravado, 'arquivo=' . $rc_png['arquivo'] )
		&& false !== strpos( $rc_gravado, 'arquivo=' . $rc_pdf['arquivo'] ),
		'arquivo-imagem-e-link-sobrevivem-ao-sanitizador'
	);

	// A varredura só em simulação: com `false` ela apagaria também os órfãos
	// reais da instalação, que não são assunto de um script de verificação.
	// O arquivo antigo e não citado entra na lista; o citado só numa revisão,
	// não — apagá-lo faria a restauração devolver imagem quebrada.
	$rc_velho = $rc_gif['arquivo'] ?? '';
	if ( $rc_velho ) {
		touch( $rc_pasta . '/' . $rc_velho, time() - 2 * DAY_IN_SECONDS );
	}
	touch( $rc_pasta . '/' . $rc_png['arquivo'], time() - 2 * DAY_IN_SECONDS );
	Reconectar_Incubadora_Acoes::salvar(
		array(
			'pagina'   => $rc_filha['id'],
			'conteudo' => '<p>sem imagem</p>',
			'forcar'   => true,
		)
	);
	$rc_simulado = Reconectar_Incubadora_Arquivos::limpar_orfaos();
	$rc_caso( $rc_velho && in_array( $rc_velho, $rc_simulado, true ) && is_file( $rc_pasta . '/' . $rc_velho ), 'orfaos-simulacao-lista-sem-apagar' );
	$rc_caso( ! in_array( $rc_grande['arquivo'] ?? '', $rc_simulado, true ), 'orfaos-recente-fica-de-fora' );
	$rc_caso( ! in_array( $rc_png['arquivo'], $rc_simulado, true ), 'orfaos-revisao-segura-o-arquivo' );
} else {
	$rc_caso( false, 'arquivo-sem-png-ou-pdf-para-o-sanitizador' );
}

// --- Histórico ---------------------------------------------------------------

/**
 * Grava título e conteúdo na página, partindo do estado atual dela.
 *
 * @param int    $pagina   ID da página.
 * @param string $titulo   Título novo.
 * @param string $conteudo Conteúdo novo.
 * @return array|WP_Error
 */
function reconectar_verificar_incubadora_gravar( $pagina, $titulo, $conteudo ) {
	return Reconectar_Incubadora_Acoes::salvar(
		array(
			'pagina'     => $pagina,
			'titulo'     => $titulo,
			'conteudo'   => $conteudo,
			'modificado' => get_post_field( 'post_modified_gmt', $pagina ),
		)
	);
}

$rc_hist = $rc_filha['id'];
reconectar_verificar_incubadora_gravar( $rc_hist, $rc_rotulo . ' v1', '<p>primeira versão</p>' );
$rc_v1 = Reconectar_Incubadora_Leitura::versoes( get_post( $rc_hist ) );
$rc_v1 = $rc_v1 ? $rc_v1[0]['versao']->ID : 0;
reconectar_verificar_incubadora_gravar( $rc_hist, $rc_rotulo . ' v2', '<p>segunda versão</p>' );
$rc_versoes = Reconectar_Incubadora_Leitura::versoes( get_post( $rc_hist ) );
$rc_caso( $rc_versoes && $rc_versoes[0]['atual'] && 1 === count( array_filter( wp_list_pluck( $rc_versoes, 'atual' ) ) ), 'historico-uma-so-versao-atual' );

$rc_quantas    = count( wp_get_post_revisions( $rc_hist ) );
$rc_restaurada = Reconectar_Incubadora_Acoes::restaurar( array( 'pagina' => $rc_hist, 'versao' => $rc_v1, 'modificado' => get_post_field( 'post_modified_gmt', $rc_hist ) ) );
$rc_caso(
	$rc_codigo( $rc_restaurada, 'restaurada' )
	&& '<p>primeira versão</p>' === get_post_field( 'post_content', $rc_hist )
	&& $rc_rotulo . ' v1' === get_post_field( 'post_title', $rc_hist ),
	'historico-restaura-titulo-e-conteudo'
);
$rc_textos = wp_list_pluck( wp_get_post_revisions( $rc_hist ), 'post_content' );
$rc_caso( count( wp_get_post_revisions( $rc_hist ) ) > $rc_quantas && in_array( '<p>segunda versão</p>', $rc_textos, true ), 'historico-restaurar-e-desfazivel' );

$rc_caso( $rc_codigo( Reconectar_Incubadora_Acoes::restaurar( array( 'pagina' => $rc_hist, 'versao' => $rc_v1, 'modificado' => get_post_field( 'post_modified_gmt', $rc_hist ) ) ), 'sem_alteracoes' ), 'historico-restaurar-igual-nao-grava' );

$rc_conflito = Reconectar_Incubadora_Acoes::restaurar( array( 'pagina' => $rc_hist, 'versao' => $rc_v1, 'modificado' => '2000-01-01 00:00:00' ) );
$rc_caso( $rc_codigo( $rc_conflito, 'conflito' ) && 409 === $rc_conflito->get_error_data()['status'], 'historico-conflito-recebe-409' );

// Versão de outra página, e o ID da própria página no lugar de uma versão.
$rc_alheia = Reconectar_Incubadora_Acoes::restaurar( array( 'pagina' => $rc_raiz['id'], 'versao' => $rc_v1, 'modificado' => get_post_field( 'post_modified_gmt', $rc_raiz['id'] ) ) );
$rc_caso( $rc_codigo( $rc_alheia, 'versao_inexistente' ) && 404 === $rc_alheia->get_error_data()['status'], 'historico-versao-alheia-recebe-404' );
$rc_caso( $rc_codigo( Reconectar_Incubadora_Acoes::restaurar( array( 'pagina' => $rc_hist, 'versao' => $rc_hist, 'modificado' => get_post_field( 'post_modified_gmt', $rc_hist ) ) ), 'versao_inexistente' ), 'historico-pagina-nao-e-versao' );

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
