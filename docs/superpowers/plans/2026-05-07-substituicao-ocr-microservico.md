# Substituição OCR Tesseract → Microserviço SIM OCR — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Substituir o processamento local de OCR com Tesseract pelo microserviço SIM OCR, usando fluxo S3→S3 com webhook de retorno.

**Architecture:** O `OCRRequestJob` passa a chamar `POST /documents/process` com `bucket_origem`/`path_origem` (arquivo já no S3) e `webhook_url`. O microserviço salva o `.txt` no bucket destino e chama de volta o endpoint `POST /api/ocr/webhook`, que atualiza o documento e dispara `JuntarOCRProcessoJob` quando todos os docs do processo estão prontos.

**Tech Stack:** Laravel 11, Pest PHP, `Illuminate\Support\Facades\Http`, PostgreSQL, filas `ocr-request` e `ocr`.

---

## Mapa de arquivos

| Ação | Arquivo |
|---|---|
| Criar | `database/migrations/2026_05_07_000000_add_ocr_job_id_to_processo_documentos_table.php` |
| Modificar | `app/Models/ProcessoDocumento.php` |
| Modificar | `app/Jobs/OCRRequestJob.php` |
| Criar | `app/Http/Controllers/Api/OCRWebhookController.php` |
| Modificar | `routes/api.php` |
| Modificar | `app/Http/Controllers/Api/OCRDocumentoController.php` |
| Deletar | `app/Jobs/OCRDocumentoJob.php` |
| Deletar | `app/Services/Processo/OCRDocumentoService.php` |
| Deletar | `app/Console/Commands/DiagnoseOCRPerformance.php` |
| Modificar | `composer.json` |
| Modificar | `docker/Dockerfile` |
| Modificar | `docker/prod/8.4/Dockerfile` |
| Modificar | `.env.example` |
| Criar | `tests/Feature/Jobs/OCRRequestJobTest.php` |
| Criar | `tests/Feature/Api/OCRWebhookControllerTest.php` |

---

## Task 1: Migration e Model — coluna `ocr_job_id`

**Files:**
- Create: `database/migrations/2026_05_07_000000_add_ocr_job_id_to_processo_documentos_table.php`
- Modify: `app/Models/ProcessoDocumento.php`

- [ ] **Step 1: Criar a migration**

```php
<?php
// database/migrations/2026_05_07_000000_add_ocr_job_id_to_processo_documentos_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->string('ocr_job_id')->nullable()->after('ocr_concluido_data');
            $table->index('ocr_job_id');
        });
    }

    public function down(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->dropIndex(['ocr_job_id']);
            $table->dropColumn('ocr_job_id');
        });
    }
};
```

- [ ] **Step 2: Rodar a migration**

```bash
php artisan migrate
```

Esperado: `Migrating: 2026_05_07_000000_add_ocr_job_id...` seguido de `Migrated`.

- [ ] **Step 3: Adicionar `ocr_job_id` ao `$fillable` do model**

Em `app/Models/ProcessoDocumento.php`, adicionar `'ocr_job_id'` à array `$fillable`:

```php
protected $fillable = [
    'processo_id',
    'id_documento',
    'id_documento_vinculado',
    'tipo_documento',
    'data_hora',
    'mimetype',
    'movimento',
    'hash',
    'descricao',
    'usuario_juntada_arquivo',
    'data_juntada',
    'status',
    'url',
    'path',
    'file_size',
    'tentativas_download',
    'erro_mni',
    'ocr_processado',
    'ocr_enviado_fila',
    'ocr_concluido_data',
    'ocr_job_id',
];
```

- [ ] **Step 4: Commitar**

```bash
git add database/migrations/2026_05_07_000000_add_ocr_job_id_to_processo_documentos_table.php
git add app/Models/ProcessoDocumento.php
git commit -m "feat: adiciona coluna ocr_job_id à tabela processo_documentos"
```

---

## Task 2: Atualizar `OCRRequestJob` — nova API + salvar `job_id`

**Files:**
- Modify: `app/Jobs/OCRRequestJob.php`
- Create: `tests/Feature/Jobs/OCRRequestJobTest.php`

- [ ] **Step 1: Escrever o teste com falha esperada**

Criar `tests/Feature/Jobs/OCRRequestJobTest.php`:

```php
<?php

use App\Jobs\OCRRequestJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

it('envia documento ao microserviço OCR e salva o job_id', function () {
    Http::fake([
        '*/documents/process' => Http::response(['id' => 'uuid-test-123', 'status' => 'pending'], 202),
    ]);

    config()->set('queue.default', 'sync');

    $processo = Processo::create([
        'numero_processo' => '0000001-00.2026.8.03.0001',
        'tribunal_id' => 1,
        'valor_causa' => '0.00',
    ]);

    $documento = ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => 9001,
        'mimetype' => 'application/pdf',
        'data_hora' => now(),
        'tipo_documento' => '0',
        'path' => 'documentos-processos/0000001-00.2026.8.03.0001/9001.pdf',
        'status' => ProcessoDocumento::STATUS_BAIXADO,
    ]);

    config([
        'services.sim_ocr.url' => 'http://ocr.test',
        'services.sim_ocr.token' => 'token-test',
        'services.sim_ocr.bucket_origem' => 'bucket-origem-test',
        'services.sim_ocr.bucket_destino' => 'bucket-destino-test',
        'services.sim_ocr.webhook_url' => 'http://app.test/api/ocr/webhook',
    ]);

    (new OCRRequestJob($documento))->handle();

    Http::assertSent(function ($request) use ($documento, $processo) {
        return str_contains($request->url(), '/documents/process')
            && $request['bucket_origem'] === 'bucket-origem-test'
            && $request['path_origem'] === $documento->path
            && $request['bucket_destino'] === 'bucket-destino-test'
            && $request['path_destino'] === "documentos-processos/{$processo->numero_processo}/{$documento->id_documento}.txt"
            && $request['webhook_url'] === 'http://app.test/api/ocr/webhook';
    });

    $documento->refresh();
    expect($documento->ocr_job_id)->toBe('uuid-test-123');
    expect($documento->ocr_enviado_fila)->toBeTrue();
});

it('redefine ocr_enviado_fila ao falhar definitivamente', function () {
    $processo = Processo::create([
        'numero_processo' => '0000002-00.2026.8.03.0001',
        'tribunal_id' => 1,
        'valor_causa' => '0.00',
    ]);

    $documento = ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => 9002,
        'mimetype' => 'application/pdf',
        'data_hora' => now(),
        'tipo_documento' => '0',
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'ocr_enviado_fila' => true,
    ]);

    $job = new OCRRequestJob($documento);
    $job->failed(new \Exception('falha simulada'));

    $documento->refresh();
    expect($documento->ocr_enviado_fila)->toBeFalse();
});
```

- [ ] **Step 2: Rodar o teste para confirmar falha**

```bash
php artisan test tests/Feature/Jobs/OCRRequestJobTest.php
```

Esperado: FAIL — `OCRRequestJob` ainda usa o endpoint legado.

- [ ] **Step 3: Substituir o `OCRRequestJob`**

Substituir o conteúdo de `app/Jobs/OCRRequestJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\ProcessoDocumento;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OCRRequestJob implements ShouldQueue
{
    use Queueable;

    public $documento;

    public function __construct(ProcessoDocumento $documento)
    {
        $this->documento = $documento;
    }

    public function handle(): void
    {
        $pathDestino = 'documentos-processos/'
            . $this->documento->processo->numero_processo
            . '/' . $this->documento->id_documento . '.txt';

        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization' => 'Bearer ' . config('services.sim_ocr.token'),
            ])
            ->post(config('services.sim_ocr.url') . '/documents/process', [
                'bucket_origem'  => config('services.sim_ocr.bucket_origem'),
                'path_origem'    => $this->documento->path,
                'bucket_destino' => config('services.sim_ocr.bucket_destino'),
                'path_destino'   => $pathDestino,
                'webhook_url'    => config('services.sim_ocr.webhook_url'),
            ]);

        if (!$response->successful()) {
            Log::error('Falha ao enviar documento ao microserviço OCR', [
                'documento_id' => $this->documento->id_documento,
                'status'       => $response->status(),
                'body'         => $response->body(),
            ]);
            throw new \RuntimeException('Microserviço OCR retornou HTTP ' . $response->status());
        }

        $this->documento->ocr_job_id     = $response->json('id');
        $this->documento->ocr_enviado_fila = true;
        $this->documento->save();
    }

    public function failed(\Throwable $exception): void
    {
        $this->documento->ocr_enviado_fila = false;
        $this->documento->save();
    }
}
```

- [ ] **Step 4: Adicionar `sim_ocr` ao arquivo de configuração de serviços**

Em `config/services.php`, adicionar a entrada `sim_ocr` (caso o arquivo não exista, criá-lo apenas com o conteúdo abaixo dentro do array `return`):

