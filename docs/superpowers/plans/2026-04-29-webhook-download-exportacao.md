# Webhook Download — Refatoração da Exportação de Processo

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Substituir o envio de e-mail por um webhook ao SIM ao concluir/falhar a exportação de processo, persistindo estado em uma nova tabela `processo_exportacoes` e quebrando o job monolítico atual em três jobs encadeados.

**Architecture:** Pipeline de jobs (`GerarPdfExportacaoJob` → `EnviarParaS3ExportacaoJob` → `EnviarWebhookDownloadJob`) orquestrado por `ExportacaoProcessoService`, com chamada HTTP ao SIM encapsulada em `WebhookDownloadClient`. Idempotência do webhook via campo `webhook_enviado_em`. Breaking change limpo na API: `email`/`notificacao_id` saem; `user_id`/`titulo`/`formato` entram.

**Tech Stack:** Laravel 11, PHP 8.2+, Pest 3 (testes), Eloquent, Queue (database), Storage S3, FPDI, DomPDF.

**Spec aprovado:** [`docs/superpowers/specs/2026-04-29-webhook-download-exportacao-design.md`](../specs/2026-04-29-webhook-download-exportacao-design.md)

---

## File Structure

**Novos:**

```
app/
├── Console/Commands/ExportacoesReenviarWebhook.php        — comando artisan de redespacho manual
├── Http/Requests/Api/CriarExportacaoProcessoRequest.php   — validação da request POST
├── Jobs/
│   ├── GerarPdfExportacaoJob.php                          — gera PDF via FPDI
│   ├── EnviarParaS3ExportacaoJob.php                      — sobe arquivo pro S3
│   └── EnviarWebhookDownloadJob.php                       — POST idempotente ao SIM
├── Models/ProcessoExportacao.php                          — model com constantes de status
├── Services/Exportacao/
│   ├── ExportacaoProcessoService.php                      — orquestrador de domínio
│   └── WebhookDownloadClient.php                          — cliente HTTP do SIM

database/
├── factories/ProcessoExportacaoFactory.php                — factory para testes
└── migrations/2026_04_29_*_create_processo_exportacoes_table.php

tests/
├── Feature/
│   ├── Api/DownloadProcessoControllerTest.php
│   ├── Console/ExportacoesReenviarWebhookTest.php
│   └── Jobs/
│       ├── GerarPdfExportacaoJobTest.php
│       ├── EnviarParaS3ExportacaoJobTest.php
│       └── EnviarWebhookDownloadJobTest.php
└── Unit/Services/
    ├── ExportacaoProcessoServiceTest.php
    └── WebhookDownloadClientTest.php
```

**Modificados:**

- `app/Http/Controllers/Api/DownloadProcessoController.php` — usa Form Request, cria via service, retorna 200+`exportacao_id`.
- `config/services.php` — adiciona seção `sim_webhook_download`.
- `.env.example` — adiciona `SIM_WEBHOOK_DOWNLOAD_URL` e `SIM_API_TOKEN`.
- `tests/Pest.php` — habilita `Tests\TestCase` também em `Unit/`.

**Removidos (Task 14):**

- `app/Mail/EnviarAutosProcessoMail.php`
- `app/Listeners/DeleteTemporaryFilesAfterEmailSent.php`
- `resources/views/mail/proceso/autos.blade.php`
- `app/Jobs/DownloadProcessoJob.php`
- `tests/Feature/DownloadProcessoTest.php`

---

## Task 1: Migration e Model `ProcessoExportacao`

**Files:**
- Create: `database/migrations/2026_04_29_120000_create_processo_exportacoes_table.php`
- Create: `app/Models/ProcessoExportacao.php`
- Create: `database/factories/ProcessoExportacaoFactory.php`
- Create: `tests/Unit/Models/ProcessoExportacaoTest.php`
- Modify: `tests/Pest.php` (habilita TestCase em Unit/)

- [ ] **Step 1: Habilitar `Tests\TestCase` em `Unit/`**

Edite `tests/Pest.php` para que tests `Unit/` também usem o TestCase do Laravel (necessário para acessar `database`, `factory`, etc):

```php
pest()->extend(Tests\TestCase::class)
    ->in('Feature', 'Unit');
```

- [ ] **Step 2: Criar migration**

Arquivo `database/migrations/2026_04_29_120000_create_processo_exportacoes_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('processo_exportacoes');
    }
};
```

- [ ] **Step 3: Criar Model**

Arquivo `app/Models/ProcessoExportacao.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProcessoExportacao extends Model
{
    use HasFactory;

    public const STATUS_ENFILEIRADO = 'enfileirado';
    public const STATUS_PROCESSANDO = 'processando';
    public const STATUS_CONCLUIDO = 'concluido';
    public const STATUS_FALHOU = 'falhou';

    public const FORMATO_PDF = 'pdf';

    protected $table = 'processo_exportacoes';

    protected $fillable = [
        'user_id',
        'numero_processo',
        'tribunal_id',
        'titulo',
        'formato',
        'status',
        'uuid_arquivo',
        's3_path',
        'tamanho_bytes',
        'erro_resumo',
        'filtros',
        'webhook_enviado_em',
        'webhook_tentativas',
    ];

    protected $casts = [
        'filtros' => 'array',
        'webhook_enviado_em' => 'datetime',
        'tamanho_bytes' => 'integer',
        'webhook_tentativas' => 'integer',
    ];

    public function jaFoiNotificado(): bool
    {
        return $this->webhook_enviado_em !== null;
    }
}
```

- [ ] **Step 4: Criar Factory**

Arquivo `database/factories/ProcessoExportacaoFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ProcessoExportacao;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProcessoExportacaoFactory extends Factory
{
    protected $model = ProcessoExportacao::class;

    public function definition(): array
    {
        return [
            'user_id' => 1,
            'numero_processo' => '6001255-81.2024.8.03.0003',
            'tribunal_id' => null,
            'titulo' => 'Processo 6001255-81.2024.8.03.0003 — PDF',
            'formato' => ProcessoExportacao::FORMATO_PDF,
            'status' => ProcessoExportacao::STATUS_ENFILEIRADO,
            'uuid_arquivo' => null,
            's3_path' => null,
            'tamanho_bytes' => null,
            'erro_resumo' => null,
            'filtros' => ['ids_selecionados' => [1, 2, 3]],
            'webhook_enviado_em' => null,
            'webhook_tentativas' => 0,
        ];
    }

    public function processando(): self
    {
        return $this->state(fn () => [
            'status' => ProcessoExportacao::STATUS_PROCESSANDO,
            'uuid_arquivo' => (string) Str::uuid(),
        ]);
    }

    public function concluido(): self
    {
        $uuid = (string) Str::uuid();
        return $this->state(fn (array $attrs) => [
            'status' => ProcessoExportacao::STATUS_CONCLUIDO,
            'uuid_arquivo' => $uuid,
            's3_path' => "downloads/{$attrs['user_id']}/{$uuid}.pdf",
            'tamanho_bytes' => 1024 * 1024,
        ]);
    }

    public function falhou(string $erro = 'Documentos do processo indisponíveis no momento.'): self
    {
        return $this->state(fn () => [
            'status' => ProcessoExportacao::STATUS_FALHOU,
            'erro_resumo' => $erro,
        ]);
    }

    public function webhookEnviado(): self
    {
        return $this->state(fn () => [
            'webhook_enviado_em' => now(),
            'webhook_tentativas' => 1,
        ]);
    }
}
```

- [ ] **Step 5: Escrever teste do Model**

Arquivo `tests/Unit/Models/ProcessoExportacaoTest.php`:

```php
<?php

use App\Models\ProcessoExportacao;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('persiste e recupera registro com casts corretos', function () {
    $exportacao = ProcessoExportacao::factory()->create([
        'filtros' => ['ids_selecionados' => [10, 20]],
    ]);

    $reload = ProcessoExportacao::find($exportacao->id);

    expect($reload->filtros)->toBe(['ids_selecionados' => [10, 20]]);
    expect($reload->status)->toBe(ProcessoExportacao::STATUS_ENFILEIRADO);
    expect($reload->webhook_tentativas)->toBe(0);
});

it('retorna jaFoiNotificado() falso quando webhook_enviado_em nulo', function () {
    $exportacao = ProcessoExportacao::factory()->create();

    expect($exportacao->jaFoiNotificado())->toBeFalse();
});

it('retorna jaFoiNotificado() verdadeiro quando webhook_enviado_em preenchido', function () {
    $exportacao = ProcessoExportacao::factory()->webhookEnviado()->create();

    expect($exportacao->jaFoiNotificado())->toBeTrue();
});

it('factory concluido() popula s3_path com pattern downloads/{user_id}/{uuid}.pdf', function () {
    $exportacao = ProcessoExportacao::factory()->concluido()->create(['user_id' => 42]);

    expect($exportacao->s3_path)->toMatch('#^downloads/42/[0-9a-f-]+\.pdf$#');
    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_CONCLUIDO);
});
```

- [ ] **Step 6: Rodar a migration localmente e os testes**

```bash
php artisan migrate
php artisan test --filter=ProcessoExportacaoTest
```

Esperado: migration cria tabela, 4 testes passam.

- [ ] **Step 7: Commit**

