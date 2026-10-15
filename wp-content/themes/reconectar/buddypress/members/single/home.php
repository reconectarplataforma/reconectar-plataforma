<?php
/**
 * Perfil de membro: cabeçalho com capa e abas num cartão só, "Sobre" ao lado.
 *
 * Override de `bp-nouveau/buddypress/members/single/home.php` (versão 3.0.0).
 * `#item-header`, `#object-nav` e `#item-body` ficam com os ids do original: o
 * `bp-nouveau-priority-nav` recolhe as abas que não cabem procurando o
 * `#object-nav`, e o JS de atividade do perfil vive dentro de `#item-body`.
 *
 * O cartão "Sobre" não sai na aba Perfil, que já é a ficha inteira — e cujo
 * laço de xProfile reiniciaria do ponto em que o do cartão parou (veja
 * `reconectar_comunidade_sobre()`).
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

$reconectar_sobre = bp_is_user_profile() ? array() : reconectar_comunidade_sobre( bp_displayed_user_id() );
?>

	<?php bp_nouveau_member_hook( 'before', 'home_content' ); ?>

	<div class="rc-perfil">

		<div class="rc-perfil__topo">
			<div id="item-header" role="complementary" data-bp-item-id="<?php echo esc_attr( bp_displayed_user_id() ); ?>" data-bp-item-component="members" class="users-header single-headers">

				<?php bp_nouveau_member_header_template_part(); ?>

			</div><!-- #item-header -->

			<?php if ( ! bp_nouveau_is_object_nav_in_sidebar() ) : ?>

				<?php bp_get_template_part( 'members/single/parts/item-nav' ); ?>

			<?php endif; ?>
		</div>

		<div class="bp-wrap rc-perfil__corpo<?php echo $reconectar_sobre ? ' rc-perfil__corpo--com-sobre' : ''; ?>">

			<?php if ( $reconectar_sobre ) : ?>
				<aside class="rc-perfil__sobre rc-comunidade__cartao" aria-labelledby="rc-perfil-sobre">
					<h2 id="rc-perfil-sobre" class="rc-comunidade__titulo-cartao"><?php esc_html_e( 'Sobre', 'reconectar' ); ?></h2>
					<dl>
						<?php foreach ( $reconectar_sobre as $reconectar_linha ) : ?>
							<div class="rc-perfil__sobre-linha">
								<dt><?php echo esc_html( $reconectar_linha['rotulo'] ); ?></dt>
								<dd>
									<?php if ( ! empty( $reconectar_linha['url'] ) ) : ?>
										<a href="<?php echo esc_url( $reconectar_linha['url'] ); ?>"><?php echo esc_html( $reconectar_linha['valor'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $reconectar_linha['valor'] ); ?>
									<?php endif; ?>
								</dd>
							</div>
						<?php endforeach; ?>
					</dl>
				</aside>
			<?php endif; ?>

			<div id="item-body" class="item-body">

				<?php bp_nouveau_member_template_part(); ?>

			</div><!-- #item-body -->
		</div><!-- // .bp-wrap -->

	</div><!-- .rc-perfil -->

	<?php bp_nouveau_member_hook( 'after', 'home_content' ); ?>
