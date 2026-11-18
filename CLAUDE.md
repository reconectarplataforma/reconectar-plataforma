# Instruções para agentes — Plataforma Reconectar

Marketplace multi-vendedor em WordPress do projeto "Reconectar" (edital
FUNDEPES/UNOPS, "Nosso Chão Nossa História"). Cinco atores: Super Administrador
(`administrator`), Administrador (`company_admin`), Moderador de Conteúdo
(`content_moderator`), Loja (`seller`) e Usuário Comum (`customer`). Licença
GPL-2.0-or-later.

**Vocabulário.** A **empresa** é a Reconectar Incubadora Digital; cada negócio
que ela cadastra é uma **Loja**. No painel de empresas e no código autoral dele,
a entidade se chama loja (`Reconectar_Lojas`, `META_LOJA_ATIVA`,
`/painel-empresas/loja/`). "Vendedor" sobrevive onde o nome é do **Dokan** — o
papel `seller`, a capacidade `dokandar`, as metas `dokan_*` — e ali não se
traduz. `Reconectar_Permissoes::eh_vendedor()` e os dois métodos de isolamento
por vendedor ficam com o nome antigo de propósito: falam do papel, não da
entidade.

Antes de mexer em qualquer coisa, leia a seção **Armadilhas** abaixo. Ela não
é uma lista de boas práticas genéricas: cada item corresponde a um erro que já
aconteceu neste repositório, e vários custaram horas. As armadilhas de cada
área ficam em `.claude/rules/`, e cada uma é carregada quando a sessão mexe
naquela área.

## Mapa

| Onde | O que é |
| --- | --- |
| `docker-compose.yml` | 5 serviços: `db`, `wordpress`, `wpcli`, `demo`, `phpmyadmin` |
| `scripts/provision.sh` | instala núcleo, idioma, tema, plugins, páginas, permalinks |
| `scripts/seed/dados-demo.php` | catálogo declarativo da demonstração (só dados) |
| `scripts/seed/demo.php` | motor da carga: cria e remove (só lógica) |
| `scripts/demo-completa.sh` | ambiente + provisionamento + carga, em um comando |
| `scripts/seed-demo.sh` | atalho para `instalar` / `remover` só a carga |
| `scripts/verificar-acessos.sh` | testa as travas de RBAC por HTTP, nos cinco perfis |
| `scripts/configurar-url-dinamica.php` | grava no `wp-config.php` o bloco que resolve a URL pelo `Host` |
| `scripts/normalizar-urls.php` | converte em caminho as URLs absolutas já gravadas no banco |
| `wp-content/themes/reconectar/` | tema autoral (child theme do Storefront) |
| `wp-content/plugins/reconectar-core/` | plugin autoral: RBAC, status, governança |
| `…/includes/class-reconectar-painel-empresas.php` | painel gerencial, rota própria fora do `/wp-admin` |
| `…/includes/painel-empresas/` | os templates das telas do painel |
| `scripts/icones-de-categoria.php` | associa os ícones SVG às categorias; chamado pelo provisionamento e pela carga |
| `wp-content/themes/reconectar/assets/icones/categorias/` | os ícones de categoria, autorais e versionados |
| `…/includes/class-reconectar-modulos.php` | a tela `/modulos/`: Loja, Administrador, Moderador e Super Administrador escolhem Mercado, Incubadora ou Praça a cada login; Loja e Super Administrador têm ainda o cartão do painel |
| `…/includes/class-reconectar-painel-gestao.php` | a rota `/gestao/<area>/` na moldura do Dokan: enquetes (`…-painel-enquetes.php`), categorias do fórum (`…-painel-foruns.php`), campanhas da home (`…-painel-campanhas.php`) e a fila de moderação de avaliações e do fórum (`…-painel-moderacao.php`), para Administrador e Moderador sem o `/wp-admin` |
| `…/includes/painel-gestao/` | os templates das telas de gestão |
| `…/includes/class-reconectar-servicos.php` | serviços no mercado: preço "A combinar", solicitação sem pagamento, resposta da loja |
| `…/includes/class-reconectar-privacidade.php` | apagador e exportador nas ferramentas de privacidade do núcleo: votos, comentários da Incubadora, rastros do fórum, login social, loja |
| `…/includes/class-reconectar-email.php` | SMTP por variável de ambiente, logo e acentos da marca nos e-mails, embrulho dos de texto puro |
| `…/includes/class-reconectar-migracoes.php` | migrações de dados versionadas (meta e capacidade) |
| `…/includes/class-reconectar-incubadora*.php` | a Incubadora, wiki interna: rotas, leitura, ações, sanitizador, arquivos, editor, busca, interação (vídeo em destaque, avaliação, comentários) |
| `…/includes/incubadora/` | os templates da Incubadora |
| `…/assets/vendor/tinymce/8.9.2/` | o editor da Incubadora, vendorizado; origem e licença no `LEIAME.md` |
| `scripts/verificar-incubadora.php` | operações da Incubadora por WP-CLI, chamado pelo `verificar-acessos.sh` |
| `wp-content/themes/reconectar/bbpress/` | overrides de template do fórum |
| `wp-content/themes/reconectar/inc/forum/` | consultas, componentes e telas do Q&A; o menu "Moderar" no próprio tópico está em `reconectar_forum_moderacao()` |
| `wp-content/themes/reconectar/buddypress/` | overrides do Nouveau: feed em três colunas, perfil com capa |
| `wp-content/themes/reconectar/inc/comunidade.php` | colunas laterais e cartão "Sobre" da Comunidade; CSS em `assets/css/comunidade.css` |
| `docs/STACKS.md` | as camadas da plataforma e por que cada uma existe |
| `docs/PERFIS_E_PERMISSOES.md` | os cinco atores e a matriz de permissões |
| `docs/ROTEIRO_PERFIS.md` | roteiro de demonstração, com credenciais |
| `docs/DADOS_DEMONSTRACAO.md` | o que a carga cria, em detalhe |
| `docs/CADASTRO_MANUAL.md` | popular um ambiente real à mão, pelo Super Administrador |
| `docs/EXCLUSAO_DE_DADOS.md` | atender pedido de exclusão: apagador primeiro, conta depois |
| `DIARIO_DESENVOLVIMENTO.md` | histórico cronológico das entregas |