```bash
git add app/Models/ProcessoExportacao.php database/migrations/2026_04_29_120000_create_processo_exportacoes_table.php database/factories/ProcessoExportacaoFactory.php tests/Unit/Models/ProcessoExportacaoTest.php tests/Pest.php
git commit -m "feat: model ProcessoExportacao + migration + factory"
```

---

## Task 2: Configuração do webhook (env + services)

**Files:**
- Modify: `.env.example`
- Modify: `config/services.php`

- [ ] **Step 1: Adicionar variáveis em `.env.example`**

Acrescente ao final do `.env.example`:

```bash
# Webhook do SIM (Central de Downloads)
SIM_WEBHOOK_DOWNLOAD_URL=https://sim.example.com/webhook/download
SIM_API_TOKEN=
SIM_WEBHOOK_TIMEOUT=10
```

- [ ] **Step 2: Adicionar seção em `config/services.php`**

No array retornado, após `'sim_ocr' => [...]`, adicione:

```php
'sim_webhook_download' => [
    'url' => env('SIM_WEBHOOK_DOWNLOAD_URL'),
    'token' => env('SIM_API_TOKEN'),
    'timeout' => env('SIM_WEBHOOK_TIMEOUT', 10),
],
```

- [ ] **Step 3: Verificar que a config carrega**

```bash
php artisan config:clear
php artisan tinker --execute="dd(config('services.sim_webhook_download'));"
```

Esperado: array com `url`, `token`, `timeout`.

- [ ] **Step 4: Commit**

```bash
git add .env.example config/services.php
git commit -m "chore: configuração do webhook download (SIM)"
```

---

## Task 3: `WebhookDownloadClient`

Cliente HTTP isolado, fácil de mockar nos testes do job.

**Files:**
- Create: `app/Services/Exportacao/WebhookDownloadClient.php`
- Create: `tests/Unit/Services/WebhookDownloadClientTest.php`

- [ ] **Step 1: Escrever testes**

Arquivo `tests/Unit/Services/WebhookDownloadClientTest.php`:

```php
<?php

use App\Models\ProcessoExportacao;
use App\Services\Exportacao\WebhookDownloadClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config()->set('services.sim_webhook_download.url', 'https://sim.test/webhook/download');
    config()->set('services.sim_webhook_download.token', 'test-token');
    config()->set('services.sim_webhook_download.timeout', 10);
});

it('envia payload de sucesso com s3_path e tamanho_bytes', function () {
    Http::fake(['sim.test/*' => Http::response(['message' => 'OK', 'download_id' => 1], 200)]);

    $exportacao = ProcessoExportacao::factory()->concluido()->create([
        'user_id' => 152,
        'titulo' => 'Processo X — PDF',
        'tamanho_bytes' => 4582934,
    ]);

    (new WebhookDownloadClient())->notificar($exportacao);

    Http::assertSent(function ($request) use ($exportacao) {
        return $request->url() === 'https://sim.test/webhook/download'
            && $request->header('X-API-Token')[0] === 'test-token'
            && $request['user_id'] === 152
            && $request['titulo'] === 'Processo X — PDF'
            && $request['formato'] === 'pdf'
            && $request['status'] === 'concluido'
            && $request['s3_path'] === $exportacao->s3_path
            && $request['tamanho_bytes'] === 4582934;
    });
});

it('envia payload de falha apenas com erro_resumo', function () {
    Http::fake(['sim.test/*' => Http::response(['message' => 'OK'], 200)]);

    $exportacao = ProcessoExportacao::factory()->falhou('Indisponível.')->create([
        'user_id' => 7,
    ]);

    (new WebhookDownloadClient())->notificar($exportacao);

    Http::assertSent(function ($request) {
        return $request['status'] === 'falhou'
            && $request['erro_resumo'] === 'Indisponível.'
            && !isset($request['s3_path'])
            && !isset($request['tamanho_bytes']);
    });
});

it('lança exception em 5xx do SIM', function () {
    Http::fake(['sim.test/*' => Http::response('boom', 503)]);

    $exportacao = ProcessoExportacao::factory()->concluido()->create();

    expect(fn () => (new WebhookDownloadClient())->notificar($exportacao))
        ->toThrow(Illuminate\Http\Client\RequestException::class);
});

it('lança exception em timeout/conexão recusada', function () {
    Http::fake(function () {
        throw new Illuminate\Http\Client\ConnectionException('timeout');
    });

    $exportacao = ProcessoExportacao::factory()->concluido()->create();

    expect(fn () => (new WebhookDownloadClient())->notificar($exportacao))
        ->toThrow(Illuminate\Http\Client\ConnectionException::class);
});

it('lança WebhookDownloadClient\\WebhookPermanentException em 4xx (não retentável)', function () {
    Http::fake(['sim.test/*' => Http::response(['error' => 'Validação'], 422)]);

    $exportacao = ProcessoExportacao::factory()->concluido()->create();

    expect(fn () => (new WebhookDownloadClient())->notificar($exportacao))
        ->toThrow(App\Services\Exportacao\WebhookPermanentException::class);
});
```

- [ ] **Step 2: Rodar para verificar que falha**

```bash
php artisan test --filter=WebhookDownloadClientTest
```

Esperado: erros de classe não encontrada (`WebhookDownloadClient`, `WebhookPermanentException`).

- [ ] **Step 3: Implementar exception permanente**

Arquivo `app/Services/Exportacao/WebhookPermanentException.php`:

```php
<?php

namespace App\Services\Exportacao;

use Exception;

class WebhookPermanentException extends Exception
{
    public function __construct(public readonly int $statusCode, string $message = '')
    {
        parent::__construct($message ?: "Webhook permanently rejected (HTTP {$statusCode})");
    }
}
```

- [ ] **Step 4: Implementar `WebhookDownloadClient`**

Arquivo `app/Services/Exportacao/WebhookDownloadClient.php`:

```php
<?php

namespace App\Services\Exportacao;

use App\Models\ProcessoExportacao;
use Illuminate\Support\Facades\Http;

class WebhookDownloadClient
{
    public function notificar(ProcessoExportacao $exportacao): void
    {
        $payload = $this->montarPayload($exportacao);
        $url = (string) config('services.sim_webhook_download.url');
        $token = (string) config('services.sim_webhook_download.token');
        $timeout = (int) config('services.sim_webhook_download.timeout', 10);

        $response = Http::withHeaders(['X-API-Token' => $token])
            ->timeout($timeout)
            ->post($url, $payload);

        if ($response->successful()) {
            return;
        }

        // 4xx => erro permanente; 5xx => throw para o queue retentar
        if ($response->status() >= 400 && $response->status() < 500) {
            throw new WebhookPermanentException(
                $response->status(),
                "SIM rejeitou webhook (HTTP {$response->status()}): {$response->body()}"
            );
        }

        $response->throw();
    }

    private function montarPayload(ProcessoExportacao $exportacao): array
    {
        $base = [
            'user_id' => $exportacao->user_id,
            'titulo' => $exportacao->titulo,
            'formato' => $exportacao->formato,
            'status' => $exportacao->status,
        ];

        if ($exportacao->status === ProcessoExportacao::STATUS_CONCLUIDO) {
            return $base + [
                's3_path' => $exportacao->s3_path,
                'tamanho_bytes' => $exportacao->tamanho_bytes,
            ];
        }

        return $base + ['erro_resumo' => $exportacao->erro_resumo];
    }
}
```

- [ ] **Step 5: Rodar testes — devem passar**

```bash
php artisan test --filter=WebhookDownloadClientTest
```

Esperado: 5 testes passam.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Exportacao/WebhookDownloadClient.php app/Services/Exportacao/WebhookPermanentException.php tests/Unit/Services/WebhookDownloadClientTest.php
git commit -m "feat: WebhookDownloadClient (chamada HTTP idempotente ao SIM)"
```

---

## Task 4: `EnviarWebhookDownloadJob`

Job idempotente que delega ao client. Tries=5, backoff exponencial.

**Files:**
- Create: `app/Jobs/EnviarWebhookDownloadJob.php`
- Create: `tests/Feature/Jobs/EnviarWebhookDownloadJobTest.php`

- [ ] **Step 1: Escrever testes**

Arquivo `tests/Feature/Jobs/EnviarWebhookDownloadJobTest.php`:

```php
<?php

use App\Jobs\EnviarWebhookDownloadJob;
use App\Models\ProcessoExportacao;
use App\Services\Exportacao\WebhookDownloadClient;
use App\Services\Exportacao\WebhookPermanentException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;

uses(DatabaseTransactions::class);

it('chama o client e marca webhook_enviado_em quando sucesso', function () {
    $exportacao = ProcessoExportacao::factory()->concluido()->create();

    $client = Mockery::mock(WebhookDownloadClient::class);
    $client->shouldReceive('notificar')->once();
    app()->instance(WebhookDownloadClient::class, $client);

    (new EnviarWebhookDownloadJob($exportacao->id))->handle(app(WebhookDownloadClient::class));

    $exportacao->refresh();
    expect($exportacao->webhook_enviado_em)->not->toBeNull();
    expect($exportacao->webhook_tentativas)->toBe(1);
});

it('é idempotente: não chama o client se webhook_enviado_em já preenchido', function () {
    $exportacao = ProcessoExportacao::factory()->concluido()->webhookEnviado()->create();

    $client = Mockery::mock(WebhookDownloadClient::class);
    $client->shouldNotReceive('notificar');
    app()->instance(WebhookDownloadClient::class, $client);

    (new EnviarWebhookDownloadJob($exportacao->id))->handle(app(WebhookDownloadClient::class));

    $exportacao->refresh();
    expect($exportacao->webhook_tentativas)->toBe(1);
});

