# Métricas na Dashboard Inicial — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Substituir o placeholder de `/dashboard` por cards de totais + 2 gráficos (processos baixados/dia e documentos baixados/dia) com seletor de período 7/30/90 dias.

**Architecture:** Nova coluna `downloaded_at` em `processo_documentos` (backfill via `updated_at`); `DashboardMetricasService` agrega por dia (Postgres, timezone America/Sao_Paulo, série zero-filled); `DashboardController` entrega `periodo` direto e `metricas` via `Inertia::defer`; frontend React usa Recharts via componente chart do shadcn com `<Deferred>` + skeleton.

**Tech Stack:** Laravel 11 + Inertia v2 + React 19 + TypeScript + Tailwind 4 + Recharts. Pest com `DatabaseTransactions` contra Postgres dev.

**Spec:** `docs/superpowers/specs/2026-07-22-dashboard-metricas-design.md`

## Global Constraints

- PHP roda SÓ no container: `docker compose exec php php ...`. O wrapper `./php` do repo está quebrado — nunca usar.
- Testes: `docker compose exec php ./vendor/bin/pest <arquivo>`. Suíte completa tem **9 falhas pré-existentes** no domínio exportação (baseline conhecido) — não são regressão.
- `npx shadcn add` está QUEBRADO neste ambiente (`storage/framework/testing/disks` root-owned → EACCES). Buscar código no registry via curl e escrever à mão (Task 5).
- Banco de testes = banco dev Postgres com dados reais → testes de contagem usam **asserções de delta** (antes/depois dentro da transação), nunca contagem absoluta.
- Timestamps armazenados em UTC (`APP_TIMEZONE=UTC`); agrupamento por dia em `America/Sao_Paulo` via `(col AT TIME ZONE 'UTC') AT TIME ZONE 'America/Sao_Paulo'`.
- Período válido: `{7, 30, 90}`, default e fallback = 30.
- Cores dos gráficos (validadas com dataviz validator, todos os checks PASS): light `--chart-1: #f0562a`, `--chart-2: #0d9488`; dark `--chart-1: #8b5cf6`, `--chart-2: #059669`. Processos = chart-1, Documentos = chart-2, em ambos os modos.
- Após editar `SalvarDocumentoProcessoService` (código usado por jobs): `docker compose exec php php artisan horizon:terminate` (workers são daemons com código velho em memória).
- Commits frequentes, mensagens em pt-BR estilo conventional (`feat(dashboard): ...`), com `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.

---

### Task 1: Migration `downloaded_at` + cast no model

**Files:**
- Create: `database/migrations/2026_07_22_000001_add_downloaded_at_to_processo_documentos_table.php`
- Modify: `app/Models/ProcessoDocumento.php` (fillable + casts)
- Test: `tests/Feature/Migrations/DownloadedAtTest.php`

**Interfaces:**
- Consumes: tabela `processo_documentos` existente (coluna `status` com valores `baixado|pendente|erro`).
- Produces: coluna `processo_documentos.downloaded_at` (timestamp nullable, indexada), cast `datetime` no model. Tasks 2 e 3 dependem dela.

- [ ] **Step 1: Write the failing test**

Criar `tests/Feature/Migrations/DownloadedAtTest.php`:

```php
<?php

use Illuminate\Support\Facades\Schema;

it('processo_documentos tem a coluna downloaded_at', function () {
    expect(Schema::hasColumn('processo_documentos', 'downloaded_at'))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec php ./vendor/bin/pest tests/Feature/Migrations/DownloadedAtTest.php`
Expected: FAIL — `Failed asserting that false is true`.

- [ ] **Step 3: Write the migration**

Criar `database/migrations/2026_07_22_000001_add_downloaded_at_to_processo_documentos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->timestamp('downloaded_at')->nullable()->after('status');
            $table->index('downloaded_at', 'idx_processo_documentos_downloaded_at');
        });

        // Backfill histórico: aproximação — updated_at é o último save,
        // que para docs baixados normalmente é o momento do download.
        DB::table('processo_documentos')
            ->where('status', 'baixado')
            ->whereNull('downloaded_at')
            ->update(['downloaded_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->dropIndex('idx_processo_documentos_downloaded_at');
            $table->dropColumn('downloaded_at');
        });
    }
};
```

- [ ] **Step 4: Run the migration**

Run: `docker compose exec php php artisan migrate`
Expected: `2026_07_22_000001_add_downloaded_at_to_processo_documentos_table ... DONE`

- [ ] **Step 5: Add fillable + cast no model**

Em `app/Models/ProcessoDocumento.php`:

No array `$fillable`, depois de `'status',` adicionar:

```php
        'downloaded_at',