```php
'sim_ocr' => [
    'url'            => env('SIM_OCR_URL'),
    'token'          => env('SIM_OCR_API_TOKEN'),
    'bucket_origem'  => env('SIM_OCR_BUCKET_ORIGEM'),
    'bucket_destino' => env('SIM_OCR_BUCKET_DESTINO'),
    'webhook_url'    => env('SIM_OCR_WEBHOOK_URL'),
],
```

- [ ] **Step 5: Rodar o teste novamente**

```bash
php artisan test tests/Feature/Jobs/OCRRequestJobTest.php
```

Esperado: PASS (2 testes).

- [ ] **Step 6: Commitar**

```bash
git add app/Jobs/OCRRequestJob.php config/services.php tests/Feature/Jobs/OCRRequestJobTest.php
git commit -m "feat: atualiza OCRRequestJob para usar microserviço SIM OCR via S3"
```

---

## Task 3: Criar `OCRWebhookController` e rota

**Files:**
- Create: `app/Http/Controllers/Api/OCRWebhookController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Api/OCRWebhookControllerTest.php`

- [ ] **Step 1: Escrever os testes com falha esperada**

Criar `tests/Feature/Api/OCRWebhookControllerTest.php`:

```php
<?php

use App\Jobs\JuntarOCRProcessoJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

it('marca documento como processado ao receber webhook de sucesso', function () {
    Queue::fake();

    $processo = Processo::create([
        'numero_processo' => '0001001-00.2026.8.03.0001',
        'tribunal_id' => 1,
        'valor_causa' => '0.00',
        'knowledge_base_status_sync' => Processo::KNOWLEDGE_BASE_STATUS_STARTING,
    ]);

    $documento = ProcessoDocumento::create([
        'processo_id'    => $processo->id,
        'id_documento'   => 8001,
        'mimetype'       => 'application/pdf',
        'data_hora'      => now(),
        'tipo_documento' => '0',
        'status'         => ProcessoDocumento::STATUS_BAIXADO,
        'ocr_enviado_fila' => true,
        'ocr_processado' => false,
        'ocr_job_id'     => 'job-uuid-sucesso',
    ]);

    $response = $this->postJson('/api/ocr/webhook', [
        'status'       => 'success',
        'job_id'       => 'job-uuid-sucesso',
        'path_destino' => 'documentos-processos/0001001-00.2026.8.03.0001/8001.txt',
    ]);

    $response->assertStatus(200);

    $documento->refresh();
    expect($documento->ocr_processado)->toBeTrue();
    expect($documento->ocr_concluido_data)->not->toBeNull();
});

it('dispara JuntarOCRProcessoJob quando todos os documentos do processo estão prontos', function () {
    Queue::fake();

    $processo = Processo::create([
        'numero_processo' => '0002001-00.2026.8.03.0001',
        'tribunal_id' => 1,
        'valor_causa' => '0.00',
        'knowledge_base_status_sync' => Processo::KNOWLEDGE_BASE_STATUS_STARTING,
    ]);

    ProcessoDocumento::create([
        'processo_id'    => $processo->id,
        'id_documento'   => 8002,
        'mimetype'       => 'application/pdf',
        'data_hora'      => now(),
        'tipo_documento' => '0',
        'status'         => ProcessoDocumento::STATUS_BAIXADO,
        'ocr_processado' => true,
        'ocr_job_id'     => 'job-outro',
    ]);

    $docPendente = ProcessoDocumento::create([
        'processo_id'    => $processo->id,
        'id_documento'   => 8003,
        'mimetype'       => 'application/pdf',
        'data_hora'      => now(),
        'tipo_documento' => '0',
        'status'         => ProcessoDocumento::STATUS_BAIXADO,
        'ocr_enviado_fila' => true,
        'ocr_processado' => false,
        'ocr_job_id'     => 'job-uuid-ultimo',
    ]);

    $this->postJson('/api/ocr/webhook', [
        'status'  => 'success',
        'job_id'  => 'job-uuid-ultimo',
    ]);

    Queue::assertPushedOn('ocr', JuntarOCRProcessoJob::class);
});

it('retorna 404 para job_id desconhecido', function () {
    $response = $this->postJson('/api/ocr/webhook', [
        'status' => 'success',
        'job_id' => 'job-inexistente-xyz',
    ]);

    $response->assertStatus(404);
});

it('redefine ocr_enviado_fila ao receber webhook de erro', function () {
    Queue::fake();

    $processo = Processo::create([
        'numero_processo' => '0003001-00.2026.8.03.0001',
        'tribunal_id' => 1,
        'valor_causa' => '0.00',
    ]);

    $documento = ProcessoDocumento::create([
        'processo_id'    => $processo->id,
        'id_documento'   => 8004,
        'mimetype'       => 'application/pdf',
        'data_hora'      => now(),
        'tipo_documento' => '0',
        'status'         => ProcessoDocumento::STATUS_BAIXADO,
        'ocr_enviado_fila' => true,
        'ocr_processado' => false,
        'ocr_job_id'     => 'job-uuid-erro',
    ]);

    $response = $this->postJson('/api/ocr/webhook', [
        'status'       => 'error',
        'job_id'       => 'job-uuid-erro',
        'error_detail' => 'Timeout ao processar documento',
    ]);

    $response->assertStatus(200);

    $documento->refresh();
    expect($documento->ocr_enviado_fila)->toBeFalse();
    expect($documento->ocr_processado)->toBeFalse();
});
```

