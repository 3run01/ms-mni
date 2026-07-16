# Tokens de API Gerenciáveis — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tela para gerar/gerenciar tokens de acesso à API; middleware passa a validar somente tokens da tabela `api_tokens` (corte seco do `API_TOKEN` do `.env`).

**Architecture:** Tabela própria `api_tokens` (conexão default pgsql, hash SHA-256 do token), middleware `ValidateApiToken` reescrito para buscar na tabela, CRUD web Inertia no padrão existente de `tribunais`. Spec: `docs/superpowers/specs/2026-07-10-api-tokens-design.md`.

**Tech Stack:** Laravel 12, Pest 3, Inertia/React 19, shadcn/ui, Tailwind.

## Global Constraints

- PHP roda SÓ no container: `docker compose exec php php ...` e `docker compose exec php ./vendor/bin/pest ...`. O wrapper `./php` do repo está quebrado — não usar.
- npm/node rodam no HOST (node 23): `npm run build`, `npm run typecheck`.
- Baseline de testes: **8 falhas pré-existentes** no domínio exportação (`ExportacaoProcessoServiceTest`, `DownloadProcessoControllerTest`, `ExportacaoPipelineTest`). Critério de aceite: número de falhas NÃO cresce; as novas suítes passam 100%.
- Tabela `api_tokens` vive na conexão **default** (pgsql `ms_mni`) — NÃO na conexão `sim`. Model `ApiToken` sem `$connection`. Testes usam `DatabaseTransactions` padrão (não `SimDatabaseTestCase`).
- Header da API mantido: `X-API-Token`. Mensagem 401 mantida verbatim: `Token inválido ou não fornecido`.
- Prefixo do token plaintext: `mni_` + 48 chars aleatórios. Plaintext nunca persiste; banco guarda só SHA-256 (hex, 64 chars).
- Mensagens de validação da tela em PT-BR via `messages()` no FormRequest (o projeto não tem `lang/`; sem `messages()` sai chave literal).
- Commits frequentes, um por task no mínimo.

---

### Task 1: Migration + Model `ApiToken` + Factory + helper de teste

**Files:**
- Create: `database/migrations/2026_07_10_000000_create_api_tokens_table.php`
- Create: `app/Models/ApiToken.php`
- Create: `database/factories/ApiTokenFactory.php`
- Modify: `tests/Pest.php` (helper `criarTokenApi`)
- Test: `tests/Feature/ApiTokenModelTest.php`

**Interfaces:**
- Consumes: nada (primeira task).
- Produces:
  - `App\Models\ApiToken` (Eloquent, conexão default) com colunas `id, name, token, ativo, expires_at, last_used_at, created_at, updated_at`; casts `ativo: boolean`, `expires_at/last_used_at: datetime`.
  - `ApiToken::generatePlainToken(): string` — retorna `'mni_' . Str::random(48)`.
  - `ApiToken::hashToken(string $plainToken): string` — retorna `hash('sha256', $plainToken)`.
  - `ApiToken::findValid(string $plainToken): ?ApiToken` — busca por hash com `ativo=true` e não expirado; senão `null`.
  - Scope `valido()` — `ativo=true` e (`expires_at` null ou `> now()`).
  - `$token->registrarUso(): void` — grava `last_used_at = now()` sem tocar `updated_at`; no-op se último uso < 1 minuto atrás.
  - Helper global de teste `criarTokenApi(string $plain = 'tk-test'): ApiToken` em `tests/Pest.php`.

- [ ] **Step 1: Escrever migration**

```php
<?php
// database/migrations/2026_07_10_000000_create_api_tokens_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('token', 64)->unique();
            $table->boolean('ativo')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
```

- [ ] **Step 2: Rodar migration**

Run: `docker compose exec php php artisan migrate`
Expected: `2026_07_10_000000_create_api_tokens_table ......... DONE`

- [ ] **Step 3: Escrever teste do model (falhando)**

