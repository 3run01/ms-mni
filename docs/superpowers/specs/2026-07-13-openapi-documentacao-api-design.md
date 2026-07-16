# Documentação OpenAPI da API REST externa (ms-mni)

**Data:** 2026-07-13
**Tipo:** Documentação
**Serviço:** ms-mni
**Formato:** OpenAPI 3.1 (`openapi.yaml`)
**Idioma:** Português (BR)

## Contexto

A API REST externa do ms-mni (`routes/api.php`) expõe 12 endpoints para consulta de
processos, documentos e tribunais via integração MNI/PJe. Hoje não há documentação
formal — clientes descobrem contratos lendo o código-fonte dos controllers.

Este documento especifica a criação de uma spec **OpenAPI 3.1** única
(`docs/api/openapi.yaml`), renderizável em Swagger UI / Redoc, cobrindo **apenas** a
API REST externa autenticada por token. Rotas web (Inertia) ficam fora de escopo.

## Escopo

**Dentro:**
- 12 endpoints de `routes/api.php` protegidos pelo middleware `ValidateApiToken`.
- Schema de autenticação (header `X-API-Token`).
- Schemas reutilizáveis de request/response.
- Webhook de saída da exportação (`webhooks` do OpenAPI 3.1) — documenta o contrato
  que o cliente SIM implementa.

**Fora:**
- `routes/web.php` (UI Inertia: login, tribunais CRUD, tokens, processos).
- `GET /api/user` (rota Sanctum de scaffolding — não faz parte da API pública).
- Qualquer alteração de código. Documento reflete o comportamento atual como está.

## Autenticação

Todos os endpoints exigem o header:

```
X-API-Token: {token}
```

Validado por `App\Http\Middleware\ValidateApiToken`. Token ausente ou inválido →
resposta `401`:

```json
{ "message": "Token inválido ou não fornecido" }
```

Uso do token é registrado (`registrarUso()`) a cada request válido.

**Nota de segurança (documentar, não corrigir):** os endpoints `GET` recebem
`login_pje` e `senha_pje` como **query params**, expondo credenciais PJe na URL
(logs de servidor, histórico de proxy). A spec deve incluir um aviso em `description`
recomendando não logar essas URLs. Nenhuma mudança de código faz parte desta entrega.

## Parâmetros comuns (query)

| param | tipo | obrig. | usado em |
|---|---|---|---|
| `login_pje` | string | sim | todos os endpoints de processo/documento |
| `senha_pje` | string | sim | todos os endpoints de processo/documento |
| `tribunal_id` | integer | ver endpoint | maioria dos endpoints de processo |
| `numero_processo` | string | ver endpoint | consulta por número |
| `nomeParte` | string | não | busca por nome de parte |
| `data_referencia` | date | não | movimentos/documentos (filtro incremental) |
| `id_documento` | string | sim | `/documento/visualizar` |

Reutilizar via `components/parameters` onde repetido.

## Endpoints

Base path: `/api`. Todos exigem `X-API-Token`.

### 1. `GET /api/processo/consultar` — Buscar processos

Busca por `numero_processo` **ou** `nomeParte`. Retorna lista paginada
(`DEFAULT_PER_PAGE = 10`). Se busca por número e não existe no banco, baixa do MNI
sincronamente; se existe, dispara atualização em background (`BaixarProcessoMNIJob`).

- Query: `login_pje`*, `senha_pje`*, `tribunal_id`* (obrigatório na prática — 400 se ausente), `numero_processo`, `nomeParte`.
- 200: `PaginacaoLaravel<Processo>`.
- 400: `{ "error": "Tribunal não informado" }`.
- 422: validação (`login_pje`/`senha_pje` ausentes).
- 500: `Erro` (`{error, line, file}`).

### 2. `GET /api/processo/visualizar` — Processo completo

Retorna um processo com relações completas (`tribunal`, `partes.representantesProcessual`,
`prioridades`, `classe`, `assuntos`, `movimentos`, `documentos`). Baixa do MNI se ausente;
senão dispara atualização em background.

