# CRUD de Tribunais — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tela web para listar, cadastrar, editar e ativar/desativar tribunais (config de integração MNI), a primeira CRUD do app.

**Architecture:** Controller web resource + FormRequest + 3 páginas Inertia (index com Table/Switch, create/edit com `<TribunalForm>` compartilhado). Model corrigido para refletir o schema real da conexão `sim`. Testes Pest com `DatabaseTransactions` na conexão `sim` (rollback — não suja o banco local).

**Tech Stack:** Laravel 11 (conexão `sim`/Postgres), Inertia v2, React 19, shadcn (table/switch/select novos), Pest 3.

**Spec:** `docs/superpowers/specs/2026-07-10-tribunais-crud-design.md`

## Global Constraints

- PHP/pest SÓ no container: `docker compose exec php php ...`. Wrapper `./php` quebrado — não usar. Container: `docker compose up -d --no-deps php` (redis externo: `docker start redis` se parado). npm no HOST. App dev: `http://localhost:8006`.
- Baseline da suíte: **8 failed, 57 passed** (8 pré-existentes do domínio exportação — não tocar). Após este plano: **8 failed, 68 passed**.
- Tabela `tribunais` fica na conexão **`sim`** (Postgres `sim_producao` local, 8 registros reais). Testes de escrita DEVEM usar rollback (`DatabaseTransactions` com `$connectionsToTransact = ['sim']`) — nunca sujar/apagar os registros existentes.
- Schema REAL manda (introspecção 2026-07-10): `tipo` nullable (6/8 registros têm NULL); NOT NULL: `nome`, `login`, `password`, `url_webservice_mni`, `url_webservice_mni_complementar`, `enviar_dados_criminais`, `usar_credencial_tribunal`; códigos e `usar_codigo_documento_padrao` são **varchar**; coluna `uuid` NÃO existe neste banco.
- `password` write-only: nunca enviada ao frontend; vazia no update = mantém a atual.
- `Api\TribunalController` e `routes/api.php` intocados.
- Imports radix via meta-package `radix-ui`; kit clone em `KIT=/tmp/claude-1000/-home-bruno-projetos-ms-mni/f7b9d1dd-a1f6-4547-a5a4-962e6298d970/scratchpad/react-starter-kit` (re-clone: `git clone --depth 1 https://github.com/laravel/react-starter-kit.git "$KIT"`). Zero deps npm/composer novas.
- Working tree tem trabalho paralelo do usuário (`database/seeders/UserSeeder.php` modificado, `database/migrations/2026_07_09_000000_seed_admin_user.php` untracked): NÃO tocar/commitar/reverter. Commits sempre com paths explícitos.
- Branch de trabalho: criar `feat/tribunais-crud` a partir de `feat/starter-kit-dashboard`.

---

### Task 1: Backend — rotas, controller, request, model fix (TDD)

**Files:**

- Test: `tests/Feature/TribunalCrudTest.php` (novo)
- Create: `tests/SimDatabaseTestCase.php`
- Create: `database/factories/TribunalFactory.php`
- Create: `app/Http/Requests/TribunalRequest.php`
- Create: `app/Http/Controllers/TribunalController.php`
- Modify: `app/Models/Tribunal.php` (fillable + guard do uuid no boot)
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` (flash)
- Modify: `routes/web.php`

**Interfaces:**

- Consumes: `config/inertia.php` (testing page_paths — `assertInertia->component()` checa existência do arquivo; ver Step 6, stubs).
- Produces: rotas nomeadas `tribunais.index|create|store|edit|update|toggle`; páginas Inertia `tribunais/index` (prop `tribunais: {id, nome, tipo, versao_mni, ativo}[]`), `tribunais/create` (prop `tipos: string[]`), `tribunais/edit` (props `tipos`, `tribunal` sem password); shared prop `flash.success: string|null`. Task 2 consome exatamente esses nomes/shapes.

- [ ] **Step 1: Branch**

```bash
git checkout -b feat/tribunais-crud
```

- [ ] **Step 2: Criar `tests/SimDatabaseTestCase.php`** (base para testes que escrevem na conexão sim)

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;

abstract class SimDatabaseTestCase extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sim'];
}
```

- [ ] **Step 3: Criar `database/factories/TribunalFactory.php`**