```php
<?php
// tests/Feature/ApiTokenModelTest.php

use App\Models\ApiToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('generatePlainToken gera token com prefixo mni_ e 52 chars', function () {
    $plain = ApiToken::generatePlainToken();

    expect($plain)->toStartWith('mni_')->toHaveLength(52);
});

it('findValid retorna token ativo sem expiração', function () {
    $token = criarTokenApi('mni_teste_valido');

    expect(ApiToken::findValid('mni_teste_valido')?->id)->toBe($token->id);
});

it('findValid retorna null para token inexistente', function () {
    expect(ApiToken::findValid('mni_nao_existe'))->toBeNull();
});

it('findValid retorna null para token inativo', function () {
    criarTokenApi('mni_teste_inativo')->update(['ativo' => false]);

    expect(ApiToken::findValid('mni_teste_inativo'))->toBeNull();
});

it('findValid retorna null para token expirado', function () {
    criarTokenApi('mni_teste_expirado')->update(['expires_at' => now()->subMinute()]);

    expect(ApiToken::findValid('mni_teste_expirado'))->toBeNull();
});

it('findValid retorna token com expiração futura', function () {
    criarTokenApi('mni_teste_futuro')->update(['expires_at' => now()->addDay()]);

    expect(ApiToken::findValid('mni_teste_futuro'))->not->toBeNull();
});

it('registrarUso grava last_used_at sem tocar updated_at', function () {
    $token = criarTokenApi();
    $updatedAt = $token->fresh()->updated_at;

    $token->registrarUso();

    $fresh = $token->fresh();
    expect($fresh->last_used_at)->not->toBeNull();
    expect($fresh->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('registrarUso não regrava quando uso recente (menos de 1 minuto)', function () {
    $token = criarTokenApi();
    $primeiroUso = now()->subSeconds(30);
    $token->forceFill(['last_used_at' => $primeiroUso])->saveQuietly();

    $token->refresh()->registrarUso();

    expect($token->fresh()->last_used_at->equalTo($primeiroUso))->toBeTrue();
});
```

E o helper em `tests/Pest.php` — substituir a função placeholder:

```php
// tests/Pest.php — substituir:
function something()
{
    // ..
}

// por:
function criarTokenApi(string $plain = 'tk-test'): \App\Models\ApiToken
{
    return \App\Models\ApiToken::create([
        'name' => 'token-teste-' . \Illuminate\Support\Str::random(8),
        'token' => \App\Models\ApiToken::hashToken($plain),
        'ativo' => true,
    ]);
}
```

- [ ] **Step 4: Rodar teste para ver falhar**

Run: `docker compose exec php ./vendor/bin/pest tests/Feature/ApiTokenModelTest.php`
Expected: FAIL — `Class "App\Models\ApiToken" not found`

- [ ] **Step 5: Implementar model e factory**

```php
<?php
// app/Models/ApiToken.php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    /** @use HasFactory<\Database\Factories\ApiTokenFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'token',
        'ativo',
        'expires_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public static function generatePlainToken(): string
    {
        return 'mni_' . Str::random(48);
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function scopeValido(Builder $query): Builder
    {
        return $query
            ->where('ativo', true)
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public static function findValid(string $plainToken): ?self
    {
        return static::query()
            ->valido()
            ->where('token', static::hashToken($plainToken))
            ->first();
    }

    public function registrarUso(): void
    {
        if ($this->last_used_at !== null && $this->last_used_at->gt(now()->subMinute())) {
            return;
        }

        static::withoutTimestamps(function () {
            $this->forceFill(['last_used_at' => now()])->saveQuietly();
        });
    }
}
```

```php
<?php
// database/factories/ApiTokenFactory.php

namespace Database\Factories;

use App\Models\ApiToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    protected $model = ApiToken::class;

    public function definition(): array
    {
        return [
            'name' => 'token-' . fake()->unique()->slug(2),
            'token' => ApiToken::hashToken(ApiToken::generatePlainToken()),
            'ativo' => true,
            'expires_at' => null,
            'last_used_at' => null,
        ];
    }
}
```

