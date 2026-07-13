# Documentação OpenAPI da API REST externa — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produzir uma spec OpenAPI 3.1 (`docs/api/openapi.yaml`) que documenta os 12 endpoints da API REST externa do ms-mni, renderizável em Swagger UI / Redoc.

**Architecture:** Um único arquivo `openapi.yaml`. Schemas, parâmetros, respostas de erro e o security scheme ficam em `components` e são referenciados via `$ref` (DRY). Endpoints agrupados por `tags`. O webhook de saída da exportação usa a seção `webhooks` do OpenAPI 3.1. Nenhuma mudança de código de aplicação — apenas documentação do comportamento atual.

**Tech Stack:** OpenAPI 3.1.0 (YAML). Validação via `@redocly/cli` (executado com `npx`). Idioma PT-BR.

## Global Constraints

- Versão da spec: `openapi: 3.1.0` — exato.
- Idioma de toda `description`/`summary`: Português (BR).
- Autenticação: header `X-API-Token` (security scheme `apiKey`), aplicado globalmente.
- Base path dos endpoints: `/api`.
- Nenhuma alteração de código-fonte da aplicação (só cria `docs/api/openapi.yaml`).
- Toda estrutura repetida (parâmetros, erros, schemas) referenciada via `$ref` — sem duplicação inline.
- Campos dos schemas derivam dos models reais (respeitando `$hidden`: campos ocultos NÃO aparecem no response).
- Comando de validação (usado ao fim de cada task): `npx --yes @redocly/cli@latest lint docs/api/openapi.yaml`. Esperado: `Woohoo! Your API description is valid.` (ou `0 errors`). Se o ambiente estiver offline e o `npx` falhar ao baixar, registrar o bloqueio e seguir — a validação final (Task 9) é o gate obrigatório.

---

### Task 1: Esqueleto da spec + info + security

**Files:**
- Create: `docs/api/openapi.yaml`

**Interfaces:**
- Consumes: nada.
- Produces: security scheme `ApiToken`; blocos `info`, `servers`, `tags`, `paths` (vazio), `components.securitySchemes`. Tasks seguintes preenchem `components.schemas`, `components.parameters`, `components.responses` e `paths`.

- [ ] **Step 1: Criar o arquivo com esqueleto**

Criar `docs/api/openapi.yaml`:

```yaml
openapi: 3.1.0
info:
  title: API MNI/PJe — ms-mni
  version: "1.0.0"
  description: |
    API REST externa do ms-mni para consulta de processos, documentos e
    tribunais via integração MNI/PJe.

    ## Autenticação
    Todas as rotas exigem o header `X-API-Token` com um token válido.

    ## Aviso de segurança
    Os endpoints `GET` recebem `login_pje` e `senha_pje` como **query params**.
    Isso expõe credenciais PJe na URL (logs de servidor, histórico de proxy).
    **Não registre em log as URLs completas destas requisições.**
servers:
  - url: https://{host}/api
    description: Servidor da aplicação
    variables:
      host:
        default: localhost:8006
security:
  - ApiToken: []
tags:
  - name: Processos
    description: Consulta e exportação de processos
  - name: Documentos
    description: Consulta e download de documentos
  - name: Tribunais
    description: Listagem de tribunais
paths: {}
components:
  securitySchemes:
    ApiToken:
      type: apiKey
      in: header
      name: X-API-Token
      description: Token de API emitido pelo ms-mni.
```

- [ ] **Step 2: Validar**

Run: `npx --yes @redocly/cli@latest lint docs/api/openapi.yaml`
Expected: `0 errors` (pode haver warnings de `paths` vazio / operações ausentes — aceitável nesta etapa).

- [ ] **Step 3: Commit**

```bash
git add docs/api/openapi.yaml
git commit -m "docs(api): esqueleto openapi 3.1 + security scheme X-API-Token"
```

---

### Task 2: Componentes reutilizáveis — parâmetros e respostas de erro

**Files:**
- Modify: `docs/api/openapi.yaml` (adicionar `components.parameters` e `components.responses`)

