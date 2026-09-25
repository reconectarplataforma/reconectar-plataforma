<?php
/**
 * Seed de dados de demonstração da plataforma Reconectar.
 *
 * Executado por `wp eval-file`, nunca pelo WordPress em requisição web:
 *
 *     wp eval-file scripts/seed/demo.php            # instala
 *     wp eval-file scripts/seed/demo.php remover    # remove
 *
 * O conteúdo criado é fictício e existe só para que as seções da home
 * (categorias, lojas, produtos) tenham o que renderizar durante o
 * desenvolvimento e nas demonstrações do projeto.
 *
 * Este é um projeto de licitação: em algum momento alguém vai abrir esta
 * instalação sem saber o que é real. Por isso tudo que o seed cria carrega
 * quatro marcas independentes, de modo que nenhuma falha isolada faça um dado
 * fictício passar por verdadeiro:
 *
 *   1. meta `_reconectar_demo = 1` em todo post, usuário e termo criado — é o
 *      que a remoção usa, e é verificável por consulta ao banco;
 *   2. a opção `reconectar_demo_ativo`, que faz o plugin `reconectar-core`
 *      exibir uma faixa de aviso no topo de todas as páginas do site;
 *   3. e-mails no domínio `exemplo.invalid`, reservado pela RFC 2606 e por
 *      definição não registrável — nenhuma mensagem pode alcançar uma pessoa
 *      real, nem hoje nem depois;
 *   4. a palavra "DEMO" impressa dentro de cada imagem gerada, que sobrevive
 *      até a um print de tela compartilhado fora do sistema.
 *
 * As imagens são geradas por GD na hora, em vez de versionadas no repositório:
 * o repositório é público (Atividade 2.9 do edital) e qualquer foto de banco
 * de imagens traria uma licença para auditar. Um retângulo com gradiente e
 * texto não tem essa ambiguidade.
 *
 * @package reconectar
 */

defined( 'ABSPATH' ) || exit;

// wp_generate_attachment_metadata() e wp_delete_user() não são carregadas em
// contexto de CLI; sem estes require, as imagens ficam sem miniaturas e a
// remoção de vendedores dá erro fatal.
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

/**
 * Marca que identifica tudo que este script cria.
 *
 * Usada como meta_key em posts (produtos e anexos), usuários, termos e
 * comentários (as avaliações). Alterar este valor após um seed já instalado
 * torna o conteúdo antigo irremovível pelo modo `remover`.
 */
const RECONECTAR_DEMO_META = '_reconectar_demo';

/**
 * Opção que sinaliza ao plugin `reconectar-core` que há dados fictícios no ar.
 */
const RECONECTAR_DEMO_OPCAO = 'reconectar_demo_ativo';

/**
 * Meta que dá identidade estável a cada avaliação criada pela carga.
 *
 * Categorias, lojas e produtos já têm identificador natural (slug, login) e é
 * por ele que são procurados antes de serem criados. Avaliação não tem: dois
 * comentários com o mesmo autor, o mesmo texto e a mesma nota são registros
 * legitimamente distintos para o WordPress. Sem uma chave própria, a segunda
 * execução da carga inseriria tudo de novo — foi exatamente o que aconteceu
 * antes desta meta existir (16 avaliações viraram 32).
 *
 * O valor é `{login_da_loja}-{posição no array `avaliacoes` daquela loja}`.
 * Deliberadamente não deriva do produto nem do texto: o produto é escolhido
 * por rodízio (`$posicao % count($produtos_ids)`) e o texto por um contador
 * global que atravessa as lojas, de modo que ambos mudariam de lugar se o
 * catálogo em `dados-demo.php` ganhasse ou perdesse um item. Loja e posição
 * são os dois únicos dados fixos na definição da avaliação.
 */
const RECONECTAR_DEMO_CHAVE = '_reconectar_demo_chave';

/**
 * Senha das contas de demonstração (vendedores e clientes).
 *
 * Antes desta constante os vendedores recebiam `wp_generate_password()` e a
 * senha era descartada na mesma linha em que nascia: ninguém conseguia entrar
 * como vendedor, o que deixava dois dos três perfis da plataforma impossíveis
 * de demonstrar. Uma senha previsível é o preço de um roteiro que qualquer
 * pessoa consiga percorrer sem pedir acesso a ninguém.
 *
 * O risco é aceito porque estas contas se anunciam como fictícias por quatro
 * caminhos independentes (ver o cabeçalho deste arquivo) e porque o ambiente é
 * local. Fora dele, `RECONECTAR_DEMO_SENHA` no ambiente troca o valor sem
 * editar código:
 *
 *     RECONECTAR_DEMO_SENHA='…' wp eval-file scripts/seed/demo.php
 *
 * A senha vale só para contas novas: rodar a carga de novo não redefine a senha
 * de quem já existe, pelo mesmo motivo que o seed não reescreve nenhum outro
 * dado já criado — uma segunda execução não deve desfazer ajustes manuais.
 */
define( 'RECONECTAR_DEMO_SENHA', getenv( 'RECONECTAR_DEMO_SENHA' ) ?: 'reconectar-demo' );

/**
 * Imprime uma linha de progresso.
 *
 * Usa WP_CLI::log quando disponível para respeitar `--quiet` e o formato de
 * saída do WP-CLI; cai em echo se o arquivo for executado por outro meio.
 *
 * @param string $mensagem Texto a imprimir.
 */
function reconectar_demo_log( $mensagem ) {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::log( $mensagem );
		return;
	}

	echo $mensagem . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * Interrompe a execução com mensagem de erro.
 *
 * @param string $mensagem Texto do erro.
 */
function reconectar_demo_abortar( $mensagem ) {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::error( $mensagem );
	}

	echo 'ERRO: ' . $mensagem . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput
	exit( 1 );
}

/* ==========================================================================
   Geração de imagens
   ========================================================================== */

/**
 * Converte uma cor hexadecimal em seus três componentes RGB.
 *
 * @param string $hex Cor no formato `#RRGGBB` ou `RRGGBB`.
 * @return int[] Array com os valores de vermelho, verde e azul (0–255).
 */
function reconectar_demo_hex_para_rgb( $hex ) {
	$hex = ltrim( $hex, '#' );

	return array(
		hexdec( substr( $hex, 0, 2 ) ),
		hexdec( substr( $hex, 2, 2 ) ),
		hexdec( substr( $hex, 4, 2 ) ),
	);
}

/**
 * Devolve o caminho absoluto da fonte usada nas imagens geradas.
 *
 * A Poppins já está no tema (é a tipografia do projeto), então a imagem sai
 * coerente com o restante da interface sem adicionar nenhum arquivo novo.
 * Se o tema não estiver no lugar esperado, as imagens ainda são geradas — só
 * ficam sem texto, com aviso.
 *
 * @return string|null Caminho do .ttf, ou null se indisponível.
 */
function reconectar_demo_fonte() {
	static $fonte = false;

	if ( false !== $fonte ) {
		return $fonte;
	}

	$caminho = get_theme_file_path( 'assets/fonts/poppins/Poppins-SemiBold.ttf' );
	$fonte   = ( $caminho && file_exists( $caminho ) && function_exists( 'imagettftext' ) ) ? $caminho : null;

	if ( null === $fonte ) {
		reconectar_demo_log( '  ! Poppins-SemiBold.ttf não encontrada: as imagens serão geradas sem texto.' );
	}

	return $fonte;
}

/**
 * Quebra um texto em linhas que caibam na largura disponível.
 *
 * `imagettftext` não quebra linha sozinho — sem isto, o nome de um produto
 * longo sairia cortado na borda da imagem.
 *
 * @param string $texto          Texto a quebrar.
 * @param string $fonte          Caminho do arquivo .ttf.
 * @param int    $tamanho        Tamanho da fonte em pontos.
 * @param int    $largura_maxima Largura disponível em pixels.
 * @return string[] Linhas resultantes.
 */
function reconectar_demo_quebrar_texto( $texto, $fonte, $tamanho, $largura_maxima ) {
	$palavras = preg_split( '/\s+/u', trim( $texto ) );
	$linhas   = array();
	$atual    = '';

	foreach ( $palavras as $palavra ) {
		$tentativa = ( '' === $atual ) ? $palavra : $atual . ' ' . $palavra;
		$caixa     = imagettfbbox( $tamanho, 0, $fonte, $tentativa );
		$largura   = abs( $caixa[4] - $caixa[0] );

		if ( $largura <= $largura_maxima || '' === $atual ) {
			$atual = $tentativa;
			continue;
		}

		$linhas[] = $atual;
		$atual    = $palavra;
	}

	if ( '' !== $atual ) {
		$linhas[] = $atual;
	}

	return $linhas;
}

/**
 * Gera um PNG com gradiente, texto centralizado e a marca "DEMO".
 *
 * @param int    $largura  Largura em pixels.
 * @param int    $altura   Altura em pixels.
 * @param string $cor_base Cor dominante, em hexadecimal.
 * @param string $texto    Texto centralizado na imagem.
 * @return string|null Conteúdo binário do PNG, ou null em caso de falha.
 */
function reconectar_demo_gerar_png( $largura, $altura, $cor_base, $texto ) {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return null;
	}

	$imagem = imagecreatetruecolor( $largura, $altura );

	// Gradiente vertical entre a cor da entidade e uma versão 35% mais escura
	// dela. Escurecer a própria cor (em vez de misturar com uma segunda cor
	// fixa) mantém cada imagem dentro de um único tom da paleta.
	list( $r, $g, $b ) = reconectar_demo_hex_para_rgb( $cor_base );
	$fator             = 0.65;

	for ( $y = 0; $y < $altura; $y++ ) {
		$passo = $y / max( 1, $altura - 1 );
		$cor   = imagecolorallocate(
			$imagem,
			(int) round( $r * ( 1 - $passo * ( 1 - $fator ) ) ),
			(int) round( $g * ( 1 - $passo * ( 1 - $fator ) ) ),
			(int) round( $b * ( 1 - $passo * ( 1 - $fator ) ) )
		);
		imagefilledrectangle( $imagem, 0, $y, $largura, $y, $cor );
	}

	$branco = imagecolorallocate( $imagem, 255, 255, 255 );
	$fonte  = reconectar_demo_fonte();

	if ( $fonte ) {
		// Tamanho proporcional ao menor lado: a mesma rotina serve para um
		// avatar de 400px e para um banner de 1200x360.
		$tamanho        = max( 12, (int) round( min( $largura, $altura ) * 0.11 ) );
		$largura_maxima = (int) round( $largura * 0.82 );
		$linhas         = reconectar_demo_quebrar_texto( $texto, $fonte, $tamanho, $largura_maxima );

		// Enquanto o bloco de texto não couber na altura, reduz a fonte e
		// requebra — um nome de quatro palavras não pode estourar a imagem.
		$altura_linha = (int) round( $tamanho * 1.38 );
		while ( count( $linhas ) * $altura_linha > $altura * 0.7 && $tamanho > 10 ) {
			$tamanho      = (int) round( $tamanho * 0.85 );
			$altura_linha = (int) round( $tamanho * 1.38 );
			$linhas       = reconectar_demo_quebrar_texto( $texto, $fonte, $tamanho, $largura_maxima );
		}

		$topo = (int) round( ( $altura - count( $linhas ) * $altura_linha ) / 2 + $tamanho );

		foreach ( $linhas as $indice => $linha ) {
			$caixa   = imagettfbbox( $tamanho, 0, $fonte, $linha );
			$largura_linha = abs( $caixa[4] - $caixa[0] );
			$x       = (int) round( ( $largura - $largura_linha ) / 2 );
			$y       = $topo + $indice * $altura_linha;
			imagettftext( $imagem, $tamanho, 0, $x, $y, $branco, $fonte, $linha );
		}

		// Marca "DEMO" no canto inferior direito: sobrevive a recorte, print
		// de tela e reaproveitamento da imagem fora da plataforma.
		$tamanho_marca = max( 9, (int) round( min( $largura, $altura ) * 0.055 ) );
		$caixa_marca   = imagettfbbox( $tamanho_marca, 0, $fonte, 'DEMO' );
		$largura_marca = abs( $caixa_marca[4] - $caixa_marca[0] );
		$margem        = (int) round( min( $largura, $altura ) * 0.05 );
		imagettftext(
			$imagem,
			$tamanho_marca,
			0,
			$largura - $largura_marca - $margem,
			$altura - $margem,
			$branco,
			$fonte,
			'DEMO'
		);
	}

	ob_start();
	imagepng( $imagem );
	$conteudo = ob_get_clean();
	imagedestroy( $imagem );

	return $conteudo;
}