```php
<?php

namespace Database\Factories;

use App\Models\Tribunal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tribunal>
 */
class TribunalFactory extends Factory
{
    protected $model = Tribunal::class;

    public function definition(): array
    {
        return [
            'nome' => 'Tribunal ' . fake()->unique()->company(),
            'tipo' => null,
            'login' => fake()->userName(),
            'password' => fake()->password(12),
            'url_webservice_mni' => fake()->url(),
            'url_webservice_mni_complementar' => fake()->url(),
            'ativo' => true,
            'enviar_dados_criminais' => false,
            'usar_credencial_tribunal' => false,
            'versao_mni' => '2.2.2',
        ];
    }
}
```

- [ ] **Step 4: Escrever teste que falha — `tests/Feature/TribunalCrudTest.php`**

```php
<?php

use App\Models\Tribunal;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\SimDatabaseTestCase;

uses(SimDatabaseTestCase::class);

function tribunalPayload(array $overrides = []): array
{
    return array_merge([
        'nome' => 'Tribunal de Teste E2E',
        'tipo' => Tribunal::TIPO_STJ,
        'login' => 'usuario.mni',
        'password' => 'senha-secreta',
        'url_webservice_mni' => 'https://tribunal.test/mni',
        'url_webservice_mni_complementar' => 'https://tribunal.test/mni-complementar',
        'ativo' => true,
        'enviar_dados_criminais' => false,
        'usar_credencial_tribunal' => false,
    ], $overrides);
}

function autenticado(): User
{
    return User::factory()->make(['id' => 1]);
}

it('redireciona visitante para o login', function () {
    $this->get('/tribunais')->assertRedirect('/login');
});

it('lista tribunais no componente tribunais/index', function () {
    $tribunal = Tribunal::factory()->create(['nome' => 'AAA Tribunal Listado']);

    $this->actingAs(autenticado())
        ->get('/tribunais')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tribunais/index')
            ->has('tribunais')
            ->where('tribunais.0.nome', 'AAA Tribunal Listado'));
});

it('renderiza o formulário de criação com os tipos', function () {
    $this->actingAs(autenticado())
        ->get('/tribunais/criar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tribunais/create')
            ->has('tipos', 5));
});

it('cria tribunal e redireciona com flash', function () {
    $this->actingAs(autenticado())
        ->post('/tribunais', tribunalPayload())
        ->assertRedirect(route('tribunais.index'))
        ->assertSessionHas('success');

    expect(Tribunal::where('nome', 'Tribunal de Teste E2E')->exists())->toBeTrue();
});

it('valida campos obrigatórios no store', function () {
    $this->actingAs(autenticado())
        ->post('/tribunais', [])
        ->assertInvalid(['nome', 'login', 'password', 'url_webservice_mni', 'url_webservice_mni_complementar']);
});

it('rejeita tipo fora da lista', function () {
    $this->actingAs(autenticado())
        ->post('/tribunais', tribunalPayload(['tipo' => 'Tribunal Inventado']))
        ->assertInvalid(['tipo']);
});

it('rejeita URL inválida', function () {
    $this->actingAs(autenticado())
        ->post('/tribunais', tribunalPayload(['url_webservice_mni' => 'nao-e-url']))
        ->assertInvalid(['url_webservice_mni']);
});

it('renderiza o formulário de edição sem a password', function () {
    $tribunal = Tribunal::factory()->create();

    $this->actingAs(autenticado())
        ->get("/tribunais/{$tribunal->id}/editar")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tribunais/edit')
            ->where('tribunal.id', $tribunal->id)
            ->missing('tribunal.password'));
});

it('atualiza tribunal', function () {
    $tribunal = Tribunal::factory()->create();

    $this->actingAs(autenticado())
        ->put("/tribunais/{$tribunal->id}", tribunalPayload(['nome' => 'Nome Atualizado']))
        ->assertRedirect(route('tribunais.index'));

    expect($tribunal->fresh()->nome)->toBe('Nome Atualizado');
});

it('mantém a password quando enviada em branco no update', function () {
    $tribunal = Tribunal::factory()->create(['password' => 'senha-original']);

    $this->actingAs(autenticado())
        ->put("/tribunais/{$tribunal->id}", tribunalPayload(['password' => '']))
        ->assertRedirect(route('tribunais.index'));

    expect($tribunal->fresh()->password)->toBe('senha-original');
});

it('inverte o ativo no toggle', function () {
    $tribunal = Tribunal::factory()->create(['ativo' => true]);

    $this->actingAs(autenticado())
        ->from('/tribunais')
        ->patch("/tribunais/{$tribunal->id}/ativo")
        ->assertRedirect('/tribunais');

    expect($tribunal->fresh()->ativo)->toBeFalse();
});
```

