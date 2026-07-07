# Substituição de e-mail por webhook na exportação de processo

**Data:** 2026-04-29
**Tipo:** Refatoração / breaking change
**Serviço:** ms-mni
**Cliente afetado:** SIM (sistema interno)

## Contexto

Hoje a exportação de processo no ms-mni encerra entregando um e-mail ao usuário (`EnviarAutosProcessoMail` com PDF anexo). O SIM está implantando uma **Central de Downloads** própria (dropdown na topbar + sino de notificações) e quer substituir o e-mail por uma notificação in-app. O ms-mni continua responsável pela geração pesada do PDF; muda apenas a forma de entrega.

O contrato com o time SIM já foi acordado e fixa: a request de entrada ganha `user_id`/`titulo`/`formato`, perde `email`/`notificacao_id`; o arquivo gerado vai pro S3 em `downloads/{user_id}/{uuid}.pdf`; a conclusão vira um webhook `POST {SIM_URL}/webhook/download` com payload tipado por status (`concluido` ou `falhou`).

## Decisões de alto nível

- **Breaking change limpo.** `email` e `notificacao_id` removidos do payload de entrada. Não há retrocompat. Deploy coordenado SIM ↔ ms-mni.
- **Apenas formato `pdf`** suportado nesta entrega. O campo `formato` fica no contrato para evolução futura.
- **Webhook em sucesso e falha**, com payloads distintos. Status: `concluido` ou `falhou`.
- **`notificacao_id` legacy removido** apenas para o tipo `DownloadProcesso`. Tabela `notificacoes` permanece (continua usada por OCR).
- **Pipeline de três jobs encadeados** em vez do job monolítico atual. Cada etapa (gerar PDF → upload S3 → webhook) é um job independente, com retry próprio, mediada por uma tabela de estado `processo_exportacoes`.
- **Idempotência do webhook garantida via campo `webhook_enviado_em`** na tabela. O job `EnviarWebhookDownloadJob` é idempotente: se o campo já estiver preenchido, retorna sem POST.

## Contrato HTTP

### Endpoint de entrada (sem mudança de URL)

```
POST /api/processo/download
Headers: X-API-Token: {token}
```

#### Body — request

| campo | tipo | obrig. | observação |
|---|---|---|---|
| `numero_processo` | string | sim | mantido |
| `tribunal_id` | int | não | mantido (opcional) |
| `user_id` | int | sim | **novo** — eco no webhook |
| `titulo` | string (≤255) | sim | **novo** — eco no webhook |
| `formato` | string | sim | **novo** — só aceita `"pdf"` por enquanto |
| `ids_selecionados` | int[] | condicional | mantido |
| `periodo_inicial` / `periodo_final` | date | condicional | mantidos |
| `id_inicial` / `id_final` | int | condicional | mantidos |
| `email` | — | — | **REMOVIDO** |
| `notificacao_id` | — | — | **REMOVIDO** |

#### Respostas síncronas

- `200 OK` → `{ "message": "Exportação enfileirada", "exportacao_id": 123 }`
- `422 Unprocessable Entity` → validação falhou (campos faltando, `formato` inválido)
- `404 Not Found` → nenhum documento disponível para os filtros (validação síncrona via `ExportacaoProcessoService::temDocumentosDisponiveis`, equivalente ao atual `validarDocumentosDisponiveis`)
- `401 Unauthorized` → token inválido (middleware `ValidateApiToken` existente)

Quando o controller responde `4xx`, **nenhum** registro `processo_exportacoes` é criado e nenhum job é despachado. O SIM exibe a mensagem de erro ao usuário em tempo real.

### Webhook de saída

```
POST {SIM_WEBHOOK_DOWNLOAD_URL}
Headers: X-API-Token: {SIM_API_TOKEN}
Content-Type: application/json
Timeout: 10s
```

#### Payload — sucesso (`status=concluido`)

```json
{
  "user_id": 152,
  "titulo": "Processo 0000000-00.0000.0.00.0000 — PDF",
  "formato": "pdf",
  "status": "concluido",
  "s3_path": "downloads/152/8f3a-2e1c-...uuid.pdf",
  "tamanho_bytes": 4582934
}
```

#### Payload — falha (`status=falhou`)

```json
{
  "user_id": 152,
  "titulo": "Processo 0000000-00.0000.0.00.0000 — PDF",
  "formato": "pdf",
  "status": "falhou",
  "erro_resumo": "Documentos do processo indisponíveis no momento."
}
```

`erro_resumo` é opcional (o SIM tem texto-padrão de fallback).

## Modelo de dados

### Migration nova: `create_processo_exportacoes_table`

