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
 * As duas datas vêm de `_gmt`, que não muda com o fuso do servidor.
 *
 * @package reconectar-core
 *
 * @var WP_Post $rc_pagina
 */

defined( 'ABSPATH' ) || exit;

$rc_titulo     = get_the_title( $rc_pagina );
$rc_autor      = Reconectar_Incubadora_Leitura::nome_de_usuario( (int) $rc_pagina->post_author );
$rc_editor     = Reconectar_Incubadora_Leitura::nome_de_usuario( Reconectar_Incubadora_Leitura::editado_por( $rc_pagina ) );
$rc_modificado = (int) get_post_modified_time( 'U', true, $rc_pagina );
$rc_rascunho   = 'draft' === $rc_pagina->post_status;
?>
<article class="rc-incubadora__pagina" aria-labelledby="rc-incubadora-titulo">
	<header class="rc-incubadora__cabecalho">
		<?php if ( $rc_rascunho ) : ?>
			<p class="rc-incubadora__selo rc-incubadora__selo--destaque"><?php esc_html_e( 'Rascunho — visível só para quem edita a Incubadora', 'reconectar-core' ); ?></p>
		<?php endif; ?>

		<h1 class="rc-incubadora__titulo" id="rc-incubadora-titulo"><?php echo esc_html( '' !== $rc_titulo ? $rc_titulo : __( '(sem título)', 'reconectar-core' ) ); ?></h1>

		<p class="rc-incubadora__autoria">
			<?php
			/* translators: %s: nome de quem criou a página. */
			echo esc_html( sprintf( __( 'Criada por %s', 'reconectar-core' ), $rc_autor ) );
			?>
			<span aria-hidden="true">·</span>
			<time datetime="<?php echo esc_attr( gmdate( 'c', $rc_modificado ) ); ?>" title="<?php echo esc_attr( Reconectar_Incubadora_Leitura::data_local( $rc_pagina, 'post_modified' ) ); ?>">
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
					<td>
						<?php
						/* translators: 1: data, 2: nome de quem editou. */
						echo esc_html( sprintf( __( '%1$s, por %2$s', 'reconectar-core' ), Reconectar_Incubadora_Leitura::data_local( $rc_pagina, 'post_modified' ), $rc_editor ) );
						?>
					</td>
				</tr>
			</tbody>
		</table>
	</header>

	<div class="rc-incubadora__conteudo">
		<?php
		$rc_html = Reconectar_Incubadora_Leitura::conteudo( $rc_pagina );

		if ( '' === trim( $rc_html ) ) :
			?>
			<p class="rc-incubadora__vazio"><?php esc_html_e( 'Esta página ainda não tem conteúdo.', 'reconectar-core' ); ?></p>
			<?php
		else :
			echo $rc_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- já passou pelo kses em `conteudo()`.
		endif;
		?>
	</div>
</article>
