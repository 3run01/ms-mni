# Desacoplar credenciais do Tribunal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A credencial MNI deixa de morar no `Tribunal`; toda consulta de processo passa a exigir `login_pje`/`senha_pje` no payload, e as colunas `login`/`password` são dropadas da tabela `tribunais` (conexão `sim`).

**Architecture:** Refatoração em 5 tarefas ordenadas: primeiro remove-se todo LEITOR da credencial armazenada (services síncronos, jobs async, sweep de expediente), depois dropa-se as colunas com segurança e limpa-se o CRUD/model/factory/frontend. As colunas só caem quando nenhum código as lê.

**Tech Stack:** Laravel 11 (slim, sem Console/Kernel), Postgres (conexão `sim` = `sim_producao`), Pest 3, Inertia v2 + React 19 + TypeScript.

## Global Constraints

- **Nunca escrever direto na conexão `sim`.** Testes que tocam `tribunais` usam a trait `Tests\SimDatabaseTestCase` (`uses(SimDatabaseTestCase::class)`) — rollback via `DatabaseTransactions` com `$connectionsToTransact = ['sim']`. A ÚNICA mudança de schema aprovada em `sim` é a migration da Task 4.
- **PHP só roda no container:** `docker compose exec php php artisan ...`. NUNCA usar o wrapper `./php` (quebrado). Se o container não estiver de pé: `docker compose up -d --no-deps php`.
- **Rodar a migration da Task 4 com `--path`** apontando só para o arquivo dela — `php artisan migrate` sem path dispararia também a migration não-rastreada `2026_07_09_000000_seed_admin_user.php` do usuário, que NÃO deve ser executada por nós.
- **Não tocar** em `database/seeders/UserSeeder.php` nem em `database/migrations/2026_07_09_000000_seed_admin_user.php` (arquivos paralelos do usuário).
- **Baseline de testes:** 8 falhas pré-existentes no domínio exportação (ExportacaoProcessoServiceTest, DownloadProcessoControllerTest, ExportacaoPipelineTest) — não relacionadas; qualquer falha NOVA além dessas 8 é regressão.
- A coluna `password` era armazenada CRIPTOGRAFADA (`Crypt::decrypt`); ao dropá-la isso vira irrelevante. A senha do payload é texto puro (sem decrypt).
- Após deploy, a migration dropa as colunas na `sim` real (perda irreversível das credenciais das 8 rows) — intencional.

---

## File Structure

**Task 1 — services síncronos payload-only:**
- Modify: `app/Http/Controllers/Api/ConsultarProcessoController.php` (métodos `consultarDadosBasicos`, `consultarMovimentos`)
- Modify: `app/Http/Controllers/Api/DocumentoController.php` (métodos `show`, `listarDocumentos`)
- Modify: `app/Services/MNI/Intercomunicacao/ConsultarProcessoService.php`
- Modify: `app/Services/MNI/Intercomunicacao/ConsultarDocumentoService.php`
- Modify: `app/Services/Processo/SalvarDocumentoProcessoService.php` (`downloadMP4`, `downloadQuicktime`)
- Test: `tests/Feature/Api/ConsultarProcessoControllerTest.php`, `tests/Feature/Api/DocumentoControllerTest.php` (novo)

**Task 2 — endpoints async threading credenciais:**
- Modify: `app/Jobs/ConsultarDadosBasicosProcessoMNIJob.php`, `app/Jobs/ConsultarMovimentosProcessoMNIJob.php`, `app/Jobs/ConsultarDocumentosProcessoMNIJob.php`
- Modify: `app/Http/Controllers/Api/ConsultarProcessoController.php` (async), `app/Http/Controllers/Api/DocumentoController.php` (async)
- Test: `tests/Feature/Api/ConsultarProcessoControllerTest.php`, `tests/Feature/Api/DocumentoControllerTest.php`

**Task 3 — remoção do expediente:**
- Delete: `app/Services/MNI/Intercomunicacao/ConsultarExpedienteService.php`, `app/Console/Commands/MNIConsultarExpediente.php`
- Test: `tests/Feature/ExpedienteRemovidoTest.php` (novo)