Plugins e temas de terceiros **não são versionados**. Só `wp-content/themes/reconectar/`
e `wp-content/plugins/reconectar-core/` entram no Git.

## Comandos

```bash
./scripts/demo-completa.sh
```

Sobe o ambiente, provisiona e popula. Idempotente: rodar de novo não duplica
nada. Site em http://localhost:8090, painel em `/wp-admin/`, phpMyAdmin em
http://localhost:8081.

```bash
docker compose run --rm demo
```

O mesmo, supondo os serviços já de pé.

```bash
./scripts/seed-demo.sh remover
```

Apaga só os dados de demonstração, preservando a instalação.

```bash
./scripts/verificar-acessos.sh
```

512 casos de permissão, nos cinco perfis: a maior parte por HTTP, e as
operações da Incubadora por `scripts/verificar-incubadora.php`, que ele chama.
Sai com status 1 se algum falhar. **Rode depois de mexer em qualquer coisa de
RBAC** — as travas não têm teste automatizado além deste.

```bash
./scripts/permissoes-dev.sh
```

Dono e permissões de `wp-content/`, quando host e container brigam por um
arquivo. `--conferir` só relata.

```bash
docker compose --project-directory /caminho/absoluto/para/reconectar-plataforma run --rm --entrypoint php wpcli -l /var/www/scripts/seed/demo.php
```

Lint de PHP. Não existe atalho: veja a primeira armadilha.

## Armadilhas

Aqui ficam as de ambiente, as que valem em qualquer área e as que apagam dado
sem volta. As demais estão em `.claude/rules/`, separadas por área, e cada
arquivo declara em `paths` os caminhos que o carregam:

| Arquivo | Área |
| --- | --- |
| `tema.md` | CSS, Storefront, Bootstrap, BuddyPress, telas do carrinho e do checkout |
| `pagamento.md` | gateways, checkout, sub-pedidos e painel de pedidos do Dokan, QR Code |
| `plugin.md` | RBAC, bbPress, Incubadora, privacidade, e-mail, migrações |
| `scripts.md` | `provision.sh`, WP-CLI, carga de demonstração, URL dinâmica, deploy |

Ficam em `.claude/`, e não junto do código, porque o deploy sincroniza o tema e o
plugin inteiros: um `CLAUDE.md` ali seria servido pelo Apache em produção.

