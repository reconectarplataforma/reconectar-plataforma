# Deploy — GitLab CI para a instância EC2

A esteira está em [`.gitlab-ci.yml`](../.gitlab-ci.yml). Um push na `main`
sincroniza o código autoral com a instância e roda o `provision.sh`. Os dados de
demonstração ficam em jobs manuais, fora do fluxo automático.

## O que a esteira faz

| Estágio | Quando | O que faz |
| --- | --- | --- |
| `verificar` | toda branch e todo merge request | `php -l` em `reconectar-core/`, `themes/reconectar/` e `scripts/`; `bash -n` nos `scripts/*.sh` |
| `implantar` | push na `main` | `rsync` do código autoral, `docker compose up -d db wordpress`, `provision.sh` |
| `demonstracao` | manual, depois do deploy | `seed-demo.sh instalar` ou `seed-demo.sh remover -y` |

O deploy é `rsync` a partir do runner, não `git pull` no servidor: o runner já
tem o checkout e já tem a chave SSH, então **o servidor não precisa de nenhuma
credencial do GitLab**.

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
repositório público.

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
- **22/tcp** para os runners do GitLab.

Este é o preço da opção escolhida: os IPs dos runners compartilhados são amplos
e variáveis, então na prática a 22 fica aberta. Um runner instalado na própria
EC2 eliminaria a exposição, ao custo de manter o runner.

A 8081 (phpMyAdmin) e a 8090 **não** devem ser liberadas. O phpMyAdmin nem sobe:
o job nomeia os serviços (`up -d db wordpress`), e é assim que ele fica fora do
ar sem precisar de arquivo de override.

## Variáveis CI/CD do GitLab

Settings → CI/CD → Variables.

| Variável | Tipo | Valor |
| --- | --- | --- |
| `SSH_CHAVE_PRIVADA` | **File**, Protected | o conteúdo do `dev.pem` |
| `SSH_HOST` | Variable, Protected | `ec2-3-148-211-66.us-east-2.compute.amazonaws.com` |
| `SSH_USUARIO` | Variable, Protected | `ec2-user` |
| `SSH_HOST_KEY` | Variable, Protected | saída do `ssh-keyscan` (abaixo) |
| `CAMINHO_REMOTO` | Variable, Protected | `/opt/reconectar` |

`SSH_CHAVE_PRIVADA` **não** pode ser marcada como *Masked*: o GitLab só mascara
valores de linha única, e uma chave privada tem várias. *File* + *Protected* é a
combinação correta — *Protected* restringe a variável a branches protegidas, o
que impede que um MR de terceiro dispare um job com acesso à chave.

Para o `SSH_HOST_KEY`, na sua máquina:

```bash
ssh-keyscan -t ed25519 ec2-3-148-211-66.us-east-2.compute.amazonaws.com
```

Cole a linha inteira. Ela é o que dispensa o `StrictHostKeyChecking=no`:
desligar a verificação faria a esteira aceitar qualquer servidor que
respondesse naquele endereço, numa sessão que carrega uma chave de produção.

A chave `dev.pem` **nunca entra no repositório** — `*.pem` está no `.gitignore`.
Na sua máquina, `chmod 400 dev.pem`.

## Verificação depois do primeiro deploy

1. **O recorte do `rsync`.** Na instância, `ls /opt/reconectar/wp-content/plugins/`
   tem de listar os cinco plugins de terceiros ao lado do `reconectar-core`, e
   `ls /opt/reconectar/wp-content/uploads/` não pode estar vazio. Rode o deploy
   **duas vezes** e repita — a segunda é a que pega o erro.
2. **`WP_URL`.** Abra o site pelo DNS público e confira no HTML que nenhum asset
   sai com `localhost`.
3. **Idempotência.** Dois deploys seguidos sem commit no meio: o `provision.sh`
   tem de imprimir "já instalado" / "já ativo" em todas as linhas.
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
- **Rollback.** Voltar é dar deploy no commit anterior. Sem release versionada e
  sem symlink de versão.
- **Ambiente de homologação separado.** Um servidor só, uma branch só.
- **Rotação da chave.** O `dev.pem` é uma chave de longa duração numa variável
  de CI.