- [ ] **Step 5: Rodar e ver falhar**

```bash
docker compose exec php php vendor/bin/pest tests/Feature/TribunalCrudTest.php
```

Expected: FAIL — **11 failed**. Sem as rotas, todo endpoint devolve 404: falham os asserts de status/redirect/Inertia, inclusive o teste guest (404 ≠ redirect para `/login`).

- [ ] **Step 6: Criar stubs das páginas** (o `assertInertia->component()` checa existência do arquivo em `resources/js/pages`; Task 2 substitui pelo conteúdo real)

```bash
mkdir -p resources/js/pages/tribunais
for p in index create edit; do
  cat > resources/js/pages/tribunais/$p.tsx << 'EOF'
export default function Stub() {
    return null;
}
EOF
done
```

- [ ] **Step 7: Corrigir o model — `app/Models/Tribunal.php`**

Trocar o array `$fillable` inteiro por (remove `codigo_tribunal` e `segmento_justica` — colunas inexistentes; adiciona as 3 colunas reais que faltavam):

```php
    protected $fillable = [
        'nome',
        'login',
        'password',
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
        'usar_credencial_tribunal',
        'versao_mni',
    ];
```

E trocar o `boot()` por (guard: a coluna `uuid` não existe no banco local — sem o guard, todo INSERT falharia; cache estático evita re-consultar o schema a cada create):

```php
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            static $temUuid = null;
            $temUuid ??= $model->getConnection()
                ->getSchemaBuilder()
                ->hasColumn($model->getTable(), 'uuid');

            if ($temUuid) {
                $model->uuid = Str::uuid();
            }
        });
    }
```

- [ ] **Step 8: Criar `app/Http/Requests/TribunalRequest.php`**

```php
<?php

namespace App\Http\Requests;

use App\Models\Tribunal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TribunalRequest extends FormRequest
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
        $criando = $this->isMethod('POST');

        return [
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['nullable', 'string', Rule::in(Tribunal::getTipos())],
            'login' => ['required', 'string', 'max:255'],
            'password' => [$criando ? 'required' : 'nullable', 'string'],
            'url_webservice_mni' => ['required', 'url', 'max:2048'],
            'url_webservice_mni_complementar' => ['required', 'url', 'max:2048'],
            'url_webservice_mni_consultar_processo' => ['nullable', 'url', 'max:2048'],
            'url_consulta_pje' => ['nullable', 'url', 'max:2048'],
            'url_webservice_mni_criminal' => ['nullable', 'url', 'max:2048'],
            'url_recuperar_senha_tribunal' => ['nullable', 'url', 'max:2048'],
            'codigo_peticao_inicial' => ['nullable', 'string', 'max:255'],
            'codigo_peticao_avulsa' => ['nullable', 'string', 'max:255'],
            'codigo_certidao_inicio_fim' => ['nullable', 'string', 'max:255'],
            'codigo_seeu' => ['nullable', 'string', 'max:255'],
            'usar_codigo_documento_padrao' => ['nullable', 'string', 'max:255'],
            'versao_mni' => ['nullable', 'string', 'max:50'],
            'ativo' => ['boolean'],
            'enviar_dados_criminais' => ['boolean'],
            'usar_credencial_tribunal' => ['boolean'],
        ];
    }
}
```

- [ ] **Step 9: Criar `app/Http/Controllers/TribunalController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\TribunalRequest;
use App\Models\Tribunal;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TribunalController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('tribunais/index', [
            'tribunais' => Tribunal::query()
                ->select(['id', 'nome', 'tipo', 'versao_mni', 'ativo'])
                ->orderBy('nome')
                ->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('tribunais/create', [
            'tipos' => Tribunal::getTipos(),
        ]);
    }

    public function store(TribunalRequest $request): RedirectResponse
    {
        Tribunal::create($request->validated());

        return redirect()->route('tribunais.index')->with('success', 'Tribunal criado.');
    }

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
                'login',
                'usar_credencial_tribunal',
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

    public function update(TribunalRequest $request, Tribunal $tribunal): RedirectResponse
    {
        $dados = $request->validated();

        if (blank($dados['password'] ?? null)) {
            unset($dados['password']);
        }

        $tribunal->update($dados);

        return redirect()->route('tribunais.index')->with('success', 'Tribunal atualizado.');
    }

    public function toggleAtivo(Tribunal $tribunal): RedirectResponse
    {
        $tribunal->update(['ativo' => ! $tribunal->ativo]);

        return back()->with('success', 'Tribunal atualizado.');
    }
}
```