/**
 * Gera uma imagem e a registra na biblioteca de mídia.
 *
 * @param string $nome_arquivo Nome do arquivo, sem extensão.
 * @param string $titulo       Título do anexo e texto impresso na imagem.
 * @param string $cor          Cor dominante, em hexadecimal.
 * @param int    $largura      Largura em pixels.
 * @param int    $altura       Altura em pixels.
 * @param string $alt          Texto alternativo do anexo.
 * @return int ID do anexo criado, ou 0 em caso de falha.
 */
function reconectar_demo_criar_anexo( $nome_arquivo, $titulo, $cor, $largura, $altura, $alt ) {
	$conteudo = reconectar_demo_gerar_png( $largura, $altura, $cor, $titulo );

	if ( ! $conteudo ) {
		return 0;
	}

	$arquivo = wp_upload_bits( sanitize_file_name( $nome_arquivo . '.png' ), null, $conteudo );

	if ( ! empty( $arquivo['error'] ) ) {
		reconectar_demo_log( '  ! Falha ao gravar ' . $nome_arquivo . ': ' . $arquivo['error'] );
		return 0;
	}

	$anexo_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => $titulo,
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		$arquivo['file']
	);

	if ( is_wp_error( $anexo_id ) || ! $anexo_id ) {
		return 0;
	}

	wp_update_attachment_metadata( $anexo_id, wp_generate_attachment_metadata( $anexo_id, $arquivo['file'] ) );
	update_post_meta( $anexo_id, '_wp_attachment_image_alt', $alt );
	update_post_meta( $anexo_id, RECONECTAR_DEMO_META, 1 );

	return (int) $anexo_id;
}

/* ==========================================================================
   Instalação
   ========================================================================== */

/**
 * Cria as categorias de produto e suas imagens.
 *
 * Idempotente: uma categoria cujo slug já existe é reaproveitada, sem gerar
 * imagem nova nem sobrescrever dados que alguém possa ter editado à mão.
 *
 * @param array $categorias Definições vindas de `dados-demo.php`.
 * @return array Mapa de slug para term_id.
 */
function reconectar_demo_criar_categorias( $categorias ) {
	$mapa = array();

	foreach ( $categorias as $definicao ) {
		$termo = get_term_by( 'slug', $definicao['slug'], 'product_cat' );

		if ( $termo ) {
			$mapa[ $definicao['slug'] ] = (int) $termo->term_id;
			reconectar_demo_log( '  = Categoria já existia: ' . $definicao['nome'] );
			continue;
		}

		$resultado = wp_insert_term(
			$definicao['nome'],
			'product_cat',
			array(
				'slug'        => $definicao['slug'],
				'description' => $definicao['descricao'],
			)
		);

		if ( is_wp_error( $resultado ) ) {
			reconectar_demo_log( '  ! Categoria ' . $definicao['nome'] . ': ' . $resultado->get_error_message() );
			continue;
		}

		$term_id = (int) $resultado['term_id'];

		$anexo_id = reconectar_demo_criar_anexo(
			'demo-categoria-' . $definicao['slug'],
			$definicao['nome'],
			$definicao['cor'],
			600,
			600,
			$definicao['nome']
		);

		if ( $anexo_id ) {
			// O WooCommerce lê a imagem da categoria desta meta, não de um
			// featured image — termos não têm thumbnail nativa.
			update_term_meta( $term_id, 'thumbnail_id', $anexo_id );
		}

		update_term_meta( $term_id, RECONECTAR_DEMO_META, 1 );
		$mapa[ $definicao['slug'] ] = $term_id;

		reconectar_demo_log( '  + Categoria: ' . $definicao['nome'] );
	}

	return $mapa;
}

/**
 * Cria as subcategorias de uma loja, sob a categoria dela.
 *
 * São elas que dividem o catálogo em seções na página da loja: a categoria da
 * loja vale para tudo que ela vende e por isso não separa nada.
 *
 * Diferem das de primeiro nível em dois pontos, ambos deliberados. Não recebem
 * imagem: a única tela que as exibe é a página da loja, que imprime apenas o
 * nome da seção, e gerar um PNG por termo encheria a biblioteca de mídia de
 * arquivos que nenhuma consulta busca. E não recebem descrição, porque nenhum
 * template a lê.
 *
 * Recebem, isso sim, a mesma `RECONECTAR_DEMO_META` — é ela que faz
 * `reconectar_demo_remover()` encontrar o termo pela consulta ao `termmeta` e
 * apagá-lo junto do resto. Sem a meta, cada remoção deixaria subcategorias
 * órfãs no banco.
 *
 * Idempotente por slug, como as de primeiro nível.
 *
 * @param array $subcategorias Mapa de slug para nome, vindo de `dados-demo.php`.
 * @param int   $pai_id        Term ID da categoria da loja.
 * @return array Mapa de slug para term_id.
 */
function reconectar_demo_criar_subcategorias( $subcategorias, $pai_id ) {
	$mapa = array();

	if ( empty( $subcategorias ) || ! $pai_id ) {
		return $mapa;
	}

	foreach ( $subcategorias as $slug => $nome ) {
		$termo = get_term_by( 'slug', $slug, 'product_cat' );

		if ( $termo ) {
			$mapa[ $slug ] = (int) $termo->term_id;
			reconectar_demo_log( '  = Subcategoria já existia: ' . $nome );
			continue;
		}

		$resultado = wp_insert_term(
			$nome,
			'product_cat',
			array(
				'slug'   => $slug,
				'parent' => (int) $pai_id,
			)
		);

		if ( is_wp_error( $resultado ) ) {
			reconectar_demo_log( '  ! Subcategoria ' . $nome . ': ' . $resultado->get_error_message() );
			continue;
		}

		$term_id = (int) $resultado['term_id'];

		update_term_meta( $term_id, RECONECTAR_DEMO_META, 1 );
		$mapa[ $slug ] = $term_id;

		reconectar_demo_log( '  + Subcategoria: ' . $nome );
	}

	return $mapa;
}

/**
 * Traduz o horário declarado no catálogo para o formato do Dokan.
 *
 * O catálogo declara o mínimo — `abre` e `fecha`, ou um array vazio para dia
 * fechado. O Dokan espera algo bem mais verboso, e o formato foi conferido
 * lendo `dokan_is_store_open()` e `dokan_get_store_times()`
 * (`dokan-lite/includes/functions.php`), não presumido:
 *
 *     'monday' => array(
 *         'status'       => 'open',            // ou 'close'
 *         'opening_time' => array( '09:00 am' ),
 *         'closing_time' => array( '06:00 pm' ),
 *     )
 *
 * Os horários vão em array porque é assim que o painel do Dokan os grava — o
 * plugin aceita string também, mas gravar no formato que ele próprio produz é o
 * que garante que a tela de configuração da loja exiba o valor de volta sem
 * estranhar.
 *
 * Os sete dias são sempre gravados, inclusive os fechados com `status` `close`.
 * Dia ausente e dia fechado dão no mesmo para `dokan_is_store_open()`, mas só o
 * dia presente aparece na tela de configuração do vendedor — e um dia que some
 * da tela parece campo perdido, não decisão da loja.
 *
 * Loja sem a chave `horario` devolve array vazio: a página não imprime linha de
 * horário nenhuma, que é o comportamento pretendido para "não informou".
 *
 * @param array $horario Mapa de dia (em inglês) para `abre`/`fecha`.
 * @return array Estrutura pronta para a chave `dokan_store_time` do perfil.
 */
function reconectar_demo_horario_do_dokan( $horario ) {
	if ( empty( $horario ) ) {
		return array();
	}

	$dias = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
	$traduzido = array();

	foreach ( $dias as $dia ) {
		$aberto = ! empty( $horario[ $dia ]['abre'] ) && ! empty( $horario[ $dia ]['fecha'] );

		$traduzido[ $dia ] = array(
			'status'       => $aberto ? 'open' : 'close',
			'opening_time' => $aberto ? array( $horario[ $dia ]['abre'] ) : array(),
			'closing_time' => $aberto ? array( $horario[ $dia ]['fecha'] ) : array(),
		);
	}

	return $traduzido;
}

/**
 * Cria as empresas do catálogo.
 *
 * A identificação idempotente é pelo `post_name`, gravado a partir do `slug` do
 * catálogo. Não é pelo título: o título é texto de vitrine e pode ser corrigido
 * a qualquer momento, e uma correção transformaria a execução seguinte em uma
 * segunda criação da mesma empresa.
 *
 * A criação passa por `Reconectar_Empresa::salvar()`, e não por
 * `wp_insert_post()` direto, para que a carga exercite o mesmo caminho que o
 * painel usa — inclusive a gravação das metas cadastrais.
 *
 * @param array $empresas Definições vindas de `dados-demo.php`.
 * @return array Mapa de `slug` para ID da empresa.
 */
function reconectar_demo_criar_empresas( $empresas ) {
	$mapa = array();

	foreach ( $empresas as $definicao ) {
		$existente = get_page_by_path( $definicao['slug'], OBJECT, Reconectar_Empresa::POST_TYPE );

		if ( $existente ) {
			// Convergência, não só ausência de duplicata: as instalações carregadas
			// antes da validação de CNPJ existir têm gravado um número que o painel
			// hoje recusaria, e sem esta passagem ele ficaria lá para sempre — o
			// ramo de criação, que corrigiria, é justamente o que se pula aqui.
			$reconciliado = Reconectar_Empresa::salvar( (int) $existente->ID, $definicao );

			if ( is_wp_error( $reconciliado ) ) {
				reconectar_demo_log( '  ! Empresa ' . $definicao['nome'] . ': ' . $reconciliado->get_error_message() );
			}

			$mapa[ $definicao['slug'] ] = (int) $existente->ID;
			reconectar_demo_log( '  = Empresa já existia: ' . $definicao['nome'] );
			continue;
		}

		$empresa_id = Reconectar_Empresa::salvar( 0, $definicao );

		if ( is_wp_error( $empresa_id ) ) {
			reconectar_demo_log( '  ! Empresa ' . $definicao['nome'] . ': ' . $empresa_id->get_error_message() );
			continue;
		}

		// O slug precisa ser gravado depois, e não junto: `salvar()` é a API do
		// painel, onde a empresa é identificada pelo ID e o `post_name` não é
		// campo de formulário. Quem precisa dele é esta carga, para se reencontrar.
		wp_update_post(
			array(
				'ID'        => $empresa_id,
				'post_name' => $definicao['slug'],
			)
		);

		update_post_meta( $empresa_id, RECONECTAR_DEMO_META, 1 );

		$mapa[ $definicao['slug'] ] = (int) $empresa_id;

		reconectar_demo_log( '  + Empresa: ' . $definicao['nome'] );
	}

	return $mapa;
}

/**
 * Cria um Administrador de Empresas (papel `company_admin`).
 *
 * O papel é literal aqui, como em `Reconectar_Vendedores::criar()`: nada no
 * catálogo diz qual papel criar, e é assim que tem de ser — um campo de papel
 * em arquivo de dados é uma escalada de privilégio esperando por um descuido
 * de revisão.
 *
 * O escopo é gravado de uma das duas formas mutuamente exclusivas que o módulo
 * reconhece: lista de empresas em user meta, ou a capacidade de alcance global.
 * As duas juntas seriam contraditórias, e `empresas_no_escopo()` daria
 * precedência à capacidade — a lista viraria letra morta e ninguém entenderia
 * por quê.
 *
 * @param array $admin    Definição do administrador.
 * @param array $empresas Mapa de `slug` para ID, vindo de `reconectar_demo_criar_empresas()`.
 * @return int ID do usuário, ou 0 em caso de falha.
 */