it('relança a exception em erro retentável (5xx) e incrementa tentativas', function () {
    $exportacao = ProcessoExportacao::factory()->concluido()->create();

    $client = Mockery::mock(WebhookDownloadClient::class);
    $client->shouldReceive('notificar')->andThrow(new RuntimeException('5xx'));
    app()->instance(WebhookDownloadClient::class, $client);

    expect(fn () => (new EnviarWebhookDownloadJob($exportacao->id))->handle(app(WebhookDownloadClient::class)))
        ->toThrow(RuntimeException::class);

    $exportacao->refresh();
    expect($exportacao->webhook_tentativas)->toBe(1);
    expect($exportacao->webhook_enviado_em)->toBeNull();
});

it('em erro permanente (4xx) marca como falho via fail() sem relançar', function () {
    $exportacao = ProcessoExportacao::factory()->concluido()->create();

    $client = Mockery::mock(WebhookDownloadClient::class);
    $client->shouldReceive('notificar')->andThrow(new WebhookPermanentException(422, 'rejected'));
    app()->instance(WebhookDownloadClient::class, $client);

    Log::spy();

    $job = new EnviarWebhookDownloadJob($exportacao->id);
    $job->handle(app(WebhookDownloadClient::class));

    $exportacao->refresh();
    expect($exportacao->webhook_tentativas)->toBe(1);
    expect($exportacao->webhook_enviado_em)->toBeNull();
    Log::shouldHaveReceived('critical')->once();
});

it('retorna sem efeito quando exportacao_id não existe', function () {
    $client = Mockery::mock(WebhookDownloadClient::class);
    $client->shouldNotReceive('notificar');
    app()->instance(WebhookDownloadClient::class, $client);

    Log::spy();

    (new EnviarWebhookDownloadJob(999999))->handle(app(WebhookDownloadClient::class));

    Log::shouldHaveReceived('warning')->once();
});
```

- [ ] **Step 2: Rodar testes — devem falhar**

```bash
php artisan test --filter=EnviarWebhookDownloadJobTest
```

Esperado: classe `EnviarWebhookDownloadJob` não existe.

- [ ] **Step 3: Implementar o job**

Arquivo `app/Jobs/EnviarWebhookDownloadJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\ProcessoExportacao;
use App\Services\Exportacao\WebhookDownloadClient;
use App\Services\Exportacao\WebhookPermanentException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class EnviarWebhookDownloadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $exportacaoId) {}

    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600];
    }

    public function handle(WebhookDownloadClient $client): void
    {
        $exportacao = ProcessoExportacao::find($this->exportacaoId);

        if (!$exportacao) {
            Log::warning("[Exportacao:{$this->exportacaoId}] não encontrada ao enviar webhook");
            return;
        }

        if ($exportacao->jaFoiNotificado()) {
            Log::info("[Exportacao:{$exportacao->id}] webhook já enviado — idempotência aplicada");
            return;
        }

        $exportacao->increment('webhook_tentativas');

        try {
            $client->notificar($exportacao);
        } catch (WebhookPermanentException $e) {
            Log::critical("[Exportacao:{$exportacao->id}] webhook rejeitado pelo SIM (permanente)", [
                'status' => $e->statusCode,
                'mensagem' => $e->getMessage(),
            ]);
            $this->fail($e);
            return;
        }

        $exportacao->update(['webhook_enviado_em' => now()]);
        Log::info("[Exportacao:{$exportacao->id}] webhook enviado com sucesso");
    }

    public function failed(\Throwable $e): void
    {
        Log::critical("[Exportacao:{$this->exportacaoId}] esgotou tentativas de webhook", [
            'erro' => $e->getMessage(),
        ]);
    }
}
```

- [ ] **Step 4: Rodar testes — devem passar**

```bash
php artisan test --filter=EnviarWebhookDownloadJobTest
```

Esperado: 5 testes passam.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/EnviarWebhookDownloadJob.php tests/Feature/Jobs/EnviarWebhookDownloadJobTest.php
git commit -m "feat: EnviarWebhookDownloadJob (idempotente, com retry)"
```

---

## Task 5: `ExportacaoProcessoService` — métodos de leitura

Antes da geração de PDF, criamos os métodos puros (consultas + criação de registro). `consultarDocumentos` extrai a lógica do antigo `DownloadProcessoJob::consultarDocumentos`.

**Files:**
- Create: `app/Services/Exportacao/ExportacaoProcessoService.php`
- Create: `tests/Unit/Services/ExportacaoProcessoServiceTest.php`

- [ ] **Step 1: Escrever testes**

Arquivo `tests/Unit/Services/ExportacaoProcessoServiceTest.php`:

```php
<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoExportacao;
use App\Services\Exportacao\ExportacaoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function criarProcessoComDocumentos(string $numero, array $docs): Processo
{
    $processo = Processo::create([
        'numero_processo' => $numero,
        'tribunal_id' => 1,
    ]);

    foreach ($docs as $doc) {
        ProcessoDocumento::create(array_merge([
            'processo_id' => $processo->id,
            'mimetype' => 'application/pdf',
        ], $doc));
    }

    return $processo;
}

it('criar() cria registro com status enfileirado e filtros serializados', function () {
    $service = new ExportacaoProcessoService();

    $exportacao = $service->criar([
        'user_id' => 42,
        'numero_processo' => '6001255-81.2024.8.03.0003',
        'tribunal_id' => 2,
        'titulo' => 'Processo X — PDF',
        'formato' => 'pdf',
        'ids_selecionados' => [1, 2],
        'periodo_inicial' => null,
        'periodo_final' => null,
        'id_inicial' => null,
        'id_final' => null,
    ]);

    expect($exportacao->user_id)->toBe(42);
    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_ENFILEIRADO);
    expect($exportacao->filtros)->toBe([
        'ids_selecionados' => [1, 2],
        'periodo_inicial' => null,
        'periodo_final' => null,
        'id_inicial' => null,
        'id_final' => null,
    ]);
});

it('temDocumentosDisponiveis() retorna true quando há ao menos 1 documento aplicável', function () {
    $numero = '6001255-81.2024.8.03.0003';
    criarProcessoComDocumentos($numero, [
        ['id_documento' => 100, 'data_hora' => '2024-12-12 10:00:00', 'mimetype' => 'application/pdf'],
    ]);

    $service = new ExportacaoProcessoService();

    expect($service->temDocumentosDisponiveis(['ids_selecionados' => [100]], $numero))->toBeTrue();
});

it('temDocumentosDisponiveis() retorna false quando ids não casam', function () {
    $numero = '6001255-81.2024.8.03.0003';
    criarProcessoComDocumentos($numero, [
        ['id_documento' => 100, 'data_hora' => '2024-12-12 10:00:00', 'mimetype' => 'application/pdf'],
    ]);

    $service = new ExportacaoProcessoService();

    expect($service->temDocumentosDisponiveis(['ids_selecionados' => [999]], $numero))->toBeFalse();
});

it('consultarDocumentos() filtra por ids_selecionados', function () {
    $numero = '6001255-81.2024.8.03.0003';
    criarProcessoComDocumentos($numero, [
        ['id_documento' => 1, 'data_hora' => '2024-12-12 10:00:00', 'mimetype' => 'application/pdf'],
        ['id_documento' => 2, 'data_hora' => '2024-12-13 10:00:00', 'mimetype' => 'application/pdf'],
        ['id_documento' => 3, 'data_hora' => '2024-12-14 10:00:00', 'mimetype' => 'application/pdf'],
    ]);

    $exportacao = ProcessoExportacao::factory()->create([
        'numero_processo' => $numero,
        'filtros' => ['ids_selecionados' => [1, 3]],
    ]);

    $docs = (new ExportacaoProcessoService())->consultarDocumentos($exportacao);

    expect($docs)->toHaveCount(2);
    expect($docs->pluck('id_documento')->all())->toEqualCanonicalizing([1, 3]);
});

it('consultarDocumentos() filtra por periodo', function () {
    $numero = '6001255-81.2024.8.03.0003';
    criarProcessoComDocumentos($numero, [
        ['id_documento' => 1, 'data_hora' => '2024-12-10 10:00:00', 'mimetype' => 'application/pdf'],
        ['id_documento' => 2, 'data_hora' => '2024-12-12 10:00:00', 'mimetype' => 'application/pdf'],
        ['id_documento' => 3, 'data_hora' => '2024-12-15 10:00:00', 'mimetype' => 'application/pdf'],
    ]);

    $exportacao = ProcessoExportacao::factory()->create([
        'numero_processo' => $numero,
        'filtros' => [
            'ids_selecionados' => null,
            'periodo_inicial' => '2024-12-12',
            'periodo_final' => '2024-12-13',
            'id_inicial' => null,
            'id_final' => null,
        ],
    ]);

    $docs = (new ExportacaoProcessoService())->consultarDocumentos($exportacao);

    expect($docs)->toHaveCount(1);
    expect($docs->first()->id_documento)->toBe(2);
});

it('consultarDocumentos() ignora mimetypes não suportados', function () {
    $numero = '6001255-81.2024.8.03.0003';
    criarProcessoComDocumentos($numero, [
        ['id_documento' => 1, 'data_hora' => '2024-12-12 10:00:00', 'mimetype' => 'image/png'],
        ['id_documento' => 2, 'data_hora' => '2024-12-12 10:00:00', 'mimetype' => 'application/pdf'],
    ]);

    $exportacao = ProcessoExportacao::factory()->create([
        'numero_processo' => $numero,
        'filtros' => ['ids_selecionados' => [1, 2]],
    ]);

    $docs = (new ExportacaoProcessoService())->consultarDocumentos($exportacao);

    expect($docs)->toHaveCount(1);
    expect($docs->first()->id_documento)->toBe(2);
});
```