- [ ] **Step 10: Rotas — `routes/web.php`**

Adicionar import `use App\Http\Controllers\TribunalController;` e, DENTRO do grupo `Route::middleware('auth:web')` (depois da rota dashboard):

```php
    Route::get('/tribunais', [TribunalController::class, 'index'])->name('tribunais.index');
    Route::get('/tribunais/criar', [TribunalController::class, 'create'])->name('tribunais.create');
    Route::post('/tribunais', [TribunalController::class, 'store'])->name('tribunais.store');
    Route::get('/tribunais/{tribunal}/editar', [TribunalController::class, 'edit'])->name('tribunais.edit');
    Route::put('/tribunais/{tribunal}', [TribunalController::class, 'update'])->name('tribunais.update');
    Route::patch('/tribunais/{tribunal}/ativo', [TribunalController::class, 'toggleAtivo'])->name('tribunais.toggle');
```

- [ ] **Step 11: Flash na shared prop — `app/Http/Middleware/HandleInertiaRequests.php`**

No array de `share()`, após `'sidebarOpen' => ...,` adicionar:

```php
            'flash' => [
                'success' => $request->session()->get('success'),
            ],
```

- [ ] **Step 12: Rodar testes e ver passar**

```bash
docker compose exec php php vendor/bin/pest tests/Feature/TribunalCrudTest.php
docker compose exec php php vendor/bin/pest
```

Expected: TribunalCrudTest **11 passed**; suíte **8 failed, 68 passed**. Se o store falhar com erro de coluna `uuid`, o guard do Step 7 está errado — investigar antes de seguir (não remover o teste).

Confirmar também que o banco não foi sujo (rollback funcionou):

```bash
docker compose exec php php artisan tinker --execute="echo App\Models\Tribunal::count();"
```

Expected: `8` (mesmos 8 registros de antes).

- [ ] **Step 13: Commit**

```bash
git add tests/SimDatabaseTestCase.php tests/Feature/TribunalCrudTest.php database/factories/TribunalFactory.php app/Http/Requests/TribunalRequest.php app/Http/Controllers/TribunalController.php app/Models/Tribunal.php app/Http/Middleware/HandleInertiaRequests.php routes/web.php resources/js/pages/tribunais
git commit -m "feat(tribunais): backend CRUD (rotas, controller, request, model fix) com testes"
```

---

### Task 2: Frontend — ui novos, páginas e sidebar

**Files:**

- Create: `resources/js/components/ui/select.tsx` (copiado do kit + sed), `resources/js/components/ui/table.tsx`, `resources/js/components/ui/switch.tsx`
- Create: `resources/js/components/tribunal-form.tsx`
- Modify: `resources/js/pages/tribunais/index.tsx`, `create.tsx`, `edit.tsx` (substituir stubs)
- Modify: `resources/js/types/index.d.ts`, `resources/js/components/app-sidebar.tsx`

**Interfaces:**

- Consumes: rotas/props da Task 1 (shapes exatos no bloco Produces de lá); `AppLayout({ breadcrumbs, children })`; `InputError`; ui existentes (button, input, label, checkbox); `cn()`.
- Produces: páginas finais `tribunais/index|create|edit`; `TribunalForm({ tipos, tribunal? })`; types `TribunalListItem`, `TribunalFormValues`; `SharedProps.flash`.

- [ ] **Step 1: Copiar select do kit + adaptar import**

```bash
KIT=/tmp/claude-1000/-home-bruno-projetos-ms-mni/f7b9d1dd-a1f6-4547-a5a4-962e6298d970/scratchpad/react-starter-kit
[ -d "$KIT" ] || git clone --depth 1 https://github.com/laravel/react-starter-kit.git "$KIT"
cp "$KIT/resources/js/components/ui/select.tsx" resources/js/components/ui/select.tsx
sed -i 's|import \* as SelectPrimitive from "@radix-ui/react-select"|import { Select as SelectPrimitive } from "radix-ui"|' resources/js/components/ui/select.tsx
grep -c "@radix-ui" resources/js/components/ui/select.tsx || echo "imports ok"
```

Expected: `imports ok`.

- [ ] **Step 2: Criar `resources/js/components/ui/table.tsx`** (canônico shadcn v4)