function reconectar_demo_criar_admin_de_empresa( $admin, $empresas ) {
	$existente = get_user_by( 'login', $admin['login'] );

	if ( $existente ) {
		reconectar_demo_log( '  = Administrador de empresas já existia: ' . $admin['primeiro'] . ' ' . $admin['ultimo'] );
		return (int) $existente->ID;
	}

	$usuario_id = wp_insert_user(
		array(
			'user_login'   => $admin['login'],
			'user_email'   => $admin['email'],
			'user_pass'    => RECONECTAR_DEMO_SENHA,
			'display_name' => $admin['primeiro'] . ' ' . $admin['ultimo'],
			'first_name'   => $admin['primeiro'],
			'last_name'    => $admin['ultimo'],
			'role'         => Reconectar_Permissoes::PAPEL_ADMIN_EMPRESAS,
		)
	);

	if ( is_wp_error( $usuario_id ) ) {
		reconectar_demo_log( '  ! Administrador ' . $admin['login'] . ': ' . $usuario_id->get_error_message() );
		return 0;
	}

	if ( ! empty( $admin['todas'] ) ) {
		$usuario = new WP_User( $usuario_id );
		$usuario->add_cap( Reconectar_Permissoes::CAP_TODAS_AS_EMPRESAS );

		$escopo = __( 'todas as empresas', 'reconectar-core' );
	} else {
		$ids = array();

		foreach ( $admin['empresas'] as $slug ) {
			if ( isset( $empresas[ $slug ] ) ) {
				$ids[] = $empresas[ $slug ];
			}
		}

		Reconectar_Empresa::definir_empresas_geridas( $usuario_id, $ids );

		$escopo = implode( ', ', $admin['empresas'] );
	}

	update_user_meta( $usuario_id, RECONECTAR_DEMO_META, 1 );

	reconectar_demo_log( '  + Administrador de empresas: ' . $admin['primeiro'] . ' ' . $admin['ultimo'] . ' (' . $escopo . ')' );

	return (int) $usuario_id;
}

/**
 * Acerta o vínculo de um vendedor que já existia com a empresa do catálogo.
 *
 * Idempotência aqui não é só "não duplicar": é convergir para o estado
 * declarado. Sem esta função, uma instalação carregada antes de as empresas
 * existirem ficaria com as cinco lojas órfãs para sempre — a criação é pulada,
 * e com ela o vínculo —, e o painel do Administrador de Empresas abriria vazio
 * sem nada na saída da carga indicando por quê. Foi exatamente o que aconteceu
 * na primeira execução depois da mudança do catálogo.
 *
 * O contrário também é reconciliado: tirar o campo `empresa` de uma loja no
 * catálogo apaga a meta em vez de deixar um vínculo que ninguém mais declara.
 *
 * A permissão de venda é recalculada no fim porque ela é derivada da empresa:
 * mudar o vínculo sem recalcular deixaria o vendedor vendendo sob uma empresa
 * desativada.
 *
 * @param int $usuario_id ID do vendedor.
 * @param int $empresa_id Empresa declarada no catálogo; 0 para loja sem vínculo.
 * @return void
 */
function reconectar_demo_reconciliar_vinculo( $usuario_id, $empresa_id ) {
	$atual = (int) get_user_meta( $usuario_id, Reconectar_Empresa::META_VINCULO, true );

	if ( $atual === (int) $empresa_id ) {
		return;
	}

	if ( $empresa_id ) {
		update_user_meta( $usuario_id, Reconectar_Empresa::META_VINCULO, $empresa_id );
	} else {
		delete_user_meta( $usuario_id, Reconectar_Empresa::META_VINCULO );
	}

	Reconectar_Empresa::aplicar_permissao_de_venda( $usuario_id );
}

/**
 * Grava o horário de funcionamento declarado numa loja que já existe.
 *
 * `reconectar_demo_criar_vendedor()` retorna cedo quando encontra o login, e o
 * perfil inteiro — horário incluído — só é escrito na criação. Sem esta
 * reconciliação, uma instalação já carregada nunca receberia o horário: a linha
 * "Aberta agora" simplesmente não apareceria, e o único jeito de vê-la seria
 * remover e recarregar a demonstração inteira.
 *
 * É a mesma escolha de `reconectar_demo_reconciliar_vinculo()`, pelo mesmo
 * motivo: idempotência aqui é convergir para o estado declarado, não apenas
 * deixar de duplicar.
 *
 * Escreve só quando há diferença. O perfil é uma meta única e grande, e
 * regravá-lo a cada execução da carga sujaria o histórico de revisões sem
 * mudar nada.
 *
 * @param int   $usuario_id ID do vendedor.
 * @param array $horario    Horário declarado no catálogo, no formato do Dokan.
 * @return void
 */
function reconectar_demo_reconciliar_horario( $usuario_id, $horario ) {
	$perfil = get_user_meta( $usuario_id, 'dokan_profile_settings', true );

	if ( ! is_array( $perfil ) ) {
		return;
	}

	$atual = isset( $perfil['dokan_store_time'] ) ? $perfil['dokan_store_time'] : array();

	if ( $atual === $horario ) {
		return;
	}

	$perfil['dokan_store_time'] = $horario;

	update_user_meta( $usuario_id, 'dokan_profile_settings', $perfil );
}

/**
 * Cria um vendedor do Dokan.
 *
 * O cadastro em si é delegado a `Reconectar_Vendedores::criar()`, que é o
 * caminho usado pelo painel do Administrador de Empresas. A delegação é o
 * ponto: duas implementações de "criar vendedor" divergiriam em silêncio, e a
 * carga deixaria de provar qualquer coisa sobre o código de produção. Aqui a
 * carga passa a exercitá-lo — inclusive o vínculo com a empresa e o cálculo da
 * permissão de venda.
 *
 * Deliberadamente não usa `dokan()->vendor->create()`. Aquele método dispara
 * `wp_send_new_user_notifications( $id, 'admin' )` de forma incondicional — o
 * seed mandaria um e-mail ao administrador por loja criada — e não grava
 * `dokan_enable_selling`, sem o qual o vendedor é filtrado para fora de
 * `get_vendors()` (`Vendor/Manager.php:170`) e nunca aparece na vitrine.
 *
 * O que sobra nesta função é o que é específico da demonstração: banner,
 * avatar, endereço, categoria da loja e as metas de entrega — nada disso existe
 * no cadastro real, onde o próprio vendedor preenche depois.
 *
 * @param array $loja       Definição da loja.
 * @param int   $empresa_id Empresa a que a loja pertence; 0 para loja sem vínculo.
 * @return int ID do usuário, ou 0 em caso de falha.
 */
function reconectar_demo_criar_vendedor( $loja, $empresa_id = 0 ) {
	$existente = get_user_by( 'login', $loja['login'] );

	$horario = reconectar_demo_horario_do_dokan( isset( $loja['horario'] ) ? $loja['horario'] : array() );

	if ( $existente ) {
		reconectar_demo_reconciliar_vinculo( (int) $existente->ID, $empresa_id );
		reconectar_demo_reconciliar_horario( (int) $existente->ID, $horario );

		reconectar_demo_log( '  = Loja já existia: ' . $loja['nome'] );
		return (int) $existente->ID;
	}

	$resultado = Reconectar_Vendedores::criar(
		array(
			'login'      => $loja['login'],
			'email'      => $loja['email'],
			'empresa_id' => $empresa_id,
			'nome'       => $loja['nome'],
			'primeiro'   => $loja['primeiro'],
			'ultimo'     => $loja['ultimo'],
			'telefone'   => $loja['telefone'],
			'descricao'  => $loja['descricao'],
			'senha'      => RECONECTAR_DEMO_SENHA,
		)
	);

	if ( is_wp_error( $resultado ) ) {
		reconectar_demo_log( '  ! Loja ' . $loja['nome'] . ': ' . $resultado->get_error_message() );
		return 0;
	}

	$usuario_id = $resultado['usuario_id'];

	$banner_id = reconectar_demo_criar_anexo(
		'demo-loja-' . $loja['login'] . '-banner',
		$loja['nome'],
		$loja['cor'],
		1200,
		360,
		'Imagem de capa fictícia da loja ' . $loja['nome']
	);

	// Iniciais da loja no avatar: o card no estilo iFood mostra o logo em um
	// círculo pequeno, onde o nome completo ficaria ilegível.
	$iniciais = '';
	foreach ( preg_split( '/\s+/u', $loja['nome'] ) as $palavra ) {
		if ( '' !== $palavra ) {
			$iniciais .= mb_strtoupper( mb_substr( $palavra, 0, 1 ) );
		}
	}

	$avatar_id = reconectar_demo_criar_anexo(
		'demo-loja-' . $loja['login'] . '-logo',
		mb_substr( $iniciais, 0, 3 ),
		$loja['cor'],
		400,
		400,
		'Logotipo fictício da loja ' . $loja['nome']
	);

	/*
	 * Completa o perfil que `Reconectar_Vendedores::criar()` já deixou gravado.
	 * As chaves abaixo repetem de propósito as que vieram de lá: o array é
	 * gravado inteiro em uma meta só, e montá-lo por diferença tornaria este
	 * trecho dependente da ordem interna daquela função.
	 */
	$perfil = array(
		'store_name'              => $loja['nome'],
		'social'                  => array(),
		'payment'                 => array(
			'paypal' => array( 'email' => '' ),
			'bank'   => array(),
		),
		'phone'                   => $loja['telefone'],
		'show_email'              => 'no',
		'address'                 => array(
			'street_1' => $loja['endereco']['rua'],
			'street_2' => '',
			'city'     => $loja['endereco']['cidade'],
			'zip'      => $loja['endereco']['cep'],
			'country'  => 'BR',
			'state'    => $loja['endereco']['estado'],
		),
		'location'                => '',
		'find_address'            => $loja['endereco']['rua'] . ', ' . $loja['endereco']['cidade'] . ' - ' . $loja['endereco']['estado'],
		'dokan_category'          => $loja['categoria'],
		'banner'                  => $banner_id,
		'gravatar'                => $avatar_id,
		'icon'                    => '',
		'enable_tnc'              => 'off',
		'store_tnc'               => '',
		'show_min_order_discount' => 'no',
		'store_seo'               => array(),
		'dokan_store_time'        => $horario,
		'store_ppp'               => 12,
		'profile_completion'      => array( 'progress' => 100 ),
	);

	update_user_meta( $usuario_id, 'dokan_profile_settings', $perfil );
	update_user_meta( $usuario_id, 'dokan_store_name', $loja['nome'] );
	update_user_meta( $usuario_id, RECONECTAR_DEMO_META, 1 );

	// `dokan_enable_selling` não é gravado aqui de propósito. Ele é resultado da
	// conjunção entre o estado da empresa e o do vendedor, e quem o calcula é
	// `Reconectar_Empresa::aplicar_permissao_de_venda()`, já chamada no cadastro.
	// Escrever 'yes' à mão neste ponto faria a carga criar em operação um vendedor
	// de empresa desativada — e o defeito só apareceria no dia em que o catálogo
	// tivesse uma empresa inativa.

	/*
	 * Tempo, taxa e distância de entrega. São metas do tema, não do Dokan: o
	 * Dokan Lite não tem campo para nenhuma das três, e o card da vitrine as lê
	 * por `reconectar_dados_de_entrega()`
	 * (`inc/marketplace/consultas.php`). Uma loja sem estas metas continua
	 * aparecendo na vitrine — o card simplesmente omite a linha, que é o
	 * comportamento correto para uma loja que ainda não declarou como entrega.
	 */
	if ( isset( $loja['entrega'] ) ) {
		update_user_meta( $usuario_id, '_reconectar_tempo_entrega', $loja['entrega']['tempo'] );
		update_user_meta( $usuario_id, '_reconectar_taxa_entrega', $loja['entrega']['taxa'] );
		update_user_meta( $usuario_id, '_reconectar_distancia', $loja['entrega']['distancia'] );
	}

	reconectar_demo_log( '  + Loja: ' . $loja['nome'] );

	return (int) $usuario_id;
}

