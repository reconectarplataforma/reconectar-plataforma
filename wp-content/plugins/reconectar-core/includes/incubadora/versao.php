<?php
/**
 * Uma versão antiga da página, no layout de leitura, com a faixa de aviso e "Restaurar".
 *
 * Incluído por `shell.php` com `$contexto['versao']` já conferido: é uma
 * revisão desta página, e quem vê pode restaurá-la.
 *
 * O `<h1>` é o título **da versão**, que pode ser diferente do de agora — é
 * parte do que se está conferindo. A faixa vem antes dele na ordem do DOM,
 * para que quem chega por leitor de tela saiba que o texto não é o atual
 * antes de lê-lo.
 *
 * "Restaurar" nasce `hidden` pelo motivo dos botões da leitura: sem script
 * ele não faria nada. Não pede confirmação porque é desfazível — a versão
 * atual fica no histórico, e restaurá-la é o mesmo botão na outra direção.
 *
 * O conteúdo passa por `html_de_leitura()`, que sanitiza de novo: a revisão
 * pode ter sido gravada antes de uma regra nova, ou pelo `/wp-admin`.
 *
 * @package reconectar-core
 *
 * @var WP_Post $rc_pagina
 * @var array   $contexto
 */

defined( 'ABSPATH' ) || exit;

$rc_versao = $contexto['versao'];
$rc_titulo = $rc_versao->post_title;
$rc_atual  = Reconectar_Incubadora_Leitura::versao_e_a_atual( $rc_versao, $rc_pagina );
$rc_html   = Reconectar_Incubadora_Conteudo::html_de_leitura( $rc_versao->post_content );
?>
<article class="rc-incubadora__pagina rc-incubadora__versao-antiga" aria-labelledby="rc-incubadora-titulo">
	<header class="rc-incubadora__cabecalho">
		<div class="rc-incubadora__faixa">
			<p>
				<strong>
					<?php
					if ( $rc_atual ) {
						esc_html_e( 'Esta versão é igual à página de agora.', 'reconectar-core' );
					} else {
						esc_html_e( 'Você está vendo uma versão antiga.', 'reconectar-core' );
					}
					?>
				</strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: data e hora, 2: nome de quem gravou. */
						__( 'Gravada em %1$s, por %2$s.', 'reconectar-core' ),
						Reconectar_Incubadora_Leitura::data_e_hora( $rc_versao ),
						Reconectar_Incubadora_Leitura::nome_de_usuario( (int) $rc_versao->post_author )
					)
				);
				?>
			</p>
			<?php if ( ! $rc_atual ) : ?>
				<p><?php esc_html_e( 'Restaurar põe este título e este conteúdo na página. O que está lá agora continua no histórico.', 'reconectar-core' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="rc-incubadora__acoes">
			<?php if ( ! $rc_atual ) : ?>
				<button type="button" class="rc-incubadora__botao rc-incubadora__botao--primario" data-rc-incubadora="restaurar" hidden><?php esc_html_e( 'Restaurar esta versão', 'reconectar-core' ); ?></button>
			<?php endif; ?>
			<a class="rc-incubadora__botao" href="<?php echo esc_url( Reconectar_Incubadora_Leitura::url_de_historico( $rc_pagina, array( Reconectar_Incubadora_Leitura::PARAM_HISTORICO => 1 ) ) ); ?>"><?php esc_html_e( 'Voltar ao histórico', 'reconectar-core' ); ?></a>
			<a class="rc-incubadora__botao" href="<?php echo esc_url( Reconectar_Incubadora_Leitura::url_de_historico( $rc_pagina ) ); ?>"><?php esc_html_e( 'Ver a página atual', 'reconectar-core' ); ?></a>
		</div>
		<p class="rc-incubadora__status" role="status"></p>
		<div class="rc-incubadora__alerta" role="alert"></div>

		<h1 class="rc-incubadora__titulo" id="rc-incubadora-titulo"><?php echo esc_html( '' !== $rc_titulo ? $rc_titulo : __( '(sem título)', 'reconectar-core' ) ); ?></h1>
	</header>

	<div class="rc-incubadora__conteudo">
		<?php if ( '' === trim( $rc_html ) ) : ?>
			<p class="rc-incubadora__vazio"><?php esc_html_e( 'Esta versão não tinha conteúdo.', 'reconectar-core' ); ?></p>
			<?php
		else :
			echo $rc_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- reconstruído e sanitizado em `html_de_leitura()`.
		endif;
		?>
	</div>
</article>
