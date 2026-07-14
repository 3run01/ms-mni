# Callback genérico — desacoplar do SIM (Sub-projeto A) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Substituir o webhook fixo do SIM por um callback fornecido pelo chamador (`callback_url` + `callback_token`) em `/processo/download` e nos 3 endpoints `*/async`, entregando o PDF via presigned URL, e remover as referências ao SIM no código e na doc.

**Architecture:** Um validador de URL anti-SSRF e um notificador HTTP genérico, ambos reutilizados por exportação e async. O `callback_url`/`callback_token` é validado na entrada, persistido em `processo_exportacoes` (export) ou passado como arg de job (async), e usado no POST de notificação com header `X-API-Token`.

**Tech Stack:** Laravel 11, Pest, filas (`exportar-processo`), S3 (`Storage::disk('s3')`), `Illuminate\Support\Facades\Http`.

## Global Constraints

- `callback_url` e `callback_token` são **obrigatórios** em `/processo/download` e nos 3 `*/async`; ausência → `422`.
- `callback_url` deve ser **https** e é rejeitada se o host for/for resolvido para IP interno (`127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `::1`, `localhost`).
- Notificação de saída: `POST {callback_url}` com header `X-API-Token: {callback_token}` e corpo JSON.
- Callback de exportação `concluido` envia `download_url` = **presigned URL do S3 (60 min)**, nunca `s3_path` relativo.
- **NÃO** tocar na conexão de DB `sim` nem nos models `Tribunal`/`TipoDocumento` (Sub-projeto B).
- **NÃO** alterar docs históricos em `docs/superpowers/specs|plans`.
- Working tree tem arquivos não-relacionados não-commitados; subagents SEMPRE usam `git add` com caminhos específicos, NUNCA `git add -A`/`-u`.
- Rodar testes com `php artisan test` (o wrapper `./php` está quebrado — usar `php` direto). Baseline atual: ~8 falhas pré-existentes no pipeline de exportação; comparar contra o baseline, não exigir suíte 100% verde herdada.

---

### Task 1: Validador de URL de callback (anti-SSRF)

**Files:**
- Create: `app/Services/Callback/CallbackUrlValidator.php`
- Create: `app/Services/Callback/InvalidCallbackUrlException.php`
- Test: `tests/Unit/Services/Callback/CallbackUrlValidatorTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `CallbackUrlValidator::assertValida(string $url): void` (lança `InvalidCallbackUrlException`); `CallbackUrlValidator::ehValida(string $url): bool`. `InvalidCallbackUrlException extends \Exception`.

- [ ] **Step 1: Escrever o teste**

Criar `tests/Unit/Services/Callback/CallbackUrlValidatorTest.php`:

```php
<?php

use App\Services\Callback\CallbackUrlValidator;
use App\Services\Callback\InvalidCallbackUrlException;

beforeEach(fn () => $this->v = new CallbackUrlValidator());

it('aceita https público', function () {
    expect($this->v->ehValida('https://cliente.exemplo.gov.br/webhook'))->toBeTrue();
});

it('rejeita http', function () {
    expect($this->v->ehValida('http://cliente.exemplo.gov.br/webhook'))->toBeFalse();
});

it('rejeita URL malformada', function () {
    expect($this->v->ehValida('not a url'))->toBeFalse();
});

it('rejeita localhost e IPs internos', function () {
    foreach ([
        'https://localhost/webhook',
        'https://127.0.0.1/webhook',
        'https://10.1.2.3/webhook',
        'https://172.16.5.5/webhook',
        'https://192.168.0.10/webhook',
        'https://169.254.1.1/webhook',
    ] as $url) {
        expect($this->v->ehValida($url))->toBeFalse();
    }
});

it('assertValida lança em URL inválida', function () {
    $this->v->assertValida('http://localhost');
})->throws(InvalidCallbackUrlException::class);
```

- [ ] **Step 2: Rodar o teste (deve falhar)**

Run: `php artisan test tests/Unit/Services/Callback/CallbackUrlValidatorTest.php`
Expected: FAIL — classe `CallbackUrlValidator` não existe.

- [ ] **Step 3: Criar a exceção**

`app/Services/Callback/InvalidCallbackUrlException.php`:

```php
<?php

namespace App\Services\Callback;

use Exception;

class InvalidCallbackUrlException extends Exception {}
```

- [ ] **Step 4: Criar o validador**

`app/Services/Callback/CallbackUrlValidator.php`:

```php
<?php

namespace App\Services\Callback;

class CallbackUrlValidator
{
    public function ehValida(string $url): bool
    {
        try {
            $this->assertValida($url);
            return true;
        } catch (InvalidCallbackUrlException) {
            return false;
        }
    }

    public function assertValida(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            throw new InvalidCallbackUrlException('callback_url deve ser uma URL https válida');
        }

        $host = $parts['host'];

        if (strcasecmp($host, 'localhost') === 0) {
            throw new InvalidCallbackUrlException('callback_url não pode apontar para localhost');
        }

        // Resolve o host (ou usa o próprio literal se já for IP)
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new InvalidCallbackUrlException('callback_url não pode apontar para IP interno/privado');
        }
    }
}
```

- [ ] **Step 5: Rodar o teste (deve passar)**

Run: `php artisan test tests/Unit/Services/Callback/CallbackUrlValidatorTest.php`
Expected: PASS (6 asserções). Se `gethostbyname` de um host inexistente devolver o próprio host (não-IP), `filter_var` falha → tratado como inválido; ok.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Callback/CallbackUrlValidator.php app/Services/Callback/InvalidCallbackUrlException.php tests/Unit/Services/Callback/CallbackUrlValidatorTest.php
git commit -m "feat(callback): validador de URL de callback anti-SSRF"
```

---

### Task 2: Colunas callback em processo_exportacoes

**Files:**
- Create: `database/migrations/2026_07_14_000001_add_callback_to_processo_exportacoes_table.php`
- Modify: `app/Models/ProcessoExportacao.php:21-35` (fillable)
- Test: `tests/Unit/Models/ProcessoExportacaoTest.php` (adicionar caso)

**Interfaces:**
- Consumes: nada.
- Produces: colunas `callback_url` (string 2048, nullable) e `callback_token` (string 500, nullable) em `processo_exportacoes`; ambas no `$fillable` de `ProcessoExportacao`.

- [ ] **Step 1: Escrever o teste**

Adicionar em `tests/Unit/Models/ProcessoExportacaoTest.php`:

```php
it('persiste callback_url e callback_token', function () {
    $e = \App\Models\ProcessoExportacao::factory()->create([
        'callback_url' => 'https://cliente.exemplo.gov.br/webhook',
        'callback_token' => 'segredo-123',
    ]);

    expect($e->fresh()->callback_url)->toBe('https://cliente.exemplo.gov.br/webhook')
        ->and($e->fresh()->callback_token)->toBe('segredo-123');
});
```

- [ ] **Step 2: Rodar o teste (deve falhar)**

Run: `php artisan test tests/Unit/Models/ProcessoExportacaoTest.php`
Expected: FAIL — coluna `callback_url` não existe / não é fillable.

- [ ] **Step 3: Criar a migration**

`database/migrations/2026_07_14_000001_add_callback_to_processo_exportacoes_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processo_exportacoes', function (Blueprint $table) {
            $table->string('callback_url', 2048)->nullable()->after('filtros');
            $table->string('callback_token', 500)->nullable()->after('callback_url');
        });
    }

    public function down(): void
    {
        Schema::table('processo_exportacoes', function (Blueprint $table) {
            $table->dropColumn(['callback_url', 'callback_token']);
        });
    }
};
```

- [ ] **Step 4: Adicionar ao fillable**

Em `app/Models/ProcessoExportacao.php`, incluir no array `$fillable` (após `'filtros',`):

```php
        'filtros',
        'callback_url',
        'callback_token',
        'webhook_enviado_em',
