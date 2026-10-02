<?php
/**
 * Lista de versões da página: quem gravou e quando, da mais nova para a mais antiga.
 *
 * Incluído por `shell.php`, só para quem passa em
 * `Reconectar_Incubadora_Leitura::pode_ver_historico()`.
 *
 * Lista ordenada, e não tabela: cada item tem uma frase e um link, e a ordem
 * é o dado. O nome acessível do link inclui a data, para que "Ver" não se
 * repita vinte vezes na lista de links do leitor de tela.
 *
 * A versão igual à página de agora sai marcada como atual e sem link de
 * restaurar: restaurá-la não gravaria nada.
 *
 * @package reconectar-core
 *
 * @var WP_Post $rc_pagina
 */

defined( 'ABSPATH' ) || exit;

$rc_versoes = Reconectar_Incubadora_Leitura::versoes( $rc_pagina );
$rc_titulo  = get_the_title( $rc_pagina );
?>
<article class="rc-incubadora__pagina rc-incubadora__historico" aria-labelledby="rc-incubadora-titulo">
	<header class="rc-incubadora__cabecalho">
		<div class="rc-incubadora__acoes">
			<a class="rc-incubadora__botao" href="<?php echo esc_url( Reconectar_Incubadora_Leitura::url_de_historico( $rc_pagina ) ); ?>"><?php esc_html_e( 'Voltar à página', 'reconectar-core' ); ?></a>
		</div>

		<h1 class="rc-incubadora__titulo" id="rc-incubadora-titulo">
			<?php
			/* translators: %s: título da página. */
			echo esc_html( sprintf( __( 'Histórico de “%s”', 'reconectar-core' ), '' !== $rc_titulo ? $rc_titulo : __( '(sem título)', 'reconectar-core' ) ) );
			?>
		</h1>

		<p class="rc-incubadora__autoria">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: quantas versões a lista mostra, 2: quantas o histórico guarda. */
					__( 'As %1$d versões mais recentes. O histórico guarda até %2$d; as mais antigas saem conforme a página é gravada.', 'reconectar-core' ),
					Reconectar_Incubadora_Leitura::VERSOES_NA_LISTA,
					Reconectar_Incubadora::REVISOES_MAXIMAS
				)
			);
			?>
		</p>
	</header>

	<?php if ( ! $rc_versoes ) : ?>
		<p class="rc-incubadora__vazio"><?php esc_html_e( 'Esta página ainda não tem versões. A primeira é gravada quando ela for salva.', 'reconectar-core' ); ?></p>
	<?php else : ?>
		<ol class="rc-incubadora__versoes">
			<?php
			foreach ( $rc_versoes as $rc_item ) :
				$rc_versao   = $rc_item['versao'];
				$rc_quando   = (int) get_post_timestamp( $rc_versao );
				$rc_data     = Reconectar_Incubadora_Leitura::data_e_hora( $rc_versao );
				$rc_autor    = Reconectar_Incubadora_Leitura::nome_de_usuario( (int) $rc_versao->post_author );
				$rc_endereco = Reconectar_Incubadora_Leitura::url_de_historico( $rc_pagina, array( Reconectar_Incubadora_Leitura::PARAM_VERSAO => $rc_versao->ID ) );
				?>
				<li class="rc-incubadora__versao<?php echo $rc_item['atual'] ? ' rc-incubadora__versao--atual' : ''; ?>">
					<p class="rc-incubadora__versao-quando">
						<a href="<?php echo esc_url( $rc_endereco ); ?>">
							<time datetime="<?php echo esc_attr( gmdate( 'c', $rc_quando ) ); ?>"><?php echo esc_html( $rc_data ); ?></time>
						</a>
						<?php if ( $rc_item['atual'] ) : ?>
							<span class="rc-incubadora__selo"><?php esc_html_e( 'Versão atual', 'reconectar-core' ); ?></span>
						<?php endif; ?>
					</p>
					<p class="rc-incubadora__versao-autor">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: nome de quem gravou, 2: intervalo, como "5 minutos". */
								__( 'Por %1$s, há %2$s', 'reconectar-core' ),
								$rc_autor,
								human_time_diff( $rc_quando, time() )
							)
						);
						?>
					</p>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</article>