**Task 4 — migration + model + factory + CRUD backend:**
- Create: `database/migrations/XXXX_XX_XX_XXXXXX_drop_login_password_from_tribunais.php`
- Modify: `app/Models/Tribunal.php`, `database/factories/TribunalFactory.php`, `app/Http/Requests/TribunalRequest.php`, `app/Http/Controllers/TribunalController.php`
- Test: `tests/Feature/TribunalCrudTest.php`

**Task 5 — limpeza do frontend:**
- Modify: `resources/js/components/tribunal-form.tsx`

---

## Task 1: Consulta síncrona exige credenciais no payload; services largam o fallback do tribunal

**Files:**
- Modify: `app/Http/Controllers/Api/ConsultarProcessoController.php:165-209`
- Modify: `app/Http/Controllers/Api/DocumentoController.php:27-61`, `:151-178`
- Modify: `app/Services/MNI/Intercomunicacao/ConsultarProcessoService.php`
- Modify: `app/Services/MNI/Intercomunicacao/ConsultarDocumentoService.php`
- Modify: `app/Services/Processo/SalvarDocumentoProcessoService.php:324-344`, `:465-485`
- Test: `tests/Feature/Api/ConsultarProcessoControllerTest.php`, `tests/Feature/Api/DocumentoControllerTest.php` (novo)

**Interfaces:**
- Consumes: rotas existentes `GET /api/processo/dados-basicos`, `/api/processo/movimentos/listar`, `/api/documento/visualizar`, `/api/processo/documentos/listar` (auth por header `X-API-Token`).
- Produces: esses 4 endpoints passam a responder **422** com erros de validação em `login_pje`/`senha_pje` quando ausentes; quando presentes, repassam as credenciais direto (sem `?? null`).

- [ ] **Step 1: Escrever os testes que falham (sync — dados-basicos e movimentos)**

Substituir os DOIS testes obsoletos em `tests/Feature/Api/ConsultarProcessoControllerTest.php` (o bloco atual sob o comentário `// ---------- endpoints que continuam SEM exigir credenciais ----------`, linhas ~105-132) por estes. Adicionar `use App\Models\Processo;` já existe; garantir `use App\Services\Processo\ProcessoService;` (já existe).

```php
// ---------- endpoints que agora EXIGEM credenciais ----------

it('dados-basicos sem credenciais retorna 422', function () {
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/dados-basicos?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});

it('dados-basicos repassa credenciais do payload ao ProcessoService', function () {
    $this->mock(ProcessoService::class, function ($mock) {
        $mock->shouldReceive('consultarDadosBasicos')
            ->once()
            ->withArgs(fn ($tribunal, $numero, $login, $senha) => $login === 'u-pje' && $senha === 's-pje')
            ->andReturn(new Processo());
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/dados-basicos?tribunal_id=1&numero_processo=9999999-99.2024.8.03.9999&login_pje=u-pje&senha_pje=s-pje')
        ->assertOk();
});

it('movimentos sem credenciais retorna 422', function () {
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/movimentos/listar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});

it('movimentos repassa credenciais do payload ao ProcessoService', function () {
    $processo = criarProcessoParaConsulta('0600125-81.2024.8.03.0003');
    $processo->setRelation('movimentos', collect());

    $this->mock(ProcessoService::class, function ($mock) use ($processo) {
        $mock->shouldReceive('consultarMovimentos')
            ->once()
            ->withArgs(fn ($tribunal, $numero, $login, $senha, $dataRef) => $login === 'u-pje' && $senha === 's-pje')
            ->andReturn($processo);
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/movimentos/listar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=u-pje&senha_pje=s-pje')
        ->assertOk();
});
```

- [ ] **Step 2: Escrever os testes que falham (sync — documento)**

Criar `tests/Feature/Api/DocumentoControllerTest.php`:

