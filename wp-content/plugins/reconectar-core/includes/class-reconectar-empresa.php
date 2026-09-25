<?php
/**
 * A entidade Empresa e os vínculos que ela governa.
 *
 * O Dokan modela vendedores, não empresas: cada loja é um usuário isolado, e
 * nada acima dela agrupa várias. A especificação do Administrador de Empresas
 * pede exatamente esse nível que falta — "Empresa A (Vendedores A1, A2, A3)",
 * "Empresa B (Vendedores B1, B2)" — e é ele que esta classe acrescenta.
 *
 * O agrupamento não altera a natureza do vendedor: cada um segue sendo uma loja
 * Dokan independente, com seus produtos, seus pedidos e seu faturamento. A
 * empresa é a camada de *gestão* por cima, e é dela que sai o isolamento entre
 * administradores de empresas distintas.
 *
 * Aqui moram as três perguntas que todo o resto do módulo faz:
 *
 *   - quais empresas este usuário administra?  `empresas_no_escopo()`
 *   - ele pode administrar esta empresa?       `pode_gerir_empresa()`
 *   - ele pode administrar este vendedor?      `pode_gerir_vendedor()`
 *
 * @package reconectar-core
 */

defined( 'ABSPATH' ) || exit;

class Reconectar_Empresa {

	/**
	 * Post type da empresa.
	 */
	const POST_TYPE = 'reconectar_empresa';

	/**
	 * Prefixo das metas de dados cadastrais.
	 */
	const PREFIXO_META = '_reconectar_empresa_';

	/**
	 * Meta que guarda se a empresa está em operação.
	 */
	const META_ATIVA = '_reconectar_empresa_ativa';

	/**
	 * User meta do vendedor: a empresa a que ele pertence.
	 *
	 * Um vendedor pertence a no máximo uma empresa — é o que a especificação
	 * descreve, e é o que mantém "de quem é este pedido" com uma resposta só.
	 */
	const META_VINCULO = '_reconectar_empresa_id';

	/**
	 * User meta do Administrador de Empresas: as empresas que ele administra.
	 *
	 * Lista de IDs, e não um ID único, porque o vínculo é N:N desde o início. A
	 * especificação prevê o administrador de múltiplas empresas como exceção, e
	 * uma estrutura que só comporta uma obrigaria a migrar dados no dia em que a
	 * exceção aparecesse.
	 */
	const META_ESCOPO = '_reconectar_empresas_geridas';

	/**
	 * User meta do vendedor: o estado de atividade dele, próprio.
	 *
	 * Distinto do estado da empresa de propósito — ver `aplicar_permissao_de_venda()`.
	 */
	const META_VENDEDOR_ATIVO = '_reconectar_vendedor_ativo';

	/**
	 * Siglas válidas de unidade federativa.
	 *
	 * Lista fechada, e não `strlen() === 2`: "Alagoas" digitado com pressa vira
	 * "AL" corretamente, mas "A1" ou "XX" passariam por uma checagem de tamanho e
	 * entrariam no cadastro como endereço de lugar nenhum.
	 */
	const UNIDADES_FEDERATIVAS = array(
		'AC',
		'AL',
		'AM',
		'AP',
		'BA',
		'CE',
		'DF',
		'ES',
		'GO',
		'MA',
		'MG',
		'MS',
		'MT',
		'PA',
		'PB',
		'PE',
		'PI',
		'PR',
		'RJ',
		'RN',
		'RO',
		'RR',
		'RS',
		'SC',
		'SE',
		'SP',
		'TO',
	);

	/**
	 * Registra os ganchos.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'registrar_post_type' ) );
		add_action( 'init', array( __CLASS__, 'registrar_metas' ) );
	}

	/* ---------------------------------------------------------------------
	 * Estrutura de dados
	 * ------------------------------------------------------------------ */