```tsx
import * as React from "react"

import { cn } from "@/lib/utils"

function Table({ className, ...props }: React.ComponentProps<"table">) {
  return (
    <div
      data-slot="table-container"
      className="relative w-full overflow-x-auto"
    >
      <table
        data-slot="table"
        className={cn("w-full caption-bottom text-sm", className)}
        {...props}
      />
    </div>
  )
}

function TableHeader({ className, ...props }: React.ComponentProps<"thead">) {
  return (
    <thead
      data-slot="table-header"
      className={cn("[&_tr]:border-b", className)}
      {...props}
    />
  )
}

function TableBody({ className, ...props }: React.ComponentProps<"tbody">) {
  return (
    <tbody
      data-slot="table-body"
      className={cn("[&_tr:last-child]:border-0", className)}
      {...props}
    />
  )
}

function TableFooter({ className, ...props }: React.ComponentProps<"tfoot">) {
  return (
    <tfoot
      data-slot="table-footer"
      className={cn(
        "bg-muted/50 border-t font-medium [&>tr]:last:border-b-0",
        className
      )}
      {...props}
    />
  )
}

function TableRow({ className, ...props }: React.ComponentProps<"tr">) {
  return (
    <tr
      data-slot="table-row"
      className={cn(
        "hover:bg-muted/50 data-[state=selected]:bg-muted border-b transition-colors",
        className
      )}
      {...props}
    />
  )
}

function TableHead({ className, ...props }: React.ComponentProps<"th">) {
  return (
    <th
      data-slot="table-head"
      className={cn(
        "text-foreground h-10 px-2 text-left align-middle font-medium whitespace-nowrap [&:has([role=checkbox])]:pr-0 [&>[role=checkbox]]:translate-y-[2px]",
        className
      )}
      {...props}
    />
  )
}

function TableCell({ className, ...props }: React.ComponentProps<"td">) {
  return (
    <td
      data-slot="table-cell"
      className={cn(
        "p-2 align-middle whitespace-nowrap [&:has([role=checkbox])]:pr-0 [&>[role=checkbox]]:translate-y-[2px]",
        className
      )}
      {...props}
    />
  )
}

function TableCaption({ className, ...props }: React.ComponentProps<"caption">) {
  return (
    <caption
      data-slot="table-caption"
      className={cn("text-muted-foreground mt-4 text-sm", className)}
      {...props}
    />
  )
}

export {
  Table,
  TableHeader,
  TableBody,
  TableFooter,
  TableHead,
  TableRow,
  TableCell,
  TableCaption,
}
```

- [ ] **Step 3: Criar `resources/js/components/ui/switch.tsx`** (canônico shadcn v4, radix via meta-package)

```tsx
import * as React from "react"
import { Switch as SwitchPrimitive } from "radix-ui"

import { cn } from "@/lib/utils"

function Switch({
  className,
  ...props
}: React.ComponentProps<typeof SwitchPrimitive.Root>) {
  return (
    <SwitchPrimitive.Root
      data-slot="switch"
      className={cn(
        "peer data-[state=checked]:bg-primary data-[state=unchecked]:bg-input focus-visible:border-ring focus-visible:ring-ring/50 dark:data-[state=unchecked]:bg-input/80 inline-flex h-[1.15rem] w-8 shrink-0 items-center rounded-full border border-transparent shadow-xs transition-all outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50",
        className
      )}
      {...props}
    >
      <SwitchPrimitive.Thumb
        data-slot="switch-thumb"
        className={cn(
          "bg-background dark:data-[state=unchecked]:bg-foreground dark:data-[state=checked]:bg-primary-foreground pointer-events-none block size-4 rounded-full ring-0 transition-transform data-[state=checked]:translate-x-[calc(100%-2px)] data-[state=unchecked]:translate-x-0"
        )}
      />
    </SwitchPrimitive.Root>
  )
}

export { Switch }
```

- [ ] **Step 4: Types — `resources/js/types/index.d.ts`**

Adicionar ao arquivo:

```ts
export interface TribunalListItem {
    id: number;
    nome: string;
    tipo: string | null;
    versao_mni: string | null;
    ativo: boolean | null;
}
```

E no `SharedProps`, adicionar o campo:

```ts
    flash: { success: string | null };
```

- [ ] **Step 5: Criar `resources/js/components/tribunal-form.tsx`**

