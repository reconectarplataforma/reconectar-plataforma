# Deploy — GitHub Actions para a instância EC2

Os workflows estão em [`.github/workflows/`](../.github/workflows/). Um push na
`main` sincroniza o código autoral com a instância e roda o `provision.sh`. Os
dados de demonstração ficam num workflow manual, fora do fluxo automático.

## O que a esteira faz

| Workflow | Quando | O que faz |
| --- | --- | --- |
| [`verificar.yml`](../.github/workflows/verificar.yml) | todo push e todo pull request de fork | `php -l` em `reconectar-core/`, `themes/reconectar/` e `scripts/`; `bash -n` nos `scripts/*.sh` |
| [`implantar.yml`](../.github/workflows/implantar.yml) | push na `main`, ou disparo manual | `rsync` do código autoral, `docker compose up -d db wordpress`, `provision.sh` |
| [`demonstracao.yml`](../.github/workflows/demonstracao.yml) | só manual, com escolha `instalar`/`remover` | `seed-demo.sh instalar` ou `seed-demo.sh remover -y` |

O preparo do SSH é uma ação composta, [`.github/actions/preparar-ssh/`](../.github/actions/preparar-ssh/action.yml),
usada pelos dois workflows que falam com o servidor. Não é enfeite: duas cópias
do mesmo bloco divergem, e a que não recebeu o ajuste passa a falhar por um
motivo que ninguém procuraria no arquivo certo.

O deploy é `rsync` a partir do runner, não `git pull` no servidor: o runner já
tem o checkout e já tem a chave SSH, então **o servidor não precisa de nenhuma
credencial do GitHub**.

### O recorte da sincronização

Só quatro caminhos sobem, e cada um com o `--delete` aplicado **dentro de si**:

```
wp-content/themes/reconectar/
wp-content/plugins/reconectar-core/
scripts/
docker-compose.yml
```

`git ls-files wp-content/` devolve exatamente os dois primeiros. O núcleo do
WordPress, o Storefront, o WooCommerce, o Dokan, o bbPress, o BuddyPress e todo
o `uploads/` **não estão no Git** — chegam pelo `provision.sh`, no destino.

Um `--delete` no nível de `wp-content/` apagaria os cinco plugins de terceiros,
o tema pai e os uploads, que não têm origem nenhuma para serem restaurados.
Dentro de cada diretório listado o `--delete` é o comportamento desejado: um
arquivo removido do tema tem de sumir do servidor.

O `rsync` roda com `--rsync-path="sudo rsync"` porque os arquivos do bind-mount
pertencem ao UID 33 (a imagem Apache é Debian) e o `ec2-user` é 1000. Sem isso a
escrita falha em arquivo que já existe.

## Preparação do servidor (uma vez)

### 1. Docker e o plugin Compose

Amazon Linux 2023:

```bash
sudo dnf install -y docker
sudo systemctl enable --now docker
sudo usermod -aG docker ec2-user
sudo mkdir -p /usr/libexec/docker/cli-plugins
sudo curl -sSL https://github.com/docker/compose/releases/latest/download/docker-compose-linux-x86_64 -o /usr/libexec/docker/cli-plugins/docker-compose
sudo chmod +x /usr/libexec/docker/cli-plugins/docker-compose
```

Saia e entre de novo no SSH para o grupo `docker` valer. Confira com
`docker compose version`.

### 2. Diretório do projeto

```bash
sudo mkdir -p /opt/reconectar
sudo chown ec2-user:ec2-user /opt/reconectar
```

Este caminho é o valor da variável `CAMINHO_REMOTO`.

### 3. O `.env`, **antes do primeiro `up`**

O `wp-config.php` nasce na primeira subida do container e **não é reescrito**
quando as variáveis de ambiente mudam depois. Senha de banco e porta precisam
estar certas antes, não depois. Se o arquivo já tiver nascido errado, o conserto
é derrubar o volume (`docker compose down -v`), o que apaga o banco.

Crie `/opt/reconectar/.env` à mão, com as chaves abaixo. **Troque as senhas** —
os defaults do `docker-compose.yml` são de desenvolvimento e estão no
repositório.

```
WORDPRESS_PORT=80
WORDPRESS_DEBUG=0
WP_URL=http://ec2-3-148-211-66.us-east-2.compute.amazonaws.com

MYSQL_DATABASE=reconectar
MYSQL_USER=reconectar
MYSQL_PASSWORD=<senha forte>
MYSQL_ROOT_PASSWORD=<outra senha forte>

WORDPRESS_DB_NAME=reconectar
WORDPRESS_DB_USER=reconectar
WORDPRESS_DB_PASSWORD=<a mesma de MYSQL_PASSWORD>

WP_ADMIN_USER=<login do super administrador>
WP_ADMIN_PASSWORD=<senha forte>
WP_ADMIN_EMAIL=<e-mail real>
```

`WP_ADMIN_PASSWORD` tem default `reconectar-admin` no `provision.sh:12`. Deixar o
default é publicar a conta de super administrador junto com o site.

Sobre o `WP_URL`: ele **precisa** ser o DNS público. A resolução dinâmica de host
(`scripts/configurar-url-dinamica.php:82`) é uma allowlist que só aceita
`localhost`, o loopback e os três blocos privados — um DNS da AWS não casa e cai
em `RECONECTAR_HOST_PADRAO`, derivado de `WP_URL`. Com o default
`http://localhost:8090`, o site sobe carimbando todo asset e todo link com
`localhost`, e o sintoma é a página crua, sem CSS, para qualquer visitante.

