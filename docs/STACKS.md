# Stacks da Plataforma Reconectar

As camadas da plataforma, o que cada uma resolve e — o que mais importa na hora
de mexer — **onde termina a responsabilidade de uma e começa a da seguinte**.

Este documento existe para responder a uma pergunta só: *para esta tarefa, qual
arquivo eu abro?* A tabela de roteamento no fim responde direto; o resto explica
por quê.

## Visão geral

```
┌──────────────────────────────────────────────────────────────┐
│  APRESENTAÇÃO      tema reconectar (child do Storefront)     │
│                    layout, vitrine, cards, filtros           │
├──────────────────────────────────────────────────────────────┤
│  REGRAS AUTORAIS   plugin reconectar-core                    │
│                    RBAC, status de pedido, governança        │
├──────────────────────────────────────────────────────────────┤
│  COMUNIDADE        BuddyPress · bbPress                      │
├──────────────────────────────────────────────────────────────┤
│  MULTI-VENDEDOR    Dokan Lite                                │
│                    lojas, split de pedido, comissão, saldo   │
├──────────────────────────────────────────────────────────────┤
│  COMÉRCIO          WooCommerce                               │
│                    produto, carrinho, pedido, pagamento      │
├──────────────────────────────────────────────────────────────┤
│  NÚCLEO            WordPress 7 · PHP 8.2                     │
├──────────────────────────────────────────────────────────────┤
│  DADOS             MariaDB 10.11                             │
├──────────────────────────────────────────────────────────────┤
│  INFRAESTRUTURA    Docker Compose · Apache                   │
└──────────────────────────────────────────────────────────────┘
```

Versões em uso, conferidas na instalação: WooCommerce 11.1.1, Dokan Lite 5.1.2,
BuddyPress 14.5.2, bbPress 2.6.18, reconectar-core 0.1.0, tema reconectar 1.0.0.

## 1. Infraestrutura — Docker Compose

Cinco serviços em `docker-compose.yml`:

| Serviço | Imagem | Papel |
| --- | --- | --- |
| `db` | `mariadb:10.11` | banco, com healthcheck de que os outros dependem |
| `wordpress` | `wordpress:7-php8.2-apache` | servidor web, porta 8090 |
| `wpcli` | `wordpress:cli-php8.2` | comandos avulsos, sob demanda |
| `demo` | `wordpress:cli-php8.2` | monta a demonstração inteira; profile `demo` |
| `phpmyadmin` | `phpmyadmin:5` | inspeção do banco, porta 8081 |

**Por que `demo` é um serviço e não uma imagem.** A carga não precisa de nada
além do WP-CLI, do PHP 8.2 e da extensão GD — tudo já presente em
`wordpress:cli-php8.2`. Uma imagem autoral seria essa mesma imagem com um script
copiado para dentro, trocando um bind-mount que já funciona por um rebuild a
cada ajuste no script. O `profiles: ["demo"]` mantém o serviço fora do
`docker compose up` comum: ele roda até o fim e sai, e um serviço assim no
conjunto padrão deixaria um container `Exited (0)` a cada subida.

**A assimetria dos volumes importa.** `./scripts:/var/www/scripts` existe apenas
em `wpcli` e `demo`. O serviço `wordpress` não enxerga `scripts/` — tentar rodar
um script por lá falha por arquivo inexistente, com uma mensagem que parece
outra coisa.

## 2. Dados — MariaDB

Prefixo `wp_`. Além das tabelas do núcleo e do WooCommerce, o Dokan mantém as
suas:

| Tabela | Conteúdo | Coluna do pedido |
| --- | --- | --- |
| `wp_dokan_orders` | vínculo pedido ↔ vendedor | `order_id` |
| `wp_dokan_vendor_balance` | lançamentos financeiros | `trn_id` (com `trn_type`) |
| `wp_dokan_order_stats` | agregados de relatório | `order_id` |
| `wp_dokan_refund` | estornos | `order_id` |

**O painel do vendedor lê o faturamento daqui, não dos pedidos.** Apagar um
pedido no WooCommerce não limpa essas linhas — o Dokan as remove a partir dos
seus hooks de estorno e cancelamento, não da exclusão definitiva. Linha órfã
vira venda fantasma no relatório, e o relatório não fica errado por cálculo:
fica certo sobre uma base suja. `reconectar_demo_limpar_tabelas_dokan()` existe
por isso.