```php
<?php

use App\Models\Processo;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config()->set('services.api.token', 'tk-test');
});

it('documento visualizar sem credenciais retorna 422', function () {
    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/documento/visualizar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&id_documento=123')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});

it('documentos listar sem credenciais retorna 422', function () {
    Processo::create([
        'numero_processo' => cleanNumeroProcesso('0600125-81.2024.8.03.0003'),
        'tribunal_id' => 1,
        'valor_causa' => '0.00',
    ]);

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/documentos/listar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['login_pje', 'senha_pje']);
});
```

- [ ] **Step 3: Rodar os testes e confirmar que falham**

Run: `docker compose exec php php artisan test tests/Feature/Api/ConsultarProcessoControllerTest.php tests/Feature/Api/DocumentoControllerTest.php`
Expected: FAIL — os endpoints hoje não validam credenciais (retornam 200/500, não 422).

- [ ] **Step 4: Adicionar validação nos endpoints síncronos do `ConsultarProcessoController`**

Em `app/Http/Controllers/Api/ConsultarProcessoController.php`, no método `consultarDadosBasicos` (linha ~165), inserir a validação como primeira instrução e trocar o `?? null`:

```php
    public function consultarDadosBasicos(Request $request): JsonResponse
    {
        $request->validate([
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
        ]);

        $numero_processo = cleanNumeroProcesso($request->numero_processo);
        $processo = Processo::with('tribunal', 'classe', 'assuntos', 'prioridades', 'partes.representantesProcessual')
            ->where('numero_processo', $numero_processo)
            ->where('tribunal_id', $request->tribunal_id)
            ->first();

        //Verifica se o processo existe, caso exisa retorna o que está salvo no banco de dados
        if (!empty($processo)) {
            return response()->json($processo);
        }

        $processo = $this->processoService->consultarDadosBasicos(
            Tribunal::find($request->tribunal_id),
            $numero_processo,
            $request->login_pje,
            $request->senha_pje
        );

        return response()->json($processo);
    }
```

No método `consultarMovimentos` (linha ~188), inserir a mesma validação no topo e trocar o `?? null` (mantendo `data_referencia`):

```php
    public function consultarMovimentos(Request $request): JsonResponse
    {
        $request->validate([
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
        ]);

        $numero_processo = cleanNumeroProcesso($request->numero_processo);
        $processo = Processo::with('movimentos')
            ->where('numero_processo', $numero_processo)
            ->where('tribunal_id', $request->tribunal_id)
            ->first();

        if ($processo && $processo->movimentos->count() > 0) {
            return response()->json($processo->movimentos);
        }

        $processo = $this->processoService->consultarMovimentos(
            Tribunal::find($request->tribunal_id),
            $numero_processo,
            $request->login_pje,
            $request->senha_pje,
            $request->data_referencia ?? null,
        );

        return response()->json($processo->movimentos);
    }
```

- [ ] **Step 5: Adicionar validação nos endpoints síncronos do `DocumentoController`**

Em `app/Http/Controllers/Api/DocumentoController.php`, método `show` (linha ~27), inserir a validação como PRIMEIRA instrução (antes do `try`) e trocar o `?? null`:

```php
    public function show(Request $request): JsonResponse
    {
        $request->validate([
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
        ]);

        try {
            $maxTentativas = 3;
            $tentativa = 0;

            do {
                $tribunal = Tribunal::find($request->tribunal_id);
                $documento = $this->getDocumento(
                    $request->id_documento,
                    $request->numero_processo,
                    $tribunal,
                    $request->login_pje,
                    $request->senha_pje
                );
```

(o restante do método `show` fica inalterado.)

No método `listarDocumentos` (linha ~151), inserir a validação no topo e trocar o `?? null`:

```php
    public function listarDocumentos(Request $request): JsonResponse
    {
        $request->validate([
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
        ]);

        $numero_processo = cleanNumeroProcesso($request->numero_processo);
        $processo = Processo::with('documentos')
            ->where('numero_processo', $numero_processo)
            ->where('tribunal_id', $request->tribunal_id)
            ->first();

        if ($processo && $processo->documentos->count() > 0) {
            return response()->json($processo->documentos);
        }

        $processo = $this->processoService->consultarDocumentos(
            Tribunal::find($request->tribunal_id),
            $numero_processo,
            $request->login_pje,
            $request->senha_pje,
            $request->data_referencia ?? null,
        );

        // Carregar tipos de documento manualmente para cada documento
        $documentos = $processo->documentos->map(function ($documento) {
            $documento->tipo = $documento->getTipoDocumento();
            return $documento;
        });

        return response()->json($documentos);
    }
```

- [ ] **Step 6: Rodar os testes e confirmar que passam**

Run: `docker compose exec php php artisan test tests/Feature/Api/ConsultarProcessoControllerTest.php tests/Feature/Api/DocumentoControllerTest.php`
Expected: PASS.

- [ ] **Step 7: Remover o fallback de credencial armazenada dos 3 services**

Estas edições são remoção de código morto: com os endpoints exigindo credenciais, o operador `??` nunca alcança o lado do tribunal. Removê-las evita que, após a Task 4 dropar as colunas, sobre referência a coluna inexistente (`Crypt::decrypt(null)` lançaria).

Em `app/Services/MNI/Intercomunicacao/ConsultarProcessoService.php`:
- Remover os imports `use Illuminate\Contracts\Encryption\DecryptException;` e `use Illuminate\Support\Facades\Crypt;`.
- Trocar as duas linhas do `$params`:

```php
                'idConsultante' => $login_pje,
                'senhaConsultante' => $senha_pje,
```

- Remover o bloco `catch (DecryptException $e) { ... }` inteiro (o `catch (MNIException ...)` e `catch (\Exception ...)` permanecem):

```php
        } catch (MNIException $e) {
            throw new MNIException($e->getError(), 500);
        } catch (\Exception $e) {
            throw new MNIException($e->getMessage(), 500);
        }
```

Em `app/Services/MNI/Intercomunicacao/ConsultarDocumentoService.php`:
- Remover o import `use Illuminate\Support\Facades\Crypt;`.
- Trocar as duas linhas do `$parametros`:

```php
                'idConsultante' => $login_pje,
                'senhaConsultante' => $senha_pje,
```

Em `app/Services/Processo/SalvarDocumentoProcessoService.php`:
- Remover o import `use Illuminate\Support\Facades\Crypt;`.
- Nos métodos `downloadMP4` (linhas ~331-344) e `downloadQuicktime` (linhas ~472-485), substituir o bloco de fallback pelo guard abaixo (nos DOIS métodos):

Trocar:

```php
            // Se login e senha não forem informados, usar as credenciais do tribunal
            if (empty($login_pje) || empty($senha_pje)) {
                $tribunal = $documento->processo->tribunal;
                if (!$tribunal) {
                    throw new \Exception('Processo não possui tribunal associado');
                }

                $login_pje = $tribunal->login;
                $senha_pje = Crypt::decrypt($tribunal->password);

                if (empty($login_pje) || empty($senha_pje)) {
                    throw new \Exception('Tribunal não possui credenciais de acesso configuradas');
                }
            }
```

Por:

```php
            if (empty($login_pje) || empty($senha_pje)) {
                throw new \Exception('Credenciais MNI/PJ-e obrigatórias para baixar o documento');
            }
```

- [ ] **Step 8: Verificar que nenhum leitor de credencial armazenada permanece nesses services**

Run: `grep -rnE '\$tribunal->login|Crypt::decrypt\(\$tribunal->password\)' app/Services/`
Expected: apenas `app/Services/MNI/Intercomunicacao/ConsultarExpedienteService.php` (removido na Task 3). NENHUMA ocorrência em ConsultarProcessoService, ConsultarDocumentoService ou SalvarDocumentoProcessoService.

- [ ] **Step 9: Rodar a suíte relevante e confirmar verde**

