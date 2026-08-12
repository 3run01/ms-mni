# Monitoramento periódico de processo via API

**Data:** 2026-08-12
**Tipo:** Feature nova
**Serviço:** ms-mni
**Escopo:** Recurso `monitoramentos` (CRUD + agendador + webhook) e tabela
separada de credenciais PJe cifradas. Não inclui UI no dashboard.

## Contexto

Hoje o ms-mni consulta um processo **sob demanda**: o cliente chama
`/processo/consultar/*/async`, o job roda uma vez na fila `alta` e notifica o
`callback_url` informado no payload (`CallbackNotifier`). Não existe nada
recorrente — para acompanhar um processo, o cliente precisa chamar a API de
tempos em tempos por conta própria.

A demanda é inverter isso: o cliente **assina** o monitoramento de um processo
informando um intervalo em horas; o ms-mni consulta o MNI periodicamente,
atualiza o processo no banco quando houver movimento ou documento novo, e
dispara um webhook para a URL informada a cada execução assíncrona.

O que já existe e é reaproveitado sem alteração:

| Peça | Arquivo | Papel no monitoramento |
|---|---|---|
| `CallbackNotifier` | `app/Services/Callback/CallbackNotifier.php` | POST do webhook, 4xx permanente / 5xx retry |
| `CallbackUrlValidator` + `Rules\CallbackUrl` | `app/Services/Callback/`, `app/Rules/` | anti-SSRF (https, bloqueia IP interno) |
| `ProcessoService::consultarNumero()` | `app/Services/Processo/ProcessoService.php:26` | 1 chamada SOAP trazendo básicos + movimentos + documentos |
| `updateOrCreate` por `identificador_movimento` / `id_documento` | `SalvarMovimentoProcessoService`, `SalvarDocumentoProcessoService` | idempotência da atualização |
| Padrão de retry de webhook | `EnviarWebhookDownloadJob` + `processo_exportacoes` | modelo copiado para as execuções |
| `ValidateApiToken` / `ApiToken` | `app/Http/Middleware/`, `app/Models/` | dono do monitoramento é o `api_token_id` |

## Decisões

- **Webhook em toda execução.** Cada execução agendada dispara o webhook, com
  `houve_alteracao: true|false`. O cliente filtra do lado dele. Execução que
  falhou também notifica (`status: "falha"`), para o cliente não ficar no escuro.
- **Credenciais PJe em tabela separada e cifrada.** Nova tabela `credenciais_pje`
  com `login`/`senha` sob cast `encrypted` (APP_KEY). O monitoramento referencia
  `credencial_id`; `null` significa "usar o par padrão do `.env`"
  (`config('pje.credenciais_padrao')`, mesmo par que o `InjectCredenciaisPjePadrao`
  injeta hoje) e, na ausência dele, o fallback das credenciais do tribunal na
  camada de service. Credenciais **nunca** voltam na API — só `login_mascarado`.
- **Escopo da consulta: básicos + movimentos + documentos (metadados).** Uma
  chamada `consultarNumero`, sem baixar binário de documento. Isso exige mudar
  `SalvarDocumentoProcessoService`, que hoje despacha `BaixarDocumentoMNIJob`
  para todo documento não baixado (`SalvarDocumentoProcessoService.php:57`) —
  ver "Mudança de comportamento" abaixo.
- **Payload do webhook: resumo + deltas.** Contadores e a lista dos movimentos e
  documentos novos (metadados), auto-suficiente, sem exigir round-trip.
- **Agendamento por polling de banco, não por `delay()` encadeado.** Coluna
  `proxima_execucao_em` + comando `monitoramentos:despachar` a cada minuto.
  Sobrevive a restart de worker, permite alterar intervalo sem cancelar job
  pendente, e o estado é inspecionável em SQL.
- **Fila dedicada `monitoramento`.** Não compete com `alta` (consultas
  interativas) nem com `mni-download`.
- **Isolamento por token.** Toda leitura/escrita é filtrada por `api_token_id`
  do header `X-API-Token`. Um token não enxerga nem altera monitoramento de
  outro. É a primeira noção de tenancy do serviço.
- **Intervalo em horas inteiras, 1 a 720** (1h a 30 dias), com jitter para
  espalhar a carga no tribunal.
- **Um monitoramento ativo por (token, tribunal, processo).** Recriar retorna
  `409` com o uuid do existente, em vez de duplicar consultas ao tribunal.

## Arquitetura

### Componentes

**Models** — `app/Models/`
- `CredencialPje` — casts `encrypted` em `login`/`senha`; `login_hash`
  (sha256 do login) para lookup e unicidade sem decifrar; acessor
  `login_mascarado`.