```

- [ ] **Step 5: Rodar migrations + teste (deve passar)**

Run: `php artisan migrate && php artisan test tests/Unit/Models/ProcessoExportacaoTest.php`
Expected: migration aplicada; teste PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_14_000001_add_callback_to_processo_exportacoes_table.php app/Models/ProcessoExportacao.php tests/Unit/Models/ProcessoExportacaoTest.php
git commit -m "feat(callback): colunas callback_url/callback_token em processo_exportacoes"
```

---

### Task 3: Notificador genérico (rename WebhookDownloadClient → CallbackNotifier)

**Files:**
- Create: `app/Services/Callback/CallbackNotifier.php`
- Create: `app/Services/Callback/CallbackPermanentException.php`
- Delete: `app/Services/Exportacao/WebhookDownloadClient.php`, `app/Services/Exportacao/WebhookPermanentException.php`
- Test: `tests/Unit/Services/Callback/CallbackNotifierTest.php` (novo); remover `tests/Unit/Services/WebhookDownloadClientTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `CallbackNotifier::notificar(string $url, string $token, array $payload): void` — `POST $url` com header `X-API-Token: $token`. 4xx → `CallbackPermanentException(int $statusCode, string $message)`; 5xx/erro → relança (`$response->throw()`).

- [ ] **Step 1: Escrever o teste**

Criar `tests/Unit/Services/Callback/CallbackNotifierTest.php`:

```php
<?php

