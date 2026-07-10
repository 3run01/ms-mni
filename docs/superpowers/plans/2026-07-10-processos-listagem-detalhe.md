# Processos — Listagem com Filtros e Detalhe (read-only) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Telas web (Inertia/React) para listar/filtrar processos e visualizar o detalhe completo de um processo, somente leitura.

**Architecture:** Novo `ProcessoController` (web, não tocar o de `Api\`) com `index` (paginação server-side + 8 filtros via query string) e `show` (dados gerais + partes eager; movimentos/documentos como deferred props do Inertia v2). Frontend segue o padrão das telas de tribunais: `AppLayout` + shadcn/ui, duas páginas novas em `resources/js/pages/processos/`.

**Tech Stack:** Laravel 11 + Inertia v2 (`Inertia::defer`), React 19, shadcn/ui (componentes escritos à mão — CLI quebrado no ambiente), Pest.

**Spec:** `docs/superpowers/specs/2026-07-10-processos-listagem-detalhe-design.md`

## Global Constraints

- PHP **só roda no container**: `docker compose exec -T php php ...`. O wrapper `./php` do repo está quebrado. Subir com `docker compose up -d --no-deps php`.
- Testes: `docker compose exec -T php php vendor/bin/pest <arquivo>`. Baseline: **8 falhas pré-existentes** no domínio exportação (ExportacaoProcessoServiceTest, DownloadProcessoControllerTest, ExportacaoPipelineTest) — ignorar, não introduzir novas.
- npm/node **no host** (`npm run typecheck`, `npm run build`).
- `npx shadcn add` **quebrado** (EACCES em `storage/framework/testing/disks` root-owned) — componentes ui escritos à mão, imports do meta-package `radix-ui` (padrão do projeto, ver `resources/js/components/ui/tooltip.tsx`).
- Conexão default é **pgsql** (processos etc.); tabela `tribunais` fica na conexão **`sim`**. Sem FK entre elas — `tribunal_id` aceita qualquer inteiro.
- Testes rodam contra o **banco dev real (24.489 processos)** dentro de transações. NUNCA assertar contagens globais — sempre escopar por `numero_processo` com prefixo único do teste (filtro `busca`).
- Helpers de Pest são funções PHP globais: nomes novos não podem colidir com `autenticado()`/`tribunalPayload()` de `TribunalCrudTest.php`.
- Model `Processo` tem `$with` default (tribunal, prioridades, classe, assuntos) e `$hidden` com `id` — payloads Inertia sempre via arrays explícitos (`through`/`map`), nunca serialização direta do model.
- Coluna `nivel_sigilo` é **varchar** no Postgres — comparar sempre com string (`(string) $valor`), senão erro de operador varchar = integer.
- Não alterar models existentes nem o controller `Api\ProcessoController`.
- Textos de UI em pt-BR, seguindo tom das telas de tribunais.

## File Structure

| Arquivo | Ação | Responsabilidade |
| --- | --- | --- |
| `routes/web.php` | Modify | +2 rotas GET (`processos.index`, `processos.show`) |
| `app/Http/Controllers/ProcessoController.php` | Create | index (filtros+paginação) e show (payload+deferred) |
| `database/factories/ProcessoFactory.php` | Create | factory mínima de Processo |
| `tests/MultiConnectionDatabaseTestCase.php` | Create | trait de transações nas conexões default + sim |
| `tests/Feature/ProcessoConsultaTest.php` | Create | testes de index e show |
| `resources/js/components/ui/badge.tsx` | Create | shadcn Badge |
| `resources/js/components/ui/tabs.tsx` | Create | shadcn Tabs (radix) |
| `resources/js/components/ui/popover.tsx` | Create | shadcn Popover (radix) |
| `resources/js/components/ui/collapsible.tsx` | Create | shadcn Collapsible (radix) |
| `resources/js/components/pagination.tsx` | Create | paginação a partir dos links do paginator Laravel |
| `resources/js/lib/format.ts` | Create | formatadores (moeda, data, bytes, CPF/CNPJ, flags) |
| `resources/js/types/index.d.ts` | Modify | tipos Paginated, ProcessoListItem, filtros, detalhe |
| `resources/js/components/app-sidebar.tsx` | Modify | item "Processos" antes de "Tribunais" |
| `resources/js/pages/processos/index.tsx` | Create | listagem + filtros + tabela + paginação |
| `resources/js/pages/processos/show.tsx` | Create | detalhe: cabeçalho + abas |

---

### Task 1: Infra de teste + rota e `index` básico (auth + paginação)

**Files:**

- Create: `tests/MultiConnectionDatabaseTestCase.php`
- Create: `database/factories/ProcessoFactory.php`
- Create: `tests/Feature/ProcessoConsultaTest.php`
- Create: `app/Http/Controllers/ProcessoController.php`
- Modify: `routes/web.php`

**Interfaces:**

- Consumes: `App\Models\Processo` (constantes de status, `$with` default), `Tests\SimDatabaseTestCase` como referência de padrão.
- Produces: rota `GET /processos` → `processos.index` renderizando `processos/index` com prop `processos` (paginator com itens `{id, numero_processo, tribunal, classe, status, valor_causa, created_at}`); helper Pest `loginProcessos(): User`; helper `novoProcesso(array $overrides = []): Processo`; `Database\Factories\ProcessoFactory` com defaults `status=Peticionado`, `valor_causa=1000.50`, `nivel_sigilo='0'`, `tribunal_id=999999`. Task 2 adiciona filtros neste mesmo controller/teste.

- [ ] **Step 1: Criar trait de transações multi-conexão**

`tests/MultiConnectionDatabaseTestCase.php`:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Trait (não classe — ver nota em SimDatabaseTestCase) para testes que
 * escrevem na conexão default (pgsql: processos etc.) E na conexão sim
 * (tribunais). `null` = conexão default.
 */
trait MultiConnectionDatabaseTestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'sim'];
}
```

- [ ] **Step 2: Criar ProcessoFactory**

`database/factories/ProcessoFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Processo;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProcessoFactory extends Factory
{
    protected $model = Processo::class;

    public function definition(): array
    {
        return [
            // tribunais ficam na conexão `sim` — sem FK; sobrescrever nos
            // testes que precisam da relação com um Tribunal::factory() real
            'tribunal_id' => 999999,
            'numero_processo' => $this->faker->numerify('####################'),
            'status' => Processo::STATUS_PETICIONADO,
            'valor_causa' => 1000.50,
            'nivel_sigilo' => '0',
        ];
    }
}
```

- [ ] **Step 3: Escrever testes que falham (auth, componente, paginação)**

`tests/Feature/ProcessoConsultaTest.php`:

```php
<?php

use App\Models\Processo;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\MultiConnectionDatabaseTestCase;

uses(MultiConnectionDatabaseTestCase::class);

function loginProcessos(): User
{
    return User::factory()->make(['id' => 1]);
}

function novoProcesso(array $overrides = []): Processo
{
    return Processo::factory()->create($overrides);
}

it('redireciona visitante para o login na listagem', function () {
    $this->get('/processos')->assertRedirect('/login');
});

it('lista processos paginados no componente processos/index', function () {
    $prefixo = 'T1LISTA' . getmypid();
    novoProcesso(['numero_processo' => $prefixo . '001']);

    $this->actingAs(loginProcessos())
        ->get('/processos?busca=' . $prefixo)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('processos/index')
            ->has('processos.data', 1)
            ->where('processos.data.0.numero_processo', $prefixo . '001')
            ->has('processos.data.0.id')
            ->has('processos.total')
            ->has('processos.links'));
});

it('pagina de 20 em 20 preservando a query string', function () {
    $prefixo = 'T1PAG' . getmypid();
    for ($i = 1; $i <= 25; $i++) {
        novoProcesso(['numero_processo' => $prefixo . str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
    }

    $this->actingAs(loginProcessos())
        ->get('/processos?busca=' . $prefixo)
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 20)
            ->where('processos.total', 25)
            ->where('processos.current_page', 1));

    $this->actingAs(loginProcessos())
        ->get('/processos?busca=' . $prefixo . '&page=2')
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 5)
            ->where('processos.current_page', 2));
});
```

Nota: o teste de paginação já usa o filtro `busca` (implementado nesta task junto com a paginação) porque é impossível assertar contra o banco real de 24k linhas sem escopar.

- [ ] **Step 4: Rodar e ver falhar**

Run: `docker compose exec -T php php vendor/bin/pest tests/Feature/ProcessoConsultaTest.php`
Expected: FAIL — rota `/processos` não existe (404 em vez de redirect/200).

- [ ] **Step 5: Implementar rota + controller mínimo (busca + paginação)**

Em `routes/web.php`, dentro do grupo `auth:web`, após as rotas de tribunais:

```php
Route::get('/processos', [ProcessoController::class, 'index'])->name('processos.index');
```

E o import no topo: `use App\Http\Controllers\ProcessoController;`

`app/Http/Controllers/ProcessoController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Processo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProcessoController extends Controller
{
    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:255'],
        ]);

        $processos = Processo::query()
            ->without(['prioridades', 'assuntos'])
            ->when($filtros['busca'] ?? null,
                fn ($q, $v) => $q->where('numero_processo', 'ilike', "%{$v}%"))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Processo $p) => [
                'id' => $p->id,
                'numero_processo' => $p->numero_processo,
                'tribunal' => $p->tribunal?->nome,
                'classe' => $p->classe?->descricao,
                'status' => $p->status,
                'valor_causa' => $p->valor_causa,
                'created_at' => $p->created_at,
            ]);

        return Inertia::render('processos/index', [
            'processos' => $processos,
            'filtros' => $filtros,
        ]);
    }
}
```

- [ ] **Step 6: Rodar e ver passar**

Run: `docker compose exec -T php php vendor/bin/pest tests/Feature/ProcessoConsultaTest.php`
Expected: PASS (3 testes). Nota: a página React `processos/index` ainda não existe — `assertInertia` não renderiza JS, só inspeciona o payload; OK.

- [ ] **Step 7: Commit**

```bash
git add tests/MultiConnectionDatabaseTestCase.php database/factories/ProcessoFactory.php tests/Feature/ProcessoConsultaTest.php app/Http/Controllers/ProcessoController.php routes/web.php
git commit -m "feat(processos): rota e index basico com busca e paginacao server-side"
```

---

### Task 2: Filtros completos do `index` + props de opções

**Files:**

- Modify: `app/Http/Controllers/ProcessoController.php`
- Modify: `tests/Feature/ProcessoConsultaTest.php`

**Interfaces:**

- Consumes: controller e helpers da Task 1.
- Produces: `index` aceita `busca, tribunal_id, status, data_inicio, data_fim, classe_codigo, orgao_julgador, nivel_sigilo`; props extras `tribunais: [{id, nome}]`, `classes: [{codigo, descricao}]`, `statusOptions: string[]`, `niveisSigilo: {0: 'Não sigiloso', ...}`. Task 5 (frontend) consome exatamente esses nomes.

- [ ] **Step 1: Escrever testes que falham (um por filtro + combinado + validação)**

Adicionar em `tests/Feature/ProcessoConsultaTest.php` (imports extras no topo: `use App\Models\Tribunal;`):

```php
it('filtra por tribunal_id', function () {
    $prefixo = 'T2TRIB' . getmypid();
    $tribunal = Tribunal::factory()->create(['nome' => 'Tribunal Filtro Processos']);
    novoProcesso(['numero_processo' => $prefixo . 'A', 'tribunal_id' => $tribunal->id]);
    novoProcesso(['numero_processo' => $prefixo . 'B', 'tribunal_id' => 999998]);

    $this->actingAs(loginProcessos())
        ->get("/processos?busca={$prefixo}&tribunal_id={$tribunal->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 1)
            ->where('processos.data.0.numero_processo', $prefixo . 'A')
            ->where('processos.data.0.tribunal', 'Tribunal Filtro Processos'));
});

it('filtra por status', function () {
    $prefixo = 'T2STAT' . getmypid();
    novoProcesso(['numero_processo' => $prefixo . 'A', 'status' => Processo::STATUS_PENDENTE_ENVIO]);
    novoProcesso(['numero_processo' => $prefixo . 'B', 'status' => Processo::STATUS_PETICIONADO]);

    $this->actingAs(loginProcessos())
        ->get('/processos?busca=' . $prefixo . '&status=' . urlencode(Processo::STATUS_PENDENTE_ENVIO))
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 1)
            ->where('processos.data.0.status', Processo::STATUS_PENDENTE_ENVIO));
});

it('rejeita status fora do enum', function () {
    $this->actingAs(loginProcessos())
        ->from('/processos')
        ->get('/processos?status=Inventado')
        ->assertRedirect('/processos')
        ->assertSessionHasErrors('status');
});

it('filtra por intervalo de datas de criacao', function () {
    $prefixo = 'T2DATA' . getmypid();
    $antigo = novoProcesso(['numero_processo' => $prefixo . 'A']);
    $antigo->created_at = '2020-01-10 12:00:00';
    $antigo->save();
    $recente = novoProcesso(['numero_processo' => $prefixo . 'B']);
    $recente->created_at = '2020-03-10 12:00:00';
    $recente->save();

    $this->actingAs(loginProcessos())
        ->get("/processos?busca={$prefixo}&data_inicio=2020-03-01&data_fim=2020-03-31")
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 1)
            ->where('processos.data.0.numero_processo', $prefixo . 'B'));
});

it('rejeita data_fim anterior a data_inicio', function () {
    $this->actingAs(loginProcessos())
        ->from('/processos')
        ->get('/processos?data_inicio=2026-02-01&data_fim=2026-01-01')
        ->assertRedirect('/processos')
        ->assertSessionHasErrors('data_fim');
});

it('filtra por classe_codigo', function () {
    $prefixo = 'T2CLAS' . getmypid();
    novoProcesso(['numero_processo' => $prefixo . 'A', 'classe_codigo' => '99991']);
    novoProcesso(['numero_processo' => $prefixo . 'B', 'classe_codigo' => '99992']);

    $this->actingAs(loginProcessos())
        ->get("/processos?busca={$prefixo}&classe_codigo=99991")
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 1)
            ->where('processos.data.0.numero_processo', $prefixo . 'A'));
});

it('filtra por orgao julgador com busca parcial case-insensitive', function () {
    $prefixo = 'T2ORG' . getmypid();
    novoProcesso(['numero_processo' => $prefixo . 'A', 'nome_orgao_julgador' => 'Vara Única de Testolândia']);
    novoProcesso(['numero_processo' => $prefixo . 'B', 'nome_orgao_julgador' => 'Outra Vara']);

    $this->actingAs(loginProcessos())
        ->get("/processos?busca={$prefixo}&orgao_julgador=testol")
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 1)
            ->where('processos.data.0.numero_processo', $prefixo . 'A'));
});

it('filtra por nivel de sigilo', function () {
    $prefixo = 'T2SIG' . getmypid();
    novoProcesso(['numero_processo' => $prefixo . 'A', 'nivel_sigilo' => '5']);
    novoProcesso(['numero_processo' => $prefixo . 'B', 'nivel_sigilo' => '0']);

    $this->actingAs(loginProcessos())
        ->get("/processos?busca={$prefixo}&nivel_sigilo=5")
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 1)
            ->where('processos.data.0.numero_processo', $prefixo . 'A'));
});

it('combina multiplos filtros', function () {
    $prefixo = 'T2COMB' . getmypid();
    novoProcesso(['numero_processo' => $prefixo . 'A', 'status' => Processo::STATUS_ARQUIVADO, 'nivel_sigilo' => '2']);
    novoProcesso(['numero_processo' => $prefixo . 'B', 'status' => Processo::STATUS_ARQUIVADO, 'nivel_sigilo' => '0']);
    novoProcesso(['numero_processo' => $prefixo . 'C', 'status' => Processo::STATUS_PETICIONADO, 'nivel_sigilo' => '2']);

    $this->actingAs(loginProcessos())
        ->get('/processos?busca=' . $prefixo . '&status=' . urlencode(Processo::STATUS_ARQUIVADO) . '&nivel_sigilo=2')
        ->assertInertia(fn (Assert $page) => $page
            ->has('processos.data', 1)
            ->where('processos.data.0.numero_processo', $prefixo . 'A'));
});

it('entrega opcoes de filtro como props', function () {
    $this->actingAs(loginProcessos())
        ->get('/processos')
        ->assertInertia(fn (Assert $page) => $page
            ->has('tribunais')
            ->has('classes')
            ->has('statusOptions', 4)
            ->has('niveisSigilo'));
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose exec -T php php vendor/bin/pest tests/Feature/ProcessoConsultaTest.php`
Expected: FAIL — filtros ignorados (2 resultados em vez de 1), props de opções ausentes.

- [ ] **Step 3: Implementar filtros e props**

Substituir o método `index` em `app/Http/Controllers/ProcessoController.php` (imports extras: `use App\Models\ClasseCNJ;`, `use App\Models\Tribunal;`, `use Illuminate\Validation\Rule;`):

```php
    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:255'],
            'tribunal_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([
                Processo::STATUS_PENDENTE_ENVIO,
                Processo::STATUS_PROCESSANDO_ENVIO,
                Processo::STATUS_PETICIONADO,
                Processo::STATUS_ARQUIVADO,
            ])],
            'data_inicio' => ['nullable', 'date'],
            'data_fim' => ['nullable', 'date', 'after_or_equal:data_inicio'],
            'classe_codigo' => ['nullable', 'string', 'max:255'],
            'orgao_julgador' => ['nullable', 'string', 'max:255'],
            'nivel_sigilo' => ['nullable', 'integer', 'between:0,5'],
        ]);

        $processos = Processo::query()
            ->without(['prioridades', 'assuntos'])
            ->when($filtros['busca'] ?? null,
                fn ($q, $v) => $q->where('numero_processo', 'ilike', "%{$v}%"))
            ->when($filtros['tribunal_id'] ?? null,
                fn ($q, $v) => $q->where('tribunal_id', $v))
            ->when($filtros['status'] ?? null,
                fn ($q, $v) => $q->where('status', $v))
            ->when($filtros['data_inicio'] ?? null,
                fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filtros['data_fim'] ?? null,
                fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filtros['classe_codigo'] ?? null,
                fn ($q, $v) => $q->where('classe_codigo', $v))
            ->when($filtros['orgao_julgador'] ?? null,
                fn ($q, $v) => $q->where('nome_orgao_julgador', 'ilike', "%{$v}%"))
            // nivel_sigilo é varchar no Postgres: comparar como string
            ->when(isset($filtros['nivel_sigilo']),
                fn ($q) => $q->where('nivel_sigilo', (string) $filtros['nivel_sigilo']))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Processo $p) => [
                'id' => $p->id,
                'numero_processo' => $p->numero_processo,
                'tribunal' => $p->tribunal?->nome,
                'classe' => $p->classe?->descricao,
                'status' => $p->status,
                'valor_causa' => $p->valor_causa,
                'created_at' => $p->created_at,
            ]);

        return Inertia::render('processos/index', [
            'processos' => $processos,
            'filtros' => $filtros,
            'tribunais' => Tribunal::query()->select(['id', 'nome'])->orderBy('nome')->get(),
            'classes' => ClasseCNJ::query()
                ->whereIn('codigo', Processo::query()->select('classe_codigo')->whereNotNull('classe_codigo')->distinct())
                ->orderBy('descricao')
                ->get(['codigo', 'descricao'])
                ->map(fn ($c) => ['codigo' => $c->codigo, 'descricao' => $c->descricao]),
            'statusOptions' => [
                Processo::STATUS_PENDENTE_ENVIO,
                Processo::STATUS_PROCESSANDO_ENVIO,
                Processo::STATUS_PETICIONADO,
                Processo::STATUS_ARQUIVADO,
            ],
            'niveisSigilo' => Processo::niveisSigilo(),
        ]);
    }
```

Nota: `classes` usa `->map()` porque `ClasseCNJ` esconde campos no `$hidden` — o array explícito garante `codigo` e `descricao` no JSON.

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose exec -T php php vendor/bin/pest tests/Feature/ProcessoConsultaTest.php`
Expected: PASS (12 testes).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ProcessoController.php tests/Feature/ProcessoConsultaTest.php
git commit -m "feat(processos): filtros completos do index e props de opcoes"
```

---

### Task 3: Backend do `show` (payload + deferred props)

**Files:**

- Modify: `app/Http/Controllers/ProcessoController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/ProcessoConsultaTest.php`

**Interfaces:**

- Consumes: helpers da Task 1; relações do model (`partes.representantesProcessual`, `movimentos`, `documentos`, `assuntos`, `prioridades`, `tribunal`, `classe`); `ProcessoParte::modalidadePolo()`, `ProcessoParteRepresentante::tipoRepresentante()`, `Processo::niveisSigilo()`.
- Produces: rota `GET /processos/{processo}` → `processos.show`, componente `processos/show` com props:
  - `processo`: `{id, numero_processo, status, tribunal, classe, orgao_julgador, instancia_orgao_julgador, valor_causa, nivel_sigilo (label string), justica_gratuita, pedido_liminar, motivo_segredo_justica, created_at, assuntos: [{nome, assunto_codigo, principal}], prioridades: string[], partes: [{id, polo (label), nome, cpf_cnpj, endereco, representantes: [{id, nome, numero_documento_principal, inscricao, tipo (label)}]}]}`
  - `movimentos` (deferred): `[{id, codigo_nacional, complemento, data_hora, tem_documento: bool}]`
  - `documentos` (deferred): `[{id, descricao, tipo_documento, mimetype, file_size, nivel_sigilo, data_juntada, data_hora, status}]`

  Task 6 (frontend do detalhe) consome exatamente esses nomes.

- [ ] **Step 1: Escrever testes que falham**

Adicionar em `tests/Feature/ProcessoConsultaTest.php` (imports extras: `use App\Models\ProcessoDocumento;`, `use App\Models\ProcessoMovimento;`, `use App\Models\ProcessoParte;`, `use App\Models\ProcessoParteRepresentante;`):

```php
it('redireciona visitante para o login no detalhe', function () {
    $processo = novoProcesso();

    $this->get("/processos/{$processo->id}")->assertRedirect('/login');
});

it('retorna 404 para processo inexistente', function () {
    $this->actingAs(loginProcessos())
        ->get('/processos/999999999')
        ->assertNotFound();
});

it('mostra dados gerais, partes com representantes e assuntos', function () {
    $tribunal = Tribunal::factory()->create(['nome' => 'Tribunal Detalhe']);
    $processo = novoProcesso([
        'tribunal_id' => $tribunal->id,
        'nome_orgao_julgador' => 'Vara do Detalhe',
        'nivel_sigilo' => '1',
    ]);
    $processo->assuntos()->create(['nome' => 'Assunto Teste', 'assunto_codigo' => '123', 'principal' => true]);
    $processo->prioridades()->create(['descricao' => 'Idoso']);
    $parte = $processo->partes()->create(['nome' => 'Fulano de Tal', 'cpf_cnpj' => '12345678901', 'polo' => 'AT', 'municipio' => 'Macapá', 'estado' => 'AP']);
    ProcessoParteRepresentante::create([
        'processo_id' => $processo->id,
        'parte_id' => $parte->id,
        'nome' => 'Dra. Advogada',
        'tipo_representante' => 'A',
    ]);

    $this->actingAs(loginProcessos())
        ->get("/processos/{$processo->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('processos/show')
            ->where('processo.id', $processo->id)
            ->where('processo.tribunal', 'Tribunal Detalhe')
            ->where('processo.orgao_julgador', 'Vara do Detalhe')
            ->where('processo.nivel_sigilo', '1 - Segredo de Justiça')
            ->where('processo.assuntos.0.nome', 'Assunto Teste')
            ->where('processo.prioridades.0', 'Idoso')
            ->where('processo.partes.0.nome', 'Fulano de Tal')
            ->where('processo.partes.0.polo', 'Ativo')
            ->where('processo.partes.0.representantes.0.nome', 'Dra. Advogada')
            ->where('processo.partes.0.representantes.0.tipo', 'Advogado')
            ->missing('processo.payload_envio'));
});

it('adia movimentos e documentos e entrega no partial reload sem conteudo pesado', function () {
    $processo = novoProcesso();
    ProcessoMovimento::create([
        'processo_id' => $processo->id,
        'identificador_movimento' => 'MOV-1',
        'codigo_nacional' => 26,
        'complemento' => 'Distribuição',
        'data_hora' => '2026-01-05 10:00:00',
    ]);
    ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => 'DOC-1',
        'tipo_documento' => 57,
        'descricao' => 'Petição Inicial',
        'mimetype' => 'application/pdf',
        'data_hora' => '2026-01-05 10:00:00',
        'status' => 'baixado',
        'file_size' => 2048,
    ]);

    // primeiro carregamento: deferred props ausentes
    $this->actingAs(loginProcessos())
        ->get("/processos/{$processo->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('processos/show')
            ->missing('movimentos')
            ->missing('documentos'));

    // partial reload (como o Inertia faz no cliente) entrega os dados
    $this->actingAs(loginProcessos())
        ->get("/processos/{$processo->id}", [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => \Inertia\Inertia::getVersion() ?? '',
            'X-Inertia-Partial-Component' => 'processos/show',
            'X-Inertia-Partial-Data' => 'movimentos,documentos',
        ])
        ->assertOk()
        ->assertJsonPath('props.movimentos.0.complemento', 'Distribuição')
        ->assertJsonPath('props.documentos.0.descricao', 'Petição Inicial')
        ->assertJsonMissingPath('props.documentos.0.conteudo_html')
        ->assertJsonMissingPath('props.documentos.0.path');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose exec -T php php vendor/bin/pest tests/Feature/ProcessoConsultaTest.php`
Expected: FAIL — rota show não existe (404).

- [ ] **Step 3: Implementar rota + show**

Em `routes/web.php`, após a rota `processos.index`:

```php
Route::get('/processos/{processo}', [ProcessoController::class, 'show'])->name('processos.show');
```

Adicionar ao `ProcessoController` (imports extras: `use App\Models\ProcessoParte;`, `use App\Models\ProcessoParteRepresentante;`):

```php
    public function show(Processo $processo): Response
    {
        $processo->load(['partes.representantesProcessual']);

        $polos = ProcessoParte::modalidadePolo();
        $tiposRepresentante = ProcessoParteRepresentante::tipoRepresentante();
        $niveisSigilo = Processo::niveisSigilo();

        return Inertia::render('processos/show', [
            'processo' => [
                'id' => $processo->id,
                'numero_processo' => $processo->numero_processo,
                'status' => $processo->status,
                'tribunal' => $processo->tribunal?->nome,
                'classe' => $processo->classe?->descricao,
                'orgao_julgador' => $processo->nome_orgao_julgador,
                'instancia_orgao_julgador' => $processo->instancia_orgao_julgador,
                'valor_causa' => $processo->valor_causa,
                'nivel_sigilo' => $niveisSigilo[(int) $processo->nivel_sigilo] ?? $processo->nivel_sigilo,
                'justica_gratuita' => $processo->justica_gratuita,
                'pedido_liminar' => $processo->pedido_liminar,
                'motivo_segredo_justica' => $processo->motivo_segredo_justica,
                'created_at' => $processo->created_at,
                'assuntos' => $processo->assuntos->map(fn ($a) => [
                    'nome' => $a->nome,
                    'assunto_codigo' => $a->assunto_codigo,
                    'principal' => (bool) $a->principal,
                ]),
                'prioridades' => $processo->prioridades->pluck('descricao'),
                'partes' => $processo->partes->map(fn ($parte) => [
                    'id' => $parte->id,
                    'polo' => $polos[$parte->polo] ?? $parte->polo,
                    'nome' => $parte->nome,
                    'cpf_cnpj' => $parte->cpf_cnpj,
                    'endereco' => collect([
                        $parte->logradouro,
                        $parte->numero,
                        $parte->bairro,
                        $parte->municipio,
                        $parte->estado,
                        $parte->cep,
                    ])->filter()->implode(', '),
                    'representantes' => $parte->representantesProcessual->map(fn ($r) => [
                        'id' => $r->id,
                        'nome' => $r->nome,
                        'numero_documento_principal' => $r->numero_documento_principal,
                        'inscricao' => $r->inscricao,
                        'tipo' => $tiposRepresentante[$r->tipo_representante] ?? $r->tipo_representante,
                    ]),
                ]),
            ],
            // deferred: processos antigos têm centenas de movimentos/documentos;
            // o primeiro paint não espera por eles
            'movimentos' => Inertia::defer(fn () => $processo->movimentos()
                ->get(['id', 'codigo_nacional', 'complemento', 'data_hora', 'id_documento_vinculado'])
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'codigo_nacional' => $m->codigo_nacional,
                    'complemento' => $m->complemento,
                    'data_hora' => $m->data_hora,
                    'tem_documento' => filled($m->id_documento_vinculado),
                ])),
            'documentos' => Inertia::defer(fn () => $processo->documentos()
                ->get(['id', 'descricao', 'tipo_documento', 'mimetype', 'file_size', 'nivel_sigilo', 'data_juntada', 'data_hora', 'status'])
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'descricao' => $d->descricao,
                    'tipo_documento' => $d->tipo_documento,
                    'mimetype' => $d->mimetype,
                    'file_size' => $d->file_size,
                    'nivel_sigilo' => $d->nivel_sigilo,
                    'data_juntada' => $d->data_juntada,
                    'data_hora' => $d->data_hora,
                    'status' => $d->status,
                ])),
        ]);
    }
```

Nota: os `->map()` para arrays explícitos são obrigatórios — `ProcessoMovimento`/`ProcessoDocumento` têm `id` no `$hidden`, e o React precisa de `id` como key. O `get([...colunas])` explícito garante que `conteudo_html`/`path`/`url` nunca saem do banco.

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose exec -T php php vendor/bin/pest tests/Feature/ProcessoConsultaTest.php`
Expected: PASS (16 testes).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ProcessoController.php routes/web.php tests/Feature/ProcessoConsultaTest.php
git commit -m "feat(processos): show com payload explicito e deferred props de movimentos/documentos"
```

---

### Task 4: Componentes UI base (badge, tabs, popover, collapsible, pagination, format)

**Files:**

- Create: `resources/js/components/ui/badge.tsx`
- Create: `resources/js/components/ui/tabs.tsx`
- Create: `resources/js/components/ui/popover.tsx`
- Create: `resources/js/components/ui/collapsible.tsx`
- Create: `resources/js/components/pagination.tsx`
- Create: `resources/js/lib/format.ts`
- Modify: `resources/js/types/index.d.ts`

**Interfaces:**

- Consumes: `cn` de `@/lib/utils`, meta-package `radix-ui` (padrão do projeto — ver `ui/tooltip.tsx`), `@inertiajs/react` (`Link`).
- Produces (consumidos nas Tasks 5 e 6):
  - `Badge({variant, className})` — variants `default | secondary | destructive | outline`
  - `Tabs, TabsList, TabsTrigger, TabsContent`
  - `Popover, PopoverTrigger, PopoverContent`
  - `Collapsible, CollapsibleTrigger, CollapsibleContent`
  - `Pagination({paginator}: {paginator: Paginated<unknown>})`
  - `formatMoeda(v: string | number | null): string`, `formatData(iso: string | null): string`, `formatDataHora(iso: string | null): string`, `formatBytes(n: number | null): string`, `formatCpfCnpj(v: string | null): string`, `flagAtiva(v: string | null): boolean`
  - Tipos: `Paginated<T>`, `ProcessoListItem`, `ProcessoFiltros`, `ProcessoDetalhe`, `ParteItem`, `RepresentanteItem`, `MovimentoItem`, `DocumentoItem`, `ClasseOption`, `TribunalOption`

Sem testes JS no projeto — verificação por `npm run typecheck` + `npm run build`.

- [ ] **Step 1: Criar badge.tsx**

```tsx
import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

const badgeVariants = cva(
    'inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 gap-1 [&>svg]:size-3 [&>svg]:pointer-events-none transition-colors overflow-hidden',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-primary text-primary-foreground',
                secondary: 'border-transparent bg-secondary text-secondary-foreground',
                destructive: 'border-transparent bg-destructive text-white',
                outline: 'text-foreground',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

function Badge({
    className,
    variant,
    ...props
}: React.ComponentProps<'span'> & VariantProps<typeof badgeVariants>) {
    return <span data-slot="badge" className={cn(badgeVariants({ variant }), className)} {...props} />;
}

export { Badge, badgeVariants };
```

- [ ] **Step 2: Criar tabs.tsx**

```tsx
import { Tabs as TabsPrimitive } from 'radix-ui';
import * as React from 'react';

import { cn } from '@/lib/utils';

function Tabs({ className, ...props }: React.ComponentProps<typeof TabsPrimitive.Root>) {
    return <TabsPrimitive.Root data-slot="tabs" className={cn('flex flex-col gap-2', className)} {...props} />;
}

function TabsList({ className, ...props }: React.ComponentProps<typeof TabsPrimitive.List>) {
    return (
        <TabsPrimitive.List
            data-slot="tabs-list"
            className={cn(
                'bg-muted text-muted-foreground inline-flex h-9 w-fit items-center justify-center rounded-lg p-[3px]',
                className,
            )}
            {...props}
        />
    );
}

function TabsTrigger({ className, ...props }: React.ComponentProps<typeof TabsPrimitive.Trigger>) {
    return (
        <TabsPrimitive.Trigger
            data-slot="tabs-trigger"
            className={cn(
                "data-[state=active]:bg-background data-[state=active]:text-foreground focus-visible:border-ring focus-visible:ring-ring/50 text-muted-foreground inline-flex h-[calc(100%-1px)] flex-1 items-center justify-center gap-1.5 rounded-md border border-transparent px-2 py-1 text-sm font-medium whitespace-nowrap transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:outline-1 disabled:pointer-events-none disabled:opacity-50 data-[state=active]:shadow-sm [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
                className,
            )}
            {...props}
        />
    );
}

function TabsContent({ className, ...props }: React.ComponentProps<typeof TabsPrimitive.Content>) {
    return <TabsPrimitive.Content data-slot="tabs-content" className={cn('flex-1 outline-none', className)} {...props} />;
}

export { Tabs, TabsContent, TabsList, TabsTrigger };
```

- [ ] **Step 3: Criar popover.tsx**

```tsx
import { Popover as PopoverPrimitive } from 'radix-ui';
import * as React from 'react';

import { cn } from '@/lib/utils';

function Popover({ ...props }: React.ComponentProps<typeof PopoverPrimitive.Root>) {
    return <PopoverPrimitive.Root data-slot="popover" {...props} />;
}

function PopoverTrigger({ ...props }: React.ComponentProps<typeof PopoverPrimitive.Trigger>) {
    return <PopoverPrimitive.Trigger data-slot="popover-trigger" {...props} />;
}

function PopoverContent({
    className,
    align = 'center',
    sideOffset = 4,
    ...props
}: React.ComponentProps<typeof PopoverPrimitive.Content>) {
    return (
        <PopoverPrimitive.Portal>
            <PopoverPrimitive.Content
                data-slot="popover-content"
                align={align}
                sideOffset={sideOffset}
                className={cn(
                    'bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 w-72 origin-(--radix-popover-content-transform-origin) rounded-md border p-4 shadow-md outline-hidden',
                    className,
                )}
                {...props}
            />
        </PopoverPrimitive.Portal>
    );
}

export { Popover, PopoverContent, PopoverTrigger };
```

- [ ] **Step 4: Criar collapsible.tsx**

```tsx
import { Collapsible as CollapsiblePrimitive } from 'radix-ui';
import * as React from 'react';

function Collapsible({ ...props }: React.ComponentProps<typeof CollapsiblePrimitive.Root>) {
    return <CollapsiblePrimitive.Root data-slot="collapsible" {...props} />;
}

function CollapsibleTrigger({ ...props }: React.ComponentProps<typeof CollapsiblePrimitive.CollapsibleTrigger>) {
    return <CollapsiblePrimitive.CollapsibleTrigger data-slot="collapsible-trigger" {...props} />;
}

function CollapsibleContent({ ...props }: React.ComponentProps<typeof CollapsiblePrimitive.CollapsibleContent>) {
    return <CollapsiblePrimitive.CollapsibleContent data-slot="collapsible-content" {...props} />;
}

export { Collapsible, CollapsibleContent, CollapsibleTrigger };
```

- [ ] **Step 5: Criar lib/format.ts**

```ts
export function formatMoeda(valor: string | number | null): string {
    if (valor === null || valor === '' || valor === undefined) return '—';
    const numero = typeof valor === 'string' ? parseFloat(valor) : valor;
    if (Number.isNaN(numero)) return '—';
    return numero.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

export function formatData(iso: string | null): string {
    if (!iso) return '—';
    const data = new Date(iso);
    if (Number.isNaN(data.getTime())) return '—';
    return data.toLocaleDateString('pt-BR');
}

export function formatDataHora(iso: string | null): string {
    if (!iso) return '—';
    const data = new Date(iso);
    if (Number.isNaN(data.getTime())) return '—';
    return data.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
}

export function formatBytes(bytes: number | null): string {
    if (!bytes) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function formatCpfCnpj(valor: string | null): string {
    if (!valor) return '—';
    const digitos = valor.replace(/\D/g, '');
    if (digitos.length === 11) {
        return digitos.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
    }
    if (digitos.length === 14) {
        return digitos.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
    }
    return valor;
}

// justica_gratuita/pedido_liminar são varchar com semântica frouxa no banco
export function flagAtiva(valor: string | null): boolean {
    if (!valor) return false;
    return !['', '0', 'false', 'n', 'nao', 'não'].includes(valor.trim().toLowerCase());
}
```

- [ ] **Step 6: Adicionar tipos em types/index.d.ts**

Acrescentar ao final de `resources/js/types/index.d.ts`:

```ts
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

export interface ProcessoListItem {
    id: number;
    numero_processo: string | null;
    tribunal: string | null;
    classe: string | null;
    status: string;
    valor_causa: string | null;
    created_at: string;
}

export interface ProcessoFiltros {
    busca?: string;
    tribunal_id?: number | string;
    status?: string;
    data_inicio?: string;
    data_fim?: string;
    classe_codigo?: string;
    orgao_julgador?: string;
    nivel_sigilo?: number | string;
}

export interface TribunalOption {
    id: number;
    nome: string;
}

export interface ClasseOption {
    codigo: string;
    descricao: string;
}

export interface RepresentanteItem {
    id: number;
    nome: string | null;
    numero_documento_principal: string | null;
    inscricao: string | null;
    tipo: string | null;
}

export interface ParteItem {
    id: number;
    polo: string | null;
    nome: string | null;
    cpf_cnpj: string | null;
    endereco: string;
    representantes: RepresentanteItem[];
}

export interface ProcessoDetalhe {
    id: number;
    numero_processo: string | null;
    status: string;
    tribunal: string | null;
    classe: string | null;
    orgao_julgador: string | null;
    instancia_orgao_julgador: string | null;
    valor_causa: string | null;
    nivel_sigilo: string | null;
    justica_gratuita: string | null;
    pedido_liminar: string | null;
    motivo_segredo_justica: string | null;
    created_at: string;
    assuntos: { nome: string | null; assunto_codigo: string | null; principal: boolean }[];
    prioridades: string[];
    partes: ParteItem[];
}

export interface MovimentoItem {
    id: number;
    codigo_nacional: number | string | null;
    complemento: string | null;
    data_hora: string | null;
    tem_documento: boolean;
}

export interface DocumentoItem {
    id: number;
    descricao: string | null;
    tipo_documento: number | string | null;
    mimetype: string | null;
    file_size: number | null;
    nivel_sigilo: string | null;
    data_juntada: string | null;
    data_hora: string | null;
    status: string | null;
}
```

- [ ] **Step 7: Criar components/pagination.tsx**

```tsx
import { Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { type Paginated } from '@/types';

export function Pagination({ paginator }: { paginator: Paginated<unknown> }) {
    if (paginator.total === 0) return null;

    return (
        <div className="flex flex-col items-center justify-between gap-2 sm:flex-row">
            <p className="text-sm text-muted-foreground">
                {paginator.from ?? 0}–{paginator.to ?? 0} de {paginator.total}
            </p>
            <div className="flex flex-wrap gap-1">
                {paginator.links.map((link, i) => (
                    <Button
                        key={i}
                        variant={link.active ? 'default' : 'outline'}
                        size="sm"
                        disabled={!link.url}
                        asChild={Boolean(link.url)}
                    >
                        {link.url ? (
                            <Link href={link.url} preserveScroll preserveState only={['processos']}>
                                <span dangerouslySetInnerHTML={{ __html: link.label }} />
                            </Link>
                        ) : (
                            <span dangerouslySetInnerHTML={{ __html: link.label }} />
                        )}
                    </Button>
                ))}
            </div>
        </div>
    );
}
```

Nota: `dangerouslySetInnerHTML` porque os labels do paginator Laravel vêm com entidades HTML (`&laquo; Previous`). Conteúdo é gerado pelo framework, não por usuário.

- [ ] **Step 8: Verificar typecheck**

Run: `npm run typecheck`
Expected: sem erros (`tsc --noEmit` limpo). Se `collapsible.tsx` acusar `React` não importado, adicionar `import * as React from 'react';` no topo.

- [ ] **Step 9: Commit**

```bash
git add resources/js/components/ui/badge.tsx resources/js/components/ui/tabs.tsx resources/js/components/ui/popover.tsx resources/js/components/ui/collapsible.tsx resources/js/components/pagination.tsx resources/js/lib/format.ts resources/js/types/index.d.ts
git commit -m "feat(frontend): componentes ui base e tipos para telas de processos"
```

---

### Task 5: Página de listagem + item na sidebar

**Files:**

- Create: `resources/js/pages/processos/index.tsx`
- Modify: `resources/js/components/app-sidebar.tsx`

**Interfaces:**

- Consumes: props do controller (Task 2: `processos`, `filtros`, `tribunais`, `classes`, `statusOptions`, `niveisSigilo`), componentes da Task 4 (`Badge`, `Popover*`, `Collapsible*`, `Pagination`, `formatMoeda`, `formatData`), tipos da Task 4.
- Produces: página `processos/index` funcional; item "Processos" na sidebar.

- [ ] **Step 1: Adicionar item na sidebar**

Em `resources/js/components/app-sidebar.tsx`: adicionar `Scale` ao import do lucide e inserir em `mainNavItems`, entre Dashboard e Tribunais:

```tsx
    {
        title: 'Processos',
        href: '/processos',
        icon: Scale,
    },
```

- [ ] **Step 2: Criar a página de listagem**

`resources/js/pages/processos/index.tsx`:

```tsx
import { Head, Link, router } from '@inertiajs/react';
import { Check, ChevronDown, ChevronsUpDown, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { formatData, formatMoeda } from '@/lib/format';
import {
    type BreadcrumbItem,
    type ClasseOption,
    type Paginated,
    type ProcessoFiltros,
    type ProcessoListItem,
    type TribunalOption,
} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Processos', href: '/processos' }];

const statusBadgeClass: Record<string, string> = {
    'Peticionado': 'border-transparent bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    'Processando envio': 'border-transparent bg-yellow-100 text-yellow-800 dark:bg-yellow-950 dark:text-yellow-200',
    'Pendente de envio': 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    'Arquivado': 'border-transparent bg-muted text-muted-foreground',
};

interface Props {
    processos: Paginated<ProcessoListItem>;
    filtros: ProcessoFiltros;
    tribunais: TribunalOption[];
    classes: ClasseOption[];
    statusOptions: string[];
    niveisSigilo: Record<string, string>;
    errors: Record<string, string>;
}

function aplicarFiltros(filtros: ProcessoFiltros) {
    const params = Object.fromEntries(
        Object.entries(filtros).filter(([, v]) => v !== undefined && v !== null && v !== ''),
    );
    router.get('/processos', params, {
        preserveState: true,
        preserveScroll: true,
        only: ['processos', 'filtros', 'errors'],
    });
}

function ClasseCombobox({
    classes,
    value,
    onChange,
}: {
    classes: ClasseOption[];
    value: string | undefined;
    onChange: (codigo: string | undefined) => void;
}) {
    const [open, setOpen] = useState(false);
    const [termo, setTermo] = useState('');

    const selecionada = classes.find((c) => c.codigo === value);
    const filtradas = classes
        .filter((c) => c.descricao.toLowerCase().includes(termo.toLowerCase()))
        .slice(0, 50);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button variant="outline" role="combobox" aria-expanded={open} className="w-full justify-between font-normal">
                    <span className="truncate">{selecionada ? selecionada.descricao : 'Todas as classes'}</span>
                    <ChevronsUpDown className="ml-2 size-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-(--radix-popover-trigger-width) p-2" align="start">
                <Input
                    placeholder="Buscar classe..."
                    value={termo}
                    onChange={(e) => setTermo(e.target.value)}
                    className="mb-2"
                    autoFocus
                />
                <div className="max-h-60 overflow-y-auto">
                    {filtradas.map((classe) => (
                        <button
                            key={classe.codigo}
                            type="button"
                            className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent"
                            onClick={() => {
                                onChange(classe.codigo === value ? undefined : classe.codigo);
                                setOpen(false);
                            }}
                        >
                            <Check className={cn('size-4 shrink-0', classe.codigo === value ? 'opacity-100' : 'opacity-0')} />
                            <span className="truncate">{classe.descricao}</span>
                        </button>
                    ))}
                    {filtradas.length === 0 && (
                        <p className="px-2 py-1.5 text-sm text-muted-foreground">Nenhuma classe encontrada.</p>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}

export default function ProcessosIndex({
    processos,
    filtros,
    tribunais,
    classes,
    statusOptions,
    niveisSigilo,
    errors,
}: Props) {
    const [busca, setBusca] = useState(filtros.busca ?? '');
    const primeiraRenderizacao = useRef(true);
    const [maisFiltros, setMaisFiltros] = useState(
        Boolean(filtros.data_inicio || filtros.data_fim || filtros.orgao_julgador || filtros.nivel_sigilo !== undefined && filtros.nivel_sigilo !== ''),
    );

    const temFiltroAtivo = Object.values(filtros).some((v) => v !== undefined && v !== null && v !== '');

    const mudarFiltro = useCallback(
        (mudanca: Partial<ProcessoFiltros>) => {
            aplicarFiltros({ ...filtros, busca: busca || undefined, ...mudanca });
        },
        [filtros, busca],
    );

    // debounce de 400ms na busca por número
    useEffect(() => {
        if (primeiraRenderizacao.current) {
            primeiraRenderizacao.current = false;
            return;
        }
        const timer = setTimeout(() => {
            aplicarFiltros({ ...filtros, busca: busca || undefined });
        }, 400);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [busca]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Processos" />

            <div className="flex flex-col gap-4 p-4">
                <h1 className="text-2xl font-bold">Processos</h1>

                <div className="flex flex-col gap-3 rounded-xl border p-4">
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="busca">Número do processo</Label>
                            <Input
                                id="busca"
                                placeholder="Buscar por número..."
                                value={busca}
                                onChange={(e) => setBusca(e.target.value)}
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Tribunal</Label>
                            <Select
                                value={String(filtros.tribunal_id ?? 'todos')}
                                onValueChange={(v) => mudarFiltro({ tribunal_id: v === 'todos' ? undefined : v })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Todos" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="todos">Todos</SelectItem>
                                    {tribunais.map((t) => (
                                        <SelectItem key={t.id} value={String(t.id)}>
                                            {t.nome}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Status</Label>
                            <Select
                                value={filtros.status ?? 'todos'}
                                onValueChange={(v) => mudarFiltro({ status: v === 'todos' ? undefined : v })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Todos" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="todos">Todos</SelectItem>
                                    {statusOptions.map((s) => (
                                        <SelectItem key={s} value={s}>
                                            {s}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Classe CNJ</Label>
                            <ClasseCombobox
                                classes={classes}
                                value={filtros.classe_codigo}
                                onChange={(codigo) => mudarFiltro({ classe_codigo: codigo })}
                            />
                        </div>
                    </div>

                    <Collapsible open={maisFiltros} onOpenChange={setMaisFiltros}>
                        <div className="flex items-center justify-between">
                            <CollapsibleTrigger asChild>
                                <Button variant="ghost" size="sm">
                                    <ChevronDown className={cn('size-4 transition-transform', maisFiltros && 'rotate-180')} />
                                    Mais filtros
                                </Button>
                            </CollapsibleTrigger>
                            {temFiltroAtivo && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        setBusca('');
                                        aplicarFiltros({});
                                    }}
                                >
                                    <X className="size-4" /> Limpar filtros
                                </Button>
                            )}
                        </div>
                        <CollapsibleContent>
                            <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="data_inicio">Criado a partir de</Label>
                                    <Input
                                        id="data_inicio"
                                        type="date"
                                        value={filtros.data_inicio ?? ''}
                                        onChange={(e) => mudarFiltro({ data_inicio: e.target.value || undefined })}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="data_fim">Criado até</Label>
                                    <Input
                                        id="data_fim"
                                        type="date"
                                        value={filtros.data_fim ?? ''}
                                        onChange={(e) => mudarFiltro({ data_fim: e.target.value || undefined })}
                                    />
                                    {errors.data_fim && <p className="text-sm text-destructive">{errors.data_fim}</p>}
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="orgao_julgador">Órgão julgador</Label>
                                    <Input
                                        id="orgao_julgador"
                                        placeholder="Nome do órgão..."
                                        defaultValue={filtros.orgao_julgador ?? ''}
                                        onBlur={(e) => mudarFiltro({ orgao_julgador: e.target.value || undefined })}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                mudarFiltro({ orgao_julgador: e.currentTarget.value || undefined });
                                            }
                                        }}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label>Nível de sigilo</Label>
                                    <Select
                                        value={String(filtros.nivel_sigilo ?? 'todos')}
                                        onValueChange={(v) => mudarFiltro({ nivel_sigilo: v === 'todos' ? undefined : v })}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Todos" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="todos">Todos</SelectItem>
                                            {Object.entries(niveisSigilo).map(([valor, label]) => (
                                                <SelectItem key={valor} value={valor}>
                                                    {label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                </div>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Número do processo</TableHead>
                                <TableHead>Tribunal</TableHead>
                                <TableHead>Classe</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Valor da causa</TableHead>
                                <TableHead>Criado em</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {processos.data.map((processo) => (
                                <TableRow
                                    key={processo.id}
                                    className="cursor-pointer"
                                    onClick={() => router.visit(`/processos/${processo.id}`)}
                                >
                                    <TableCell className="font-medium">
                                        <Link
                                            href={`/processos/${processo.id}`}
                                            className="hover:underline"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            {processo.numero_processo ?? '—'}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">{processo.tribunal ?? '—'}</TableCell>
                                    <TableCell className="text-muted-foreground">{processo.classe ?? '—'}</TableCell>
                                    <TableCell>
                                        <Badge variant="outline" className={statusBadgeClass[processo.status] ?? ''}>
                                            {processo.status}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>{formatMoeda(processo.valor_causa)}</TableCell>
                                    <TableCell className="text-muted-foreground">{formatData(processo.created_at)}</TableCell>
                                </TableRow>
                            ))}
                            {processos.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={6} className="text-center text-muted-foreground">
                                        Nenhum processo encontrado.
                                        {temFiltroAtivo && ' Tente limpar os filtros.'}
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                <Pagination paginator={processos} />
            </div>
        </AppLayout>
    );
}
```

- [ ] **Step 3: Verificar typecheck e build**

Run: `npm run typecheck && npm run build`
Expected: ambos sem erro.

- [ ] **Step 4: Verificação manual no navegador**

Subir ambiente se necessário (`docker compose up -d --no-deps php`; se redis parado: `docker start redis`; `npm run dev` em background). Acessar `http://localhost:8006/login` (admin@admin.com / 12345678), navegar para Processos via sidebar. Verificar: tabela popula, busca com debounce filtra, selects filtram, "Mais filtros" expande, paginação navega, URL reflete filtros, limpar filtros zera, linha navega pro detalhe (404/erro esperado — página show é a Task 6).

Cuidado com Playwright MCP neste ambiente: nunca usar `page.setViewportSize` em `run_code` nem clicar em option de Radix Select que não abriu (corrompe o input pipeline; recovery: `browser_close` + `browser_navigate`). Redimensionar só com `browser_resize`.

- [ ] **Step 5: Rodar testes de regressão**

Run: `docker compose exec -T php php vendor/bin/pest tests/Feature/ProcessoConsultaTest.php tests/Feature/TribunalCrudTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/processos/index.tsx resources/js/components/app-sidebar.tsx
git commit -m "feat(processos): tela de listagem com filtros, paginacao e item na sidebar"
```

---

### Task 6: Página de detalhe

**Files:**

- Create: `resources/js/pages/processos/show.tsx`

**Interfaces:**

- Consumes: props do `show` (Task 3: `processo: ProcessoDetalhe`, `movimentos?: MovimentoItem[]`, `documentos?: DocumentoItem[]` — deferred, chegam undefined no primeiro render), componentes das Task 4 (`Badge`, `Tabs*`, `Skeleton`, formatadores), `Deferred` do `@inertiajs/react`.
- Produces: página `processos/show` completa.

- [ ] **Step 1: Criar a página de detalhe**

`resources/js/pages/processos/show.tsx`:

```tsx
import { Deferred, Head } from '@inertiajs/react';
import { Copy, FileText } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { flagAtiva, formatBytes, formatCpfCnpj, formatData, formatDataHora, formatMoeda } from '@/lib/format';
import {
    type BreadcrumbItem,
    type DocumentoItem,
    type MovimentoItem,
    type ParteItem,
    type ProcessoDetalhe,
} from '@/types';

const statusBadgeClass: Record<string, string> = {
    'Peticionado': 'border-transparent bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    'Processando envio': 'border-transparent bg-yellow-100 text-yellow-800 dark:bg-yellow-950 dark:text-yellow-200',
    'Pendente de envio': 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    'Arquivado': 'border-transparent bg-muted text-muted-foreground',
};

interface Props {
    processo: ProcessoDetalhe;
    movimentos?: MovimentoItem[];
    documentos?: DocumentoItem[];
}

function CampoInfo({ rotulo, valor }: { rotulo: string; valor: string | null | undefined }) {
    return (
        <div>
            <dt className="text-sm text-muted-foreground">{rotulo}</dt>
            <dd className="text-sm font-medium">{valor || '—'}</dd>
        </div>
    );
}

function ListaSkeleton() {
    return (
        <div className="flex flex-col gap-2 p-4">
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
        </div>
    );
}

function GrupoPartes({ titulo, partes }: { titulo: string; partes: ParteItem[] }) {
    if (partes.length === 0) return null;

    return (
        <div className="flex flex-col gap-2">
            <h3 className="text-sm font-semibold text-muted-foreground uppercase">{titulo}</h3>
            {partes.map((parte) => (
                <Card key={parte.id}>
                    <CardContent className="flex flex-col gap-2 pt-4">
                        <div>
                            <p className="font-medium">{parte.nome ?? '—'}</p>
                            <p className="text-sm text-muted-foreground">
                                {formatCpfCnpj(parte.cpf_cnpj)}
                                {parte.endereco && ` · ${parte.endereco}`}
                            </p>
                        </div>
                        {parte.representantes.length > 0 && (
                            <div className="border-l-2 pl-3">
                                <p className="text-xs font-semibold text-muted-foreground uppercase">Representantes</p>
                                {parte.representantes.map((rep) => (
                                    <p key={rep.id} className="text-sm">
                                        {rep.nome ?? '—'}
                                        <span className="text-muted-foreground">
                                            {rep.tipo && ` · ${rep.tipo}`}
                                            {rep.inscricao && ` · ${rep.inscricao}`}
                                        </span>
                                    </p>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

export default function ProcessoShow({ processo, movimentos, documentos }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Processos', href: '/processos' },
        { title: processo.numero_processo ?? String(processo.id), href: `/processos/${processo.id}` },
    ];

    const partesAtivas = processo.partes.filter((p) => p.polo === 'Ativo');
    const partesPassivas = processo.partes.filter((p) => p.polo === 'Passivo');
    const demaisPartes = processo.partes.filter((p) => p.polo !== 'Ativo' && p.polo !== 'Passivo');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Processo ${processo.numero_processo ?? processo.id}`} />

            <div className="flex flex-col gap-4 p-4">
                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-center gap-2">
                            <CardTitle className="text-xl">{processo.numero_processo ?? '—'}</CardTitle>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Copiar número do processo"
                                onClick={() => navigator.clipboard.writeText(processo.numero_processo ?? '')}
                            >
                                <Copy className="size-4" />
                            </Button>
                            <Badge variant="outline" className={statusBadgeClass[processo.status] ?? ''}>
                                {processo.status}
                            </Badge>
                            {flagAtiva(processo.justica_gratuita) && <Badge variant="secondary">Justiça gratuita</Badge>}
                            {flagAtiva(processo.pedido_liminar) && <Badge variant="secondary">Pedido liminar</Badge>}
                        </div>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <CampoInfo rotulo="Tribunal" valor={processo.tribunal} />
                            <CampoInfo rotulo="Classe CNJ" valor={processo.classe} />
                            <CampoInfo
                                rotulo="Órgão julgador"
                                valor={
                                    processo.orgao_julgador
                                        ? `${processo.orgao_julgador}${processo.instancia_orgao_julgador ? ` (${processo.instancia_orgao_julgador})` : ''}`
                                        : null
                                }
                            />
                            <CampoInfo rotulo="Valor da causa" valor={formatMoeda(processo.valor_causa)} />
                            <CampoInfo rotulo="Nível de sigilo" valor={processo.nivel_sigilo} />
                            <CampoInfo rotulo="Criado em" valor={formatData(processo.created_at)} />
                            {processo.motivo_segredo_justica && (
                                <CampoInfo rotulo="Motivo do segredo de justiça" valor={processo.motivo_segredo_justica} />
                            )}
                        </dl>
                        {(processo.assuntos.length > 0 || processo.prioridades.length > 0) && (
                            <div className="mt-4 flex flex-wrap gap-1.5">
                                {processo.assuntos.map((assunto, i) => (
                                    <Badge key={i} variant={assunto.principal ? 'default' : 'secondary'}>
                                        {assunto.nome ?? assunto.assunto_codigo}
                                    </Badge>
                                ))}
                                {processo.prioridades.map((prioridade, i) => (
                                    <Badge key={`p-${i}`} variant="outline">
                                        {prioridade}
                                    </Badge>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Tabs defaultValue="partes">
                    <TabsList>
                        <TabsTrigger value="partes">Partes ({processo.partes.length})</TabsTrigger>
                        <TabsTrigger value="movimentos">Movimentos</TabsTrigger>
                        <TabsTrigger value="documentos">Documentos</TabsTrigger>
                    </TabsList>

                    <TabsContent value="partes" className="flex flex-col gap-4">
                        {processo.partes.length === 0 && (
                            <p className="p-4 text-center text-muted-foreground">Nenhuma parte cadastrada.</p>
                        )}
                        <GrupoPartes titulo="Polo ativo" partes={partesAtivas} />
                        <GrupoPartes titulo="Polo passivo" partes={partesPassivas} />
                        <GrupoPartes titulo="Outras partes" partes={demaisPartes} />
                    </TabsContent>

                    <TabsContent value="movimentos">
                        <Deferred data="movimentos" fallback={<ListaSkeleton />}>
                            <div className="rounded-xl border">
                                {(movimentos ?? []).length === 0 ? (
                                    <p className="p-4 text-center text-muted-foreground">Nenhum movimento registrado.</p>
                                ) : (
                                    <ul className="divide-y">
                                        {(movimentos ?? []).map((mov) => (
                                            <li key={mov.id} className="flex flex-col gap-1 p-3">
                                                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                                    <span>{formatDataHora(mov.data_hora)}</span>
                                                    {mov.codigo_nacional != null && <span>· Código {mov.codigo_nacional}</span>}
                                                    {mov.tem_documento && (
                                                        <span className="flex items-center gap-1">
                                                            · <FileText className="size-3.5" /> documento vinculado
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="text-sm">{mov.complemento ?? '—'}</p>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </Deferred>
                    </TabsContent>

                    <TabsContent value="documentos">
                        <Deferred data="documentos" fallback={<ListaSkeleton />}>
                            <div className="rounded-xl border">
                                {(documentos ?? []).length === 0 ? (
                                    <p className="p-4 text-center text-muted-foreground">Nenhum documento registrado.</p>
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Descrição</TableHead>
                                                <TableHead>Tipo</TableHead>
                                                <TableHead>Formato</TableHead>
                                                <TableHead>Tamanho</TableHead>
                                                <TableHead>Sigilo</TableHead>
                                                <TableHead>Juntada</TableHead>
                                                <TableHead>Status</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {(documentos ?? []).map((doc) => (
                                                <TableRow key={doc.id}>
                                                    <TableCell className="font-medium">{doc.descricao ?? '—'}</TableCell>
                                                    <TableCell className="text-muted-foreground">{doc.tipo_documento ?? '—'}</TableCell>
                                                    <TableCell className="text-muted-foreground">{doc.mimetype ?? '—'}</TableCell>
                                                    <TableCell>{formatBytes(doc.file_size)}</TableCell>
                                                    <TableCell className="text-muted-foreground">{doc.nivel_sigilo ?? '—'}</TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {formatDataHora(doc.data_juntada ?? doc.data_hora)}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">{doc.status ?? '—'}</TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                )}
                            </div>
                        </Deferred>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
```

Nota: agrupamento de partes compara com os **labels** ("Ativo"/"Passivo") porque o backend (Task 3) já traduz o código do polo via `modalidadePolo()`.

- [ ] **Step 2: Verificar typecheck e build**

Run: `npm run typecheck && npm run build`
Expected: ambos sem erro.

- [ ] **Step 3: Verificação manual no navegador**

Com o ambiente de pé, abrir um processo real da listagem em `http://localhost:8006/processos`. Verificar: cabeçalho popula, copiar número funciona, abas trocam, movimentos/documentos mostram skeleton e depois populam (deferred), estados vazios aparecem num processo sem partes, breadcrumb volta pra listagem.

- [ ] **Step 4: Commit**

```bash
git add resources/js/pages/processos/show.tsx
git commit -m "feat(processos): tela de detalhe com cabecalho e abas de partes, movimentos e documentos"
```

---

### Task 7: Verificação final

**Files:** nenhum novo — regressão e ajustes finais.

**Interfaces:**

- Consumes: tudo das tasks anteriores.
- Produces: branch verificada, pronta pra revisão.

- [ ] **Step 1: Suíte completa**

Run: `docker compose exec -T php php vendor/bin/pest`
Expected: nenhuma falha NOVA. Baseline conhecido: 8 falhas pré-existentes em ExportacaoProcessoServiceTest, DownloadProcessoControllerTest e ExportacaoPipelineTest.

- [ ] **Step 2: Typecheck + build de produção**

Run: `npm run typecheck && npm run build`
Expected: sem erros.

- [ ] **Step 3: Commit final (se houve ajustes)**

```bash
git status
# se houver ajustes pendentes:
git add -A && git commit -m "chore(processos): ajustes finais de verificacao"
```