- Query: `login_pje`*, `senha_pje`*, `tribunal_id`*, `numero_processo`*.
- 200: `Processo`.
- 400: tribunal ou número não informado.
- 422 / 500: idem padrão.

### 3. `GET /api/documento/visualizar` — Baixar documento

Baixa o documento do processo e retorna link temporário S3 (validade 60 min). Até 3
tentativas.

- Query: `login_pje`*, `senha_pje`*, `tribunal_id`, `numero_processo`, `id_documento`.
- 200: `{ "message": string, "documento": Documento }` (com `documento.link`).
- 404: `{ "message": "Não foi possível obter o documento ... após 3 tentativas" }`.
- 422 / 500: idem padrão (500 usa `{ "message": ... }`).

### 4. `GET /api/processo/dados-basicos` — Dados básicos (síncrono)

Retorna dados básicos do processo (`tribunal`, `classe`, `assuntos`, `prioridades`,
`partes.representantesProcessual`). Se existe no banco, retorna direto; senão consulta o
MNI de forma síncrona.

- Query: `login_pje`*, `senha_pje`*, `tribunal_id`, `numero_processo`.
- 200: `Processo` (dados básicos).
- 422 / 500: idem padrão.

### 5. `GET /api/processo/movimentos/listar` — Movimentos (síncrono)

Retorna array de movimentos. Se já há movimentos salvos, retorna do banco; senão
consulta o MNI. Aceita `data_referencia` para filtro incremental.

- Query: `login_pje`*, `senha_pje`*, `tribunal_id`, `numero_processo`, `data_referencia`.
- 200: `Movimento[]`.
- 422 / 500: idem padrão.

### 6. `GET /api/processo/documentos/listar` — Documentos (síncrono)

Retorna array de documentos (com `tipo` resolvido). Retorna do banco se existir; senão
consulta o MNI. Aceita `data_referencia`.

- Query: `login_pje`*, `senha_pje`*, `tribunal_id`, `numero_processo`, `data_referencia`.
- 200: `Documento[]`.
- 422 / 500: idem padrão.

### 7–9. Endpoints assíncronos

Enfileiram um job na fila `alta` e retornam **200 com corpo vazio** (nenhum dado é
devolvido; resultado é processado em background). Documentar o comportamento vazio
explicitamente.

| # | Método | Path | Job |
|---|---|---|---|
| 7 | GET | `/processo/consultar/dados-basicos/async` | `ConsultarDadosBasicosProcessoMNIJob` |
| 8 | GET | `/processo/consultar/movimentos/async` | `ConsultarMovimentosProcessoMNIJob` |
| 9 | GET | `/processo/consultar/documentos/async` | `ConsultarDocumentosProcessoMNIJob` |

- Query (todos): `login_pje`*, `senha_pje`*, `tribunal_id`, `numero_processo`.
- 200: corpo vazio.
- 422: validação.

### 10. `POST /api/processo/download` — Exportar processo (PDF)

Enfileira a geração de um PDF de exportação (`GerarPdfExportacaoJob`, fila
`exportar-processo`). A entrega do arquivo pronto é feita via **webhook de saída** (ver
seção Webhooks). Retorna imediatamente o `exportacao_id`.

Body (JSON), fonte `CriarExportacaoProcessoRequest`:

| campo | tipo | obrig. | regra |
|---|---|---|---|
| `numero_processo` | string (≤25) | sim | — |
| `tribunal_id` | integer | não | — |
| `user_id` | integer (≥1) | sim | eco no webhook |
| `titulo` | string (≤255) | sim | eco no webhook |
| `formato` | string | sim | só `"pdf"` |
| `ids_selecionados` | integer[] | não | filtro por ids |
| `periodo_inicial` | date `Y-m-d` | não | `required_with:periodo_final` |
| `periodo_final` | date `Y-m-d` | não | `required_with:periodo_inicial` |
| `id_inicial` | integer | não | `required_with:id_final` |
| `id_final` | integer | não | `required_with:id_inicial` |