- [ ] **Step 2: Rodar os testes para confirmar falha**

```bash
php artisan test tests/Feature/Api/OCRWebhookControllerTest.php
```

Esperado: FAIL — rota não existe ainda.

- [ ] **Step 3: Criar o controller**

Criar `app/Http/Controllers/Api/OCRWebhookController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\JuntarOCRProcessoJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class OCRWebhookController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $jobId  = $request->input('job_id');
        $status = $request->input('status');

        $documento = ProcessoDocumento::where('ocr_job_id', $jobId)->first();

        if (!$documento) {
            return response()->json(['message' => 'Job não encontrado.'], 404);
        }

        if ($status === 'success') {
            $documento->ocr_processado    = true;
            $documento->ocr_concluido_data = now();
            $documento->save();

            $this->dispararJuncaoSeCompleto($documento);

            return response()->json(['message' => 'OK']);
        }

        if ($status === 'error') {
            Log::error('OCR falhou no microserviço SIM OCR', [
                'job_id'       => $jobId,
                'documento_id' => $documento->id_documento,
                'error_detail' => $request->input('error_detail'),
            ]);

            $documento->ocr_enviado_fila = false;
            $documento->save();

            return response()->json(['message' => 'OK']);
        }

        return response()->json(['message' => 'Status desconhecido.'], 422);
    }

    private function dispararJuncaoSeCompleto(ProcessoDocumento $documento): void
    {
        $processo = $documento->processo;

        if (!$processo) {
            return;
        }

        $totalDocumentos = $processo->documentos()
            ->whereIn('mimetype', ['application/pdf', 'text/html'])
            ->where('status', ProcessoDocumento::STATUS_BAIXADO)
            ->count();

        $documentosProcessados = $processo->documentos()
            ->where('ocr_processado', true)
            ->count();

        if ($totalDocumentos > 0 && $documentosProcessados >= $totalDocumentos) {
            $processo->refresh();

            if ($processo->knowledge_base_status_sync !== Processo::KNOWLEDGE_BASE_STATUS_STARTING) {
                return;
            }

            Log::info('Todos os documentos processados, disparando JuntarOCRProcessoJob', [
                'processo' => $processo->numero_processo,
            ]);

            JuntarOCRProcessoJob::dispatch($processo)->onQueue('ocr');
        }
    }
}
```

- [ ] **Step 4: Registrar a rota (fora do grupo `ValidateApiToken`)**

Em `routes/api.php`, adicionar o import e a rota ANTES do grupo `Route::middleware(ValidateApiToken::class)`:

```php
use App\Http\Controllers\Api\OCRWebhookController;

// Webhook do microserviço SIM OCR (sem autenticação de usuário)
Route::post('/ocr/webhook', [OCRWebhookController::class, 'store']);
```

- [ ] **Step 5: Rodar os testes novamente**

```bash
php artisan test tests/Feature/Api/OCRWebhookControllerTest.php
```

Esperado: PASS (4 testes).

- [ ] **Step 6: Commitar**

```bash
git add app/Http/Controllers/Api/OCRWebhookController.php routes/api.php
git add tests/Feature/Api/OCRWebhookControllerTest.php
git commit -m "feat: cria OCRWebhookController e rota POST /api/ocr/webhook"
```

---

## Task 4: Atualizar `OCRDocumentoController` — trocar job

**Files:**
- Modify: `app/Http/Controllers/Api/OCRDocumentoController.php`

