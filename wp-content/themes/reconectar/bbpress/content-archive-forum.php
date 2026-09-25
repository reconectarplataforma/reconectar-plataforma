<?php
/**
 * A tela "Todas as perguntas".
 *
 * Substitui o índice nativo, que lista **fóruns**. A troca é deliberada: manter
 * `/forums/` como endereço canônico preserva o que `verificar-acessos.sh:230` já
 * testa e o que `Reconectar_Permissoes::requisicao_e_de_comunidade()` já
 * reconhece. Mudar a rota exigiria mexer nos dois sem nenhum ganho.
 *
 * As categorias não somem: elas passam para o seletor "Filtrar por categoria" e
 * para a coluna lateral.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

?>

<div id="bbpress-forums" class="bbpress-wrapper rc-forum-wrapper">

	<?php do_action( 'bbp_template_before_forums_index' ); ?>

	<?php
	if ( function_exists( 'reconectar_forum_listagem' ) ) {
		reconectar_forum_listagem();
	} else {
		// Sem o tema, o bbPress ainda precisa entregar alguma coisa. Este ramo é o
		// comportamento nativo, e não um erro: o plugin não pode depender do tema.
		if ( bbp_has_forums() ) {
			bbp_get_template_part( 'loop', 'forums' );
		} else {
			bbp_get_template_part( 'feedback', 'no-forums' );
		}
	}
	?>

	<?php do_action( 'bbp_template_after_forums_index' ); ?>

</div>
