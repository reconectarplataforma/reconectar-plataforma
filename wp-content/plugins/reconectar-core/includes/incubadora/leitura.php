<?php
/**
 * A página aberta: título, linha de autor, metadados e conteúdo.
 *
 * Incluído por `shell.php`, que já abriu `$rc_pagina`. O `<h1>` daqui é o único
 * da tela: o tema tira o `storefront_page_header` nas telas da Incubadora, e o
 * título do site no cabeçalho não é `<h1>` fora da home.
 *
 * "Editado há N minutos" sai em `<time datetime>` com a data absoluta no
 * `title`: o relativo é o que se lê de relance, e o absoluto é o que se cita.
 * As duas datas vêm de `_gmt`, que não muda com o fuso do servidor. O
 * `incubadora.js` refaz o relativo a cada meio minuto, corrigido pela hora do
 * servidor em `data-rc-agora` — o relógio de quem lê pode estar errado.
 *
 * "Nova subpágina" e "Mover…" saem para quem pode escrever, e quem os liga é
 * o script da árvore, carregado só para essas pessoas.
 *
 * "Histórico" é link, e não botão: funciona sem script, e a lista tem endereço
 * próprio, que se abre em outra aba. Sai só para quem edita, pela razão de
 * `Reconectar_Incubadora_Leitura::pode_ver_historico()`.
 *
 * O vídeo em destaque (`destaque.php`) e a avaliação com os comentários
 * (`interacao.php`) ficam fora de `.rc-incubadora__conteudo`: o editor troca o
 * `innerHTML` daquele bloco, e nenhum dos dois é texto da página.
 *
 * Os botões nascem `hidden` e o JS os revela: sem script, "Editar" não faria
 * nada e "Copiar link" não copiaria. As duas regiões de aviso existem desde o
 * carregamento, vazias, porque leitor de tela só anuncia mudança em região
 * viva que já estava no DOM.
 *
 * @package reconectar-core
 *
 * @var WP_Post $rc_pagina
 */

defined( 'ABSPATH' ) || exit;