Run: `docker compose exec php php artisan test tests/Feature/Api/`
Expected: PASS (todos os testes de API verdes).

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/Api/ConsultarProcessoController.php app/Http/Controllers/Api/DocumentoController.php app/Services/MNI/Intercomunicacao/ConsultarProcessoService.php app/Services/MNI/Intercomunicacao/ConsultarDocumentoService.php app/Services/Processo/SalvarDocumentoProcessoService.php tests/Feature/Api/ConsultarProcessoControllerTest.php tests/Feature/Api/DocumentoControllerTest.php
git commit -m "feat(mni): consulta síncrona exige credenciais no payload"
```

---

## Task 2: Endpoints async threading credenciais pelo job

**Files:**
- Modify: `app/Jobs/ConsultarDadosBasicosProcessoMNIJob.php`, `app/Jobs/ConsultarMovimentosProcessoMNIJob.php`, `app/Jobs/ConsultarDocumentosProcessoMNIJob.php`
- Modify: `app/Http/Controllers/Api/ConsultarProcessoController.php:211-221`, `app/Http/Controllers/Api/DocumentoController.php:180-184`
- Test: `tests/Feature/Api/ConsultarProcessoControllerTest.php`, `tests/Feature/Api/DocumentoControllerTest.php`

**Interfaces:**
- Consumes: rotas `GET /api/processo/dados-basicos/async`, `/api/processo/movimentos/async`, `/api/processo/documentos/async`.
- Produces: os 3 jobs passam a expor propriedades públicas `login_pje` e `senha_pje`; construtores viram `($tribunal_id, $numero_processo, $login_pje = null, $senha_pje = null)`. Endpoints async respondem 422 sem credenciais e despacham o job com as credenciais do payload.

- [ ] **Step 1: Escrever os testes que falham**

Adicionar em `tests/Feature/Api/ConsultarProcessoControllerTest.php` (garantir imports `use App\Jobs\ConsultarDadosBasicosProcessoMNIJob;` e `use App\Jobs\ConsultarMovimentosProcessoMNIJob;` no topo):

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

Adicionar em `tests/Feature/Api/DocumentoControllerTest.php` (garantir `use App\Jobs\ConsultarDocumentosProcessoMNIJob;` e `use Illuminate\Support\Facades\Queue;` no topo):

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
Expected: FAIL — async hoje não valida credenciais e o job não tem as propriedades `login_pje`/`senha_pje`.

- [ ] **Step 3: Threading das credenciais nos 3 jobs**

`app/Jobs/ConsultarDadosBasicosProcessoMNIJob.php` — substituir o bloco de propriedades + construtor + `handle`:

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

`app/Http/Controllers/Api/ConsultarProcessoController.php` (linhas ~211-221):

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

`app/Http/Controllers/Api/DocumentoController.php` (linhas ~180-184):

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
git commit -m "feat(mni): endpoints async threading credenciais pelo job (fix \$request indefinido)"
```

---

## Task 3: Remover o sweep de expediente (dependente de credencial armazenada)

**Files:**
- Delete: `app/Services/MNI/Intercomunicacao/ConsultarExpedienteService.php`
- Delete: `app/Console/Commands/MNIConsultarExpediente.php`
- Test: `tests/Feature/ExpedienteRemovidoTest.php` (novo)

**Interfaces:**
- Consumes: nada (o service era referenciado só pelo comando; o comando não é agendado).
- Produces: as classes `App\Services\MNI\Intercomunicacao\ConsultarExpedienteService` e `App\Console\Commands\MNIConsultarExpediente` deixam de existir. `BaixarProcessoMNIJob` e o model `Expediente` permanecem.

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/Feature/ExpedienteRemovidoTest.php`:

```php
<?php

