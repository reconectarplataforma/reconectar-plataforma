<?php
/**
 * O índice da âncora, que só chega à tela quando não há página visível.
 *
 * Com uma página que seja, `abrir_primeira_pagina()` já redirecionou. O que
 * resta é o estado vazio, e ele é diferente para quem pode escrever: o
 * comprador lê que ainda não há conteúdo; quem gere a Incubadora lê o mesmo e
 * o convite para começar. O botão de criar chega com os endpoints de escrita —
 * oferecê-lo antes seria um botão que não faz nada.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="rc-incubadora__indice" aria-labelledby="rc-incubadora-titulo">
	<h1 class="rc-incubadora__titulo" id="rc-incubadora-titulo"><?php esc_html_e( 'Incubadora', 'reconectar-core' ); ?></h1>

	<div class="rc-incubadora__estado-vazio">
		<p><?php esc_html_e( 'Ainda não há páginas publicadas na Incubadora.', 'reconectar-core' ); ?></p>

		<?php if ( Reconectar_Incubadora_Leitura::ve_rascunho() ) : ?>
			<p><?php esc_html_e( 'Quando a primeira página for criada, ela passa a abrir aqui, com a árvore de páginas ao lado.', 'reconectar-core' ); ?></p>
		<?php endif; ?>
	</div>
</section>