- [ ] **Step 6: Rodar teste para ver passar**

Run: `docker compose exec php ./vendor/bin/pest tests/Feature/ApiTokenModelTest.php`
Expected: PASS (8 testes)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_10_000000_create_api_tokens_table.php app/Models/ApiToken.php database/factories/ApiTokenFactory.php tests/Pest.php tests/Feature/ApiTokenModelTest.php
git commit -m "feat(api-tokens): migration, model ApiToken e factory"
```

---

### Task 2: Reescrever middleware `ValidateApiToken`

**Files:**
- Modify: `app/Http/Middleware/ValidateApiToken.php`
- Test: `tests/Feature/Api/ValidateApiTokenTest.php`

**Interfaces:**
- Consumes: `ApiToken::findValid(string): ?ApiToken`, `$token->registrarUso(): void`, helper `criarTokenApi(string $plain)` (Task 1).
- Produces: middleware que autoriza somente tokens da tabela. Rotas da API inalteradas. `config('services.api.token')` deixa de ser lido.

- [ ] **Step 1: Escrever teste do middleware (falhando)**

Usa a rota real `GET /api/tribunais` (protegida pelo middleware, leitura simples):

```php
<?php
// tests/Feature/Api/ValidateApiTokenTest.php

use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('aceita token válido da tabela', function () {
    criarTokenApi('mni_token_valido');

    $this->withHeaders(['X-API-Token' => 'mni_token_valido'])
        ->getJson('/api/tribunais')
        ->assertOk();
});

it('rejeita requisição sem token', function () {
    $this->getJson('/api/tribunais')
        ->assertStatus(401)
        ->assertJson(['message' => 'Token inválido ou não fornecido']);
});

it('rejeita token desconhecido', function () {
    criarTokenApi('mni_token_valido');

    $this->withHeaders(['X-API-Token' => 'mni_token_errado'])
        ->getJson('/api/tribunais')
        ->assertStatus(401)
        ->assertJson(['message' => 'Token inválido ou não fornecido']);
});

it('rejeita token inativo', function () {
    criarTokenApi('mni_token_inativo')->update(['ativo' => false]);

    $this->withHeaders(['X-API-Token' => 'mni_token_inativo'])
        ->getJson('/api/tribunais')
        ->assertStatus(401);
});

it('rejeita token expirado', function () {
    criarTokenApi('mni_token_expirado')->update(['expires_at' => now()->subMinute()]);

    $this->withHeaders(['X-API-Token' => 'mni_token_expirado'])
        ->getJson('/api/tribunais')
        ->assertStatus(401);
});

it('não aceita mais o token do config/env', function () {
    config()->set('services.api.token', 'tk-env-antigo');

    $this->withHeaders(['X-API-Token' => 'tk-env-antigo'])
        ->getJson('/api/tribunais')
        ->assertStatus(401);
});

it('registra last_used_at ao usar o token', function () {
    $token = criarTokenApi('mni_token_uso');

    $this->withHeaders(['X-API-Token' => 'mni_token_uso'])
        ->getJson('/api/tribunais')
        ->assertOk();

    expect($token->fresh()->last_used_at)->not->toBeNull();
});
```

- [ ] **Step 2: Rodar teste para ver falhar**

Run: `docker compose exec php ./vendor/bin/pest tests/Feature/Api/ValidateApiTokenTest.php`
Expected: FAIL — "aceita token válido da tabela" e "não aceita mais o token do config/env" quebram (middleware ainda compara com config)

- [ ] **Step 3: Reescrever middleware**

Substituir o corpo de `app/Http/Middleware/ValidateApiToken.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->header('X-API-Token');

        $apiToken = $plainToken ? ApiToken::findValid($plainToken) : null;

        if (! $apiToken) {
            return response()->json([
                'message' => 'Token inválido ou não fornecido',
            ], 401);
        }

        $apiToken->registrarUso();

        return $next($request);
    }
}
```

- [ ] **Step 4: Rodar teste para ver passar**

Run: `docker compose exec php ./vendor/bin/pest tests/Feature/Api/ValidateApiTokenTest.php`
Expected: PASS (7 testes)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/ValidateApiToken.php tests/Feature/Api/ValidateApiTokenTest.php
git commit -m "feat(api-tokens): middleware valida contra tabela api_tokens"
```

