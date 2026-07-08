# Remoção OCR/SAMIA + credenciais PJe obrigatórias na consulta

**Data:** 2026-07-07
**Status:** Aprovado

## Objetivo

1. Remover completamente as funcionalidades de OCR (código + colunas de banco).
2. Remover completamente a integração SAMIA (código + colunas de banco).
3. Tornar `login_pje` e `senha_pje` obrigatórios nos endpoints `GET /processo/consultar` e `GET /processo/visualizar`.

## Contexto

- OCR: pipeline de extração de texto de documentos via microserviço `sim-ocr`, com webhook de retorno e polling. Integrado ao download de documentos MNI.
- SAMIA: sync de base de conhecimento (RAG). Já dormante — todos os dispatches em código estão comentados ("desativado temporariamente — sem Samia").
- Consulta de processo: `login_pje`/`senha_pje` hoje são opcionais em todos os endpoints; fallback são as credenciais do tribunal no banco (`$tribunal->login`, `Crypt::decrypt($tribunal->password)`).

## Decisões

| Decisão | Escolha |
| --- | --- |
| Profundidade da remoção | Código + migration dropando colunas (dados dessas colunas serão perdidos) |
| Escopo da obrigatoriedade | Apenas `/processo/consultar` e `/processo/visualizar` |
| Quando validar | Sempre (422 se ausentes, mesmo com processo já no banco local) |
| Estilo de validação | Inline no controller (padrão existente do projeto; sem FormRequest) |
| Fallback tribunal no service layer | Mantido — usado pelos demais endpoints e jobs internos |

## 1. Remoção OCR

### Apagar

- **Rotas** em `routes/api.php`: `POST /ocr/webhook`, `POST /processo/ocr`, `POST /documento/ocr`
- **Controllers**: `app/Http/Controllers/Api/OCRProcessoController.php`, `OCRDocumentoController.php`, `OCRWebhookController.php`
- **Middleware**: `app/Http/Middleware/ValidateOCRWebhook.php` (não estava roteado)
- **Jobs**: `app/Jobs/OCRProcessoJob.php`, `OCRRequestJob.php`, `JuntarOCRProcessoJob.php`
- **Command**: `app/Console/Commands/OCRPollStatus.php`
- **Config**: bloco `sim_ocr` em `config/services.php`; disk `s3_ocr` em `config/filesystems.php`; filas/tags OCR em `config/horizon.php`
- **Env**: `SIM_OCR_URL`, `SIM_OCR_API_TOKEN`, `SIM_OCR_BUCKET_ORIGEM`, `SIM_OCR_BUCKET_DESTINO`, `SIM_OCR_WEBHOOK_URL`, `AWS_ACCESS_KEY_ID_OCR`, `AWS_SECRET_ACCESS_KEY_OCR`, `AWS_BUCKET_OCR`, `VERSAO_OCR` — de `.env.example` e `.env`
- **Docs**: `docs/fluxo-ocr-rag-webhook.md`; specs/plans antigos de OCR em `docs/superpowers/`

### Ajustar (não apagar)

- **`BaixarDocumentoMNIJob`**: remover gate `processoTemOcrSolicitado()` e dispatch de `OCRRequestJob` pós-download. Job permanece (download de documentos é função independente do OCR). Remover 4º argumento legado do construtor se não usado.
- **Model `Processo`**: remover `ocr_status` do fillable e consts `OCR_STATUS_PENDENTE/PROCESSANDO/CONCLUIDO/FALHA`.
- **Model `ProcessoDocumento`**: remover `ocr_processado`, `ocr_enviado_fila`, `ocr_concluido_data`, `ocr_job_id` de fillable/casts.
- **`CleanTempFiles` / `CleanDuplicateFailedJobs`**: limpar referências a OCR (arquivos temp `.metadata.json`, nomes de job nas filas).
- **Webhooks de progresso para o SIM** (`notificarProgressoSim` → `sim_app.url/webhook/ocr-progresso`): morrem junto com os controllers/jobs OCR. Bloco `sim_app` em `config/services.php` só é removido se nenhum outro fluxo usar (verificar no plano).

### Migration (nova)

Dropar:

- `processo_documentos`: `ocr_processado`, `ocr_enviado_fila`, `ocr_concluido_data`, `ocr_job_id` (+ índice de `ocr_job_id`)
- `processos`: `ocr_status` (+ índice)

