# Desacoplar credenciais do Tribunal — Implementation Plan (REVISADO: colunas NULLABLE)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development para implementar task-by-task. Steps usam checkbox (`- [ ]`).

**Goal:** Toda consulta de processo passa a exigir `login_pje`/`senha_pje` no payload; a credencial deixa de ser gerenciada pelo CRUD; as colunas `login`/`password` viram NULLABLE (não são dropadas — fluxos de background sem requester ainda dependem delas via fallback).

**Architecture:** Endpoints interativos (sync + async) validam credencial obrigatória. O fallback de credencial armazenada nos services PERMANECE para os fluxos de background sem requester. `BaixarProcessoMNIJob` passa a receber a credencial do request quando disparado por um endpoint. O CRUD para de gerenciar credencial; a coluna continua existindo (nullable, `$hidden`).

**Tech Stack:** Laravel 11 (slim), Postgres (conexão `sim`), Pest 3, Inertia v2 + React 19 + TypeScript.

## Global Constraints

- **Branch:** trabalhar em `feat/credenciais-payload` (isolada; a original recebeu commits paralelos de outra feature).
- **Nunca escrever direto na conexão `sim`.** Testes que tocam `tribunais` usam a trait `Tests\SimDatabaseTestCase`. A migration da Task 4 (nullable) é a única mudança de schema em `sim`.
- **PHP só no container:** `docker compose exec php php artisan ...`. NUNCA `./php`.
- **Rodar a migration da Task 4 com `--path`** — `php artisan migrate` sem path dispararia a migration não-rastreada `2026_07_09_000000_seed_admin_user.php` (não executar).
- **Não tocar** em `database/seeders/UserSeeder.php` nem em `database/migrations/2026_07_09_000000_seed_admin_user.php`.
- **Não tocar** em arquivos da feature de processos (`app/Http/Controllers/ProcessoController.php`, `routes/web.php` na parte de `/processos`, `tests/Feature/ProcessoConsultaTest.php`, `tests/MultiConnectionDatabaseTestCase.php`, `database/factories/ProcessoFactory.php`) — não existem nesta branch e não são desta feature.
- **Fallback de credencial nos services PERMANECE.** A senha vinda do payload é texto puro; a senha armazenada é criptografada (`Crypt::decrypt`) — o fallback lida com a armazenada.
- **`$hidden` do model MANTÉM `login`/`password`** — as colunas continuam existindo e são sensíveis.
- **Baseline de testes:** 8 falhas pré-existentes no domínio exportação (não relacionadas).

---

## Task 1 — CONCLUÍDA (referência)

Consulta síncrona exige credenciais no payload. **Já implementada** nos commits:
- `1c03e4f` — validação `login_pje`/`senha_pje` `required` em `consultarDadosBasicos`, `consultarMovimentos` (ConsultarProcessoController) e `show`, `listarDocumentos` (DocumentoController); testes 422 + passthrough.
- `f5ed404` — revert do fallback dos services (mantido, pois background depende dele).

Nada a fazer aqui. As Tasks abaixo são o que resta.

---

## Task 2: Endpoints async exigem credencial e threading pelo job

**Files:**
- Modify: `app/Jobs/ConsultarDadosBasicosProcessoMNIJob.php`, `app/Jobs/ConsultarMovimentosProcessoMNIJob.php`, `app/Jobs/ConsultarDocumentosProcessoMNIJob.php`
- Modify: `app/Http/Controllers/Api/ConsultarProcessoController.php` (métodos `consultarDadosBasicosAsync`, `consultarMovimentosAsync`), `app/Http/Controllers/Api/DocumentoController.php` (`consultarDocumentosAsync`)
- Test: `tests/Feature/Api/ConsultarProcessoControllerTest.php`, `tests/Feature/Api/DocumentoControllerTest.php`

**Interfaces:**
- Produces: os 3 jobs ganham propriedades públicas `login_pje`/`senha_pje`; construtor vira `($tribunal_id, $numero_processo, $login_pje = null, $senha_pje = null)`. Endpoints async respondem 422 sem credenciais e despacham o job com as credenciais do payload. Isto também conserta o bug pré-existente do `$request` indefinido em `handle()`.

- [ ] **Step 1: Escrever os testes que falham**

