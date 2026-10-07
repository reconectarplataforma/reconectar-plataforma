<?php
/**
 * Filtro de município da home.
 *
 * Morou no cabeçalho, com o rótulo "Entregando em", e lá prometia mais do que
 * fazia: estava em toda página, mas só a vitrine de lojas lia o `?cidade=`. No
 * catálogo, no carrinho ou numa loja, trocar de município recarregava a tela
 * igual — e na própria home as faixas de destaque o ignoravam.
 *
 * Aqui ele fica imediatamente acima das seções que de fato filtra — lojas em
 * destaque, produtos em destaque e a vitrine de lojas. Campanhas e categorias
 * vêm antes e não dependem dele, e a posição na página é o que diz isso a quem
 * lê, sem precisar de uma frase explicando.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Âncora do filtro, que também é o `id` da seção.
 *
 * Cada opção é um link que recarrega a home. Sem a âncora a página voltava ao
 * topo, e quem acabou de escolher um município não via, sem rolar, o resultado
 * da escolha — que é exatamente o que precisa confirmar.
 */
const RECONECTAR_ANCORA_MUNICIPIO = 'rc-municipio';

/**
 * Imprime o filtro de município da home.
 *
 * É um `<details>` com uma lista de links, e não um `<select>` com JavaScript:
 * abre, fecha e navega sem script nenhum, e cada opção é uma URL de verdade —
 * compartilhável, favoritável e desfeita pelo "voltar" do navegador, como as
 * pílulas da vitrine.
 *
 * A lista vem dos municípios que **têm loja cadastrada**
 * (`reconectar_obter_cidades()`), então escolher uma opção nunca leva a uma
 * home vazia. Quando não há loja alguma, a seção não é impressa.
 */
function reconectar_home_municipio() {
	$cidades = reconectar_obter_cidades();

	if ( ! $cidades ) {
		return;
	}

	$ativos = reconectar_filtros_ativos();
	$atual  = $ativos['cidade'] ? $ativos['cidade'] : __( 'Todos os municípios', 'reconectar' );
	?>
	<section class="rc-home-municipio" id="<?php echo esc_attr( RECONECTAR_ANCORA_MUNICIPIO ); ?>" aria-label="<?php esc_attr_e( 'Filtro por município', 'reconectar' ); ?>">
		<details class="rc-municipio">
			<summary class="rc-municipio__gatilho">
				<span class="rc-municipio__icone" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
						<path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11z"></path>
						<circle cx="12" cy="10" r="2.5"></circle>
					</svg>
				</span>

				<?php
				/*
				 * "Lojas e produtos de", e não mais "Entregando em": o filtro compara
				 * o município **da loja**, não a área que ela atende. Uma loja de
				 * Maceió que entrega em Marechal Deodoro some ao escolher Marechal, e
				 * o rótulo antigo dizia o contrário.
				 */
				?>
				<span class="rc-municipio__texto">
					<span class="rc-municipio__rotulo"><?php esc_html_e( 'Lojas e produtos de', 'reconectar' ); ?></span>
					<span class="rc-municipio__valor"><?php echo esc_html( $atual ); ?></span>
				</span>

				<span class="rc-municipio__seta" aria-hidden="true"></span>
			</summary>

			<ul class="rc-municipio__lista">
				<li>
					<a
						class="rc-municipio__opcao"
						href="<?php echo esc_url( reconectar_url_de_filtro( 'cidade', null ) . '#' . RECONECTAR_ANCORA_MUNICIPIO ); ?>"
						<?php echo $ativos['cidade'] ? '' : ' aria-current="true"'; ?>
					>
						<?php esc_html_e( 'Todos os municípios', 'reconectar' ); ?>
					</a>
				</li>

				<?php foreach ( $cidades as $cidade ) : ?>
					<li>
						<a
							class="rc-municipio__opcao"
							href="<?php echo esc_url( reconectar_url_de_filtro( 'cidade', $cidade ) . '#' . RECONECTAR_ANCORA_MUNICIPIO ); ?>"
							<?php echo sanitize_title( $cidade ) === sanitize_title( $ativos['cidade'] ) ? ' aria-current="true"' : ''; ?>
						>
							<?php echo esc_html( $cidade ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
	</section>
	<?php
}
add_action( 'reconectar_home', 'reconectar_home_municipio', 15 );