/**
 * Cria um produto atribuído a um vendedor.
 *
 * Usa a CRUD do WooCommerce em vez de `wp_insert_post()` direto porque é ela
 * que popula as lookup tables (`wc_product_meta_lookup`) das quais dependem a
 * ordenação por preço e os filtros da loja.
 *
 * Recebe os term_ids já resolvidos — normalmente dois, a categoria da loja e a
 * subcategoria do produto. Atribuir **as duas** não é redundância: é a
 * categoria-mãe que mantém o produto casando com o filtro por categoria da
 * vitrine e que faz `reconectar_categoria_principal_da_loja()` continuar
 * elegendo-a, já que a função ordena por contagem e a mãe aparece em todos os
 * produtos enquanto cada subcategoria aparece em poucos. Atribuir só a
 * subcategoria mudaria em silêncio a categoria exibida no card da loja.
 *
 * @param array $produto    Definição do produto.
 * @param int   $vendedor   ID do usuário vendedor.
 * @param array $categorias term_ids a atribuir ao produto.
 * @param array $loja       Definição da loja (para cor e nome).
 * @return int ID do produto, ou 0 em caso de falha.
 */
function reconectar_demo_criar_produto( $produto, $vendedor, $categorias, $loja ) {
	$slug      = sanitize_title( $produto['nome'] );
	$existente = get_page_by_path( $slug, OBJECT, 'product' );

	$categorias = array_values( array_unique( array_filter( array_map( 'intval', (array) $categorias ) ) ) );

	if ( $existente ) {
		// Mesma reconciliação do horário da loja, e pelo mesmo motivo: os produtos
		// nasceram antes de as subcategorias existirem, e sem isto continuariam só
		// na categoria-mãe para sempre — ficariam todos no grupo residual da página
		// da loja, e o agrupamento por seção pareceria não funcionar.
		// `wp_set_object_terms()` substitui o conjunto, então só é chamada quando há
		// diferença real.
		$atuais = wp_get_object_terms( (int) $existente->ID, 'product_cat', array( 'fields' => 'ids' ) );

		if ( ! is_wp_error( $atuais ) && $categorias ) {
			sort( $atuais );
			$desejadas = $categorias;
			sort( $desejadas );

			if ( $atuais !== $desejadas ) {
				wp_set_object_terms( (int) $existente->ID, $categorias, 'product_cat' );
			}
		}

		reconectar_demo_log( '    = Produto já existia: ' . $produto['nome'] );
		return (int) $existente->ID;
	}

	$objeto = new WC_Product_Simple();
	$objeto->set_name( $produto['nome'] );
	$objeto->set_slug( $slug );
	$objeto->set_status( 'publish' );
	$objeto->set_catalog_visibility( 'visible' );
	$objeto->set_description( $produto['resumo'] . ' Produto fictício, criado para demonstração da plataforma Reconectar.' );
	$objeto->set_short_description( $produto['resumo'] );
	$objeto->set_regular_price( $produto['preco'] );
	$objeto->set_manage_stock( false );
	$objeto->set_stock_status( 'instock' );
	$objeto->set_reviews_allowed( true );

	if ( ! empty( $produto['promocional'] ) ) {
		$objeto->set_sale_price( $produto['promocional'] );
	}

	if ( $categorias ) {
		$objeto->set_category_ids( $categorias );
	}

	$produto_id = $objeto->save();

	if ( ! $produto_id ) {
		reconectar_demo_log( '    ! Falha ao criar produto: ' . $produto['nome'] );
		return 0;
	}

	// O Dokan identifica o dono da loja pelo autor do post; a CRUD do
	// WooCommerce não expõe esse campo, daí o update separado. Sem isto o
	// produto pertenceria ao usuário que rodou o WP-CLI.
	wp_update_post(
		array(
			'ID'          => $produto_id,
			'post_author' => $vendedor,
		)
	);

	$imagem_id = reconectar_demo_criar_anexo(
		'demo-produto-' . $slug,
		$produto['nome'],
		$loja['cor'],
		800,
		800,
		'Imagem fictícia do produto ' . $produto['nome']
	);

	if ( $imagem_id ) {
		wp_update_post(
			array(
				'ID'          => $imagem_id,
				'post_parent' => $produto_id,
			)
		);
		set_post_thumbnail( $produto_id, $imagem_id );
	}

	update_post_meta( $produto_id, RECONECTAR_DEMO_META, 1 );

	reconectar_demo_log( '    + Produto: ' . $produto['nome'] );

	return (int) $produto_id;
}

/**
 * Cria uma avaliação aprovada em um produto.
 *
 * A nota exibida no card da loja não é um campo do vendedor: o Dokan a calcula
 * em `Vendor::get_rating()` com um AVG sobre a meta `rating` dos comentários
 * aprovados dos produtos daquele autor. Sem avaliações, `get_rating()` devolve
 * count 0 e o card imprime "No ratings found yet!" em vez da nota.
 *
 * Idempotente pela meta `RECONECTAR_DEMO_CHAVE`: se já houver um comentário
 * com a mesma chave, devolve o ID existente sem inserir nada.
 *
 * O `'type' => 'review'` da consulta não é decorativo — sem ele a guarda
 * devolve zero resultados mesmo com as avaliações no banco, e a carga volta a
 * duplicar tudo em silêncio. O WooCommerce registra
 * `ReviewsUtil::comments_clauses_without_product_reviews` em `comments_clauses`
 * (`includes/class-wc-comments.php:61`, sob o comentário "Exclude product
 * reviews from general comments") para que avaliações de produto não poluam as
 * listagens genéricas de comentários. Esse filtro só deixa a consulta passar
 * intacta quando ela demonstra ser sobre produtos, e uma das formas de
 * demonstrá-lo é preencher qualquer um dos doze query vars listados em
 * `ReviewsUtil.php:63` — `type` está entre eles. Consultar apenas por
 * `meta_key`/`meta_value` não preenche nenhum: o filtro acrescenta a exclusão,
 * a consulta volta vazia, e não há erro nem log denunciando o que houve.
 *
 * O próprio WooCommerce avisa, no comentário acima dessa lista, que o
 * tratamento de `type` pode mudar caso as respostas a avaliações virem um tipo
 * próprio. Se isso acontecer, o escape sem ressalva é `'post_type' =>
 * 'product'`, tratado antes na mesma função.
 *
 * @param int    $produto_id ID do produto avaliado.
 * @param int    $nota       Nota de 1 a 5.
 * @param array  $texto      Definição da avaliação (autor, e-mail, conteúdo).
 * @param int    $dias_atras Quantos dias no passado datar o comentário.
 * @param string $chave      Identificador estável, `{login-da-loja}-{posição}`.
 * @return int ID do comentário, ou 0 em caso de falha.
 */
function reconectar_demo_criar_avaliacao( $produto_id, $nota, $texto, $dias_atras, $chave ) {
	$existentes = get_comments(
		array(
			'meta_key'   => RECONECTAR_DEMO_CHAVE,
			'meta_value' => $chave,
			'type'       => 'review',
			'status'     => 'any',
			'number'     => 1,
			'fields'     => 'ids',
		)
	);

	if ( ! empty( $existentes ) ) {
		return (int) $existentes[0];
	}

	$data = gmdate( 'Y-m-d H:i:s', time() - $dias_atras * DAY_IN_SECONDS );

	$comentario_id = wp_insert_comment(
		array(
			'comment_post_ID'      => $produto_id,
			'comment_author'       => $texto['autor'],
			'comment_author_email' => $texto['email'],
			'comment_content'      => $texto['conteudo'],
			'comment_type'         => 'review',
			'comment_approved'     => 1,
			'comment_date'         => get_date_from_gmt( $data ),
			'comment_date_gmt'     => $data,
		)
	);

	if ( ! $comentario_id ) {
		return 0;
	}

	add_comment_meta( $comentario_id, 'rating', $nota );
	// Sem esta meta o WooCommerce mostra o aviso de "compra não verificada"
	// junto de cada avaliação, o que poluiria a demonstração.
	add_comment_meta( $comentario_id, 'verified', 1 );
	add_comment_meta( $comentario_id, RECONECTAR_DEMO_META, 1 );
	add_comment_meta( $comentario_id, RECONECTAR_DEMO_CHAVE, $chave );

	return (int) $comentario_id;
}

/* ==========================================================================
   Clientes e pedidos
   ========================================================================== */

/**
 * Cria um cliente (papel `customer`).
 *
 * O endereço é gravado nas metas de cobrança e de entrega do WooCommerce, e não
 * só no perfil do usuário, porque é de lá que o checkout preenche o formulário
 * e é de lá que o painel do vendedor lê o destino do pedido. Um cliente sem
 * essas metas chega ao checkout com todos os campos vazios, o que transformaria
 * a demonstração da jornada de compra em um exercício de digitação.
 *
 * Idempotente por login, como as lojas.
 *
 * @param array $cliente Definição do cliente.
 * @return int ID do usuário, ou 0 em caso de falha.
 */
function reconectar_demo_criar_cliente( $cliente ) {
	$existente = get_user_by( 'login', $cliente['login'] );

	if ( $existente ) {
		reconectar_demo_log( '  = Cliente já existia: ' . $cliente['primeiro'] . ' ' . $cliente['ultimo'] );
		return (int) $existente->ID;
	}

	$usuario_id = wp_insert_user(
		array(
			'user_login'   => $cliente['login'],
			'user_email'   => $cliente['email'],
			'user_pass'    => RECONECTAR_DEMO_SENHA,
			'display_name' => $cliente['primeiro'] . ' ' . $cliente['ultimo'],
			'first_name'   => $cliente['primeiro'],
			'last_name'    => $cliente['ultimo'],
			'role'         => 'customer',
		)
	);

	if ( is_wp_error( $usuario_id ) ) {
		reconectar_demo_log( '  ! Cliente ' . $cliente['login'] . ': ' . $usuario_id->get_error_message() );
		return 0;
	}

	$campos = array(
		'first_name' => $cliente['primeiro'],
		'last_name'  => $cliente['ultimo'],
		'address_1'  => $cliente['endereco']['rua'],
		'city'       => $cliente['endereco']['cidade'],
		'state'      => $cliente['endereco']['estado'],
		'postcode'   => $cliente['endereco']['cep'],
		'country'    => 'BR',
	);

	foreach ( $campos as $campo => $valor ) {
		update_user_meta( $usuario_id, 'billing_' . $campo, $valor );
		update_user_meta( $usuario_id, 'shipping_' . $campo, $valor );
	}

	// Telefone e e-mail existem só na cobrança: o WooCommerce não tem os campos
	// equivalentes em entrega no checkout padrão.
	update_user_meta( $usuario_id, 'billing_phone', $cliente['telefone'] );
	update_user_meta( $usuario_id, 'billing_email', $cliente['email'] );

	update_user_meta( $usuario_id, RECONECTAR_DEMO_META, 1 );

	reconectar_demo_log( '  + Cliente: ' . $cliente['primeiro'] . ' ' . $cliente['ultimo'] );

	return (int) $usuario_id;
}

/**
 * Localiza um pedido já criado pela carga a partir da sua chave.
 *
 * A busca NÃO é por meta. `wc_get_orders()` tem uma lista fechada de argumentos
 * e descarta em silêncio o que não está nela — `meta_key`, `meta_value` e
 * também `meta_query`, que parece suportado por ser o nome que a `WP_Query`
 * usa, mas não é. O resultado de uma consulta assim não é vazio: é o banco
 * inteiro, sem filtro nenhum, e um `limit` pequeno faz esse banco inteiro
 * parecer uma resposta plausível.
 *
 * Esse foi um defeito real desta carga: com `limit => 5`, os cinco pedidos mais
 * recentes voltavam para qualquer chave procurada, e a conferência item a item
 * confirmava os dois ou três que por acaso estavam na janela. Os demais eram
 * dados como inexistentes e criados de novo a cada execução.
 *
 * O filtro é por `customer`, que consta da lista e portanto é aplicado de
 * verdade. Ele reduz a varredura aos pedidos de um cliente — uma dezena, no
 * pior caso — e a chave é conferida item a item sobre esse conjunto pequeno. A
 * conferência deixa de ser uma salvaguarda e passa a ser o critério.
 *
 * @param string $chave      Identificador declarado no catálogo (ex.: `ped-001`).
 * @param int    $cliente_id ID do comprador, usado para estreitar a busca.
 * @return int ID do pedido, ou 0 se não houver.
 */
function reconectar_demo_localizar_pedido( $chave, $cliente_id ) {
	$encontrados = wc_get_orders(
		array(
			'limit'    => -1,
			'status'   => 'any',
			'return'   => 'ids',
			'customer' => (int) $cliente_id,
		)
	);

	foreach ( $encontrados as $pedido_id ) {
		$pedido = wc_get_order( $pedido_id );

		if ( $pedido && $chave === (string) $pedido->get_meta( RECONECTAR_DEMO_CHAVE ) ) {
			return (int) $pedido_id;
		}
	}

	return 0;
}

