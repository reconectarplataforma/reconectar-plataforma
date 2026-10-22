<?php
/**
 * A casca das telas de gestão, servida por `template_include`.
 *
 * O mesmo `#primary > #main` do `page.php` do Storefront, como na Incubadora,
 * para que a moldura do Dokan — decidida em `header.php` por
 * `Reconectar_Navegacao_Da_Loja::area_corrente()` — envolva a tela como envolve
 * as outras áreas. As classes do painel de empresas são reaproveitadas porque
 * tabela, formulário, botão e aviso já estão desenhados lá.
 *
 * O acesso já foi decidido em `Reconectar_Painel_Gestao::proteger()`: aqui só
 * se escolhe o template da área.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

$rc_gestao_classe   = Reconectar_Painel_Gestao::classe();
$rc_gestao_item     = Reconectar_Painel_Gestao::item();
$rc_gestao_template = basename( call_user_func( array( $rc_gestao_classe, 'template' ), $rc_gestao_item ) );

get_header();
?>

	<div id="primary" class="content-area">
		<main id="main" class="site-main">
			<div class="rc-painel-empresas rc-painel-empresas--dashboard rc-painel-gestao<?php echo Reconectar_Painel_Gestao::na_moldura() ? ' rc-painel-empresas--moldura' : ''; ?>">
				<div class="rc-painel-empresas__area">
					<?php
					Reconectar_Painel_Gestao::aviso();

					include __DIR__ . '/' . $rc_gestao_template;
					?>
				</div>
			</div>
		</main>
	</div>

<?php
get_footer();
