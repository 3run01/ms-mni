# Remoção OCR/SAMIA + Credenciais PJe Obrigatórias — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remover completamente OCR e SAMIA (código + colunas DB) e tornar `login_pje`/`senha_pje` obrigatórios em `GET /processo/consultar` e `GET /processo/visualizar`.

**Architecture:** Laravel 11 API (sim-mni). Remoção em camadas: primeiro a mudança de contrato (validação de credenciais, com testes), depois remoção de código OCR, depois SAMIA, depois config/env, depois modelos + migration de drop de colunas, por fim docs. Cada task deixa a suite verde.

**Tech Stack:** PHP 8.x / Laravel 11, Pest (testes), PostgreSQL, Horizon (filas), Redis.

**Spec:** `docs/superpowers/specs/2026-07-07-remocao-ocr-samia-credenciais-obrigatorias-design.md`

## Global Constraints

- Testes: Pest, padrão existente — `uses(DatabaseTransactions::class)`, `config()->set('services.api.token', 'tk-test')` no `beforeEach`, header `X-API-Token` nas chamadas.
- Rodar suite: `php artisan test` (requer serviços do `docker-compose.yml` de pé; alternativa: `./vendor/bin/pest`).
- Validação inline no controller — NÃO criar FormRequest.
- Service layer (fallback `?? $tribunal->login`) NÃO deve ser alterado.
- Nomes/comentários em pt-BR, seguindo estilo do arquivo tocado.
- Commits frequentes, mensagens conventional commits em pt-BR (padrão do repo: `feat:`, `fix:`, `docs:`, `refactor:`).

---

### Task 1: Credenciais obrigatórias em `/processo/consultar` e `/processo/visualizar`

**Files:**

- Modify: `app/Http/Controllers/Api/ConsultarProcessoController.php:31-48` (index), `:50-104` (show), `:106-128` (buscarPorNumeroProcesso)
- Test: `tests/Feature/Api/ConsultarProcessoControllerTest.php` (create)

**Interfaces:**

- Consumes: `ProcessoService::consultarNumero(Tribunal $tribunal, $numero_processo, $login_pje = null, $senha_pje = null)` (existente, não muda).
- Produces: `GET /api/processo/consultar` e `GET /api/processo/visualizar` retornam **422** (formato padrão Laravel `{message, errors}`) quando `login_pje` ou `senha_pje` ausentes — sempre, mesmo com processo em banco. Demais endpoints inalterados.

**Contexto para quem nunca viu o projeto:** o controller usa validação manual inline (sem FormRequest) e envolve o corpo em `try/catch (\Exception)` genérico que converte exceções em JSON de erro. `Illuminate\Validation\ValidationException` estende `\Exception` — se `$request->validate()` ficar DENTRO do try, o catch genérico engole a exceção e devolve 500 em vez de 422. Por isso a validação vai ANTES do try.

- [ ] **Step 1: Escrever os testes que falham**

Criar `tests/Feature/Api/ConsultarProcessoControllerTest.php`:

```php
<?php

use App\Jobs\BaixarProcessoMNIJob;
use App\Models\Processo;
use App\Services\Processo\ProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config()->set('services.api.token', 'tk-test');
});

function criarProcessoParaConsulta(string $numero, int $tribunalId = 1): Processo
{
    return Processo::create([
        'numero_processo' => cleanNumeroProcesso($numero),
        'tribunal_id' => $tribunalId,
        'valor_causa' => '0.00',
    ]);
}

// ---------- GET /api/processo/consultar ----------

it('consultar sem login_pje e senha_pje retorna 422', function () {
    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});

it('consultar com login_pje mas sem senha_pje retorna 422', function () {
    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=usuario');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['senha_pje'])
        ->assertJsonMissingValidationErrors(['login_pje']);
});

it('consultar com credenciais e processo existente retorna 200 e agenda refresh', function () {
    Queue::fake();
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=usuario&senha_pje=segredo');

    $response->assertOk();
    Queue::assertPushed(BaixarProcessoMNIJob::class);
});

it('consultar com processo inexistente repassa credenciais ao ProcessoService', function () {
    $this->mock(ProcessoService::class, function ($mock) {
        $mock->shouldReceive('consultarNumero')
            ->once()
            ->withArgs(function ($tribunal, $numero, $login, $senha) {
                return $login === 'usuario-pje' && $senha === 'segredo-pje';
            });
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar?tribunal_id=1&numero_processo=9999999-99.2024.8.03.9999&login_pje=usuario-pje&senha_pje=segredo-pje')
        ->assertOk();
});

// ---------- GET /api/processo/visualizar ----------

it('visualizar sem credenciais retorna 422 mesmo com processo em banco', function () {
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/visualizar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});

it('visualizar com credenciais e processo existente retorna 200', function () {
    Queue::fake();
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/visualizar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=usuario&senha_pje=segredo');

    $response->assertOk();
    Queue::assertPushed(BaixarProcessoMNIJob::class);
});

it('visualizar com processo inexistente repassa credenciais ao ProcessoService', function () {
    $this->mock(ProcessoService::class, function ($mock) {
        $mock->shouldReceive('consultarNumero')
            ->once()
            ->withArgs(function ($tribunal, $numero, $login, $senha) {
                return $login === 'usuario-pje' && $senha === 'segredo-pje';
            });
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/visualizar?tribunal_id=1&numero_processo=9999999-99.2024.8.03.9999&login_pje=usuario-pje&senha_pje=segredo-pje')
        ->assertOk();
});

// ---------- endpoints que continuam SEM exigir credenciais ----------

it('dados-basicos continua funcionando sem credenciais (fallback tribunal)', function () {
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $response = $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/dados-basicos?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003');

    $response->assertOk();
});
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `php artisan test --filter=ConsultarProcessoControllerTest`
Expected: FAIL — os testes de 422 recebem 200/400/500 (validação ainda não existe); o teste de propagação em `consultar` falha porque `buscarPorNumeroProcesso` não repassa credenciais (mock não recebe os args esperados).

- [ ] **Step 3: Implementar a validação e o repasse de credenciais**

Em `app/Http/Controllers/Api/ConsultarProcessoController.php`:

**3a.** `index()` — adicionar validação ANTES do `try` (linhas 31-33 atuais):

```php
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
        ]);

        try {
            if (!$request->tribunal_id) {
```

**3b.** `show()` — mesma validação ANTES do `try`:

```php
    public function show(Request $request): JsonResponse
    {
        $request->validate([
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
        ]);

        try {
            if (!$request->tribunal_id) {
```

**3c.** `buscarPorNumeroProcesso()` — usar o service injetado e repassar credenciais. Substituir:

```php
        if ($processos->total() == 0) {
            //baixa o processo caso nao exista
            $processoService = new ProcessoService();
            $processoService->consultarNumero(
                Tribunal::find($request->tribunal_id),
                $numero_processo
            );
```

por:

```php
        if ($processos->total() == 0) {
            //baixa o processo caso nao exista
            $this->processoService->consultarNumero(
                Tribunal::find($request->tribunal_id),
                $numero_processo,
                $request->login_pje,
                $request->senha_pje
            );
```

(O `show()` já repassa `$request->login_pje ?? null` / `$request->senha_pje ?? null` nas linhas 86-87 — pode manter como está; com a validação, nunca serão null.)

- [ ] **Step 4: Rodar os testes e confirmar que passam**

Run: `php artisan test --filter=ConsultarProcessoControllerTest`
Expected: PASS (8 testes)

- [ ] **Step 5: Rodar a suite completa**

Run: `php artisan test`
Expected: PASS — nenhum teste existente cobre consultar/visualizar sem credenciais, então nada deve quebrar.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/ConsultarProcessoController.php tests/Feature/Api/ConsultarProcessoControllerTest.php
git commit -m "feat: exige login_pje e senha_pje em /processo/consultar e /processo/visualizar"
```

---

### Task 2: Remover código OCR

**Files:**

- Delete: `app/Http/Controllers/Api/OCRProcessoController.php`, `app/Http/Controllers/Api/OCRDocumentoController.php`, `app/Http/Controllers/Api/OCRWebhookController.php`, `app/Http/Middleware/ValidateOCRWebhook.php`, `app/Jobs/OCRProcessoJob.php`, `app/Jobs/OCRRequestJob.php`, `app/Jobs/JuntarOCRProcessoJob.php`, `app/Console/Commands/OCRPollStatus.php`, `tests/Feature/Api/OCRWebhookControllerTest.php`, `tests/Feature/Jobs/OCRRequestJobTest.php`, `tests/Feature/Console/OCRPollStatusTest.php`
- Modify: `routes/api.php`, `app/Jobs/BaixarDocumentoMNIJob.php`, `app/Services/Processo/SalvarDocumentoProcessoService.php:6-7,133-136,752-764`, `app/Http/Controllers/Api/ConsultarProcessoController.php:71`, `app/Console/Commands/CleanTempFiles.php:15`, `app/Console/Commands/CleanDuplicateFailedJobs.php:15`

**Interfaces:**

- Consumes: nada de tasks anteriores.
- Produces: `POST /api/ocr/webhook`, `POST /api/processo/ocr`, `POST /api/documento/ocr` e `GET /api/processo-pje/consultar` deixam de existir (404). `BaixarDocumentoMNIJob::__construct($documento, $login_pje = null, $senha_pje = null)` (4º parâmetro legado removido). Comandos renomeados: `app:clean-temp`, `app:clean-duplicate-failed-jobs`.

- [ ] **Step 1: Deletar arquivos OCR**

```bash
git rm app/Http/Controllers/Api/OCRProcessoController.php \
       app/Http/Controllers/Api/OCRDocumentoController.php \
       app/Http/Controllers/Api/OCRWebhookController.php \
       app/Http/Middleware/ValidateOCRWebhook.php \
       app/Jobs/OCRProcessoJob.php \
       app/Jobs/OCRRequestJob.php \
       app/Jobs/JuntarOCRProcessoJob.php \
       app/Console/Commands/OCRPollStatus.php \
       tests/Feature/Api/OCRWebhookControllerTest.php \
       tests/Feature/Jobs/OCRRequestJobTest.php \
       tests/Feature/Console/OCRPollStatusTest.php
```

- [ ] **Step 2: Limpar rotas**

Substituir o conteúdo de `routes/api.php` por:

```php
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\ConsultarProcessoController;
use App\Http\Controllers\Api\DocumentoController;
use App\Http\Controllers\Api\DownloadProcessoController;
use App\Http\Controllers\Api\TribunalController;
use App\Http\Middleware\ValidateApiToken;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware(ValidateApiToken::class)->group(function () {
    Route::get('/processo/consultar', [ConsultarProcessoController::class, 'index']);
    Route::get('/processo/visualizar', [ConsultarProcessoController::class, 'show']);

    Route::get('/documento/visualizar', [DocumentoController::class, 'show']);
    Route::resource('/tribunais', TribunalController::class)->only(['index', 'show']);
    Route::post('/processo/download', [DownloadProcessoController::class, 'store']);

    //consultar dados basicos, movimentos e documentos
    Route::get('/processo/dados-basicos', [ConsultarProcessoController::class, 'consultarDadosBasicos']);
    Route::get('/processo/movimentos/listar', [ConsultarProcessoController::class, 'consultarMovimentos']);
    Route::get('/processo/documentos/listar', [DocumentoController::class, 'listarDocumentos']);

    Route::group(['prefix' => '/processo/consultar'], function () {
        Route::get('/dados-basicos/async', [ConsultarProcessoController::class, 'consultarDadosBasicosAsync']);
        Route::get('/movimentos/async', [ConsultarProcessoController::class, 'consultarMovimentosAsync']);
        Route::get('/documentos/async', [DocumentoController::class, 'consultarDocumentosAsync']);
    });
});
```

Removidos: rotas OCR (webhook/processo/documento), imports `OCRProcessoController`/`OCRDocumentoController`/`OCRWebhookController`, e a rota morta `GET /processo-pje/consultar` (apontava para `ConsultarProcessoController@consultarPje`, método que não existe — 500 garantido).

- [ ] **Step 3: Limpar `BaixarDocumentoMNIJob`**

Substituir o conteúdo de `app/Jobs/BaixarDocumentoMNIJob.php` por:

```php
<?php

namespace App\Jobs;

use App\Exceptions\MNIException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Support\Facades\Log;

class BaixarDocumentoMNIJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    public $priority = 2;
    public int $uniqueFor = 1800;

    private $documento;
    private $login_pje;
    private $senha_pje;

    /**
     * Create a new job instance.
     */
    public function __construct($documento, $login_pje = null, $senha_pje = null)
    {
        $this->documento = $documento;
        $this->login_pje = $login_pje;
        $this->senha_pje = $senha_pje;
    }

    public function uniqueId(): string
    {
        return (string) $this->documento->id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $salvarDocumentoProcessoService = new SalvarDocumentoProcessoService();
            $salvarDocumentoProcessoService->baixarDocumento($this->documento, $this->login_pje, $this->senha_pje);
            $this->documento->tentativas_download++;
            $this->documento->save();
        } catch (MNIException $e) {
            Log::error('Erro ao baixar documento: ' . $this->documento->id_documento . ' - Processo: ' . $this->documento->processo->numero_processo . ' - ' . $e->getError() . ' - Arquivo: ' . $e->getFile() . ' - Linha: ' . $e->getLine());
        } catch (\Exception $e) {
            Log::error('Erro ao baixar documento: ' . $this->documento->id_documento . ' - Processo: ' . $this->documento->processo->numero_processo . ' - ' . $e->getMessage() . ' - Arquivo: ' . $e->getFile() . ' - Linha: ' . $e->getLine());
        }
    }
}
```

Removidos: gate `processoTemOcrSolicitado()`, dispatch de `OCRRequestJob`, imports `Processo`/`ProcessoDocumento`/`OCRRequestJob`, 4º parâmetro legado do construtor (nenhum chamador passa 4 argumentos — verificado).

- [ ] **Step 4: Limpar `SalvarDocumentoProcessoService`**

**4a.** Remover o import na linha 7:

```php
use App\Jobs\OCRRequestJob;
```

**4b.** Remover a chamada dentro de `baixarDocumento()` (por volta da linha 133-136). Trecho atual:

```php
            // Aguarda um momento para garantir que o arquivo esteja disponível
            sleep(1);

            // Realiza OCR
            $this->realizarOCR($documento);

            // Recarrega o documento do banco para garantir dados atualizados
            return ProcessoDocumento::find($documento->id);
```

Trecho novo (o `sleep(1)` existia só para dar tempo ao arquivo antes do OCR — sai junto):

```php
            // Recarrega o documento do banco para garantir dados atualizados
            return ProcessoDocumento::find($documento->id);
```

**4c.** Remover o método `realizarOCR()` inteiro (linhas 752-764):

```php
    public function realizarOCR($documento)
    {
        if (
            !$documento->ocr_enviado_fila &&
            !$documento->ocr_processado &&
            in_array($documento->processo?->knowledge_base_status_sync, [
                Processo::KNOWLEDGE_BASE_STATUS_STARTING,
                Processo::KNOWLEDGE_BASE_STATUS_IN_PROGRESS,
                Processo::KNOWLEDGE_BASE_STATUS_COMPLETE
            ])
        ) {
            OCRRequestJob::dispatch($documento)->onQueue('ocr-request');
        }
    }
```

- [ ] **Step 5: Remover `ocr_processado` do select em `ConsultarProcessoController`**

Na linha 71 de `app/Http/Controllers/Api/ConsultarProcessoController.php`, trocar:

```php
                    $q->select('id', 'id_documento', 'descricao', 'id_documento_vinculado', 'movimento', 'tipo_documento', 'data_hora', 'nivel_sigilo', 'processo_id', 'ocr_processado');
```

por:

```php
                    $q->select('id', 'id_documento', 'descricao', 'id_documento_vinculado', 'movimento', 'tipo_documento', 'data_hora', 'nivel_sigilo', 'processo_id');
```

(Crítico: a coluna será dropada na Task 5 — se ficar no select, o endpoint quebra com erro SQL.)

- [ ] **Step 6: Renomear signatures dos commands de limpeza**

Em `app/Console/Commands/CleanTempFiles.php` linha 15:

```php
    protected $signature = 'app:clean-temp {--hours=24 : Arquivos mais antigos que X horas}';
```

Em `app/Console/Commands/CleanDuplicateFailedJobs.php` linha 15:

```php
    protected $signature = 'app:clean-duplicate-failed-jobs {--dry-run : Simular a limpeza sem deletar}';
```

(Os commands são utilitários genéricos — só o prefixo `ocr:` era herança. Nenhum scheduler/script referencia os nomes antigos — verificado em `routes/console.php`, yamls e docker/.)

- [ ] **Step 7: Rodar a suite completa**

Run: `php artisan test`
Expected: PASS. Se aparecer erro de classe não encontrada, procurar referência órfã com `grep -rn "OCRProcessoJob\|OCRRequestJob\|JuntarOCRProcessoJob\|OCRWebhook\|OCRPollStatus" app/ routes/ tests/ --include="*.php"` — o resultado deve ser vazio.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "refactor: remove funcionalidade de OCR (rotas, controllers, jobs, command, testes)"
```

---

### Task 3: Remover código SAMIA

**Files:**

- Delete: `app/Services/SamiaService.php`, `app/Jobs/SyncBaseConhecimentoSamiaJob.php`, `app/Console/Commands/SyncBaseConhecimento.php`
- Modify: `app/Helpers/functions.php:196-207`, `app/Models/Notificacao.php:9`

**Interfaces:**

- Consumes: Task 2 já removeu os arquivos OCR que referenciavam `SamiaService`/`Notificacao::TIPO_OCR_PROCESSO`.
- Produces: comando `samia:sync-base-conhecimento` e helper global `samia()` deixam de existir.

- [ ] **Step 1: Deletar arquivos SAMIA**

```bash
git rm app/Services/SamiaService.php \
       app/Jobs/SyncBaseConhecimentoSamiaJob.php \
       app/Console/Commands/SyncBaseConhecimento.php
```

- [ ] **Step 2: Remover o helper `samia()`**

Em `app/Helpers/functions.php`, remover o bloco (linhas ~196-207):

```php
if (!function_exists('samia')) {
    /**
     * Helper para acessar o serviço Samia
     *
     * @return \App\Services\SamiaService
     */
    function samia(): \App\Services\SamiaService
    {
        return app(\App\Services\SamiaService::class);
    }
}
```

- [ ] **Step 3: Remover a const órfã em `Notificacao`**

Em `app/Models/Notificacao.php`, remover a linha 9:

```php
    const TIPO_OCR_PROCESSO = 'OCRProcesso';
```

(Únicos usuários eram `OCRProcessoJob` e `SyncBaseConhecimentoSamiaJob`, ambos já deletados. O model `Notificacao` em si fica — a tabela existe e `TIPO_DOWNLOAD_PROCESSO` permanece.)

- [ ] **Step 4: Verificar ausência de referências órfãs**

Run: `grep -rn -i "samia" app/ routes/ tests/ --include="*.php"`
Expected: saída vazia.

- [ ] **Step 5: Rodar a suite completa**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor: remove integracao SAMIA (service, job, command, helper)"
```

---

### Task 4: Limpar config e env

**Files:**

- Modify: `config/services.php:42-47,55-73`, `config/filesystems.php:60-70`, `config/horizon.php:103-105,240-256,274-290,317-325,338-346`, `.env.example:76-81`, `.env` (local, fora do git)

**Interfaces:**

- Consumes: Tasks 2-3 removeram todo código que lia `services.sim_ocr`, `services.samia`, `services.sim_app` e o disk `s3_ocr`.
- Produces: `config('services.sim_webhook_download')` permanece (usado pelo fluxo de exportação/download — não tocar).

- [ ] **Step 1: Confirmar que nenhum código lê as configs a remover**

Run: `grep -rn "services.samia\|services.sim_ocr\|services.sim_app\|s3_ocr" app/ routes/ tests/ config/ --include="*.php" | grep -v "config/services.php\|config/filesystems.php"`
Expected: saída vazia. Se aparecer algo, é referência órfã esquecida nas Tasks 2-3 — remover antes de prosseguir.

- [ ] **Step 2: Remover blocos de `config/services.php`**

Remover os três blocos (`samia` linhas 42-47, `sim_app` linhas 55-59, `sim_ocr` linhas 61-73). Arquivo final:

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'api' => [
        'token' => env('API_TOKEN'),
    ],

    'sim_webhook_download' => [
        'url' => rtrim(env('SIM_APP_URL', ''), '/') . '/webhook/download',
        'token' => env('MS_MNI_API_TOKEN'),
        'timeout' => env('SIM_WEBHOOK_TIMEOUT', 10),
    ],
];
```

- [ ] **Step 3: Remover disk `s3_ocr` de `config/filesystems.php`**

Remover o bloco (linhas 60-70):

```php
        's3_ocr' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID_OCR'),
            'secret' => env('AWS_SECRET_ACCESS_KEY_OCR'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET_OCR'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],
```

- [ ] **Step 4: Remover filas OCR de `config/horizon.php`**

**4a.** No array `waits` (linhas 103-105), remover:

```php
        'redis:ocr-mni-download' => 60,
        'redis:ocr' => 300,
        'redis:ocr-request' => 60,
```

**4b.** Em `defaults`, remover os supervisors inteiros: `supervisor-ocr-mni-download` (linhas ~240-255), o bloco comentado `// 'supervisor-ocr' => [...]` (linhas ~257-272) e `supervisor-ocr-request` (linhas ~274-289).

**4c.** Em `environments.production`, remover:

```php
            'supervisor-ocr-mni-download' => [
                'maxProcesses' => 8,
            ],
            // 'supervisor-ocr' => [
            //     'maxProcesses' => 8,
            // ],
            'supervisor-ocr-request' => [
                'maxProcesses' => 1,
            ],
```

**4d.** Em `environments.local`, remover:

```php
            'supervisor-ocr-mni-download' => [
                'maxProcesses' => 2,
            ],
            // 'supervisor-ocr' => [
            //     'maxProcesses' => 2,
            // ],
            'supervisor-ocr-request' => [
                'maxProcesses' => 3,
            ],
```

- [ ] **Step 5: Rodar o teste de regressão do Horizon**

Run: `php artisan test --filter=HorizonConfigTest`
Expected: PASS — esse teste falha se algum supervisor ficar órfão em `environments` sem par em `defaults` (exatamente o erro que este step pode introduzir).

- [ ] **Step 6: Limpar `.env.example` e `.env`**

De `.env.example`, remover as linhas 76-81:

```text
# Microserviço SIM OCR
SIM_OCR_URL=http://ocr.mpap.private:8000
SIM_OCR_API_TOKEN=
SIM_OCR_BUCKET_ORIGEM=
SIM_OCR_BUCKET_DESTINO=
SIM_OCR_WEBHOOK_URL=
```

Do `.env` local (fora do git — editar direto), remover as linhas:

```text
SIM_OCR_URL=http://sim-mni
AWS_ACCESS_KEY_ID_OCR="${AWS_ACCESS_KEY_ID}"
AWS_SECRET_ACCESS_KEY_OCR="${AWS_SECRET_ACCESS_KEY}"
AWS_BUCKET_OCR=mpap-sim-mni-ocr
SAMIA_API_KEY="dbee3fbb-a09b-48a8-8e22-ce72dd5fd5e8"
VERSAO_OCR=1_0_0
```

- [ ] **Step 7: Rodar a suite completa**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add config/services.php config/filesystems.php config/horizon.php .env.example
git commit -m "refactor: remove configs e env vars de OCR/SAMIA (services, filesystems, horizon)"
```

---

### Task 5: Limpar models e dropar colunas do banco

**Files:**

- Modify: `app/Models/Processo.php:32-44,78-81`, `app/Models/ProcessoDocumento.php:33-36,41`
- Create: `database/migrations/2026_07_07_000000_drop_ocr_and_samia_columns.php`

**Interfaces:**

- Consumes: Tasks 2-3 removeram todos os leitores das colunas/consts (verificável: `grep -rn "ocr_status\|ocr_processado\|ocr_enviado_fila\|ocr_job_id\|ocr_concluido_data\|knowledge_base_" app/ --include="*.php"` deve retornar apenas os dois models).
- Produces: tabelas `processos` e `processo_documentos` sem colunas OCR/SAMIA.

- [ ] **Step 1: Limpar `app/Models/Processo.php`**

**1a.** Remover as consts (linhas 32-44):

```php
    const KNOWLEDGE_BASE_STATUS_PENDING = 'PENDING';
    const KNOWLEDGE_BASE_STATUS_STARTING = 'STARTING';
    const KNOWLEDGE_BASE_STATUS_IN_PROGRESS = 'IN_PROGRESS';
    const KNOWLEDGE_BASE_STATUS_COMPLETE = 'COMPLETE';
    const KNOWLEDGE_BASE_STATUS_FAILED = 'FAILED';
    const KNOWLEDGE_BASE_STATUS_STOPPING = 'STOPPING';
    const KNOWLEDGE_BASE_STATUS_STOPPED = 'STOPPED';
    const KNOWLEDGE_BASE_STATUS_UNKNOWN = 'UNKNOWN';

    const OCR_STATUS_PENDENTE = 'PENDENTE';
    const OCR_STATUS_PROCESSANDO = 'PROCESSANDO';
    const OCR_STATUS_CONCLUIDO = 'CONCLUIDO';
    const OCR_STATUS_FALHA = 'FALHA';
```

**1b.** Remover do `$fillable` (linhas 78-82):

```php
        'knowledge_base_status_sync',
        'knowledge_base_sequence_job',
        'knowledge_base_created_at',
        'ocr_status',
        // 'knowledge_base_ingestion_job_id',
```

- [ ] **Step 2: Limpar `app/Models/ProcessoDocumento.php`**

**2a.** Remover do `$fillable` (linhas 33-36):

```php
        'ocr_processado',
        'ocr_enviado_fila',
        'ocr_concluido_data',
        'ocr_job_id'
```

(Atenção: a linha anterior, `'erro_mni',`, vira o último item — manter a vírgula final ou removê-la, tanto faz em PHP, mas o array precisa continuar válido.)

**2b.** Remover o cast (linha 41):

```php
        'ocr_concluido_data' => 'datetime',
```

- [ ] **Step 3: Criar a migration de drop**

Criar `database/migrations/2026_07_07_000000_drop_ocr_and_samia_columns.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove as colunas de OCR e SAMIA (base de conhecimento).
     * Funcionalidades removidas — ver spec
     * docs/superpowers/specs/2026-07-07-remocao-ocr-samia-credenciais-obrigatorias-design.md
     */
    public function up(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            if (Schema::hasColumn('processo_documentos', 'ocr_job_id')) {
                $table->dropIndex(['ocr_job_id']);
            }
            foreach (['ocr_processado', 'ocr_enviado_fila', 'ocr_concluido_data', 'ocr_job_id'] as $coluna) {
                if (Schema::hasColumn('processo_documentos', $coluna)) {
                    $table->dropColumn($coluna);
                }
            }
        });

        Schema::table('processos', function (Blueprint $table) {
            if (Schema::hasColumn('processos', 'ocr_status')) {
                $table->dropIndex(['ocr_status']);
                $table->dropColumn('ocr_status');
            }
            foreach (['knowledge_base_status_sync', 'knowledge_base_sequence_job', 'knowledge_base_created_at'] as $coluna) {
                if (Schema::hasColumn('processos', $coluna)) {
                    $table->dropColumn($coluna);
                }
            }
        });
    }

    /**
     * Recria as colunas com os tipos que tinham antes do drop
     * (knowledge_base_sequence_job ja como BIGINT, pos-2025_12_17).
     */
    public function down(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->boolean('ocr_processado')->default(false);
            $table->boolean('ocr_enviado_fila')->default(false);
            $table->dateTime('ocr_concluido_data')->nullable();
            $table->string('ocr_job_id')->nullable();
            $table->index('ocr_job_id');
        });

        Schema::table('processos', function (Blueprint $table) {
            $table->string('knowledge_base_status_sync')->default('PENDING');
            $table->bigInteger('knowledge_base_sequence_job')->nullable();
            $table->dateTime('knowledge_base_created_at')->nullable();
            $table->string('ocr_status', 32)->nullable();
            $table->index('ocr_status');
        });
    }
};
```

- [ ] **Step 4: Conferir o SQL antes de aplicar**

Run: `php artisan migrate --pretend`
Expected: statements `alter table ... drop column ocr_...`/`knowledge_base_...` e drop dos índices `processo_documentos_ocr_job_id_index` e `processos_ocr_status_index`. Nenhum statement inesperado.

- [ ] **Step 5: Aplicar a migration**

> **Atenção (irreversível em produção):** dropa dados das colunas OCR/SAMIA. Em ambiente local isso é seguro; em produção, fazer backup antes (decisão registrada na spec — perda de dados aceita).

Run: `php artisan migrate`
Expected: `2026_07_07_000000_drop_ocr_and_samia_columns ......... DONE`

- [ ] **Step 6: Rodar a suite completa**

Run: `php artisan test`
Expected: PASS — inclusive `ConsultarProcessoControllerTest` (Task 1), provando que `/processo/visualizar` funciona sem a coluna `ocr_processado`.

- [ ] **Step 7: Commit**

```bash
git add app/Models/Processo.php app/Models/ProcessoDocumento.php database/migrations/2026_07_07_000000_drop_ocr_and_samia_columns.php
git commit -m "refactor: dropa colunas OCR/SAMIA de processos e processo_documentos"
```

---

### Task 6: Remover docs e varredura final

**Files:**

- Delete: `docs/fluxo-ocr-rag-webhook.md`, `docs/superpowers/specs/2026-05-07-substituicao-ocr-tesseract-microservico-design.md`, `docs/superpowers/plans/2026-05-07-substituicao-ocr-microservico.md`

**Interfaces:**

- Consumes: nada.
- Produces: repositório sem referências funcionais a OCR/SAMIA.

- [ ] **Step 1: Deletar docs de OCR**

```bash
git rm docs/fluxo-ocr-rag-webhook.md \
       docs/superpowers/specs/2026-05-07-substituicao-ocr-tesseract-microservico-design.md \
       docs/superpowers/plans/2026-05-07-substituicao-ocr-microservico.md
```

(Manter `docs/superpowers/*2026-04-29-webhook-download-exportacao*` — são de outra feature, só mencionam OCR de passagem. Manter também a spec e o plano desta remoção.)

- [ ] **Step 2: Varredura final de referências**

Run: `grep -rn -i "ocr\|samia" app/ config/ routes/ tests/ --include="*.php"`
Expected: saída vazia. (Migrations históricas em `database/migrations/` e a migration de drop mencionam OCR — ficam, são registro.)

Run: `php artisan route:list | grep -i ocr`
Expected: saída vazia.

- [ ] **Step 3: Rodar a suite completa**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "docs: remove documentacao do fluxo OCR/RAG"
```