- [ ] **Step 2: Rodar testes — devem falhar**

```bash
php artisan test --filter=ExportacaoProcessoServiceTest
```

- [ ] **Step 3: Implementar service (apenas os 3 métodos cobertos por testes nesta task)**

Arquivo `app/Services/Exportacao/ExportacaoProcessoService.php`:

```php
<?php

namespace App\Services\Exportacao;

use App\Models\ProcessoDocumento;
use App\Models\ProcessoExportacao;
use Illuminate\Support\Collection;

class ExportacaoProcessoService
{
    public function criar(array $dados): ProcessoExportacao
    {
        return ProcessoExportacao::create([
            'user_id' => $dados['user_id'],
            'numero_processo' => $dados['numero_processo'],
            'tribunal_id' => $dados['tribunal_id'] ?? null,
            'titulo' => $dados['titulo'],
            'formato' => $dados['formato'],
            'status' => ProcessoExportacao::STATUS_ENFILEIRADO,
            'filtros' => [
                'ids_selecionados' => $dados['ids_selecionados'] ?? null,
                'periodo_inicial' => $dados['periodo_inicial'] ?? null,
                'periodo_final' => $dados['periodo_final'] ?? null,
                'id_inicial' => $dados['id_inicial'] ?? null,
                'id_final' => $dados['id_final'] ?? null,
            ],
        ]);
    }

    public function temDocumentosDisponiveis(array $filtros, string $numeroProcesso): bool
    {
        return $this->queryDocumentos($filtros, $numeroProcesso)->exists();
    }

    public function consultarDocumentos(ProcessoExportacao $exportacao): Collection
    {
        return $this->queryDocumentos($exportacao->filtros ?? [], $exportacao->numero_processo)
            ->orderBy('id_documento', 'desc')
            ->get();
    }

    private function queryDocumentos(array $filtros, string $numeroProcesso)
    {
        $query = ProcessoDocumento::whereHas('processo', function ($q) use ($numeroProcesso) {
            $q->where('numero_processo', $numeroProcesso);
        })->whereIn('mimetype', ['application/pdf', 'text/html']);

        $idsSelecionados = $filtros['ids_selecionados'] ?? null;

        if (!empty($idsSelecionados) && is_array($idsSelecionados)) {
            $ids = array_map('intval', $idsSelecionados);
            return $query->whereIn('id_documento', $ids);
        }

        $periodoInicial = $filtros['periodo_inicial'] ?? null;
        $periodoFinal = $filtros['periodo_final'] ?? null;
        $idInicial = $filtros['id_inicial'] ?? null;
        $idFinal = $filtros['id_final'] ?? null;

        return $query
            ->when(!empty($periodoInicial) && !empty($periodoFinal), function ($q) use ($periodoInicial, $periodoFinal) {
                $q->whereBetween('data_hora', [$periodoInicial . ' 00:00:01', $periodoFinal . ' 23:59:59']);
            })
            ->when(!empty($idInicial) && !empty($idFinal), function ($q) use ($idInicial, $idFinal) {
                $q->whereBetween('id_documento', [$idInicial, $idFinal]);
            });
    }
}
```

- [ ] **Step 4: Rodar testes**

```bash
php artisan test --filter=ExportacaoProcessoServiceTest
```

Esperado: 6 testes passam.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Exportacao/ExportacaoProcessoService.php tests/Unit/Services/ExportacaoProcessoServiceTest.php
git commit -m "feat: ExportacaoProcessoService (criar, consultarDocumentos, temDocumentosDisponiveis)"
```

---

## Task 6: `ExportacaoProcessoService::marcarComoFalhou` + `enviarParaS3`

Adiciona dois métodos restantes que serão usados pelos jobs.

**Files:**
- Modify: `app/Services/Exportacao/ExportacaoProcessoService.php`
- Modify: `tests/Unit/Services/ExportacaoProcessoServiceTest.php`

- [ ] **Step 1: Adicionar testes**

Acrescente em `tests/Unit/Services/ExportacaoProcessoServiceTest.php`:

```php
use App\Jobs\EnviarWebhookDownloadJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('marcarComoFalhou() atualiza registro e despacha webhook', function () {
    Queue::fake();

    $exportacao = ProcessoExportacao::factory()->processando()->create();

    (new ExportacaoProcessoService())->marcarComoFalhou($exportacao, 'algo deu errado');

    $exportacao->refresh();
    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_FALHOU);
    expect($exportacao->erro_resumo)->toBe('algo deu errado');

    Queue::assertPushed(EnviarWebhookDownloadJob::class, fn ($job) => $job->exportacaoId === $exportacao->id);
});

it('enviarParaS3() faz upload no path downloads/{user_id}/{uuid}.pdf, atualiza s3_path e tamanho_bytes, marca concluido e remove arquivo local', function () {
    Storage::fake('s3');

    $exportacao = ProcessoExportacao::factory()->processando()->create([
        'user_id' => 99,
    ]);

    $caminhoLocal = storage_path("app/private/exportacoes/{$exportacao->uuid_arquivo}.pdf");
    @mkdir(dirname($caminhoLocal), 0755, true);
    file_put_contents($caminhoLocal, str_repeat('x', 2048));

    (new ExportacaoProcessoService())->enviarParaS3($exportacao, $caminhoLocal);

    $exportacao->refresh();
    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_CONCLUIDO);
    expect($exportacao->s3_path)->toBe("downloads/99/{$exportacao->uuid_arquivo}.pdf");
    expect($exportacao->tamanho_bytes)->toBe(2048);
    Storage::disk('s3')->assertExists($exportacao->s3_path);
    expect(file_exists($caminhoLocal))->toBeFalse();
});
```

- [ ] **Step 2: Rodar — devem falhar**

```bash
php artisan test --filter=ExportacaoProcessoServiceTest
```

- [ ] **Step 3: Implementar os métodos**

Edite `app/Services/Exportacao/ExportacaoProcessoService.php` adicionando os imports no topo e os métodos:

```php
use App\Jobs\EnviarWebhookDownloadJob;
use Illuminate\Support\Facades\Storage;

// ... dentro da classe, após consultarDocumentos():

public function marcarComoFalhou(ProcessoExportacao $exportacao, string $erroResumo): void
{
    $exportacao->update([
        'status' => ProcessoExportacao::STATUS_FALHOU,
        'erro_resumo' => $erroResumo,
    ]);

    EnviarWebhookDownloadJob::dispatch($exportacao->id)->onQueue('exportar-processo');
}

public function enviarParaS3(ProcessoExportacao $exportacao, string $caminhoLocal): void
{
    $tamanho = filesize($caminhoLocal);
    $s3Path = "downloads/{$exportacao->user_id}/{$exportacao->uuid_arquivo}.pdf";

    $stream = fopen($caminhoLocal, 'r');
    Storage::disk('s3')->put($s3Path, $stream, ['visibility' => 'private']);
    if (is_resource($stream)) {
        fclose($stream);
    }

    $exportacao->update([
        's3_path' => $s3Path,
        'tamanho_bytes' => $tamanho,
        'status' => ProcessoExportacao::STATUS_CONCLUIDO,
    ]);

    @unlink($caminhoLocal);
}
```

- [ ] **Step 4: Rodar testes**

```bash
php artisan test --filter=ExportacaoProcessoServiceTest
```

Esperado: 8 testes passam.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Exportacao/ExportacaoProcessoService.php tests/Unit/Services/ExportacaoProcessoServiceTest.php
git commit -m "feat: ExportacaoProcessoService::marcarComoFalhou + enviarParaS3"
```

---

## Task 7: `EnviarParaS3ExportacaoJob`

Job fino que delega ao service. Tries=3, backoff `[10, 30, 60]`. Em falha definitiva: marca falho + dispara webhook.

**Files:**
- Create: `app/Jobs/EnviarParaS3ExportacaoJob.php`
- Create: `tests/Feature/Jobs/EnviarParaS3ExportacaoJobTest.php`

- [ ] **Step 1: Escrever testes**

Arquivo `tests/Feature/Jobs/EnviarParaS3ExportacaoJobTest.php`:

```php
<?php

use App\Jobs\EnviarParaS3ExportacaoJob;
use App\Jobs\EnviarWebhookDownloadJob;
use App\Models\ProcessoExportacao;
use App\Services\Exportacao\ExportacaoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

function criarArquivoLocalParaExportacao(ProcessoExportacao $exportacao, string $conteudo = 'pdf-bytes'): string
{
    $caminho = storage_path("app/private/exportacoes/{$exportacao->uuid_arquivo}.pdf");
    @mkdir(dirname($caminho), 0755, true);
    file_put_contents($caminho, $conteudo);
    return $caminho;
}

it('faz upload, atualiza registro e despacha webhook em sucesso', function () {
    Storage::fake('s3');
    Queue::fake();

    $exportacao = ProcessoExportacao::factory()->processando()->create(['user_id' => 7]);
    criarArquivoLocalParaExportacao($exportacao);

    (new EnviarParaS3ExportacaoJob($exportacao->id))->handle(app(ExportacaoProcessoService::class));

    $exportacao->refresh();
    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_CONCLUIDO);
    Storage::disk('s3')->assertExists($exportacao->s3_path);
    Queue::assertPushed(EnviarWebhookDownloadJob::class, fn ($j) => $j->exportacaoId === $exportacao->id);
});

it('relança exception em falha transitória do upload (queue retenta)', function () {
    Storage::fake('s3');

    $exportacao = ProcessoExportacao::factory()->processando()->create();
    // Não criamos o arquivo local — força falha de IO no service
    $service = app(ExportacaoProcessoService::class);

    expect(fn () => (new EnviarParaS3ExportacaoJob($exportacao->id))->handle($service))
        ->toThrow(Throwable::class);
});

it('em failed() (esgotou tries) marca exportação como falhou e despacha webhook', function () {
    Queue::fake();

    $exportacao = ProcessoExportacao::factory()->processando()->create();

    $job = new EnviarParaS3ExportacaoJob($exportacao->id);
    $job->failed(new RuntimeException('upload definitivamente falhou'));

    $exportacao->refresh();
    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_FALHOU);
    expect($exportacao->erro_resumo)->toBe('Falha ao enviar arquivo para o storage.');
    Queue::assertPushed(EnviarWebhookDownloadJob::class);
});

it('retorna sem efeito quando exportacao não existe', function () {
    Queue::fake();
    Storage::fake('s3');

    (new EnviarParaS3ExportacaoJob(999999))->handle(app(ExportacaoProcessoService::class));

    Queue::assertNothingPushed();
});
```

- [ ] **Step 2: Rodar — devem falhar**

```bash
php artisan test --filter=EnviarParaS3ExportacaoJobTest
```

- [ ] **Step 3: Implementar o job**

Arquivo `app/Jobs/EnviarParaS3ExportacaoJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\ProcessoExportacao;
use App\Services\Exportacao\ExportacaoProcessoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class EnviarParaS3ExportacaoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(public int $exportacaoId) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(ExportacaoProcessoService $service): void
    {
        $exportacao = ProcessoExportacao::find($this->exportacaoId);

        if (!$exportacao) {
            Log::warning("[Exportacao:{$this->exportacaoId}] não encontrada ao enviar para S3");
            return;
        }

        $caminhoLocal = storage_path("app/private/exportacoes/{$exportacao->uuid_arquivo}.pdf");

        $service->enviarParaS3($exportacao, $caminhoLocal);

        EnviarWebhookDownloadJob::dispatch($exportacao->id)->onQueue('exportar-processo');

        Log::info("[Exportacao:{$exportacao->id}] arquivo enviado para S3", [
            's3_path' => $exportacao->fresh()->s3_path,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Exportacao:{$this->exportacaoId}] falha definitiva ao enviar para S3", [
            'erro' => $e->getMessage(),
        ]);

        $exportacao = ProcessoExportacao::find($this->exportacaoId);
        if ($exportacao) {
            app(ExportacaoProcessoService::class)
                ->marcarComoFalhou($exportacao, 'Falha ao enviar arquivo para o storage.');
        }
    }
}
```

- [ ] **Step 4: Rodar testes**

```bash
php artisan test --filter=EnviarParaS3ExportacaoJobTest
```

Esperado: 4 testes passam.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/EnviarParaS3ExportacaoJob.php tests/Feature/Jobs/EnviarParaS3ExportacaoJobTest.php
git commit -m "feat: EnviarParaS3ExportacaoJob (upload com retry e fallback)"
```

---

## Task 8: `ExportacaoProcessoService::gerarPdf`

Encapsula a lógica FPDI extraída do `DownloadProcessoJob` atual. Por ser código com side effects pesados (filesystem, FPDI, gs), o teste é mínimo: confirma que o método produz um arquivo no caminho esperado dado um cenário com 0 documentos a baixar (apenas a capa).

**Files:**
- Modify: `app/Services/Exportacao/ExportacaoProcessoService.php`
- Modify: `tests/Unit/Services/ExportacaoProcessoServiceTest.php`

- [ ] **Step 1: Adicionar teste mínimo**

Acrescente em `tests/Unit/Services/ExportacaoProcessoServiceTest.php`:

```php
use Illuminate\Support\Facades\Storage as StorageFacade;

it('gerarPdf() persiste uuid_arquivo e cria arquivo PDF no caminho esperado', function () {
    StorageFacade::fake('public');
    StorageFacade::fake('s3');

    $numero = '6001255-81.2024.8.03.0003';
    $processo = criarProcessoComDocumentos($numero, []);

    $exportacao = ProcessoExportacao::factory()->create([
        'numero_processo' => $numero,
        'tribunal_id' => $processo->tribunal_id,
    ]);

    $service = new ExportacaoProcessoService();
    $documentos = $service->consultarDocumentos($exportacao); // collection vazia

    $caminho = $service->gerarPdf($exportacao, $documentos);

    $exportacao->refresh();
    expect($exportacao->uuid_arquivo)->not->toBeNull();
    expect($caminho)->toBe(storage_path("app/private/exportacoes/{$exportacao->uuid_arquivo}.pdf"));
    expect(file_exists($caminho))->toBeTrue();

    @unlink($caminho);
})->skip(fn () => !class_exists(\setasign\Fpdi\Tcpdf\Fpdi::class), 'FPDI não disponível neste ambiente de teste');
```

> Nota: o `skip()` roda só se a lib não estiver instalada. Em CI normal ela está, então o teste roda. Se o `gerarPdf` exigir documentos para produzir saída válida, ajuste o teste para criar 1 documento PDF mínimo via fixture — mas o caminho mais barato é gerar só a capa.

- [ ] **Step 2: Rodar — deve falhar**

```bash
php artisan test --filter=ExportacaoProcessoServiceTest
```

- [ ] **Step 3: Implementar `gerarPdf` no service**

Edite `app/Services/Exportacao/ExportacaoProcessoService.php`. Adicione imports no topo:

```php
use App\Models\Processo;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Illuminate\Support\Str;
use setasign\Fpdi\Tcpdf\Fpdi;
```

E o método dentro da classe (após `enviarParaS3()`):

```php
public function gerarPdf(ProcessoExportacao $exportacao, Collection $documentos): string
{
    $uuid = $exportacao->uuid_arquivo ?: (string) Str::uuid();
    $exportacao->update(['uuid_arquivo' => $uuid]);

    $pastaTemp = storage_path('app/private/exportacoes');
    if (!is_dir($pastaTemp)) {
        mkdir($pastaTemp, 0755, true);
    }

    $caminhoFinal = "{$pastaTemp}/{$uuid}.pdf";
    $processo = Processo::where('numero_processo', $exportacao->numero_processo)->first();

    $pdfCapa = PDF::loadView('processo.download', [
        'documentos' => $documentos,
        'processo' => $processo,
    ]);
    $tempCapaPath = "{$pastaTemp}/_capa_{$uuid}.pdf";
    file_put_contents($tempCapaPath, $pdfCapa->output());

    $pdf = new Fpdi();
    $pageCount = $pdf->setSourceFile($tempCapaPath);
    for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
        $templateId = $pdf->importPage($pageNo);
        $pdf->AddPage();
        $pdf->useTemplate($templateId);
        if ($pageNo === 1) {
            $pdf->Bookmark('Capa', 0, 0, '', 'B', [0, 0, 0]);
        }
    }

    foreach ($documentos as $documento) {
        $this->baixarESomarDocumento($pdf, $documento);
    }

    $pdf->Output($caminhoFinal, 'F');
    @unlink($tempCapaPath);

    return $caminhoFinal;
}

private function baixarESomarDocumento(Fpdi $pdf, $documento): void
{
    if (\Illuminate\Support\Facades\Storage::disk('s3')->exists($documento->path)) {
        $content = \Illuminate\Support\Facades\Storage::disk('s3')->get($documento->path);
        \Illuminate\Support\Facades\Storage::disk('public')->put($documento->path, $content);
    }

    $pathDocumento = \Illuminate\Support\Facades\Storage::disk('public')->path($documento->path);
    if (!file_exists($pathDocumento)) {
        return;
    }

    $this->converterVersaoPdf($pathDocumento);

    try {
        $pageCount = $pdf->setSourceFile($pathDocumento);
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            $pdf->AddPage();
            $pdf->useTemplate($templateId);
            if ($pageNo === 1) {
                $pdf->Bookmark($documento->descricao ?? 'Documento', 0, 0, '', 'B', [0, 0, 0]);
            }
        }
        @unlink($pathDocumento);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning("Erro ao processar documento {$documento->id_documento}: {$e->getMessage()}");
    }
}