---

### Task 3: Migrar testes de API existentes do token do config para a tabela

**Files:**
- Modify: `tests/Feature/Api/ConsultarProcessoControllerTest.php:12`
- Modify: `tests/Feature/Api/DocumentoControllerTest.php:9`
- Modify: `tests/Feature/Api/DownloadProcessoControllerTest.php:13`
- Modify: `tests/Feature/ExportacaoPipelineTest.php:17`

**Interfaces:**
- Consumes: helper `criarTokenApi(string $plain = 'tk-test')` (Task 1).
- Produces: nenhuma interface nova — testes existentes voltam a passar (dentro do baseline).

Todos os 4 arquivos já usam `uses(DatabaseTransactions::class)` (conexão default) — a criação do `ApiToken` sofre rollback normalmente.

- [ ] **Step 1: Substituir setup do token nos 4 arquivos**

Em CADA um dos 4 arquivos, substituir a linha:

```php
    config()->set('services.api.token', 'tk-test');
```

por:

```php
    criarTokenApi();
```

Nos 3 primeiros arquivos a linha está dentro do `beforeEach(...)`. Em `ExportacaoPipelineTest.php` está dentro do próprio `it('pipeline: ...')` — substituir só essa linha; NÃO tocar nas linhas vizinhas `services.sim_webhook_download.*` (token de SAÍDA para o SIM, fora do escopo).

- [ ] **Step 2: Rodar os testes afetados**

Run: `docker compose exec php ./vendor/bin/pest tests/Feature/Api/ConsultarProcessoControllerTest.php tests/Feature/Api/DocumentoControllerTest.php tests/Feature/Api/DownloadProcessoControllerTest.php tests/Feature/ExportacaoPipelineTest.php`
Expected: `ConsultarProcessoControllerTest` e `DocumentoControllerTest` PASS. `DownloadProcessoControllerTest` e `ExportacaoPipelineTest` mantêm as falhas PRÉ-EXISTENTES do domínio exportação (baseline) — verificar que nenhuma falha nova é 401/token (se aparecer 401, a migração do setup falhou).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Api/ConsultarProcessoControllerTest.php tests/Feature/Api/DocumentoControllerTest.php tests/Feature/Api/DownloadProcessoControllerTest.php tests/Feature/ExportacaoPipelineTest.php
git commit -m "test(api-tokens): testes de API usam token da tabela em vez do config"
```

---

### Task 4: CRUD web — rotas, controller, FormRequest, flash do token

**Files:**
- Modify: `routes/web.php`
- Create: `app/Http/Controllers/ApiTokenController.php`
- Create: `app/Http/Requests/ApiTokenRequest.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` (flash `token`)
- Test: `tests/Feature/ApiTokenCrudTest.php`

**Interfaces:**
- Consumes: `ApiToken` model (Task 1): `generatePlainToken()`, `hashToken()`, colunas.
- Produces:
  - Rotas nomeadas: `tokens.index` (GET /tokens), `tokens.create` (GET /tokens/criar), `tokens.store` (POST /tokens), `tokens.toggle` (PATCH /tokens/{apiToken}/ativo), `tokens.destroy` (DELETE /tokens/{apiToken}).
  - Página Inertia `tokens/index` com prop `tokens: Array<{id, name, ativo, expires_at, last_used_at, created_at}>`.
  - Página Inertia `tokens/create` sem props.
  - Flash compartilhado `flash.token: string | null` (plaintext, exibido uma única vez após store).

- [ ] **Step 1: Escrever teste do CRUD (falhando)**

```php
<?php
// tests/Feature/ApiTokenCrudTest.php

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseTransactions::class);