- `ProcessoMonitoramento` — estados, cálculo de `proxima_execucao_em`,
  relação `credencial()`, `execucoes()`, `tribunal()`.
- `ProcessoMonitoramentoExecucao` — uma linha por ciclo; guarda o delta e o
  estado do webhook.

**`MonitoramentoService`** — `app/Services/Monitoramento/MonitoramentoService.php`
- Criar, atualizar, pausar, retomar e cancelar. Resolve a credencial, calcula a
  primeira `proxima_execucao_em` (= agora, primeira execução imediata) e aplica
  o limite de monitoramentos ativos por token.

**`CredencialPjeService`** — `app/Services/Monitoramento/CredencialPjeService.php`
- `resolver(?string $login, ?string $senha, ApiToken $token, Tribunal $tribunal): ?CredencialPje`
  — sem par completo devolve `null` (usa o padrão do `.env`); com par, faz
  find-or-create por `(api_token_id, tribunal_id, login_hash)` e atualiza a senha
  se mudou. Par incompleto é descartado inteiro, mesma regra atômica do
  `InjectCredenciaisPjePadrao`.

**`DetectarAlteracoesProcessoService`** — `app/Services/Processo/DetectarAlteracoesProcessoService.php`
- `snapshot(?Processo): array` — `identificador_movimento` e `id_documento`
  existentes antes da consulta.
- `delta(Processo, array $snapshot): array` — o que apareceu depois, já no
  formato do payload. Processo inexistente antes → snapshot vazio → tudo é novo
  (primeira execução, sinalizada com `primeira_execucao: true`).

**`ExecutarMonitoramentoProcessoJob`** — fila `monitoramento`
- `tries = 1` (o reagendamento é do agendador, não da fila; retry cego
  multiplicaria chamadas ao tribunal). Falha vira execução com `status: falha`.

**`EnviarWebhookMonitoramentoJob`** — fila `monitoramento`
- `tries = 5`, `backoff = [10, 60, 300, 900, 3600]`, idempotente por
  `webhook_enviado_em` — cópia fiel do `EnviarWebhookDownloadJob`.

**`MonitoramentosDespachar`** (`monitoramentos:despachar`) — comando agendado
a cada minuto com `withoutOverlapping()`.

**`MonitoramentosReenviarWebhook`** (`monitoramentos:reenviar-webhook`) —
espelha `ExportacoesReenviarWebhook` para reenvio manual.

**`MonitoramentoProcessoController`** — `app/Http/Controllers/Api/`
- `store`, `index`, `show`, `update`, `destroy`, `execucoes`, `executar`.

**Extensão de `CallbackNotifier`** — parâmetro opcional `array $headers = []`,
para enviar `X-Idempotency-Key` e `X-Evento` junto do `X-API-Token`. Assinatura
atual preservada (default vazio), exportação segue igual.

### Fluxo — criação

```
POST /api/processo/monitoramentos { numero_processo, tribunal_id, intervalo_horas,
                                    callback_url, callback_token, login_pje?, senha_pje? }
  → valida (callback https + não-interno, intervalo 1..720, tribunal ativo)
  → CredencialPjeService.resolver(...)  → credenciais_pje (cifrado) ou null
  → cria processo_monitoramentos (status=ativo, proxima_execucao_em=now())
  → 201 { uuid, status, proxima_execucao_em, credencial: { login_mascarado } }
```

### Fluxo — ciclo agendado

```
schedule:run (a cada minuto) → monitoramentos:despachar
  SELECT ... WHERE status='ativo' AND proxima_execucao_em <= now()
              AND (bloqueado_ate IS NULL OR bloqueado_ate < now())
  FOR UPDATE SKIP LOCKED  (chunks de 200)
    → bloqueado_ate = now()+15min
    → proxima_execucao_em = now() + intervalo_horas ± jitter
    → ExecutarMonitoramentoProcessoJob::dispatch(id)->onQueue('monitoramento')

ExecutarMonitoramentoProcessoJob
  → cria execucao (iniciado_em)
  → snapshot = DetectarAlteracoes.snapshot(processo)
  → ProcessoService.consultarNumero(tribunal, numero, login, senha,
                                    data_referencia, baixarBinarios: false)
  → delta = DetectarAlteracoes.delta(processo, snapshot)
  → execucao: status=sucesso, contadores, delta
     monitoramento: ultima_execucao_em, data_referencia, falhas_consecutivas=0,
                    bloqueado_ate=null
  → EnviarWebhookMonitoramentoJob::dispatch(execucao_id)

  em exceção:
  → execucao: status=falha, erro_resumo
     monitoramento: falhas_consecutivas++, bloqueado_ate=null
                    se falhas_consecutivas >= 5 → status=suspenso
  → EnviarWebhookMonitoramentoJob::dispatch(execucao_id)   // notifica mesmo assim
```