use App\Services\Callback\CallbackNotifier;
use App\Services\Callback\CallbackPermanentException;
use Illuminate\Support\Facades\Http;

it('envia POST com X-API-Token e payload', function () {
    Http::fake(['*' => Http::response('', 200)]);

    (new CallbackNotifier())->notificar('https://c.exemplo/webhook', 'tok-1', ['status' => 'concluido']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://c.exemplo/webhook'
            && $request->hasHeader('X-API-Token', 'tok-1')
            && $request['status'] === 'concluido';
    });
});

it('lança permanente em 4xx', function () {
    Http::fake(['*' => Http::response('rejeitado', 422)]);

    (new CallbackNotifier())->notificar('https://c.exemplo/webhook', 'tok-1', []);
})->throws(CallbackPermanentException::class);

it('relança em 5xx', function () {
    Http::fake(['*' => Http::response('erro', 500)]);

    (new CallbackNotifier())->notificar('https://c.exemplo/webhook', 'tok-1', []);
})->throws(\Illuminate\Http\Client\RequestException::class);
```

- [ ] **Step 2: Rodar o teste (deve falhar)**

Run: `php artisan test tests/Unit/Services/Callback/CallbackNotifierTest.php`
Expected: FAIL — `CallbackNotifier` não existe.

- [ ] **Step 3: Criar a exceção**

`app/Services/Callback/CallbackPermanentException.php`:

```php
<?php

namespace App\Services\Callback;

use Exception;

class CallbackPermanentException extends Exception
{
    public function __construct(public readonly int $statusCode, string $message = '')
    {
        parent::__construct($message ?: "Callback permanently rejected (HTTP {$statusCode})");
    }
}
```

- [ ] **Step 4: Criar o notificador**

`app/Services/Callback/CallbackNotifier.php`:

```php
<?php

namespace App\Services\Callback;

use Illuminate\Support\Facades\Http;

class CallbackNotifier
{
    public function notificar(string $url, string $token, array $payload): void
    {
        $response = Http::withHeaders(['X-API-Token' => $token])
            ->timeout(10)
            ->post($url, $payload);

        if ($response->successful()) {
            return;
        }

        if ($response->status() >= 400 && $response->status() < 500) {
            throw new CallbackPermanentException(
                $response->status(),
                "Callback rejeitado (HTTP {$response->status()}): {$response->body()}"
            );
        }

        $response->throw();
    }
}
```

- [ ] **Step 5: Remover os arquivos antigos do webhook SIM**

```bash
git rm app/Services/Exportacao/WebhookDownloadClient.php app/Services/Exportacao/WebhookPermanentException.php tests/Unit/Services/WebhookDownloadClientTest.php
```

- [ ] **Step 6: Rodar o teste novo (deve passar)**

Run: `php artisan test tests/Unit/Services/Callback/CallbackNotifierTest.php`
Expected: PASS (3 asserções).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Callback/CallbackNotifier.php app/Services/Callback/CallbackPermanentException.php tests/Unit/Services/Callback/CallbackNotifierTest.php
git commit -m "feat(callback): CallbackNotifier generico substitui WebhookDownloadClient"
```

Nota: o app ainda não compila até a Task 5 (o `EnviarWebhookDownloadJob` referencia o cliente removido). Task 5 reconecta.

---

### Task 4: Request de download aceita e persiste callback

**Files:**
- Modify: `app/Http/Requests/Api/CriarExportacaoProcessoRequest.php`
- Modify: `app/Services/Exportacao/ExportacaoProcessoService.php:18-35` (`criar`)
- Test: `tests/Feature/Api/DownloadProcessoControllerTest.php`

**Interfaces:**
- Consumes: `CallbackUrlValidator` (Task 1).
- Produces: request valida `callback_url` (required, https, não-interno via `CallbackUrlValidator`) e `callback_token` (required, string); `ExportacaoProcessoService::criar` persiste ambos.

- [ ] **Step 1: Escrever os testes**

Adicionar em `tests/Feature/Api/DownloadProcessoControllerTest.php`:

```php
it('rejeita download sem callback_url/callback_token', function () {
    $resposta = $this->withHeader('X-API-Token', tokenValido())
        ->postJson('/api/processo/download', [
            'numero_processo' => '6000146-58.2026.8.03.0004',
            'user_id' => 1,
            'titulo' => 'Autos',
            'formato' => 'pdf',
        ]);

    $resposta->assertStatus(422)
        ->assertJsonValidationErrors(['callback_url', 'callback_token']);
});

it('rejeita callback_url http ou interna', function () {
    $resposta = $this->withHeader('X-API-Token', tokenValido())
        ->postJson('/api/processo/download', [
            'numero_processo' => '6000146-58.2026.8.03.0004',
            'user_id' => 1,
            'titulo' => 'Autos',
            'formato' => 'pdf',
            'callback_url' => 'http://localhost/webhook',
            'callback_token' => 'tok',
        ]);

    $resposta->assertStatus(422)->assertJsonValidationErrors(['callback_url']);
});
```

Se não houver helper `tokenValido()` no arquivo, reutilizar o padrão de header já usado nos testes existentes deste arquivo (inspecionar o topo do arquivo e seguir o mesmo setup de token).

- [ ] **Step 2: Rodar (deve falhar)**

Run: `php artisan test tests/Feature/Api/DownloadProcessoControllerTest.php`
Expected: FAIL — hoje callback não é validado (recebe 404/200, não 422).

- [ ] **Step 3: Adicionar regras no FormRequest**

Em `app/Http/Requests/Api/CriarExportacaoProcessoRequest.php`, adicionar ao array de `rules()`:

```php
            'callback_url' => ['required', 'string', 'max:2048', function ($attribute, $value, $fail) {
                if (! app(\App\Services\Callback\CallbackUrlValidator::class)->ehValida((string) $value)) {
                    $fail('O callback_url deve ser uma URL https válida e não pode apontar para IP interno.');
                }
            }],
            'callback_token' => ['required', 'string', 'max:500'],
```

- [ ] **Step 4: Persistir no `criar`**

Em `app/Services/Exportacao/ExportacaoProcessoService.php`, no array de `ProcessoExportacao::create([...])` dentro de `criar`, adicionar após `'formato' => $dados['formato'],`:

```php
            'formato' => $dados['formato'],
            'callback_url' => $dados['callback_url'],
            'callback_token' => $dados['callback_token'],
            'status' => ProcessoExportacao::STATUS_ENFILEIRADO,
```

- [ ] **Step 5: Rodar (deve passar)**

Run: `php artisan test tests/Feature/Api/DownloadProcessoControllerTest.php`
Expected: os 2 testes novos PASS. (Outros testes deste arquivo que já enviavam payload sem callback precisam ser atualizados para incluir `callback_url`/`callback_token` válidos — atualizar cada chamada `postJson('/api/processo/download', [...])` adicionando `'callback_url' => 'https://cliente.exemplo.gov.br/webhook', 'callback_token' => 'tok'`.)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/Api/CriarExportacaoProcessoRequest.php app/Services/Exportacao/ExportacaoProcessoService.php tests/Feature/Api/DownloadProcessoControllerTest.php
git commit -m "feat(callback): valida e persiste callback_url/token no download"
```

---

### Task 5: Job de webhook usa callback persistido + presigned URL

**Files:**
- Modify: `app/Jobs/EnviarWebhookDownloadJob.php`
- Modify: `app/Services/Exportacao/ExportacaoProcessoService.php:49-61` (comentário `marcarComoFalhou`)
- Test: `tests/Feature/Jobs/EnviarWebhookDownloadJobTest.php`

**Interfaces:**
- Consumes: `CallbackNotifier` (Task 3), colunas callback (Task 2), presigned via `Storage::disk('s3')->temporaryUrl`.
- Produces: `EnviarWebhookDownloadJob::handle(CallbackNotifier $notifier)` que monta o payload e chama `$notifier->notificar($exportacao->callback_url, $exportacao->callback_token, $payload)`.

- [ ] **Step 1: Escrever o teste**

Substituir/atualizar `tests/Feature/Jobs/EnviarWebhookDownloadJobTest.php` para o novo contrato:

```php
<?php

use App\Jobs\EnviarWebhookDownloadJob;
use App\Models\ProcessoExportacao;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('notifica callback do chamador com download_url presigned em concluido', function () {
    Storage::fake('s3');
    Http::fake(['*' => Http::response('', 200)]);

    $e = ProcessoExportacao::factory()->create([
        'status' => ProcessoExportacao::STATUS_CONCLUIDO,
        's3_path' => 'downloads/1/abc.pdf',
        'tamanho_bytes' => 999,
        'callback_url' => 'https://cliente.exemplo.gov.br/webhook',
        'callback_token' => 'tok-xyz',
        'webhook_enviado_em' => null,
    ]);
    Storage::disk('s3')->put('downloads/1/abc.pdf', 'conteudo');

    EnviarWebhookDownloadJob::dispatchSync($e->id);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://cliente.exemplo.gov.br/webhook'
            && $request->hasHeader('X-API-Token', 'tok-xyz')
            && $request['status'] === 'concluido'
            && array_key_exists('download_url', $request->data())
            && $request['tamanho_bytes'] === 999
            && ! array_key_exists('s3_path', $request->data());
    });

    expect($e->fresh()->webhook_enviado_em)->not->toBeNull();
});