```php
Schema::create('processo_exportacoes', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id');
    $table->string('numero_processo', 25);
    $table->unsignedBigInteger('tribunal_id')->nullable();
    $table->string('titulo');
    $table->string('formato', 10);
    $table->enum('status', ['enfileirado', 'processando', 'concluido', 'falhou'])
          ->default('enfileirado');
    $table->uuid('uuid_arquivo')->nullable();
    $table->string('s3_path', 500)->nullable();
    $table->unsignedBigInteger('tamanho_bytes')->nullable();
    $table->text('erro_resumo')->nullable();
    $table->json('filtros');
    $table->timestamp('webhook_enviado_em')->nullable();
    $table->unsignedTinyInteger('webhook_tentativas')->default(0);
    $table->timestamps();

    $table->index('user_id');
    $table->index('status');
    $table->index(['user_id', 'created_at']);
});
```

`filtros` guarda em JSON o que veio na request: `ids_selecionados`, `periodo_inicial/final`, `id_inicial/final`. Isso desacopla o registro da `Processo` local (a entidade de processo pode nem existir ainda no banco quando a exportação chega).

### Model novo: `App\Models\ProcessoExportacao`

- Constantes de status: `STATUS_ENFILEIRADO`, `STATUS_PROCESSANDO`, `STATUS_CONCLUIDO`, `STATUS_FALHOU`.
- `casts`: `filtros` → `array`, `webhook_enviado_em` → `datetime`.
- Sem relação obrigatória com `Processo` — apenas `numero_processo` como string.

### Tabela `notificacoes` legacy

Preservada (uso por OCR continua). Apenas removemos a criação de registros com `tipo=DownloadProcesso` no controller.

## Pipeline de jobs

Todos os jobs ficam na fila `exportar-processo` (existente).

### `GerarPdfExportacaoJob`

`tries=3`, `timeout=600`. Recebe `exportacao_id`.

1. Carrega `ProcessoExportacao`, marca `status=processando`.
2. Se `tribunal_id` presente, chama `ProcessoService::consultarNumero` (best-effort, idêntico ao atual — falha de WS apenas loga warning).
3. Resolve documentos via `ExportacaoProcessoService::consultarDocumentos`.
4. Se vazio: marca `status=falhou`, `erro_resumo="Nenhum documento encontrado para os filtros informados."`, despacha `EnviarWebhookDownloadJob`, encerra.
5. Gera PDF unificado (FPDI + capa Blade `processo.download`, mesma lógica atual) em `storage/app/private/exportacoes/{uuid_arquivo}.pdf`. UUID v4 gerado e persistido em `uuid_arquivo`.
6. Limpa documentos individuais baixados do S3 (lógica atual).
7. Despacha `EnviarParaS3ExportacaoJob`.

Em qualquer `Throwable` não tratado: `marcarComoFalhou($e->getMessage())`.

### `EnviarParaS3ExportacaoJob`

`tries=3`, backoff `[10, 30, 60]`. Recebe `exportacao_id`.

1. Lê arquivo local em `storage/app/private/exportacoes/{uuid_arquivo}.pdf`.
2. `Storage::disk('s3')->putFileAs("downloads/{$user_id}", $arquivo, "{$uuid_arquivo}.pdf", ['visibility' => 'private'])`.
3. Atualiza `s3_path`, `tamanho_bytes`, `status=concluido`.
4. Apaga arquivo local.
5. Despacha `EnviarWebhookDownloadJob`.

Falha transitória: lança exception → queue retenta com backoff. Esgotado o `tries`: marca `status=falhou`, `erro_resumo="Falha ao enviar arquivo para o storage."`, despacha webhook. Arquivo local **não** é deletado em falha definitiva (preserva para investigação).

### `EnviarWebhookDownloadJob`

`tries=5`, backoff `[10, 60, 300, 900, 3600]`. Recebe `exportacao_id`.

**Idempotência:** se `webhook_enviado_em != null`, retorna imediatamente sem fazer HTTP.

1. Incrementa `webhook_tentativas` (a cada execução, sucesso ou falha).
2. Monta payload com base no `status` da exportação:
   - `concluido` → inclui `s3_path` + `tamanho_bytes`
   - `falhou` → inclui `erro_resumo`
3. `POST {SIM_WEBHOOK_DOWNLOAD_URL}` com `X-API-Token: {SIM_API_TOKEN}`, timeout 10s.
4. **Sucesso (2xx):** seta `webhook_enviado_em = now()`. Job termina.
5. **Erro retentável** (5xx, timeout, conexão recusada): lança exception → queue retenta com backoff configurado.
6. **Erro permanente** (4xx — cliente errado, retry não vai resolver): `Log::critical`, marca o job como falho sem relançar (`fail()` no Laravel) para evitar consumir as `tries` à toa. Estado fica recuperável via comando `exportacoes:reenviar-webhook` após investigação.
7. **Esgotado `tries` por erros retentáveis:** `Log::critical` com último payload + status. Recuperação via comando.

## Camada de serviço