**Interfaces:**
- Consumes: `ApiToken` (Task 1).
- Produces: parâmetros reutilizáveis `LoginPje`, `SenhaPje`, `TribunalIdQuery`, `NumeroProcessoQuery`, `DataReferencia`, `IdDocumento`; respostas reutilizáveis `Erro401`, `Erro422`, `Erro500`, `Erro400`. Schemas de erro `Erro` e `ErroMensagem` (definidos aqui pois as respostas dependem deles).

- [ ] **Step 1: Adicionar schemas de erro em `components.schemas`**

Adicionar a chave `schemas:` sob `components:` (irmã de `securitySchemes`) com:

```yaml
  schemas:
    Erro:
      type: object
      description: Erro genérico com detalhes de origem (respostas 500).
      properties:
        error:
          type: string
          example: "Mensagem do erro"
        line:
          type: integer
          example: 49
        file:
          type: string
          example: "/app/Http/Controllers/Api/ConsultarProcessoController.php"
      required: [error]
    ErroMensagem:
      type: object
      description: Erro simples com apenas mensagem.
      properties:
        message:
          type: string
          example: "Token inválido ou não fornecido"
      required: [message]
```

- [ ] **Step 2: Adicionar `components.parameters`**

Adicionar sob `components:`:

```yaml
  parameters:
    LoginPje:
      name: login_pje
      in: query
      required: true
      description: Login do usuário no PJe. Enviado na query string (ver aviso de segurança).
      schema: { type: string }
    SenhaPje:
      name: senha_pje
      in: query
      required: true
      description: Senha do usuário no PJe. Enviada na query string (ver aviso de segurança).
      schema: { type: string }
    TribunalIdQuery:
      name: tribunal_id
      in: query
      required: false
      description: ID do tribunal. Obrigatório na prática para consultas de processo.
      schema: { type: integer }
    NumeroProcessoQuery:
      name: numero_processo
      in: query
      required: false
      description: Número do processo (será normalizado internamente).
      schema: { type: string }
    DataReferencia:
      name: data_referencia
      in: query
      required: false
      description: Data de referência para filtro incremental (movimentos/documentos).
      schema: { type: string, format: date }
    IdDocumento:
      name: id_documento
      in: query
      required: true
      description: Identificador do documento no processo.
      schema: { type: string }
```

- [ ] **Step 3: Adicionar `components.responses`**

Adicionar sob `components:`:

```yaml
  responses:
    Erro400:
      description: Requisição inválida (parâmetro obrigatório ausente).
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Erro' }
          example: { error: "Tribunal não informado" }
    Erro401:
      description: Token inválido ou não fornecido.
      content:
        application/json:
          schema: { $ref: '#/components/schemas/ErroMensagem' }
          example: { message: "Token inválido ou não fornecido" }
    Erro422:
      description: Falha de validação (ex. login_pje/senha_pje ausentes).
      content:
        application/json:
          schema:
            type: object
            properties:
              message: { type: string }
              errors:
                type: object
                additionalProperties:
                  type: array
                  items: { type: string }
          example:
            message: "The login pje field is required."
            errors: { login_pje: ["The login pje field is required."] }
    Erro500:
      description: Erro interno / exceção MNI.
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Erro' }
```

- [ ] **Step 4: Validar**

Run: `npx --yes @redocly/cli@latest lint docs/api/openapi.yaml`
Expected: `0 errors`.

- [ ] **Step 5: Commit**

```bash
git add docs/api/openapi.yaml
git commit -m "docs(api): parametros e respostas de erro reutilizaveis"
```

---

### Task 3: Schemas de domínio

**Files:**
- Modify: `docs/api/openapi.yaml` (`components.schemas`)

**Interfaces:**
- Consumes: nada de tasks anteriores além do bloco `schemas` existente.
- Produces: schemas `Tribunal`, `ProcessoParte`, `Movimento`, `Documento`, `Processo`, `PaginacaoProcessos`, `ExportacaoEnfileirada`. Endpoints (Tasks 4–8) referenciam estes via `$ref`.

Campos derivados dos models (respeitando `$hidden`).

- [ ] **Step 1: Adicionar `Tribunal`**

Adicionar em `components.schemas` (campos ocultos `login`, `password`, URLs internas, timestamps NÃO aparecem):