Adicionar em `tests/Feature/Api/ConsultarProcessoControllerTest.php` (garantir os imports `use App\Jobs\ConsultarDadosBasicosProcessoMNIJob;` e `use App\Jobs\ConsultarMovimentosProcessoMNIJob;`):

```php
// ---------- endpoints async ----------

it('dados-basicos async sem credenciais retorna 422', function () {
    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/dados-basicos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});

it('dados-basicos async despacha job com as credenciais do payload', function () {
    Queue::fake();

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/dados-basicos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=u-pje&senha_pje=s-pje');

    Queue::assertPushed(
        ConsultarDadosBasicosProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'u-pje' && $job->senha_pje === 's-pje'
    );
});

it('movimentos async sem credenciais retorna 422', function () {
    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/movimentos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});

it('movimentos async despacha job com as credenciais do payload', function () {
    Queue::fake();

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/movimentos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=u-pje&senha_pje=s-pje');

    Queue::assertPushed(
        ConsultarMovimentosProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'u-pje' && $job->senha_pje === 's-pje'
    );
});
```

Adicionar em `tests/Feature/Api/DocumentoControllerTest.php` (garantir `use App\Jobs\ConsultarDocumentosProcessoMNIJob;` e `use Illuminate\Support\Facades\Queue;`):

```php
it('documentos async sem credenciais retorna 422', function () {
    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/documentos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});

it('documentos async despacha job com as credenciais do payload', function () {
    Queue::fake();

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/documentos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=u-pje&senha_pje=s-pje');

    Queue::assertPushed(
        ConsultarDocumentosProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'u-pje' && $job->senha_pje === 's-pje'
    );
});
```

- [ ] **Step 2: Rodar e confirmar falha**

Run: `docker compose exec php php artisan test tests/Feature/Api/ConsultarProcessoControllerTest.php tests/Feature/Api/DocumentoControllerTest.php`
Expected: FAIL — async não valida credenciais e o job não tem `login_pje`/`senha_pje`.

- [ ] **Step 3: Threading nos 3 jobs**

`app/Jobs/ConsultarDadosBasicosProcessoMNIJob.php` — substituir propriedades + construtor + `handle`:

```php
    public $numero_processo;
    public $tribunal_id;
    public $login_pje;
    public $senha_pje;

    public function __construct($tribunal_id, $numero_processo, $login_pje = null, $senha_pje = null)
    {
        $this->numero_processo = $numero_processo;
        $this->tribunal_id = $tribunal_id;
        $this->login_pje = $login_pje;
        $this->senha_pje = $senha_pje;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $processoService = new ProcessoService();
        $processo = $processoService->consultarDadosBasicos(
            Tribunal::find($this->tribunal_id),
            $this->numero_processo,
            $this->login_pje,
            $this->senha_pje
        );

        Http::timeout(1000)->get(env('SIM_APP_URL')."/webhook/atualizar-processo/{$this->numero_processo}");
    }
```

`app/Jobs/ConsultarMovimentosProcessoMNIJob.php` — mesma estrutura, chamando `consultarMovimentos`:

```php
    public $numero_processo;
    public $tribunal_id;
    public $login_pje;
    public $senha_pje;

    public function __construct($tribunal_id, $numero_processo, $login_pje = null, $senha_pje = null)
    {
        $this->numero_processo = $numero_processo;
        $this->tribunal_id = $tribunal_id;
        $this->login_pje = $login_pje;
        $this->senha_pje = $senha_pje;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $processoService = new ProcessoService();
        $processo = $processoService->consultarMovimentos(
            Tribunal::find($this->tribunal_id),
            $this->numero_processo,
            $this->login_pje,
            $this->senha_pje
        );

        Http::timeout(1000)->get(env('SIM_APP_URL')."/webhook/atualizar-processo/{$this->numero_processo}");
    }
```

`app/Jobs/ConsultarDocumentosProcessoMNIJob.php` — mesma estrutura, chamando `consultarDocumentos`:

```php
    public $numero_processo;
    public $tribunal_id;
    public $login_pje;
    public $senha_pje;

    public function __construct($tribunal_id, $numero_processo, $login_pje = null, $senha_pje = null)
    {
        $this->numero_processo = $numero_processo;
        $this->tribunal_id = $tribunal_id;
        $this->login_pje = $login_pje;
        $this->senha_pje = $senha_pje;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $processoService = new ProcessoService();
        $processo = $processoService->consultarDocumentos(
            Tribunal::find($this->tribunal_id),
            $this->numero_processo,
            $this->login_pje,
            $this->senha_pje
        );

        Http::timeout(1000)->get(env('SIM_APP_URL')."/webhook/atualizar-processo/{$this->numero_processo}");
    }
```

- [ ] **Step 4: Validar + despachar com credenciais nos endpoints async**

`app/Http/Controllers/Api/ConsultarProcessoController.php`:

```php
    public function consultarDadosBasicosAsync(Request $request)
    {
        $request->validate([
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
        ]);

        $numero_processo = cleanNumeroProcesso($request->numero_processo);
        ConsultarDadosBasicosProcessoMNIJob::dispatch($request->tribunal_id, $numero_processo, $request->login_pje, $request->senha_pje)->onQueue('alta');
    }

    public function consultarMovimentosAsync(Request $request)
    {
        $request->validate([
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
        ]);

        $numero_processo = cleanNumeroProcesso($request->numero_processo);
        ConsultarMovimentosProcessoMNIJob::dispatch($request->tribunal_id, $numero_processo, $request->login_pje, $request->senha_pje)->onQueue('alta');
    }
```

`app/Http/Controllers/Api/DocumentoController.php`:

```php
    public function consultarDocumentosAsync(Request $request)
    {
        $request->validate([
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
        ]);

        $numero_processo = cleanNumeroProcesso($request->numero_processo);
        ConsultarDocumentosProcessoMNIJob::dispatch($request->tribunal_id, $numero_processo, $request->login_pje, $request->senha_pje)->onQueue('alta');
    }
```

- [ ] **Step 5: Rodar e confirmar que passam**

Run: `docker compose exec php php artisan test tests/Feature/Api/ConsultarProcessoControllerTest.php tests/Feature/Api/DocumentoControllerTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/ConsultarDadosBasicosProcessoMNIJob.php app/Jobs/ConsultarMovimentosProcessoMNIJob.php app/Jobs/ConsultarDocumentosProcessoMNIJob.php app/Http/Controllers/Api/ConsultarProcessoController.php app/Http/Controllers/Api/DocumentoController.php tests/Feature/Api/ConsultarProcessoControllerTest.php tests/Feature/Api/DocumentoControllerTest.php
git commit -m "feat(mni): endpoints async exigem credencial e threading pelo job"
```

---

## Task 3: BaixarProcessoMNIJob recebe credencial do request + conserta bug do data_referencia

**Files:**
- Modify: `app/Jobs/BaixarProcessoMNIJob.php`
- Modify: `app/Http/Controllers/Api/ConsultarProcessoController.php` (dispatch em `show` e `buscarPorNumeroProcesso`)
- Test: `tests/Feature/Api/ConsultarProcessoControllerTest.php`

**Interfaces:**
- Consumes: `ProcessoService::consultarDadosBasicos($tribunal, $numero, $login, $senha)`, `consultarMovimentos($tribunal, $numero, $login, $senha, $data_referencia)`, `consultarDocumentos($tribunal, $numero, $login, $senha, $data_referencia)`.
- Produces: `BaixarProcessoMNIJob` construtor vira `($tribunal, $numero_processo, $login_pje = null, $senha_pje = null, $data_referencia = null)`, com propriedades públicas `login_pje`/`senha_pje`. Bug corrigido: hoje `handle()` passa `$this->data_referencia` no slot `$login`.

**Nota:** o dispatch em `ConsultarExpedienteService.php:48` (`BaixarProcessoMNIJob::dispatch($tribunal, $expediente->processo->numero)`) NÃO muda — login/senha nulos → fallback do tribunal, correto para o contexto de background do expediente.

- [ ] **Step 1: Escrever o teste que falha**

Adicionar em `tests/Feature/Api/ConsultarProcessoControllerTest.php` (o import `use App\Jobs\BaixarProcessoMNIJob;` já existe):