### PHP não existe no host

Só dentro do container. `php -l` no host falha com exit 127. Todo lint passa
por `docker compose run --rm --entrypoint php wpcli -l <arquivo>`.

### `scripts/` não está montado no serviço `wordpress`

O bind-mount `./scripts:/var/www/scripts` existe apenas em `wpcli` e `demo`.
`docker compose exec wordpress php -l /var/www/scripts/...` falha por arquivo
inexistente — um erro que parece de sintaxe e não é.

### Nunca use `cd` relativo no Bash

O diretório de trabalho da sessão persiste entre chamadas. Use caminhos
absolutos e `--project-directory` no `docker compose`.

### `wc_get_orders()` descarta filtros em silêncio

Esta é a pior. A função reconhece uma lista fechada de argumentos e **ignora
sem aviso** o que está fora dela — `meta_key`, `meta_value` e também
`meta_query`, que parece suportado por ser o nome que a `WP_Query` usa.

O resultado de uma consulta "filtrada" por meta não é vazio: é o banco inteiro.
Com um `limit` pequeno, esse banco inteiro passa por uma resposta plausível.

Foi exatamente isso que fez a carga recriar pedidos a cada execução, e o mesmo
defeito estava no caminho de remoção — onde teria apagado **todos** os pedidos
da instalação, em definitivo, sem lixeira.

Argumentos que funcionam de verdade: `limit`, `status`, `return`, `customer`,
`parent`. Para filtrar por meta, traga o conjunto e filtre em PHP.

### O Dokan mantém tabelas paralelas

`wp_dokan_orders` e `wp_dokan_vendor_balance` guardam as vendas, e é delas que
o painel do vendedor lê o faturamento. Apagar o pedido no WooCommerce **não**
limpa essas linhas: o plugin as remove a partir dos seus próprios hooks de
estorno, não da exclusão definitiva. Linhas órfãs viram faturamento fantasma no
painel. Veja `reconectar_demo_limpar_tabelas_dokan()`.

### `class_exists` numa guarda `||` curto-circuita antes do autoload

`if ( ! class_exists( 'Foo' ) || ! Foo::bar() )` parece defensivo e é o oposto:
com a classe ainda não carregada, o primeiro termo é verdadeiro, o segundo
**nunca roda**, e a função devolve o recuo — lista vazia, meio nenhum — sem que
nada tenha falhado. Como a chamada que dispararia o autoload está no termo
curto-circuitado, a classe segue não carregada para sempre naquela requisição.

O sintoma é a tela sair correta em alguns carregamentos e vazia em outros,
conforme a ordem em que outro código tiver carregado a classe antes.

### `pre_option_*` faz o `wp option update` recusar a gravação

O fuso é imposto por `Reconectar_Fuso`, em `pre_option_timezone_string`, porque
instalação nova nasce com a opção vazia — UTC no núcleo — e o "Aberta agora" das
lojas errava por três horas. O efeito colateral: com o plugin ativo,
`wp option update timezone_string …` lê o valor **filtrado**, conclui que nada
mudou e responde "unchanged" sem gravar. O banco fica vazio e a mensagem parece
sucesso. Gravação de opção que o plugin filtra vai com `--skip-plugins`, na
leitura e na escrita.

### bbPress e BuddyPress estão ativos

bbPress 2.6.18 e BuddyPress 14.5.2 são instalados e ativados pelo
`provision.sh`. O fórum de perguntas e respostas é bbPress com uma camada
autoral por cima (`Reconectar_Forum`, no plugin; `inc/forum/`, no tema; overrides
em `themes/reconectar/bbpress/`).

### Qualquer aviso PHP impresso antes de um redirect cancela a ação

`WORDPRESS_DEBUG=1` liga `WP_DEBUG`, e o padrão de `WP_DEBUG_DISPLAY` é
imprimir no corpo da resposta. Uma linha impressa antes de `wp_redirect()`
derruba os dois `header()` de `pluggable.php:1539` e `:1542` com "headers
already sent" — e o efeito não é uma mensagem feia numa tela que funciona: **é
a ação não acontecer**. Salvar pergunta no fórum gravava o tópico e parava numa
tela de avisos, sem nunca chegar nele.