```yaml
    Tribunal:
      type: object
      properties:
        id: { type: integer, example: 1 }
        uuid: { type: string, format: uuid }
        nome: { type: string, example: "TJSP" }
        tipo: { type: string, example: "Tribunal de Justiça" }
        ativo: { type: boolean, example: true }
        versao_mni: { type: string, nullable: true }
        codigo_peticao_inicial: { type: string, nullable: true }
        codigo_peticao_avulsa: { type: string, nullable: true }
        codigo_certidao_inicio_fim: { type: string, nullable: true }
        codigo_seeu: { type: string, nullable: true }
        usar_codigo_documento_padrao: { type: boolean, nullable: true }
        enviar_dados_criminais: { type: boolean, nullable: true }
```

- [ ] **Step 2: Adicionar `ProcessoParte`**

```yaml
    ProcessoParte:
      type: object
      properties:
        nome: { type: string, example: "Fulano de Tal" }
        cpf_cnpj: { type: string, nullable: true, example: "12345678900" }
        polo: { type: string, nullable: true, example: "AT" }
        cep: { type: string, nullable: true }
        logradouro: { type: string, nullable: true }
        numero: { type: string, nullable: true }
        bairro: { type: string, nullable: true }
        municipio: { type: string, nullable: true }
        estado: { type: string, nullable: true }
        representantes_processual:
          type: array
          items: { type: object }
```

- [ ] **Step 3: Adicionar `Movimento`**

```yaml
    Movimento:
      type: object
      properties:
        identificador_movimento: { type: string, nullable: true }
        codigo_nacional: { type: string, nullable: true }
        complemento: { type: string, nullable: true }
        data_hora: { type: string, format: date-time, nullable: true }
        id_documento_vinculado: { type: string, nullable: true }
```

- [ ] **Step 4: Adicionar `Documento`**

```yaml
    Documento:
      type: object
      properties:
        id_documento: { type: integer, example: 12345 }
        id_documento_vinculado: { type: integer, nullable: true }
        tipo_documento: { type: string, nullable: true }
        tipo: { type: string, nullable: true, description: "Descrição do tipo, resolvida em /processo/documentos/listar." }
        descricao: { type: string, nullable: true }
        movimento: { type: string, nullable: true }
        data_hora: { type: string, format: date-time, nullable: true }
        data_juntada: { type: string, format: date-time, nullable: true }
        usuario_juntada_arquivo: { type: string, nullable: true }
        mimetype: { type: string, nullable: true }
        hash: { type: string, nullable: true }
        nivel_sigilo: { type: integer, nullable: true }
        status: { type: string, enum: [baixado, pendente, erro], nullable: true }
        file_size: { type: integer, nullable: true }
        link:
          type: string
          format: uri
          nullable: true
          description: "URL temporária S3 (validade 60 min), presente apenas em /documento/visualizar."
```

- [ ] **Step 5: Adicionar `Processo`**

```yaml
    Processo:
      type: object
      description: |
        Processo com relações. As relações presentes variam por endpoint.
        Campos ocultos do model (id, tribunal_id, payload_envio, etc.) não aparecem.
      properties:
        numero_processo: { type: string }
        jurisdicao_codigo: { type: string, nullable: true }
        classe_codigo: { type: string, nullable: true }
        assunto_codigo: { type: string, nullable: true }
        competencia_codigo: { type: string, nullable: true }
        valor_causa: { type: string, nullable: true }
        nivel_sigilo: { type: integer, nullable: true }
        status: { type: string, nullable: true }
        justica_gratuita: { type: boolean, nullable: true }
        pedido_liminar: { type: boolean, nullable: true }
        nome_orgao_julgador: { type: string, nullable: true }
        codigo_orgao_julgador: { type: string, nullable: true }
        instancia_orgao_julgador: { type: string, nullable: true }
        tribunal: { $ref: '#/components/schemas/Tribunal' }
        classe: { type: object, nullable: true }
        assuntos: { type: array, items: { type: object } }
        prioridades: { type: array, items: { type: object } }
        partes:
          type: array
          items: { $ref: '#/components/schemas/ProcessoParte' }
        movimentos:
          type: array
          items: { $ref: '#/components/schemas/Movimento' }
        documentos:
          type: array
          items: { $ref: '#/components/schemas/Documento' }
```

