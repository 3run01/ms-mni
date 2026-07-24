# Design: Métricas na Dashboard Inicial

**Data:** 2026-07-22
**Status:** Aprovado

## Objetivo

Substituir o placeholder da página `/dashboard` por métricas reais: cards de totais e dois gráficos de série temporal — processos baixados por dia e documentos baixados por dia — com seletor de período (7/30/90 dias).

## Contexto

- Frontend: Inertia.js v2 + React 19 + TypeScript + TailwindCSS 4 + shadcn/Radix.
- `resources/js/pages/dashboard.tsx` hoje é placeholder puro; rota `/dashboard` é closure inline em `routes/web.php`, sem controller.
- Nenhuma lib de gráfico instalada.
- Processos baixados: tabela `processos`, coluna `created_at` (linha criada quando o processo é consultado/baixado do tribunal).
- Documentos baixados: tabela `processo_documentos`, `status = 'baixado'`. Não existe coluna com o momento do download — será criada (`downloaded_at`).

## Decisões

| Decisão | Escolha |
|---|---|
| Período | Seletor 7/30/90 dias, padrão 30 |
| Momento do download de documento | Nova coluna `downloaded_at` (backfill com `updated_at`) |
| Lib de gráfico | Recharts via componente chart do shadcn |
| Escopo | Cards de totais + 2 gráficos |
| Carregamento | Controller dedicado + `Inertia::defer` (deferred props) |

## 1. Dados — migration `downloaded_at`

- Migration adiciona `downloaded_at TIMESTAMP NULL` em `processo_documentos`, com índice.
- Backfill na própria migration: `UPDATE processo_documentos SET downloaded_at = updated_at WHERE status = 'baixado'`. Aproximação assumida para registros históricos (qualquer update posterior ao download distorce; aceito).
- `App\Services\Processo\SalvarDocumentoProcessoService`: nos três pontos que setam `status = 'baixado'` (linhas ~124, ~464, ~591), setar também `downloaded_at = now()`.
- Model `ProcessoDocumento`: adicionar cast `downloaded_at => datetime`.

## 2. Backend — `DashboardController`

- Nova classe `App\Http\Controllers\DashboardController`, método `index`, substitui a closure em `routes/web.php` (mantém `->name('dashboard')` e middlewares atuais).
- Query param `periodo` ∈ {7, 30, 90}; valor inválido ou ausente → 30.
- Props Inertia:
  - `periodo` (int) — prop direta.
  - `metricas` — `Inertia::defer(...)` retornando:
    - `totais`:
      - `processos`: `COUNT(*)` de `processos` com `created_at >=` início do período.
      - `documentosBaixados`: `COUNT(*)` de `processo_documentos` com `status='baixado'` e `downloaded_at >=` início do período.
      - `documentosPendentes`: `COUNT(*)` com `status='pendente'` (estado atual, sem filtro de período).
      - `documentosErro`: `COUNT(*)` com `status='erro'` (estado atual, sem filtro de período).
    - `processosPorDia`: `[{ dia: 'YYYY-MM-DD', total: n }]` — `GROUP BY DATE(created_at)`.
    - `documentosPorDia`: mesmo shape — `GROUP BY DATE(downloaded_at)` com `status='baixado'`.
- Dias sem registro preenchidos com zero no PHP — série contínua do início do período até hoje.
- Timezone `America/Sao_Paulo` para corte de período e agrupamento por dia (padrão já usado no model `Processo`).

## 3. Frontend — `dashboard.tsx`

- Adicionar componente chart do shadcn (`npx shadcn@latest add chart`; traz Recharts). Se o CLI falhar por permissão de diretório (problema conhecido no ambiente), criar `resources/js/components/ui/chart.tsx` manualmente e instalar `recharts` via npm.
- Layout da página:
  - Topo: seletor de período — grupo de 3 botões toggle (7/30/90 dias). Troca dispara `router.reload({ data: { periodo } })` (visita parcial Inertia).
  - Linha de 4 cards: processos no período, documentos baixados no período, pendentes, erros.
  - Dois gráficos (área ou barra, tokens de cor do tema shadcn): processos por dia, documentos por dia.
- `<Deferred>` do Inertia v2 com skeleton enquanto `metricas` carrega.
- Tipos TypeScript para as props (`Metricas`, `PontoSerie` etc).

## 4. Erros e casos-borda

- `periodo` inválido → fallback 30, sem erro.
- Período sem dados → série zerada renderiza normalmente; cards mostram 0.
- Documentos `baixado` sem `downloaded_at` (não deve ocorrer pós-backfill) → fora do gráfico; contagem de card usa `downloaded_at`.

## 5. Testes

- Feature test do `DashboardController`: shape das props, fallback de período inválido, contagens corretas com registros criados no teste (dentro e fora do período), série contínua com zeros.
- Test do `SalvarDocumentoProcessoService` (ou unit no ponto de salvamento): marcar documento como `baixado` preenche `downloaded_at`.
- Migration backfill coberta implicitamente (rodar migrations no ambiente de teste).

## Fora de escopo

- Métricas de exportações de PDF (`processo_exportacoes`) — possível evolução futura.
- Date range picker livre.
- Refresh automático/polling.