it('notifica erro_resumo em falhou', function () {
    Http::fake(['*' => Http::response('', 200)]);

    $e = ProcessoExportacao::factory()->create([
        'status' => ProcessoExportacao::STATUS_FALHOU,
        'erro_resumo' => 'sem documentos',
        'callback_url' => 'https://cliente.exemplo.gov.br/webhook',
        'callback_token' => 'tok',
        'webhook_enviado_em' => null,
    ]);

    EnviarWebhookDownloadJob::dispatchSync($e->id);

    Http::assertSent(fn ($r) => $r['status'] === 'falhou' && $r['erro_resumo'] === 'sem documentos');
});
```

- [ ] **Step 2: Rodar (deve falhar)**

Run: `php artisan test tests/Feature/Jobs/EnviarWebhookDownloadJobTest.php`
Expected: FAIL — job ainda referencia `WebhookDownloadClient` (classe removida) / não monta payload.

- [ ] **Step 3: Reescrever o job**

Substituir `app/Jobs/EnviarWebhookDownloadJob.php` por:

```php
<?php

namespace App\Jobs;

use App\Models\ProcessoExportacao;
use App\Services\Callback\CallbackNotifier;
use App\Services\Callback\CallbackPermanentException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EnviarWebhookDownloadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $exportacaoId) {}

    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600];
    }

    public function handle(CallbackNotifier $notifier): void
    {
        $exportacao = \DB::transaction(function () {
            $exportacao = ProcessoExportacao::query()->lockForUpdate()->find($this->exportacaoId);

            if (! $exportacao) {
                return null;
            }
            if ($exportacao->jaFoiNotificado()) {
                return false;
            }

            $exportacao->increment('webhook_tentativas');
            return $exportacao->fresh();
        });

        if ($exportacao === null) {
            Log::warning("[Exportacao:{$this->exportacaoId}] não encontrada ao enviar callback");
            return;
        }
        if ($exportacao === false) {
            return;
        }

        try {
            $notifier->notificar($exportacao->callback_url, $exportacao->callback_token, $this->montarPayload($exportacao));
        } catch (CallbackPermanentException $e) {
            Log::critical("[Exportacao:{$exportacao->id}] callback rejeitado (permanente)", [
                'status' => $e->statusCode,
                'mensagem' => $e->getMessage(),
            ]);
            $this->fail($e);
            return;
        }

        $exportacao->update(['webhook_enviado_em' => now()]);
        Log::info("[Exportacao:{$exportacao->id}] callback enviado");
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
                'download_url' => Storage::disk('s3')->temporaryUrl($exportacao->s3_path, now()->addMinutes(60)),
                'tamanho_bytes' => $exportacao->tamanho_bytes,
            ];
        }

        return $base + ['erro_resumo' => $exportacao->erro_resumo];
    }

    public function failed(\Throwable $e): void
    {
        Log::critical("[Exportacao:{$this->exportacaoId}] esgotou tentativas de callback", [
            'erro' => $e->getMessage(),
        ]);
    }
}
```

- [ ] **Step 4: Ajustar comentário do service**

Em `app/Services/Exportacao/ExportacaoProcessoService.php`, no docblock de `marcarComoFalhou`, trocar "webhook de notificação ao SIM" por "callback de notificação ao chamador".

- [ ] **Step 5: Rodar (deve passar)**

Run: `php artisan test tests/Feature/Jobs/EnviarWebhookDownloadJobTest.php`
Expected: PASS (2 asserções principais).

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/EnviarWebhookDownloadJob.php app/Services/Exportacao/ExportacaoProcessoService.php tests/Feature/Jobs/EnviarWebhookDownloadJobTest.php
git commit -m "feat(callback): job de exportacao notifica callback do chamador com presigned url"
```

---

### Task 6: Endpoints async aceitam callback e notificam