- [ ] **Step 1: Substituir `OCRDocumentoJob` por `OCRRequestJob` no controller**

Substituir o conteúdo de `app/Http/Controllers/Api/OCRDocumentoController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\OCRRequestJob;
use Illuminate\Http\Request;

class OCRDocumentoController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'documento_id' => 'required|integer|exists:processo_documentos,id_documento',
        ]);

        $documentos = \App\Models\ProcessoDocumento::where('id_documento', $request->input('documento_id'))->get();

        foreach ($documentos as $documento) {
            OCRRequestJob::dispatch($documento)->onQueue('ocr-request');
        }

        return response()->json(['message' => 'Job de OCR enfileirado com sucesso.'], 200);
    }
}
```

- [ ] **Step 2: Rodar todos os testes para garantir que não houve regressão**

```bash
php artisan test
```

Esperado: todos PASS.

- [ ] **Step 3: Commitar**

```bash
git add app/Http/Controllers/Api/OCRDocumentoController.php
git commit -m "feat: OCRDocumentoController passa a usar OCRRequestJob"
```

---

## Task 5: Remover código morto — Tesseract, jobs, serviços, Docker

**Files:**
- Delete: `app/Jobs/OCRDocumentoJob.php`
- Delete: `app/Services/Processo/OCRDocumentoService.php`
- Delete: `app/Console/Commands/DiagnoseOCRPerformance.php`
- Modify: `composer.json`
- Modify: `docker/Dockerfile`
- Modify: `docker/prod/8.4/Dockerfile`

- [ ] **Step 1: Deletar os arquivos de código morto**

```bash
rm app/Jobs/OCRDocumentoJob.php
rm app/Services/Processo/OCRDocumentoService.php
rm app/Console/Commands/DiagnoseOCRPerformance.php
```

- [ ] **Step 2: Remover dependência Tesseract do `composer.json`**

Em `composer.json`, remover a linha (linha 26):
```json
"thiagoalessio/tesseract_ocr": "^2.13",
```

Depois rodar:
```bash
composer update --no-scripts
```

Esperado: pacote removido sem erros.

- [ ] **Step 3: Remover pacotes Tesseract/Poppler do Dockerfile de dev**

Em `docker/Dockerfile`, remover as linhas:
```
    tesseract-ocr \
    tesseract-ocr-por \
    poppler-utils \
    ghostscript
```

(Atenção: verificar se alguma outra linha usa `\` e precisa ser ajustada para não quebrar o bloco RUN.)

- [ ] **Step 4: Remover pacotes Tesseract/Poppler do Dockerfile de produção**

Em `docker/prod/8.4/Dockerfile`, remover as linhas:
```
    tesseract-ocr \
    tesseract-ocr-data-por \
    poppler-utils \
    ghostscript \
```

(Atenção: a linha anterior a `tesseract-ocr` usa `\` — ajustar para que o bloco `apk add` continue válido.)

- [ ] **Step 5: Rodar todos os testes para garantir que nenhum arquivo removido é referenciado**

```bash
php artisan test
```

Esperado: todos PASS.

- [ ] **Step 6: Commitar**

```bash
git add -u
git commit -m "chore: remove OCRDocumentoJob, OCRDocumentoService, Tesseract e Poppler"
```

---

## Task 6: Variáveis de ambiente — `.env.example`

**Files:**
- Modify: `.env.example`

- [ ] **Step 1: Adicionar as novas variáveis ao `.env.example`**

Ao final do arquivo `.env.example`, adicionar:

```
# Microserviço SIM OCR
SIM_OCR_URL=http://ocr.mpap.private:8000
SIM_OCR_API_TOKEN=
SIM_OCR_BUCKET_ORIGEM=
SIM_OCR_BUCKET_DESTINO=
SIM_OCR_WEBHOOK_URL=
```

- [ ] **Step 2: Commitar**

```bash
git add .env.example
git commit -m "chore: documenta variáveis de ambiente do microserviço SIM OCR"
```

---

## Checklist pós-implementação

Antes do deploy em produção:

- [ ] Cadastrar bucket origem no painel admin do SIM OCR (`http://ocr.mpap.private:8000/admin` > Buckets) e testar conexão
- [ ] Cadastrar bucket destino no mesmo painel e testar conexão
- [ ] Preencher todas as variáveis `SIM_OCR_*` no ambiente de produção
- [ ] Verificar que `SIM_OCR_WEBHOOK_URL` é acessível pelo microserviço (rede interna)