### Mudança de dados

**`credenciais_pje`** (nova)

| coluna | tipo | nota |
|---|---|---|
| `id` | bigint PK | |
| `uuid` | uuid unique | identificador público |
| `api_token_id` | FK `api_tokens` | dono |
| `tribunal_id` | FK `tribunais` | |
| `login` | text | cast `encrypted` |
| `senha` | text | cast `encrypted` |
| `login_hash` | char(64) | sha256(login), lookup sem decifrar |
| `ativo` | boolean default true | |
| `timestamps` | | |

Unique `(api_token_id, tribunal_id, login_hash)`.

**`processo_monitoramentos`** (nova)

| coluna | tipo | nota |
|---|---|---|
| `id` | bigint PK | |
| `uuid` | uuid unique | identificador público na API |
| `api_token_id` | FK `api_tokens` | dono, base do isolamento |
| `tribunal_id` | FK `tribunais` | |
| `numero_processo` | string(25) | normalizado por `cleanNumeroProcesso()` |
| `intervalo_horas` | smallint | 1..720 |
| `credencial_id` | FK `credenciais_pje` nullable | null = par padrão do `.env` |
| `callback_url` | string(2048) | |
| `callback_token` | string(500) | |
| `status` | string(20) | `ativo` \| `pausado` \| `suspenso` \| `cancelado` |
| `proxima_execucao_em` | timestamp | |
| `ultima_execucao_em` | timestamp nullable | |
| `data_referencia` | string(8) nullable | marca d'água MNI (`Ymd`) |
| `falhas_consecutivas` | tinyint default 0 | |
| `bloqueado_ate` | timestamp nullable | lock de despacho |
| `timestamps`, `softDeletes` | | |

Índices: `(status, proxima_execucao_em)`; unique parcial
`(api_token_id, tribunal_id, numero_processo) WHERE deleted_at IS NULL AND status <> 'cancelado'`.

**`processo_monitoramento_execucoes`** (nova)

| coluna | tipo | nota |
|---|---|---|
| `id` | bigint PK | |
| `uuid` | uuid unique | `X-Idempotency-Key` do webhook |
| `monitoramento_id` | FK cascade | |
| `iniciado_em`, `finalizado_em` | timestamp | |
| `status` | string(12) | `sucesso` \| `falha` |
| `houve_alteracao` | boolean default false | |
| `movimentos_novos`, `documentos_novos` | int default 0 | |
| `delta` | json nullable | itens novos, já no formato do payload |
| `erro_resumo` | text nullable | |
| `webhook_enviado_em` | timestamp nullable | idempotência |
| `webhook_tentativas` | tinyint default 0 | |
| `webhook_status_http` | smallint nullable | |
| `timestamps` | | |

Índice `(monitoramento_id, created_at)`. Retenção: comando
`monitoramentos:limpar-execucoes --dias=90` no schedule diário.

### Mudança de comportamento — download de binários

`SalvarDocumentoProcessoService::salvarDocumento()` despacha hoje
`BaixarDocumentoMNIJob` para todo documento cujo status não seja `baixado`. Sem
mexer nisso, cada ciclo de monitoramento encheria a fila `mni-download`.

Solução: parâmetro `bool $baixarBinarios = true` em
`SalvarDocumentoProcessoService::execute()/salvarDocumento()` e propagado por
`ProcessoService::consultarNumero()`. **Default `true` — todos os chamadores
atuais mantêm o comportamento exato de hoje.** Só o job de monitoramento passa
`false`.

Consequência aceita: documentos descobertos pelo monitoramento ficam com status
`pendente` e sem binário no S3 até alguém pedir (`/documento/visualizar`) ou
rodar `mni:baixar-documento-pendente`. É o que "metadados" significa.

## Contrato HTTP

Todas as rotas sob `ValidateApiToken` (+ `InjectCredenciaisPjePadrao` no `store`).
Prefixo: `/api/processo/monitoramentos`.

### `POST /api/processo/monitoramentos`

| campo | tipo | obrig. | regra |
|---|---|---|---|
| `numero_processo` | string | sim | normalizado por `cleanNumeroProcesso()` |
| `tribunal_id` | int | sim | tribunal existente e `ativo` |
| `intervalo_horas` | int | sim | 1..720 |
| `callback_url` | string | sim | `Rules\CallbackUrl` (https, sem IP interno), max 2048 |
| `callback_token` | string | sim | max 500 |
| `login_pje` | string | não | par atômico com `senha_pje` |
| `senha_pje` | string | não | idem |

