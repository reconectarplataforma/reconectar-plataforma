<?php
/**
 * A página "Comunidade" como porta de entrada do BuddyPress.
 *
 * O provisionamento criava a página com o conteúdo `[buddypress]`, e esse
 * shortcode não existe: o BuddyPress 14 monta seus diretórios em páginas
 * próprias (`bp-pages`, hoje `activity` e `members`) e não registra shortcode
 * nenhum. O texto saía cru na tela, para todo perfil que tinha acesso à
 * comunidade — e sem erro, porque um shortcode desconhecido é só texto.
 *
 * A página continua existindo, porque é ela que o menu principal e o rodapé
 * apontam **pelo ID** (`provision.sh`), e mudar o destino deles alcançaria só
 * as instalações novas — as guardas de idempotência protegem as antigas. Quem
 * chega nela é levado ao fluxo de atividade, que é a comunidade de fato.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Redireciona a página da comunidade ao diretório de atividade.
 */
class Reconectar_Comunidade {

	/**
	 * Slug da página criada pelo provisionamento.
	 */
	const SLUG_DA_PAGINA = 'comunidade';

	/**
	 * Registra os ganchos.
	 *
	 * Prioridade 11: depois de `Reconectar_Permissoes::bloquear_comunidade()`,
	 * em 10. O cliente recebe a negação na própria URL que pediu, e não um
	 * redirecionamento que só revelaria o endereço do diretório antes do 403.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'redirecionar_ao_diretorio' ), 11 );
	}

	/**
	 * URL do diretório de atividade, ou vazio se o componente estiver desligado.
	 *
	 * `bp_is_active( 'activity' )` vem antes da função de URL: com o componente
	 * desligado ela ainda existiria em algumas versões e devolveria uma página
	 * que responde 404.
	 *
	 * @return string
	 */
	public static function url_do_diretorio() {
		if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'activity' ) || ! function_exists( 'bp_get_activity_directory_permalink' ) ) {
			return '';
		}

		return (string) bp_get_activity_directory_permalink();
	}

	/**
	 * Leva a página "Comunidade" ao diretório de atividade.
	 *
	 * 302, e não 301: o destino depende de qual componente estiver ativo, e um
	 * 301 ficaria no cache do navegador depois de o administrador trocar isso.
	 * Sem o componente, a página fica como está — melhor que um laço ou um 404.
	 *
	 * @return void
	 */
	public static function redirecionar_ao_diretorio() {
		if ( ! is_page( self::SLUG_DA_PAGINA ) ) {
			return;
		}

		$destino = self::url_do_diretorio();

		if ( '' === $destino ) {
			return;
		}

		wp_safe_redirect( $destino, 302 );
		exit;
	}
}