`provision.sh` fixa `WP_DEBUG_LOG=true` e `WP_DEBUG_DISPLAY=false`: o aviso
continua existindo, em `wp-content/debug.log`, e fora do corpo da resposta.
Ao conferir essas constantes, lembre que `wp config get` devolve o valor
**avaliado** (`1` e string vazia), nunca o literal que `--raw` gravou — e que
uma constante inexistente também devolve vazio, igual a `false`.

### O diretório do plugin é `includes/`, não `inc/`

`inc/` é a convenção do **tema**. Confundir os dois leva a criar arquivo em
lugar que nada carrega.

### `set_date_created()`, não `set_created_date()`

O segundo não existe e o erro só aparece em tempo de execução, como fatal.

### UID divergente entre as imagens

A imagem CLI é Alpine (`www-data` = 82); a Apache é Debian (`www-data` = 33), e
é ela que cria os arquivos no volume. Por isso `wpcli` e `demo` declaram
`user: "33:33"`. Quando a escrita falhar mesmo assim:

```bash
./scripts/permissoes-dev.sh
```

Ele acerta dono, grupo e setgid em `wp-content/` inteiro — não faça o `chown` à
mão. `--conferir` relata sem alterar nada.

### Blocksy está instalado e **inativo**

A migração nunca aconteceu, e o tema ativo continua sendo o `reconectar`. Não
trate a presença do Blocksy como decisão tomada. Há um `docker/backup-pre-blocksy.sql`.

### `.env` é bloqueado por regra de segurança

Não leia nem escreva `.env*`. Se precisar de uma variável nova, entregue o
trecho no chat para o usuário criar à mão. O `.env.example` documenta as chaves.

### A porta é 8090

A 8080 estava ocupada na máquina de desenvolvimento. Documentação que diga 8080
está desatualizada.

### O deploy sincroniza **dois** diretórios de `wp-content/`, nunca a pasta

`git ls-files wp-content/` devolve exatamente `plugins/reconectar-core/` e
`themes/reconectar/`. O núcleo do WordPress, o Storefront, o WooCommerce, o
Dokan, o bbPress, o BuddyPress e **todo** o `uploads/` não estão no Git — chegam
pelo `provision.sh` no destino.

Um `rsync --delete` no nível de `wp-content/` apaga a instalação inteira do
servidor. Os plugins e o tema pai voltariam num provisionamento; os **uploads
não têm origem nenhuma** para serem restaurados. Dentro de cada diretório
versionado, o `--delete` é o que se quer: arquivo removido do tema tem de sumir
de lá.

`.github/workflows/implantar.yml` traz esse recorte com um comentário longo por
cima. É o tipo de bloco que alguém "simplifica" para uma linha só seis meses
depois — e o estrago não aparece no job, que termina em verde.

E o `rsync` precisa de `--rsync-path="sudo rsync"`: os arquivos do bind-mount
pertencem ao UID 33 e o `ec2-user` é 1000, a mesma assimetria registrada acima.

### Fora do `localhost`, o site depende de `WP_URL` apontar para o host público

A allowlist de `Host` (`configurar-url-dinamica.php:82`) só aceita `localhost`,
o loopback e os três blocos privados. Um DNS público — da AWS ou de onde for —
não casa e cai em `RECONECTAR_HOST_PADRAO`, que o `provision.sh:96` deriva de
`WP_URL`.

Com o default `http://localhost:8090`, o servidor sobe funcionando e carimba
**todo** asset e todo link com `localhost`. O sintoma não é erro: é a página
crua, sem CSS, para quem acessa de fora — enquanto para quem faz um túnel SSH
tudo parece certo.

E o `wp-config.php` nasce na primeira subida e não é reescrito quando as
variáveis mudam depois. `WP_URL`, senha de banco e porta têm de estar certas
**antes** do primeiro `up`; consertar depois é derrubar o volume, o que apaga o
banco.

### `required: true` num input de ação composta não barra valor vazio

Ele exige que o `with:` **traga a chave**, não que o valor chegue preenchido. Os
workflows sempre trazem as cinco; o que falta é o secret ou a variable por trás
delas, e a ação recebia string vazia sem reclamar.

O sintoma é o `usage` do `ssh`. Medido:

```
ssh "@"                  → usage
ssh "@exemplo.invalido"  → usage
ssh "ec2-user@"          → Could not resolve hostname
ssh ""                   → Could not resolve hostname
```