/**
 * Copia o status do pedido para a linha de saldo que o Dokan acabou de criar.
 *
 * `dokan_sync_insert_order()` grava a linha de `wp_dokan_vendor_balance` sem
 * preencher a coluna `status` — quem a preenche é o hook de mudança de status,
 * que nunca dispara aqui porque a carga salva o pedido já no status final. A
 * linha nasce, portanto, com status vazio.
 *
 * O efeito não aparece em lugar nenhum da carga, e sim nos painéis:
 * `Vendor::get_earnings()` soma apenas as linhas cujo status está entre os de
 * saque (`dokan_withdraw_get_active_order_status_in_comma()`). Status vazio não
 * casa com nenhum, então o faturamento de todo vendedor era R$ 0,00 — no painel
 * do vendedor e no painel de empresas, que lê da mesma fonte. Oito pedidos pagos
 * no banco, zero em toda tela que os resume.
 *
 * Só a tabela de saldo é reconciliada: `wp_dokan_orders.order_status` já sai
 * correto do sync, e reescrevê-lo seria redundância sem defeito que a motive.
 *
 * @param int $pedido_id ID do pedido pai recém-criado.
 * @return void
 */
function reconectar_demo_sincronizar_saldo_dokan( $pedido_id ) {
	global $wpdb;

	$tabela = $wpdb->prefix . 'dokan_vendor_balance';

	if ( $tabela !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tabela ) ) ) { // phpcs:ignore WordPress.DB
		return;
	}

	// O pai entra na lista junto dos filhos: em pedido de vendedor único não há
	// divisão, e é o próprio pai que o Dokan sincroniza.
	$ids = array( (int) $pedido_id );

	foreach ( dokan()->order->get_child_orders( $pedido_id ) as $filho ) {
		$ids[] = (int) $filho->get_id();
	}

	foreach ( $ids as $id ) {
		$objeto = wc_get_order( $id );

		if ( ! $objeto ) {
			continue;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB
			$tabela,
			array( 'status' => 'wc-' . $objeto->get_status() ),
			array(
				'trn_id'   => $id,
				'trn_type' => 'dokan_orders',
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		// O ganho fica em cache por vendedor e por dia. Sem invalidar, uma
		// instalação com cache persistente continuaria servindo o zero que
		// calculou antes desta correção.
		$vendedor_id = (int) $objeto->get_meta( '_dokan_vendor_id', true );

		if ( $vendedor_id && class_exists( '\WeDevs\Dokan\Cache' ) ) {
			\WeDevs\Dokan\Cache::invalidate_group( "seller_order_data_{$vendedor_id}" );
		}
	}
}

/**
 * Cria um pedido com os itens declarados no catálogo.
 *
 * Não há linha de frete. A taxa de entrega que o card da vitrine exibe é
 * informativa e pertence à loja, não ao pedido: a plataforma ainda não tem
 * regra de cálculo de frete, e somar um valor ao total do pedido produziria uma
 * conta que nenhuma parte do sistema sabe reproduzir. O total do pedido é a
 * soma dos itens, e é isso que ele diz ser.
 *
 * Depois de salvo, o pedido passa por `maybe_split_orders()` do Dokan — sempre,
 * mesmo quando tem um vendedor só. O nome sugere que a chamada só interessa ao
 * caso multi-vendedor, mas é ela que grava `_dokan_vendor_id` no pedido de
 * vendedor único (`Order/Manager.php:900`); sem essa meta o pedido existe para
 * o cliente e para o administrador, e é invisível no painel da loja. A função
 * é idempotente por conta própria: sai cedo se a meta já estiver lá.
 *
 * @param array $pedido     Definição do pedido.
 * @param int   $cliente_id ID do usuário comprador.
 * @param array $pagamento  Definição do meio de pagamento (`id` e `titulo`).
 * @return int ID do pedido, ou 0 em caso de falha.
 */
function reconectar_demo_criar_pedido( $pedido, $cliente_id, $pagamento ) {
	$existente = reconectar_demo_localizar_pedido( $pedido['chave'], $cliente_id );

	if ( $existente ) {
		// Convergência, não só ausência de duplicata: as instalações carregadas
		// antes desta correção têm as linhas de saldo com status vazio, e sem
		// esta passagem ficariam com faturamento zerado para sempre — a criação é
		// pulada, e com ela a sincronização.
		if ( function_exists( 'dokan' ) ) {
			reconectar_demo_sincronizar_saldo_dokan( $existente );
		}

		reconectar_demo_log( '  = Pedido já existia: ' . $pedido['chave'] );
		return $existente;
	}

	$cliente = get_userdata( $cliente_id );

	if ( ! $cliente ) {
		reconectar_demo_log( '  ! Pedido ' . $pedido['chave'] . ': cliente não encontrado.' );
		return 0;
	}

	$objeto = new WC_Order();
	$objeto->set_customer_id( $cliente_id );

	$itens = 0;

	foreach ( $pedido['itens'] as $item ) {
		// O produto é procurado pelo mesmo slug com que foi criado. Um nome que
		// não case é reportado: silenciar aqui produziria um pedido com menos
		// itens do que o catálogo declara, e a diferença só apareceria no total.
		$produto_post = get_page_by_path( sanitize_title( $item['produto'] ), OBJECT, 'product' );
		$produto      = $produto_post ? wc_get_product( $produto_post->ID ) : null;

		if ( ! $produto ) {
			reconectar_demo_log( '  ! Pedido ' . $pedido['chave'] . ': produto não encontrado — ' . $item['produto'] );
			continue;
		}

		$objeto->add_product( $produto, $item['quantidade'] );
		$itens++;
	}

	if ( ! $itens ) {
		reconectar_demo_log( '  ! Pedido ' . $pedido['chave'] . ' descartado: nenhum item válido.' );
		return 0;
	}

	$endereco = array(
		'first_name' => $cliente->first_name,
		'last_name'  => $cliente->last_name,
		'address_1'  => get_user_meta( $cliente_id, 'billing_address_1', true ),
		'city'       => get_user_meta( $cliente_id, 'billing_city', true ),
		'state'      => get_user_meta( $cliente_id, 'billing_state', true ),
		'postcode'   => get_user_meta( $cliente_id, 'billing_postcode', true ),
		'country'    => 'BR',
	);

	$objeto->set_address(
		array_merge(
			$endereco,
			array(
				'email' => $cliente->user_email,
				'phone' => get_user_meta( $cliente_id, 'billing_phone', true ),
			)
		),
		'billing'
	);
	$objeto->set_address( $endereco, 'shipping' );

	$objeto->set_payment_method( $pagamento['id'] );
	$objeto->set_payment_method_title( $pagamento['titulo'] );
	$objeto->set_date_created( time() - $pedido['dias'] * DAY_IN_SECONDS );

	// `false` desliga o cálculo de impostos: a instalação não tem alíquotas
	// cadastradas, e pedir o cálculo faria o WooCommerce percorrer as tabelas de
	// imposto para concluir o óbvio.
	$objeto->calculate_totals( false );

	$objeto->update_meta_data( RECONECTAR_DEMO_META, 1 );
	$objeto->update_meta_data( RECONECTAR_DEMO_CHAVE, $pedido['chave'] );

	/*
	 * Os dois status autorais (`wc-preparacao` e `wc-enviado`) só são válidos
	 * com o plugin `reconectar-core` ativo. Sem a checagem, `set_status()`
	 * rebaixaria o pedido para `pending` sem dizer nada, e a demonstração
	 * mostraria cinco pedidos onde deveria haver dois em cada ponta do fluxo.
	 */
	if ( ! array_key_exists( $pedido['status'], wc_get_order_statuses() ) ) {
		reconectar_demo_log( '  ! Status desconhecido (' . $pedido['status'] . '): o plugin reconectar-core está ativo?' );
	}

	$objeto->set_status( $pedido['status'] );

	// Pedidos já pagos precisam da data de pagamento: é ela que alimenta os
	// relatórios de venda e a coluna de faturamento do painel do vendedor.
	if ( in_array( $pedido['status'], array( 'wc-processing', 'wc-preparacao', 'wc-enviado', 'wc-completed' ), true ) ) {
		$objeto->set_date_paid( time() - $pedido['dias'] * DAY_IN_SECONDS );
	}

	$pedido_id = $objeto->save();

	if ( ! $pedido_id ) {
		reconectar_demo_log( '  ! Falha ao criar pedido ' . $pedido['chave'] );
		return 0;
	}

	if ( function_exists( 'dokan' ) ) {
		dokan()->order->maybe_split_orders( $pedido_id );

		/*
		 * Os sub-pedidos são criados pelo Dokan, que não conhece a marcação de
		 * demonstração — sem esta passagem eles sobreviveriam à remoção como
		 * pedidos órfãos de um pai que já não existe.
		 */
		foreach ( dokan()->order->get_child_orders( $pedido_id ) as $filho ) {
			$filho->update_meta_data( RECONECTAR_DEMO_META, 1 );
			$filho->save();
		}

		reconectar_demo_sincronizar_saldo_dokan( $pedido_id );
	} else {
		reconectar_demo_log( '  ! Dokan indisponível: o pedido não aparecerá no painel do vendedor.' );
	}

	reconectar_demo_log(
		sprintf(
			'  + Pedido %s: %s, %s, %s',
			$pedido['chave'],
			$cliente->display_name,
			$pagamento['titulo'],
			wc_get_order_status_name( $pedido['status'] )
		)
	);

	return (int) $pedido_id;
}

/* ==========================================================================
   Fórum
   ========================================================================== */

/**
 * Localiza um post da carga pela chave de idempotência.
 *
 * A busca é por `RECONECTAR_DEMO_CHAVE`, e não por slug como nos produtos, por
 * dois motivos. O slug de um tópico sai de um título de pergunta inteiro, que é
 * longo e que o WordPress trunca e desambigua com sufixo numérico quando há
 * colisão — o mesmo dado de entrada pode virar slugs diferentes em instalações
 * diferentes. E `get_page_by_path()` num post type hierárquico, que é o caso do
 * `forum`, exige o caminho completo e não o último segmento.
 *
 * A consulta exige as duas metas. A chave sozinha bastaria hoje, mas quem lê
 * uma linha de remoção precisa ver que o recorte da demonstração está ali.
 *
 * @param string $post_type Tipo do post.
 * @param string $chave     Valor de `RECONECTAR_DEMO_CHAVE`.
 * @return int ID, ou 0 se não existir.
 */
function reconectar_demo_post_por_chave( $post_type, $chave ) {
	$posts = get_posts(
		array(
			'post_type'        => $post_type,
			'post_status'      => 'any',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'suppress_filters' => false,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'   => RECONECTAR_DEMO_CHAVE,
					'value' => $chave,
				),
				array(
					'key'   => RECONECTAR_DEMO_META,
					'value' => 1,
				),
			),
		)
	);

	return $posts ? (int) $posts[0] : 0;
}

/**
 * Converte "há N dias" em data local e GMT, para `wp_insert_post()`.
 *
 * As duas são obrigatórias em par: informar só `post_date` faz o WordPress
 * calcular `post_date_gmt` pelo fuso do site, e informar só a GMT deixa a local
 * como agora. Um tópico com as duas datas divergindo aparece no lugar certo de
 * uma consulta e no lugar errado da outra, conforme cada uma ordene por um campo
 * ou pelo outro.
 *
 * @param int $dias Há quantos dias.
 * @return array `array( 'post_date' => …, 'post_date_gmt' => … )`
 */
function reconectar_demo_datas_de_post( $dias ) {
	$instante = time() - ( (int) $dias * DAY_IN_SECONDS );

	return array(
		'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $instante ) ),
		'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $instante ),
	);
}

