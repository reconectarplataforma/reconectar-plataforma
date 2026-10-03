<?php
/**
 * Seção institucional de Comunidade e Transparência da home.
 *
 * Não é seção de e-commerce: são as duas frentes exigidas pelo edital
 * (participação da comunidade e transparência das decisões), por isso a seção
 * permanece mesmo quando não há produto nenhum cadastrado.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renderiza os cards de Comunidade e Transparência.
 *
 * Cada card só aparece se a página correspondente existir, e a seção inteira é
 * omitida quando nenhuma das duas existe — evita renderizar um bloco vazio em
 * uma instalação recém-provisionada.
 *
 * O card da Comunidade tem uma segunda condição, de permissão: quem não pode
 * entrar na comunidade também não deve recebê-la como convite. O plugin já
 * bloqueia o acesso — o cliente logado leva um 403 e o visitante deslogado é
 * mandado para o login —, mas o bloqueio não alcançava esta superfície, e o
 * resultado era um card que só servia para levar a um beco. A mesma regra vale
 * no menu principal, por `Reconectar_Permissoes::ocultar_itens_da_comunidade()`,
 * e no widget do rodapé; as três precisam concordar.
 *
 * A consulta passa por `function_exists()` porque o tema não pode depender do
 * plugin: sem ele não há bloqueio nenhum, e esconder o card deixaria a área
 * aberta e invisível ao mesmo tempo.
 *
 * A terceira condição é de navegação, não de permissão: para a loja, a
 * comunidade mora no menu do painel do Dokan e sai das telas de compra —
 * `Reconectar_Navegacao_Da_Loja`, que também a tira do menu e do rodapé.
 */
function reconectar_home_comunidade_transparencia() {
	$comunidade    = get_page_by_path( 'comunidade' );
	$transparencia = get_page_by_path( 'transparencia' );

	if ( $comunidade
		&& function_exists( 'reconectar_pode_participar_da_comunidade' )
		&& ! reconectar_pode_participar_da_comunidade() ) {
		$comunidade = null;
	}

	if ( $comunidade
		&& function_exists( 'reconectar_comunidade_mora_no_painel_da_loja' )
		&& reconectar_comunidade_mora_no_painel_da_loja() ) {
		$comunidade = null;
	}

	if ( ! $comunidade && ! $transparencia ) {
		return;
	}

	// Com um card só, `row-cols-md-2` deixaria metade da linha vazia a partir de
	// 768px. A contagem resolve tanto o caso de permissão acima quanto o de uma
	// instalação em que só uma das duas páginas exista.
	$colunas_md = ( $comunidade && $transparencia ) ? 'row-cols-md-2' : 'row-cols-md-1';
	?>
	<section class="reconectar-home-comunidade-transparencia">
		<div class="container">
			<div class="row row-cols-1 <?php echo esc_attr( $colunas_md ); ?> g-3">
				<?php if ( $comunidade ) : ?>
					<div class="col">
						<a class="card h-100 text-decoration-none" href="<?php echo esc_url( get_permalink( $comunidade ) ); ?>">
							<div class="card-body">
								<h3 class="card-title h5"><?php esc_html_e( 'Comunidade', 'reconectar' ); ?></h3>
								<p class="card-text"><?php esc_html_e( 'Participe da rede de pessoas e negócios conectados pela plataforma.', 'reconectar' ); ?></p>
							</div>
						</a>
					</div>
				<?php endif; ?>
				<?php if ( $transparencia ) : ?>
					<div class="col">
						<a class="card h-100 text-decoration-none" href="<?php echo esc_url( get_permalink( $transparencia ) ); ?>">
							<div class="card-body">
								<h3 class="card-title h5"><?php esc_html_e( 'Transparência', 'reconectar' ); ?></h3>
								<p class="card-text"><?php esc_html_e( 'Acompanhe as propostas de votação e as decisões coletivas da plataforma.', 'reconectar' ); ?></p>
							</div>
						</a>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</section>
	<?php
}
add_action( 'reconectar_home', 'reconectar_home_comunidade_transparencia', 60 );