**Files:**
- Modify: `app/Http/Controllers/Api/ConsultarProcessoController.php` (`consultarDadosBasicosAsync`, `consultarMovimentosAsync`) e `app/Http/Controllers/Api/DocumentoController.php` (`consultarDocumentosAsync`)
- Modify: `app/Jobs/ConsultarDadosBasicosProcessoMNIJob.php`, `app/Jobs/ConsultarMovimentosProcessoMNIJob.php`, `app/Jobs/ConsultarDocumentosProcessoMNIJob.php`
- Test: `tests/Feature/ConsultarAsyncJobsTest.php` (ou o arquivo de teste async existente — inspecionar; o ledger cita `ConsultarAsyncJobsTest`)

**Interfaces:**
- Consumes: `CallbackNotifier` (Task 3), `CallbackUrlValidator` (Task 1).
- Produces: os 3 jobs recebem `callback_url` e `callback_token` como args do construtor (após os args atuais) e, ao fim do `handle`, chamam `app(CallbackNotifier::class)->notificar($this->callback_url, $this->callback_token, ['numero_processo' => ..., 'tipo' => '<dados-basicos|movimentos|documentos>', 'status' => 'concluido'])`. Os endpoints validam `callback_url`/`callback_token` (required, https, não-interno) e os repassam no `dispatch`.

- [ ] **Step 1: Escrever o teste**

Adicionar ao arquivo de teste async (usar `Queue::fake()` + `Http::fake()`); exemplo para movimentos:

```php
use Illuminate\Support\Facades\Http;

it('async movimentos exige callback e job notifica o chamador', function () {
    // sem callback → 422
    $this->withHeader('X-API-Token', tokenValido())
        ->getJson('/api/processo/consultar/movimentos/async?login_pje=a&senha_pje=b&tribunal_id=1&numero_processo=X')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['callback_url', 'callback_token']);
});

it('job de movimentos POSTa callback ao concluir', function () {
    Http::fake(['*' => Http::response('', 200)]);
    // stub do ProcessoService para não bater no MNI real:
    $this->mock(\App\Services\Processo\ProcessoService::class, function ($m) {
        $m->shouldReceive('consultarMovimentos')->andReturn(new \App\Models\Processo());
    });

    (new \App\Jobs\ConsultarMovimentosProcessoMNIJob(1, '123', 'a', 'b', 'https://c.exemplo/webhook', 'tok'))->handle();

    Http::assertSent(fn ($r) => $r->url() === 'https://c.exemplo/webhook'
        && $r->hasHeader('X-API-Token', 'tok')
        && $r['tipo'] === 'movimentos' && $r['status'] === 'concluido');
});
```

Replicar os dois casos para dados-básicos (`tipo=dados-basicos`) e documentos (`tipo=documentos`). Inspecionar o setup de token/rota já usado no arquivo async existente e segui-lo.

- [ ] **Step 2: Rodar (deve falhar)**

Run: `php artisan test --filter=async`
Expected: FAIL — construtor dos jobs não aceita callback; endpoints não validam.

- [ ] **Step 3: Atualizar os 3 jobs**

Em cada job (`ConsultarMovimentosProcessoMNIJob`, `ConsultarDadosBasicosProcessoMNIJob`, `ConsultarDocumentosProcessoMNIJob`):
(a) adicionar propriedades e params ao construtor; (b) trocar a chamada `Http::...->get(env('SIM_APP_URL')...)` pela notificação do callback.

Exemplo completo para `app/Jobs/ConsultarMovimentosProcessoMNIJob.php`:

```php
<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\Processo\ProcessoService;
use App\Services\Callback\CallbackNotifier;
use App\Models\Tribunal;

class ConsultarMovimentosProcessoMNIJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $tribunal_id,
        public $numero_processo,
        public $login_pje = null,
        public $senha_pje = null,
        public ?string $callback_url = null,
        public ?string $callback_token = null,
    ) {}

    public function handle(): void
    {
        $processoService = app(ProcessoService::class);
        $processoService->consultarMovimentos(
            Tribunal::find($this->tribunal_id),
            $this->numero_processo,
            $this->login_pje,
            $this->senha_pje
        );

        app(CallbackNotifier::class)->notificar($this->callback_url, $this->callback_token, [
            'numero_processo' => $this->numero_processo,
            'tipo' => 'movimentos',
            'status' => 'concluido',
        ]);
    }
}
```

Para `ConsultarDadosBasicosProcessoMNIJob`: mesma estrutura, chamando `consultarDadosBasicos(...)` e `'tipo' => 'dados-basicos'`. Para `ConsultarDocumentosProcessoMNIJob`: chamando `consultarDocumentos(...)` e `'tipo' => 'documentos'`. Manter a assinatura de cada `consultar*` idêntica à atual (inspecionar o método atual de cada job antes de editar).