- [ ] **Step 6: Adicionar `PaginacaoProcessos` e `ExportacaoEnfileirada`**

```yaml
    PaginacaoProcessos:
      type: object
      description: Envelope padrão do paginate() do Laravel.
      properties:
        current_page: { type: integer, example: 1 }
        data:
          type: array
          items: { $ref: '#/components/schemas/Processo' }
        first_page_url: { type: string }
        from: { type: integer, nullable: true }
        last_page: { type: integer, example: 1 }
        last_page_url: { type: string }
        next_page_url: { type: string, nullable: true }
        path: { type: string }
        per_page: { type: integer, example: 10 }
        prev_page_url: { type: string, nullable: true }
        to: { type: integer, nullable: true }
        total: { type: integer, example: 1 }
    ExportacaoEnfileirada:
      type: object
      properties:
        message: { type: string, example: "Exportação enfileirada" }
        exportacao_id: { type: integer, example: 42 }
```

- [ ] **Step 7: Validar**

Run: `npx --yes @redocly/cli@latest lint docs/api/openapi.yaml`
Expected: `0 errors`.

- [ ] **Step 8: Commit**

```bash
git add docs/api/openapi.yaml
git commit -m "docs(api): schemas de dominio (Processo, Tribunal, Movimento, Documento)"
```

---

### Task 4: Endpoints de Tribunais

**Files:**
- Modify: `docs/api/openapi.yaml` (`paths`)

**Interfaces:**
- Consumes: `Tribunal` (Task 3), `Erro401` (Task 2).
- Produces: paths `/tribunais` e `/tribunais/{uuid}`.

- [ ] **Step 1: Adicionar os dois paths**

Substituir `paths: {}` por `paths:` e adicionar:

```yaml
  /tribunais:
    get:
      tags: [Tribunais]
      summary: Listar tribunais ativos
      description: Retorna todos os tribunais com `ativo = true`.
      responses:
        '200':
          description: Lista de tribunais.
          content:
            application/json:
              schema:
                type: array
                items: { $ref: '#/components/schemas/Tribunal' }
        '401': { $ref: '#/components/responses/Erro401' }
  /tribunais/{uuid}:
    get:
      tags: [Tribunais]
      summary: Obter tribunal por uuid
      parameters:
        - name: uuid
          in: path
          required: true
          schema: { type: string, format: uuid }
      responses:
        '200':
          description: Tribunal encontrado (ou `null` se não existir).
          content:
            application/json:
              schema:
                oneOf:
                  - { $ref: '#/components/schemas/Tribunal' }
                  - { type: 'null' }
        '401': { $ref: '#/components/responses/Erro401' }
```

- [ ] **Step 2: Validar**

Run: `npx --yes @redocly/cli@latest lint docs/api/openapi.yaml`
Expected: `0 errors`.

- [ ] **Step 3: Commit**

```bash
git add docs/api/openapi.yaml
git commit -m "docs(api): endpoints de tribunais"
```

---

### Task 5: Endpoints de consulta de processo (consultar, visualizar, dados-basicos)

**Files:**
- Modify: `docs/api/openapi.yaml` (`paths`)

**Interfaces:**
- Consumes: parâmetros `LoginPje`/`SenhaPje`/`TribunalIdQuery`/`NumeroProcessoQuery` (Task 2), schemas `Processo`/`PaginacaoProcessos` (Task 3), respostas `Erro400`/`Erro401`/`Erro422`/`Erro500`.
- Produces: paths `/processo/consultar`, `/processo/visualizar`, `/processo/dados-basicos`.

- [ ] **Step 1: Adicionar `/processo/consultar`**

Sob `paths:`:

```yaml
  /processo/consultar:
    get:
      tags: [Processos]
      summary: Buscar processos por número ou nome de parte
      description: |
        Busca paginada (10 por página). Informe `numero_processo` **ou**
        `nomeParte`. Na busca por número: se o processo não existe no banco,
        é baixado do MNI de forma síncrona; se existe, uma atualização é
        disparada em background.
      parameters:
        - { $ref: '#/components/parameters/LoginPje' }
        - { $ref: '#/components/parameters/SenhaPje' }
        - { $ref: '#/components/parameters/TribunalIdQuery' }
        - { $ref: '#/components/parameters/NumeroProcessoQuery' }
        - name: nomeParte
          in: query
          required: false
          schema: { type: string }
      responses:
        '200':
          description: Página de processos.
          content:
            application/json:
              schema: { $ref: '#/components/schemas/PaginacaoProcessos' }
        '400': { $ref: '#/components/responses/Erro400' }
        '401': { $ref: '#/components/responses/Erro401' }
        '422': { $ref: '#/components/responses/Erro422' }
        '500': { $ref: '#/components/responses/Erro500' }
```

- [ ] **Step 2: Adicionar `/processo/visualizar`**

```yaml
  /processo/visualizar:
    get:
      tags: [Processos]
      summary: Obter processo completo
      description: |
        Retorna um processo com relações completas (tribunal, partes e
        representantes, prioridades, classe, assuntos, movimentos, documentos).
        Baixa do MNI se ausente; senão dispara atualização em background.
      parameters:
        - { $ref: '#/components/parameters/LoginPje' }
        - { $ref: '#/components/parameters/SenhaPje' }
        - { $ref: '#/components/parameters/TribunalIdQuery' }
        - { $ref: '#/components/parameters/NumeroProcessoQuery' }
      responses:
        '200':
          description: Processo encontrado.
          content:
            application/json:
              schema: { $ref: '#/components/schemas/Processo' }
        '400': { $ref: '#/components/responses/Erro400' }
        '401': { $ref: '#/components/responses/Erro401' }
        '422': { $ref: '#/components/responses/Erro422' }
        '500': { $ref: '#/components/responses/Erro500' }
```

- [ ] **Step 3: Adicionar `/processo/dados-basicos`**

```yaml
  /processo/dados-basicos:
    get:
      tags: [Processos]
      summary: Dados básicos do processo (síncrono)
      description: |
        Retorna dados básicos (tribunal, classe, assuntos, prioridades, partes
        e representantes). Se existe no banco, retorna direto; senão consulta o
        MNI de forma síncrona.
      parameters:
        - { $ref: '#/components/parameters/LoginPje' }
        - { $ref: '#/components/parameters/SenhaPje' }
        - { $ref: '#/components/parameters/TribunalIdQuery' }
        - { $ref: '#/components/parameters/NumeroProcessoQuery' }
      responses:
        '200':
          description: Dados básicos do processo.
          content:
            application/json:
              schema: { $ref: '#/components/schemas/Processo' }
        '401': { $ref: '#/components/responses/Erro401' }
        '422': { $ref: '#/components/responses/Erro422' }
        '500': { $ref: '#/components/responses/Erro500' }
```

- [ ] **Step 4: Validar**

Run: `npx --yes @redocly/cli@latest lint docs/api/openapi.yaml`
Expected: `0 errors`.

- [ ] **Step 5: Commit**

```bash
git add docs/api/openapi.yaml
git commit -m "docs(api): endpoints de consulta de processo"
```

---

### Task 6: Endpoints de movimentos/documentos (listar síncrono + async)

**Files:**
- Modify: `docs/api/openapi.yaml` (`paths`)

**Interfaces:**
- Consumes: parâmetros comuns + `DataReferencia`; schemas `Movimento`, `Documento`; respostas de erro.
- Produces: paths `/processo/movimentos/listar`, `/processo/documentos/listar`, `/processo/consultar/dados-basicos/async`, `/processo/consultar/movimentos/async`, `/processo/consultar/documentos/async`.

- [ ] **Step 1: Adicionar os dois endpoints síncronos**