```

No array `$casts`, virar:

```php
    protected $casts = [
        'id_documento' => 'integer',
        'downloaded_at' => 'datetime',
    ];
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec php ./vendor/bin/pest tests/Feature/Migrations/DownloadedAtTest.php`
Expected: PASS (1 test).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_22_000001_add_downloaded_at_to_processo_documentos_table.php app/Models/ProcessoDocumento.php tests/Feature/Migrations/DownloadedAtTest.php
git commit -m "feat(dashboard): coluna downloaded_at em processo_documentos com backfill

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: `marcarComoBaixado()` no SalvarDocumentoProcessoService

**Files:**
- Modify: `app/Services/Processo/SalvarDocumentoProcessoService.php` (novo método + 3 call sites: ~linhas 122-126, ~462-466, ~589-593)
- Test: `tests/Feature/SalvarDocumentoDownloadedAtTest.php`

**Interfaces:**
- Consumes: coluna `downloaded_at` (Task 1); `ProcessoDocumento::STATUS_BAIXADO`.
- Produces: `public function marcarComoBaixado(ProcessoDocumento $documento, string $path, ?int $fileSize = null): void` — seta `status`, `path`, `downloaded_at = now()`, opcionalmente `file_size`, e salva. Task 3 conta docs por `downloaded_at`.

- [ ] **Step 1: Write the failing test**

Criar `tests/Feature/SalvarDocumentoDownloadedAtTest.php`:

```php
<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('marcarComoBaixado seta status, path, file_size e downloaded_at', function () {
    $processo = Processo::factory()->create();
    $documento = ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => 'DOC-DL-' . getmypid(),
        'tipo_documento' => 57,
        'descricao' => 'Doc teste downloaded_at',
        'mimetype' => 'application/pdf',
        'data_hora' => '2026-01-05 10:00:00',
        'status' => ProcessoDocumento::STATUS_PENDENTE,
    ]);

    app(SalvarDocumentoProcessoService::class)
        ->marcarComoBaixado($documento, 'documentos-processos/teste/1.pdf', 1234);

    $documento->refresh();
    expect($documento->status)->toBe(ProcessoDocumento::STATUS_BAIXADO)
        ->and($documento->getRawOriginal('path'))->toBe('documentos-processos/teste/1.pdf')
        ->and($documento->file_size)->toEqual(1234)
        ->and($documento->downloaded_at)->not->toBeNull();
});