- [ ] **Step 4: Atualizar os controllers**

Em `ConsultarProcessoController::consultarDadosBasicosAsync` e `consultarMovimentosAsync`, e em `DocumentoController::consultarDocumentosAsync`: adicionar ao `$request->validate([...])` as regras de callback e repassá-las no `dispatch`. Exemplo para `consultarMovimentosAsync`:

```php
    public function consultarMovimentosAsync(Request $request)
    {
        $request->validate([
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
            'callback_url' => ['required', 'string', 'max:2048', function ($a, $v, $fail) {
                if (! app(\App\Services\Callback\CallbackUrlValidator::class)->ehValida((string) $v)) {
                    $fail('O callback_url deve ser uma URL https válida e não pode apontar para IP interno.');
                }
            }],
            'callback_token' => ['required', 'string', 'max:500'],
        ]);

        $numero_processo = cleanNumeroProcesso($request->numero_processo);
        ConsultarMovimentosProcessoMNIJob::dispatch(
            $request->tribunal_id, $numero_processo, $request->login_pje, $request->senha_pje,
            $request->callback_url, $request->callback_token
        )->onQueue('alta');
    }
```

Replicar a mesma regra de validação e o repasse dos 2 args no `dispatch` dos outros dois métodos async.

- [ ] **Step 5: Rodar (deve passar)**

Run: `php artisan test --filter=async`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/ConsultarProcessoController.php app/Http/Controllers/Api/DocumentoController.php app/Jobs/ConsultarDadosBasicosProcessoMNIJob.php app/Jobs/ConsultarMovimentosProcessoMNIJob.php app/Jobs/ConsultarDocumentosProcessoMNIJob.php tests/Feature/ConsultarAsyncJobsTest.php
git commit -m "feat(callback): endpoints async aceitam callback e jobs notificam o chamador"
```

---

### Task 7: Remover config/env do SIM + limpeza de strings

**Files:**
- Modify: `config/services.php:38-42` (remover bloco `sim_webhook_download`)
- Modify: `.env.example` (remover vars SIM)
- Modify: `app/Console/Commands/ExportacoesReenviarWebhook.php` (se citar SIM em texto)
- Test: nenhum novo; rodar a suíte de exportação para garantir que nada quebrou.

**Interfaces:**
- Consumes: nada.
- Produces: zero referências a `services.sim_webhook_download`, `SIM_APP_URL`, `SIM_WEBHOOK_TIMEOUT`, `SIM_WEBHOOK_DOWNLOAD_URL`, `SIM_API_TOKEN` no código de app e no `.env.example`.

- [ ] **Step 1: Remover o bloco de config**

Em `config/services.php`, apagar as linhas 38-42 (o array `'sim_webhook_download' => [ ... ],`).

- [ ] **Step 2: Limpar o `.env.example`**

Remover as linhas 71-74 do `.env.example` (`# Webhook do SIM (Central de Downloads)`, `SIM_WEBHOOK_DOWNLOAD_URL=...`, `SIM_API_TOKEN=`, `SIM_WEBHOOK_TIMEOUT=10`).

- [ ] **Step 3: Grep de regressão**

Run:
```bash
grep -rnE "sim_webhook_download|SIM_APP_URL|SIM_WEBHOOK_TIMEOUT|SIM_WEBHOOK_DOWNLOAD_URL|SIM_API_TOKEN|WebhookDownloadClient|WebhookPermanentException" --include="*.php" -- app config tests | grep -vE "vendor/"
```
Expected: nenhuma saída. Se aparecer algo em comentários/strings, remover/genericizar (ex.: comando `ExportacoesReenviarWebhook` — trocar menção a "SIM" por "callback do chamador"). NÃO alterar `docs/superpowers/`.

- [ ] **Step 4: Rodar a suíte de exportação/async**

Run: `php artisan test --filter="Exportacao|Webhook|async|Download|Callback"`
Expected: verde (comparar contra o baseline; nenhuma nova falha atribuível a esta mudança).

- [ ] **Step 5: Commit**

```bash
git add config/services.php .env.example app/Console/Commands/ExportacoesReenviarWebhook.php
git commit -m "chore(callback): remove config/env e strings do webhook SIM"
```

---

### Task 8: Atualizar a documentação OpenAPI

**Files:**
- Modify: `docs/api/openapi.yaml`
- Test: `npx --yes @redocly/cli@latest lint docs/api/openapi.yaml`

