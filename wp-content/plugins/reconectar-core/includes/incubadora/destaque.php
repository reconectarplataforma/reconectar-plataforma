<?php
/**
 * O vídeo em destaque da página, e o formulário de quem o define.
 *
 * Incluído por `leitura.php`, entre o cabeçalho e o texto: o título vem
 * primeiro, para que quem navega por cabeçalhos saiba onde está antes do
 * player, e o texto fica abaixo, como descrição.
 *
 * Fora de `.rc-incubadora__conteudo` de propósito. O editor troca o
 * `innerHTML` daquele bloco ao abrir e ao salvar, e o vídeo em destaque não é
 * texto: tem formulário próprio, de um campo só, que funciona sem script e sem
 * abrir o editor — é a operação mais frequente de quem cuida do conteúdo.
 *
 * Sem vídeo e sem permissão para definir um, a seção não sai: um espaço vazio
 * no topo da página não diz nada a quem lê.
 *
 * @package reconectar-core
 *
 * @var WP_Post $rc_pagina
 */

defined( 'ABSPATH' ) || exit;

$rc_video      = Reconectar_Incubadora_Interacao::video( $rc_pagina->ID );
$rc_pode_video = current_user_can( Reconectar_Permissoes::CAP_GERIR_INCUBADORA ) && current_user_can( 'edit_post', $rc_pagina->ID );

if ( ! $rc_video && ! $rc_pode_video ) {
	return;
}
?>
<section class="rc-incubadora__destaque" id="rc-incubadora-video" aria-label="<?php esc_attr_e( 'Vídeo da página', 'reconectar-core' ); ?>">
	<?php
	if ( $rc_video ) {
		echo Reconectar_Incubadora_Conteudo::facade_de_video( $rc_video ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- montada e escapada em `facade()`, sobre vídeo revalidado.
	}

	if ( $rc_pode_video ) :
		ob_start();
		?>
		<form class="rc-incubadora__form-video" method="post" action="<?php echo esc_attr( Reconectar_Incubadora_Interacao::url_do_formulario() ); ?>">
			<?php Reconectar_Incubadora_Interacao::campos( 'video', $rc_pagina->ID ); ?>
			<input type="hidden" name="pagina" value="<?php echo esc_attr( $rc_pagina->ID ); ?>">
			<label class="rc-incubadora__rotulo" for="rc-incubadora-video-url"><?php esc_html_e( 'Endereço do vídeo no YouTube', 'reconectar-core' ); ?></label>
			<p class="rc-incubadora__dica" id="rc-incubadora-video-dica"><?php esc_html_e( 'Copie da barra de endereços ou do botão "Compartilhar" do YouTube. O vídeo aparece no topo da página, acima do texto.', 'reconectar-core' ); ?></p>
			<div class="rc-incubadora__linha-video">
				<input class="rc-incubadora__campo" type="url" id="rc-incubadora-video-url" name="video" value="<?php echo esc_attr( Reconectar_Incubadora_Interacao::url_do_video( $rc_pagina->ID ) ); ?>" placeholder="https://www.youtube.com/watch?v=…" aria-describedby="rc-incubadora-video-dica" inputmode="url" autocomplete="off" required>
				<button type="submit" class="rc-incubadora__botao rc-incubadora__botao--primario"><?php echo esc_html( $rc_video ? __( 'Trocar vídeo', 'reconectar-core' ) : __( 'Publicar vídeo', 'reconectar-core' ) ); ?></button>
				<?php if ( $rc_video ) : ?>
					<?php // `formnovalidate`: o campo é obrigatório para publicar, e remover não precisa dele. ?>
					<button type="submit" class="rc-incubadora__botao" name="remover" value="1" formnovalidate><?php esc_html_e( 'Remover vídeo', 'reconectar-core' ); ?></button>
				<?php endif; ?>
			</div>
		</form>
		<?php
		$rc_formulario = ob_get_clean();

		if ( $rc_video ) :
			// Com vídeo, o formulário recolhe: quem edita lê a página como os
			// outros, e abre o campo só quando quer trocar.
			?>
			<details class="rc-incubadora__trocar-video">
				<summary><?php esc_html_e( 'Trocar ou remover o vídeo', 'reconectar-core' ); ?></summary>
				<?php echo $rc_formulario; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- montado acima, com cada valor escapado. ?>
			</details>
			<?php
		else :
			?>
			<div class="rc-incubadora__sem-video">
				<p class="rc-incubadora__sem-video-titulo"><?php esc_html_e( 'Esta página ainda não tem vídeo', 'reconectar-core' ); ?></p>
				<?php echo $rc_formulario; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- montado acima, com cada valor escapado. ?>
			</div>
			<?php
		endif;
	endif;
	?>
</section>
