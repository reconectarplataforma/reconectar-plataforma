<?php
/**
 * Vitrine de lojas da home.
 *
 * É a seção central da página: barra de filtros, grade de cards e um botão que
 * amplia a lista. Vem depois das faixas de categorias e destaques porque essas
 * servem para descobrir; esta serve para escolher.
 *
 * O "Ver mais" é um link com `?lojas=N`, não um botão que busca por AJAX. Custa
 * uma recarga, mas o estado expandido fica na URL — compartilhável, favoritável
 * e desfeito pelo botão "voltar" — e a lista continua completa para quem navega
 * sem JavaScript ou com leitor de tela.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renderiza a vitrine de lojas.
 */
function reconectar_home_lojas() {
	$ativos = reconectar_filtros_ativos();

	/*
	 * Pede uma loja a mais do que vai exibir. É a forma mais barata de saber se
	 * ainda há resultados depois do corte: contar o total exigiria carregar e
	 * filtrar a lista inteira uma segunda vez, sem paginação, só para descobrir
	 * se o botão deve aparecer.
	 */
	$lojas = reconectar_obter_lojas(
		array(
			'numero'    => $ativos['limite'] + 1,
			'categoria' => $ativos['categoria'],
			'cidade'    => $ativos['cidade'],
			'so_gratis' => $ativos['so_gratis'],
			'ordenar'   => $ativos['ordenar'],
		)
	);

	$tem_mais = count( $lojas ) > $ativos['limite'];

	if ( $tem_mais ) {
		$lojas = array_slice( $lojas, 0, $ativos['limite'] );
	}
	?>
	<section class="rc-vitrine" aria-labelledby="rc-vitrine-titulo">
		<div class="rc-vitrine__cabecalho">
			<h2 class="rc-vitrine__titulo" id="rc-vitrine-titulo">
				<?php esc_html_e( 'Lojas', 'reconectar' ); ?>
			</h2>
		</div>

		<?php reconectar_barra_de_filtros( $ativos ); ?>

		<?php if ( $lojas ) : ?>
			<div class="rc-vitrine__grade">
				<?php foreach ( $lojas as $loja ) : ?>
					<?php reconectar_card_loja( $loja ); ?>
				<?php endforeach; ?>
			</div>

			<?php if ( $tem_mais ) : ?>
				<p class="rc-vitrine__mais">
					<a
						class="rc-botao rc-botao--largo"
						href="<?php echo esc_url( reconectar_url_de_filtro( 'lojas', (string) ( $ativos['limite'] + RECONECTAR_LOJAS_POR_PAGINA ) ) ); ?>"
					>
						<?php esc_html_e( 'Ver mais lojas', 'reconectar' ); ?>
					</a>
				</p>
			<?php endif; ?>
		<?php else : ?>
			<?php reconectar_vitrine_vazia( $ativos ); ?>
		<?php endif; ?>
	</section>
	<?php
}
add_action( 'reconectar_home', 'reconectar_home_lojas', 45 );

/**
 * Mensagem exibida quando nenhuma loja atende aos filtros.
 *
 * Distingue os dois casos possíveis. Sem filtro ativo, a plataforma realmente
 * ainda não tem lojas publicadas, e dizer "tente outros filtros" seria enganoso.
 * Com filtro, a saída existe e precisa estar à mão — daí o link para limpar.
 *
 * @param array $ativos Filtros ativos, de `reconectar_filtros_ativos()`.
 */
function reconectar_vitrine_vazia( $ativos ) {
	$tem_filtro = $ativos['categoria'] || $ativos['cidade'] || $ativos['so_gratis'];
	?>
	<div class="rc-vitrine__vazia">
		<?php if ( $tem_filtro ) : ?>
			<p><?php esc_html_e( 'Nenhuma loja atende aos filtros selecionados.', 'reconectar' ); ?></p>
			<p>
				<a class="rc-botao" href="<?php echo esc_url( reconectar_url_base_da_vitrine() ); ?>">
					<?php esc_html_e( 'Limpar filtros', 'reconectar' ); ?>
				</a>
			</p>
		<?php else : ?>
			<p><?php esc_html_e( 'Ainda não há lojas publicadas na plataforma.', 'reconectar' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}
