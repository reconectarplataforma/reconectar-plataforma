<?php
/**
 * Plugin Name: Reconectar Core
 * Plugin URI: https://github.com/reconectar/reconectar-plataforma
 * Description: Funcionalidades autorais do módulo de Governança Digital da plataforma Reconectar — Custom Post Type "Proposta de Votação" e painel público de transparência. MVP/esqueleto: as regras de funcionamento da votação ainda dependem de definição participativa com os beneficiários (Atividade 2.10 do edital).
 * Version: 0.1.0
 * Author: Reconectar
 * License: GPL-2.0-or-later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: reconectar-core
 */

defined( 'ABSPATH' ) || exit;

define( 'RECONECTAR_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'RECONECTAR_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-proposta-votacao.php';
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-painel-transparencia.php';
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-enquete-flutuante.php';

// A faixa de aviso de dados de demonstração vive aqui, e não no tema, para
// não depender de qual tema esteja ativo: o aviso precisa continuar de pé
// mesmo em um ambiente que troque de tema (como a migração para o Blocksy).
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-aviso-demo.php';

// Regras de negócio da plataforma. Ficam no plugin, e não no tema, porque
// controle de acesso e fluxo de pedido não são aparência: precisam valer
// mesmo que alguém troque o tema ativo ou o desative por engano.
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-permissoes.php';
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-status-pedido.php';

// O Administrador de Empresas opera por uma interface própria da aplicação, e
// não pelo `/wp-admin`. Os três arquivos abaixo são essa camada: a entidade que
// agrupa lojas, a API que as cadastra e a rota que dá a tela.
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-empresa.php';
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-lojas.php';
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-painel-empresas.php';

// Depois de `class-reconectar-empresa.php`: a migração lê as constantes de meta
// de lá, e é a entidade que define a chave, não a migração.
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-migracoes.php';

// A contrapartida do arquivo acima: enquanto o Dokan deixar qualquer visitante
// abrir uma loja por conta própria, o cadastro controlado por empresa é só uma
// das entradas possíveis — e não a regra.
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-cadastro-lojas.php';

// O banner da home com vigência. Fica no plugin porque a campanha é conteúdo
// da instituição, não do tema: trocar de tema não pode apagar a agenda de
// campanhas já publicadas.
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-campanha.php';

// Voto, leitura e melhor resposta do fórum. O bbPress entrega tópico, resposta,
// categoria e tag; o que transforma isso num Q&A é esta camada — e ela é regra
// de negócio, não aparência.
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-forum.php';

// A wiki da Incubadora. Depois de `class-reconectar-permissoes.php`, de onde lê
// a capacidade de escrita; e no plugin, não no tema, pela mesma razão das
// campanhas: trocar de tema não pode apagar o conhecimento já escrito.
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-incubadora.php';
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-incubadora-conteudo.php';
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-incubadora-leitura.php';
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-incubadora-acoes.php';
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-incubadora-arquivos.php';
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-incubadora-editor.php';
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-incubadora-busca.php';

// O avatar local também é plugin, e não tema, porque o que ele resolve é
// proteção de dados: o Gravatar entrega a um terceiro o hash do e-mail de quem
// avalia e o IP de quem visita. Uma troca de tema não pode reabrir isso.
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-avatar-local.php';

// Pagamento direto do comprador à loja, por PIX ou transferência. O dinheiro
// não passa pela plataforma: o que existe aqui é o cadastro da chave na
// dashboard do Dokan e a instrução que o comprador recebe depois do pedido.
require_once RECONECTAR_CORE_PATH . 'includes/pagamento/funcoes-pix.php';
require_once RECONECTAR_CORE_PATH . 'includes/pagamento/funcoes-qrcode.php';
require_once RECONECTAR_CORE_PATH . 'includes/pagamento/class-reconectar-pagamento-pix.php';
require_once RECONECTAR_CORE_PATH . 'includes/pagamento/class-reconectar-pagamento-direto.php';
// Fecha o ciclo do pagamento direto: o comprador anexa o comprovante, a loja
// confere e confirma o recebimento. Depois de `class-reconectar-pagamento-direto.php`
// porque lê a constante `STATUS_AGUARDANDO` dele.
require_once RECONECTAR_CORE_PATH . 'includes/pagamento/class-reconectar-comprovante.php';
// A aba de avaliações pendentes também é da dashboard do Dokan e lê os pedidos
// da loja; fica junto do ciclo do pedido, e não no tema, porque é regra sobre
// quem pode ver qual pedido.
require_once RECONECTAR_CORE_PATH . 'includes/class-reconectar-avaliacoes-pendentes.php';
// As três classes de gateway ficam de fora daqui de propósito: elas estendem
// `WC_Payment_Gateway`, que só existe depois de o WooCommerce carregar. Quem as
// exige é `Reconectar_Pagamento_Direto::registrar_gateways()`, já dentro do
// filtro `woocommerce_payment_gateways`.

/**
 * Inicializa os módulos do plugin.
 *
 * Roda em `plugins_loaded`, e não em `init`: os módulos precisam ter seus
 * ganchos registrados antes que o WordPress comece a disparar `init`, que é
 * onde vários deles se penduram.
 */
function reconectar_core_init() {
	Reconectar_Proposta_Votacao::init();
	Reconectar_Painel_Transparencia::init();
	Reconectar_Enquete_Flutuante::init();
	Reconectar_Aviso_Demo::init();
	// Antes de `Reconectar_Permissoes`: as duas se penduram em `init`, e a
	// migração de dados roda em prioridade menor — ver o PHPDoc de
	// `Reconectar_Migracoes::init()`.
	Reconectar_Migracoes::init();
	Reconectar_Permissoes::init();
	Reconectar_Status_Pedido::init();
	Reconectar_Avatar_Local::init();
	Reconectar_Empresa::init();
	Reconectar_Painel_Empresas::init();
	Reconectar_Cadastro_De_Lojas::init();
	Reconectar_Campanha::init();
	Reconectar_Pagamento_Pix::init();
	Reconectar_Pagamento_Direto::init();
	Reconectar_Comprovante::init();
	Reconectar_Avaliacoes_Pendentes::init();
	// Depois de `Reconectar_Permissoes`, de quem `Reconectar_Forum` lê as
	// capacidades da comunidade.
	Reconectar_Forum::init();
	// Também depois de `Reconectar_Permissoes`: o post type aponta suas
	// primitivas para `CAP_GERIR_INCUBADORA`.
	Reconectar_Incubadora::init();
	Reconectar_Incubadora_Leitura::init();
	Reconectar_Incubadora_Acoes::init();
	Reconectar_Incubadora_Arquivos::init();
	Reconectar_Incubadora_Editor::init();
	Reconectar_Incubadora_Busca::init();
}
add_action( 'plugins_loaded', 'reconectar_core_init' );