- 200: `{ "message": "Exportação enfileirada", "exportacao_id": integer }`.
- 404: `{ "error": "Nenhum documento encontrado para o processo informado com os filtros aplicados." }`.
- 422: validação do body.

### 11. `GET /api/tribunais` — Listar tribunais ativos

- 200: `Tribunal[]` (apenas `ativo = true`).

### 12. `GET /api/tribunais/{uuid}` — Tribunal por uuid

- Path: `uuid` (string).
- 200: `Tribunal` (ou `null` se não encontrado).

## Webhooks (OpenAPI 3.1 `webhooks`)

Documentar o contrato de saída da exportação — **o cliente SIM implementa** este
endpoint; o ms-mni faz o `POST`.

```
POST {SIM_URL}/webhook/download
```

Dois payloads por status:

- `concluido`: eco de `user_id`/`titulo` + URL/caminho do PDF em S3
  (`downloads/{user_id}/{uuid}.pdf`).
- `falhou`: eco de `user_id`/`titulo` + motivo do erro.

Detalhar os campos exatos a partir de
`docs/superpowers/specs/2026-04-29-webhook-download-exportacao-design.md` durante a
implementação.

## Componentes reutilizáveis (`components`)

### `securitySchemes`
- `ApiToken`: `type: apiKey`, `in: header`, `name: X-API-Token`. Aplicado globalmente via `security`.

### `schemas`
- `Processo` — campos + relações (tribunal, partes, movimentos, documentos, classe, assuntos, prioridades). Derivar dos models/resources.
- `Tribunal` — `id`, `uuid`, `nome`, `ativo`, etc.
- `Movimento` — campos do model `ProcessoMovimento`.
- `Documento` — `id`, `id_documento`, `descricao`, `tipo_documento`, `data_hora`, `nivel_sigilo`, `link` (quando presente), etc.
- `PaginacaoLaravel` — envelope padrão do `paginate()` (`data`, `current_page`, `last_page`, `per_page`, `total`, `links`…), genérico sobre `Processo`.
- `Erro` — `{ error: string, line: integer, file: string }` (respostas 500).
- `ErroMensagem` — `{ message: string }` (401, 404 de documento, 500 de documento).
- `ExportacaoEnfileirada` — `{ message: string, exportacao_id: integer }`.

### `parameters`
- `LoginPje`, `SenhaPje`, `TribunalId`, `NumeroProcesso`, `DataReferencia` — query params reutilizados.

## Estrutura do arquivo

```
docs/api/openapi.yaml
```

- `openapi: 3.1.0`
- `info`: título "API MNI/PJe — ms-mni", versão, descrição com aviso de segurança das credenciais em query string.
- `servers`: URL base configurável (produção / local), path `/api`.
- `security`: `ApiToken` global.
- `tags`: `Processos`, `Documentos`, `Tribunais`, `Exportação`.
- `paths`: 12 endpoints agrupados por tag.
- `webhooks`: `download`.
- `components`: schemas, parameters, securitySchemes, responses comuns (`Erro401`, `Erro422`, `Erro500`).

## Critérios de sucesso

1. `openapi.yaml` valida contra OpenAPI 3.1 (linter, ex. `spectral` ou `swagger-cli validate`).
2. Renderiza sem erro em Swagger UI / Redoc.
3. Os 12 endpoints presentes, cada um com: método, path, params, security, respostas (200 + erros), exemplos.
4. Schemas reutilizados via `$ref` (sem duplicação inline).
5. Webhook de saída documentado.
6. Aviso de segurança sobre credenciais em query string presente no `info.description`.

## Fora de escopo (YAGNI)

- Geração automática da spec a partir de anotações no código.
- Setup de servidor Swagger UI hospedado (só o arquivo YAML).
- Mudanças de código (mover credenciais para header/body, alterar respostas).
- Documentação das rotas web.