it('não expõe mais o service nem o comando de expediente', function () {
    expect(class_exists('App\\Services\\MNI\\Intercomunicacao\\ConsultarExpedienteService'))->toBeFalse();
    expect(class_exists('App\\Console\\Commands\\MNIConsultarExpediente'))->toBeFalse();
});
```

- [ ] **Step 2: Rodar e confirmar falha**

Run: `docker compose exec php php artisan test tests/Feature/ExpedienteRemovidoTest.php`
Expected: FAIL — as classes ainda existem.

- [ ] **Step 3: Deletar os arquivos**

```bash
git rm app/Services/MNI/Intercomunicacao/ConsultarExpedienteService.php app/Console/Commands/MNIConsultarExpediente.php
```

- [ ] **Step 4: Confirmar que não há referências pendentes**

Run: `grep -rnE 'ConsultarExpedienteService|MNIConsultarExpediente|mni:consultar-expedientes' app/`
Expected: nenhuma ocorrência.

- [ ] **Step 5: Rodar e confirmar que passa**

Run: `docker compose exec php php artisan test tests/Feature/ExpedienteRemovidoTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/ExpedienteRemovidoTest.php
git commit -m "refactor(mni): remove sweep de expediente dependente de credencial armazenada"
```

---

## Task 4: Dropar colunas login/password + limpar model, factory e CRUD backend

**Files:**
- Create: `database/migrations/XXXX_XX_XX_XXXXXX_drop_login_password_from_tribunais.php`
- Modify: `app/Models/Tribunal.php:24-57`
- Modify: `database/factories/TribunalFactory.php:17-28`
- Modify: `app/Http/Requests/TribunalRequest.php:23-43`
- Modify: `app/Http/Controllers/TribunalController.php:41-76`
- Test: `tests/Feature/TribunalCrudTest.php`

**Interfaces:**
- Consumes: conexão `sim`, tabela `tribunais`. Trait `Tests\SimDatabaseTestCase`.
- Produces: colunas `login`/`password` inexistentes; `Tribunal::$fillable` sem `login`/`password`/`usar_credencial_tribunal`; CRUD web sem campos de credencial.

- [ ] **Step 1: Gerar o arquivo de migration**

Run: `docker compose exec php php artisan make:migration drop_login_password_from_tribunais`
Expected: cria `database/migrations/AAAA_MM_DD_HHMMSS_drop_login_password_from_tribunais.php`.

- [ ] **Step 2: Escrever o conteúdo da migration**

Substituir o conteúdo do arquivo gerado por:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sim')->table('tribunais', function (Blueprint $table) {
            $table->dropColumn(['login', 'password']);
        });
    }

    public function down(): void
    {
        Schema::connection('sim')->table('tribunais', function (Blueprint $table) {
            $table->string('login')->nullable();
            $table->string('password')->nullable();
        });
    }
};
```

- [ ] **Step 3: Escrever o teste que falha (colunas ausentes + factory sem credencial)**

Adicionar no topo de `tests/Feature/TribunalCrudTest.php` o import `use Illuminate\Support\Facades\Schema;` e este teste:

```php
it('não tem mais as colunas login e password na tabela tribunais', function () {
    expect(Schema::connection('sim')->hasColumn('tribunais', 'login'))->toBeFalse();
    expect(Schema::connection('sim')->hasColumn('tribunais', 'password'))->toBeFalse();
});
```

- [ ] **Step 4: Rodar e confirmar falha**

Run: `docker compose exec php php artisan test tests/Feature/TribunalCrudTest.php`
Expected: FAIL — as colunas ainda existem (migration não rodada).

- [ ] **Step 5: Rodar SOMENTE esta migration na conexão sim**

⚠️ Usar `--path` para não disparar a migration não-rastreada `seed_admin_user`. Substituir `AAAA_MM_DD_HHMMSS` pelo timestamp real do arquivo gerado no Step 1.

Run: `docker compose exec php php artisan migrate --path=database/migrations/AAAA_MM_DD_HHMMSS_drop_login_password_from_tribunais.php --force`
Expected: `INFO Running migrations. ... drop_login_password_from_tribunais DONE`.

- [ ] **Step 6: Limpar o model `Tribunal`**

Em `app/Models/Tribunal.php`, `$fillable` (remover `login`, `password`, `usar_credencial_tribunal`):

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