```php
it('visualizar processo existente agenda refresh com as credenciais do payload', function () {
    Queue::fake();
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/visualizar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=u-pje&senha_pje=s-pje')
        ->assertOk();

    Queue::assertPushed(
        BaixarProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'u-pje' && $job->senha_pje === 's-pje'
    );
});
```

- [ ] **Step 2: Rodar e confirmar falha**

Run: `docker compose exec php php artisan test tests/Feature/Api/ConsultarProcessoControllerTest.php`
Expected: FAIL — o job hoje não tem `login_pje`/`senha_pje` (propriedade inexistente → closure retorna false).

- [ ] **Step 3: Adicionar credenciais ao `BaixarProcessoMNIJob` + corrigir o bug**

`app/Jobs/BaixarProcessoMNIJob.php` — substituir propriedades + construtor + `handle`:

```php
    public $tribunal;
    public $numero_processo;
    public $login_pje;
    public $senha_pje;
    public $data_referencia;

    /**
     * Create a new job instance.
     */
    public function __construct(
        $tribunal,
        $numero_processo,
        $login_pje = null,
        $senha_pje = null,
        $data_referencia = null
    ) {
        $this->tribunal = $tribunal;
        $this->numero_processo = $numero_processo;
        $this->login_pje = $login_pje;
        $this->senha_pje = $senha_pje;
        $this->data_referencia = $data_referencia;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $service = new ProcessoService();

            $service->consultarDadosBasicos(
                $this->tribunal,
                $this->numero_processo,
                $this->login_pje,
                $this->senha_pje
            );

            $service->consultarMovimentos(
                $this->tribunal,
                $this->numero_processo,
                $this->login_pje,
                $this->senha_pje,
                $this->data_referencia
            );

            $service->consultarDocumentos(
                $this->tribunal,
                $this->numero_processo,
                $this->login_pje,
                $this->senha_pje,
                $this->data_referencia
            );

            //Dispara evento para webhook

        } catch (MNIException $e) {
            Log::error('BaixarProcessoMNIJob: ' . $this->numero_processo . ' - ' . $e->getError() . ' - Arquivo: ' . $e->getFile() . ' - Linha: ' . $e->getLine());
        } catch (\Exception $e) {
            Log::error('BaixarProcessoMNIJob: ' . $this->numero_processo . ' - ' . $e->getMessage() . ' - Arquivo: ' . $e->getFile() . ' - Linha: ' . $e->getLine());
        }
    }
```

- [ ] **Step 4: Passar credenciais nos dispatches de `show` e `buscarPorNumeroProcesso`**

`app/Http/Controllers/Api/ConsultarProcessoController.php`:
- Em `show` (dispatch dentro do `else`, ~linha 105):

```php
                BaixarProcessoMNIJob::dispatch(Tribunal::find($request->tribunal_id), $numero_processo, $request->login_pje, $request->senha_pje);
```

- Em `buscarPorNumeroProcesso` (dispatch dentro do `else`, ~linha 135):

```php
            BaixarProcessoMNIJob::dispatch(Tribunal::find($request->tribunal_id), $numero_processo, $request->login_pje, $request->senha_pje);
```

- [ ] **Step 5: Rodar e confirmar que passa**

