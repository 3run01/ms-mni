# Processos — listagem com filtros e tela de detalhe (read-only)

**Data:** 2026-07-10
**Status:** aprovado em brainstorming

## Objetivo

Como usuário autenticado, quero listar e filtrar processos e, em seguida, visualizar todos os dados de um processo (dados gerais, partes, movimentos, documentos, assuntos). Uso duplo: monitoramento operacional (status de envio) e consulta completa.

Escopo desta iteração: **somente leitura**. Sem download de documentos, sem reenvio, sem edição. Ações ficam para iterações futuras.

## Contexto

- Stack: Laravel + Inertia v2 + React + shadcn/ui. Padrão de referência: CRUD de tribunais (`TribunalController`, `resources/js/pages/tribunais/*`).
- Tabela `processos` tem **24.489 linhas** no banco local → paginação server-side obrigatória.
- Status reais no banco: `Peticionado` (24.125) e `Arquivado` (364). O model define 4 constantes: `Pendente de envio`, `Processando envio`, `Peticionado`, `Arquivado`. `Arquivado` existe no banco mas está fora de `Processo::getStatus()` — o filtro da tela deve incluir os 4.
- Model `Processo` tem `$with` default (tribunal, prioridades, classe, assuntos) e `$hidden` (id, payload_envio, etc.). Atenção: `id` está em `$hidden` — a listagem/detalhe expõe `id` via `makeVisible('id')` no controller (não alterar o `$hidden` do model, usado pela API existente).
- Campo `unidade_id` está em `$fillable` mas **não existe** na tabela — não usar.
- Relação `tribunal()` do model filtra `ativo = true` — processo de tribunal inativo renderiza tribunal como nulo; tratar com "—".

## Rotas

Grupo `auth:web` em `routes/web.php`, junto às rotas de tribunais:

| Método | URI | Action | Nome |
| --- | --- | --- | --- |
| GET | `/processos` | `ProcessoController@index` | `processos.index` |
| GET | `/processos/{processo}` | `ProcessoController@show` | `processos.show` |

Controller novo: `App\Http\Controllers\ProcessoController` (o existente em `Api\` não é tocado).

## Backend

### `index`

Filtros via query string, todos opcionais e combináveis:

| Parâmetro | Comportamento |
| --- | --- |
| `busca` | `numero_processo LIKE %valor%` |
| `tribunal_id` | igualdade |
| `status` | igualdade (um dos 4 status) |
| `data_inicio` | `created_at >=` (date) |
| `data_fim` | `created_at <=` (fim do dia) |
| `classe_codigo` | igualdade (opções de `classes_cnj`) |
| `orgao_julgador` | `nome_orgao_julgador LIKE %valor%` |
| `nivel_sigilo` | igualdade (0–5) |

- Validação leve inline no controller (sem FormRequest): tipos, `status` dentro do enum, `data_inicio <= data_fim` (mensagem de validação se invertido).
- Paginação server-side: 20/página, `->withQueryString()`, ordenação `created_at DESC`.
- Eager load na lista: apenas `tribunal` e `classe` (usar `Processo::without(['prioridades','assuntos'])` para desligar o `$with` default — 20 linhas não precisam de prioridades/assuntos).
- Props Inertia: `processos` (paginator), `filtros` (valores ativos ecoados), `tribunais` (id+sigla+nome, para o Select), `classes` (codigo+nome, para o Combobox), `statusOptions`, `niveisSigilo`.

### `show`

- Route model binding; 404 padrão para id inexistente.
- Carrega: tribunal, classe, assuntos, prioridades, partes (com `representantes`), movimentos, documentos.
- `movimentos` e `documentos` como **deferred props** do Inertia v2 (`Inertia::defer`) — processos antigos podem ter centenas; primeiro paint não espera por eles.
- **Excluir do payload de documentos:** `conteudo_html` (pesado) e do processo: `payload_envio` (já em `$hidden`; garantir que permanece oculto). Selecionar colunas explícitas nos documentos em vez de `*`.

## Frontend

### Sidebar

Novo item "Processos" em `nav-main` (ícone `Scale` do lucide), imediatamente antes de Tribunais.

### Listagem — `resources/js/pages/processos/index.tsx`

Layout `AppLayout` + breadcrumb "Processos", padrão visual das telas de tribunais.

**Barra de filtros:**

- Linha 1: busca por número (Input com debounce 400ms), Tribunal (Select), Status (Select), Classe CNJ (Combobox com busca — centenas de opções)
- Linha 2, colapsável ("Mais filtros"): Data início / Data fim (inputs date), Órgão julgador (Input), Nível de sigilo (Select)
- Botão "Limpar filtros" visível quando qualquer filtro ativo
- Aplicação via `router.get` com partial reload (`only: ['processos']`), `preserveState` + `preserveScroll`. URL sempre reflete filtros (compartilhável).

**Tabela** (shadcn `Table`):

- Colunas: Número do processo, Tribunal (sigla), Classe, Status (Badge: verde Peticionado, amarelo Processando envio, vermelho Pendente de envio, cinza Arquivado), Valor da causa (R$ formatado), Criado em (data local)
- Linha inteira clicável → `processos.show`
- Estado vazio: mensagem + hint para limpar filtros

**Paginação:** links do paginator Laravel, indicador "X–Y de Z".

### Detalhe — `resources/js/pages/processos/show.tsx`

Breadcrumb: Processos → {número}. Voltar via history (preserva filtros da listagem).

**Cabeçalho** (Card): número do processo (título + botão copiar), Badge de status, tribunal, classe CNJ, órgão julgador (nome + instância), valor da causa, nível de sigilo (label de `niveisSigilo()`), badges "Justiça gratuita" / "Pedido liminar" quando true, motivo segredo de justiça quando presente, data de criação. Assuntos e prioridades como badges no cabeçalho.

**Abas** (shadcn `Tabs`):

1. **Partes** — agrupadas por polo (Ativo / Passivo / demais). Por parte: nome, CPF/CNPJ formatado, endereço concatenado; representantes aninhados sob a parte.
2. **Movimentos** — deferred, skeleton enquanto carrega. Lista cronológica desc: data/hora, código nacional, complemento, indicador quando há documento vinculado.
3. **Documentos** — deferred, skeleton enquanto carrega. Tabela: descrição, tipo, mimetype, tamanho (KB/MB), sigilo, data juntada, status. **Sem ação de download.**

## Erros e edge cases

- Processo inexistente → 404.
- Campos nulos (órgão julgador, valor, tribunal inativo, datas) → "—".
- Zero partes/movimentos/documentos → estado vazio por aba.
- `data_inicio > data_fim` → erro de validação exibido no filtro.

## Testes (Pest, padrão dos testes de tribunais)

**index:**

- redireciona guest para login
- retorna página com paginator (20/página)
- um teste por filtro (busca, tribunal, status, datas, classe, órgão julgador, sigilo)
- filtros combinados
- paginação preserva query string

**show:**

- redireciona guest para login
- 404 para id inexistente
- retorna dados gerais + partes com representantes + assuntos
- deferred props de movimentos/documentos carregam no partial reload
- `conteudo_html` e `payload_envio` ausentes do payload

## Fora de escopo

- Download de documentos
- Reenvio de processos / ações operacionais
- Exportações (`processo_exportacoes`)
- Filtro por partes (nome/CPF)
- Qualquer escrita