```tsx
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEvent, type ReactNode } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const SEM_TIPO = '__none__';

export interface TribunalFormValues {
    id: number;
    nome: string;
    tipo: string | null;
    versao_mni: string | null;
    ativo: boolean | null;
    login: string;
    usar_credencial_tribunal: boolean | null;
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

function Secao({ titulo, children }: { titulo: string; children: ReactNode }) {
    return (
        <section className="space-y-4">
            <h2 className="border-b pb-2 text-lg font-semibold">{titulo}</h2>
            <div className="grid gap-4 md:grid-cols-2">{children}</div>
        </section>
    );
}

export default function TribunalForm({
    tipos,
    tribunal,
}: {
    tipos: string[];
    tribunal?: TribunalFormValues;
}) {
    const { data, setData, post, put, processing, errors } = useForm({
        nome: tribunal?.nome ?? '',
        tipo: tribunal?.tipo ?? null,
        versao_mni: tribunal?.versao_mni ?? '',
        ativo: Boolean(tribunal?.ativo ?? true),
        login: tribunal?.login ?? '',
        password: '',
        usar_credencial_tribunal: Boolean(tribunal?.usar_credencial_tribunal ?? false),
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

    function submit(e: FormEvent) {
        e.preventDefault();

        if (tribunal) {
            put(`/tribunais/${tribunal.id}`);
        } else {
            post('/tribunais');
        }
    }

    const camposUrl = [
        { key: 'url_webservice_mni', label: 'URL webservice MNI *' },
        { key: 'url_webservice_mni_complementar', label: 'URL webservice MNI complementar *' },
        { key: 'url_webservice_mni_consultar_processo', label: 'URL consultar processo' },
        { key: 'url_consulta_pje', label: 'URL consulta PJe' },
        { key: 'url_webservice_mni_criminal', label: 'URL webservice MNI criminal' },
        { key: 'url_recuperar_senha_tribunal', label: 'URL recuperar senha' },
    ] as const;

    const camposCodigo = [
        { key: 'codigo_peticao_inicial', label: 'Código petição inicial' },
        { key: 'codigo_peticao_avulsa', label: 'Código petição avulsa' },
        { key: 'codigo_certidao_inicio_fim', label: 'Código certidão início/fim' },
        { key: 'codigo_seeu', label: 'Código SEEU' },
        { key: 'usar_codigo_documento_padrao', label: 'Código documento padrão' },
    ] as const;

    return (
        <form onSubmit={submit} className="flex max-w-4xl flex-col gap-8">
            <Secao titulo="Identificação">
                <div className="space-y-2 md:col-span-2">
                    <Label htmlFor="nome">Nome *</Label>
                    <Input
                        id="nome"
                        value={data.nome}
                        onChange={(e) => setData('nome', e.target.value)}
                        aria-invalid={!!errors.nome}
                    />
                    <InputError message={errors.nome} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="tipo">Tipo</Label>
                    <Select
                        value={data.tipo ?? SEM_TIPO}
                        onValueChange={(value) => setData('tipo', value === SEM_TIPO ? null : value)}
                    >
                        <SelectTrigger id="tipo" aria-invalid={!!errors.tipo}>
                            <SelectValue placeholder="Selecione o tipo" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={SEM_TIPO}>Nenhum</SelectItem>
                            {tipos.map((tipo) => (
                                <SelectItem key={tipo} value={tipo}>
                                    {tipo}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.tipo} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="versao_mni">Versão MNI</Label>
                    <Input
                        id="versao_mni"
                        value={data.versao_mni ?? ''}
                        onChange={(e) => setData('versao_mni', e.target.value)}
                        placeholder="2.2.2"
                        aria-invalid={!!errors.versao_mni}
                    />
                    <InputError message={errors.versao_mni} />
                </div>

                <div className="flex items-center gap-2">
                    <Checkbox
                        id="ativo"
                        checked={data.ativo}
                        onCheckedChange={(checked) => setData('ativo', checked === true)}
                    />
                    <Label htmlFor="ativo" className="font-normal">
                        Ativo
                    </Label>
                </div>
            </Secao>

            <Secao titulo="Credenciais">
                <div className="space-y-2">
                    <Label htmlFor="login">Login *</Label>
                    <Input
                        id="login"
                        value={data.login}
                        onChange={(e) => setData('login', e.target.value)}
                        autoComplete="off"
                        aria-invalid={!!errors.login}
                    />
                    <InputError message={errors.login} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="password">{tribunal ? 'Senha' : 'Senha *'}</Label>
                    <Input
                        id="password"
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        autoComplete="new-password"
                        placeholder={tribunal ? 'Preencha somente para trocar a senha' : undefined}
                        aria-invalid={!!errors.password}
                    />
                    <InputError message={errors.password} />
                </div>

                <div className="flex items-center gap-2 md:col-span-2">
                    <Checkbox
                        id="usar_credencial_tribunal"
                        checked={data.usar_credencial_tribunal}
                        onCheckedChange={(checked) => setData('usar_credencial_tribunal', checked === true)}
                    />
                    <Label htmlFor="usar_credencial_tribunal" className="font-normal">
                        Usar credencial do tribunal
                    </Label>
                </div>
            </Secao>

            <Secao titulo="URLs MNI">
                {camposUrl.map(({ key, label }) => (
                    <div key={key} className="space-y-2">
                        <Label htmlFor={key}>{label}</Label>
                        <Input
                            id={key}
                            type="url"
                            value={data[key] ?? ''}
                            onChange={(e) => setData(key, e.target.value)}
                            aria-invalid={!!errors[key]}
                        />
                        <InputError message={errors[key]} />
                    </div>
                ))}
            </Secao>

            <Secao titulo="Códigos">
                {camposCodigo.map(({ key, label }) => (
                    <div key={key} className="space-y-2">
                        <Label htmlFor={key}>{label}</Label>
                        <Input
                            id={key}
                            value={data[key] ?? ''}
                            onChange={(e) => setData(key, e.target.value)}
                            aria-invalid={!!errors[key]}
                        />
                        <InputError message={errors[key]} />
                    </div>
                ))}
            </Secao>

            <Secao titulo="Flags">
                <div className="flex items-center gap-2 md:col-span-2">
                    <Checkbox
                        id="enviar_dados_criminais"
                        checked={data.enviar_dados_criminais}
                        onCheckedChange={(checked) => setData('enviar_dados_criminais', checked === true)}
                    />
                    <Label htmlFor="enviar_dados_criminais" className="font-normal">
                        Enviar dados criminais
                    </Label>
                </div>
            </Secao>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="size-4 animate-spin" />}
                    Salvar
                </Button>
                <Button variant="ghost" asChild>
                    <Link href="/tribunais">Cancelar</Link>
                </Button>
            </div>
        </form>
    );
}
```