/**
 * Grava votos fictícios em uma pergunta ou resposta.
 *
 * Grava os dois lados: a lista de votantes e o saldo. Gravar só o saldo daria um
 * número que morre no primeiro voto real, porque `Reconectar_Forum::votar()`
 * recalcula o total de `array_sum( $votantes )` e não incrementa o valor
 * guardado — nove viraria um, sem nada na tela explicando a queda.
 *
 * O autor é excluído da lista pela mesma regra que o endpoint aplica: ninguém
 * vota no que escreveu. Se a quantidade pedida for maior que o número de
 * eleitores disponíveis, ela é saturada — um voto sem votante não existe.
 *
 * @param int   $post_id    Pergunta ou resposta.
 * @param int   $quantidade Votos positivos desejados.
 * @param int   $autor_id   Autor do conteúdo, que não vota.
 * @param int[] $eleitores  IDs dos usuários da comunidade criados pela carga.
 * @return int Saldo gravado.
 */
function reconectar_demo_gravar_votos( $post_id, $quantidade, $autor_id, $eleitores ) {
	$disponiveis = array_values( array_diff( array_map( 'intval', $eleitores ), array( (int) $autor_id ) ) );
	$votantes    = array();

	foreach ( array_slice( $disponiveis, 0, max( 0, (int) $quantidade ) ) as $eleitor_id ) {
		$votantes[ $eleitor_id ] = 1;
	}

	$saldo = array_sum( $votantes );

	if ( $votantes ) {
		update_post_meta( $post_id, Reconectar_Forum::META_VOTANTES, $votantes );
	} else {
		delete_post_meta( $post_id, Reconectar_Forum::META_VOTANTES );
	}

	update_post_meta( $post_id, Reconectar_Forum::META_VOTOS, $saldo );

	return (int) $saldo;
}

/**
 * Cria uma categoria de perguntas (um fórum do bbPress).
 *
 * As categorias nascem no primeiro nível, irmãs do "Fórum Geral" que
 * `provision.sh` cria, e não como subfóruns dele: `reconectar_forum_categorias()`
 * lista os fóruns sem hierarquia, então aninhar não mudaria o seletor da tela e
 * só acrescentaria uma dependência do ID de um post que a carga não criou.
 *
 * @param array $categoria Definição vinda de `dados-demo.php`.
 * @param int   $ordem     Posição no seletor de categorias.
 * @return int ID do fórum, ou 0 em caso de falha.
 */
function reconectar_demo_criar_categoria_de_forum( $categoria, $ordem ) {
	$chave     = 'forum-' . sanitize_title( $categoria['nome'] );
	$existente = reconectar_demo_post_por_chave( 'forum', $chave );

	if ( $existente ) {
		reconectar_demo_log( '  = Categoria de fórum já existia: ' . $categoria['nome'] );
		return $existente;
	}

	$forum_id = bbp_insert_forum(
		array(
			'post_title'   => $categoria['nome'],
			'post_content' => $categoria['descricao'],
			'post_parent'  => 0,
			'menu_order'   => (int) $ordem,
		),
		array(
			'forum_type' => 'forum',
			'status'     => 'open',
		)
	);

	if ( ! $forum_id ) {
		reconectar_demo_log( '  ! Falha ao criar categoria de fórum: ' . $categoria['nome'] );
		return 0;
	}

	update_post_meta( $forum_id, RECONECTAR_DEMO_META, 1 );
	update_post_meta( $forum_id, RECONECTAR_DEMO_CHAVE, $chave );

	reconectar_demo_log( '  + Categoria de fórum: ' . $categoria['nome'] );

	return (int) $forum_id;
}

/**
 * Cria uma pergunta (um tópico do bbPress) com tags, votos e visualizações.
 *
 * As três metas autorais são gravadas aqui, à mão, e não por um gancho: os
 * hooks `bbp_new_topic` e `bbp_new_reply` — onde `Reconectar_Forum` se pendura
 * para inicializar o saldo de votos — vivem dentro dos manipuladores de
 * formulário do frontend (`topics/functions.php:387`, `replies/functions.php:471`),
 * não dentro de `bbp_insert_topic()`. Criação programática não os dispara, e sem
 * estas linhas as perguntas da demonstração ficariam sem `_reconectar_votos` —
 * exatamente o caso que o ramo `NOT EXISTS` da aba "Votos" existe para cobrir,
 * e que não convém exercitar com o próprio conteúdo da demonstração.
 *
 * @param array $pergunta   Definição vinda de `dados-demo.php`.
 * @param int   $forum_id   Categoria onde a pergunta entra.
 * @param int   $autor_id   Usuário que assina a pergunta.
 * @param int[] $eleitores  IDs dos usuários da comunidade, para os votos.
 * @return int ID do tópico, ou 0 em caso de falha.
 */
function reconectar_demo_criar_pergunta( $pergunta, $forum_id, $autor_id, $eleitores ) {
	$chave     = 'pergunta-' . sanitize_title( $pergunta['titulo'] );
	$existente = reconectar_demo_post_por_chave( 'topic', $chave );

	if ( $existente ) {
		reconectar_demo_log( '  = Pergunta já existia: ' . $pergunta['titulo'] );
		return $existente;
	}

	$topico_id = bbp_insert_topic(
		array_merge(
			reconectar_demo_datas_de_post( $pergunta['dias'] ),
			array(
				'post_parent'  => (int) $forum_id,
				'post_author'  => (int) $autor_id,
				'post_title'   => $pergunta['titulo'],
				'post_content' => $pergunta['conteudo'],
			)
		),
		array(
			'forum_id' => (int) $forum_id,
		)
	);

	if ( ! $topico_id ) {
		reconectar_demo_log( '  ! Falha ao criar pergunta: ' . $pergunta['titulo'] );
		return 0;
	}

	if ( ! empty( $pergunta['tags'] ) ) {
		wp_set_object_terms( $topico_id, $pergunta['tags'], bbp_get_topic_tag_tax_id() );

		// Os termos ganham a marcação da carga para que a remoção os alcance pela
		// consulta ao `termmeta` que já existe. Sem ela, cada ciclo deixaria tags
		// órfãs — visíveis na coluna lateral, ligando para listas vazias.
		foreach ( wp_get_object_terms( $topico_id, bbp_get_topic_tag_tax_id(), array( 'fields' => 'ids' ) ) as $term_id ) {
			update_term_meta( (int) $term_id, RECONECTAR_DEMO_META, 1 );
		}
	}

	update_post_meta( $topico_id, RECONECTAR_DEMO_META, 1 );
	update_post_meta( $topico_id, RECONECTAR_DEMO_CHAVE, $chave );
	update_post_meta( $topico_id, Reconectar_Forum::META_VISUALIZACOES, (int) $pergunta['visualizacoes'] );

	reconectar_demo_gravar_votos( $topico_id, $pergunta['votos'], $autor_id, $eleitores );

	reconectar_demo_log( '  + Pergunta: ' . $pergunta['titulo'] );

	return (int) $topico_id;
}

/**
 * Cria uma resposta de uma pergunta.
 *
 * @param array $resposta   Definição vinda de `dados-demo.php`.
 * @param int   $topico_id  Pergunta respondida.
 * @param int   $forum_id   Categoria da pergunta.
 * @param int   $autor_id   Usuário que assina a resposta.
 * @param int   $posicao    Índice da resposta na pergunta, usado na chave.
 * @param int[] $eleitores  IDs dos usuários da comunidade, para os votos.
 * @return int ID da resposta, ou 0 em caso de falha.
 */
function reconectar_demo_criar_resposta( $resposta, $topico_id, $forum_id, $autor_id, $posicao, $eleitores ) {
	$chave     = 'resposta-' . $topico_id . '-' . (int) $posicao;
	$existente = reconectar_demo_post_por_chave( 'reply', $chave );

	if ( $existente ) {
		reconectar_demo_log( '    = Resposta já existia: ' . $chave );
		return $existente;
	}

	$resposta_id = bbp_insert_reply(
		array_merge(
			reconectar_demo_datas_de_post( $resposta['dias'] ),
			array(
				'post_parent'  => (int) $topico_id,
				'post_author'  => (int) $autor_id,
				'post_status'  => bbp_get_public_status_id(),
				'post_content' => $resposta['conteudo'],
			)
		),
		array(
			'forum_id' => (int) $forum_id,
			'topic_id' => (int) $topico_id,
		)
	);

	if ( ! $resposta_id ) {
		reconectar_demo_log( '    ! Falha ao criar resposta: ' . $chave );
		return 0;
	}

	update_post_meta( $resposta_id, RECONECTAR_DEMO_META, 1 );
	update_post_meta( $resposta_id, RECONECTAR_DEMO_CHAVE, $chave );

	reconectar_demo_gravar_votos( $resposta_id, $resposta['votos'], $autor_id, $eleitores );

	reconectar_demo_log( '    + Resposta de ' . $resposta['autor'] );

	return (int) $resposta_id;
}

/**
 * Recalcula os contadores de um tópico e da categoria acima dele.
 *
 * Isto não é zelo redundante: `bbp_insert_reply()` chama
 * `bbp_update_reply_walker()`, que sobe a árvore mas só refaz as contagens
 * quando `current_filter()` é `bbp_deleted_reply` ou `save_post`
 * (`replies/functions.php`, dentro do laço de ancestrais). Numa carga por
 * WP-CLI não é nenhum dos dois, e o resultado seria `_bbp_reply_count` em zero
 * para todas as perguntas: a aba "Sem resposta" listaria o fórum inteiro e cada
 * card diria "0 respostas" embaixo das respostas que estão logo ali.
 *
 * @param int $topico_id Pergunta.
 * @param int $forum_id  Categoria.
 */
function reconectar_demo_recontar_forum( $topico_id, $forum_id ) {
	bbp_update_topic_reply_count( $topico_id );
	bbp_update_topic_reply_count_hidden( $topico_id );
	bbp_update_topic_voice_count( $topico_id );

	bbp_update_forum_topic_count( $forum_id );
	bbp_update_forum_reply_count( $forum_id );
}

/**
 * Cria as categorias, perguntas e respostas do fórum.
 *
 * Os autores são resolvidos por login, e não recebidos prontos da instalação:
 * assim a etapa não depende da ordem em que as lojas e os administradores foram
 * criados, e uma segunda execução reencontra todos sem recriar nada.
 *
 * Uma pergunta cujo autor não exista é pulada com aviso, em vez de cair para um
 * autor qualquer. Atribuir a pergunta a outra pessoa daria uma tela plausível e
 * errada — e o fórum é justamente onde a demonstração precisa mostrar quem pode
 * publicar o quê.
 *
 * @param array $forum Bloco `forum` de `dados-demo.php`.
 * @return array `array( 'categorias' => int, 'perguntas' => int, 'respostas' => int )`
 */
function reconectar_demo_criar_forum( $forum ) {
	$totais = array(
		'categorias' => 0,
		'perguntas'  => 0,
		'respostas'  => 0,
	);

	if ( ! function_exists( 'bbp_insert_topic' ) || ! class_exists( 'Reconectar_Forum' ) ) {
		reconectar_demo_log( '  ! bbPress ou Reconectar_Forum indisponível: o fórum não foi populado.' );
		return $totais;
	}

	$categorias = array();

	foreach ( $forum['categorias'] as $ordem => $categoria ) {
		$forum_id = reconectar_demo_criar_categoria_de_forum( $categoria, $ordem );

		if ( $forum_id ) {
			$categorias[ $categoria['nome'] ] = $forum_id;
			$totais['categorias']++;
		}
	}

	/*
	 * Os eleitores saem dos autores declarados no catálogo, e não de uma consulta
	 * por papel: só quem a carga cria pode ser apagado por ela, e um voto de um
	 * usuário real ficaria gravado numa lista que a remoção não toca.
	 */
	$eleitores = array();

	foreach ( $forum['perguntas'] as $pergunta ) {
		$logins = array( $pergunta['autor'] );

		foreach ( $pergunta['respostas'] as $resposta ) {
			$logins[] = $resposta['autor'];
		}

		foreach ( $logins as $login ) {
			if ( ! isset( $eleitores[ $login ] ) ) {
				$usuario             = get_user_by( 'login', $login );
				$eleitores[ $login ] = $usuario ? (int) $usuario->ID : 0;
			}
		}
	}

	$ids_dos_eleitores = array_values( array_filter( $eleitores ) );

	foreach ( $forum['perguntas'] as $pergunta ) {
		if ( ! isset( $categorias[ $pergunta['categoria'] ] ) ) {
			reconectar_demo_log( '  ! Pergunta "' . $pergunta['titulo'] . '": categoria ' . $pergunta['categoria'] . ' não está no catálogo.' );
			continue;
		}

		if ( empty( $eleitores[ $pergunta['autor'] ] ) ) {
			reconectar_demo_log( '  ! Pergunta "' . $pergunta['titulo'] . '": autor ' . $pergunta['autor'] . ' não foi criado.' );
			continue;
		}

		$forum_id  = $categorias[ $pergunta['categoria'] ];
		$topico_id = reconectar_demo_criar_pergunta(
			$pergunta,
			$forum_id,
			$eleitores[ $pergunta['autor'] ],
			$ids_dos_eleitores
		);

		if ( ! $topico_id ) {
			continue;
		}

		$totais['perguntas']++;
		$respostas_ids = array();

		foreach ( $pergunta['respostas'] as $posicao => $resposta ) {
			if ( empty( $eleitores[ $resposta['autor'] ] ) ) {
				reconectar_demo_log( '    ! Resposta de ' . $resposta['autor'] . ': usuário não foi criado.' );
				continue;
			}

			$resposta_id = reconectar_demo_criar_resposta(
				$resposta,
				$topico_id,
				$forum_id,
				$eleitores[ $resposta['autor'] ],
				$posicao,
				$ids_dos_eleitores
			);

			if ( $resposta_id ) {
				$respostas_ids[ $posicao ] = $resposta_id;
				$totais['respostas']++;
			}
		}

		// A melhor resposta é gravada pelo índice declarado, e só quando aquela
		// resposta existe de fato: um ID inválido aqui marcaria como aceita uma
		// resposta que a tela não encontraria, e o selo apareceria em lugar nenhum.
		if ( isset( $pergunta['melhor'] ) && isset( $respostas_ids[ $pergunta['melhor'] ] ) ) {
			update_post_meta(
				$topico_id,
				Reconectar_Forum::META_MELHOR_RESPOSTA,
				$respostas_ids[ $pergunta['melhor'] ]
			);
		}

		reconectar_demo_recontar_forum( $topico_id, $forum_id );
	}

	return $totais;
}

