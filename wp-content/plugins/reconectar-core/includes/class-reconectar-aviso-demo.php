<?php
/**
 * Faixa de aviso exibida enquanto a carga de dados de demonstração estiver
 * ativa no ambiente.
 *
 * Esta é uma das quatro camadas que impedem que dado fictício seja
 * confundido com dado real numa entrega de licitação (as outras três são a
 * meta `_reconectar_demo` em cada registro criado, os e-mails no domínio
 * reservado `exemplo.invalid` da RFC 2606, e `docs/DADOS_DEMONSTRACAO.md`).
 *
 * A faixa é deliberadamente feia e sem relação com a identidade visual do
 * projeto: fita de advertência listrada, não um banner da marca. Se ela
 * parecesse parte do design, alguém poderia lê-la como peça promocional e
 * ignorá-la — que é exatamente o contrário do que ela existe para fazer.
 * Pelo mesmo motivo não há botão de fechar: quem estiver vendo o site
 * precisa continuar vendo o aviso.
 *
 * O gatilho é a opção `reconectar_demo_ativo`, gravada por
 * `scripts/seed/demo.php` na carga e apagada por ele na remoção. Em um
 * ambiente que nunca recebeu a carga a opção não existe, e esta classe não
 * imprime absolutamente nada.
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Aviso_Demo {

	/**
	 * Nome da opção que sinaliza a carga ativa.
	 *
	 * Precisa ser idêntico a `RECONECTAR_DEMO_OPCAO` em
	 * `scripts/seed/demo.php` — aquele arquivo grava, este lê. A string
	 * está repetida nos dois lados de propósito: o seed roda fora do
	 * ciclo normal do WordPress (via `wp eval-file`) e não pode depender
	 * de o plugin estar ativo para saber onde gravar.
	 */
	const OPCAO = 'reconectar_demo_ativo';

	/**
	 * Registra os ganchos.
	 */
	public static function init() {
		// Prioridade 5 para a faixa vir antes de qualquer coisa que o tema
		// ou outro plugin pendure no mesmo hook — o aviso precisa ser a
		// primeira coisa dentro do <body>.
		add_action( 'wp_body_open', array( __CLASS__, 'renderizar_no_site' ), 5 );
		add_action( 'admin_notices', array( __CLASS__, 'renderizar_no_admin' ) );
	}

	/**
	 * A carga de demonstração está ativa neste ambiente?
	 */
	public static function esta_ativa() {
		return (bool) get_option( self::OPCAO );
	}

	/**
	 * Faixa no topo de todas as páginas do site.
	 *
	 * O texto é mais curto que o do painel, e a diferença é a frase com o
	 * comando de remoção. Ela é instrução de desenvolvedor: pressupõe acesso
	 * ao terminal do servidor, que nenhum visitante tem. No painel faz
	 * sentido — quem lê ali administra a instalação. Aqui era só ruído
	 * dirigido a quem não pode agir sobre ele, e ruído caro: o `<code>` leva
	 * `white-space: nowrap` e mede cerca de 204px, o que em um aparelho de
	 * 320px monopoliza uma linha inteira. A faixa custava 209,7px nessa
	 * largura, empilhados acima do cabeçalho.
	 *
	 * O aviso em si não encolhe em nada que importe: continua listrado, sem
	 * botão de fechar e dizendo que lojas, produtos e avaliações são
	 * fictícios. O que sai é a linha de comando, não a advertência.
	 */
	public static function renderizar_no_site() {
		if ( ! self::esta_ativa() ) {
			return;
		}

		// O CSS vai embutido em vez de enfileirado porque a faixa não pode
		// depender de nenhum handle de tema para aparecer: ela precisa
		// funcionar igual sob Storefront, sob Blocksy ou sob um tema que
		// alguém venha a ativar por engano num servidor de homologação.
		?>
		<style id="reconectar-aviso-demo-css">
			.reconectar-aviso-demo {
				background-color: #F1BF3D;
				background-image: repeating-linear-gradient(
					45deg,
					rgba(0, 0, 0, 0.14) 0,
					rgba(0, 0, 0, 0.14) 12px,
					transparent 12px,
					transparent 24px
				);
				border-bottom: 3px solid #1f2328;
				color: #1f2328;
				font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
				font-size: 0.875rem;
				line-height: 1.45;
				padding: 0.75rem 1rem;
				text-align: center;
			}

			.reconectar-aviso-demo p {
				margin: 0 auto;
				max-width: 62rem;
			}

			.reconectar-aviso-demo strong {
				text-transform: uppercase;
				letter-spacing: 0.04em;
			}
		</style>
		<div class="reconectar-aviso-demo" role="region" aria-label="<?php esc_attr_e( 'Aviso de ambiente de demonstração', 'reconectar-core' ); ?>">
			<p>
				<strong><?php esc_html_e( 'Ambiente de demonstração.', 'reconectar-core' ); ?></strong>
				<?php esc_html_e( 'As lojas, os produtos e as avaliações exibidos nesta página são fictícios e foram criados automaticamente para fins de teste. Nenhuma pessoa, empresa ou produto real está representado aqui.', 'reconectar-core' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Aviso equivalente no painel administrativo.
	 *
	 * Quem administra o site vê o catálogo pela lista de produtos e pela
	 * lista de usuários, onde a faixa do front-end não aparece — sem este
	 * aviso, seria possível passar uma sessão inteira no admin sem saber
	 * que está olhando para dado inventado.
	 */
	public static function renderizar_no_admin() {
		if ( ! self::esta_ativa() ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Reconectar — ambiente de demonstração.', 'reconectar-core' ); ?></strong>
				<?php esc_html_e( 'Parte das lojas, dos produtos, dos usuários e das avaliações deste site é fictícia e foi criada por script. Esses registros levam a meta "_reconectar_demo" e os usuários usam e-mails no domínio reservado "exemplo.invalid".', 'reconectar-core' ); ?>
				<?php
				printf(
					/* translators: %s: comando de terminal que remove os dados de demonstração. */
					esc_html__( 'Para remover, rode %s na raiz do projeto.', 'reconectar-core' ),
					'<code>./scripts/seed-demo.sh remover</code>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