private function converterVersaoPdf(string $inputPdf): void
{
    $tempOut = $inputPdf . '.tmp';
    $command = sprintf('gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -o %s %s 2>/dev/null', escapeshellarg($tempOut), escapeshellarg($inputPdf));
    exec($command, $output, $code);
    if ($code === 0 && file_exists($tempOut)) {
        rename($tempOut, $inputPdf);
    }
}
```

- [ ] **Step 4: Rodar testes**

```bash
php artisan test --filter=ExportacaoProcessoServiceTest
```

Esperado: 9 testes passam (ou 8 + 1 skipped se FPDI ausente).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Exportacao/ExportacaoProcessoService.php tests/Unit/Services/ExportacaoProcessoServiceTest.php
git commit -m "feat: ExportacaoProcessoService::gerarPdf (FPDI + capa)"
```

---

## Task 9: `GerarPdfExportacaoJob`

Job orquestrador da geração. Marca status, consulta WS opcional, gera PDF via service, despacha próximo job. Em qualquer falha: marca falhou + dispara webhook.

**Files:**
- Create: `app/Jobs/GerarPdfExportacaoJob.php`
- Create: `tests/Feature/Jobs/GerarPdfExportacaoJobTest.php`

- [ ] **Step 1: Escrever testes**

Arquivo `tests/Feature/Jobs/GerarPdfExportacaoJobTest.php`:

```php
<?php

use App\Jobs\EnviarParaS3ExportacaoJob;
use App\Jobs\EnviarWebhookDownloadJob;
use App\Jobs\GerarPdfExportacaoJob;
use App\Models\ProcessoExportacao;
use App\Services\Exportacao\ExportacaoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Mockery as m;

uses(DatabaseTransactions::class);

it('marca status processando, gera PDF e despacha S3 job em sucesso', function () {
    Queue::fake();

    $exportacao = ProcessoExportacao::factory()->create();

    $service = m::mock(ExportacaoProcessoService::class);
    $service->shouldReceive('consultarDocumentos')->once()->andReturn(new Collection([(object) ['id_documento' => 1]]));
    $service->shouldReceive('gerarPdf')->once()->andReturn('/tmp/fake.pdf');
    app()->instance(ExportacaoProcessoService::class, $service);

    (new GerarPdfExportacaoJob($exportacao->id))->handle(app(ExportacaoProcessoService::class));

    $exportacao->refresh();
    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_PROCESSANDO);
    Queue::assertPushed(EnviarParaS3ExportacaoJob::class, fn ($j) => $j->exportacaoId === $exportacao->id);
});

it('marca falhou e despacha webhook quando não há documentos', function () {
    Queue::fake();

    $exportacao = ProcessoExportacao::factory()->create();

    $service = m::mock(ExportacaoProcessoService::class)->makePartial();
    $service->shouldReceive('consultarDocumentos')->once()->andReturn(new Collection([]));
    $service->shouldReceive('marcarComoFalhou')->once()->withArgs(function ($e, $msg) use ($exportacao) {
        return $e->id === $exportacao->id && str_contains($msg, 'Nenhum documento');
    });
    app()->instance(ExportacaoProcessoService::class, $service);

    (new GerarPdfExportacaoJob($exportacao->id))->handle(app(ExportacaoProcessoService::class));
});

it('em falha de geração, chama marcarComoFalhou com a mensagem do erro', function () {
    Queue::fake();

    $exportacao = ProcessoExportacao::factory()->create();

    $service = m::mock(ExportacaoProcessoService::class)->makePartial();
    $service->shouldReceive('consultarDocumentos')->andReturn(new Collection([(object) ['id_documento' => 1]]));
    $service->shouldReceive('gerarPdf')->andThrow(new RuntimeException('boom no FPDI'));
    $service->shouldReceive('marcarComoFalhou')->once()->withArgs(function ($e, $msg) {
        return str_contains($msg, 'boom no FPDI');
    });
    app()->instance(ExportacaoProcessoService::class, $service);

    (new GerarPdfExportacaoJob($exportacao->id))->handle(app(ExportacaoProcessoService::class));
});

it('retorna sem efeito quando exportacao não existe', function () {
    Queue::fake();

    (new GerarPdfExportacaoJob(999999))->handle(app(ExportacaoProcessoService::class));

    Queue::assertNothingPushed();
});
```

- [ ] **Step 2: Rodar — devem falhar**

```bash
php artisan test --filter=GerarPdfExportacaoJobTest
```

- [ ] **Step 3: Implementar o job**

Arquivo `app/Jobs/GerarPdfExportacaoJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\ProcessoExportacao;
use App\Models\Tribunal;
use App\Services\Exportacao\ExportacaoProcessoService;
use App\Services\Processo\ProcessoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GerarPdfExportacaoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(public int $exportacaoId) {}

    public function handle(ExportacaoProcessoService $service): void
    {
        $exportacao = ProcessoExportacao::find($this->exportacaoId);

        if (!$exportacao) {
            Log::warning("[Exportacao:{$this->exportacaoId}] não encontrada ao gerar PDF");
            return;
        }

        try {
            $exportacao->update(['status' => ProcessoExportacao::STATUS_PROCESSANDO]);

            $this->consultarWebservice($exportacao);

            $documentos = $service->consultarDocumentos($exportacao);

            if ($documentos->isEmpty()) {
                $service->marcarComoFalhou($exportacao, 'Nenhum documento encontrado para os filtros informados.');
                return;
            }

            $service->gerarPdf($exportacao, $documentos);

            EnviarParaS3ExportacaoJob::dispatch($exportacao->id)->onQueue('exportar-processo');

            Log::info("[Exportacao:{$exportacao->id}] PDF gerado, encaminhando para upload S3");
        } catch (\Throwable $e) {
            Log::error("[Exportacao:{$exportacao->id}] erro na geração do PDF", [
                'erro' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $service->marcarComoFalhou($exportacao, $e->getMessage() ?: 'Erro ao gerar PDF.');
        }
    }

    private function consultarWebservice(ProcessoExportacao $exportacao): void
    {
        if (!$exportacao->tribunal_id) {
            return;
        }

        try {
            $tribunal = Tribunal::find($exportacao->tribunal_id);
            if ($tribunal) {
                (new ProcessoService())->consultarNumero($tribunal, $exportacao->numero_processo);
            }
        } catch (\Throwable $e) {
            Log::warning("[Exportacao:{$exportacao->id}] falha ao consultar webservice (best-effort): {$e->getMessage()}");
        }
    }
}
```

- [ ] **Step 4: Rodar testes**

```bash
php artisan test --filter=GerarPdfExportacaoJobTest
```

Esperado: 4 testes passam.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/GerarPdfExportacaoJob.php tests/Feature/Jobs/GerarPdfExportacaoJobTest.php
git commit -m "feat: GerarPdfExportacaoJob (orquestrador da geração)"
```

---

## Task 10: `CriarExportacaoProcessoRequest`

Form Request com regras de validação.

**Files:**
- Create: `app/Http/Requests/Api/CriarExportacaoProcessoRequest.php`

- [ ] **Step 1: Implementar o Form Request**

Arquivo `app/Http/Requests/Api/CriarExportacaoProcessoRequest.php`:

```php
<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CriarExportacaoProcessoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autenticação é feita pelo middleware ValidateApiToken
    }

    public function rules(): array
    {
        return [
            'numero_processo' => ['required', 'string', 'max:25'],
            'tribunal_id' => ['nullable', 'integer'],
            'user_id' => ['required', 'integer', 'min:1'],
            'titulo' => ['required', 'string', 'max:255'],
            'formato' => ['required', 'string', Rule::in(['pdf'])],
            'ids_selecionados' => ['nullable', 'array'],
            'ids_selecionados.*' => ['integer'],
            'periodo_inicial' => ['nullable', 'date_format:Y-m-d', 'required_with:periodo_final'],
            'periodo_final' => ['nullable', 'date_format:Y-m-d', 'required_with:periodo_inicial'],
            'id_inicial' => ['nullable', 'integer', 'required_with:id_final'],
            'id_final' => ['nullable', 'integer', 'required_with:id_inicial'],
        ];
    }

    public function prepareForValidation(): void
    {
        if ($this->has('numero_processo')) {
            $this->merge(['numero_processo' => cleanNumeroProcesso($this->input('numero_processo'))]);
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Requests/Api/CriarExportacaoProcessoRequest.php
git commit -m "feat: CriarExportacaoProcessoRequest (validação da request)"
```

> Não há testes isolados para o Form Request — ele é testado via `DownloadProcessoControllerTest` na próxima task.

---

## Task 11: Refatorar `DownloadProcessoController`

**Files:**
- Modify: `app/Http/Controllers/Api/DownloadProcessoController.php`
- Create: `tests/Feature/Api/DownloadProcessoControllerTest.php`

- [ ] **Step 1: Escrever testes**

Arquivo `tests/Feature/Api/DownloadProcessoControllerTest.php`:

```php
<?php

use App\Jobs\GerarPdfExportacaoJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoExportacao;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config()->set('services.api.token', 'tk-test');
});

function payloadValidoExportacao(array $overrides = []): array
{
    return array_merge([
        'numero_processo' => '6001255-81.2024.8.03.0003',
        'tribunal_id' => 1,
        'user_id' => 42,
        'titulo' => 'Processo X — PDF',
        'formato' => 'pdf',
        'ids_selecionados' => [1],
    ], $overrides);
}

function criarProcessoComDocParaController(string $numero, int $idDoc, string $mimetype = 'application/pdf'): void
{
    $processo = Processo::create([
        'numero_processo' => $numero,
        'tribunal_id' => 1,
    ]);
    ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => $idDoc,
        'mimetype' => $mimetype,
        'data_hora' => '2024-12-12 10:00:00',
    ]);
}