### 4. Security Group

- **80/tcp** para `0.0.0.0/0` — o site.
- **22/tcp** para os runners do GitHub.

Este é o preço da opção escolhida: os IPs dos runners hospedados pelo GitHub são
amplos e variáveis (a lista está em `https://api.github.com/meta`, campo
`actions`, e muda), então na prática a 22 fica aberta. Um runner auto-hospedado
na própria EC2 eliminaria a exposição, ao custo de manter o runner.

A 8081 (phpMyAdmin) e a 8090 **não** devem ser liberadas. O phpMyAdmin nem sobe:
o job nomeia os serviços (`up -d db wordpress`), e é assim que ele fica fora do
ar sem precisar de arquivo de override.

## Secrets e variables do repositório

Settings → Secrets and variables → Actions.

**Aba Secrets** — só um, o único valor que precisa ficar ilegível depois de
gravado:

| Secret | Valor |
| --- | --- |
| `SSH_CHAVE_PRIVADA` | o conteúdo inteiro do `dev.pem`, com as linhas `BEGIN`/`END` |

**Aba Variables** — o resto. Nenhum deles é segredo, e como variável eles
aparecem legíveis no log, o que é o que se quer quando um deploy falha por
endereço errado:

| Variable | Valor |
| --- | --- |
| `SSH_HOST` | `ec2-3-148-211-66.us-east-2.compute.amazonaws.com` |
| `SSH_USUARIO` | `ec2-user` |
| `SSH_HOST_KEY` | saída do `ssh-keyscan` (abaixo) |
| `CAMINHO_REMOTO` | `/opt/reconectar` |

A chave **do host** é pública por definição — é o que o servidor apresenta a
quem conecta. Guardá-la como secret só faria o GitHub mascará-la no log, e a
mensagem de erro de host mudado viraria `***`, ilegível justamente na hora em que
ela importa. `SSH_HOST` como variable é o que permite montar a URL do
environment; como secret ela sairia mascarada ali também.

Para o `SSH_HOST_KEY`, na sua máquina:

```bash
ssh-keyscan -t ed25519 ec2-3-148-211-66.us-east-2.compute.amazonaws.com
```

Cole a linha inteira. Ela é o que dispensa o `StrictHostKeyChecking=no`:
desligar a verificação faria a esteira aceitar qualquer servidor que
respondesse naquele endereço, numa sessão que carrega uma chave de produção.

### O environment `producao`

Os dois workflows que falam com o servidor declaram `environment: producao`.
Criá-lo em Settings → Environments não é obrigatório para eles rodarem, mas é
onde ficam as duas proteções que valem a pena:

- **Required reviewers** — o deploy espera aprovação humana antes de rodar.
- **Deployment branches** — restringe o environment à `main`, de modo que nenhum
  workflow de outra branch alcance os secrets dele.

Isso é o análogo do *Protected* do GitLab. Some-se a ele o comportamento do
próprio GitHub: **secrets não são entregues a workflow disparado por pull
request de fork**, então um PR de terceiro nunca vê a chave, mesmo que altere o
arquivo do workflow.

Se alguma dessas cinco entradas (`SSH_CHAVE_PRIVADA`, `SSH_HOST`, `SSH_USUARIO`,
`SSH_HOST_KEY`, `CAMINHO_REMOTO`) estiver ausente, os workflows remotos param
antes do primeiro `ssh` e registram no resumo do job quais valores faltam. O
erro antigo era pior: o job seguia com `ALVO=@` e falhava com a ajuda do `ssh`,
sem dizer o que realmente estava faltando.

A chave `dev.pem` **nunca entra no repositório** — `*.pem` está no `.gitignore`.
Na sua máquina, `chmod 400 dev.pem`.

## Verificação depois do primeiro deploy

1. **O recorte do `rsync`.** Na instância, `ls /opt/reconectar/wp-content/plugins/`
   tem de listar os cinco plugins de terceiros ao lado do `reconectar-core`, e
   `ls /opt/reconectar/wp-content/uploads/` não pode estar vazio. Rode o deploy
   **duas vezes** e repita — a segunda é a que pega o erro.
2. **`WP_URL`.** Abra o site pelo DNS público e confira no HTML que nenhum asset
   sai com `localhost`.
3. **Idempotência.** Dois deploys seguidos sem commit no meio (o segundo pelo
   "Run workflow" do `implantar.yml`): o `provision.sh` tem de imprimir "já
   instalado" / "já ativo" em todas as linhas.
4. **Superfície.** `curl` na 8081 e na 8090 do DNS público tem de falhar; só a 80
   responde.
5. **RBAC.** `./scripts/verificar-acessos.sh` apontado para o servidor. As travas
   não têm outro teste, e este é o primeiro ambiente onde elas rodam fora do
   `localhost`.

## O que esta esteira não faz

- **HTTPS.** O bloco de `configurar-url-dinamica.php` monta `http://` fixo
  (`:88-89`). TLS exigiria um proxy na frente e mexer naquele bloco — que é
  gerado, então editar à mão não sobrevive ao próximo provisionamento.
- **Backup do banco antes do deploy.** O deploy não roda migração de schema, mas
  o `provision.sh` escreve opções. Um dump prévio seria a rede de segurança.
- **Rollback automático.** Voltar é reverter na `main` e disparar o
  `implantar.yml` à mão. Sem release versionada e sem symlink de versão.
- **Ambiente de homologação separado.** Um servidor só, uma branch só.
- **Rotação da chave.** O `dev.pem` é uma chave de longa duração num secret.