Run: `docker compose exec php php artisan test tests/Feature/Api/ConsultarProcessoControllerTest.php`
Expected: PASS (incluindo os testes de `visualizar` que já asseguravam o push do job).

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/BaixarProcessoMNIJob.php app/Http/Controllers/Api/ConsultarProcessoController.php tests/Feature/Api/ConsultarProcessoControllerTest.php
git commit -m "fix(mni): BaixarProcessoMNIJob recebe credencial do request e corrige slot data_referencia"
```

---

## Task 4: Colunas login/password NULLABLE + CRUD deixa de gerenciar credencial

**Files:**
- Create: `database/migrations/XXXX_XX_XX_XXXXXX_make_login_password_nullable_on_tribunais.php`
- Modify: `app/Models/Tribunal.php` (`$fillable` — remover creds; `$hidden` — MANTER creds)
- Modify: `app/Http/Requests/TribunalRequest.php`, `app/Http/Controllers/TribunalController.php`
- Test: `tests/Feature/TribunalCrudTest.php`

**Interfaces:**
- Produces: colunas `login`/`password` NULLABLE em `sim`. `Tribunal::$fillable` sem `login`/`password`/`usar_credencial_tribunal`. CRUD web não aceita nem exibe credencial.

**Nota:** a `database/factories/TribunalFactory.php` NÃO muda (as colunas continuam existindo; o factory pode seguir setando `login`/`password`).

- [ ] **Step 1: Gerar o arquivo de migration**

Run: `docker compose exec php php artisan make:migration make_login_password_nullable_on_tribunais`
Expected: cria `database/migrations/AAAA_MM_DD_HHMMSS_make_login_password_nullable_on_tribunais.php`.

- [ ] **Step 2: Escrever o conteúdo da migration**

Substituir o conteúdo por (raw `DROP NOT NULL` — preserva os tipos: `login` varchar(255), `password` text):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('sim')->statement('ALTER TABLE tribunais ALTER COLUMN login DROP NOT NULL');
        DB::connection('sim')->statement('ALTER TABLE tribunais ALTER COLUMN password DROP NOT NULL');
    }

    public function down(): void
    {
        DB::connection('sim')->statement('ALTER TABLE tribunais ALTER COLUMN login SET NOT NULL');
        DB::connection('sim')->statement('ALTER TABLE tribunais ALTER COLUMN password SET NOT NULL');
    }
};
```

- [ ] **Step 3: Escrever o teste que falha (criar tribunal sem credencial)**

Adicionar no topo de `tests/Feature/TribunalCrudTest.php` o import `use Illuminate\Support\Facades\Schema;` e este teste:

```php
it('cria tribunal sem credenciais (colunas nullable)', function () {
    $this->actingAs(autenticado())
        ->post('/tribunais', tribunalPayload(['nome' => 'Tribunal Sem Credencial']))
        ->assertRedirect(route('tribunais.index'));

    $tribunal = Tribunal::where('nome', 'Tribunal Sem Credencial')->first();
    expect($tribunal)->not->toBeNull();
    expect($tribunal->login)->toBeNull();
    expect($tribunal->password)->toBeNull();
});
```

(Este teste só passa depois de (a) a migration tornar as colunas nullable e (b) `tribunalPayload` parar de enviar credenciais — Steps 5 e 8.)

- [ ] **Step 4: Rodar e confirmar falha**

Run: `docker compose exec php php artisan test tests/Feature/TribunalCrudTest.php`
Expected: FAIL — `tribunalPayload` ainda envia login/password (não nulos) e/ou colunas ainda NOT NULL.

- [ ] **Step 5: Rodar SOMENTE esta migration na conexão sim**

⚠️ `--path` para não disparar a migration não-rastreada `seed_admin_user`. Substituir `AAAA_MM_DD_HHMMSS` pelo timestamp real.

Run: `docker compose exec php php artisan migrate --path=database/migrations/AAAA_MM_DD_HHMMSS_make_login_password_nullable_on_tribunais.php --force`
Expected: `... make_login_password_nullable_on_tribunais DONE`.

- [ ] **Step 6: Limpar o `$fillable` do model (mantendo `$hidden`)**

Em `app/Models/Tribunal.php`, `$fillable` — remover `login`, `password`, `usar_credencial_tribunal`:

```php
    protected $fillable = [
        'nome',
        'url_webservice_mni',
        'url_webservice_mni_consultar_processo',
        'url_webservice_mni_complementar',
        'url_consulta_pje',
        'url_webservice_mni_criminal',
        'url_recuperar_senha_tribunal',
        'tipo',
        'ativo',
        'codigo_peticao_inicial',
        'codigo_peticao_avulsa',
        'codigo_certidao_inicio_fim',
        'codigo_seeu',
        'usar_codigo_documento_padrao',
        'enviar_dados_criminais',
        'versao_mni',
    ];
```

**NÃO alterar `$hidden`** — ele MANTÉM `login` e `password` (as colunas existem e são sensíveis).

- [ ] **Step 7: Limpar o `TribunalRequest`**

Em `app/Http/Requests/TribunalRequest.php`, remover as regras `login`, `password`, `usar_credencial_tribunal` e a linha `$criando = $this->isMethod('POST');` (fica sem uso). `rules()` retorna:

```php
        return [
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['nullable', 'string', Rule::in(Tribunal::getTipos())],
            'url_webservice_mni' => ['required', 'url', 'max:255'],
            'url_webservice_mni_complementar' => ['required', 'url', 'max:255'],
            'url_webservice_mni_consultar_processo' => ['nullable', 'url', 'max:255'],
            'url_consulta_pje' => ['nullable', 'url', 'max:255'],
            'url_webservice_mni_criminal' => ['nullable', 'url', 'max:255'],
            'url_recuperar_senha_tribunal' => ['nullable', 'url', 'max:255'],
            'codigo_peticao_inicial' => ['nullable', 'string', 'max:255'],
            'codigo_peticao_avulsa' => ['nullable', 'string', 'max:255'],
            'codigo_certidao_inicio_fim' => ['nullable', 'string', 'max:255'],
            'codigo_seeu' => ['nullable', 'string', 'max:255'],
            'usar_codigo_documento_padrao' => ['nullable', 'string', 'max:255'],
            'versao_mni' => ['nullable', 'string', 'max:50'],
            'ativo' => ['required', 'boolean'],
            'enviar_dados_criminais' => ['boolean'],
        ];
```

- [ ] **Step 8: Limpar o `TribunalController`**

Em `app/Http/Controllers/TribunalController.php`, `edit` — remover `'login'` e `'usar_credencial_tribunal'` do `only([...])`:

```php
    public function edit(Tribunal $tribunal): Response
    {
        return Inertia::render('tribunais/edit', [
            'tipos' => Tribunal::getTipos(),
            'tribunal' => $tribunal->only([
                'id',
                'nome',
                'tipo',
                'versao_mni',
                'ativo',
                'url_webservice_mni',
                'url_webservice_mni_consultar_processo',
                'url_webservice_mni_complementar',
                'url_consulta_pje',
                'url_webservice_mni_criminal',
                'url_recuperar_senha_tribunal',
                'codigo_peticao_inicial',
                'codigo_peticao_avulsa',
                'codigo_certidao_inicio_fim',
                'codigo_seeu',
                'usar_codigo_documento_padrao',
                'enviar_dados_criminais',
            ]),
        ]);
    }
```

E `update` — remover o bloco que dropava a password em branco:

```php
    public function update(TribunalRequest $request, Tribunal $tribunal): RedirectResponse
    {
        $tribunal->update($request->validated());

        return redirect()->route('tribunais.index')->with('success', 'Tribunal atualizado.');
    }
```

- [ ] **Step 9: Atualizar os testes obsoletos do CRUD**

Em `tests/Feature/TribunalCrudTest.php`:

Ajustar `tribunalPayload()` (remover `login`, `password`, `usar_credencial_tribunal`):

```php
function tribunalPayload(array $overrides = []): array
{
    return array_merge([
        'nome' => 'Tribunal de Teste E2E',
        'tipo' => Tribunal::TIPO_STJ,
        'url_webservice_mni' => 'https://tribunal.test/mni',
        'url_webservice_mni_complementar' => 'https://tribunal.test/mni-complementar',
        'ativo' => true,
        'enviar_dados_criminais' => false,
    ], $overrides);
}
```

Ajustar o teste de validação obrigatória (remover `login`/`password` da lista):

```php
it('valida campos obrigatórios no store', function () {
    $this->actingAs(autenticado())
        ->post('/tribunais', [])
        ->assertInvalid(['nome', 'url_webservice_mni', 'url_webservice_mni_complementar']);
});
```

**Deletar** por completo o teste `it('mantém a password quando enviada em branco no update', ...)`.

O teste `it('renderiza o formulário de edição sem a password', ...)` permanece válido (o `only()` não inclui password → `missing('tribunal.password')` continua verdadeiro).

- [ ] **Step 10: Rodar e confirmar que passam**