**HPOS está inativo** nesta instalação — os pedidos ainda são posts. O código
autoral não assume isso: `dono_do_objeto()` consulta `wc_get_order()` antes de
`get_post_type()` justamente porque, com HPOS ligado, o segundo devolveria
`false` para um ID de pedido válido.

**BuddyPress está ativo sem as suas tabelas.** `wp_bp_activity` não existe, e
`wp_delete_user()` dispara `bp_core_remove_data_on_delete_user`, que erra no
SQL. É ruído no log, não falha: a remoção se completa.

## 3. Núcleo — WordPress

Provisionado por `scripts/provision.sh`: núcleo, idioma pt_BR, tema, plugins,
páginas, permalinks. Idempotente.

## 4. Comércio — WooCommerce

Produto, carrinho, pedido, pagamento. Três meios previstos pelo edital: PIX,
cartão de crédito e boleto — detalhes em [PAGAMENTOS.md](PAGAMENTOS.md).

**`wc_get_orders()` descarta filtros em silêncio.** A função reconhece uma lista
fechada de argumentos e ignora sem aviso o que está fora dela, incluindo
`meta_query` — que parece suportado por ser o nome que a `WP_Query` usa. Uma
consulta "filtrada" por meta não volta vazia: volta o banco inteiro. Funcionam
de verdade: `limit`, `status`, `return`, `customer`, `parent`. Para filtrar por
meta, traga o conjunto e filtre em PHP.

O setter de data é **`set_date_created()`**. `set_created_date()` não existe e
falha só em tempo de execução.

## 5. Multi-vendedor — Dokan Lite

Cria o papel `seller`, a loja por vendedor, o painel de front-end e o split do
pedido.

**O carrinho é único; o pedido, não.** O cliente enche um carrinho com produtos
de vendedores diferentes e fecha uma compra só. No fechamento,
`dokan()->order->maybe_split_orders()` gera um pedido-pai e um sub-pedido por
vendedor, cada um com `_dokan_vendor_id` próprio. Cada vendedor vê apenas o seu.
É assim que a demonstração produz o `ped-006`: um pai e três filhos.

API usada pelo código autoral: `dokan_get_sellers()`, `dokan_get_store_info()`,
`dokan()->order->maybe_split_orders()`, `dokan()->order->get_child_orders()`,
`dokan_get_navigation_url()`.

O papel `seller` tem 67 capacidades — e **nenhuma** delas é `manage_options` ou
`manage_woocommerce`. Essa ausência é o que faz as travas do `reconectar-core`
valerem: elas liberam quem tem `manage_woocommerce`, e o vendedor não tem.

## 6. Comunidade — BuddyPress e bbPress

Fóruns e perfis. Acesso restrito a Administrador e Vendedor; o Cliente não
entra, por decisão de negócio do edital. A trava não está aqui, está na camada
seguinte.

## 7. Regras autorais — plugin `reconectar-core`

O diretório é **`includes/`**. (`inc/` é o tema. Confundir leva a criar arquivo
em lugar que nada carrega.)

| Arquivo | O que faz |
| --- | --- |
| `class-reconectar-permissoes.php` | RBAC: isolamento entre vendedores, comunidade, `/wp-admin`, plugins |
| `class-reconectar-status-pedido.php` | status `wc-preparacao` e `wc-enviado` |
| `class-reconectar-painel-transparencia.php` | prestação de contas pública |
| `class-reconectar-proposta-votacao.php` | governança participativa |
| `class-reconectar-aviso-demo.php` | aviso de ambiente de demonstração |

**Por que as regras estão em plugin, e não no tema.** Trocar de tema não pode
derrubar autorização. A migração para o Blocksy está em aberto; se o RBAC
morasse no `functions.php`, ativar outro tema abriria a plataforma inteira.

O fluxo de pedido do edital — realizado → pago → preparação → enviado →
entregue — não cabia nos status do WooCommerce, que vai de `processing` direto a
`completed`. Os dois status do meio são registrados por
`class-reconectar-status-pedido.php` e declarados como pagos em
`woocommerce_order_is_paid_statuses`, senão o faturamento sumiria dos relatórios
enquanto o pedido estivesse a caminho.

## 8. Apresentação — tema `reconectar`

Child theme do Storefront, em desacoplamento progressivo: `header.php`,
`footer.php` e a home já são autorais, mas o `style.css` ainda declara
`Template: storefront`.