Migrations antigas que criaram essas colunas permanecem (histórico). `down()` recria colunas/índices.

## 2. Remoção SAMIA

### Apagar (SAMIA)

- **Service**: `app/Services/SamiaService.php`
- **Job**: `app/Jobs/SyncBaseConhecimentoSamiaJob.php`
- **Command**: `app/Console/Commands/SyncBaseConhecimento.php` (`samia:sync-base-conhecimento`)
- **Helper**: função `samia()` em `app/Helpers/functions.php`
- **Config**: bloco `samia` em `config/services.php`
- **Env**: `SAMIA_API_KEY` (presente em `.env`), `SAMIA_API_URL`, `SAMIA_BASE_KB`, `SAMIA_API_TIMEOUT`, `SAMIA_ORIGEM_ID`

### Ajustar (SAMIA)

- **Model `Processo`**: remover `knowledge_base_status_sync`, `knowledge_base_sequence_job`, `knowledge_base_created_at` do fillable e consts `KNOWLEDGE_BASE_STATUS_*`.

### Migration (SAMIA)

Na **mesma migration** da seção 1, dropar de `processos`: `knowledge_base_status_sync`, `knowledge_base_sequence_job`, `knowledge_base_created_at`. Uma única migration cobre OCR + SAMIA.

## 3. Credenciais obrigatórias na consulta

Em `app/Http/Controllers/Api/ConsultarProcessoController.php`:

- **`index()`** (`GET /processo/consultar`) e **`show()`** (`GET /processo/visualizar`): validação inline exigindo `login_pje => required|string` e `senha_pje => required|string`. Request sem credenciais → **422**, sempre — mesmo quando o processo já existe no banco local.
- **`show()`**: já repassa creds a `consultarNumero` — mantém.
- **`index()` / `buscarPorNumeroProcesso()`**: hoje chama `consultarNumero` **sem** repassar creds (fallback tribunal sempre usado). Passa a repassar `login_pje`/`senha_pje` do request.
- **Demais endpoints** (`/processo/dados-basicos`, `/processo/movimentos/listar`, variantes async): intocados — creds continuam opcionais com fallback tribunal.
- **Service layer** (`ConsultarProcessoService`, `ConsultarDocumentoService`, `SalvarDocumentoProcessoService`, `ProcessoService`): intocado — fallback `?? $tribunal->login` permanece para os demais fluxos e jobs internos (`BaixarProcessoMNIJob` etc.).

## 4. Limpeza adicional

- **Rota morta**: `GET /processo-pje/consultar` → `ConsultarProcessoController@consultarPje` — método não existe (500 garantido). Remover a rota.

## Contrato de erro

422 com formato padrão de validação Laravel:

```json
{
  "message": "The login pje field is required. (and 1 more error)",
  "errors": {
    "login_pje": ["The login pje field is required."],
    "senha_pje": ["The senha pje field is required."]
  }
}
```

(Mensagens conforme locale configurado no app.)

## Testes (Pest)

1. `GET /processo/consultar` sem `login_pje`/`senha_pje` → 422.
2. `GET /processo/visualizar` sem creds → 422 (mesmo com processo existente no banco).
3. Ambos com creds → fluxo normal (200/appropriate), creds propagadas ao service (mock/spy).
4. `/processo/dados-basicos` e `/processo/movimentos/listar` sem creds → continuam funcionando (fallback tribunal).
5. Suite existente passa após remoções (nenhuma referência órfã a classes OCR/SAMIA).

## Riscos

- **Breaking change de API**: consumidores de `/processo/consultar` e `/processo/visualizar` que não enviam creds passarão a receber 422. Comunicar ao time do SIM.
- **Consumidores dos endpoints OCR** (`POST /processo/ocr`, `/documento/ocr`): passarão a receber 404. O SIM precisa parar de chamá-los antes do deploy.
- **Perda de dados**: colunas `ocr_*` e `knowledge_base_*` dropadas. Se necessário histórico, fazer backup antes de rodar a migration.

## Fora de escopo

- Alterar autenticação (`ValidateApiToken`) — permanece como está.
- Mexer no fluxo de expedientes (`ConsultarExpedienteService`).
- Introduzir FormRequests no projeto.