	/**
	 * Registra o post type da empresa.
	 *
	 * `public => false` porque empresa não é conteúdo de vitrine: ela não tem
	 * página própria, não entra em busca e não aparece em arquivo. Quem a
	 * consulta é o painel de empresas, que monta as telas por conta própria.
	 *
	 * Todas as capacidades apontam para uma única — `CAP_GERIR_EMPRESAS` — em vez
	 * de derivarem de `capability_type`, que geraria oito e espalharia a resposta
	 * de "quem mexe em empresa" por oito nomes diferentes. Com uma só, a pergunta
	 * se responde de relance, que é o que uma trava de acesso precisa permitir.
	 */
	public static function registrar_post_type() {
		$labels = array(
			'name'               => __( 'Empresas', 'reconectar-core' ),
			'singular_name'      => __( 'Empresa', 'reconectar-core' ),
			'add_new_item'       => __( 'Adicionar nova empresa', 'reconectar-core' ),
			'edit_item'          => __( 'Editar empresa', 'reconectar-core' ),
			'new_item'           => __( 'Nova empresa', 'reconectar-core' ),
			'view_item'          => __( 'Ver empresa', 'reconectar-core' ),
			'search_items'       => __( 'Buscar empresas', 'reconectar-core' ),
			'not_found'          => __( 'Nenhuma empresa encontrada', 'reconectar-core' ),
			'not_found_in_trash' => __( 'Nenhuma empresa na lixeira', 'reconectar-core' ),
			'menu_name'          => __( 'Empresas', 'reconectar-core' ),
		);

		$capacidade = Reconectar_Permissoes::CAP_GERIR_EMPRESAS;

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'menu_icon'           => 'dashicons-building',
				'supports'            => array( 'title' ),
				'has_archive'         => false,
				'rewrite'             => false,
				'exclude_from_search' => true,
				'map_meta_cap'        => true,
				/*
				 * Só primitivas aqui. As meta caps — `edit_post`, `read_post`,
				 * `delete_post` — ficam com o padrão de propósito, e apontá-las para
				 * `$capacidade` não é redundância: é defeito.
				 *
				 * Com `map_meta_cap => true`, `register_post_type()` monta um mapa
				 * reverso (`$post_type_meta_caps`) de valor para meta cap. Declarar
				 * `'delete_post' => 'reconectar_gerir_empresas'` grava ali
				 * `reconectar_gerir_empresas => delete_post`, e a partir daí toda
				 * verificação da primitiva vira uma verificação de `delete_post` sem
				 * post nenhum — que o core denuncia com `_doing_it_wrong()`.
				 *
				 * O estrago não fica no aviso. Ele é impresso no meio do `admin_menu`,
				 * antes dos cabeçalhos, e derruba em silêncio todo `wp_safe_redirect()`
				 * de `admin_init` — inclusive o de `bloquear_area_administrativa()`. O
				 * `/wp-admin/` passou a responder 200 para cliente, vendedor e
				 * Administrador de Empresas ao mesmo tempo: seis casos do
				 * `verificar-acessos.sh` caíram juntos, e nenhum deles em código de RBAC.
				 *
				 * As primitivas abaixo já bastam: o core mapeia as meta caps para elas.
				 */
				'capabilities'        => array(
					'edit_posts'          => $capacidade,
					'edit_others_posts'   => $capacidade,
					'delete_posts'        => $capacidade,
					'delete_others_posts' => $capacidade,
					'publish_posts'       => $capacidade,
					'read_private_posts'  => $capacidade,
					'create_posts'        => $capacidade,
				),
			)
		);
	}

	/**
	 * Os campos cadastrais da empresa.
	 *
	 * Uma lista só, consultada tanto pelo registro das metas quanto pelo
	 * formulário e pela ficha. Enquanto os três tinham a sua própria, bastava
	 * acrescentar um campo em um deles para ele nunca ser gravado.
	 *
	 * As chaves além de `rotulo` e `tipo` são todas opcionais e servem ao
	 * formulário: `mascara` liga o formatador correspondente no JS **e** o
	 * normalizador em `normalizar_campo()` — os dois lados leem esta mesma linha,
	 * que é o que impede a máscara da tela de divergir da regra do servidor.
	 * `ajuda` vira o texto associado por `aria-describedby`: o formato esperado
	 * precisa estar escrito, e não só insinuado pelo comportamento do campo, para
	 * quem navega por leitor de tela ou preenche colando de outro sistema.
	 *
	 * @return array<string,array<string,string>> Chave => atributos do campo.
	 */
	public static function campos() {
		return array(
			'razao_social' => array(
				'rotulo'       => __( 'Razão social', 'reconectar-core' ),
				'tipo'         => 'text',
				'autocomplete' => 'organization',
			),
			'cnpj'         => array(
				'rotulo'    => __( 'CNPJ', 'reconectar-core' ),
				'tipo'      => 'text',
				'mascara'   => 'cnpj',
				'inputmode' => 'numeric',
				'maxlength' => 18,
				'ajuda'     => __( 'Só números; a pontuação é aplicada sozinha. Exemplo: 00.000.000/0001-91.', 'reconectar-core' ),
			),
			'responsavel'  => array(
				'rotulo'       => __( 'Pessoa responsável', 'reconectar-core' ),
				'tipo'         => 'text',
				'autocomplete' => 'name',
			),
			'email'        => array(
				'rotulo'       => __( 'E-mail de contato', 'reconectar-core' ),
				'tipo'         => 'email',
				'validacao'    => 'email',
				'inputmode'    => 'email',
				'autocomplete' => 'email',
				'ajuda'        => __( 'Exemplo: contato@cooperativa.org.br.', 'reconectar-core' ),
			),
			'telefone'     => array(
				'rotulo'       => __( 'Telefone', 'reconectar-core' ),
				'tipo'         => 'text',
				'mascara'      => 'telefone',
				'inputmode'    => 'tel',
				'autocomplete' => 'tel-national',
				'maxlength'    => 16,
				'ajuda'        => __( 'Com DDD, fixo ou celular. Exemplo: (82) 90000-0000.', 'reconectar-core' ),
			),
			'municipio'    => array(
				'rotulo'       => __( 'Município', 'reconectar-core' ),
				'tipo'         => 'text',
				'autocomplete' => 'address-level2',
			),
			'uf'           => array(
				'rotulo'    => __( 'UF', 'reconectar-core' ),
				'tipo'      => 'text',
				'mascara'   => 'uf',
				'maxlength' => 2,
				'ajuda'     => __( 'Duas letras. Exemplo: AL.', 'reconectar-core' ),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Normalização dos campos cadastrais
	 * ------------------------------------------------------------------ */

	/**
	 * Valida e normaliza um campo cadastral antes de gravar.
	 *
	 * A máscara do formulário é conforto, não controle: ela roda no navegador,
	 * desaparece com o JS desligado e não existe para quem envia o POST por fora
	 * da tela. Quem decide o que entra na meta é esta função, e é por isso que ela
	 * mora na entidade, e não na view — `salvar()` é chamada também pela carga de
	 * demonstração e por WP-CLI, que não passam por formulário nenhum.
	 *
	 * O valor é gravado **formatado**, do mesmo jeito que a ficha o exibe. A
	 * alternativa — guardar só os dígitos e formatar na leitura — obrigaria toda
	 * tela futura a lembrar de formatar, e a primeira que esquecesse mostraria
	 * `82900000000` a um usuário. Normalizar na entrada resolve uma vez.
	 *
	 * Campo vazio passa: só o nome da empresa é obrigatório. O cadastro pode ser
	 * completado depois, e exigir CNPJ de uma cooperativa em formalização seria
	 * inventar um requisito que o edital não faz.
	 *
	 * @param string $chave Chave do campo, conforme `campos()`.
	 * @param string $valor Valor cru, vindo do POST ou da carga.
	 * @return string|WP_Error Valor normalizado, ou erro com mensagem ao usuário.
	 */
	public static function normalizar_campo( $chave, $valor ) {
		$campos = self::campos();
		$valor  = sanitize_text_field( $valor );

		if ( ! isset( $campos[ $chave ] ) || '' === $valor ) {
			return $valor;
		}

		$campo  = $campos[ $chave ];
		$regra  = isset( $campo['mascara'] ) ? $campo['mascara'] : '';
		$regra  = isset( $campo['validacao'] ) ? $campo['validacao'] : $regra;
		$rotulo = $campo['rotulo'];

		switch ( $regra ) {
			case 'cnpj':
				$digitos = preg_replace( '/\D/', '', $valor );

				if ( ! self::cnpj_e_valido( $digitos ) ) {
					return new WP_Error(
						'reconectar_cnpj_invalido',
						sprintf(
							/* translators: %s: rótulo do campo. */
							__( '%s inválido. Confira os 14 dígitos — os dois últimos são verificadores e não conferem.', 'reconectar-core' ),
							$rotulo
						)
					);
				}

				return self::formatar_cnpj( $digitos );

			case 'telefone':
				$digitos = preg_replace( '/\D/', '', $valor );
				$total   = strlen( $digitos );

				// Dez dígitos é fixo com DDD, onze é celular com o nono. Nada além
				// disso: número sem DDD não dá para ligar de fora do município, e a
				// interface não teria como adivinhar qual é.
				if ( 10 !== $total && 11 !== $total ) {
					return new WP_Error(
						'reconectar_telefone_invalido',
						sprintf(
							/* translators: %s: rótulo do campo. */
							__( '%s inválido. Informe DDD e número: 10 dígitos para fixo, 11 para celular.', 'reconectar-core' ),
							$rotulo
						)
					);
				}

				return self::formatar_telefone( $digitos );

			case 'email':
				$limpo = sanitize_email( $valor );

				if ( ! is_email( $limpo ) ) {
					return new WP_Error(
						'reconectar_email_invalido',
						sprintf(
							/* translators: %s: rótulo do campo. */
							__( '%s inválido. Confira o endereço — falta o @ ou o domínio.', 'reconectar-core' ),
							$rotulo
						)
					);
				}

				return $limpo;

			case 'uf':
				$sigla = strtoupper( preg_replace( '/[^A-Za-z]/', '', $valor ) );

				if ( ! in_array( $sigla, self::UNIDADES_FEDERATIVAS, true ) ) {
					return new WP_Error(
						'reconectar_uf_invalida',
						sprintf(
							/* translators: %s: rótulo do campo. */
							__( '%s inválida. Use a sigla de duas letras do estado, como AL.', 'reconectar-core' ),
							$rotulo
						)
					);
				}

				return $sigla;
		}

		return $valor;
	}

	/**
	 * O CNPJ passa nos dois dígitos verificadores?
	 *
	 * Conferir só o comprimento aceitaria `11.111.111/1111-11`, que tem 14 dígitos
	 * e não é CNPJ de ninguém. Daí a checagem de sequência repetida antes da
	 * conta: o algoritmo dos verificadores aprova todas elas.
	 *
	 * @param string $digitos Somente dígitos.
	 * @return bool
	 */
	public static function cnpj_e_valido( $digitos ) {
		if ( 14 !== strlen( $digitos ) || preg_match( '/^(\d)\1{13}$/', $digitos ) ) {
			return false;
		}

		foreach ( array( 12, 13 ) as $posicao ) {
			$peso = 2;
			$soma = 0;

			for ( $i = $posicao - 1; $i >= 0; $i-- ) {
				$soma += (int) $digitos[ $i ] * $peso;
				$peso  = 9 === $peso ? 2 : $peso + 1;
			}

			$resto    = $soma % 11;
			$esperado = $resto < 2 ? 0 : 11 - $resto;

			if ( (int) $digitos[ $posicao ] !== $esperado ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Aplica a pontuação do CNPJ.
	 *
	 * @param string $digitos Somente dígitos, já validados.
	 * @return string
	 */
	public static function formatar_cnpj( $digitos ) {
		return preg_replace( '/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digitos );
	}

	/**
	 * Aplica a pontuação do telefone.
	 *
	 * @param string $digitos Somente dígitos, 10 ou 11.
	 * @return string
	 */
	public static function formatar_telefone( $digitos ) {
		$padrao = 11 === strlen( $digitos )
			? '/^(\d{2})(\d{5})(\d{4})$/'
			: '/^(\d{2})(\d{4})(\d{4})$/';

		return preg_replace( $padrao, '($1) $2-$3', $digitos );
	}

	/**
	 * Registra as metas cadastrais e a de atividade.
	 */
	public static function registrar_metas() {
		$autorizar = function () {
			return current_user_can( Reconectar_Permissoes::CAP_GERIR_EMPRESAS );
		};

		foreach ( array_keys( self::campos() ) as $chave ) {
			register_post_meta(
				self::POST_TYPE,
				self::PREFIXO_META . $chave,
				array(
					'type'          => 'string',
					'single'        => true,
					'default'       => '',
					'show_in_rest'  => false,
					'auth_callback' => $autorizar,
				)
			);
		}

		register_post_meta(
			self::POST_TYPE,
			self::META_ATIVA,
			array(
				'type'          => 'string',
				'single'        => true,
				'default'       => 'sim',
				'show_in_rest'  => false,
				'auth_callback' => $autorizar,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Escopo e isolamento
	 * ------------------------------------------------------------------ */

	/**
	 * Quais empresas este usuário administra?
	 *
	 * Os três retornos são estados distintos, e a diferença entre os dois últimos
	 * é o isolamento inteiro:
	 *
	 *   `null`     — todas, sem restrição (tem `CAP_TODAS_AS_EMPRESAS`);
	 *   `array()`  — nenhuma: o painel abre vazio e nada é acessível;
	 *   `array(…)` — exatamente estas.
	 *
	 * Tratar `null` como "lista vazia" por engano esvaziaria o painel do
	 * Administrador; tratar `array()` como "todas" entregaria a plataforma
	 * inteira a um usuário sem vínculo nenhum. Os dois já foram escritos por
	 * descuido em códigos parecidos — daí o cuidado de manter os tipos separados.
	 *
	 * @param int $usuario_id Usuário a avaliar; 0 usa o usuário atual.
	 * @return int[]|null Lista de IDs, ou null para alcance total.
	 */
	public static function empresas_no_escopo( $usuario_id = 0 ) {
		$usuario_id = $usuario_id ? (int) $usuario_id : get_current_user_id();

		if ( ! $usuario_id ) {
			return array();
		}

		if ( user_can( $usuario_id, Reconectar_Permissoes::CAP_TODAS_AS_EMPRESAS ) ) {
			return null;
		}

		$ids = get_user_meta( $usuario_id, self::META_ESCOPO, true );

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * Grava as empresas que um usuário administra.
	 *
	 * @param int   $usuario_id Usuário.
	 * @param int[] $empresas   IDs de empresa.
	 * @return void
	 */
	public static function definir_empresas_geridas( $usuario_id, array $empresas ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $empresas ) ) ) );

		update_user_meta( (int) $usuario_id, self::META_ESCOPO, $ids );
	}

	/**
	 * Este usuário pode administrar esta empresa?
	 *
	 * @param int $empresa_id ID da empresa.
	 * @param int $usuario_id Usuário a avaliar; 0 usa o usuário atual.
	 * @return bool
	 */
	public static function pode_gerir_empresa( $empresa_id, $usuario_id = 0 ) {
		$usuario_id = $usuario_id ? (int) $usuario_id : get_current_user_id();
		$empresa_id = (int) $empresa_id;

		if ( ! $usuario_id || ! $empresa_id ) {
			return false;
		}

		if ( ! user_can( $usuario_id, Reconectar_Permissoes::CAP_PAINEL_EMPRESAS ) ) {
			return false;
		}

		if ( self::POST_TYPE !== get_post_type( $empresa_id ) ) {
			return false;
		}

		$escopo = self::empresas_no_escopo( $usuario_id );

		if ( null === $escopo ) {
			return true;
		}

		return in_array( $empresa_id, $escopo, true );
	}

	/**
	 * Este usuário pode administrar este vendedor?
	 *
	 * A resposta passa pela empresa: quem não está vinculado a empresa nenhuma
	 * não é administrável por ninguém a não ser pela administração técnica —
	 * inclusive os vendedores que existiam antes deste módulo.
	 *
	 * @param int $vendedor_id ID do vendedor.
	 * @param int $usuario_id  Usuário a avaliar; 0 usa o usuário atual.
	 * @return bool
	 */
	public static function pode_gerir_vendedor( $vendedor_id, $usuario_id = 0 ) {
		$usuario_id = $usuario_id ? (int) $usuario_id : get_current_user_id();

		if ( ! Reconectar_Permissoes::eh_vendedor( (int) $vendedor_id ) ) {
			return false;
		}

		$empresa_id = self::empresa_do_vendedor( $vendedor_id );

		if ( ! $empresa_id ) {
			return user_can( $usuario_id, Reconectar_Permissoes::CAP_TODAS_AS_EMPRESAS );
		}

		return self::pode_gerir_empresa( $empresa_id, $usuario_id );
	}

	/* ---------------------------------------------------------------------
	 * Consultas
	 * ------------------------------------------------------------------ */

	/**
	 * Lista as empresas que o usuário administra.
	 *
	 * @param int $usuario_id Usuário; 0 usa o atual.
	 * @return WP_Post[]
	 */
	public static function listar( $usuario_id = 0 ) {
		$escopo = self::empresas_no_escopo( $usuario_id );

		// Sem nenhuma empresa no escopo não há consulta a fazer. O atalho também
		// evita a armadilha do `post__in` vazio, que o WP_Query ignora — e
		// devolveria justamente todas as empresas a quem não administra nenhuma.
		if ( is_array( $escopo ) && empty( $escopo ) ) {
			return array();
		}

		$argumentos = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( null !== $escopo ) {
			$argumentos['post__in'] = $escopo;
		}

		return get_posts( $argumentos );
	}

	/**
	 * A qual empresa este vendedor pertence?
	 *
	 * @param int $vendedor_id ID do vendedor.
	 * @return int ID da empresa, ou 0 se não houver vínculo.
	 */
	public static function empresa_do_vendedor( $vendedor_id ) {
		return (int) get_user_meta( (int) $vendedor_id, self::META_VINCULO, true );
	}

	/**
	 * Quais vendedores pertencem a esta empresa?
	 *
	 * @param int $empresa_id ID da empresa.
	 * @return int[] IDs dos vendedores.
	 */
	public static function vendedores_da_empresa( $empresa_id ) {
		// `WP_User_Query` honra `meta_key`/`meta_value` de verdade, ao contrário
		// de `wc_get_orders()` — ver a armadilha registrada no CLAUDE.md.
		$vendedores = get_users(
			array(
				'role'       => 'seller',
				'meta_key'   => self::META_VINCULO,
				'meta_value' => (int) $empresa_id,
				'fields'     => 'ID',
				'orderby'    => 'display_name',
				'order'      => 'ASC',
			)
		);

		return array_map( 'intval', $vendedores );
	}

	/**
	 * Todos os vendedores das empresas que o usuário administra.
	 *
	 * @param int $usuario_id Usuário; 0 usa o atual.
	 * @return int[] IDs dos vendedores.
	 */
	public static function vendedores_no_escopo( $usuario_id = 0 ) {
		$vendedores = array();

		foreach ( self::listar( $usuario_id ) as $empresa ) {
			$vendedores = array_merge( $vendedores, self::vendedores_da_empresa( $empresa->ID ) );
		}

		return array_values( array_unique( $vendedores ) );
	}

	/* ---------------------------------------------------------------------
	 * Escrita
	 * ------------------------------------------------------------------ */

	/**
	 * Cria ou atualiza uma empresa.
	 *
	 * @param int   $empresa_id ID da empresa; 0 cria uma nova.
	 * @param array $dados      `nome` mais as chaves de `campos()`.
	 * @return int|WP_Error ID da empresa, ou erro.
	 */
	public static function salvar( $empresa_id, array $dados ) {
		$empresa_id = (int) $empresa_id;
		$permitido  = defined( 'WP_CLI' ) && WP_CLI;

		if ( ! $permitido ) {
			$permitido = $empresa_id
				? self::pode_gerir_empresa( $empresa_id )
				: current_user_can( Reconectar_Permissoes::CAP_GERIR_EMPRESAS );
		}

		if ( ! $permitido ) {
			return new WP_Error(
				'reconectar_sem_permissao',
				__( 'Você não tem permissão para alterar esta empresa.', 'reconectar-core' )
			);
		}

		$nome = isset( $dados['nome'] ) ? sanitize_text_field( $dados['nome'] ) : '';

		if ( '' === $nome ) {
			return new WP_Error(
				'reconectar_dados_invalidos',
				__( 'Informe o nome da empresa.', 'reconectar-core' )
			);
		}

		// Validar tudo antes de gravar qualquer coisa. Normalizar dentro do laço de
		// `update_post_meta()` gravaria os campos até o primeiro inválido e abortaria
		// no meio: a empresa ficaria com metade do cadastro novo e metade do antigo,
		// e a tela de erro mostraria um estado que o usuário não pediu nem escolheu.
		$normalizados = array();

		foreach ( array_keys( self::campos() ) as $chave ) {
			if ( ! array_key_exists( $chave, $dados ) ) {
				continue;
			}

			$valor = self::normalizar_campo( $chave, $dados[ $chave ] );

			if ( is_wp_error( $valor ) ) {
				return $valor;
			}

			$normalizados[ $chave ] = $valor;
		}

		$post = array(
			'post_title'  => $nome,
			'post_type'   => self::POST_TYPE,
			'post_status' => 'publish',
		);

		if ( $empresa_id ) {
			$post['ID'] = $empresa_id;
			$resultado  = wp_update_post( $post, true );
		} else {
			$resultado = wp_insert_post( $post, true );
		}

		if ( is_wp_error( $resultado ) ) {
			return $resultado;
		}

		$novo_id = (int) $resultado;

		foreach ( $normalizados as $chave => $valor ) {
			update_post_meta( $novo_id, self::PREFIXO_META . $chave, $valor );
		}

		// Quem acabou de cadastrar precisa entrar no escopo da própria empresa, ou
		// ela desaparece do painel dele no instante seguinte ao cadastro — a
		// listagem é filtrada pelo escopo, e uma empresa recém-criada não estaria
		// nele. Não se aplica a quem já enxerga todas.
		if ( ! $empresa_id && get_current_user_id() ) {
			$escopo = self::empresas_no_escopo();

			if ( is_array( $escopo ) ) {
				$escopo[] = $novo_id;
				self::definir_empresas_geridas( get_current_user_id(), $escopo );
			}
		}

		return $novo_id;
	}

	/* ---------------------------------------------------------------------
	 * Atividade
	 * ------------------------------------------------------------------ */

	/**
	 * A empresa está em operação?
	 *
	 * O estado mora em meta própria, e não em `post_status`: `draft` significa
	 * "não publicado", que não é a mesma coisa que "desativado". Sequestrar essa
	 * semântica faria qualquer consulta futura por `publish` mudar de sentido sem
	 * aviso — e "listar as empresas cadastradas" passaria a excluir as inativas
	 * em lugares que nada têm a ver com esta regra.
	 *
	 * Meta ausente conta como ativa: o estado neutro de um cadastro é estar em
	 * operação, e uma empresa criada por outro caminho (importação, WP-CLI) não
	 * deve nascer invisível.
	 *
	 * @param int $empresa_id ID da empresa.
	 * @return bool
	 */
	public static function esta_ativa( $empresa_id ) {
		return 'nao' !== get_post_meta( (int) $empresa_id, self::META_ATIVA, true );
	}

	/**
	 * Ativa ou desativa a empresa, propagando a decisão aos vendedores dela.
	 *
	 * @param int  $empresa_id ID da empresa.
	 * @param bool $ativa      Novo estado.
	 * @return void
	 */
	public static function definir_ativa( $empresa_id, $ativa ) {
		$empresa_id = (int) $empresa_id;

		update_post_meta( $empresa_id, self::META_ATIVA, $ativa ? 'sim' : 'nao' );

		foreach ( self::vendedores_da_empresa( $empresa_id ) as $vendedor_id ) {
			self::aplicar_permissao_de_venda( $vendedor_id );
		}
	}

	/**
	 * Recalcula se o vendedor pode vender, a partir dos dois estados.
	 *
	 * A permissão efetiva é a conjunção: a empresa precisa estar ativa **e** o
	 * vendedor também. Guardar os dois separados não é preciosismo de modelagem —
	 * é o que faz a reativação da empresa ser correta. Se a desativação apenas
	 * escrevesse `dokan_enable_selling = no` em todo mundo, o estado individual se
	 * perderia, e reativar a empresa colocaria de volta em operação justamente o
	 * vendedor que havia sido desativado à parte.
	 *
	 * @param int $vendedor_id ID do vendedor.
	 * @return void
	 */
	public static function aplicar_permissao_de_venda( $vendedor_id ) {
		$vendedor_id = (int) $vendedor_id;
		$empresa_id  = self::empresa_do_vendedor( $vendedor_id );

		$empresa_permite  = ! $empresa_id || self::esta_ativa( $empresa_id );
		$vendedor_permite = 'nao' !== get_user_meta( $vendedor_id, self::META_VENDEDOR_ATIVO, true );

		update_user_meta(
			$vendedor_id,
			'dokan_enable_selling',
			( $empresa_permite && $vendedor_permite ) ? 'yes' : 'no'
		);
	}

	/**
	 * O vendedor está ativo, considerando também a empresa dele?
	 *
	 * @param int $vendedor_id ID do vendedor.
	 * @return bool
	 */
	public static function vendedor_esta_ativo( $vendedor_id ) {
		return 'yes' === get_user_meta( (int) $vendedor_id, 'dokan_enable_selling', true );
	}
}