function usuarioLogado(): User
{
    return User::factory()->make(['id' => 1]);
}

it('redireciona visitante para o login', function () {
    $this->get('/tokens')->assertRedirect('/login');
});

it('lista tokens no componente tokens/index', function () {
    ApiToken::factory()->create(['name' => 'aaa-token-listado']);

    $this->actingAs(usuarioLogado())
        ->get('/tokens')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tokens/index')
            ->has('tokens')
            ->where('tokens.0.name', 'aaa-token-listado'));
});

it('renderiza o formulário de criação', function () {
    $this->actingAs(usuarioLogado())
        ->get('/tokens/criar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tokens/create'));
});

it('cria token, persiste hash e flasha o plaintext uma única vez', function () {
    $response = $this->actingAs(usuarioLogado())
        ->post('/tokens', ['name' => 'clickpdv', 'expires_at' => null]);

    $response->assertRedirect(route('tokens.index'))
        ->assertSessionHas('success')
        ->assertSessionHas('token');

    $plain = session('token');
    expect($plain)->toStartWith('mni_')->toHaveLength(52);

    $registro = ApiToken::where('name', 'clickpdv')->first();
    expect($registro)->not->toBeNull();
    expect($registro->token)->toBe(ApiToken::hashToken($plain));
    expect($registro->ativo)->toBeTrue();
});

it('cria token com data de expiração', function () {
    $this->actingAs(usuarioLogado())
        ->post('/tokens', ['name' => 'token-expira', 'expires_at' => now()->addMonth()->format('Y-m-d')])
        ->assertRedirect(route('tokens.index'));

    expect(ApiToken::where('name', 'token-expira')->first()->expires_at)->not->toBeNull();
});

it('rejeita nome duplicado', function () {
    ApiToken::factory()->create(['name' => 'duplicado']);

    $this->actingAs(usuarioLogado())
        ->post('/tokens', ['name' => 'duplicado'])
        ->assertSessionHasErrors('name');
});

it('rejeita expiração no passado', function () {
    $this->actingAs(usuarioLogado())
        ->post('/tokens', ['name' => 'token-passado', 'expires_at' => now()->subDay()->format('Y-m-d')])
        ->assertSessionHasErrors('expires_at');
});

it('alterna ativo do token', function () {
    $token = ApiToken::factory()->create(['ativo' => true]);

    $this->actingAs(usuarioLogado())
        ->patch("/tokens/{$token->id}/ativo")
        ->assertRedirect();

    expect($token->fresh()->ativo)->toBeFalse();
});

it('revoga (exclui) token', function () {
    $token = ApiToken::factory()->create();

    $this->actingAs(usuarioLogado())
        ->delete("/tokens/{$token->id}")
        ->assertRedirect(route('tokens.index'));

    expect(ApiToken::find($token->id))->toBeNull();
});
```

- [ ] **Step 2: Rodar teste para ver falhar**

Run: `docker compose exec php ./vendor/bin/pest tests/Feature/ApiTokenCrudTest.php`
Expected: FAIL — rotas não existem (404 em vez de redirect/200)

- [ ] **Step 3: Implementar FormRequest, controller e rotas**

```php
<?php
// app/Http/Requests/ApiTokenRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('api_tokens', 'name')],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe um nome para o token.',
            'name.unique' => 'Já existe um token com esse nome.',
            'name.max' => 'O nome pode ter no máximo 255 caracteres.',
            'expires_at.date' => 'Data de expiração inválida.',
            'expires_at.after' => 'A data de expiração deve ser futura.',
        ];
    }
}
```

```php
<?php
// app/Http/Controllers/ApiTokenController.php

namespace App\Http\Controllers;