```yaml
  /processo/movimentos/listar:
    get:
      tags: [Processos]
      summary: Listar movimentos (síncrono)
      description: Retorna do banco se houver movimentos; senão consulta o MNI.
      parameters:
        - { $ref: '#/components/parameters/LoginPje' }
        - { $ref: '#/components/parameters/SenhaPje' }
        - { $ref: '#/components/parameters/TribunalIdQuery' }
        - { $ref: '#/components/parameters/NumeroProcessoQuery' }
        - { $ref: '#/components/parameters/DataReferencia' }
      responses:
        '200':
          description: Lista de movimentos.
          content:
            application/json:
              schema:
                type: array
                items: { $ref: '#/components/schemas/Movimento' }
        '401': { $ref: '#/components/responses/Erro401' }
        '422': { $ref: '#/components/responses/Erro422' }
        '500': { $ref: '#/components/responses/Erro500' }
  /processo/documentos/listar:
    get:
      tags: [Documentos]
      summary: Listar documentos (síncrono)
      description: Retorna do banco se houver documentos; senão consulta o MNI. O campo `tipo` é resolvido.
      parameters:
        - { $ref: '#/components/parameters/LoginPje' }
        - { $ref: '#/components/parameters/SenhaPje' }
        - { $ref: '#/components/parameters/TribunalIdQuery' }
        - { $ref: '#/components/parameters/NumeroProcessoQuery' }
        - { $ref: '#/components/parameters/DataReferencia' }
      responses:
        '200':
          description: Lista de documentos.
          content:
            application/json:
              schema:
                type: array
                items: { $ref: '#/components/schemas/Documento' }
        '401': { $ref: '#/components/responses/Erro401' }
        '422': { $ref: '#/components/responses/Erro422' }
        '500': { $ref: '#/components/responses/Erro500' }
```

- [ ] **Step 2: Adicionar os três endpoints async**

Todos enfileiram um job (fila `alta`) e retornam **200 com corpo vazio**.

```yaml
  /processo/consultar/dados-basicos/async:
    get:
      tags: [Processos]
      summary: Enfileirar consulta de dados básicos (assíncrono)
      description: Enfileira `ConsultarDadosBasicosProcessoMNIJob` na fila `alta`. Retorna 200 com corpo vazio.
      parameters:
        - { $ref: '#/components/parameters/LoginPje' }
        - { $ref: '#/components/parameters/SenhaPje' }
        - { $ref: '#/components/parameters/TribunalIdQuery' }
        - { $ref: '#/components/parameters/NumeroProcessoQuery' }
      responses:
        '200': { description: Job enfileirado. Corpo vazio. }
        '401': { $ref: '#/components/responses/Erro401' }
        '422': { $ref: '#/components/responses/Erro422' }
  /processo/consultar/movimentos/async:
    get:
      tags: [Processos]
      summary: Enfileirar consulta de movimentos (assíncrono)
      description: Enfileira `ConsultarMovimentosProcessoMNIJob` na fila `alta`. Retorna 200 com corpo vazio.
      parameters:
        - { $ref: '#/components/parameters/LoginPje' }
        - { $ref: '#/components/parameters/SenhaPje' }
        - { $ref: '#/components/parameters/TribunalIdQuery' }
        - { $ref: '#/components/parameters/NumeroProcessoQuery' }
      responses:
        '200': { description: Job enfileirado. Corpo vazio. }
        '401': { $ref: '#/components/responses/Erro401' }
        '422': { $ref: '#/components/responses/Erro422' }
  /processo/consultar/documentos/async:
    get:
      tags: [Documentos]
      summary: Enfileirar consulta de documentos (assíncrono)
      description: Enfileira `ConsultarDocumentosProcessoMNIJob` na fila `alta`. Retorna 200 com corpo vazio.
      parameters:
        - { $ref: '#/components/parameters/LoginPje' }
        - { $ref: '#/components/parameters/SenhaPje' }
        - { $ref: '#/components/parameters/TribunalIdQuery' }
        - { $ref: '#/components/parameters/NumeroProcessoQuery' }
      responses:
        '200': { description: Job enfileirado. Corpo vazio. }
        '401': { $ref: '#/components/responses/Erro401' }
        '422': { $ref: '#/components/responses/Erro422' }
```

- [ ] **Step 3: Validar**

Run: `npx --yes @redocly/cli@latest lint docs/api/openapi.yaml`
Expected: `0 errors`.

- [ ] **Step 4: Commit**

```bash
git add docs/api/openapi.yaml
git commit -m "docs(api): endpoints de movimentos/documentos (sincrono e async)"
```

---

### Task 7: Endpoint de visualização de documento