- [ ] **Step 6: Substituir stub `resources/js/pages/tribunais/index.tsx`**

```tsx
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';

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
import { type BreadcrumbItem, type SharedProps, type TribunalListItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tribunais', href: '/tribunais' }];

export default function TribunaisIndex({ tribunais }: { tribunais: TribunalListItem[] }) {
    const { flash } = usePage<SharedProps>().props;

    function toggleAtivo(id: number) {
        router.patch(`/tribunais/${id}/ativo`, {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tribunais" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Tribunais</h1>
                    <Button asChild>
                        <Link href="/tribunais/criar" prefetch>
                            <Plus /> Novo tribunal
                        </Link>
                    </Button>
                </div>

                {flash.success && (
                    <div className="rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
                        {flash.success}
                    </div>
                )}

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nome</TableHead>
                                <TableHead>Tipo</TableHead>
                                <TableHead>Versão MNI</TableHead>
                                <TableHead>Ativo</TableHead>
                                <TableHead className="w-0" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tribunais.map((tribunal) => (
                                <TableRow key={tribunal.id}>
                                    <TableCell className="font-medium">{tribunal.nome}</TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {tribunal.tipo ?? '—'}
                                    </TableCell>
                                    <TableCell>{tribunal.versao_mni ?? '—'}</TableCell>
                                    <TableCell>
                                        <Switch
                                            checked={Boolean(tribunal.ativo)}
                                            onCheckedChange={() => toggleAtivo(tribunal.id)}
                                            aria-label={`Ativar/desativar ${tribunal.nome}`}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={`/tribunais/${tribunal.id}/editar`} prefetch>
                                                Editar
                                            </Link>
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                            {tribunais.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={5} className="text-center text-muted-foreground">
                                        Nenhum tribunal cadastrado.
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

- [ ] **Step 7: Substituir stub `resources/js/pages/tribunais/create.tsx`**

```tsx
import { Head } from '@inertiajs/react';

import TribunalForm from '@/components/tribunal-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tribunais', href: '/tribunais' },
    { title: 'Novo tribunal', href: '/tribunais/criar' },
];

