# TinyMCE 8.9.2 — cópia auto-hospedada

Editor da Incubadora (`assets/js/incubadora-editor.js`). Não há etapa de
compilação nem CDN: os arquivos abaixo são servidos como estão.

## Origem

| Arquivo | Pacote | SHA-256 do tarball |
| --- | --- | --- |
| tudo, menos `langs/` | `https://registry.npmjs.org/tinymce/-/tinymce-8.9.2.tgz` | `a6be6b984a254128a1a5624ffaca1d4558698cb3717ec3ec2fc367d7e2a10051` |
| `langs/pt-BR.js` | `https://registry.npmjs.org/tinymce-i18n/-/tinymce-i18n-26.9.28.tgz` (arquivo `langs8/pt-BR.js`) | `ad97385d56fbe59228403c969b90bfe11d7f2c7b652e1acef13dfea46b149607` |

Os dois tarballs foram conferidos contra o `dist.integrity` (SHA-512) do
registro antes da extração. O SHA-256 do `pt-BR.js` copiado é
`a10f51ac86481ea0f0ec1f4dffe1be709f6bc7e27e9f9a946008f66b2a4fc293`.

O TinyMCE não traz traduções no pacote. As oficiais (Community Language Packs)
são entregues pela Tiny por um formulário, sem endereço estável para conferir;
o `tinymce-i18n` as redistribui sem alteração.

## Recorte

Só o que o editor carrega: `tinymce.min.js`, o modelo `dom`, o tema `silver`,
os ícones padrão, a pele `oxide` (apenas `skin.min.css` e
`content.inline.min.css`, porque o editor roda em modo `inline`) e os plugins
`lists`, `link`, `image`, `media`, `table`, `autolink` e `emoticons` (com
`js/emojis.min.js`). Ficam de fora as versões não minificadas, as definições
TypeScript, as outras peles e os demais plugins.

Plugin novo na barra exige copiar o `plugins/<nome>/plugin.min.js` do mesmo
tarball. Sem o arquivo, o TinyMCE registra um 404 e segue sem o botão.

`emoticons` fica em `emoticons_database: 'emojis'` (caracteres Unicode). A
variante `emojiimages` busca imagens no `cdnjs.cloudflare.com`, o que
avisaria um terceiro de quem abriu o editor.

## Licença

TinyMCE: GPL-2.0-or-later (ver `license.md`), usado com
`license_key: 'gpl'`. Bibliotecas embutidas: `notices.txt`. O arquivo de idioma
segue a licença do TinyMCE.

## Atualizar

Baixar o tarball novo, conferir o `dist.integrity`, recriar este diretório com
o mesmo recorte numa pasta com o número da versão nova e trocar a constante
`Reconectar_Incubadora_Editor::VERSAO_TINYMCE`. A pasta com versão no nome
evita que o navegador misture arquivos de duas versões em cache.