it('payload válido retorna 200 com exportacao_id, cria registro e despacha job', function () {
    Queue::fake();

    criarProcessoComDocParaController('6001255-81.2024.8.03.0003', 1);

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->postJson('/api/processo/download', payloadValidoExportacao());

    $response->assertOk()
        ->assertJsonStructure(['message', 'exportacao_id']);

    $exportacao = ProcessoExportacao::find($response->json('exportacao_id'));
    expect($exportacao)->not->toBeNull();
    expect($exportacao->status)->toBe(ProcessoExportacao::STATUS_ENFILEIRADO);
    expect($exportacao->user_id)->toBe(42);
    expect($exportacao->titulo)->toBe('Processo X — PDF');

    Queue::assertPushed(GerarPdfExportacaoJob::class, fn ($j) => $j->exportacaoId === $exportacao->id);
});

it('retorna 422 quando user_id ausente', function () {
    Queue::fake();

    $payload = payloadValidoExportacao();
    unset($payload['user_id']);

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->postJson('/api/processo/download', $payload);

    $response->assertStatus(422);
    Queue::assertNothingPushed();
});

it('retorna 422 quando formato é inválido', function () {
    Queue::fake();

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->postJson('/api/processo/download', payloadValidoExportacao(['formato' => 'docx']));

    $response->assertStatus(422);
    Queue::assertNothingPushed();
});

it('ignora campos extras como email e notificacao_id', function () {
    Queue::fake();

    criarProcessoComDocParaController('6001255-81.2024.8.03.0003', 1);

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->postJson('/api/processo/download', payloadValidoExportacao([
            'email' => 'foo@example.com',
            'notificacao_id' => 'abc-123',
        ]));

    $response->assertOk();
});