export default function TribunaisCreate({ tipos }: { tipos: string[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Novo tribunal" />
            <div className="flex flex-col gap-6 p-4">
                <h1 className="text-2xl font-bold">Novo tribunal</h1>
                <TribunalForm tipos={tipos} />
            </div>
        </AppLayout>
    );
}
```

- [ ] **Step 8: Substituir stub `resources/js/pages/tribunais/edit.tsx`**

```tsx
import { Head } from '@inertiajs/react';

import TribunalForm, { type TribunalFormValues } from '@/components/tribunal-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

export default function TribunaisEdit({
    tipos,
    tribunal,
}: {
    tipos: string[];
    tribunal: TribunalFormValues;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Tribunais', href: '/tribunais' },
        { title: tribunal.nome, href: `/tribunais/${tribunal.id}/editar` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Editar ${tribunal.nome}`} />
            <div className="flex flex-col gap-6 p-4">
                <h1 className="text-2xl font-bold">Editar tribunal</h1>
                <TribunalForm tipos={tipos} tribunal={tribunal} />
            </div>
        </AppLayout>
    );
}
```

- [ ] **Step 9: Item na sidebar — `resources/js/components/app-sidebar.tsx`**

No import do lucide, adicionar `Landmark`:

```tsx
import { Activity, FileText, Landmark, LayoutGrid, LayoutList } from 'lucide-react';
```

E no array `mainNavItems`, adicionar após o item Dashboard:

```tsx
    {
        title: 'Tribunais',
        href: '/tribunais',
        icon: Landmark,
    },
```

- [ ] **Step 10: Verificar**

```bash
npm run typecheck && npm run build
docker compose exec php php vendor/bin/pest tests/Feature/TribunalCrudTest.php
```

Expected: typecheck/build verdes; TribunalCrudTest 11 passed (páginas reais substituíram stubs — `component()` continua achando os arquivos).

- [ ] **Step 11: Commit**

```bash
git add resources/js/components/ui/select.tsx resources/js/components/ui/table.tsx resources/js/components/ui/switch.tsx resources/js/components/tribunal-form.tsx resources/js/pages/tribunais resources/js/types/index.d.ts resources/js/components/app-sidebar.tsx
git commit -m "feat(tribunais): telas de listagem e formulario (index/create/edit) + item na sidebar"
```

---

### Task 3: Verificação e2e no browser

**Files:** nenhum (verificação; fixes pontuais se algo falhar).

**Interfaces:**

- Consumes: tudo das Tasks 1–2.
- Produces: confirmação real do fluxo (spec: listar, criar, editar, toggle, validação visível).

- [ ] **Step 1: Build + app de pé**

```bash
npm run build
docker compose up -d --no-deps php
docker compose exec php php artisan optimize:clear
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8006/login
```

Expected: `200`.

- [ ] **Step 2: Fluxo no browser** (MCP Playwright; usar a tool `browser_resize` do MCP — NUNCA `page.setViewportSize` em run_code, corrompe o input)

Login com user do banco local (criar via tinker `User::factory()->create(['email' => 'teste@e2e.local'])`, senha `password`; deletar no fim). Depois:

1. Sidebar mostra "Tribunais"; clicar → `/tribunais` lista os 8 registros reais (Nome/Tipo/Versão/Switch/Editar).
2. "Novo tribunal" → form com 5 seções; submeter vazio → `InputError` em nome/login/senha/URLs obrigatórias, sem reload.
3. Preencher payload válido (nome "Tribunal Teste E2E", tipo STJ, login/senha, 2 URLs obrigatórias `https://...`) → salva, volta pra index, banner verde "Tribunal criado.", registro na tabela.
4. Editar o criado: campo senha VAZIO com hint; trocar nome → salvar → banner "Tribunal atualizado.", nome novo na lista.
5. Toggle do Switch do registro de teste → estado inverte sem reload (preserveScroll); recarregar → persistiu.
6. **Limpeza obrigatória**: deletar o tribunal de teste criado — `docker compose exec php php artisan tinker --execute="App\Models\Tribunal::where('nome','like','%Teste E2E%')->forceDelete(); echo 'limpo';"` (forceDelete — model tem SoftDeletes) e deletar o user teste.
7. Console do browser: zero erros.

- [ ] **Step 3: Se algo falhar** — corrigir, re-rodar `docker compose exec php php vendor/bin/pest` + `npm run build`, commitar `fix(tribunais): <o que era>`.

---

## Critério de aceite global

1. `docker compose exec php php vendor/bin/pest` → **8 failed, 68 passed** (11 novos verdes; 8 = pré-existentes exportação).
2. `Tribunal::count()` volta a **8** após testes e e2e (rollback + limpeza funcionaram).
3. `npm run typecheck && npm run build` verdes.
4. Fluxo e2e da Task 3 completo.
5. `git diff feat/starter-kit-dashboard -- routes/api.php app/Http/Controllers/Api/` vazio; `package.json`/`composer.json` sem mudança.
