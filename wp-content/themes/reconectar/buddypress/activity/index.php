<?php
/**
 * Diretório de atividade em três colunas: atalhos, feed e membros ativos.
 *
 * Override de `bp-nouveau/buddypress/activity/index.php` (versão 6.0.0). O miolo
 * — formulário, avisos, abas, filtros e `#activity-stream` — é o original, na
 * mesma ordem e com os mesmos ids: o JS do Nouveau monta o formulário em
 * `#bp-nouveau-activity-form` e carrega o feed por AJAX em
 * `[data-bp-list="activity"]`, e procura os dois pelo id.
 *
 * As colunas laterais vêm antes e depois do feed **no DOM**, e não por `order`
 * no CSS: assim a ordem de tabulação é a mesma que se vê.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="rc-comunidade rc-comunidade--feed">

	<?php reconectar_comunidade_coluna_de_atalhos(); ?>

	<div class="rc-comunidade__principal">

		<?php bp_nouveau_before_activity_directory_content(); ?>

		<?php if ( is_user_logged_in() ) : ?>

			<?php bp_get_template_part( 'activity/post-form' ); ?>

		<?php endif; ?>

		<?php bp_nouveau_template_notices(); ?>

		<?php if ( ! bp_nouveau_is_object_nav_in_sidebar() ) : ?>

			<?php bp_get_template_part( 'common/nav/directory-nav' ); ?>

		<?php endif; ?>

		<div class="screen-content">

			<?php bp_get_template_part( 'common/search-and-filters-bar' ); ?>

			<?php bp_nouveau_activity_hook( 'before_directory', 'list' ); ?>

			<div id="activity-stream" class="activity" data-bp-list="activity">

					<div id="bp-ajax-loader"><?php bp_nouveau_user_feedback( 'directory-activity-loading' ); ?></div>

			</div><!-- .activity -->

			<?php bp_nouveau_after_activity_directory_content(); ?>

		</div><!-- // .screen-content -->

	</div><!-- .rc-comunidade__principal -->

	<?php reconectar_comunidade_coluna_de_membros(); ?>

</div><!-- .rc-comunidade -->

<?php bp_nouveau_after_directory_page(); ?>