Com `SSH_USUARIO` ausente, `ALVO` vira `@` e o `ssh` imprime o próprio modo de
uso — o que manda a investigação para a sintaxe da linha, que está correta, e
não para o cadastro que falta. `SSH_HOST` vazio nem chega a ser olhado.

Pior que o erro ilegível: `CAMINHO_REMOTO` vazio faria o passo seguinte
sincronizar para `/wp-content/` e `/scripts/` na **raiz do servidor** — com
`--rsync-path="sudo rsync"`, portanto como root e com `--delete`. Quem barra é
o passo "Conferir que os secrets e variables existem", em
`.github/actions/preparar-ssh/action.yml`.

## Convenções

**Idioma.** Todo código autoral é escrito em português: nomes de função,
variáveis, comentários e documentação. A exceção é o que a API do WordPress
impõe (nomes de hook, chaves de meta com prefixo, parâmetros de terceiros).

**Comentários explicam o porquê, nunca o quê.** Um comentário que descreve o
que a linha faz é ruído; um que registra a razão de ela ser assim — a armadilha
evitada, a alternativa descartada, o defeito que já aconteceu — é o que
sobrevive à próxima leitura. Vários comentários deste repositório são o único
registro de um bug real; não os remova ao refatorar.

**PHPDoc em toda função.** Uma regressão já apagou três blocos por descuido:
revise o diff antes de commitar.

**Prefixo `reconectar_`** em funções globais, `RECONECTAR_` em constantes,
`rc-` em classes CSS do marketplace.

**Idempotência.** Todo script de carga ou provisionamento roda mais de uma vez
sem duplicar nada. Isso é requisito, não cortesia.

**Honestidade de dados.** Tempo de entrega, taxa e distância vêm de metas
gravadas. Nunca calcule um número plausível para preencher a interface: um
valor inventado que parece certo é pior que um campo vazio.

**Acessibilidade.** WCAG 2.1 é requisito do edital, junto de LGPD, GDI e GPL.
Sem `aria-pressed` em `<a>` (use `aria-current`), sem `<details open>` que
abriria menu no celular, contraste conferido.

**Sem build, sem CDN.** Bootstrap 5.3.3 auto-hospedado em `assets/bootstrap/`.
Não introduza etapa de compilação.

## Cores e tipografia

Paleta em `--reconectar-cor-*`, no `:root` de `wp-content/themes/reconectar/style.css`.

Poppins na interface. Thoge **apenas** em `.site-title`.

**Nunca escreva um `font-size` literal em componente `rc-`.** A escala está no
`:root` de `assets/css/marketplace.css`, em nove degraus de razão 1,125 sobre os
16px do documento:

| Token | Valor | Papel |
| --- | --- | --- |
| `--rc-fonte-2xs` | 12px | selo, badge — **piso**, nada desce daqui |
| `--rc-fonte-xs` | 13px | rótulo auxiliar |
| `--rc-fonte-sm` | 14px | meta, apoio |
| `--rc-fonte-base` | 16px | corpo, nome de item |
| `--rc-fonte-md` | 18px | destaque |
| `--rc-fonte-lg` | 20px | subtítulo |
| `--rc-fonte-xl` | 24px | título de seção |
| `--rc-fonte-2xl` | 28px | — |
| `--rc-fonte-3xl` | 36px | título de página |

Mais `--rc-entrelinha-justa` (1.25) e `--rc-entrelinha-compacta` (1.4), para
rótulo e título: sem elas o Storefront entrega 1,618 a tudo, e um rótulo de uma
palavra vira ar. Antes da escala eram 16 valores avulsos entre 0.65rem e 2rem —
o menor deles rendia selo de 10,4px dentro de linha de produto de 122px.

A base do `html` fica em 16px de propósito: subi-la mexeria em carrinho,
checkout e painel do vendedor, que são Woo e Dokan. O painel de empresas, por
ser plugin e não poder exigir o tema, consome os degraus com fallback literal
(`var( --rc-fonte-sm, 0.875rem )`).

## Antes de dar algo por pronto

Rode o fluxo completo e leia a saída. A idempotência da carga só foi
comprovada quando a segunda execução imprimiu "já existia" em todas as linhas —
e foi a primeira execução real que revelou o bug do `wc_get_orders()`. Ler o
código não teria encontrado; rodar encontrou.