`201` com o recurso. `409` se já existir monitoramento ativo para
(token, tribunal, processo), com `{ "error": "...", "uuid": "<existente>" }`.
`422` em validação — inclusive ao estourar
`config('pje.monitoramento.max_ativos_por_token')` (default 500).

```json
{
  "uuid": "9f1c…",
  "numero_processo": "00008323520244013200",
  "tribunal_id": 12,
  "intervalo_horas": 6,
  "status": "ativo",
  "proxima_execucao_em": "2026-08-12T18:03:00-03:00",
  "ultima_execucao_em": null,
  "credencial": { "uuid": "3b7a…", "login_mascarado": "123****900" },
  "created_at": "2026-08-12T18:03:00-03:00"
}
```

### Demais rotas

| método | rota | efeito |
|---|---|---|
| `GET` | `/monitoramentos` | lista paginada (10/pág) do token; filtro `?status=` |
| `GET` | `/monitoramentos/{uuid}` | detalhe + últimas 10 execuções |
| `PATCH` | `/monitoramentos/{uuid}` | altera `intervalo_horas`, `callback_url`, `callback_token`, `status` (`ativo`\|`pausado`) — voltar para `ativo` zera `falhas_consecutivas` e reagenda |
| `DELETE` | `/monitoramentos/{uuid}` | `status=cancelado` + soft delete → `204` |
| `GET` | `/monitoramentos/{uuid}/execucoes` | histórico paginado |
| `POST` | `/monitoramentos/{uuid}/executar` | força um ciclo agora → `202`; não altera `proxima_execucao_em` |

`{uuid}` de outro token → `404` (não `403`: não vaza existência).

## Payload do webhook

`POST {callback_url}` com headers `X-API-Token: {callback_token}`,
`X-Evento: processo.monitoramento.executado`,
`X-Idempotency-Key: {execucao_uuid}`.

```json
{
  "evento": "processo.monitoramento.executado",
  "monitoramento_id": "9f1c…",
  "execucao_id": "c2e0…",
  "numero_processo": "00008323520244013200",
  "tribunal_id": 12,
  "executado_em": "2026-08-12T18:03:04-03:00",
  "status": "sucesso",
  "primeira_execucao": false,
  "houve_alteracao": true,
  "resumo": { "movimentos_novos": 2, "documentos_novos": 1, "truncado": false },
  "movimentos": [
    { "identificador_movimento": "…", "codigo_nacional": 123,
      "complemento": "…", "data_hora": "2026-08-12T09:12:00-03:00" }
  ],
  "documentos": [
    { "id_documento": "…", "descricao": "…", "tipo_documento": "…",
      "mimetype": "application/pdf", "data_hora": "2026-08-12T09:12:00-03:00",
      "nivel_sigilo": 0 }
  ],
  "proxima_execucao_em": "2026-08-13T00:03:00-03:00"
}
```

Execução com falha:

```json
{
  "evento": "processo.monitoramento.executado",
  "monitoramento_id": "9f1c…",
  "execucao_id": "c2e0…",
  "numero_processo": "00008323520244013200",
  "status": "falha",
  "houve_alteracao": false,
  "erro_resumo": "MNI: credenciais inválidas",
  "falhas_consecutivas": 5,
  "monitoramento_status": "suspenso"
}
```

Cap de **500 itens por lista**; acima disso a lista é truncada e
`resumo.truncado = true` (o cliente busca o resto em `/processo/visualizar`).

## Regras de negócio

- **Jitter:** `proxima_execucao_em = now() + intervalo_horas + rand(-5, +5) min`,
  para não concentrar todas as consultas no mesmo minuto.
- **`data_referencia`:** a partir da 2ª execução usa a data da última execução
  bem-sucedida **menos 1 dia** (o MNI trunca `dataReferencia` para o dia:
  `ConsultarProcessoService.php:24`). Primeira execução usa o default atual de
  90 dias. Com intervalo < 24h o tribunal devolve o dia inteiro — o delta filtra
  a duplicidade; só o payload SOAP fica maior.
- **Suspensão automática:** 5 falhas consecutivas → `status=suspenso`, para de
  agendar. O webhook da 5ª falha carrega `monitoramento_status: "suspenso"`.
  Retomada manual via `PATCH { "status": "ativo" }`.
- **Idempotência do webhook:** `webhook_enviado_em` não-nulo bloqueia reenvio;
  o cliente deduplica por `X-Idempotency-Key`.
- **4xx no callback:** `CallbackPermanentException` → não retenta, loga
  `critical`. Não suspende o monitoramento (falha do cliente, não do tribunal).