E `$hidden` (remover `login`, `password`):

```php
    protected $hidden = [
        // 'id',
        'url_webservice_mni',
        'url_webservice_mni_complementar',
        'url_consulta_pje',
        'url_recuperar_senha_tribunal',
        'created_at',
        'updated_at',
        'deleted_at'
    ];
```

(`boot()`/uuid guard e `getTipos()` ficam intactos.)

- [ ] **Step 7: Limpar a factory**

Em `database/factories/TribunalFactory.php`, `definition()` (remover `login`, `password`, `usar_credencial_tribunal`):

```php
    public function definition(): array
    {
        return [
            'nome' => 'Tribunal ' . fake()->unique()->company(),
            'tipo' => null,
            'url_webservice_mni' => fake()->url(),
            'url_webservice_mni_complementar' => fake()->url(),
            'ativo' => true,
            'enviar_dados_criminais' => false,
            'versao_mni' => '2.2.2',
        ];
    }
```

- [ ] **Step 8: Limpar o `TribunalRequest`**

Em `app/Http/Requests/TribunalRequest.php`, remover as 3 regras `login`, `password`, `usar_credencial_tribunal`. O array `rules()` fica:

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

(A variável `$criando = $this->isMethod('POST');` fica sem uso — remover a linha também.)

- [ ] **Step 9: Limpar o `TribunalController`**

Em `app/Http/Controllers/TribunalController.php`, método `edit` — remover `'login'` e `'usar_credencial_tribunal'` do `only([...])`:

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

E método `update` — remover o bloco que dropava a password em branco:

```php
    public function update(TribunalRequest $request, Tribunal $tribunal): RedirectResponse
    {
        $tribunal->update($request->validated());

        return redirect()->route('tribunais.index')->with('success', 'Tribunal atualizado.');
    }
```

- [ ] **Step 10: Atualizar os testes obsoletos do CRUD**

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

**Deletar** por completo o teste `it('mantém a password quando enviada em branco no update', ...)` (não faz mais sentido — coluna dropada).

O teste `it('renderiza o formulário de edição sem a password', ...)` permanece válido (o `only()` já não inclui password → `missing('tribunal.password')` continua verdadeiro).

- [ ] **Step 11: Rodar e confirmar que passam**

Run: `docker compose exec php php artisan test tests/Feature/TribunalCrudTest.php`
Expected: PASS (incluindo o teste de colunas ausentes).

- [ ] **Step 12: Commit**

```bash
git add database/migrations/*_drop_login_password_from_tribunais.php app/Models/Tribunal.php database/factories/TribunalFactory.php app/Http/Requests/TribunalRequest.php app/Http/Controllers/TribunalController.php tests/Feature/TribunalCrudTest.php
git commit -m "feat(tribunais): dropa colunas login/password e remove credencial do CRUD"
```

---

## Task 5: Remover a seção Credenciais do formulário de tribunal

**Files:**
- Modify: `resources/js/components/tribunal-form.tsx:20-40`, `:58-78`, `:166-203`

**Interfaces:**
- Consumes: props `tipos: string[]`, `tribunal?: TribunalFormValues`.
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

Remover o bloco JSX completo que começa em `<Secao titulo="Credenciais">` e termina no `</Secao>` correspondente (login, password e o checkbox `usar_credencial_tribunal`). As seções "Identificação", "URLs MNI", "Códigos" e "Flags" permanecem.

- [ ] **Step 4: Rodar typecheck**

Run: `npm run typecheck`
Expected: sem erros (nenhuma referência remanescente a `login`/`password`/`usar_credencial_tribunal`).

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
  Expected: as 8 falhas pré-existentes de exportação permanecem; NENHUMA falha nova. Todos os testes de API, expediente e CRUD verdes.
- [ ] **Grep de credencial armazenada:** `grep -rnE '\$tribunal->login|\$tribunal->password' app/`
  Expected: nenhuma ocorrência.
- [ ] **Frontend:** `npm run typecheck && npm run build` verdes.
