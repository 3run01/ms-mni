# Design: armazenamento do conteúdo HTML de documentos no S3

**Data:** 2026-07-14
**Status:** aprovado

## Contexto e problema

Documentos de processo com `mimetype = text/html` têm seu conteúdo bruto salvo na coluna
`processo_documentos.conteudo_html` (`text`, Postgres) por
`SalvarDocumentoProcessoService::downloadHTML`. Essa coluna está deixando a tabela e o
banco pesados.

O PDF gerado a partir desse HTML já vai para o S3
(`documentos-processos/{numero_processo}/{id_documento}.pdf`). O HTML bruto deve seguir o
mesmo caminho.

Mapa de uso atual da coluna:

- **Escrita:** apenas `downloadHTML` (após gerar o PDF).
- **Leitura como flag** ("já baixou o HTML?"): `SalvarDocumentoProcessoService::baixarDocumento`
  e `DocumentoController::getDocumento`, ambos via `empty($documento->conteudo_html)`.
- **Exposição na API:** o campo não está no `$hidden` do model, então é serializado em
  `GET /api/documento/visualizar` e `GET /api/processo/documentos/listar`. Consumidores
  externos **usam** o `conteudo_html` retornado pelo `visualizar`.
- A UI web e `GET /api/processo/consultar` já excluem a coluna via select explícito.

## Decisões

1. **Consumidores dependem do campo** → o response do `/documento/visualizar` continua
   entregando `conteudo_html`, agora hidratado do S3 quando necessário.
2. **Listagem fica leve** → `/processo/documentos/listar` deixa de retornar `conteudo_html`
   (inclusive para registros legados).
3. **Só documentos novos** → sem backfill dos registros existentes; a coluna permanece no
   schema e os legados continuam sendo servidos a partir dela. Um comando de backfill é
   evolução natural futura, fora deste escopo.
4. **Abordagem escolhida:** coluna `path_html` + hidratação explícita no service
   (alternativas descartadas: path por convenção com `Storage::exists` a cada checagem;
   accessor lazy no model com I/O de rede escondido).

## Mudanças

### Schema e model

- Migration: `path_html` (`string` 255, nullable) em `processo_documentos`.
- `ProcessoDocumento`:
  - `path_html` no `$fillable`;
  - `path_html` e `conteudo_html` no `$hidden`;
  - novo helper `temConteudoHtml(): bool` → `!empty($this->conteudo_html) || !empty($this->path_html)`.

### Escrita — `SalvarDocumentoProcessoService::downloadHTML`

1. Fluxo atual mantido: consulta MNI, decodifica base64, gera PDF e salva no S3.
2. Novo: salva o HTML bruto em `documentos-processos/{numero_processo}/{id_documento}.html`
   (mesmo disk `s3`, mesma pasta do PDF).
3. Grava `path_html` no registro e **remove** a escrita em `conteudo_html` — a coluna para
   de crescer a partir do deploy.
4. O loop de retry (3 tentativas com sleep) hoje duplicável vira um método privado
   `putComRetry(string $path, string $conteudo)` usado pelos uploads de PDF e HTML.

Os dois `put` sobrescrevem objetos com chave determinística (idempotentes); se o upload do
HTML falhar após o PDF subir, a exceção propaga, o documento permanece `pendente` e o job
re-executa com segurança.

### Leitura — flag de existência e hidratação

- `baixarDocumento` (linha ~95) e `DocumentoController::getDocumento` (linha ~77) trocam
  `empty($documento->conteudo_html)` por `!$documento->temConteudoHtml()`. Para documentos
  não-HTML os dois campos ficam vazios e o comportamento atual se mantém (chama
  `baixarDocumento`, que early-returns se já baixado).
- Novo método no service: `obterConteudoHtml(ProcessoDocumento $documento): ?string`
  - coluna `conteudo_html` preenchida → retorna direto (legado);
  - senão, `path_html` preenchido → `Storage::disk('s3')->get($path_html)`;
  - senão → `null`.
- `DocumentoController::getDocumento`: ao montar a resposta do `visualizar` para documentos
  HTML, injeta o valor de `obterConteudoHtml` no JSON **no momento da resposta, sem
  persistir nada de volta** na coluna. O formato do response não muda para o consumidor.

### Serialização e API

- Com `conteudo_html` no `$hidden`:
  - `/processo/documentos/listar` fica leve automaticamente (legados inclusos);
  - a página web de detalhe segue igual (já usava select explícito; teste
    `assertJsonMissingPath` existente permanece verde);
  - apenas o `visualizar` expõe o conteúdo, explicitamente.

## Tratamento de erros

- **Upload falhou (PDF ou HTML):** exceção propaga, documento `pendente`, job re-tenta —
  semântica atual preservada.
- **`path_html` aponta para objeto ausente/S3 indisponível na leitura:**
  `obterConteudoHtml` captura a falha e o fluxo re-executa `downloadHTML` (re-baixa do MNI
  e regrava PDF + HTML) — mesmo padrão de auto-correção já usado para PDFs via
  `Storage::exists($documento->path)`. Se a re-tentativa falhar, loga e responde com
  `conteudo_html` nulo; o consumidor ainda recebe o `link` do PDF.
- **Legado:** registros com a coluna preenchida não dependem do S3 para servir o HTML.

## Testes

Feature tests com `Storage::fake('s3')` e os mocks de MNI já usados na suíte:

1. `downloadHTML` grava o `.html` no S3, seta `path_html` e não escreve em `conteudo_html`.
2. `visualizar` de documento novo → response contém `conteudo_html` hidratado do S3.
3. `visualizar` de documento legado (coluna preenchida) → response contém o HTML da coluna,
   sem tocar o S3.
4. `listar` → payload sem `conteudo_html`, mesmo com registro legado no banco.
5. Documento HTML sem coluna e sem `path_html` → dispara o download.
6. Suíte existente permanece verde (`ProcessoConsultaTest` etc.).

## Fora de escopo

- Backfill dos registros existentes (mover `conteudo_html` legado para o S3) e posterior
  remoção da coluna + `VACUUM`/`pg_repack` para recuperar espaço.
- Tabela legada `documentos` (tem coluna homônima, mas não possui model nem uso no código).
- Atualização da documentação OpenAPI (spec de 2026-07-13): quando implementada, o response
  de `/documento/visualizar` deve documentar o campo `conteudo_html`.