/**
 * Executa a instalação completa do seed.
 *
 * @param array $dados Catálogo vindo de `dados-demo.php`.
 */
function reconectar_demo_instalar( $dados ) {
	/*
	 * Silencia o envio de e-mail durante a carga. Criar seis pedidos e movê-los
	 * pelos status do fluxo dispara uma dezena de mensagens transacionais para
	 * endereços `@exemplo.invalid`, que existem justamente para não ter caixa
	 * postal — cada uma vira uma tentativa de entrega inútil e uma espera de
	 * timeout no meio da carga.
	 *
	 * O corte é em `pre_wp_mail`, o ponto mais baixo possível (WP 5.7+), e não
	 * removendo os hooks de e-mail do WooCommerce: esses mesmos hooks carregam a
	 * sincronização do Dokan, e desligá-los deixaria os pedidos fora do painel
	 * do vendedor.
	 */
	add_filter( 'pre_wp_mail', '__return_false' );

	reconectar_demo_log( 'Criando categorias...' );
	$categorias = reconectar_demo_criar_categorias( $dados['categorias'] );

	// As empresas vêm antes das lojas porque o vínculo é gravado no cadastro do
	// vendedor, e não depois: é `Reconectar_Vendedores::criar()` que o grava, e
	// ele precisa do ID da empresa já existente.
	reconectar_demo_log( 'Criando empresas...' );
	$empresas = reconectar_demo_criar_empresas( $dados['empresas'] );

	reconectar_demo_log( 'Criando administradores de empresas...' );

	$total_admins = 0;

	foreach ( $dados['administradores_de_empresa'] as $admin ) {
		if ( reconectar_demo_criar_admin_de_empresa( $admin, $empresas ) ) {
			$total_admins++;
		}
	}

	reconectar_demo_log( 'Criando lojas, produtos e avaliações...' );

	$total_produtos   = 0;
	$total_avaliacoes = 0;
	$indice_texto     = 0;

	foreach ( $dados['lojas'] as $loja ) {
		$empresa_id = ( isset( $loja['empresa'] ) && isset( $empresas[ $loja['empresa'] ] ) )
			? $empresas[ $loja['empresa'] ]
			: 0;

		$vendedor_id = reconectar_demo_criar_vendedor( $loja, $empresa_id );

		if ( ! $vendedor_id ) {
			continue;
		}

		$categoria_id = isset( $categorias[ $loja['categoria'] ] ) ? $categorias[ $loja['categoria'] ] : 0;

		$subcategorias = reconectar_demo_criar_subcategorias(
			isset( $loja['subcategorias'] ) ? $loja['subcategorias'] : array(),
			$categoria_id
		);

		$produtos_ids = array();

		foreach ( $loja['produtos'] as $produto ) {
			// Produto sem `categoria` declarada, ou com um slug que não consta das
			// subcategorias da loja, fica só na categoria-mãe. É o que o comentário
			// do bloco `lojas` em `dados-demo.php` promete, e evita que um erro de
			// digitação no slug faça o produto sumir da página da loja.
			$subcategoria_id = ( ! empty( $produto['categoria'] ) && isset( $subcategorias[ $produto['categoria'] ] ) )
				? $subcategorias[ $produto['categoria'] ]
				: 0;

			$produto_id = reconectar_demo_criar_produto(
				$produto,
				$vendedor_id,
				array( $categoria_id, $subcategoria_id ),
				$loja
			);

			if ( $produto_id ) {
				$produtos_ids[] = $produto_id;
				$total_produtos++;
			}
		}

		if ( empty( $produtos_ids ) ) {
			continue;
		}

		// As avaliações são distribuídas entre os produtos da loja em rodízio:
		// o que importa para a nota do card é a média da loja, não qual
		// produto recebeu qual avaliação.
		foreach ( $loja['avaliacoes'] as $posicao => $nota ) {
			$produto_id = $produtos_ids[ $posicao % count( $produtos_ids ) ];
			$texto      = $dados['avaliacoes'][ $indice_texto % count( $dados['avaliacoes'] ) ];
			$indice_texto++;

			$chave = $loja['login'] . '-' . $posicao;

			if ( reconectar_demo_criar_avaliacao( $produto_id, $nota, $texto, 3 + $posicao * 5, $chave ) ) {
				$total_avaliacoes++;
			}
		}

		foreach ( array_unique( $produtos_ids ) as $produto_id ) {
			// Recalcula a média e a contagem que o WooCommerce guarda em meta;
			// sem isto as estrelas do produto só apareceriam após a próxima
			// avaliação feita pela interface.
			//
			// `clear_transients()` já faz o serviço completo — lê os comentários,
			// grava `set_rating_counts`/`set_average_rating`/`set_review_count` e
			// salva o produto (class-wc-comments.php:275). Não há nada a fazer
			// depois dela; mexer no produto aqui só desfaria o recálculo.
			WC_Comments::clear_transients( $produto_id );
		}
	}

	reconectar_demo_log( 'Criando clientes...' );

	$clientes_ids = array();

	foreach ( $dados['clientes'] as $cliente ) {
		$cliente_id = reconectar_demo_criar_cliente( $cliente );

		if ( $cliente_id ) {
			$clientes_ids[ $cliente['login'] ] = $cliente_id;
		}
	}

	reconectar_demo_log( 'Criando pedidos...' );

	$total_pedidos = 0;

	foreach ( $dados['pedidos'] as $pedido ) {
		if ( ! isset( $clientes_ids[ $pedido['cliente'] ] ) ) {
			reconectar_demo_log( '  ! Pedido ' . $pedido['chave'] . ': cliente ' . $pedido['cliente'] . ' não foi criado.' );
			continue;
		}

		if ( ! isset( $dados['pagamentos'][ $pedido['pagamento'] ] ) ) {
			reconectar_demo_log( '  ! Pedido ' . $pedido['chave'] . ': pagamento ' . $pedido['pagamento'] . ' não está no catálogo.' );
			continue;
		}

		if ( reconectar_demo_criar_pedido( $pedido, $clientes_ids[ $pedido['cliente'] ], $dados['pagamentos'][ $pedido['pagamento'] ] ) ) {
			$total_pedidos++;
		}
	}

	// O fórum vem por último porque depende dos usuários: vendedores e
	// administradores de empresa assinam as perguntas e são os votantes.
	reconectar_demo_log( 'Criando fórum...' );
	$forum = reconectar_demo_criar_forum( $dados['forum'] );

	update_option( RECONECTAR_DEMO_OPCAO, 1 );

	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}

	reconectar_demo_log( '' );
	reconectar_demo_log(
		sprintf(
			'Concluído: %d categorias, %d empresas, %d administradores de empresas, %d lojas, %d produtos, %d avaliações, %d clientes, %d pedidos.',
			count( $categorias ),
			count( $empresas ),
			$total_admins,
			count( $dados['lojas'] ),
			$total_produtos,
			$total_avaliacoes,
			count( $clientes_ids ),
			$total_pedidos
		)
	);
	reconectar_demo_log(
		sprintf(
			'Fórum: %d categorias, %d perguntas, %d respostas.',
			$forum['categorias'],
			$forum['perguntas'],
			$forum['respostas']
		)
	);
	reconectar_demo_log( 'A faixa de aviso de dados de demonstração está ativa no site.' );
	reconectar_demo_log( 'Senha de vendedores, clientes e administradores de empresas: ' . RECONECTAR_DEMO_SENHA );
	reconectar_demo_log( 'Para remover: wp eval-file scripts/seed/demo.php remover' );
}

/* ==========================================================================
   Remoção
   ========================================================================== */

/**
 * Apaga das tabelas próprias do Dokan as linhas de pedidos que já não existem.
 *
 * O Dokan não guarda as vendas apenas no pedido do WooCommerce: mantém tabelas
 * paralelas, e é delas — não dos pedidos — que o painel do vendedor lê o
 * faturamento. Apagar o pedido não apaga essas linhas, porque o plugin as
 * limpa a partir dos seus próprios hooks de estorno e cancelamento, não da
 * exclusão definitiva que esta carga usa.
 *
 * A consequência é visível: numa instalação em que a carga rodou duas vezes, o
 * painel somava vendas de pedidos que não existiam mais em lugar nenhum. O
 * relatório não estava errado por cálculo — estava certo sobre uma base suja.
 *
 * O critério é a existência do pedido, não a marcação de demonstração. Uma
 * linha que aponta para um pedido inexistente não é utilizável por ninguém,
 * qualquer que tenha sido a sua origem, e tratá-la como dado de terceiro
 * significaria preservar lixo por precaução.
 *
 * @return int Quantidade de linhas apagadas.
 */
function reconectar_demo_limpar_tabelas_dokan() {
	global $wpdb;

	// `wc_get_orders()` em vez de uma consulta a `wp_posts` porque com HPOS
	// ativo os pedidos deixam de ser posts — e uma lista de vivos vazia por
	// engano transformaria a limpeza abaixo num truncate.
	$vivos = array_map(
		'intval',
		(array) wc_get_orders(
			array(
				'limit'  => -1,
				'status' => 'any',
				'return' => 'ids',
			)
		)
	);

	// Tabela (sem prefixo) => coluna que guarda o ID do pedido.
	$tabelas = array(
		'dokan_orders'         => 'order_id',
		'dokan_order_stats'    => 'order_id',
		'dokan_refund'         => 'order_id',
		'dokan_vendor_balance' => 'trn_id',
	);

	$removidas = 0;

	foreach ( $tabelas as $sufixo => $coluna ) {
		$tabela = $wpdb->prefix . $sufixo;

		// Nem toda versão do Dokan cria todas elas, e uma consulta a tabela
		// inexistente é um erro fatal no meio de uma remoção que já começou.
		if ( $tabela !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tabela ) ) ) { // phpcs:ignore WordPress.DB
			continue;
		}

		$condicoes = array();

		// `dokan_vendor_balance` não registra só pedidos: saques e estornos
		// entram na mesma tabela, com `trn_id` apontando para outra. Sem este
		// recorte, um saque seria lido como pedido inexistente e apagado.
		if ( 'trn_id' === $coluna ) {
			$condicoes[] = "trn_type = 'dokan_orders'";
		}

		// Interpolação direta, e não `prepare()`, porque a lista tem tamanho
		// variável e cada item já passou por `intval()` acima. Quando não há
		// pedido nenhum, a condição some e a limpeza alcança a tabela inteira —
		// que é a resposta certa para "todas as linhas são órfãs".
		if ( $vivos ) {
			$condicoes[] = sprintf( '`%s` NOT IN (%s)', $coluna, implode( ',', $vivos ) );
		}

		$sql = "DELETE FROM `{$tabela}`";

		if ( $condicoes ) {
			$sql .= ' WHERE ' . implode( ' AND ', $condicoes );
		}

		$removidas += (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB
	}

	return $removidas;
}