- **Cancelamento:** soft delete preserva o histórico de execuções.

## Segurança

- `credenciais_pje.login/senha` cifrados em repouso (cast `encrypted`, APP_KEY).
  Nunca serializados na API — apenas `login_mascarado`. Rotação de APP_KEY
  invalida as credenciais salvas (documentar no README).
- `callback_token` fica em texto no banco — mesmo trade-off já registrado na
  spec de 2026-07-14, mantido por consistência.
- Nada de `login_pje`/`senha_pje`/`callback_token` em log. As rotas de
  monitoramento recebem credenciais **no body** (POST), não em query string —
  corrige o problema apontado no cabeçalho do `openapi.yaml` para os endpoints
  antigos.
- Anti-SSRF do `CallbackUrlValidator` aplicado na criação **e** antes de cada
  POST (a URL pode ter sido criada válida e depois repontada).
- Isolamento por `api_token_id` em toda query — validado por teste.

## Infra

- **Horizon:** novo supervisor `supervisor-monitoramento` na fila
  `monitoramento` (`maxProcesses` modesto, ex. 3 — o gargalo é o tribunal).
- **Schedule** (`routes/console.php`):
  - `monitoramentos:despachar` → `everyMinute()->withoutOverlapping()`
  - `monitoramentos:limpar-execucoes` → `dailyAt('04:30')`
- **Config** `config/pje.php`: bloco `monitoramento` com
  `max_ativos_por_token`, `intervalo_min_horas`, `intervalo_max_horas`,
  `max_falhas_consecutivas`, `limite_itens_payload`.
- **OpenAPI:** novo tag `Monitoramentos` e os 7 paths em `docs/api/openapi.yaml`,
  incluindo o schema do payload do webhook.

## Testes (Pest)

- `tests/Feature/Api/MonitoramentoProcessoTest.php` — CRUD; `401` sem token;
  `422` para `callback_url` http/IP interno e intervalo fora da faixa; `409`
  duplicado; `404` para uuid de outro token; credencial nunca aparece na resposta.
- `tests/Unit/Models/CredencialPjeTest.php` — `assertDatabaseMissing` com a senha
  em texto puro (prova a cifra); `login_hash` estável; `login_mascarado`.
- `tests/Feature/Console/MonitoramentosDespacharTest.php` — despacha só os
  vencidos e ativos; reagenda com jitter; `bloqueado_ate` impede duplo despacho;
  ignora `pausado`/`suspenso`/`cancelado`.
- `tests/Feature/Jobs/ExecutarMonitoramentoProcessoJobTest.php` — delta correto
  com `ProcessoService` mockado; `Queue::fake()` provando que
  `BaixarDocumentoMNIJob` **não** é despachado; exceção → `falhas_consecutivas`
  e suspensão na 5ª; webhook despachado nos dois caminhos.
- `tests/Feature/Jobs/EnviarWebhookMonitoramentoJobTest.php` — `Http::fake()`:
  2xx marca `webhook_enviado_em`; 4xx falha permanente sem retry; 5xx retenta;
  não reenvia se já notificado; headers corretos.
- `tests/Feature/Migrations/` — colunas e índices criados.

Baseline: rodar com `php artisan test` (o wrapper `./php` está quebrado) e
comparar com o baseline atual, que já tem falhas herdadas no pipeline de
exportação.

## Fora de escopo

- Tela de monitoramentos no dashboard Inertia/React e métricas na home.
- Download automático dos binários dos documentos novos.
- Monitoramento por nome de parte / OAB (só por número de processo).
- Notificação por e-mail; assinatura HMAC do corpo do webhook (hoje a
  autenticação do callback é o `X-API-Token` reenviado).

## Riscos e trade-offs

1. **Carga no tribunal.** N monitoramentos × frequência vira volume de SOAP.
   Mitigado por intervalo mínimo de 1h, jitter, fila dedicada com concorrência
   baixa e limite de ativos por token. É o risco principal a acompanhar.
2. **`data_referencia` com granularidade de dia** torna intervalos curtos
   (1–6h) menos eficientes no lado do tribunal, ainda que corretos no delta.
3. **Primeira execução notifica tudo** (até 500 itens) — sinalizado por
   `primeira_execucao: true` para o cliente tratar como carga inicial.
4. **Segredos em repouso:** credenciais cifradas, `callback_token` não. Herdado
   da decisão anterior; unificar exigiria migrar `processo_exportacoes`.
5. **Documentos sem binário** podem surpreender quem espera o comportamento dos
   endpoints existentes; está documentado no contrato e no OpenAPI.