**Interfaces:**
- Consumes: novo contrato (callback_url/token, download_url presigned, payload async).
- Produces: request body de `/processo/download` e params dos `*/async` com `callback_url`/`callback_token`; seção `webhooks` genérica (sem "SIM"), com `download_url` presigned e o webhook async.

- [ ] **Step 1: Adicionar callback ao request body de `/processo/download`**

No schema do requestBody de `POST /processo/download`, adicionar às `properties` e ao `required`:

```yaml
                callback_url:
                  type: string
                  format: uri
                  description: "URL https do chamador que receberá a notificação de conclusão. Bloqueia IPs internos."
                callback_token:
                  type: string
                  description: "Token reenviado no header X-API-Token da chamada de callback, para o chamador validar a origem."
```
e incluir `callback_url`, `callback_token` na lista `required` (junto de numero_processo/user_id/titulo/formato).

- [ ] **Step 2: Adicionar callback aos 3 endpoints async**

Em cada um dos `/processo/consultar/{dados-basicos,movimentos,documentos}/async`, adicionar aos `parameters`:

```yaml
        - name: callback_url
          in: query
          required: true
          description: "URL https de callback (bloqueia IPs internos)."
          schema: { type: string, format: uri }
        - name: callback_token
          in: query
          required: true
          description: "Token reenviado no header X-API-Token do callback."
          schema: { type: string }
```

- [ ] **Step 3: Reescrever a seção `webhooks`**

Substituir a descrição/summary da seção `webhooks: download` para remover "cliente SIM" e refletir o novo contrato:
- `description`: "Ao concluir (ou falhar) a exportação, o ms-mni faz `POST {callback_url}` (a URL informada na requisição de download), com header `X-API-Token: {callback_token}`. O chamador implementa este endpoint."
- No schema do payload: **remover `s3_path`**, **adicionar** `download_url` (`type: string, format: uri`, descrição "URL pré-assinada do S3, validade 60 min. Presente quando status=concluido."). Manter `user_id, titulo, formato, status[enum concluido,falhou], tamanho_bytes, erro_resumo`.

Adicionar um segundo webhook `atualizar_processo`:
```yaml
  atualizar_processo:
    post:
      summary: Notificação de conclusão de consulta assíncrona
      description: |
        Ao concluir uma consulta assíncrona (dados-básicos/movimentos/documentos),
        o ms-mni faz POST na callback_url informada, com header X-API-Token.
      requestBody:
        content:
          application/json:
            schema:
              type: object
              required: [numero_processo, tipo, status]
              properties:
                numero_processo: { type: string }
                tipo: { type: string, enum: [dados-basicos, movimentos, documentos] }
                status: { type: string, example: concluido }
      responses:
        '200': { description: Webhook recebido pelo chamador. }
```

- [ ] **Step 4: Validar**

Run: `npx --yes @redocly/cli@latest lint docs/api/openapi.yaml`
Expected: 0 errors. `grep -c "cliente SIM\|s3_path" docs/api/openapi.yaml` → 0.

- [ ] **Step 5: Commit**

```bash
git add docs/api/openapi.yaml
git commit -m "docs(api): callback generico na doc (callback_url/token, download_url presigned)"
```

---

### Task 9: Verificação final

**Files:** nenhum (validação).

- [ ] **Step 1: Grep final de SIM funcional**

Run:
```bash
grep -rnE "sim_webhook_download|SIM_APP_URL|SIM_WEBHOOK|WebhookDownloadClient|WebhookPermanentException" --include="*.php" --include="*.yaml" -- app config tests docs/api | grep -vE "vendor/"
```
Expected: vazio.

- [ ] **Step 2: Suíte completa**

Run: `php artisan test`
Expected: comparar contra o baseline (~8 falhas pré-existentes de exportação por env). Nenhuma nova falha atribuível a esta mudança; os testes novos de callback verdes. Registrar contagem antes/depois.

- [ ] **Step 3: Lint da doc**

Run: `npx --yes @redocly/cli@latest lint docs/api/openapi.yaml`
Expected: 0 errors.

---

## Notas de execução

- Ordem obrigatória: o app fica sem compilar entre a Task 3 (remove `WebhookDownloadClient`) e a Task 5 (reconecta o job). Não rodar a suíte completa entre elas; rodar só os testes focados de cada task.
- `ProcessoExportacaoFactory` pode precisar de defaults para `callback_url`/`callback_token` se algum teste criar exportação sem informá-los — adicionar defaults válidos na factory se testes existentes quebrarem por NOT-fillable/uso.
- NÃO tocar `Tribunal`, `TipoDocumento`, `config/database.php` (conexão `sim`) nem docs históricos — é o Sub-projeto B.