it('marcarComoBaixado sem fileSize preserva file_size existente', function () {
    $processo = Processo::factory()->create();
    $documento = ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => 'DOC-DL2-' . getmypid(),
        'tipo_documento' => 57,
        'descricao' => 'Doc teste sem filesize',
        'mimetype' => 'application/pdf',
        'data_hora' => '2026-01-05 10:00:00',
        'status' => ProcessoDocumento::STATUS_PENDENTE,
        'file_size' => 999,
    ]);

    app(SalvarDocumentoProcessoService::class)
        ->marcarComoBaixado($documento, 'documentos-processos/teste/2.pdf');

    $documento->refresh();
    expect($documento->file_size)->toEqual(999)
        ->and($documento->downloaded_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec php ./vendor/bin/pest tests/Feature/SalvarDocumentoDownloadedAtTest.php`
Expected: FAIL — `Call to undefined method ... marcarComoBaixado()`.

- [ ] **Step 3: Implement helper + trocar os 3 call sites**

Em `app/Services/Processo/SalvarDocumentoProcessoService.php`, adicionar método público (logo após `baixarDocumento`):

```php
    public function marcarComoBaixado(ProcessoDocumento $documento, string $path, ?int $fileSize = null): void
    {
        $documento->status = ProcessoDocumento::STATUS_BAIXADO;
        $documento->path = $path;
        $documento->downloaded_at = now();

        if ($fileSize !== null) {
            $documento->file_size = $fileSize;
        }

        $documento->save();
    }
```

Call site 1 — em `baixarDocumento()` (~linha 122), trocar:

```php
        if ($path && Storage::disk('s3')->exists($path)) {
            $documento->status = ProcessoDocumento::STATUS_BAIXADO;
            $documento->path = $path;
            $documento->save();
```

por:

```php
        if ($path && Storage::disk('s3')->exists($path)) {
            $this->marcarComoBaixado($documento, $path);
```

Call site 2 — no download de MP4 (~linha 462), trocar:

```php
            // Atualizar documento
            $documento->file_size = $fileSize;
            $documento->status = ProcessoDocumento::STATUS_BAIXADO;
            $documento->path = $filename;
            $documento->save();
```

por:

```php
            $this->marcarComoBaixado($documento, $filename, $fileSize);
```

Call site 3 — no download de QuickTime (~linha 589): mesma troca do call site 2 (o bloco é idêntico).

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec php ./vendor/bin/pest tests/Feature/SalvarDocumentoDownloadedAtTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Reiniciar workers (código de job mudou)**

Run: `docker compose exec php php artisan horizon:terminate`
Expected: sem erro (supervisord reinicia o Horizon sozinho).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Processo/SalvarDocumentoProcessoService.php tests/Feature/SalvarDocumentoDownloadedAtTest.php
git commit -m "feat(dashboard): downloaded_at preenchido ao marcar documento como baixado

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: DashboardMetricasService

**Files:**
- Create: `app/Services/Dashboard/DashboardMetricasService.php`
- Test: `tests/Feature/DashboardMetricasServiceTest.php`

**Interfaces:**
- Consumes: models `Processo` (created_at) e `ProcessoDocumento` (status, downloaded_at — Tasks 1-2).
- Produces: `public function metricas(int $periodoDias): array` retornando:
  ```php
  [
      'totais' => ['processos' => int, 'documentosBaixados' => int, 'documentosPendentes' => int, 'documentosErro' => int],
      'processosPorDia' => [['dia' => 'YYYY-MM-DD', 'total' => int], ...],   // exatamente $periodoDias pontos
      'documentosPorDia' => [['dia' => 'YYYY-MM-DD', 'total' => int], ...],  // idem
  ]
  ```
  Task 4 expõe esse array como prop `metricas`.

- [ ] **Step 1: Write the failing test**

Criar `tests/Feature/DashboardMetricasServiceTest.php`:

```php
<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Services\Dashboard\DashboardMetricasService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function metricasService(): DashboardMetricasService
{
    return new DashboardMetricasService();
}

function docMetrica(Processo $processo, array $overrides = []): ProcessoDocumento
{
    static $seq = 0;
    return ProcessoDocumento::create(array_merge([
        'processo_id' => $processo->id,
        'id_documento' => 'DOC-MET-' . getmypid() . '-' . (++$seq),
        'tipo_documento' => 57,
        'descricao' => 'Doc métrica',
        'mimetype' => 'application/pdf',
        'data_hora' => '2026-01-05 10:00:00',
        'status' => ProcessoDocumento::STATUS_PENDENTE,
    ], $overrides));
}

it('conta processos do período e ignora os antigos (delta)', function () {
    $antes = metricasService()->metricas(7);

    Processo::factory()->create();                                    // dentro do período
    Processo::factory()->create(['created_at' => now()->subDays(30)]); // fora

    $depois = metricasService()->metricas(7);
    expect($depois['totais']['processos'] - $antes['totais']['processos'])->toBe(1);
});

it('conta documentos baixados por downloaded_at e pendentes/erro por estado (delta)', function () {
    $processo = Processo::factory()->create();
    $antes = metricasService()->metricas(7);

    docMetrica($processo, ['status' => ProcessoDocumento::STATUS_BAIXADO, 'downloaded_at' => now()]);
    docMetrica($processo, ['status' => ProcessoDocumento::STATUS_BAIXADO, 'downloaded_at' => now()->subDays(30)]); // fora do período
    docMetrica($processo, ['status' => ProcessoDocumento::STATUS_PENDENTE]);
    docMetrica($processo, ['status' => ProcessoDocumento::STATUS_ERRO]);

    $depois = metricasService()->metricas(7);
    expect($depois['totais']['documentosBaixados'] - $antes['totais']['documentosBaixados'])->toBe(1)
        ->and($depois['totais']['documentosPendentes'] - $antes['totais']['documentosPendentes'])->toBe(1)
        ->and($depois['totais']['documentosErro'] - $antes['totais']['documentosErro'])->toBe(1);
});

it('série diária tem N pontos contínuos terminando hoje (SP) e conta o delta de hoje', function () {
    $antes = metricasService()->metricas(7);
    Processo::factory()->create();
    $depois = metricasService()->metricas(7);

    expect($depois['processosPorDia'])->toHaveCount(7)
        ->and($depois['documentosPorDia'])->toHaveCount(7);

    $hoje = now('America/Sao_Paulo')->toDateString();
    $ultimoDepois = $depois['processosPorDia'][6];
    $ultimoAntes = $antes['processosPorDia'][6];
    expect($ultimoDepois['dia'])->toBe($hoje)
        ->and($ultimoDepois['total'] - $ultimoAntes['total'])->toBe(1);

    // série contínua: dias consecutivos sem buraco
    $dias = array_column($depois['processosPorDia'], 'dia');
    for ($i = 1; $i < count($dias); $i++) {
        expect($dias[$i])->toBe(
            \Carbon\CarbonImmutable::parse($dias[$i - 1])->addDay()->toDateString()
        );
    }
});

it('suporta períodos 30 e 90', function () {
    expect(metricasService()->metricas(30)['processosPorDia'])->toHaveCount(30)
        ->and(metricasService()->metricas(90)['processosPorDia'])->toHaveCount(90);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec php ./vendor/bin/pest tests/Feature/DashboardMetricasServiceTest.php`
Expected: FAIL — `Class "App\Services\Dashboard\DashboardMetricasService" not found`.

- [ ] **Step 3: Implement the service**

Criar `app/Services/Dashboard/DashboardMetricasService.php`:

```php
<?php

namespace App\Services\Dashboard;

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class DashboardMetricasService
{
    private const TIMEZONE = 'America/Sao_Paulo';

    public function metricas(int $periodoDias): array
    {
        $inicioLocal = CarbonImmutable::now(self::TIMEZONE)
            ->startOfDay()
            ->subDays($periodoDias - 1);
        $inicioUtc = $inicioLocal->setTimezone('UTC');

        return [
            'totais' => [
                'processos' => Processo::where('created_at', '>=', $inicioUtc)->count(),
                'documentosBaixados' => ProcessoDocumento::where('status', ProcessoDocumento::STATUS_BAIXADO)
                    ->where('downloaded_at', '>=', $inicioUtc)
                    ->count(),
                'documentosPendentes' => ProcessoDocumento::where('status', ProcessoDocumento::STATUS_PENDENTE)->count(),
                'documentosErro' => ProcessoDocumento::where('status', ProcessoDocumento::STATUS_ERRO)->count(),
            ],
            'processosPorDia' => $this->seriePorDia(
                Processo::query(),
                'created_at',
                $inicioLocal,
                $periodoDias,
            ),
            'documentosPorDia' => $this->seriePorDia(
                ProcessoDocumento::where('status', ProcessoDocumento::STATUS_BAIXADO),
                'downloaded_at',
                $inicioLocal,
                $periodoDias,
            ),
        ];
    }

    private function seriePorDia(Builder $query, string $coluna, CarbonImmutable $inicioLocal, int $periodoDias): array
    {
        // timestamps são armazenados em UTC; o dia útil pro usuário é o dia em SP
        $diaExpr = sprintf(
            "((%s AT TIME ZONE 'UTC') AT TIME ZONE '%s')::date",
            $coluna,
            self::TIMEZONE,
        );

        $contagens = $query
            ->where($coluna, '>=', $inicioLocal->setTimezone('UTC'))
            ->selectRaw("{$diaExpr} as dia, COUNT(*) as total")
            ->groupByRaw($diaExpr)
            ->pluck('total', 'dia');

        $serie = [];
        for ($i = 0; $i < $periodoDias; $i++) {
            $dia = $inicioLocal->addDays($i)->toDateString();
            $serie[] = ['dia' => $dia, 'total' => (int) ($contagens[$dia] ?? 0)];
        }

        return $serie;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec php ./vendor/bin/pest tests/Feature/DashboardMetricasServiceTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Dashboard/DashboardMetricasService.php tests/Feature/DashboardMetricasServiceTest.php
git commit -m "feat(dashboard): service de metricas agregadas por periodo

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: DashboardController + rota + testes de props

**Files:**
- Create: `app/Http/Controllers/DashboardController.php`
- Modify: `routes/web.php:35-37` (trocar closure pelo controller)
- Test: `tests/Feature/DashboardTest.php` (adicionar testes; manter os 2 existentes)

**Interfaces:**
- Consumes: `DashboardMetricasService::metricas(int): array` (Task 3).
- Produces: rota `GET /dashboard` (name `dashboard`, middleware `auth:web` inalterado) com props Inertia: `periodo` (int, direto) e `metricas` (deferred, shape da Task 3). Task 6 consome essas props.

- [ ] **Step 1: Write the failing tests**

Em `tests/Feature/DashboardTest.php`, adicionar no topo (depois dos `use` existentes):

```php
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);
```

E adicionar ao final do arquivo:

```php
it('entrega periodo padrão 30 e adia metricas', function () {
    $this->actingAs(User::factory()->make(['id' => 1]))
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('periodo', 30)
            ->missing('metricas'));
});

it('aceita periodo 7 e 90', function () {
    $user = User::factory()->make(['id' => 1]);

    $this->actingAs($user)
        ->get('/dashboard?periodo=7')
        ->assertInertia(fn (Assert $page) => $page->where('periodo', 7));

    $this->actingAs($user)
        ->get('/dashboard?periodo=90')
        ->assertInertia(fn (Assert $page) => $page->where('periodo', 90));
});

it('faz fallback para 30 com periodo inválido', function () {
    $this->actingAs(User::factory()->make(['id' => 1]))
        ->get('/dashboard?periodo=99')
        ->assertInertia(fn (Assert $page) => $page->where('periodo', 30));
});

it('entrega metricas no partial reload com o shape esperado', function () {
    $this->actingAs(User::factory()->make(['id' => 1]))
        ->get('/dashboard?periodo=7', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => \Inertia\Inertia::getVersion() ?? '',
            'X-Inertia-Partial-Component' => 'dashboard',
            'X-Inertia-Partial-Data' => 'metricas',
        ])
        ->assertOk()
        ->assertJsonStructure([
            'props' => [
                'metricas' => [
                    'totais' => ['processos', 'documentosBaixados', 'documentosPendentes', 'documentosErro'],
                    'processosPorDia',
                    'documentosPorDia',
                ],
            ],
        ])
        ->assertJsonCount(7, 'props.metricas.processosPorDia')
        ->assertJsonCount(7, 'props.metricas.documentosPorDia');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec php ./vendor/bin/pest tests/Feature/DashboardTest.php`
Expected: os 2 testes antigos PASSAM; os 4 novos FALHAM (prop `periodo` inexistente).

- [ ] **Step 3: Implement controller + rota**

Criar `app/Http/Controllers/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardMetricasService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const PERIODOS_VALIDOS = [7, 30, 90];

    public function index(Request $request, DashboardMetricasService $metricas): Response
    {
        $periodo = (int) $request->query('periodo', 30);

        if (!in_array($periodo, self::PERIODOS_VALIDOS, true)) {
            $periodo = 30;
        }

        return Inertia::render('dashboard', [
            'periodo' => $periodo,
            // deferred: agregações em tabelas grandes não seguram o primeiro paint
            'metricas' => Inertia::defer(fn () => $metricas->metricas($periodo)),
        ]);
    }
}
```

Em `routes/web.php`, adicionar o import:

```php
use App\Http\Controllers\DashboardController;
```

e trocar:

```php
    Route::get('/dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
```

por:

```php
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
```

(Se o import `use Inertia\Inertia;` ficar sem uso no arquivo, removê-lo.)

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec php ./vendor/bin/pest tests/Feature/DashboardTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/DashboardController.php routes/web.php tests/Feature/DashboardTest.php
git commit -m "feat(dashboard): controller com periodo e metricas deferred

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Infra frontend — recharts, chart.tsx, CSS vars

**Files:**
- Modify: `package.json` / `package-lock.json` (via npm install)
- Create: `resources/js/components/ui/chart.tsx`
- Modify: `resources/css/app.css` (vars `--chart-1/2` + mapeamento `--color-chart-*`)

**Interfaces:**
- Consumes: alias `@/lib/utils` (`cn`) já existente; tema light/dark via classe `.dark`.
- Produces: pacote `recharts`; componentes `ChartContainer`, `ChartTooltip`, `ChartTooltipContent`, tipo `ChartConfig` exportados de `@/components/ui/chart`; CSS vars `--chart-1`, `--chart-2` (e `--color-chart-1/2` no @theme). Task 6 usa tudo isso.

- [ ] **Step 1: Instalar recharts (npm roda no host)**

```bash
npm install recharts
```

Expected: `recharts` em `dependencies` no package.json, sem erros de peer deps (React 19 é suportado pelo recharts ≥ 2.13).

- [ ] **Step 2: Baixar o chart.tsx do registry shadcn (CLI está quebrado neste ambiente)**

```bash
curl -s https://ui.shadcn.com/r/styles/new-york-v4/chart.json -o /tmp/claude-1000/-home-bruno-projetos-ms-mni/51b80cda-c48f-44a1-aea6-447ff7decd44/scratchpad/chart.json
node -e "const j=require('/tmp/claude-1000/-home-bruno-projetos-ms-mni/51b80cda-c48f-44a1-aea6-447ff7decd44/scratchpad/chart.json'); process.stdout.write(j.files[0].content)" > resources/js/components/ui/chart.tsx
head -5 resources/js/components/ui/chart.tsx
```

Expected: arquivo começa com `"use client"` / imports de `recharts`. Se a primeira linha for `"use client"`, removê-la (projeto Vite, diretiva RSC é inócua mas o codebase não a usa). Conferir que os imports usam `@/lib/utils` (alias já configurado em components.json).

Fallback se o registry estiver fora do ar ou o shape do JSON mudar: baixar de `https://raw.githubusercontent.com/shadcn-ui/ui/main/apps/v4/registry/new-york-v4/ui/chart.tsx`.

- [ ] **Step 3: Adicionar CSS vars de gráfico**

Em `resources/css/app.css`:

Dentro do bloco `@theme { ... }` (linhas ~8-42), adicionar junto aos outros mapeamentos:

```css
    --color-chart-1: var(--chart-1);
    --color-chart-2: var(--chart-2);
```

Dentro do bloco `:root { ... }` (começa ~linha 44), adicionar:

```css
    /* cores de gráfico — validadas p/ CVD e contraste (dataviz) */
    --chart-1: #f0562a;
    --chart-2: #0d9488;
```

Dentro do bloco `.dark { ... }`, adicionar:

```css
    --chart-1: #8b5cf6;
    --chart-2: #059669;
```

- [ ] **Step 4: Typecheck**

Run: `npm run typecheck`
Expected: sem erros (chart.tsx compila contra recharts instalado).

- [ ] **Step 5: Commit**

```bash
git add package.json package-lock.json resources/js/components/ui/chart.tsx resources/css/app.css
git commit -m "feat(ui): componente chart (shadcn/recharts) e paleta de graficos

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: Página dashboard.tsx com cards, seletor e gráficos

**Files:**
- Modify: `resources/js/pages/dashboard.tsx` (reescrita completa)

**Interfaces:**
- Consumes: props `periodo: number` e `metricas?: Metricas` (Task 4); `ChartContainer/ChartTooltip/ChartTooltipContent/ChartConfig` (Task 5); `Card`, `Button`, `Skeleton` de `@/components/ui`; `Deferred` do Inertia (mesmo padrão de `resources/js/pages/processos/show.tsx:187`).
- Produces: página `dashboard` final. Nenhuma task posterior depende dela (Task 7 só verifica).

- [ ] **Step 1: Reescrever dashboard.tsx**

Substituir todo o conteúdo de `resources/js/pages/dashboard.tsx` por:

```tsx
import { Deferred, Head, router } from '@inertiajs/react';
import { AlertCircle, Clock, FileDown, FolderDown } from 'lucide-react';
import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from 'recharts';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartContainer, ChartTooltip, ChartTooltipContent, type ChartConfig } from '@/components/ui/chart';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

type PontoSerie = { dia: string; total: number };

type Metricas = {
    totais: {
        processos: number;
        documentosBaixados: number;
        documentosPendentes: number;
        documentosErro: number;
    };
    processosPorDia: PontoSerie[];
    documentosPorDia: PontoSerie[];
};

const PERIODOS = [7, 30, 90] as const;

const chartConfigProcessos = {
    total: { label: 'Processos', color: 'var(--chart-1)' },
} satisfies ChartConfig;

const chartConfigDocumentos = {
    total: { label: 'Documentos', color: 'var(--chart-2)' },
} satisfies ChartConfig;

function formatarDiaCurto(dia: string) {
    return new Date(`${dia}T00:00:00`).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
}

function formatarDiaLongo(dia: string) {
    return new Date(`${dia}T00:00:00`).toLocaleDateString('pt-BR', { day: '2-digit', month: 'long', year: 'numeric' });
}

function CardTotal({
    titulo,
    valor,
    icone: Icone,
}: {
    titulo: string;
    valor?: number;
    icone: typeof FileDown;
}) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">{titulo}</CardTitle>
                <Icone className="size-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
                {valor === undefined ? (
                    <Skeleton className="h-8 w-16" />
                ) : (
                    <div className="text-2xl font-semibold tabular-nums">{valor.toLocaleString('pt-BR')}</div>
                )}
            </CardContent>
        </Card>
    );
}

function GraficoSerie({
    titulo,
    serie,
    config,
    corVar,
}: {
    titulo: string;
    serie: PontoSerie[];
    config: ChartConfig;
    corVar: string;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">{titulo}</CardTitle>
            </CardHeader>
            <CardContent>
                <ChartContainer config={config} className="h-64 w-full">
                    <AreaChart data={serie} margin={{ left: 0, right: 12, top: 8 }}>
                        <CartesianGrid vertical={false} strokeDasharray="3 3" />
                        <XAxis
                            dataKey="dia"
                            tickLine={false}
                            axisLine={false}
                            tickMargin={8}
                            minTickGap={24}
                            tickFormatter={formatarDiaCurto}
                        />
                        <YAxis tickLine={false} axisLine={false} allowDecimals={false} width={36} />
                        <ChartTooltip
                            cursor={{ strokeDasharray: '3 3' }}
                            content={<ChartTooltipContent labelFormatter={(_, payload) => formatarDiaLongo(String(payload?.[0]?.payload?.dia ?? ''))} />}
                        />
                        <Area
                            dataKey="total"
                            type="linear"
                            stroke={corVar}
                            strokeWidth={2}
                            fill={corVar}
                            fillOpacity={0.15}
                            dot={false}
                        />
                    </AreaChart>
                </ChartContainer>
            </CardContent>
        </Card>
    );
}

function GraficoSkeleton() {
    return (
        <Card>
            <CardHeader>
                <Skeleton className="h-5 w-56" />
            </CardHeader>
            <CardContent>
                <Skeleton className="h-64 w-full" />
            </CardContent>
        </Card>
    );
}

export default function Dashboard({ periodo, metricas }: { periodo: number; metricas?: Metricas }) {
    function trocarPeriodo(novoPeriodo: number) {
        router.get(
            '/dashboard',
            { periodo: novoPeriodo },
            { preserveState: true, preserveScroll: true },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-lg font-semibold">Visão geral</h1>
                    <div className="flex gap-1 rounded-lg border p-1">
                        {PERIODOS.map((p) => (
                            <Button
                                key={p}
                                size="sm"
                                variant={p === periodo ? 'default' : 'ghost'}
                                onClick={() => trocarPeriodo(p)}
                            >
                                {p} dias
                            </Button>
                        ))}
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <CardTotal titulo="Processos no período" valor={metricas?.totais.processos} icone={FolderDown} />
                    <CardTotal titulo="Documentos baixados no período" valor={metricas?.totais.documentosBaixados} icone={FileDown} />
                    <CardTotal titulo="Documentos pendentes" valor={metricas?.totais.documentosPendentes} icone={Clock} />
                    <CardTotal titulo="Documentos com erro" valor={metricas?.totais.documentosErro} icone={AlertCircle} />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Deferred data="metricas" fallback={<><GraficoSkeleton /><GraficoSkeleton /></>}>
                        {metricas && (
                            <>
                                <GraficoSerie
                                    titulo="Processos baixados por dia"
                                    serie={metricas.processosPorDia}
                                    config={chartConfigProcessos}
                                    corVar="var(--chart-1)"
                                />
                                <GraficoSerie
                                    titulo="Documentos baixados por dia"
                                    serie={metricas.documentosPorDia}
                                    config={chartConfigDocumentos}
                                    corVar="var(--chart-2)"
                                />
                            </>
                        )}
                    </Deferred>
                </div>
            </div>
        </AppLayout>
    );
}
```

Nota: `<Deferred>` exige children como função ou elemento conforme a versão — se o typecheck reclamar de `{metricas && ...}`, seguir o padrão exato de `resources/js/pages/processos/show.tsx:187-210` (que já compila neste projeto).

- [ ] **Step 2: Typecheck**

Run: `npm run typecheck`
Expected: sem erros.

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: build Vite conclui sem erro (bundle inclui recharts).

- [ ] **Step 4: Commit**

```bash
git add resources/js/pages/dashboard.tsx
git commit -m "feat(dashboard): cards de totais e graficos de processos/documentos por dia

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: Verificação end-to-end + suíte completa

**Files:** nenhum novo (só verificação).

**Interfaces:**
- Consumes: tudo das Tasks 1-6; app em `http://localhost:8006` (container `php` já up); credenciais de login em `database/seeders/UserSeeder.php`.

- [ ] **Step 1: Suíte completa de testes**

Run: `docker compose exec php ./vendor/bin/pest`
Expected: apenas as **9 falhas pré-existentes** do domínio exportação (ExportacaoProcessoServiceTest, DownloadProcessoControllerTest, ExportacaoPipelineTest). Qualquer falha NOVA é regressão — investigar antes de seguir.

- [ ] **Step 2: Verificação visual no browser (Playwright MCP)**

1. Navegar para `http://localhost:8006/dashboard` (redireciona pro login).
2. Logar com as credenciais do `database/seeders/UserSeeder.php`.
3. Verificar: 4 cards com números, 2 gráficos renderizados, seletor com "30 dias" ativo.
4. Clicar "7 dias" → URL vira `?periodo=7`, gráficos recarregam com 7 pontos.
5. Alternar dark mode (menu do usuário → Settings → Appearance) e conferir cores dos gráficos.
6. Screenshot final light + dark.

Avisos do ambiente (memória do projeto): NÃO usar `page.setViewportSize` em `run_code` (corrompe o input pipeline — usar `browser_resize`); se um clique estourar timeout de 30s, recovery = `browser_close` + `browser_navigate`.

- [ ] **Step 3: Atualizar plano com checkboxes e commit final**

Marcar checkboxes concluídos neste arquivo e:

```bash
git add docs/superpowers/plans/2026-07-22-dashboard-metricas.md
git commit -m "docs: plano de metricas da dashboard executado

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```
