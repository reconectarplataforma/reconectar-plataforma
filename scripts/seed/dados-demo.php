<?php
/**
 * Catálogo de dados fictícios da plataforma Reconectar.
 *
 * Este arquivo é apenas declarativo: devolve a estrutura que `demo.php`
 * percorre. Separá-lo da lógica permite revisar o conteúdo (nomes, preços,
 * endereços) sem ler o código de criação, e permite alterar o conteúdo sem
 * risco de mexer na rotina de remoção.
 *
 * Nada aqui representa pessoa, empresa ou produto real. Os e-mails usam o
 * domínio reservado `exemplo.invalid` (RFC 2606, seção 2), que por definição
 * nunca será registrado — nenhuma mensagem enviada a eles pode alcançar
 * alguém de verdade, nem por engano nem por registro futuro do domínio.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

return array(

	/*
	 * Categorias de produto (taxonomia `product_cat`).
	 *
	 * A cor define o visual da imagem gerada para a categoria; foram escolhidas
	 * dentro da paleta institucional, alternadas para que a faixa de categorias
	 * da home não fique monocromática.
	 */
	'categorias' => array(
		array(
			'nome'      => 'Alimentos e Bebidas',
			'slug'      => 'alimentos-e-bebidas',
			'descricao' => 'Produtos alimentícios e bebidas de produtores locais.',
			'cor'       => '#CF6442',
		),
		array(
			'nome'      => 'Artesanato',
			'slug'      => 'artesanato',
			'descricao' => 'Peças artesanais produzidas por artesãs e artesãos da rede.',
			'cor'       => '#663191',
		),
		array(
			'nome'      => 'Moda e Acessórios',
			'slug'      => 'moda-e-acessorios',
			'descricao' => 'Roupas, bolsas e acessórios de confecção local.',
			'cor'       => '#31BEB1',
		),
		array(
			'nome'      => 'Casa e Decoração',
			'slug'      => 'casa-e-decoracao',
			'descricao' => 'Itens de decoração, utilidades e objetos para o lar.',
			'cor'       => '#F1BF3D',
		),
		array(
			'nome'      => 'Beleza e Cuidados',
			'slug'      => 'beleza-e-cuidados',
			'descricao' => 'Cosméticos naturais e produtos de cuidado pessoal.',
			'cor'       => '#31BEB1',
		),
		array(
			'nome'      => 'Cultura e Educação',
			'slug'      => 'cultura-e-educacao',
			'descricao' => 'Livros, oficinas e materiais educativos da comunidade.',
			'cor'       => '#663191',
		),
	),

	/*
	 * Lojas (usuários com papel `seller` no Dokan) e seus produtos.
	 *
	 * `categoria` casa com o slug de uma das categorias acima: é usada tanto
	 * para preencher o campo de categoria do perfil da loja quanto como
	 * categoria padrão dos produtos que não declaram a sua.
	 *
	 * `entrega` alimenta as três informações que o card da vitrine exibe. Elas
	 * são declaradas aqui, uma a uma, e não calculadas em tempo de execução:
	 * a plataforma não tem integração de logística, e inventar um tempo a
	 * partir da distância daria ao cliente um número com cara de compromisso
	 * que ninguém assumiu. `taxa` é numérica (`'0'` significa entrega grátis e
	 * faz a loja entrar no filtro correspondente); `tempo` e `distancia` são
	 * texto livre, porque a unidade muda conforme a loja entregue no mesmo dia
	 * ou pelos Correios.
	 */
	'lojas'      => array(

		array(
			'login'     => 'demo-sabor-da-terra',
			'email'     => 'sabor.da.terra@exemplo.invalid',
			'nome'      => 'Sabor da Terra',
			'primeiro'  => 'Rosa',
			'ultimo'    => 'Andrade',
			'categoria' => 'alimentos-e-bebidas',
			'descricao' => 'Cooperativa de agricultura familiar com produtos de mandioca, milho e frutas da região.',
			'telefone'  => '(82) 90000-0001',
			'endereco'  => array(
				'rua'    => 'Rua das Palmeiras, 120',
				'cidade' => 'Maceió',
				'estado' => 'AL',
				'cep'    => '57000-000',
			),
			'cor'       => '#CF6442',
			'entrega'   => array(
				'tempo'     => '30-45 min',
				'taxa'      => '0',
				'distancia' => '2,4 km',
			),
			'avaliacoes' => array( 5, 5, 4 ),
			'produtos'  => array(
				array(
					'nome'      => 'Goma de tapioca artesanal 500 g',
					'preco'     => '18.90',
					'resumo'    => 'Goma hidratada, pronta para o preparo, sem conservantes.',
				),
				array(
					'nome'       => 'Doce de banana em barra 300 g',
					'preco'      => '24.00',
					'promocional' => '19.90',
					'resumo'     => 'Receita tradicional, cozido lentamente em tacho de cobre.',
				),
				array(
					'nome'   => 'Mel silvestre puro 500 ml',
					'preco'  => '42.00',
					'resumo' => 'Colhido em apiários familiares, sem adição de açúcar.',
				),
			),
		),

		array(
			'login'     => 'demo-atelie-raizes',
			'email'     => 'atelie.raizes@exemplo.invalid',
			'nome'      => 'Ateliê Raízes',
			'primeiro'  => 'Inês',
			'ultimo'    => 'Barbosa',
			'categoria' => 'artesanato',
			'descricao' => 'Ateliê coletivo de bordado e cerâmica, formado por mulheres da comunidade.',
			'telefone'  => '(82) 90000-0002',
			'endereco'  => array(
				'rua'    => 'Travessa do Farol, 45',
				'cidade' => 'Maceió',
				'estado' => 'AL',
				'cep'    => '57010-000',
			),
			'cor'       => '#663191',
			'entrega'   => array(
				'tempo'     => '45-60 min',
				'taxa'      => '9.90',
				'distancia' => '4,6 km',
			),
			'avaliacoes' => array( 5, 4, 5, 5 ),
			'produtos'  => array(
				array(
					'nome'   => 'Toalha bordada à mão',
					'preco'  => '89.00',
					'resumo' => 'Bordado em ponto cheio sobre algodão cru, peça única.',
				),
				array(
					'nome'   => 'Vaso de cerâmica esmaltada',
					'preco'  => '135.00',
					'resumo' => 'Torneado e esmaltado artesanalmente, 22 cm de altura.',
				),
				array(
					'nome'        => 'Jogo americano em fibra natural',
					'preco'       => '64.00',
					'promocional' => '54.00',
					'resumo'      => 'Conjunto com quatro peças trançadas em palha de ouricuri.',
				),
			),
		),

		array(
			'login'     => 'demo-moda-reconecta',
			'email'     => 'moda.reconecta@exemplo.invalid',
			'nome'      => 'Moda Reconecta',
			'primeiro'  => 'Cauã',
			'ultimo'    => 'Ferreira',
			'categoria' => 'moda-e-acessorios',
			'descricao' => 'Confecção de roupas em algodão com upcycling de tecidos excedentes.',
			'telefone'  => '(82) 90000-0003',
			'endereco'  => array(
				'rua'    => 'Avenida Central, 980',
				'cidade' => 'Arapiraca',
				'estado' => 'AL',
				'cep'    => '57300-000',
			),
			'cor'       => '#31BEB1',
			'entrega'   => array(
				'tempo'     => '2-4 dias',
				'taxa'      => '14.90',
				'distancia' => '18,2 km',
			),
			'avaliacoes' => array( 4, 4, 5 ),
			'produtos'  => array(
				array(
					'nome'   => 'Camiseta de algodão orgânico',
					'preco'  => '79.00',
					'resumo' => 'Malha 100% algodão certificado, tingimento natural.',
				),
				array(
					'nome'   => 'Bolsa de lona reaproveitada',
					'preco'  => '115.00',
					'resumo' => 'Costurada a partir de lonas excedentes, forro interno reforçado.',
				),
				array(
					'nome'        => 'Lenço estampado à mão',
					'preco'       => '58.00',
					'promocional' => '45.00',
					'resumo'      => 'Estampa aplicada em carimbo de madeira, peça a peça.',
				),
			),
		),

		array(
			'login'     => 'demo-casa-viva',
			'email'     => 'casa.viva@exemplo.invalid',
			'nome'      => 'Casa Viva',
			'primeiro'  => 'Marcelo',
			'ultimo'    => 'Nunes',
			'categoria' => 'casa-e-decoracao',
			'descricao' => 'Marcenaria de pequeno porte que recupera madeira de demolição.',
			'telefone'  => '(82) 90000-0004',
			'endereco'  => array(
				'rua'    => 'Rua do Sol, 301',
				'cidade' => 'Penedo',
				'estado' => 'AL',
				'cep'    => '57200-000',
			),
			'cor'       => '#F1BF3D',
			'entrega'   => array(
				'tempo'     => '3-5 dias',
				'taxa'      => '0',
				'distancia' => '22,7 km',
			),
			'avaliacoes' => array( 5, 4 ),
			'produtos'  => array(
				array(
					'nome'   => 'Luminária de mesa em madeira',
					'preco'  => '189.00',
					'resumo' => 'Base em madeira de demolição com acabamento em óleo vegetal.',
				),
				array(
					'nome'   => 'Prateleira suspensa 60 cm',
					'preco'  => '142.00',
					'resumo' => 'Madeira maciça recuperada, com suportes em ferro pintado.',
				),
				array(
					'nome'   => 'Porta-retratos artesanal',
					'preco'  => '48.00',
					'resumo' => 'Moldura montada peça a peça, comporta foto 15 x 21 cm.',
				),
			),
		),

		array(
			'login'     => 'demo-bem-viver',
			'email'     => 'bem.viver@exemplo.invalid',
			'nome'      => 'Bem Viver Natural',
			'primeiro'  => 'Sandra',
			'ultimo'    => 'Lopes',
			'categoria' => 'beleza-e-cuidados',
			'descricao' => 'Produção de sabonetes e óleos vegetais a partir de plantas da região.',
			'telefone'  => '(82) 90000-0005',
			'endereco'  => array(
				'rua'    => 'Praça da Matriz, 12',
				'cidade' => 'Marechal Deodoro',
				'estado' => 'AL',
				'cep'    => '57160-000',
			),
			'cor'       => '#31BEB1',
			'entrega'   => array(
				'tempo'     => '60-90 min',
				'taxa'      => '7.50',
				'distancia' => '8,3 km',
			),
			'avaliacoes' => array( 5, 5, 5, 4 ),
			'produtos'  => array(
				array(
					'nome'   => 'Sabonete de argila e erva-doce',
					'preco'  => '22.00',
					'resumo' => 'Saponificado a frio, sem corantes nem fragrância sintética.',
				),
				array(
					'nome'        => 'Óleo corporal de coco e buriti',
					'preco'       => '68.00',
					'promocional' => '56.00',
					'resumo'      => 'Extração a frio, envasado em vidro âmbar de 120 ml.',
				),
				array(
					'nome'   => 'Kit cuidado diário',
					'preco'  => '98.00',
					'resumo' => 'Sabonete, óleo corporal e esponja vegetal em embalagem de papel.',
				),
			),
		),
	),

	/*
	 * Textos das avaliações. São percorridos em rodízio: a loja define quantas
	 * estrelas cada avaliação recebe (`avaliacoes`) e o texto vem daqui, na
	 * ordem. Manter os textos genéricos é proposital — eles precisam servir a
	 * qualquer produto do catálogo.
	 */
	'avaliacoes' => array(
		array(
			'autor'    => 'Cliente de demonstração',
			'email'    => 'avaliacao1@exemplo.invalid',
			'conteudo' => 'Produto chegou bem embalado e dentro do prazo combinado. Recomendo a loja.',
		),
		array(
			'autor'    => 'Cliente de demonstração',
			'email'    => 'avaliacao2@exemplo.invalid',
			'conteudo' => 'Qualidade bem acima do que eu esperava pelo preço. Já comprei de novo.',
		),
		array(
			'autor'    => 'Cliente de demonstração',
			'email'    => 'avaliacao3@exemplo.invalid',
			'conteudo' => 'Atendimento atencioso e entrega rápida. Só senti falta de mais opções de tamanho.',
		),
		array(
			'autor'    => 'Cliente de demonstração',
			'email'    => 'avaliacao4@exemplo.invalid',
			'conteudo' => 'Gostei bastante do acabamento. Dá para ver que é feito com cuidado.',
		),
	),

	/*
	 * Clientes (usuários com papel `customer`).
	 *
	 * Existem para que o roteiro de demonstração possa percorrer a jornada de
	 * compra de verdade — entrar, ver o histórico, acompanhar um pedido — em
	 * vez de descrevê-la. Todos entram com a mesma senha, definida em
	 * `demo.php`; são contas de ambiente local, não credenciais de sistema.
	 */
	'clientes'   => array(
		array(
			'login'    => 'demo-cliente-ana',
			'email'    => 'ana.lima@exemplo.invalid',
			'primeiro' => 'Ana',
			'ultimo'   => 'Lima',
			'telefone' => '(82) 90000-1001',
			'endereco' => array(
				'rua'    => 'Rua Sá e Albuquerque, 300',
				'cidade' => 'Maceió',
				'estado' => 'AL',
				'cep'    => '57020-000',
			),
		),
		array(
			'login'    => 'demo-cliente-joao',
			'email'    => 'joao.ferreira@exemplo.invalid',
			'primeiro' => 'João',
			'ultimo'   => 'Ferreira',
			'telefone' => '(82) 90000-1002',
			'endereco' => array(
				'rua'    => 'Avenida Ceci Cunha, 870',
				'cidade' => 'Arapiraca',
				'estado' => 'AL',
				'cep'    => '57300-000',
			),
		),
		array(
			'login'    => 'demo-cliente-marina',
			'email'    => 'marina.costa@exemplo.invalid',
			'primeiro' => 'Marina',
			'ultimo'   => 'Costa',
			'telefone' => '(82) 90000-1003',
			'endereco' => array(
				'rua'    => 'Rua Siqueira Campos, 52',
				'cidade' => 'Penedo',
				'estado' => 'AL',
				'cep'    => '57200-000',
			),
		),
	),

	/*
	 * Pedidos.
	 *
	 * A lista cobre de propósito os cinco status do fluxo da plataforma
	 * (realizado → pago → preparação → enviado → entregue) e os três meios de
	 * pagamento previstos, de modo que quem abrir o painel encontre pelo menos
	 * um pedido em cada situação — inclusive um pedido com itens de três lojas
	 * diferentes, que o Dokan divide em um sub-pedido por vendedor.
	 *
	 * `itens` referencia produtos pelo nome exato declarado acima; `demo.php`
	 * resolve o nome para o produto pelo mesmo slug usado na criação. Um nome
	 * que não case é reportado no log em vez de virar um pedido vazio.
	 *
	 * `dias` é a idade do pedido em dias. Um histórico em que tudo aconteceu
	 * hoje não se parece com uma loja em operação, e a coluna de data do painel
	 * ficaria inútil para demonstrar ordenação.
	 */
	'pedidos'    => array(
		array(
			'chave'     => 'ped-001',
			'cliente'   => 'demo-cliente-ana',
			'status'    => 'wc-completed',
			'pagamento' => 'pix',
			'dias'      => 24,
			'itens'     => array(
				array( 'produto' => 'Goma de tapioca artesanal 500 g', 'quantidade' => 2 ),
				array( 'produto' => 'Mel silvestre puro 500 ml', 'quantidade' => 1 ),
			),
		),
		array(
			'chave'     => 'ped-002',
			'cliente'   => 'demo-cliente-joao',
			'status'    => 'wc-enviado',
			'pagamento' => 'cartao',
			'dias'      => 6,
			'itens'     => array(
				array( 'produto' => 'Camiseta de algodão orgânico', 'quantidade' => 2 ),
				array( 'produto' => 'Bolsa de lona reaproveitada', 'quantidade' => 1 ),
			),
		),
		array(
			'chave'     => 'ped-003',
			'cliente'   => 'demo-cliente-marina',
			'status'    => 'wc-preparacao',
			'pagamento' => 'boleto',
			'dias'      => 3,
			'itens'     => array(
				array( 'produto' => 'Luminária de mesa em madeira', 'quantidade' => 1 ),
			),
		),
		array(
			'chave'     => 'ped-004',
			'cliente'   => 'demo-cliente-ana',
			'status'    => 'wc-processing',
			'pagamento' => 'pix',
			'dias'      => 2,
			'itens'     => array(
				array( 'produto' => 'Toalha bordada à mão', 'quantidade' => 1 ),
				array( 'produto' => 'Vaso de cerâmica esmaltada', 'quantidade' => 2 ),
			),
		),
		array(
			'chave'     => 'ped-005',
			'cliente'   => 'demo-cliente-joao',
			'status'    => 'wc-pending',
			'pagamento' => 'boleto',
			'dias'      => 1,
			'itens'     => array(
				array( 'produto' => 'Kit cuidado diário', 'quantidade' => 1 ),
			),
		),

		/*
		 * Pedido multi-vendedor. É o caso que justifica o `maybe_split_orders()`
		 * do Dokan: o cliente fecha uma compra só e cada loja recebe, no painel
		 * dela, apenas a parte que lhe cabe.
		 */
		array(
			'chave'     => 'ped-006',
			'cliente'   => 'demo-cliente-marina',
			'status'    => 'wc-processing',
			'pagamento' => 'cartao',
			'dias'      => 1,
			'itens'     => array(
				array( 'produto' => 'Doce de banana em barra 300 g', 'quantidade' => 3 ),
				array( 'produto' => 'Jogo americano em fibra natural', 'quantidade' => 1 ),
				array( 'produto' => 'Sabonete de argila e erva-doce', 'quantidade' => 2 ),
			),
		),
	),

	/*
	 * Rótulos dos meios de pagamento.
	 *
	 * PIX e boleto não têm gateway instalado nesta plataforma — a definição do
	 * provedor de pagamento é decisão do projeto, não do seed. O que se grava
	 * aqui é o registro de qual meio foi usado, que é o que o painel exibe e o
	 * que o fluxo de pedido precisa saber. Quando um gateway real entrar, a
	 * chave `id` passa a ser a dele e nada mais muda.
	 */
	'pagamentos' => array(
		'pix'    => array(
			'id'     => 'reconectar_pix',
			'titulo' => 'PIX',
		),
		'cartao' => array(
			'id'     => 'reconectar_cartao',
			'titulo' => 'Cartão de crédito',
		),
		'boleto' => array(
			'id'     => 'reconectar_boleto',
			'titulo' => 'Boleto bancário',
		),
	),
);