Run: `docker compose exec php php artisan test tests/Feature/TribunalCrudTest.php`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add database/migrations/*_make_login_password_nullable_on_tribunais.php app/Models/Tribunal.php app/Http/Requests/TribunalRequest.php app/Http/Controllers/TribunalController.php tests/Feature/TribunalCrudTest.php
git commit -m "feat(tribunais): colunas login/password nullable e CRUD deixa de gerenciar credencial"
```

---

## Task 5: Remover a seção Credenciais do formulário de tribunal

**Files:**
- Modify: `resources/js/components/tribunal-form.tsx`

**Interfaces:**
- Produces: `TribunalFormValues` sem `login`/`usar_credencial_tribunal`; `useForm` sem `login`/`password`/`usar_credencial_tribunal`; sem a `<Secao titulo="Credenciais">`.

- [ ] **Step 1: Remover os campos de credencial do tipo `TribunalFormValues`**

Em `resources/js/components/tribunal-form.tsx`, remover as linhas `login: string;` e `usar_credencial_tribunal: boolean | null;` da interface. Resultado:

```tsx
export interface TribunalFormValues {
    id: number;
    nome: string;
    tipo: string | null;
    versao_mni: string | null;
    ativo: boolean | null;
    url_webservice_mni: string;
    url_webservice_mni_consultar_processo: string | null;
    url_webservice_mni_complementar: string;
    url_consulta_pje: string | null;
    url_webservice_mni_criminal: string | null;
    url_recuperar_senha_tribunal: string | null;
    codigo_peticao_inicial: string | null;
    codigo_peticao_avulsa: string | null;
    codigo_certidao_inicio_fim: string | null;
    codigo_seeu: string | null;
    usar_codigo_documento_padrao: string | null;
    enviar_dados_criminais: boolean | null;
}
```

- [ ] **Step 2: Remover os campos dos defaults do `useForm`**

Remover as linhas `login`, `password` e `usar_credencial_tribunal` do objeto do `useForm`. Resultado:

```tsx
    const { data, setData, post, put, processing, errors } = useForm({
        nome: tribunal?.nome ?? '',
        tipo: tribunal?.tipo ?? null,
        versao_mni: tribunal?.versao_mni ?? '',
        ativo: Boolean(tribunal?.ativo ?? true),
        url_webservice_mni: tribunal?.url_webservice_mni ?? '',
        url_webservice_mni_consultar_processo: tribunal?.url_webservice_mni_consultar_processo ?? '',
        url_webservice_mni_complementar: tribunal?.url_webservice_mni_complementar ?? '',
        url_consulta_pje: tribunal?.url_consulta_pje ?? '',
        url_webservice_mni_criminal: tribunal?.url_webservice_mni_criminal ?? '',
        url_recuperar_senha_tribunal: tribunal?.url_recuperar_senha_tribunal ?? '',
        codigo_peticao_inicial: tribunal?.codigo_peticao_inicial ?? '',
        codigo_peticao_avulsa: tribunal?.codigo_peticao_avulsa ?? '',
        codigo_certidao_inicio_fim: tribunal?.codigo_certidao_inicio_fim ?? '',
        codigo_seeu: tribunal?.codigo_seeu ?? '',
        usar_codigo_documento_padrao: tribunal?.usar_codigo_documento_padrao ?? '',
        enviar_dados_criminais: Boolean(tribunal?.enviar_dados_criminais ?? false),
    });
```

- [ ] **Step 3: Remover a `<Secao titulo="Credenciais">` inteira**

Remover o bloco JSX completo de `<Secao titulo="Credenciais">` até o `</Secao>` correspondente (login, password e o checkbox `usar_credencial_tribunal`). As seções "Identificação", "URLs MNI", "Códigos" e "Flags" permanecem.

- [ ] **Step 4: Rodar typecheck**

Run: `npm run typecheck`
Expected: sem erros.

- [ ] **Step 5: Rodar build**

Run: `npm run build`
Expected: build conclui sem erros.

- [ ] **Step 6: Commit**

```bash
git add resources/js/components/tribunal-form.tsx
git commit -m "feat(tribunais): remove seção Credenciais do formulário"
```

---

## Verificação final (após todas as tasks)

- [ ] **Suíte completa:** `docker compose exec php php artisan test`
  Expected: as 8 falhas pré-existentes de exportação permanecem; NENHUMA falha nova.
- [ ] **Fallback preservado:** `grep -rnE '\?\? \$tribunal->login' app/Services/` → deve continuar existindo (ConsultarProcessoService, ConsultarDocumentoService).
- [ ] **Colunas nullable:** introspecção confirma `login`/`password` com `is_nullable=YES` em `sim`.
- [ ] **Frontend:** `npm run typecheck && npm run build` verdes.