### `App\Services\Exportacao\ExportacaoProcessoService`

```php
public function criar(array $dados): ProcessoExportacao;
public function consultarDocumentos(ProcessoExportacao $exportacao): Collection;
public function temDocumentosDisponiveis(array $filtros, string $numeroProcesso): bool;
public function gerarPdf(ProcessoExportacao $exportacao, Collection $documentos): string;
public function enviarParaS3(ProcessoExportacao $exportacao, string $caminhoLocal): void;
public function marcarComoFalhou(ProcessoExportacao $exportacao, string $erroResumo): void;
```

Concentra regras de domínio (validação de filtros, geração do PDF, upload S3, transição de estado). Controller e jobs ficam orquestradores finos.

### `App\Services\Exportacao\WebhookDownloadClient`

```php
public function notificar(ProcessoExportacao $exportacao): void;
```

Encapsula a chamada HTTP pro SIM. Existência separada simplifica mock em teste.

## Arquivos removidos

- `app/Mail/EnviarAutosProcessoMail.php`
- `app/Listeners/DeleteTemporaryFilesAfterEmailSent.php`
- `resources/views/mail/proceso/autos.blade.php`
- Registro do listener em `EventServiceProvider`/`AppServiceProvider`, se houver.
- Mailer `smsapi` em `config/mail.php` — **manter** se existir outro uso; remover se for exclusivo.

## Arquivos modificados

- `app/Http/Controllers/Api/DownloadProcessoController.php` — Form Request, cria exportação via service, despacha `GerarPdfExportacaoJob`, retorna 200 + `exportacao_id`.
- `routes/api.php` — sem mudança de rota.
- `config/services.php` — nova seção `sim_webhook_download`.
- `.env.example` — adiciona `SIM_WEBHOOK_DOWNLOAD_URL` e `SIM_API_TOKEN`.

## Arquivos novos

```
app/
├── Jobs/
│   ├── GerarPdfExportacaoJob.php
│   ├── EnviarParaS3ExportacaoJob.php
│   └── EnviarWebhookDownloadJob.php
├── Models/ProcessoExportacao.php
├── Services/Exportacao/
│   ├── ExportacaoProcessoService.php
│   └── WebhookDownloadClient.php
├── Console/Commands/ExportacoesReenviarWebhook.php
└── Http/Requests/Api/CriarExportacaoProcessoRequest.php

database/migrations/
└── 2026_04_29_*_create_processo_exportacoes_table.php
```

## Configuração

### `.env.example` (adições)

```bash
SIM_WEBHOOK_DOWNLOAD_URL=https://sim.example.com/webhook/download
SIM_API_TOKEN=
```

### `config/services.php` (adição)

```php
'sim_webhook_download' => [
    'url' => env('SIM_WEBHOOK_DOWNLOAD_URL'),
    'token' => env('SIM_API_TOKEN'),
    'timeout' => env('SIM_WEBHOOK_TIMEOUT', 10),
],
```

`SIM_APP_URL` permanece (uso em `/webhook/notificacao`, `/webhook/atualizar-processo`). O webhook de download usa URL própria para permitir override sem afetar os demais.

## Comando artisan

`php artisan exportacoes:reenviar-webhook {exportacao_id?}`

- Sem argumento: lista exportações `(concluido|falhou)` com `webhook_enviado_em IS NULL` há mais de 1 hora, pede confirmação, redespacha `EnviarWebhookDownloadJob` para cada.
- Com argumento: redespacha apenas a especificada, sem prompt.
- `--reset-tentativas` (flag opcional): zera `webhook_tentativas` antes do redespacho.

## Storage S3 e local

- Disco S3 já configurado (`s3` em `config/filesystems.php`, credenciais via `AWS_*`). Sem novo disco.
- Path: `downloads/{user_id}/{uuid_arquivo}.pdf`, `visibility=private`.
- Local: `storage/app/private/exportacoes/{uuid_arquivo}.pdf` durante a geração.
- Após upload S3 com sucesso → arquivo local apagado.
- Após falha definitiva do upload → arquivo local **permanece** para investigação.
- Lifecycle/retenção do arquivo no S3 fica por conta do SIM (`downloads:limpar` diário, 7 dias). Ms-mni não gerencia expiração.

## Logs

Padrão: `Log::info|warning|error` com prefixo `[Exportacao:{exportacao_id}]` em todos os jobs e no service. Em falha definitiva do webhook (`tries` esgotado), `Log::critical` com payload + status da resposta do SIM.

## Testes

Todos os testes que tocam o banco usam `Illuminate\Foundation\Testing\DatabaseTransactions` (rollback por teste). **Não usar `RefreshDatabase`** — o banco é compartilhado com dev/homolog e seria destruído.

### Testes a substituir

