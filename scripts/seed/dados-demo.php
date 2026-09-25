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
	 * Empresas (CPT `reconectar_empresa`).
	 *
	 * Cada empresa agrupa vendedores. A distribuição reproduz de propósito o
	 * exemplo da especificação — uma empresa com três vendedores e outra com
	 * dois —, porque é dela que o roteiro de demonstração precisa: sem duas
	 * empresas povoadas não há como mostrar o isolamento, que é a regra de
	 * negócio central deste ator.
	 *
	 * `slug` não é campo da entidade: é a chave que amarra este catálogo. As
	 * lojas e os administradores abaixo se referem à empresa por ele, e
	 * `demo.php` o grava como `post_name` para reencontrar a empresa na
	 * execução seguinte sem depender do título.
	 */
	'empresas'   => array(
		array(
			'slug'         => 'nosso-chao',
			'nome'         => 'Cooperativa Nosso Chão',
			'razao_social' => 'Cooperativa Nosso Chão de Produção Solidária Ltda.',
			// CNPJ fictício que passa nos dígitos verificadores. A validação de
			// `Reconectar_Empresa::normalizar_campo()` confere os dois últimos
			// dígitos, e o `…/0001-00` que estava aqui antes era recusado por ela —
			// a carga gravava por baixo, via `salvar()`, um dado que o formulário
			// jamais aceitaria. Base zerada de propósito: não corresponde a empresa
			// real nenhuma.
			'cnpj'         => '00.000.000/0001-91',
			'responsavel'  => 'Teresa Nogueira',
			'email'        => 'nosso.chao@exemplo.invalid',
			'telefone'     => '(82) 90000-2001',
			'municipio'    => 'Maceió',
			'uf'           => 'AL',
		),
		array(
			'slug'         => 'bem-viver',
			'nome'         => 'Rede Bem Viver',
			'razao_social' => 'Rede Bem Viver de Economia Criativa Ltda.',
			'cnpj'         => '00.000.000/0002-72',
			'responsavel'  => 'Otávio Meireles',
			'email'        => 'bem.viver@exemplo.invalid',
			'telefone'     => '(82) 90000-2002',
			'municipio'    => 'Arapiraca',
			'uf'           => 'AL',
		),
	),

	/*
	 * Administradores de Empresas (papel `company_admin`).
	 *
	 * São três de propósito, e não dois: os dois primeiros administram uma
	 * empresa cada — é entrando com eles que a demonstração mostra que o da
	 * Nosso Chão não enxerga a Bem Viver —, e o terceiro tem
	 * `reconectar_gerir_todas_as_empresas`, o caso de alcance múltiplo previsto
	 * na especificação. Sem o terceiro, a capacidade existiria no código sem
	 * ninguém para exercê-la, e uma regressão nela passaria despercebida.
	 *
	 * `empresas` lista slugs do catálogo acima. `todas` substitui a lista pela
	 * capacidade de escopo global; quando é `true`, `empresas` é ignorado.
	 */
	'administradores_de_empresa' => array(
		array(
			'login'    => 'demo-admin-nosso-chao',
			'email'    => 'teresa.nogueira@exemplo.invalid',
			'primeiro' => 'Teresa',
			'ultimo'   => 'Nogueira',
			'empresas' => array( 'nosso-chao' ),
		),
		array(
			'login'    => 'demo-admin-bem-viver',
			'email'    => 'otavio.meireles@exemplo.invalid',
			'primeiro' => 'Otávio',
			'ultimo'   => 'Meireles',
			'empresas' => array( 'bem-viver' ),
		),
		array(
			'login'    => 'demo-admin-rede',
			'email'    => 'clara.viana@exemplo.invalid',
			'primeiro' => 'Clara',
			'ultimo'   => 'Viana',
			'todas'    => true,
		),
	),

	/*
	 * Lojas (usuários com papel `seller` no Dokan) e seus produtos.
	 *
	 * `empresa` casa com o slug de uma das empresas acima e é o que coloca a
	 * loja sob a gestão de um Administrador de Empresas. Uma loja sem este
	 * campo continua funcionando como vendedor independente — o vínculo é
	 * opcional na plataforma, e a carga não o inventa onde não foi declarado.
	 *
	 * `categoria` casa com o slug de uma das categorias acima: é usada tanto
	 * para preencher o campo de categoria do perfil da loja quanto como
	 * categoria padrão dos produtos que não declaram a sua.
	 *
	 * `subcategorias` são termos filhos dessa categoria, criados sob ela. São o
	 * que divide o catálogo em seções na página da loja: "Alimentos e Bebidas"
	 * vale para tudo que a loja vende e não separa nada, enquanto "Farináceos" e
	 * "Bebidas" separam. Cada produto declara a sua em `categoria`, e a carga
	 * atribui as duas ao produto — é a categoria-mãe que mantém o filtro da
	 * vitrine e a dedução de `reconectar_categoria_principal_da_loja()`
	 * funcionando.
	 *
	 * `horario` é o funcionamento semanal, com as chaves de dia em inglês porque
	 * é assim que o Dokan grava (`dokan_store_time`) e é assim que
	 * `dokan_is_store_open()` procura. Dia declarado como array vazio é dia
	 * fechado. Os horários seguem o formato de 12 horas com sufixo, único que
	 * `DateTime::modify()` interpreta sem ambiguidade — é o que o plugin faz com
	 * o valor. Uma loja sem esta chave não exibe linha de horário nenhuma: a
	 * diferença entre "está fechada" e "não informou" é o que decide se o
	 * cliente volta amanhã ou desiste da loja.
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
			'empresa'   => 'nosso-chao',
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
			'subcategorias' => array(
				'farinaceos'        => 'Farináceos',
				'doces-e-conservas' => 'Doces e conservas',
				'bebidas-da-terra'  => 'Bebidas',
			),
			'horario'   => array(
				'monday'    => array( 'abre' => '07:00 am', 'fecha' => '05:00 pm' ),
				'tuesday'   => array( 'abre' => '07:00 am', 'fecha' => '05:00 pm' ),
				'wednesday' => array( 'abre' => '07:00 am', 'fecha' => '05:00 pm' ),
				'thursday'  => array( 'abre' => '07:00 am', 'fecha' => '05:00 pm' ),
				'friday'    => array( 'abre' => '07:00 am', 'fecha' => '05:00 pm' ),
				'saturday'  => array( 'abre' => '07:00 am', 'fecha' => '01:00 pm' ),
				'sunday'    => array(),
			),
			'avaliacoes' => array( 5, 5, 4 ),
			'produtos'  => array(
				array(
					'nome'      => 'Goma de tapioca artesanal 500 g',
					'preco'     => '18.90',
					'categoria' => 'farinaceos',
					'resumo'    => 'Goma hidratada, pronta para o preparo, sem conservantes.',
				),
				array(
					'nome'       => 'Doce de banana em barra 300 g',
					'preco'      => '24.00',
					'promocional' => '19.90',
					'categoria'  => 'doces-e-conservas',
					'resumo'     => 'Receita tradicional, cozido lentamente em tacho de cobre.',
				),
				array(
					'nome'      => 'Mel silvestre puro 500 ml',
					'preco'     => '42.00',
					'categoria' => 'doces-e-conservas',
					'resumo'    => 'Colhido em apiários familiares, sem adição de açúcar.',
				),
				array(
					'nome'      => 'Farinha de mandioca torrada 1 kg',
					'preco'     => '16.00',
					'categoria' => 'farinaceos',
					'resumo'    => 'Torrada em forno a lenha, de mandioca colhida na semana.',
				),
				array(
					'nome'      => 'Tapioca granulada 500 g',
					'preco'     => '14.50',
					'categoria' => 'farinaceos',
					'resumo'    => 'Grão uniforme, rende bolo e tapioca de colher.',
				),
				array(
					'nome'        => 'Geleia de caju 250 g',
					'preco'       => '26.00',
					'promocional' => '21.00',
					'categoria'   => 'doces-e-conservas',
					'resumo'      => 'Feita com caju maduro da safra, sem pectina industrial.',
				),
				array(
					'nome'      => 'Suco de acerola integral 1 L',
					'preco'     => '19.00',
					'categoria' => 'bebidas-da-terra',
					'resumo'    => 'Prensado e envasado no mesmo dia, sem água nem açúcar.',
				),
				array(
					'nome'      => 'Café torrado em grãos 250 g',
					'preco'     => '38.00',
					'categoria' => 'bebidas-da-terra',
					'resumo'    => 'Torra média, de lavoura familiar do agreste alagoano.',
				),
			),
		),

		array(
			'login'     => 'demo-atelie-raizes',
			'empresa'   => 'nosso-chao',
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
			'subcategorias' => array(
				'bordados'        => 'Bordados',
				'ceramica'        => 'Cerâmica',
				'fibras-naturais' => 'Fibras naturais',
			),
			// Ateliê que só funciona de manhã. É a loja que demonstra o estado
			// "Fechada agora": uma apresentação feita à tarde — e são quase todas —
			// encontraria todas as outras abertas, e o estado fechado nunca
			// apareceria na tela.
			'horario'   => array(
				'monday'    => array(),
				'tuesday'   => array( 'abre' => '08:00 am', 'fecha' => '12:00 pm' ),
				'wednesday' => array( 'abre' => '08:00 am', 'fecha' => '12:00 pm' ),
				'thursday'  => array( 'abre' => '08:00 am', 'fecha' => '12:00 pm' ),
				'friday'    => array( 'abre' => '08:00 am', 'fecha' => '12:00 pm' ),
				'saturday'  => array( 'abre' => '09:00 am', 'fecha' => '11:00 am' ),
				'sunday'    => array(),
			),
			'avaliacoes' => array( 5, 4, 5, 5 ),
			'produtos'  => array(
				array(
					'nome'      => 'Toalha bordada à mão',
					'preco'     => '89.00',
					'categoria' => 'bordados',
					'resumo'    => 'Bordado em ponto cheio sobre algodão cru, peça única.',
				),
				array(
					'nome'      => 'Vaso de cerâmica esmaltada',
					'preco'     => '135.00',
					'categoria' => 'ceramica',
					'resumo'    => 'Torneado e esmaltado artesanalmente, 22 cm de altura.',
				),
				array(
					'nome'        => 'Jogo americano em fibra natural',
					'preco'       => '64.00',
					'promocional' => '54.00',
					'categoria'   => 'fibras-naturais',
					'resumo'      => 'Conjunto com quatro peças trançadas em palha de ouricuri.',
				),
				array(
					'nome'      => 'Caminho de mesa bordado',
					'preco'     => '72.00',
					'categoria' => 'bordados',
					'resumo'    => 'Dois metros de linho com barrado em ponto cruz.',
				),
				array(
					'nome'        => 'Almofada com bordado livre',
					'preco'       => '95.00',
					'promocional' => '79.00',
					'categoria'   => 'bordados',
					'resumo'      => 'Capa de 45 x 45 cm com desenho criado pela bordadeira.',
				),
				array(
					'nome'      => 'Caneca de cerâmica torneada',
					'preco'     => '58.00',
					'categoria' => 'ceramica',
					'resumo'    => 'Queima em alta temperatura, 300 ml, pode ir ao micro-ondas.',
				),
				array(
					'nome'      => 'Tigela de cerâmica rústica',
					'preco'     => '76.00',
					'categoria' => 'ceramica',
					'resumo'    => 'Esmalte fosco aplicado à mão, 18 cm de diâmetro.',
				),
				array(
					'nome'      => 'Cesto trançado em palha',
					'preco'     => '88.00',
					'categoria' => 'fibras-naturais',
					'resumo'    => 'Trançado em palha de ouricuri, com alças reforçadas.',
				),
			),
		),

		array(
			'login'     => 'demo-moda-reconecta',
			'empresa'   => 'nosso-chao',
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
			'subcategorias' => array(
				'roupas'             => 'Roupas',
				'bolsas-e-mochilas'  => 'Bolsas e mochilas',
				'acessorios-de-moda' => 'Acessórios',
			),
			'horario'   => array(
				'monday'    => array( 'abre' => '09:00 am', 'fecha' => '07:00 pm' ),
				'tuesday'   => array( 'abre' => '09:00 am', 'fecha' => '07:00 pm' ),
				'wednesday' => array( 'abre' => '09:00 am', 'fecha' => '07:00 pm' ),
				'thursday'  => array( 'abre' => '09:00 am', 'fecha' => '07:00 pm' ),
				'friday'    => array( 'abre' => '09:00 am', 'fecha' => '07:00 pm' ),
				'saturday'  => array( 'abre' => '09:00 am', 'fecha' => '02:00 pm' ),
				'sunday'    => array(),
			),
			'avaliacoes' => array( 4, 4, 5 ),
			'produtos'  => array(
				array(
					'nome'      => 'Camiseta de algodão orgânico',
					'preco'     => '79.00',
					'categoria' => 'roupas',
					'resumo'    => 'Malha 100% algodão certificado, tingimento natural.',
				),
				array(
					'nome'      => 'Bolsa de lona reaproveitada',
					'preco'     => '115.00',
					'categoria' => 'bolsas-e-mochilas',
					'resumo'    => 'Costurada a partir de lonas excedentes, forro interno reforçado.',
				),
				array(
					'nome'        => 'Lenço estampado à mão',
					'preco'       => '58.00',
					'promocional' => '45.00',
					'categoria'   => 'acessorios-de-moda',
					'resumo'      => 'Estampa aplicada em carimbo de madeira, peça a peça.',
				),
				array(
					'nome'      => 'Calça de tecido leve',
					'preco'     => '138.00',
					'categoria' => 'roupas',
					'resumo'    => 'Corte reto em viscose de origem controlada, com bolsos fundos.',
				),
				array(
					'nome'        => 'Camisa de linho natural',
					'preco'       => '168.00',
					'promocional' => '139.00',
					'categoria'   => 'roupas',
					'resumo'      => 'Linho sem tingimento, costura reforçada nos ombros.',
				),
				array(
					'nome'      => 'Mochila de algodão encerado',
					'preco'     => '189.00',
					'categoria' => 'bolsas-e-mochilas',
					'resumo'    => 'Encerada à mão, comporta notebook de até 15 polegadas.',
				),
				array(
					'nome'      => 'Necessaire de retalhos',
					'preco'     => '62.00',
					'categoria' => 'bolsas-e-mochilas',
					'resumo'    => 'Montada com sobras da produção, nenhuma peça igual à outra.',
				),
				array(
					'nome'      => 'Chapéu de palha trançada',
					'preco'     => '92.00',
					'categoria' => 'acessorios-de-moda',
					'resumo'    => 'Aba larga com fita de algodão cru, trançado à mão.',
				),
			),
		),

		array(
			'login'     => 'demo-casa-viva',
			'empresa'   => 'bem-viver',
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
			'subcategorias' => array(
				'iluminacao'           => 'Iluminação',
				'moveis-e-prateleiras' => 'Móveis e prateleiras',
				'decoracao'            => 'Decoração',
			),
			// Marcenaria que não abre no fim de semana: é o caso que mostra a linha
			// "Não abre hoje" em vez de um intervalo.
			'horario'   => array(
				'monday'    => array( 'abre' => '08:00 am', 'fecha' => '06:00 pm' ),
				'tuesday'   => array( 'abre' => '08:00 am', 'fecha' => '06:00 pm' ),
				'wednesday' => array( 'abre' => '08:00 am', 'fecha' => '06:00 pm' ),
				'thursday'  => array( 'abre' => '08:00 am', 'fecha' => '06:00 pm' ),
				'friday'    => array( 'abre' => '08:00 am', 'fecha' => '06:00 pm' ),
				'saturday'  => array(),
				'sunday'    => array(),
			),
			'avaliacoes' => array( 5, 4 ),
			'produtos'  => array(
				array(
					'nome'      => 'Luminária de mesa em madeira',
					'preco'     => '189.00',
					'categoria' => 'iluminacao',
					'resumo'    => 'Base em madeira de demolição com acabamento em óleo vegetal.',
				),
				array(
					'nome'      => 'Prateleira suspensa 60 cm',
					'preco'     => '142.00',
					'categoria' => 'moveis-e-prateleiras',
					'resumo'    => 'Madeira maciça recuperada, com suportes em ferro pintado.',
				),
				array(
					'nome'      => 'Porta-retratos artesanal',
					'preco'     => '48.00',
					'categoria' => 'decoracao',
					'resumo'    => 'Moldura montada peça a peça, comporta foto 15 x 21 cm.',
				),
				array(
					'nome'      => 'Abajur de madeira e linho',
					'preco'     => '215.00',
					'categoria' => 'iluminacao',
					'resumo'    => 'Cúpula em linho cru sobre base torneada, soquete E27.',
				),
				array(
					'nome'      => 'Banqueta de madeira maciça',
					'preco'     => '246.00',
					'categoria' => 'moveis-e-prateleiras',
					'resumo'    => 'Assento de 45 cm em peroba recuperada, encaixes cavilhados.',
				),
				array(
					'nome'        => 'Mesa lateral de demolição',
					'preco'       => '389.00',
					'promocional' => '329.00',
					'categoria'   => 'moveis-e-prateleiras',
					'resumo'      => 'Tampo de 50 cm com marcas originais da madeira preservadas.',
				),
				array(
					'nome'      => 'Cabideiro de parede',
					'preco'     => '96.00',
					'categoria' => 'decoracao',
					'resumo'    => 'Cinco ganchos em ferro sobre régua de madeira de 70 cm.',
				),
				array(
					'nome'      => 'Bandeja de servir em madeira',
					'preco'     => '118.00',
					'categoria' => 'decoracao',
					'resumo'    => 'Alças vazadas e acabamento em cera de carnaúba.',
				),
			),
		),

		array(
			'login'     => 'demo-bem-viver',
			'empresa'   => 'bem-viver',
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
			'subcategorias' => array(
				'sabonetes'           => 'Sabonetes',
				'oleos-e-hidratantes' => 'Óleos e hidratantes',
				'kits-e-presentes'    => 'Kits e presentes',
			),
			'horario'   => array(
				'monday'    => array( 'abre' => '08:00 am', 'fecha' => '08:00 pm' ),
				'tuesday'   => array( 'abre' => '08:00 am', 'fecha' => '08:00 pm' ),
				'wednesday' => array( 'abre' => '08:00 am', 'fecha' => '08:00 pm' ),
				'thursday'  => array( 'abre' => '08:00 am', 'fecha' => '08:00 pm' ),
				'friday'    => array( 'abre' => '08:00 am', 'fecha' => '08:00 pm' ),
				'saturday'  => array( 'abre' => '08:00 am', 'fecha' => '06:00 pm' ),
				'sunday'    => array( 'abre' => '09:00 am', 'fecha' => '01:00 pm' ),
			),
			'avaliacoes' => array( 5, 5, 5, 4 ),
			'produtos'  => array(
				array(
					'nome'      => 'Sabonete de argila e erva-doce',
					'preco'     => '22.00',
					'categoria' => 'sabonetes',
					'resumo'    => 'Saponificado a frio, sem corantes nem fragrância sintética.',
				),
				array(
					'nome'        => 'Óleo corporal de coco e buriti',
					'preco'       => '68.00',
					'promocional' => '56.00',
					'categoria'   => 'oleos-e-hidratantes',
					'resumo'      => 'Extração a frio, envasado em vidro âmbar de 120 ml.',
				),
				array(
					'nome'      => 'Kit cuidado diário',
					'preco'     => '98.00',
					'categoria' => 'kits-e-presentes',
					'resumo'    => 'Sabonete, óleo corporal e esponja vegetal em embalagem de papel.',
				),
				array(
					'nome'      => 'Sabonete de carvão ativado',
					'preco'     => '24.00',
					'categoria' => 'sabonetes',
					'resumo'    => 'Barra de 100 g com argila preta, indicada para pele oleosa.',
				),
				array(
					'nome'      => 'Sabonete de aveia e mel',
					'preco'     => '23.00',
					'categoria' => 'sabonetes',
					'resumo'    => 'Esfoliação suave com aveia em flocos e mel da própria região.',
				),
				array(
					'nome'      => 'Óleo capilar de pequi',
					'preco'     => '72.00',
					'categoria' => 'oleos-e-hidratantes',
					'resumo'    => 'Pequi do cerrado prensado a frio, frasco de 60 ml.',
				),
				array(
					'nome'        => 'Manteiga corporal de karité',
					'preco'       => '84.00',
					'promocional' => '69.00',
					'categoria'   => 'oleos-e-hidratantes',
					'resumo'      => 'Karité puro batido com óleo de girassol, pote de 200 g.',
				),
				array(
					'nome'      => 'Kit presente aromas do sertão',
					'preco'     => '145.00',
					'categoria' => 'kits-e-presentes',
					'resumo'    => 'Três sabonetes, um óleo capilar e um sachê de ervas em caixa de papelão.',
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

	/*
	 * Fórum de perguntas e respostas.
	 *
	 * As `categorias` viram fóruns do bbPress; as `perguntas`, tópicos; e cada
	 * `respostas`, uma resposta. O `autor` é o login de um usuário que a própria
	 * carga cria — sempre vendedor ou administrador de empresa, nunca cliente:
	 * o fórum é restrito aos papéis da comunidade, e um cliente perguntando aqui
	 * contradiria a regra que a demonstração existe para mostrar.
	 *
	 * `votos` e `visualizacoes` são números declarados, não calculados. Isso é
	 * deliberado e é o oposto de inventar: o que a plataforma nunca faz é
	 * estimar por fórmula a audiência de uma pergunta real. Estes são dados
	 * fictícios explícitos, marcados como tal no banco e sob a faixa de aviso
	 * que `Reconectar_Aviso_Demo` imprime.
	 *
	 * `votos` é pequeno de propósito, e o teto não é estético: cada voto da
	 * carga é um votante de verdade na lista `_reconectar_votantes`, porque
	 * `Reconectar_Forum::votar()` recalcula o saldo dessa lista a cada voto —
	 * `array_sum()`, nunca incremento. Um saldo declarado sem votantes por trás
	 * sobreviveria à carga e morreria no primeiro voto real, caindo de nove para
	 * um sem nada na tela explicando a queda. O limite é, portanto, quantos
	 * usuários da comunidade a carga cria, menos o autor — que não vota no que
	 * escreveu.
	 *
	 * `melhor` é o índice, dentro de `respostas`, daquela que fica marcada como
	 * aceita — ou ausente, quando a pergunta ainda não tem resposta escolhida.
	 * `dias` é há quantos dias a pergunta foi publicada, para que a aba
	 * "Recentes" e o bloco "Em alta" tenham o que ordenar.
	 */
	'forum'      => array(

		'categorias' => array(
			array(
				'nome'      => 'Produção e matéria-prima',
				'descricao' => 'Onde comprar, como armazenar e como calcular o custo do que entra no produto.',
			),
			array(
				'nome'      => 'Vendas e precificação',
				'descricao' => 'Formar preço, negociar frete, entender taxa e margem.',
			),
			array(
				'nome'      => 'Entrega e logística',
				'descricao' => 'Embalagem, prazos, transportadora e o que fazer quando algo se perde no caminho.',
			),
			array(
				'nome'      => 'A plataforma',
				'descricao' => 'Dúvidas sobre o painel do vendedor, cadastro de produto e pedidos.',
			),
		),

		'perguntas'  => array(
			array(
				'titulo'         => 'Como vocês calculam o preço de um produto artesanal sem trabalhar de graça?',
				'categoria'      => 'Vendas e precificação',
				'autor'          => 'demo-atelie-raizes',
				'tags'           => array( 'precificação', 'custos', 'artesanato' ),
				'dias'           => 26,
				'votos'          => 5,
				'visualizacoes'  => 184,
				'conteudo'       => 'Levo cerca de seis horas para fazer uma peça de cerâmica, contando a queima. Quando somo material e ponho a hora de trabalho, o preço fica bem acima do que vejo na feira. Como vocês resolvem isso na prática?',
				'melhor'         => 0,
				'respostas'      => array(
					array(
						'autor'     => 'demo-casa-viva',
						'votos'     => 5,
						'dias'      => 25,
						'conteudo'  => 'O que mudou para mim foi separar as duas contas. Primeiro o custo direto: material, embalagem, energia da queima, a taxa da plataforma. Depois a hora de trabalho, com um valor que eu escolhi e não negocio. O preço de feira costuma estar abaixo porque muita gente não põe a segunda conta — e fica insustentável no ano seguinte.',
					),
					array(
						'autor'     => 'demo-bem-viver',
						'votos'     => 2,
						'dias'      => 24,
						'conteudo'  => 'Acrescento uma coisa: peça difícil pode ter preço de peça difícil. Eu mantenho uma linha mais simples para girar e umas poucas peças de assinatura. Quem compra a de assinatura já sabe que está pagando as seis horas.',
					),
				),
			),
			array(
				'titulo'         => 'Qual embalagem vocês usam para cerâmica em viagem longa?',
				'categoria'      => 'Entrega e logística',
				'autor'          => 'demo-casa-viva',
				'tags'           => array( 'embalagem', 'frete', 'cerâmica' ),
				'dias'           => 19,
				'votos'          => 4,
				'visualizacoes'  => 132,
				'conteudo'       => 'Duas peças quebraram no mês passado indo para fora do estado. Estou usando plástico-bolha e caixa de papelão comum. Vale a pena caixa dupla, ou o problema é outro?',
				'melhor'         => 1,
				'respostas'      => array(
					array(
						'autor'     => 'demo-moda-reconecta',
						'votos'     => 1,
						'dias'      => 18,
						'conteudo'  => 'Não trabalho com cerâmica, mas com caixa dupla o meu índice de avaria caiu bastante. O custo sobe pouco perto do prejuízo de uma troca.',
					),
					array(
						'autor'     => 'demo-atelie-raizes',
						'votos'     => 4,
						'dias'      => 18,
						'conteudo'  => 'O que quebra cerâmica quase nunca é o impacto de fora: é a peça encostando na parede da caixa. O que resolveu aqui foi deixar pelo menos quatro dedos de enchimento em todos os lados e nunca deixar espaço para ela se mexer dentro. Caixa dupla ajuda, mas sozinha não resolve se a peça folga.',
					),
				),
			),
			array(
				'titulo'         => 'Dá para vender por peso na plataforma?',
				'categoria'      => 'A plataforma',
				'autor'          => 'demo-sabor-da-terra',
				'tags'           => array( 'painel do vendedor', 'produtos' ),
				'dias'           => 12,
				'votos'          => 3,
				'visualizacoes'  => 97,
				'conteudo'       => 'Vendo polpa de fruta e queria anunciar por quilo, com o cliente escolhendo a quantidade. Hoje cadastrei em pacotes de 500 g. Existe um jeito melhor?',
				'melhor'         => 0,
				'respostas'      => array(
					array(
						'autor'     => 'demo-admin-bem-viver',
						'votos'     => 3,
						'dias'      => 11,
						'conteudo'  => 'O caminho que funciona hoje é o que você já fez: cadastrar a unidade de venda como produto e deixar o peso no título e na descrição. Quem precisa de dois quilos compra quatro pacotes. Uma venda por peso contínuo depende de mudança no cadastro de produto, e isso ainda não existe.',
					),
				),
			),
			array(
				'titulo'         => 'Onde vocês compram tecido de algodão sem estampa em quantidade pequena?',
				'categoria'      => 'Produção e matéria-prima',
				'autor'          => 'demo-moda-reconecta',
				'tags'           => array( 'fornecedor', 'tecido', 'compras' ),
				'dias'           => 8,
				'votos'          => 1,
				'visualizacoes'  => 61,
				'conteudo'       => 'Os fornecedores que encontro só vendem a partir de cinquenta metros. Para o meu volume isso é estoque parado de meio ano. Alguém achou fornecedor que venda dez, quinze metros sem preço de varejo?',
				'respostas'      => array(),
			),
			array(
				'titulo'         => 'Vale a pena oferecer frete grátis acima de um valor?',
				'categoria'      => 'Vendas e precificação',
				'autor'          => 'demo-bem-viver',
				'tags'           => array( 'frete', 'precificação' ),
				'dias'           => 5,
				'votos'          => 2,
				'visualizacoes'  => 44,
				'conteudo'       => 'Estou pensando em zerar o frete acima de cento e cinquenta reais para aumentar o tamanho do pedido. Só que a maior parte das minhas vendas é para fora da cidade, e aí o frete come a margem inteira. Alguém já testou?',
				'melhor'         => 0,
				'respostas'      => array(
					array(
						'autor'     => 'demo-sabor-da-terra',
						'votos'     => 2,
						'dias'      => 4,
						'conteudo'  => 'Testei por dois meses. O ticket subiu, mas o lucro por pedido caiu nos envios para longe. O que ficou de pé foi frete grátis só dentro do estado — aí a conta fecha e a promessa continua sendo verdadeira.',
					),
				),
			),
			array(
				'titulo'         => 'Como lidar com pedido que o cliente não retira?',
				'categoria'      => 'Entrega e logística',
				'autor'          => 'demo-admin-bem-viver',
				'tags'           => array( 'entrega', 'atendimento' ),
				'dias'           => 2,
				'votos'          => 1,
				'visualizacoes'  => 23,
				'conteudo'       => 'Aconteceu duas vezes este mês: o pedido volta porque ninguém retirou na agência. Quem paga o frete de retorno? Vocês reenviam ou cancelam?',
				'respostas'      => array(),
			),
		),
	),
);