$rc_titulo     = get_the_title( $rc_pagina );
$rc_autor      = Reconectar_Incubadora_Leitura::nome_de_usuario( (int) $rc_pagina->post_author );
$rc_editor     = Reconectar_Incubadora_Leitura::nome_de_usuario( Reconectar_Incubadora_Leitura::editado_por( $rc_pagina ) );
// Pela data local, nunca pela GMT: `wp_insert_post()` grava `post_modified_gmt`
// zerado num rascunho novo, e `get_post_modified_time( 'U', true )` devolve
// `false` — medido, "Editado há 57 anos" numa página recém-criada.
$rc_modificado = (int) get_post_timestamp( $rc_pagina, 'modified' );
$rc_rascunho   = 'draft' === $rc_pagina->post_status;
// O link que sobrevive a mover a página: o permalink muda com a mãe, o ID não.
$rc_link       = add_query_arg(
	array(
		'post_type' => Reconectar_Incubadora::POST_TYPE,
		'p'         => $rc_pagina->ID,
	),
	wp_parse_url( home_url( '/' ), PHP_URL_PATH )
);
?>
<article class="rc-incubadora__pagina" aria-labelledby="rc-incubadora-titulo" data-rc-agora="<?php echo esc_attr( gmdate( 'c' ) ); ?>">
	<header class="rc-incubadora__cabecalho">
		<?php if ( $rc_rascunho ) : ?>
			<p class="rc-incubadora__selo rc-incubadora__selo--destaque"><?php esc_html_e( 'Rascunho — visível só para quem edita a Incubadora', 'reconectar-core' ); ?></p>
		<?php endif; ?>

		<div class="rc-incubadora__acoes">
			<?php if ( Reconectar_Incubadora_Editor::deve_carregar() ) : ?>
				<button type="button" class="rc-incubadora__botao rc-incubadora__botao--primario" data-rc-incubadora="editar" hidden><?php esc_html_e( 'Editar', 'reconectar-core' ); ?></button>
				<button type="button" class="rc-incubadora__botao" data-rc-incubadora="criar" data-rc-mae="<?php echo esc_attr( $rc_pagina->ID ); ?>" hidden><?php esc_html_e( 'Nova subpágina', 'reconectar-core' ); ?></button>
				<button type="button" class="rc-incubadora__botao" data-rc-incubadora="mover" data-rc-pagina="<?php echo esc_attr( $rc_pagina->ID ); ?>" aria-haspopup="dialog" hidden><?php esc_html_e( 'Mover…', 'reconectar-core' ); ?></button>
				<a class="rc-incubadora__botao" href="<?php echo esc_url( Reconectar_Incubadora_Leitura::url_de_historico( $rc_pagina, array( Reconectar_Incubadora_Leitura::PARAM_HISTORICO => 1 ) ) ); ?>"><?php esc_html_e( 'Histórico', 'reconectar-core' ); ?></a>
			<?php endif; ?>
			<button type="button" class="rc-incubadora__botao" data-rc-incubadora="compartilhar" data-rc-link="<?php echo esc_attr( $rc_link ); ?>" data-rc-copiado="<?php esc_attr_e( 'Link da página copiado.', 'reconectar-core' ); ?>" data-rc-falhou="<?php esc_attr_e( 'Não foi possível copiar. O link é:', 'reconectar-core' ); ?>" hidden><?php esc_html_e( 'Copiar link', 'reconectar-core' ); ?></button>
		</div>
		<p class="rc-incubadora__status" role="status"></p>
		<div class="rc-incubadora__alerta" role="alert"></div>

		<h1 class="rc-incubadora__titulo" id="rc-incubadora-titulo"><?php echo esc_html( '' !== $rc_titulo ? $rc_titulo : __( '(sem título)', 'reconectar-core' ) ); ?></h1>

		<p class="rc-incubadora__autoria">
			<?php
			/* translators: %s: nome de quem criou a página. */
			echo esc_html( sprintf( __( 'Criada por %s', 'reconectar-core' ), $rc_autor ) );
			?>
			<span aria-hidden="true">·</span>
			<time class="rc-incubadora__editado" data-rc-modelo="<?php /* translators: %s: tempo relativo, como "há 5 minutos". */ esc_attr_e( 'Editado %s', 'reconectar-core' ); ?>" datetime="<?php echo esc_attr( gmdate( 'c', $rc_modificado ) ); ?>" title="<?php echo esc_attr( Reconectar_Incubadora_Leitura::data_local( $rc_pagina, 'post_modified' ) ); ?>">
				<?php
				/* translators: %s: intervalo, como "5 minutos". */
				echo esc_html( sprintf( __( 'Editado há %s', 'reconectar-core' ), human_time_diff( $rc_modificado, time() ) ) );
				?>
			</time>
		</p>

		<table class="rc-incubadora__metadados">
			<caption class="screen-reader-text"><?php esc_html_e( 'Dados da página', 'reconectar-core' ); ?></caption>
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Autor', 'reconectar-core' ); ?></th>
					<td><?php echo esc_html( $rc_autor ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Criada em', 'reconectar-core' ); ?></th>
					<td><?php echo esc_html( Reconectar_Incubadora_Leitura::data_local( $rc_pagina, 'post_date' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Última edição', 'reconectar-core' ); ?></th>
					<td class="rc-incubadora__ultima-edicao" data-rc-modelo="<?php /* translators: 1: data, 2: nome de quem editou. */ esc_attr_e( '%1$s, por %2$s', 'reconectar-core' ); ?>">
						<?php
						/* translators: 1: data, 2: nome de quem editou. */
						echo esc_html( sprintf( __( '%1$s, por %2$s', 'reconectar-core' ), Reconectar_Incubadora_Leitura::data_local( $rc_pagina, 'post_modified' ), $rc_editor ) );
						?>
					</td>
				</tr>
			</tbody>
		</table>
	</header>

	<?php include __DIR__ . '/destaque.php'; ?>

	<div class="rc-incubadora__conteudo">
		<?php
		$rc_html = Reconectar_Incubadora_Leitura::conteudo( $rc_pagina );

		if ( '' === trim( $rc_html ) ) :
			?>
			<p class="rc-incubadora__vazio"><?php esc_html_e( 'Esta página ainda não tem conteúdo.', 'reconectar-core' ); ?></p>
			<?php
		else :
			echo $rc_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- reconstruído e sanitizado em `conteudo()`.
		endif;
		?>
	</div>

	<?php include __DIR__ . '/interacao.php'; ?>
</article>
