# Instruções para agentes — Plataforma Reconectar

Marketplace multi-vendedor em WordPress do projeto "Reconectar" (edital
FUNDEPES/UNOPS, "Nosso Chão Nossa História"). Três atores: Administrador,
Vendedor e Usuário Comum. Licença GPL-2.0-or-later.

Antes de mexer em qualquer coisa, leia a seção **Armadilhas** abaixo. Ela não
é uma lista de boas práticas genéricas: cada item corresponde a um erro que já
aconteceu neste repositório, e vários custaram horas.

## Mapa

| Onde | O que é |
| --- | --- |
| `docker-compose.yml` | 5 serviços: `db`, `wordpress`, `wpcli`, `demo`, `phpmyadmin` |
| `scripts/provision.sh` | instala núcleo, idioma, tema, plugins, páginas, permalinks |
| `scripts/seed/dados-demo.php` | catálogo declarativo da demonstração (só dados) |
| `scripts/seed/demo.php` | motor da carga: cria e remove (só lógica) |
| `scripts/demo-completa.sh` | ambiente + provisionamento + carga, em um comando |
| `scripts/seed-demo.sh` | atalho para `instalar` / `remover` só a carga |
| `scripts/verificar-acessos.sh` | testa as travas de RBAC por HTTP, nos três perfis |
| `wp-content/themes/reconectar/` | tema autoral (child theme do Storefront) |
| `wp-content/plugins/reconectar-core/` | plugin autoral: RBAC, status, governança |
| `docs/STACKS.md` | as camadas da plataforma e por que cada uma existe |
| `docs/PERFIS_E_PERMISSOES.md` | os três atores e a matriz de permissões |
| `docs/ROTEIRO_PERFIS.md` | roteiro de demonstração, com credenciais |
| `docs/DADOS_DEMONSTRACAO.md` | o que a carga cria, em detalhe |
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

16 casos de permissão por HTTP, nos três perfis. Sai com status 1 se algum
falhar. **Rode depois de mexer em qualquer coisa de RBAC** — as travas não têm
teste automatizado além deste.

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

### O diretório do plugin é `includes/`, não `inc/`

`inc/` é a convenção do **tema**. Confundir os dois leva a criar arquivo em
lugar que nada carrega.

### `set_date_created()`, não `set_created_date()`

O segundo não existe e o erro só aparece em tempo de execução, como fatal.

### `--ssl=0` só vale em `wp db *`

Qualquer outro comando do WP-CLI rejeita a flag.

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

Declaradas como custom properties em `wp-content/themes/reconectar/style.css`:

| Variável | Valor |
| --- | --- |
| `--reconectar-cor-primaria` | `#31BEB1` |
| `--reconectar-cor-secundaria` | `#CF6442` |
| `--reconectar-cor-destaque` | `#F1BF3D` |
| `--reconectar-cor-institucional` | `#663191` |

Poppins na interface. Thoge **apenas** em `.site-title`.

## Antes de dar algo por pronto

Rode o fluxo completo e leia a saída. A idempotência da carga só foi
comprovada quando a segunda execução imprimiu "já existia" em todas as linhas —
e foi a primeira execução real que revelou o bug do `wc_get_orders()`. Ler o
código não teria encontrado; rodar encontrou.