use App\Http\Requests\ApiTokenRequest;
use App\Models\ApiToken;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('tokens/index', [
            'tokens' => ApiToken::query()
                ->select(['id', 'name', 'ativo', 'expires_at', 'last_used_at', 'created_at'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('tokens/create');
    }

    public function store(ApiTokenRequest $request): RedirectResponse
    {
        $plainToken = ApiToken::generatePlainToken();

        ApiToken::create([
            'name' => $request->validated()['name'],
            'token' => ApiToken::hashToken($plainToken),
            'ativo' => true,
            'expires_at' => $request->validated()['expires_at'] ?? null,
        ]);

        return redirect()->route('tokens.index')
            ->with('success', 'Token criado.')
            ->with('token', $plainToken);
    }

    public function toggleAtivo(ApiToken $apiToken): RedirectResponse
    {
        $apiToken->update(['ativo' => ! $apiToken->ativo]);

        return back()->with('success', 'Token atualizado.');
    }

    public function destroy(ApiToken $apiToken): RedirectResponse
    {
        $apiToken->delete();

        return redirect()->route('tokens.index')->with('success', 'Token revogado.');
    }
}
```

Em `routes/web.php`, adicionar o import e as rotas dentro do grupo `auth:web`, logo após o bloco de tribunais:

```php
use App\Http\Controllers\ApiTokenController;
```

```php
    Route::get('/tokens', [ApiTokenController::class, 'index'])->name('tokens.index');
    Route::get('/tokens/criar', [ApiTokenController::class, 'create'])->name('tokens.create');
    Route::post('/tokens', [ApiTokenController::class, 'store'])->name('tokens.store');
    Route::patch('/tokens/{apiToken}/ativo', [ApiTokenController::class, 'toggleAtivo'])->name('tokens.toggle');
    Route::delete('/tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->name('tokens.destroy');
```

Em `app/Http/Middleware/HandleInertiaRequests.php`, no array `flash`, adicionar a linha do token:

```php
            'flash' => [
                'success' => $request->session()->get('success'),
                'token' => $request->session()->get('token'),
            ],
```

- [ ] **Step 4: Rodar teste para ver passar**

Run: `docker compose exec php ./vendor/bin/pest tests/Feature/ApiTokenCrudTest.php`
Expected: PASS (9 testes). Nota: as páginas Inertia `tokens/index`/`tokens/create` ainda não existem como .tsx — `assertInertia` valida o nome do componente na resposta, não o arquivo; passa sem o frontend.

- [ ] **Step 5: Commit**

```bash
git add routes/web.php app/Http/Controllers/ApiTokenController.php app/Http/Requests/ApiTokenRequest.php app/Http/Middleware/HandleInertiaRequests.php tests/Feature/ApiTokenCrudTest.php
git commit -m "feat(api-tokens): CRUD web (rotas, controller, request, flash do plaintext)"
```

---

### Task 5: Frontend — páginas Inertia, tipos, sidebar

**Files:**
- Modify: `resources/js/types/index.d.ts`
- Create: `resources/js/pages/tokens/index.tsx`
- Create: `resources/js/pages/tokens/create.tsx`
- Modify: `resources/js/components/app-sidebar.tsx`

**Interfaces:**
- Consumes: props do controller (Task 4): `tokens: ApiTokenListItem[]`, `flash.token: string | null`; rotas `tokens.*`.
- Produces: telas prontas; item "Tokens de API" na sidebar.

- [ ] **Step 1: Adicionar tipos**

Em `resources/js/types/index.d.ts`, atualizar `SharedProps.flash` e adicionar o tipo da listagem (junto aos tipos existentes, perto de `TribunalListItem`):

```typescript
export interface ApiTokenListItem {
    id: number;
    name: string;
    ativo: boolean;
    expires_at: string | null;
    last_used_at: string | null;
    created_at: string;
}
```

```typescript
// dentro de SharedProps, trocar:
    flash: { success: string | null };
// por:
    flash: { success: string | null; token: string | null };
```

- [ ] **Step 2: Criar página de listagem**

```tsx
// resources/js/pages/tokens/index.tsx
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, Copy, Plus } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type ApiTokenListItem, type BreadcrumbItem, type SharedProps } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tokens de API', href: '/tokens' }];

function formatarData(valor: string | null): string {
    if (!valor) {
        return '—';
    }
    return new Date(valor).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
}

export default function TokensIndex({ tokens }: { tokens: ApiTokenListItem[] }) {
    const { flash } = usePage<SharedProps>().props;
    const [copiado, setCopiado] = useState(false);

    function toggleAtivo(id: number) {
        router.patch(`/tokens/${id}/ativo`, {}, { preserveScroll: true });
    }

    function revogar(token: ApiTokenListItem) {
        if (confirm(`Revogar o token "${token.name}"? Os sistemas que o utilizam perderão acesso imediatamente.`)) {
            router.delete(`/tokens/${token.id}`, { preserveScroll: true });
        }
    }

    async function copiarToken() {
        if (flash.token) {
            await navigator.clipboard.writeText(flash.token);
            setCopiado(true);
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tokens de API" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Tokens de API</h1>
                    <Button asChild>
                        <Link href="/tokens/criar" prefetch>
                            <Plus /> Novo token
                        </Link>
                    </Button>
                </div>

                {flash.token && (
                    <div className="flex flex-col gap-2 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                        <p className="font-semibold">
                            Copie o token agora — ele não será mostrado novamente.
                        </p>
                        <div className="flex items-center gap-2">
                            <code className="flex-1 overflow-x-auto rounded bg-amber-100 px-2 py-1 font-mono dark:bg-amber-900">
                                {flash.token}
                            </code>
                            <Button variant="outline" size="sm" onClick={copiarToken}>
                                {copiado ? <Check /> : <Copy />}
                                {copiado ? 'Copiado' : 'Copiar'}
                            </Button>
                        </div>
                    </div>
                )}

                {flash.success && !flash.token && (
                    <div className="rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
                        {flash.success}
                    </div>
                )}

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nome</TableHead>
                                <TableHead>Ativo</TableHead>
                                <TableHead>Expira em</TableHead>
                                <TableHead>Último uso</TableHead>
                                <TableHead>Criado em</TableHead>
                                <TableHead className="w-0" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tokens.map((token) => (
                                <TableRow key={token.id}>
                                    <TableCell className="font-medium">{token.name}</TableCell>
                                    <TableCell>
                                        <Switch
                                            checked={Boolean(token.ativo)}
                                            onCheckedChange={() => toggleAtivo(token.id)}
                                            aria-label={`Ativar/desativar ${token.name}`}
                                        />
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {token.expires_at ? formatarData(token.expires_at) : 'Nunca'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatarData(token.last_used_at)}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatarData(token.created_at)}
                                    </TableCell>
                                    <TableCell>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-destructive hover:text-destructive"
                                            onClick={() => revogar(token)}
                                        >
                                            Revogar
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                            {tokens.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={6} className="text-center text-muted-foreground">
                                        Nenhum token cadastrado.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
```

- [ ] **Step 3: Criar página de criação**

```tsx
// resources/js/pages/tokens/create.tsx
import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tokens de API', href: '/tokens' },
    { title: 'Novo token', href: '/tokens/criar' },
];

export default function TokensCreate() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        expires_at: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/tokens');
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Novo token" />
            <div className="flex flex-col gap-6 p-4">
                <h1 className="text-2xl font-bold">Novo token</h1>

                <form onSubmit={submit} className="flex max-w-lg flex-col gap-4">
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="name">Nome</Label>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="ex.: clickpdv"
                            autoFocus
                        />
                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                    </div>

                    <div className="flex flex-col gap-2">
                        <Label htmlFor="expires_at">Data de expiração (opcional)</Label>
                        <Input
                            id="expires_at"
                            type="date"
                            value={data.expires_at}
                            onChange={(e) => setData('expires_at', e.target.value)}
                        />
                        {errors.expires_at && (
                            <p className="text-sm text-destructive">{errors.expires_at}</p>
                        )}
                        <p className="text-sm text-muted-foreground">
                            Em branco, o token nunca expira.
                        </p>
                    </div>

                    <div className="flex gap-2">
                        <Button type="submit" disabled={processing}>
                            Gerar token
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/tokens">Cancelar</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
```

- [ ] **Step 4: Adicionar item na sidebar**

Em `resources/js/components/app-sidebar.tsx`:

```tsx
// linha 2, adicionar KeyRound ao import:
import { Activity, FileText, KeyRound, Landmark, LayoutGrid, LayoutList } from 'lucide-react';

// em mainNavItems, após o item Tribunais:
    {
        title: 'Tokens de API',
        href: '/tokens',
        icon: KeyRound,
    },
```

- [ ] **Step 5: Verificar typecheck e build (no HOST, não no container)**

Run: `npm run typecheck && npm run build`
Expected: typecheck sem erros; build Vite conclui com `✓ built in ...`

- [ ] **Step 6: Commit**

```bash
git add resources/js/types/index.d.ts resources/js/pages/tokens/ resources/js/components/app-sidebar.tsx
git commit -m "feat(api-tokens): telas de listagem/criação e item na sidebar"
```

---

### Task 6: Corte seco — remover config/env legado e validar suíte completa

**Files:**
- Modify: `config/services.php` (remover bloco `'api'`)
- Modify: `.env` local (remover `API_TOKEN=...` se existir)

**Interfaces:**
- Consumes: middleware novo (Task 2) — nada mais lê `services.api.token` (verificado: única referência era o middleware).
- Produces: estado final — token do `.env` morto.

- [ ] **Step 1: Confirmar que nada mais referencia o config**

Run: `grep -rn "services.api.token\|services\.api\b" app/ config/ routes/ tests/ | grep -v "sim_webhook"`
Expected: apenas `config/services.php:39` (a definição) e, se houver, o teste de Task 2 que usa `config()->set('services.api.token', ...)` para provar que o valor antigo não vale mais (esse pode ficar).

- [ ] **Step 2: Remover o bloco do config**

Em `config/services.php`, remover:

```php
    'api' => [
        'token' => env('API_TOKEN'),
    ],
```

NÃO tocar em `sim_webhook_download` (token de saída `MS_MNI_API_TOKEN` — outro fluxo).

- [ ] **Step 3: Remover API_TOKEN do .env local**

Run: `grep -n "^API_TOKEN" .env && sed -i '/^API_TOKEN=/d' .env || echo "API_TOKEN ausente"`
Expected: linha removida ou "API_TOKEN ausente". (`.env.example` não tem `API_TOKEN` — nada a fazer lá.)

- [ ] **Step 4: Rodar a suíte completa**

Run: `docker compose exec php ./vendor/bin/pest`
Expected: falhas ≤ 8, todas no baseline do domínio exportação (ExportacaoProcessoServiceTest, DownloadProcessoControllerTest, ExportacaoPipelineTest). Nenhuma falha nova de 401/token. Novas suítes (ApiTokenModelTest, ValidateApiTokenTest, ApiTokenCrudTest) 100% verdes.

- [ ] **Step 5: Commit**

```bash
git add config/services.php
git commit -m "feat(api-tokens)!: corte seco do API_TOKEN do .env

BREAKING CHANGE: a API só aceita tokens gerados na tela /tokens.
Gerar um token por consumidor e atualizar o valor do header X-API-Token."
```

---

## Rollout pós-merge (manual, fora do plano de código)

1. Deploy + `php artisan migrate`.
2. Gerar um token na tela `/tokens` para cada consumidor (clickpdv, SIM, ...).
3. Atualizar o valor do `X-API-Token` em cada cliente.
4. Remover `API_TOKEN` do `.env` de produção.

Entre 1 e 3 os consumidores ficam sem acesso (decisão de corte seco na spec).