```
inc/setup.php         registro de suportes e menus
inc/enqueue.php       CSS e JS
inc/helpers.php       utilidades
inc/home/             seções da home, penduradas na action `reconectar_home`
inc/marketplace/      cabeçalho, componentes, consultas, filtros, rodapé
assets/css/           marketplace.css, fonts.css
assets/bootstrap/     Bootstrap 5.3.3 auto-hospedado
```

A home é montada por `front-page.php`, que dispara a action `reconectar_home`.
Cada seção se registra com uma prioridade:

| Prioridade | Seção |
| --- | --- |
| 10 | hero |
| 20 | categorias |
| 30 | lojas em destaque |
| 40 | produtos em destaque |
| 45 | vitrine de lojas |
| 50 | comunidade e transparência |

Para inserir uma seção nova, adicione um arquivo em `inc/home/` e pendure-o na
action com a prioridade da posição desejada. Não edite `front-page.php`.

**Sem build e sem CDN.** O Bootstrap está no repositório; o JS dele não é mais
carregado. Não introduza etapa de compilação.

## 9. Demonstração

Separação deliberada em dois arquivos:

- **`scripts/seed/dados-demo.php`** — o catálogo. Só dados: lojas, produtos,
  categorias, clientes, pedidos. É aqui que se mexe para mudar *o que* a
  demonstração mostra.
- **`scripts/seed/demo.php`** — o motor. Só lógica: criar, conferir o que já
  existe, remover. É aqui que se mexe para mudar *como* ela é montada.

Idempotência é requisito: a segunda execução imprime "já existia" em cada linha
e não duplica nada. Foi a primeira execução real — não a leitura do código — que
revelou o bug do `wc_get_orders()`.

O que a carga cria está detalhado em
[DADOS_DEMONSTRACAO.md](DADOS_DEMONSTRACAO.md); o passo a passo por perfil, em
[ROTEIRO_PERFIS.md](ROTEIRO_PERFIS.md).

## Onde mexer, por tarefa

| Tarefa | Arquivo |
| --- | --- |
| Mudar cor, fonte, espaçamento | `themes/reconectar/style.css` |
| Mudar o visual dos cards de loja | `themes/reconectar/assets/css/marketplace.css` |
| Mudar o cabeçalho (busca, carrinho, local) | `themes/reconectar/inc/marketplace/cabecalho.php` |
| Mudar a barra de filtros | `themes/reconectar/inc/marketplace/filtros.php` |
| Mudar como lojas/produtos são buscados | `themes/reconectar/inc/marketplace/consultas.php` |
| Adicionar ou reordenar seção da home | `themes/reconectar/inc/home/` + prioridade na action |
| Carregar CSS ou JS | `themes/reconectar/inc/enqueue.php` |
| Mudar quem pode o quê | `reconectar-core/includes/class-reconectar-permissoes.php` |
| Conferir se as permissões continuam valendo | `scripts/verificar-acessos.sh` |
| Mudar o fluxo de status do pedido | `reconectar-core/includes/class-reconectar-status-pedido.php` |
| Mudar o conteúdo da demonstração | `scripts/seed/dados-demo.php` |
| Mudar como a demonstração é montada | `scripts/seed/demo.php` |
| Mudar a instalação (plugins, páginas) | `scripts/provision.sh` |
| Mudar serviço, porta ou volume | `docker-compose.yml` |

## Fronteiras que não se cruzam

**Autorização não mora no tema.** Qualquer regra de quem-pode-o-quê vai para
`reconectar-core`. Um tema é trocável; a permissão não pode ser.

**Dados não moram no motor.** Loja, produto ou pedido novo entra em
`dados-demo.php`. Se `demo.php` crescer com nome de loja dentro, a separação
perdeu a razão de existir.

**Não se edita plugin ou tema de terceiro.** Nada em `wp-content/plugins/` além
de `reconectar-core/`, nada em `wp-content/themes/` além de `reconectar/`. O
resto é reinstalável e não é versionado; uma alteração ali desaparece no próximo
provisionamento. Para mudar comportamento de terceiro, use hook a partir do
`reconectar-core`.

**Não se calcula um número para preencher a tela.** Tempo de entrega, taxa e
distância vêm de metas gravadas. Um valor inventado que parece certo é pior que
um campo vazio, porque ninguém vai conferi-lo.