/**
 * Remove tudo que o seed criou.
 *
 * A ordem importa. Os IDs de banner e avatar ficam guardados dentro do array
 * `dokan_profile_settings` de cada vendedor; se os anexos forem apagados antes
 * que essas chaves sejam limpas, o perfil fica apontando para anexos
 * inexistentes — e como o usuário é apagado logo depois, o problema sumiria
 * aqui, mas não numa remoção parcial interrompida no meio. Limpar primeiro
 * torna cada etapa segura isoladamente.
 *
 * Pelo mesmo motivo os pedidos vêm antes dos produtos: uma linha de pedido que
 * aponta para um produto inexistente é estado inválido, e a janela em que ela
 * existiria é toda a duração da remoção.
 */
function reconectar_demo_remover() {
	global $wpdb;

	// Mesmo motivo da instalação: apagar pedidos e usuários também dispara
	// notificação, e ninguém precisa ser avisado de que a demonstração acabou.
	add_filter( 'pre_wp_mail', '__return_false' );

	// A consulta alcança vendedores e clientes: ambos recebem a mesma meta na
	// criação, e ambos precisam sair juntos.
	$usuarios = get_users(
		array(
			'meta_key'   => RECONECTAR_DEMO_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => 1,                     // phpcs:ignore WordPress.DB.SlowDBQuery
			'fields'     => 'ID',
			'number'     => -1,
		)
	);

	/*
	 * Pedidos por dois critérios, unidos: a marcação de demonstração e o
	 * comprador. O primeiro é o mecanismo canônico da carga, mas depende de a
	 * meta ter sido gravada — e os sub-pedidos são criados pelo Dokan, que só a
	 * recebe num segundo passo. O segundo é estrutural: um pedido de um cliente
	 * de demonstração é um pedido de demonstração, tenha ou não a meta.
	 *
	 * Os dois critérios são aplicados em PHP, sobre a lista completa de
	 * pedidos, e não como argumento de consulta. `wc_get_orders()` reconhece
	 * uma lista fechada de argumentos e ignora em silêncio o que está fora
	 * dela, `meta_query` inclusive; aqui a consequência não seria um pedido a
	 * mais, como na criação, mas a remoção de TODOS os pedidos da instalação —
	 * a consulta sem filtro efetivo devolve o banco inteiro, e o laço abaixo
	 * apaga em definitivo o que recebe. Uma linha que parece um filtro e não é
	 * não pode estar no caminho de uma exclusão.
	 *
	 * `wc_get_orders()` em vez de `get_posts()` porque com HPOS ativo os
	 * pedidos deixam de ser posts, e a consulta por tipo de post voltaria vazia
	 * sem erro nenhum.
	 */
	$clientes_demo = array_map( 'intval', (array) $usuarios );
	$pedidos       = array();

	foreach ( wc_get_orders( array( 'limit' => -1, 'status' => 'any', 'return' => 'ids' ) ) as $pedido_id ) {
		$pedido = wc_get_order( $pedido_id );

		if ( ! $pedido ) {
			continue;
		}

		$marcado = (int) $pedido->get_meta( RECONECTAR_DEMO_META );
		$comprou = in_array( (int) $pedido->get_customer_id(), $clientes_demo, true );

		if ( $marcado || $comprou ) {
			$pedidos[] = $pedido;
		}
	}

	foreach ( $pedidos as $pedido ) {
		// `true` apaga de vez, sem passar pela lixeira: um pedido na lixeira
		// continua contando nos relatórios do painel.
		$pedido->delete( true );
	}
	reconectar_demo_log( sprintf( '- %d pedidos removidos.', count( $pedidos ) ) );

	// Depois dos pedidos, e não antes: a limpeza decide pelo que sobrou, então
	// uma linha só é reconhecida como órfã quando o pedido dela já se foi.
	$linhas_dokan = reconectar_demo_limpar_tabelas_dokan();

	if ( $linhas_dokan ) {
		reconectar_demo_log( sprintf( '- %d linhas órfãs das tabelas do Dokan removidas.', $linhas_dokan ) );
	}

	// Posts: produtos primeiro, anexos depois. Apagar o produto com force
	// delete já leva junto os comentários (e as metas de rating) dele.
	$posts = get_posts(
		array(
			'post_type'      => array( 'product' ),
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => RECONECTAR_DEMO_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => 1,                     // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);

	foreach ( $posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	reconectar_demo_log( sprintf( '- %d produtos removidos.', count( $posts ) ) );

	/*
	 * Fórum: respostas, depois perguntas, depois categorias — de baixo para cima,
	 * como em todo o resto desta função, para que uma interrupção no meio nunca
	 * deixe um filho apontando para um pai que já não existe.
	 *
	 * O filtro é `RECONECTAR_DEMO_META`, sempre, e nunca o tipo de post sozinho.
	 * Um `get_posts()` por `post_type => 'topic'` sem a meta devolveria o fórum
	 * inteiro da instalação e este laço o apagaria em definitivo — a forma bbPress
	 * do mesmo defeito que `wc_get_orders()` produziu nos pedidos, e que ali quase
	 * custou todos os pedidos do banco.
	 */
	$totais_do_forum = array();

	foreach ( array( 'reply', 'topic' ) as $tipo ) {
		$do_forum = get_posts(
			array(
				'post_type'      => $tipo,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => RECONECTAR_DEMO_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => 1,                     // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		foreach ( $do_forum as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		$totais_do_forum[ $tipo ] = count( $do_forum );
	}

	/*
	 * As categorias saem por último e com uma condição a mais: o bbPress apaga em
	 * cascata o que estiver dentro de um fórum, então uma pergunta feita durante a
	 * apresentação — por alguém de verdade, numa categoria da demonstração — sairia
	 * junto sem nunca ter recebido a marcação. Diante disso a categoria fica de pé:
	 * preservar um agrupador vazio de dado fictício custa uma linha no seletor;
	 * apagar a pergunta de outra pessoa não tem desfazer.
	 */
	$categorias_do_forum = get_posts(
		array(
			'post_type'      => 'forum',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => RECONECTAR_DEMO_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => 1,                     // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);

	$totais_do_forum['forum']    = 0;
	$categorias_preservadas      = 0;

	foreach ( $categorias_do_forum as $forum_id ) {
		$herdeiros = get_posts(
			array(
				'post_type'      => array( 'topic', 'reply' ),
				'post_status'    => 'any',
				'post_parent'    => $forum_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( $herdeiros ) {
			$categorias_preservadas++;
			continue;
		}

		wp_delete_post( $forum_id, true );
		$totais_do_forum['forum']++;
	}

	reconectar_demo_log(
		sprintf(
			'- Fórum: %d respostas, %d perguntas e %d categorias removidas.',
			$totais_do_forum['reply'],
			$totais_do_forum['topic'],
			$totais_do_forum['forum']
		)
	);

	if ( $categorias_preservadas ) {
		reconectar_demo_log(
			sprintf(
				'  %d categoria(s) de fórum preservadas: ainda têm conteúdo que não é da demonstração.',
				$categorias_preservadas
			)
		);
	}

	// Limpa as referências a anexos guardadas no perfil de cada loja. Clientes
	// entram no laço e saem sem alteração: não têm perfil de vendedor, e o
	// `is_array()` abaixo dá conta disso sem precisar separar as duas listas.
	foreach ( $usuarios as $vendedor_id ) {
		$perfil = get_user_meta( $vendedor_id, 'dokan_profile_settings', true );

		if ( is_array( $perfil ) ) {
			$perfil['banner']   = 0;
			$perfil['gravatar'] = 0;
			update_user_meta( $vendedor_id, 'dokan_profile_settings', $perfil );
		}
	}

	$anexos = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => RECONECTAR_DEMO_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => 1,                     // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);

	foreach ( $anexos as $anexo_id ) {
		wp_delete_attachment( $anexo_id, true );
	}
	reconectar_demo_log( sprintf( '- %d imagens removidas.', count( $anexos ) ) );

	foreach ( $usuarios as $usuario_id ) {
		wp_delete_user( $usuario_id );
	}
	reconectar_demo_log( sprintf( '- %d usuários removidos (lojas, clientes e administradores de empresas).', count( $usuarios ) ) );

	/*
	 * Empresas depois dos usuários, e não antes: o vínculo mora no vendedor e
	 * aponta para a empresa. Invertendo a ordem, existiria uma janela — toda a
	 * duração da remoção — em que vendedores apontariam para um post apagado, e
	 * uma interrupção no meio deixaria esse estado gravado.
	 *
	 * O recorte é a marcação da carga, como em todo o resto: uma empresa
	 * cadastrada pelo painel durante a demonstração não é dado de demonstração e
	 * não pode ser apagada por um comando que promete remover só o que criou.
	 */
	$empresas = get_posts(
		array(
			'post_type'      => Reconectar_Empresa::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => RECONECTAR_DEMO_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => 1,                     // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);

	foreach ( $empresas as $empresa_id ) {
		wp_delete_post( $empresa_id, true );
	}
	reconectar_demo_log( sprintf( '- %d empresas removidas.', count( $empresas ) ) );

	// Termos não têm API de busca por meta, daí a consulta direta.
	$termos = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s",
			RECONECTAR_DEMO_META,
			'1'
		)
	);

	$termos_removidos = 0;

	foreach ( $termos as $term_id ) {
		/*
		 * A taxonomia vem do banco, e não fixa em `product_cat` como antes: desde
		 * o fórum a carga cria termos em duas taxonomias, e um `wp_delete_term()`
		 * com a taxonomia errada devolve `false` em silêncio. O sintoma seria uma
		 * coluna lateral de tags que sobrevive à remoção e leva a listas vazias.
		 */
		$taxonomias = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d",
				(int) $term_id
			)
		);

		foreach ( $taxonomias as $taxonomia ) {
			if ( true === wp_delete_term( (int) $term_id, $taxonomia ) ) {
				$termos_removidos++;
			}
		}
	}
	reconectar_demo_log( sprintf( '- %d termos removidos (categorias de produto e tags do fórum).', $termos_removidos ) );

	delete_option( RECONECTAR_DEMO_OPCAO );

	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}

	reconectar_demo_log( '' );
	reconectar_demo_log( 'Dados de demonstração removidos. A faixa de aviso foi desativada.' );
}

/* ==========================================================================
   Entrada
   ========================================================================== */

if ( ! class_exists( 'WooCommerce' ) ) {
	reconectar_demo_abortar( 'O WooCommerce precisa estar ativo para rodar o seed.' );
}

// A carga delega o cadastro de vendedores e de empresas ao Reconectar Core.
// Sem ele não há caminho de produção a exercitar, e uma carga que criasse os
// vendedores por conta própria voltaria a divergir do código real — que é o
// defeito que a delegação existe para impedir. Abortar cedo, com a causa dita,
// é melhor que um fatal de classe inexistente no meio da criação das lojas.
if ( ! class_exists( 'Reconectar_Empresa' ) || ! class_exists( 'Reconectar_Vendedores' ) ) {
	reconectar_demo_abortar( 'O plugin Reconectar Core precisa estar ativo para rodar o seed.' );
}

if ( ! function_exists( 'imagecreatetruecolor' ) ) {
	reconectar_demo_log( 'Aviso: extensão GD indisponível. O conteúdo será criado sem imagens.' );
}

// `wp eval-file` repassa os argumentos extras da linha de comando em $args.
$reconectar_demo_modo = ( isset( $args[0] ) && 'remover' === $args[0] ) ? 'remover' : 'instalar';

if ( 'remover' === $reconectar_demo_modo ) {
	reconectar_demo_log( 'Removendo dados de demonstração da Reconectar...' );
	reconectar_demo_remover();
} else {
	reconectar_demo_log( 'Instalando dados de demonstração da Reconectar...' );
	reconectar_demo_instalar( require __DIR__ . '/dados-demo.php' );
}