**Files:**
- Modify: `docs/api/openapi.yaml` (`paths`)

**Interfaces:**
- Consumes: parâmetros comuns + `IdDocumento`; schema `Documento`; `ErroMensagem`; respostas de erro.
- Produces: path `/documento/visualizar`.

- [ ] **Step 1: Adicionar `/documento/visualizar`**

```yaml
  /documento/visualizar:
    get:
      tags: [Documentos]
      summary: Baixar documento e obter link temporário
      description: |
        Baixa o documento (até 3 tentativas) e retorna um link temporário do
        S3, válido por 60 minutos, em `documento.link`.
      parameters:
        - { $ref: '#/components/parameters/LoginPje' }
        - { $ref: '#/components/parameters/SenhaPje' }
        - { $ref: '#/components/parameters/TribunalIdQuery' }
        - { $ref: '#/components/parameters/NumeroProcessoQuery' }
        - { $ref: '#/components/parameters/IdDocumento' }
      responses:
        '200':
          description: Documento consultado com sucesso.
          content:
            application/json:
              schema:
                type: object
                properties:
                  message: { type: string, example: "Documento 12345 consultado com sucesso" }
                  documento: { $ref: '#/components/schemas/Documento' }
        '401': { $ref: '#/components/responses/Erro401' }
        '404':
          description: Documento não obtido após as tentativas.
          content:
            application/json:
              schema: { $ref: '#/components/schemas/ErroMensagem' }
              example: { message: "Não foi possível obter o documento 12345 do processo ... após 3 tentativas" }
        '422': { $ref: '#/components/responses/Erro422' }
        '500':
          description: Erro interno.
          content:
            application/json:
              schema: { $ref: '#/components/schemas/ErroMensagem' }
```

- [ ] **Step 2: Validar**

Run: `npx --yes @redocly/cli@latest lint docs/api/openapi.yaml`
Expected: `0 errors`.

- [ ] **Step 3: Commit**

```bash
git add docs/api/openapi.yaml
git commit -m "docs(api): endpoint de visualizacao de documento"
```

---

### Task 8: Endpoint de download (POST) + webhook de saída

**Files:**
- Modify: `docs/api/openapi.yaml` (`paths` e nova seção `webhooks`)

**Interfaces:**
- Consumes: `ExportacaoEnfileirada`, `Erro` (para 404), respostas de erro.
- Produces: path `/processo/download`; seção top-level `webhooks.download`.

- [ ] **Step 1: Adicionar `/processo/download`**

Sob `paths:`:

```yaml
  /processo/download:
    post:
      tags: [Processos]
      summary: Exportar processo em PDF (assíncrono)
      description: |
        Enfileira a geração de um PDF de exportação. O arquivo pronto é
        entregue via webhook de saída (ver seção `webhooks`). Retorna
        imediatamente o `exportacao_id`.
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required: [numero_processo, user_id, titulo, formato]
              properties:
                numero_processo: { type: string, maxLength: 25 }
                tribunal_id: { type: integer, nullable: true }
                user_id: { type: integer, minimum: 1, description: "Eco no webhook." }
                titulo: { type: string, maxLength: 255, description: "Eco no webhook." }
                formato: { type: string, enum: [pdf] }
                ids_selecionados:
                  type: array
                  items: { type: integer }
                periodo_inicial: { type: string, format: date, description: "required_with periodo_final" }
                periodo_final: { type: string, format: date, description: "required_with periodo_inicial" }
                id_inicial: { type: integer, description: "required_with id_final" }
                id_final: { type: integer, description: "required_with id_inicial" }
      responses:
        '200':
          description: Exportação enfileirada.
          content:
            application/json:
              schema: { $ref: '#/components/schemas/ExportacaoEnfileirada' }
        '401': { $ref: '#/components/responses/Erro401' }
        '404':
          description: Nenhum documento encontrado com os filtros aplicados.
          content:
            application/json:
              schema: { $ref: '#/components/schemas/Erro' }
              example: { error: "Nenhum documento encontrado para o processo informado com os filtros aplicados." }
        '422': { $ref: '#/components/responses/Erro422' }
```

- [ ] **Step 2: Adicionar seção `webhooks` (top-level, irmã de `paths`)**