- `tests/Feature/DownloadProcessoTest.php` (cobre fluxo de e-mail) → removido/reescrito como `DownloadProcessoControllerTest.php`.

### Testes novos

- `tests/Feature/Api/DownloadProcessoControllerTest.php`
  - Payload válido → 200 + `exportacao_id`, registro criado com `status=enfileirado`, job despachado (`Queue::fake`).
  - Sem `user_id`/`titulo`/`formato` → 422.
  - `formato` inválido (ex.: `"docx"`) → 422.
  - Campos extras como `email`/`notificacao_id` → ignorados, não quebram (Form Request faz `validated()`).
  - Sem documentos disponíveis → 404, sem registro, sem job.
  - Token inválido → 401.

- `tests/Feature/Jobs/GerarPdfExportacaoJobTest.php`
  - Cenário feliz: registro + docs mockados → arquivo gerado em `storage/app/private/exportacoes/`, `EnviarParaS3ExportacaoJob` despachado, `uuid_arquivo` preenchido.
  - Sem documentos: `status=falhou`, `erro_resumo` setado, `EnviarWebhookDownloadJob` despachado.
  - Exception genérica: `status=falhou`, webhook despachado.

- `tests/Feature/Jobs/EnviarParaS3ExportacaoJobTest.php`
  - Cenário feliz com `Storage::fake('s3')`: arquivo em `downloads/{user_id}/{uuid}.pdf`, `s3_path`+`tamanho_bytes`+`status=concluido`, arquivo local apagado, webhook despachado.
  - Falha transitória: exception → queue retenta.
  - Falha definitiva (mock): `status=falhou`, webhook despachado, arquivo local preservado.

- `tests/Feature/Jobs/EnviarWebhookDownloadJobTest.php`
  - Sucesso: `Http::fake` 200 → `webhook_enviado_em` setado, payload correto pra `concluido`.
  - Sucesso com `status=falhou`: payload com `erro_resumo`, sem `s3_path`/`tamanho_bytes`.
  - Idempotência: `webhook_enviado_em` já preenchido → não há chamada HTTP.
  - 5xx do SIM: lança exception, `webhook_tentativas` incrementa.
  - Esgotou tentativas: `Log::critical`, sem `webhook_enviado_em`.

- `tests/Unit/Services/ExportacaoProcessoServiceTest.php`
  - `consultarDocumentos` para cada combinação de filtros (ids_selecionados, periodo, id_range).
  - `temDocumentosDisponiveis` true/false.

- `tests/Feature/Console/ExportacoesReenviarWebhookTest.php`
  - Com argumento: redespacha o job para a exportação especificada.
  - Sem argumento: lista pendentes >1h e redespacha após confirmação.

### Não cobrir

- Geração FPDI propriamente dita (lógica preservada do código atual, sem mudança comportamental).
- Chamada externa ao MNI WS (mantida best-effort).

## Plano de migração / deploy

**Pré-requisitos:**
- SIM já tem `/webhook/download` implementado (confirmado pelo doc do contrato).
- `AWS_BUCKET` apontando pro bucket compartilhado SIM ↔ ms-mni.

**Ordem (curta janela coordenada):**

1. **Deploy ms-mni** com novo código + `SIM_WEBHOOK_DOWNLOAD_URL` + `SIM_API_TOKEN`. Migration roda automaticamente (`migrate --force` no `start-container`).
2. **Validação smoke**: chamar `POST /api/processo/download` em homolog/staging com payload novo; verificar registro em `processo_exportacoes`, arquivo em S3, item no dropdown do SIM.
3. **Deploy SIM** com payload novo (planejamento próprio do time SIM).

**Janela de risco:** entre passos 1 e 3, qualquer chamada do SIM antigo retorna 422. É o esperado para um breaking change limpo. Time SIM coordena ordem.

**Rollback:** `git revert` + redeploy. Migration `down()` dropa `processo_exportacoes`. Exportações em curso entre rollback ficam órfãs (PDF no S3 sem registro local) — investigação manual.

## Observabilidade pós-deploy

- Monitorar log `[Exportacao:{id}]` na primeira semana.
- Query útil: `SELECT status, COUNT(*) FROM processo_exportacoes WHERE created_at > NOW() - INTERVAL 1 DAY GROUP BY status;`.
- Alertar se `webhook_tentativas >= 5` AND `webhook_enviado_em IS NULL` por mais de 1h — indica SIM inacessível ou bug no payload.

## Fora do escopo

- Múltiplos formatos além de PDF (campo `formato` está pronto, mas só `"pdf"` é aceito).
- Limpeza de arquivos locais órfãos — comando opcional `exportacoes:limpar-locais-orfaos` numa fase posterior.
- Lifecycle no S3 (responsabilidade do SIM).
- Notificações intermediárias durante o processamento (não exigidas pelo contrato).
- Watermark/sigilo — segue como hoje (já implementado).