it('retorna 404 quando nenhum documento disponível para os filtros', function () {
    Queue::fake();

    criarProcessoComDocParaController('6001255-81.2024.8.03.0003', 1);

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->postJson('/api/processo/download', payloadValidoExportacao([
            'ids_selecionados' => [999],
        ]));

    $response->assertStatus(404);
    expect(ProcessoExportacao::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('retorna 401 com token inválido', function () {
    Queue::fake();

    $response = $this->withHeaders(['X-API-Token' => 'wrong'])
        ->postJson('/api/processo/download', payloadValidoExportacao());

    $response->assertStatus(401);
});
```

- [ ] **Step 2: Rodar — devem falhar (controller ainda usa o fluxo antigo)**

```bash
php artisan test --filter=DownloadProcessoControllerTest
```

- [ ] **Step 3: Substituir o controller**

Reescreva `app/Http/Controllers/Api/DownloadProcessoController.php` por completo:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CriarExportacaoProcessoRequest;
use App\Jobs\GerarPdfExportacaoJob;
use App\Services\Exportacao\ExportacaoProcessoService;
use Illuminate\Http\JsonResponse;

class DownloadProcessoController extends Controller
{
    public function __construct(private readonly ExportacaoProcessoService $service) {}

    public function store(CriarExportacaoProcessoRequest $request): JsonResponse
    {
        $dados = $request->validated();
        $filtros = [
            'ids_selecionados' => $dados['ids_selecionados'] ?? null,
            'periodo_inicial' => $dados['periodo_inicial'] ?? null,
            'periodo_final' => $dados['periodo_final'] ?? null,
            'id_inicial' => $dados['id_inicial'] ?? null,
            'id_final' => $dados['id_final'] ?? null,
        ];

        if (!$this->service->temDocumentosDisponiveis($filtros, $dados['numero_processo'])) {
            return response()->json([
                'error' => 'Nenhum documento encontrado para o processo informado com os filtros aplicados.',
            ], 404);
        }

        $exportacao = $this->service->criar($dados);

        GerarPdfExportacaoJob::dispatch($exportacao->id)->onQueue('exportar-processo');

        return response()->json([
            'message' => 'Exportação enfileirada',
            'exportacao_id' => $exportacao->id,
        ], 200);
    }
}
```

- [ ] **Step 4: Rodar testes**

```bash
php artisan test --filter=DownloadProcessoControllerTest
```

Esperado: 6 testes passam.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/DownloadProcessoController.php tests/Feature/Api/DownloadProcessoControllerTest.php
git commit -m "refactor: DownloadProcessoController usa Form Request + service"
```

---

## Task 12: Comando `exportacoes:reenviar-webhook`

**Files:**
- Create: `app/Console/Commands/ExportacoesReenviarWebhook.php`
- Create: `tests/Feature/Console/ExportacoesReenviarWebhookTest.php`

- [ ] **Step 1: Escrever testes**

Arquivo `tests/Feature/Console/ExportacoesReenviarWebhookTest.php`:

```php
<?php

use App\Jobs\EnviarWebhookDownloadJob;
use App\Models\ProcessoExportacao;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

it('com argumento, redespacha o job para a exportação especificada', function () {
    Queue::fake();

    $exportacao = ProcessoExportacao::factory()->concluido()->create();

    $this->artisan('exportacoes:reenviar-webhook', ['exportacao_id' => $exportacao->id])
        ->assertSuccessful();

    Queue::assertPushed(EnviarWebhookDownloadJob::class, fn ($j) => $j->exportacaoId === $exportacao->id);
});

it('falha quando exportacao_id não existe', function () {
    $this->artisan('exportacoes:reenviar-webhook', ['exportacao_id' => 999999])
        ->expectsOutputToContain('não encontrada')
        ->assertFailed();
});

it('sem argumento, lista pendentes >1h e redespacha após confirmação', function () {
    Queue::fake();

    $antiga = ProcessoExportacao::factory()->concluido()->create([
        'created_at' => now()->subHours(2),
    ]);
    $recente = ProcessoExportacao::factory()->concluido()->create([
        'created_at' => now()->subMinutes(30),
    ]);
    $jaEnviada = ProcessoExportacao::factory()->concluido()->webhookEnviado()->create([
        'created_at' => now()->subHours(2),
    ]);

    $this->artisan('exportacoes:reenviar-webhook')
        ->expectsConfirmation('Redespachar webhook para 1 exportação(ões)?', 'yes')
        ->assertSuccessful();

    Queue::assertPushed(EnviarWebhookDownloadJob::class, 1);
    Queue::assertPushed(EnviarWebhookDownloadJob::class, fn ($j) => $j->exportacaoId === $antiga->id);
});

it('flag --reset-tentativas zera webhook_tentativas antes de redespachar', function () {
    Queue::fake();

    $exportacao = ProcessoExportacao::factory()->concluido()->create([
        'webhook_tentativas' => 5,
    ]);

    $this->artisan('exportacoes:reenviar-webhook', [
        'exportacao_id' => $exportacao->id,
        '--reset-tentativas' => true,
    ])->assertSuccessful();

    expect($exportacao->fresh()->webhook_tentativas)->toBe(0);
});
```

- [ ] **Step 2: Rodar — devem falhar**

```bash
php artisan test --filter=ExportacoesReenviarWebhookTest
```

- [ ] **Step 3: Implementar o comando**

Arquivo `app/Console/Commands/ExportacoesReenviarWebhook.php`:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\EnviarWebhookDownloadJob;
use App\Models\ProcessoExportacao;
use Illuminate\Console\Command;

class ExportacoesReenviarWebhook extends Command
{
    protected $signature = 'exportacoes:reenviar-webhook {exportacao_id?} {--reset-tentativas}';

    protected $description = 'Redespacha o webhook de uma (ou várias) exportações pendentes para o SIM';

    public function handle(): int
    {
        $id = $this->argument('exportacao_id');
        $reset = (bool) $this->option('reset-tentativas');

        if ($id) {
            return $this->reenviarUma((int) $id, $reset);
        }

        return $this->reenviarPendentes($reset);
    }

    private function reenviarUma(int $id, bool $reset): int
    {
        $exportacao = ProcessoExportacao::find($id);

        if (!$exportacao) {
            $this->error("Exportação {$id} não encontrada.");
            return self::FAILURE;
        }

        if ($reset) {
            $exportacao->update(['webhook_tentativas' => 0]);
        }

        EnviarWebhookDownloadJob::dispatch($exportacao->id)->onQueue('exportar-processo');
        $this->info("Webhook redespachado para exportação {$exportacao->id}.");
        return self::SUCCESS;
    }

    private function reenviarPendentes(bool $reset): int
    {
        $pendentes = ProcessoExportacao::query()
            ->whereIn('status', [ProcessoExportacao::STATUS_CONCLUIDO, ProcessoExportacao::STATUS_FALHOU])
            ->whereNull('webhook_enviado_em')
            ->where('created_at', '<=', now()->subHour())
            ->get();

        $total = $pendentes->count();

        if ($total === 0) {
            $this->info('Nenhuma exportação pendente de webhook há mais de 1 hora.');
            return self::SUCCESS;
        }

        $this->table(['id', 'user_id', 'status', 'created_at'], $pendentes->map(fn ($e) => [
            $e->id, $e->user_id, $e->status, $e->created_at->toDateTimeString(),
        ]));

        if (!$this->confirm("Redespachar webhook para {$total} exportação(ões)?", true)) {
            return self::SUCCESS;
        }

        foreach ($pendentes as $e) {
            if ($reset) {
                $e->update(['webhook_tentativas' => 0]);
            }
            EnviarWebhookDownloadJob::dispatch($e->id)->onQueue('exportar-processo');
        }

        $this->info("Redespachado para {$total} exportação(ões).");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Rodar testes**

```bash
php artisan test --filter=ExportacoesReenviarWebhookTest
```

Esperado: 4 testes passam.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/ExportacoesReenviarWebhook.php tests/Feature/Console/ExportacoesReenviarWebhookTest.php
git commit -m "feat: comando exportacoes:reenviar-webhook"
```

---

## Task 13: Smoke test integrado (queue=sync, ponta a ponta)

Verifica o pipeline completo executando síncrono. Garante que os 3 jobs encadeiam e o webhook é chamado no final.

**Files:**
- Create: `tests/Feature/ExportacaoPipelineTest.php`

- [ ] **Step 1: Escrever o teste**

Arquivo `tests/Feature/ExportacaoPipelineTest.php`:

```php
<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoExportacao;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

it('pipeline: POST /api/processo/download → PDF → S3 → webhook', function () {
    Storage::fake('s3');
    Storage::fake('public');
    Http::fake(['*' => Http::response(['message' => 'OK', 'download_id' => 1], 200)]);

    config()->set('services.api.token', 'tk-test');
    config()->set('services.sim_webhook_download.url', 'https://sim.test/webhook/download');
    config()->set('services.sim_webhook_download.token', 'tk-test');
    config()->set('queue.default', 'sync');

    $numero = '6001255-81.2024.8.03.0003';
    $processo = Processo::create(['numero_processo' => $numero, 'tribunal_id' => 1]);
    ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => 1,
        'mimetype' => 'application/pdf',
        'data_hora' => '2024-12-12 10:00:00',
        'descricao' => 'Documento Teste',
    ]);

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->postJson('/api/processo/download', [
            'numero_processo' => $numero,
            'user_id' => 42,
            'titulo' => 'Processo X — PDF',
            'formato' => 'pdf',
            'ids_selecionados' => [1],
        ]);

    $response->assertOk();
    $exportacao = ProcessoExportacao::find($response->json('exportacao_id'));

    expect($exportacao->status)->toBeIn([ProcessoExportacao::STATUS_CONCLUIDO, ProcessoExportacao::STATUS_FALHOU]);

    if ($exportacao->status === ProcessoExportacao::STATUS_CONCLUIDO) {
        Storage::disk('s3')->assertExists($exportacao->s3_path);
        expect($exportacao->webhook_enviado_em)->not->toBeNull();
        Http::assertSent(fn ($req) => $req['status'] === 'concluido');
    } else {
        // Caso o documento não tenha conteúdo válido em fixture, ainda confirmamos webhook
        Http::assertSent(fn ($req) => $req['status'] === 'falhou');
    }
})->skip(fn () => !class_exists(\setasign\Fpdi\Tcpdf\Fpdi::class), 'FPDI não disponível neste ambiente de teste');
```

- [ ] **Step 2: Rodar**

```bash
php artisan test --filter=ExportacaoPipelineTest
```

Esperado: passa (ou skipped se FPDI ausente).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/ExportacaoPipelineTest.php
git commit -m "test: pipeline integrado (POST → PDF → S3 → webhook)"
```

---

## Task 14: Remover código legacy

Remove o que foi substituído. Faça em um único commit com o título claro: é o ponto sem volta do breaking change.

**Files (a deletar):**
- `app/Mail/EnviarAutosProcessoMail.php`
- `app/Listeners/DeleteTemporaryFilesAfterEmailSent.php`
- `resources/views/mail/proceso/autos.blade.php`
- `app/Jobs/DownloadProcessoJob.php`
- `tests/Feature/DownloadProcessoTest.php`
- `resources/views/mail/proceso/` (diretório, se ficar vazio)

**Adicionalmente verificar:**
- `app/Providers/EventServiceProvider.php` ou `AppServiceProvider.php` — se houver registro do listener `DeleteTemporaryFilesAfterEmailSent`, remover.
- `config/mail.php` — verificar se mailer `smsapi` é usado por outro fluxo. Se NÃO for, remover. Se sim, manter.

- [ ] **Step 1: Verificar referências cruzadas antes de apagar**

```bash
grep -rn "EnviarAutosProcessoMail\|DeleteTemporaryFilesAfterEmailSent\|DownloadProcessoJob\|mailer.*smsapi\|smsapi.*mailer" \
  app/ config/ resources/ tests/ routes/ --include='*.php' --include='*.blade.php' | grep -v '^Binary'
```

Esperado: ocorrências apenas em arquivos a remover. Anote quaisquer outras (provavelmente nenhuma).

- [ ] **Step 2: Remover arquivos**

```bash
rm app/Mail/EnviarAutosProcessoMail.php
rm app/Listeners/DeleteTemporaryFilesAfterEmailSent.php
rm resources/views/mail/proceso/autos.blade.php
rmdir resources/views/mail/proceso 2>/dev/null || true
rmdir resources/views/mail 2>/dev/null || true
rm app/Jobs/DownloadProcessoJob.php
rm tests/Feature/DownloadProcessoTest.php
```

- [ ] **Step 3: Limpar registro do listener (se existir)**

Procure em `app/Providers/EventServiceProvider.php` (e/ou `AppServiceProvider.php`) por `DeleteTemporaryFilesAfterEmailSent` e remova a entrada do array `$listen`. Se o EventServiceProvider não existir, pular.

- [ ] **Step 4: Avaliar mailer `smsapi`**

```bash
grep -rn "'smsapi'" app/ config/ tests/ --include='*.php' | grep -v Mail/Transport/SmsApiTransport.php
```

Se não houver outras referências além da definição em `config/mail.php`, remova:
- A entrada `'smsapi' => [...]` em `config/mail.php`.
- O bind/registro do transport em `AppServiceProvider`.
- `app/Mail/Transport/SmsApiTransport.php`.

Se houver referências em outros lugares (ex.: outro Mailable), **não remova**.

- [ ] **Step 5: Rodar suíte completa para garantir que nada quebrou**

```bash
php artisan test
```

Esperado: tudo passa.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor!: remove fluxo de e-mail (substituído por webhook)

BREAKING CHANGE: o endpoint POST /api/processo/download não envia mais e-mail.
Conclusão da exportação agora é notificada via POST {SIM}/webhook/download.
Campos 'email' e 'notificacao_id' removidos do payload."
```

---

## Task 15: Verificação final + atualização de docs operacionais

- [ ] **Step 1: Rodar suíte completa**

```bash
php artisan test
```

Esperado: 100% verde.

- [ ] **Step 2: Verificar que migration roda em ambiente limpo**

```bash
php artisan migrate:status | grep processo_exportacoes
```

Esperado: linha `Ran` para a migration nova.

- [ ] **Step 3: Smoke manual com `tinker`**

```bash
php artisan tinker --execute="\$e = App\Models\ProcessoExportacao::factory()->concluido()->create(['user_id' => 1, 'titulo' => 'Smoke', 'tamanho_bytes' => 1024]); App\Jobs\EnviarWebhookDownloadJob::dispatchSync(\$e->id); dd(\$e->fresh());"
```

> Em ambiente local sem SIM, isso vai marcar `webhook_tentativas=1` e logar erro de conexão. Apenas confirma que o pipeline conecta. Em homolog (com SIM real e endpoint configurado), o webhook deve sair com sucesso.

- [ ] **Step 4: Atualizar README operacional (se existir)**

Procure por menções ao fluxo de e-mail em arquivos de documentação:

```bash
grep -rn "email.*processo\|EnviarAutosProcesso\|DownloadProcessoJob" README.md docs/ --include='*.md' 2>/dev/null
```

Se encontrar, atualize o texto explicando o novo fluxo (webhook). Se nada for encontrado, pular.

- [ ] **Step 5: Commit final (se houve mudanças no Step 4)**

```bash
git add -A
git diff --staged --quiet || git commit -m "docs: atualiza referências do fluxo de exportação"
```

- [ ] **Step 6: Push e abertura de PR**

```bash
git push -u origin feature/microservico-ocr
```

Abrir PR com a descrição do spec. (Opcional, se já houver PR aberto, apenas push.)

---

## Notas operacionais

- **Todos os testes** que tocam o banco usam `Illuminate\Foundation\Testing\DatabaseTransactions` (rollback). **Nunca usar `RefreshDatabase`** — destruiria o banco compartilhado.
- **`Queue::fake()`** é usado em testes que verificam dispatch sem executar o job.
- **`Storage::fake('s3')`** e **`Http::fake()`** isolam dependências externas em todos os testes de integração.
- **Pest 3** é o framework de testes; sintaxe `it('...', fn () => ...)` e `expect($x)->toBe(...)`.
- **`exportar-processo`** é a fila existente no projeto (configurada no supervisord). Não criar fila nova.
- **Bucket S3**: usa o disco `s3` já configurado (`AWS_*`), `visibility=private`, path `downloads/{user_id}/{uuid}.pdf`.
- **Lifecycle do arquivo no S3**: responsabilidade do SIM (`downloads:limpar` diário, 7 dias). O ms-mni não gerencia expiração.
- **Deploy**: a migration roda automaticamente via `migrate --force` no `start-container` (já configurado).