Confirmar os campos exatos do payload em `docs/superpowers/specs/2026-04-29-webhook-download-exportacao-design.md` antes de finalizar.

```yaml
webhooks:
  download:
    post:
      summary: Notificação de conclusão da exportação (ms-mni → cliente SIM)
      description: |
        O ms-mni faz `POST {SIM_URL}/webhook/download` ao concluir (ou falhar) a
        geração do PDF. O cliente SIM implementa este endpoint. Payload tipado por
        status.
      requestBody:
        content:
          application/json:
            schema:
              type: object
              required: [status, user_id, titulo]
              properties:
                status: { type: string, enum: [concluido, falhou] }
                user_id: { type: integer }
                titulo: { type: string }
                exportacao_id: { type: integer }
                arquivo_url:
                  type: string
                  format: uri
                  nullable: true
                  description: "Presente em status=concluido. PDF em downloads/{user_id}/{uuid}.pdf."
                erro:
                  type: string
                  nullable: true
                  description: "Presente em status=falhou."
      responses:
        '200': { description: Webhook recebido pelo cliente. }
```

- [ ] **Step 3: Validar**

Run: `npx --yes @redocly/cli@latest lint docs/api/openapi.yaml`
Expected: `0 errors`.

- [ ] **Step 4: Commit**

```bash
git add docs/api/openapi.yaml
git commit -m "docs(api): endpoint de download e webhook de exportacao"
```

---

### Task 9: Validação final + render + ponteiro no README

**Files:**
- Modify: `docs/api/openapi.yaml` (apenas se a validação achar problemas)
- Modify: `README.md` (adicionar link para a doc)

**Interfaces:**
- Consumes: spec completa.
- Produces: confirmação de que os 12 endpoints estão presentes e a spec renderiza.

- [ ] **Step 1: Validação final (gate obrigatório)**

Run: `npx --yes @redocly/cli@latest lint docs/api/openapi.yaml`
Expected: `Woohoo! Your API description is valid.` / `0 errors`.

- [ ] **Step 2: Conferir contagem de endpoints**

Run: `grep -cE "^  /" docs/api/openapi.yaml`
Expected: `11` (11 paths — `/processo/consultar` até `/tribunais/{uuid}`; o 12º "endpoint" é o webhook, contado à parte).
Conferir também: `grep -c "get:\|post:" docs/api/openapi.yaml` retorna ao menos `12` operações (11 endpoints REST + 1 webhook).

Se a contagem divergir, revisar quais paths faltam contra a tabela do spec (`docs/superpowers/specs/2026-07-13-openapi-documentacao-api-design.md`) e adicionar.

- [ ] **Step 3: Render (Swagger UI / Redoc)**

Run: `npx --yes @redocly/cli@latest build-docs docs/api/openapi.yaml -o docs/api/index.html`
Expected: gera `docs/api/index.html` sem erro. Abrir no navegador e confirmar que os 12 itens aparecem agrupados por tag. (O `index.html` é artefato de verificação; não precisa ser commitado.)

- [ ] **Step 4: Adicionar ponteiro no README**

Adicionar uma linha na seção apropriada do `README.md`:

```markdown
## Documentação da API

A API REST externa está documentada em [`docs/api/openapi.yaml`](docs/api/openapi.yaml) (OpenAPI 3.1). Renderize com `npx @redocly/cli preview-docs docs/api/openapi.yaml`.
```

- [ ] **Step 5: Commit**

```bash
git add docs/api/openapi.yaml README.md
git commit -m "docs(api): ponteiro no README e validacao final da spec openapi"
```

---

## Notas de execução

- Todas as tasks editam o mesmo arquivo `docs/api/openapi.yaml` — executar em ordem.
- O bloco `components` é montado incrementalmente (Tasks 1–3); ao adicionar novas chaves, colocá-las como irmãs das existentes sob `components:`, sem duplicar a chave `components:`.
- `paths` começa vazio (`{}`) na Task 1 e vira mapa a partir da Task 4 — substituir `paths: {}` por `paths:` na primeira adição.
- Indentação YAML é significativa: os exemplos de path acima assumem 2 espaços sob `paths:`.
