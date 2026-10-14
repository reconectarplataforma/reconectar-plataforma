<?php
/**
 * Cabeçalho do perfil com capa: avatar sobreposto e nome legível.
 *
 * Override de `bp-nouveau/buddypress/members/single/cover-image-header.php`
 * (versão 12.0.0). No original o título é a menção — `@demo-casa-viva`, em
 * branco sobre a capa, que sem imagem é cinza `#c5c5c5`: 1,7:1, abaixo
 * de qualquer critério da WCAG. Aqui o título é o nome de exibição, abaixo da
 * capa, e a menção vira texto de apoio.
 *
 * Os ids `#item-header-cover-image`, `#item-header-avatar` e
 * `#item-header-content` saíram de propósito. O Nouveau imprime CSS inline
 * contra eles (`bp_nouveau_theme_cover_image()`), com dois ids de
 * especificidade e deslocamentos em pixel calculados para o layout dele — o
 * avatar saía com 4px de largura, preso num float de largura automática.
 * `#header-cover-image` fica: é nele que `cover-image.js` troca o fundo quando
 * a pessoa envia uma capa nova, e nele que o inline põe a imagem gravada.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;
?>

<div id="cover-image-container" class="rc-perfil__capa-moldura">
	<div id="header-cover-image" class="rc-perfil__capa"></div>

	<div class="rc-perfil__identidade">
		<div class="rc-perfil__avatar">
			<a href="<?php bp_displayed_user_link(); ?>">

				<?php bp_displayed_user_avatar( 'type=full&width=168&height=168' ); ?>

			</a>
		</div>

		<div class="rc-perfil__dados">

			<h2 class="rc-perfil__nome"><?php echo esc_html( bp_get_displayed_user_fullname() ); ?></h2>

			<?php if ( bp_is_active( 'activity' ) && bp_activity_do_mentions() ) : ?>
				<p class="rc-perfil__mencao user-nicename">@<?php bp_displayed_user_mentionname(); ?></p>
			<?php endif; ?>

			<?php bp_nouveau_member_hook( 'before', 'header_meta' ); ?>

			<?php if ( bp_nouveau_member_has_meta() ) : ?>
				<div class="item-meta rc-perfil__meta">

					<?php bp_nouveau_member_meta(); ?>

				</div><!-- #item-meta -->
			<?php endif; ?>

			<?php
			bp_member_type_list(
				bp_displayed_user_id(),
				array(
					'label'        => array(
						'plural'   => __( 'Member Types', 'buddypress' ),
						'singular' => __( 'Member Type', 'buddypress' ),
					),
					'list_element' => 'span',
				)
			);
			?>

		</div>

		<?php bp_nouveau_member_header_buttons( array( 'container_classes' => array( 'member-header-actions', 'rc-perfil__acoes' ) ) ); ?>

	</div>
</div><!-- #cover-image-container -->
