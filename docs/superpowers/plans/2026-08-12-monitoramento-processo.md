# Monitoramento periódico de processo via API — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que um cliente da API assine o monitoramento de um processo (`intervalo_horas`), com um command agendado a cada 30 min que enfileira os vencidos na fila serial `monitoramento` (1 worker), atualiza o processo via MNI (básicos + movimentos + documentos, sem binários) e dispara webhook com deltas a cada execução.

**Spec:** `docs/superpowers/specs/2026-08-12-monitoramento-processo-design.md`

**Architecture:** 3 tabelas novas (`credenciais_pje` cifrada, `processo_monitoramentos`, `processo_monitoramento_execucoes`), serviço de delta por snapshot de identificadores, job de execução `tries=1` na fila serial, job de webhook com retry em fila própria (`monitoramento-webhook`), command de despacho com `FOR UPDATE SKIP LOCKED` + `bloqueado_ate`, CRUD isolado por `api_token_id`.

**Tech Stack:** Laravel 11, Pest (`DatabaseTransactions`, banco Postgres real), Horizon, `CallbackNotifier`/`CallbackUrlValidator` existentes, `ProcessoService` existente.

## Global Constraints

- **Fila `monitoramento` é serial:** `ExecutarMonitoramentoProcessoJob` sempre `->onQueue('monitoramento')`; supervisor com `maxProcesses: 1` em todos os ambientes. Webhook sempre em `monitoramento-webhook`.
- **Monitoramento nunca baixa binário:** todo caminho iniciado pelo job passa `baixar_binarios: false`; default `true` preserva os chamadores atuais. Teste com `Queue::fake()` provando que `BaixarDocumentoMNIJob` não é despachado.
- **Isolamento por token:** toda query de monitoramento filtra `api_token_id`; uuid de outro token → `404`.
- **`store` NÃO usa `InjectCredenciaisPjePadrao`** (congelaria o par padrão do `.env` cifrado no banco). Rotas novas só com `ValidateApiToken`.
- Credencial cifrada: `assertDatabaseMissing` com o texto puro em todo teste que persiste credencial. Nada de `login_pje`/`senha_pje`/`callback_token` em log ou resposta.
- Testes: `php artisan test <arquivo>` (o wrapper `./php` está quebrado). Feature tests usam `uses(DatabaseTransactions::class)`. Baseline atual tem falhas herdadas no pipeline de exportação — comparar contra o baseline, não exigir suíte 100% verde.
- Commits com caminhos específicos (`git add <paths>`), nunca `git add -A`.
- Datas/horas de resposta em `toIso8601String()` (padrão do projeto).

---

### Task 1: Config, migrations, models e factories

**Files:**
- Modify: `config/pje.php` (bloco `monitoramento`)
- Create: `database/migrations/2026_08_12_000001_create_credenciais_pje_table.php`
- Create: `database/migrations/2026_08_12_000002_create_processo_monitoramentos_table.php`
- Create: `database/migrations/2026_08_12_000003_create_processo_monitoramento_execucoes_table.php`
- Create: `app/Models/CredencialPje.php`, `app/Models/ProcessoMonitoramento.php`, `app/Models/ProcessoMonitoramentoExecucao.php`
- Create: `database/factories/CredencialPjeFactory.php`, `database/factories/ProcessoMonitoramentoFactory.php`, `database/factories/ProcessoMonitoramentoExecucaoFactory.php`
- Test: `tests/Unit/Models/CredencialPjeTest.php`, `tests/Unit/Models/ProcessoMonitoramentoTest.php`

**Interfaces:**
- Consumes: `api_tokens`, `tribunais` (FKs).
- Produces: 3 tabelas; models com constantes de status, casts, relações e scopes (`doToken`, `vencidos`); factories com states `pausado()`, `suspenso()`, `vencido()`, `sucesso()`, `falha()`.

- [ ] **Step 1: Escrever os testes**

`tests/Unit/Models/CredencialPjeTest.php`:

```php
<?php

use App\Models\ApiToken;
use App\Models\CredencialPje;
use App\Models\Tribunal;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('cifra login e senha em repouso', function () {
    $c = CredencialPje::factory()->create(['login' => '12345678900', 'senha' => 'segredo-pje']);

    $this->assertDatabaseMissing('credenciais_pje', ['login' => '12345678900']);
    $this->assertDatabaseMissing('credenciais_pje', ['senha' => 'segredo-pje']);
    expect($c->fresh()->login)->toBe('12345678900')
        ->and($c->fresh()->senha)->toBe('segredo-pje');
});

it('gera uuid e login_mascarado', function () {
    $c = CredencialPje::factory()->create(['login' => '12345678900']);

    expect($c->uuid)->not->toBeNull()
        ->and($c->login_mascarado)->toBe('123*****900');
});

it('mascara login curto sem vazar conteúdo', function () {
    $c = CredencialPje::factory()->create(['login' => 'ab']);
    expect($c->login_mascarado)->toBe('******');
});
```

`tests/Unit/Models/ProcessoMonitoramentoTest.php`:

```php
<?php

use App\Models\ApiToken;
use App\Models\ProcessoMonitoramento;
use App\Models\ProcessoMonitoramentoExecucao;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('gera uuid ao criar e serializa datas como iso8601', function () {
    $m = ProcessoMonitoramento::factory()->create();
    expect($m->uuid)->not->toBeNull()
        ->and($m->proxima_execucao_em)->not->toBeNull();
});

it('scope vencidos pega só ativo, vencido e não bloqueado', function () {
    $vencido = ProcessoMonitoramento::factory()->vencido()->create();
    ProcessoMonitoramento::factory()->create(['proxima_execucao_em' => now()->addHour()]);
    ProcessoMonitoramento::factory()->vencido()->pausado()->create();
    ProcessoMonitoramento::factory()->vencido()->suspenso()->create();
    ProcessoMonitoramento::factory()->vencido()->create(['bloqueado_ate' => now()->addHour()]);
    $lockExpirado = ProcessoMonitoramento::factory()->vencido()->create(['bloqueado_ate' => now()->subMinute()]);

    $ids = ProcessoMonitoramento::vencidos()->pluck('id');
    expect($ids)->toContain($vencido->id)->toContain($lockExpirado->id)->toHaveCount(2);
});

it('scope doToken isola por api_token_id', function () {
    $meu = ProcessoMonitoramento::factory()->create();
    $outro = ProcessoMonitoramento::factory()->create();

    $ids = ProcessoMonitoramento::doToken($meu->api_token_id)->pluck('id');
    expect($ids)->toContain($meu->id)->not->toContain($outro->id);
});

it('execucoes relaciona e delta é json', function () {
    $e = ProcessoMonitoramentoExecucao::factory()->create(['delta' => ['movimentos' => []]]);
    expect($e->fresh()->delta)->toBeArray()
        ->and($e->monitoramento)->not->toBeNull();
});
```

- [ ] **Step 2: Rodar (deve falhar)** — `php artisan test tests/Unit/Models/CredencialPjeTest.php tests/Unit/Models/ProcessoMonitoramentoTest.php` → FAIL (tabelas/models não existem).

- [ ] **Step 3: Config**

Adicionar em `config/pje.php`, após `credenciais_padrao`:

```php
'monitoramento' => [
    'max_ativos_por_token' => env('PJE_MONITORAMENTO_MAX_ATIVOS_POR_TOKEN', 500),
    'intervalo_min_horas' => 1,
    'intervalo_max_horas' => 720,
    'max_falhas_consecutivas' => 5,
    'limite_itens_payload' => 500,
    'bloqueio_despacho_minutos' => 120,
],
```

- [ ] **Step 4: Migrations**

`..._000001_create_credenciais_pje_table.php`:

```php
Schema::create('credenciais_pje', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('api_token_id')->constrained('api_tokens');
    $table->foreignId('tribunal_id')->constrained('tribunais');
    $table->text('login');
    $table->text('senha');
    $table->char('login_hash', 64);
    $table->boolean('ativo')->default(true);
    $table->timestamps();

    $table->unique(['api_token_id', 'tribunal_id', 'login_hash']);
});
```

`..._000002_create_processo_monitoramentos_table.php`:

```php
Schema::create('processo_monitoramentos', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('api_token_id')->constrained('api_tokens');
    $table->foreignId('tribunal_id')->constrained('tribunais');
    $table->string('numero_processo', 25);
    $table->unsignedSmallInteger('intervalo_horas');
    $table->foreignId('credencial_id')->nullable()->constrained('credenciais_pje');
    $table->string('callback_url', 2048);
    $table->string('callback_token', 500);
    $table->string('status', 20)->default('ativo');
    $table->timestamp('proxima_execucao_em');
    $table->timestamp('ultima_execucao_em')->nullable();
    $table->string('data_referencia', 8)->nullable();
    $table->unsignedTinyInteger('falhas_consecutivas')->default(0);
    $table->timestamp('bloqueado_ate')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['status', 'proxima_execucao_em']);
});

// unique parcial (Postgres): 1 monitoramento vivo por (token, tribunal, processo)
DB::statement("CREATE UNIQUE INDEX processo_monitoramentos_ativo_unico
    ON processo_monitoramentos (api_token_id, tribunal_id, numero_processo)
    WHERE deleted_at IS NULL AND status <> 'cancelado'");
```

(`down()`: `dropIfExists`; o índice cai junto com a tabela.)

`..._000003_create_processo_monitoramento_execucoes_table.php`:

```php
Schema::create('processo_monitoramento_execucoes', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('monitoramento_id')->constrained('processo_monitoramentos')->cascadeOnDelete();
    $table->timestamp('iniciado_em');
    $table->timestamp('finalizado_em')->nullable();
    $table->string('status', 12);
    $table->boolean('houve_alteracao')->default(false);
    $table->unsignedInteger('movimentos_novos')->default(0);
    $table->unsignedInteger('documentos_novos')->default(0);
    $table->json('delta')->nullable();
    $table->text('erro_resumo')->nullable();
    $table->timestamp('webhook_enviado_em')->nullable();
    $table->unsignedTinyInteger('webhook_tentativas')->default(0);
    $table->unsignedSmallInteger('webhook_status_http')->nullable();
    $table->timestamps();

    $table->index(['monitoramento_id', 'created_at']);
});
```

- [ ] **Step 5: Models**

`app/Models/CredencialPje.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CredencialPje extends Model
{
    use HasFactory;

    protected $table = 'credenciais_pje';

    protected $fillable = ['api_token_id', 'tribunal_id', 'login', 'senha', 'login_hash', 'ativo'];

    protected $hidden = ['id', 'login', 'senha', 'login_hash', 'api_token_id'];

    protected $casts = [
        'login' => 'encrypted',
        'senha' => 'encrypted',
        'ativo' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public static function hashLogin(string $login): string
    {
        return hash('sha256', $login);
    }

    public function getLoginMascaradoAttribute(): string
    {
        $login = (string) $this->login;

        if (mb_strlen($login) < 8) {
            return '******';
        }

        return mb_substr($login, 0, 3) . str_repeat('*', mb_strlen($login) - 6) . mb_substr($login, -3);
    }
}
```

`app/Models/ProcessoMonitoramento.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ProcessoMonitoramento extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ATIVO = 'ativo';
    public const STATUS_PAUSADO = 'pausado';
    public const STATUS_SUSPENSO = 'suspenso';
    public const STATUS_CANCELADO = 'cancelado';

    protected $table = 'processo_monitoramentos';

    protected $fillable = [
        'api_token_id', 'tribunal_id', 'numero_processo', 'intervalo_horas',
        'credencial_id', 'callback_url', 'callback_token', 'status',
        'proxima_execucao_em', 'ultima_execucao_em', 'data_referencia',
        'falhas_consecutivas', 'bloqueado_ate',
    ];

    protected $hidden = ['id', 'api_token_id', 'credencial_id', 'callback_token', 'deleted_at'];

    protected $casts = [
        'proxima_execucao_em' => 'datetime',
        'ultima_execucao_em' => 'datetime',
        'bloqueado_ate' => 'datetime',
        'intervalo_horas' => 'integer',
        'falhas_consecutivas' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function tribunal()
    {
        return $this->belongsTo(Tribunal::class);
    }

    public function credencial()
    {
        return $this->belongsTo(CredencialPje::class, 'credencial_id');
    }

    public function execucoes()
    {
        return $this->hasMany(ProcessoMonitoramentoExecucao::class, 'monitoramento_id')
            ->orderBy('id', 'desc');
    }

    public function scopeDoToken(Builder $query, int $apiTokenId): Builder
    {
        return $query->where('api_token_id', $apiTokenId);
    }

    public function scopeVencidos(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ATIVO)
            ->where('proxima_execucao_em', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('bloqueado_ate')->orWhere('bloqueado_ate', '<', now()));
    }
}
```

`app/Models/ProcessoMonitoramentoExecucao.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProcessoMonitoramentoExecucao extends Model
{
    use HasFactory;

    public const STATUS_SUCESSO = 'sucesso';
    public const STATUS_FALHA = 'falha';

    protected $table = 'processo_monitoramento_execucoes';

    protected $fillable = [
        'monitoramento_id', 'iniciado_em', 'finalizado_em', 'status',
        'houve_alteracao', 'movimentos_novos', 'documentos_novos', 'delta',
        'erro_resumo', 'webhook_enviado_em', 'webhook_tentativas', 'webhook_status_http',
    ];

    protected $hidden = ['id', 'delta'];

    protected $casts = [
        'iniciado_em' => 'datetime',
        'finalizado_em' => 'datetime',
        'webhook_enviado_em' => 'datetime',
        'houve_alteracao' => 'boolean',
        'delta' => 'array',
        'movimentos_novos' => 'integer',
        'documentos_novos' => 'integer',
        'webhook_tentativas' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function monitoramento()
    {
        return $this->belongsTo(ProcessoMonitoramento::class, 'monitoramento_id');
    }

    public function jaFoiNotificado(): bool
    {
        return $this->webhook_enviado_em !== null;
    }
}
```

- [ ] **Step 6: Factories**

`CredencialPjeFactory`: `api_token_id => ApiToken::factory()`, `tribunal_id => Tribunal::factory()`, `login => fake()->numerify('###########')`, `senha => fake()->password(12)`, `login_hash` calculado no `configure()->afterMaking` via `CredencialPje::hashLogin($model->login)` (o login ainda está em claro no atributo antes de salvar).

`ProcessoMonitoramentoFactory`:

```php
public function definition(): array
{
    return [
        'api_token_id' => ApiToken::factory(),
        'tribunal_id' => Tribunal::factory(),
        'numero_processo' => fake()->unique()->numerify('####################'),
        'intervalo_horas' => 6,
        'credencial_id' => null,
        'callback_url' => 'https://cliente.exemplo.gov.br/webhook',
        'callback_token' => 'tok-' . fake()->sha1(),
        'status' => ProcessoMonitoramento::STATUS_ATIVO,
        'proxima_execucao_em' => now()->addHours(6),
        'bloqueado_ate' => null,
    ];
}

public function vencido(): static { return $this->state(['proxima_execucao_em' => now()->subMinute()]); }
public function pausado(): static { return $this->state(['status' => ProcessoMonitoramento::STATUS_PAUSADO]); }
public function suspenso(): static { return $this->state(['status' => ProcessoMonitoramento::STATUS_SUSPENSO]); }
```

`ProcessoMonitoramentoExecucaoFactory`: `monitoramento_id => ProcessoMonitoramento::factory()`, `iniciado_em/finalizado_em => now()`, `status => sucesso`, states `sucesso()` (com delta e contadores), `falha()` (`erro_resumo`), `webhookEnviado()` (`webhook_enviado_em => now()`).

- [ ] **Step 7: Migrar e rodar (deve passar)** — `php artisan migrate && php artisan test tests/Unit/Models/CredencialPjeTest.php tests/Unit/Models/ProcessoMonitoramentoTest.php` → PASS.

- [ ] **Step 8: Commit** — `feat(monitoramento): tabelas, models e factories do monitoramento de processo`

---

### Task 2: ValidateApiToken expõe o token + CredencialPjeService

**Files:**
- Modify: `app/Http/Middleware/ValidateApiToken.php` (expor o token resolvido)
- Create: `app/Services/Monitoramento/CredencialPjeService.php`
- Test: `tests/Unit/Services/Monitoramento/CredencialPjeServiceTest.php`, caso novo em `tests/Feature/Api/ValidateApiTokenTest.php`

**Interfaces:**
- Consumes: `CredencialPje` (Task 1).
- Produces: `$request->attributes->get('apiToken'): ApiToken` disponível após o middleware; `CredencialPjeService::resolver(ApiToken $token, Tribunal $tribunal, ?string $login, ?string $senha): ?CredencialPje`.

- [ ] **Step 1: Escrever os testes**

`tests/Unit/Services/Monitoramento/CredencialPjeServiceTest.php` (com `DatabaseTransactions`):

```php
it('retorna null sem par completo (regra atômica)', function () {
    $s = new CredencialPjeService();
    expect($s->resolver($this->token, $this->tribunal, null, null))->toBeNull()
        ->and($s->resolver($this->token, $this->tribunal, 'so-login', null))->toBeNull()
        ->and($s->resolver($this->token, $this->tribunal, null, 'so-senha'))->toBeNull();
});

it('cria credencial cifrada com login_hash', function () { /* resolver com par → registro criado; assertDatabaseMissing texto puro; login_hash = CredencialPje::hashLogin */ });

it('reusa credencial existente do mesmo (token, tribunal, login)', function () { /* 2 chamadas → 1 registro, mesmo id */ });

it('atualiza a senha quando muda para o mesmo login', function () { /* 2ª chamada com senha nova → mesmo id, senha atualizada */ });

it('não reusa credencial de outro token', function () { /* mesmo login/tribunal, tokens diferentes → 2 registros */ });
```

Caso novo em `tests/Feature/Api/ValidateApiTokenTest.php`: request autenticada → `$request->attributes->get('apiToken')` é o `ApiToken` correto (rota de teste inline ou assert indireto via endpoint existente).

- [ ] **Step 2: Rodar (deve falhar)**

- [ ] **Step 3: Implementar**

`ValidateApiToken`: após `$apiToken->registrarUso();` adicionar `$request->attributes->set('apiToken', $apiToken);`.

`CredencialPjeService`:

```php
<?php

namespace App\Services\Monitoramento;

use App\Models\ApiToken;
use App\Models\CredencialPje;
use App\Models\Tribunal;

class CredencialPjeService
{
    /**
     * Par incompleto é descartado inteiro (mesma regra atômica do
     * InjectCredenciaisPjePadrao): sem par completo → null → o job usa o
     * par padrão do .env na hora da execução.
     */
    public function resolver(ApiToken $token, Tribunal $tribunal, ?string $login, ?string $senha): ?CredencialPje
    {
        if (blank($login) || blank($senha)) {
            return null;
        }

        $credencial = CredencialPje::query()
            ->where('api_token_id', $token->id)
            ->where('tribunal_id', $tribunal->id)
            ->where('login_hash', CredencialPje::hashLogin($login))
            ->first();

        if ($credencial) {
            if ($credencial->senha !== $senha) {
                $credencial->update(['senha' => $senha]);
            }

            return $credencial;
        }

        return CredencialPje::create([
            'api_token_id' => $token->id,
            'tribunal_id' => $tribunal->id,
            'login' => $login,
            'senha' => $senha,
            'login_hash' => CredencialPje::hashLogin($login),
        ]);
    }
}
```

- [ ] **Step 4: Rodar (deve passar)**

- [ ] **Step 5: Commit** — `feat(monitoramento): resolver de credenciais PJe cifradas por token/tribunal`

---

### Task 3: DetectarAlteracoesProcessoService (snapshot/delta)

**Files:**
- Create: `app/Services/Processo/DetectarAlteracoesProcessoService.php`
- Test: `tests/Unit/Services/Processo/DetectarAlteracoesProcessoServiceTest.php`

**Interfaces:**
- Consumes: `Processo`, `ProcessoMovimento`, `ProcessoDocumento`.
- Produces:
  - `snapshot(?Processo $processo): array` — `['existia' => bool, 'movimentos' => string[], 'documentos' => string[]]` (identificadores).
  - `delta(Processo $processo, array $snapshot): array` — `['primeira_execucao' => bool, 'houve_alteracao' => bool, 'movimentos_novos' => int, 'documentos_novos' => int, 'truncado' => bool, 'movimentos' => array, 'documentos' => array]`, listas já no formato do payload, cap `config('pje.monitoramento.limite_itens_payload')`.

- [ ] **Step 1: Escrever os testes** (com `DatabaseTransactions`; criar `Processo` via factory e movimentos/documentos via `DB`/models diretos)

Casos:
1. processo `null` no snapshot → `existia=false`; delta pós-consulta marca `primeira_execucao=true` e lista tudo;
2. snapshot com 2 movimentos, +1 novo depois → delta só com o novo, `houve_alteracao=true`, contadores certos, formato do item (`identificador_movimento`, `codigo_nacional`, `complemento`, `data_hora` iso8601);
3. documento novo → item com `id_documento`, `descricao`, `tipo_documento`, `mimetype`, `data_hora`, `nivel_sigilo`;
4. nada novo → `houve_alteracao=false`, listas vazias;
5. mais itens novos que `limite_itens_payload` (usar `config()->set('pje.monitoramento.limite_itens_payload', 2)`) → lista com 2, `truncado=true`, contadores com o total real.

- [ ] **Step 2: Rodar (deve falhar)**

- [ ] **Step 3: Implementar**

```php
<?php

namespace App\Services\Processo;

use App\Models\Processo;

class DetectarAlteracoesProcessoService
{
    public function snapshot(?Processo $processo): array
    {
        if (! $processo) {
            return ['existia' => false, 'movimentos' => [], 'documentos' => []];
        }

        return [
            'existia' => true,
            'movimentos' => $processo->movimentos()->pluck('identificador_movimento')->all(),
            'documentos' => $processo->documentos()->pluck('id_documento')->all(),
        ];
    }

    public function delta(Processo $processo, array $snapshot): array
    {
        $limite = (int) config('pje.monitoramento.limite_itens_payload');

        $movimentos = $processo->movimentos()
            ->whereNotIn('identificador_movimento', $snapshot['movimentos'])
            ->get(['identificador_movimento', 'codigo_nacional', 'complemento', 'data_hora']);

        $documentos = $processo->documentos()
            ->whereNotIn('id_documento', $snapshot['documentos'])
            ->get(['id_documento', 'descricao', 'tipo_documento', 'mimetype', 'data_hora', 'nivel_sigilo']);

        return [
            'primeira_execucao' => ! $snapshot['existia'],
            'houve_alteracao' => $movimentos->isNotEmpty() || $documentos->isNotEmpty(),
            'movimentos_novos' => $movimentos->count(),
            'documentos_novos' => $documentos->count(),
            'truncado' => $movimentos->count() > $limite || $documentos->count() > $limite,
            'movimentos' => $movimentos->take($limite)->map(fn ($m) => [
                'identificador_movimento' => $m->identificador_movimento,
                'codigo_nacional' => $m->codigo_nacional,
                'complemento' => $m->complemento,
                'data_hora' => optional($m->data_hora ? \Carbon\Carbon::parse($m->data_hora) : null)->toIso8601String(),
            ])->values()->all(),
            'documentos' => $documentos->take($limite)->map(fn ($d) => [
                'id_documento' => $d->id_documento,
                'descricao' => $d->descricao,
                'tipo_documento' => $d->tipo_documento,
                'mimetype' => $d->mimetype,
                'data_hora' => optional($d->data_hora ? \Carbon\Carbon::parse($d->data_hora) : null)->toIso8601String(),
                'nivel_sigilo' => $d->nivel_sigilo,
            ])->values()->all(),
        ];
    }
}
```

(Conferir os casts reais de `data_hora` nos models `ProcessoMovimento`/`ProcessoDocumento` e simplificar o `parse` se já forem `datetime`.)

- [ ] **Step 4: Rodar (deve passar)**

- [ ] **Step 5: Commit** — `feat(monitoramento): serviço de snapshot/delta de movimentos e documentos`

---

### Task 4: Flag baixar_binarios (metadados sem download)

**Files:**
- Modify: `app/Services/Processo/SalvarDocumentoProcessoService.php` (`execute()` e `salvarDocumento()`)
- Modify: `app/Services/Processo/ProcessoService.php` (`consultarNumero()`)
- Test: `tests/Feature/Jobs/SalvarDocumentoSemBinarioTest.php`

**Interfaces:**
- Consumes: comportamento atual (dispatch incondicional de `BaixarDocumentoMNIJob` para documento não baixado, `SalvarDocumentoProcessoService.php:57`).
- Produces: `SalvarDocumentoProcessoService::execute($processo, $documentos, $login_pje = null, $senha_pje = null, bool $baixar_binarios = true)`; idem `salvarDocumento(...)`; `ProcessoService::consultarNumero($tribunal, $numero_processo, $login = null, $senha = null, $data_referencia = null, bool $baixar_binarios = true)` propagando a flag. **Default `true` em tudo — nenhum chamador atual muda de comportamento.**

- [ ] **Step 1: Escrever o teste** — com `Queue::fake()` e um documento stub (objeto `stdClass` com `idDocumento`, `mimetype` etc.):
1. `salvarDocumento(..., baixar_binarios: false)` → documento persistido via `updateOrCreate`, `Queue::assertNotPushed(BaixarDocumentoMNIJob::class)`;
2. default (sem o argumento) → `Queue::assertPushed(BaixarDocumentoMNIJob::class)` na fila `mni-download` (trava a retrocompatibilidade);
3. documento com `documentoVinculado` e flag `false` → nenhum push (a flag propaga para os vinculados).

- [ ] **Step 2: Rodar (deve falhar)** — o caso 1 falha (push acontece hoje).

- [ ] **Step 3: Implementar** — adicionar o parâmetro nas 2 assinaturas de `SalvarDocumentoProcessoService`, propagar na recursão dos vinculados e condicionar o dispatch:

```php
if ($baixar_binarios && $processoDocumento->status != ProcessoDocumento::STATUS_BAIXADO) {
    BaixarDocumentoMNIJob::dispatch($processoDocumento, $login_pje, $senha_pje)->onQueue('mni-download');
}
```

Em `ProcessoService::consultarNumero`, adicionar `bool $baixar_binarios = true` como último parâmetro e repassar no `salvarDocumentoProcessoService->execute(...)`.

- [ ] **Step 4: Rodar (deve passar)** e rodar também `php artisan test tests/Feature/Jobs/BaixarProcessoMNIJobTest.php` (regressão do caminho default).

- [ ] **Step 5: Commit** — `feat(processo): flag baixar_binarios para salvar documentos só com metadados`

---

### Task 5: CallbackNotifier com headers extras

**Files:**
- Modify: `app/Services/Callback/CallbackNotifier.php`
- Test: `tests/Unit/Services/Callback/CallbackNotifierTest.php` (casos novos)

**Interfaces:**
- Consumes: assinatura atual `notificar(string $url, string $token, array $payload): void`.
- Produces: `notificar(string $url, string $token, array $payload, array $headers = []): int` — merge dos headers extras com `X-API-Token` e retorno do status HTTP em sucesso. Chamadores atuais (exportação, jobs async) não passam o 4º argumento e ignoram o retorno — sem breaking change.

- [ ] **Step 1: Testes** (`Http::fake()`): headers extras chegam na requisição junto do `X-API-Token`; retorno é o status (ex. `201`); comportamento 4xx/5xx inalterado (casos existentes seguem passando).

- [ ] **Step 2: Rodar (deve falhar)**

- [ ] **Step 3: Implementar**

```php
public function notificar(string $url, string $token, array $payload, array $headers = []): int
{
    app(CallbackUrlValidator::class)->assertValida($url);

    $response = Http::withHeaders(array_merge($headers, ['X-API-Token' => $token]))
        ->timeout(10)
        ->post($url, $payload);

    if ($response->successful()) {
        return $response->status();
    }
    // ... 4xx/5xx inalterado
}
```

- [ ] **Step 4: Rodar (deve passar)** + regressão `php artisan test tests/Feature/Jobs/EnviarWebhookDownloadJobTest.php`.

- [ ] **Step 5: Commit** — `feat(callback): headers extras e status de retorno no CallbackNotifier`

---

### Task 6: ExecutarMonitoramentoProcessoJob

**Files:**
- Create: `app/Jobs/ExecutarMonitoramentoProcessoJob.php`
- Test: `tests/Feature/Jobs/ExecutarMonitoramentoProcessoJobTest.php`

**Interfaces:**
- Consumes: `ProcessoService::consultarNumero` (mockado nos testes via `$this->mock(ProcessoService::class)`), `DetectarAlteracoesProcessoService`, `CredencialPje`, config `pje.credenciais_padrao` e `pje.monitoramento.max_falhas_consecutivas`.
- Produces: execução persistida + `EnviarWebhookMonitoramentoJob::dispatch($execucaoId)->onQueue('monitoramento-webhook')` nos dois caminhos.

- [ ] **Step 1: Escrever os testes** (`DatabaseTransactions`, `Queue::fake()` só para o webhook — o job em si roda inline):

1. **sucesso com delta:** monitoramento + processo pré-existente com 1 movimento; mock de `consultarNumero` insere +1 movimento e retorna o processo, e o teste assevera que recebeu `baixar_binarios === false` e as credenciais esperadas; resultado: execução `sucesso` com `movimentos_novos=1`, `houve_alteracao=true`, monitoramento com `ultima_execucao_em` set, `falhas_consecutivas=0`, `bloqueado_ate=null`, `data_referencia = now()->format('Ymd')`; `Queue::assertPushed(EnviarWebhookMonitoramentoJob::class)` na fila `monitoramento-webhook`;
2. **primeira execução:** sem `Processo` no banco antes; mock cria o processo → execução com `primeira_execucao=true` no delta;
3. **data_referencia:** monitoramento com `data_referencia='20260810'` → mock recebe `'2026-08-09'` (véspera); monitoramento sem `data_referencia` → mock recebe `null`;
4. **credencial própria:** monitoramento com `credencial_id` → mock recebe o login/senha decifrados; sem credencial e com `config(['pje.credenciais_padrao' => [...]])` → recebe o par do config; sem nenhum → recebe `null/null`;
5. **falha:** mock lança `MNIException` → execução `falha` com `erro_resumo`, `falhas_consecutivas` incrementado, `bloqueado_ate=null`, webhook despachado mesmo assim;
6. **suspensão:** monitoramento com `falhas_consecutivas=4` + mock falhando → `status=suspenso`;
7. **monitoramento sumiu:** id inexistente → retorna sem lançar e sem webhook.

- [ ] **Step 2: Rodar (deve falhar)**

- [ ] **Step 3: Implementar**

```php
<?php

namespace App\Jobs;

use App\Models\Processo;
use App\Models\ProcessoMonitoramento;
use App\Models\ProcessoMonitoramentoExecucao;
use App\Services\Processo\DetectarAlteracoesProcessoService;
use App\Services\Processo\ProcessoService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExecutarMonitoramentoProcessoJob implements ShouldQueue
{
    use Queueable;

    /**
     * Sem retry de fila: o reagendamento é responsabilidade do despachador.
     * Retry cego aqui multiplicaria consultas ao tribunal.
     */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $monitoramentoId) {}

    public function handle(): void
    {
        $monitoramento = ProcessoMonitoramento::with('credencial', 'tribunal')->find($this->monitoramentoId);

        if (! $monitoramento) {
            Log::warning("[Monitoramento:{$this->monitoramentoId}] não encontrado ao executar");
            return;
        }

        $iniciadoEm = now();
        $detector = app(DetectarAlteracoesProcessoService::class);

        $processoAntes = Processo::where('numero_processo', $monitoramento->numero_processo)
            ->where('tribunal_id', $monitoramento->tribunal_id)
            ->first();
        $snapshot = $detector->snapshot($processoAntes);

        [$login, $senha] = $this->resolverCredenciais($monitoramento);

        try {
            $processo = app(ProcessoService::class)->consultarNumero(
                $monitoramento->tribunal,
                $monitoramento->numero_processo,
                $login,
                $senha,
                $this->dataReferencia($monitoramento),
                baixar_binarios: false,
            );

            $delta = $detector->delta($processo, $snapshot);

            $execucao = ProcessoMonitoramentoExecucao::create([
                'monitoramento_id' => $monitoramento->id,
                'iniciado_em' => $iniciadoEm,
                'finalizado_em' => now(),
                'status' => ProcessoMonitoramentoExecucao::STATUS_SUCESSO,
                'houve_alteracao' => $delta['houve_alteracao'],
                'movimentos_novos' => $delta['movimentos_novos'],
                'documentos_novos' => $delta['documentos_novos'],
                'delta' => $delta,
            ]);

            $monitoramento->update([
                'ultima_execucao_em' => now(),
                'data_referencia' => now()->format('Ymd'),
                'falhas_consecutivas' => 0,
                'bloqueado_ate' => null,
            ]);
        } catch (\Throwable $e) {
            $execucao = $this->registrarFalha($monitoramento, $iniciadoEm, $e);
        }

        EnviarWebhookMonitoramentoJob::dispatch($execucao->id)->onQueue('monitoramento-webhook');
    }

    private function resolverCredenciais(ProcessoMonitoramento $m): array
    {
        if ($m->credencial) {
            return [$m->credencial->login, $m->credencial->senha];
        }

        $login = config('pje.credenciais_padrao.login');
        $senha = config('pje.credenciais_padrao.senha');

        return filled($login) && filled($senha) ? [$login, $senha] : [null, null];
    }

    private function dataReferencia(ProcessoMonitoramento $m): ?string
    {
        // véspera da última execução bem-sucedida: o MNI trunca dataReferencia
        // para o dia, a véspera garante não perder o próprio dia da execução.
        return $m->data_referencia
            ? Carbon::createFromFormat('Ymd', $m->data_referencia)->subDay()->format('Y-m-d')
            : null;
    }

    private function registrarFalha(ProcessoMonitoramento $m, Carbon $iniciadoEm, \Throwable $e): ProcessoMonitoramentoExecucao
    {
        Log::error("[Monitoramento:{$m->id}] falha na consulta", ['erro' => $e->getMessage()]);

        $execucao = ProcessoMonitoramentoExecucao::create([
            'monitoramento_id' => $m->id,
            'iniciado_em' => $iniciadoEm,
            'finalizado_em' => now(),
            'status' => ProcessoMonitoramentoExecucao::STATUS_FALHA,
            'erro_resumo' => mb_substr($e->getMessage(), 0, 1000),
        ]);

        $falhas = $m->falhas_consecutivas + 1;
        $suspender = $falhas >= (int) config('pje.monitoramento.max_falhas_consecutivas');

        $m->update([
            'falhas_consecutivas' => $falhas,
            'bloqueado_ate' => null,
            'status' => $suspender ? ProcessoMonitoramento::STATUS_SUSPENSO : $m->status,
        ]);

        return $execucao;
    }
}
```

Nota: `MNIException::getError()` existe; usar `$e instanceof MNIException ? $e->getError() : $e->getMessage()` no `erro_resumo` se o `getMessage()` vier vazio nos testes.

- [ ] **Step 4: Rodar (deve passar)**

- [ ] **Step 5: Commit** — `feat(monitoramento): job de execução serial da consulta com delta e suspensão`

---

### Task 7: EnviarWebhookMonitoramentoJob

**Files:**
- Create: `app/Jobs/EnviarWebhookMonitoramentoJob.php`
- Test: `tests/Feature/Jobs/EnviarWebhookMonitoramentoJobTest.php`

**Interfaces:**
- Consumes: `ProcessoMonitoramentoExecucao` (+ `monitoramento`), `CallbackNotifier` estendido (Task 5).
- Produces: POST no callback com headers `X-API-Token`, `X-Evento: processo.monitoramento.executado`, `X-Idempotency-Key: {execucao_uuid}`; `webhook_enviado_em`/`webhook_status_http` preenchidos.

- [ ] **Step 1: Escrever os testes** (`Http::fake()`, `DatabaseTransactions`) — espelhar `EnviarWebhookDownloadJobTest`:

1. sucesso (2xx): payload conforme spec (evento, uuids, `houve_alteracao`, `resumo`, listas, `proxima_execucao_em`), headers corretos, `webhook_enviado_em` set, `webhook_status_http=200`;
2. execução `falha`: payload com `erro_resumo`, `falhas_consecutivas`, `monitoramento_status`;
3. 4xx: job faila permanente (sem retry), `webhook_status_http` gravado, `webhook_enviado_em` null;
4. 5xx: exceção propaga (fila retenta), tentativas incrementadas;
5. já notificado (`webhookEnviado()`): nenhum request HTTP;
6. execução inexistente: retorna sem lançar.

- [ ] **Step 2: Rodar (deve falhar)**

- [ ] **Step 3: Implementar** — cópia estrutural de `EnviarWebhookDownloadJob` (`tries=5`, `backoff [10,60,300,900,3600]`, transação com `lockForUpdate` + increment de tentativas):

```php
public function handle(CallbackNotifier $notifier): void
{
    // transação lockForUpdate + jaFoiNotificado() + increment, igual EnviarWebhookDownloadJob

    $monitoramento = $execucao->monitoramento()->withTrashed()->first();

    try {
        $status = $notifier->notificar(
            $monitoramento->callback_url,
            $monitoramento->callback_token,
            $this->montarPayload($execucao, $monitoramento),
            [
                'X-Evento' => 'processo.monitoramento.executado',
                'X-Idempotency-Key' => $execucao->uuid,
            ],
        );
    } catch (CallbackPermanentException $e) {
        $execucao->update(['webhook_status_http' => $e->statusCode]);
        Log::critical("[MonitoramentoExecucao:{$execucao->id}] callback rejeitado (permanente)", [...]);
        $this->fail($e);
        return;
    }

    $execucao->update(['webhook_enviado_em' => now(), 'webhook_status_http' => $status]);
}

private function montarPayload($execucao, $monitoramento): array
{
    $base = [
        'evento' => 'processo.monitoramento.executado',
        'monitoramento_id' => $monitoramento->uuid,
        'execucao_id' => $execucao->uuid,
        'numero_processo' => $monitoramento->numero_processo,
        'tribunal_id' => $monitoramento->tribunal_id,
        'executado_em' => $execucao->finalizado_em?->toIso8601String(),
        'status' => $execucao->status,
        'houve_alteracao' => $execucao->houve_alteracao,
    ];

    if ($execucao->status === ProcessoMonitoramentoExecucao::STATUS_FALHA) {
        return $base + [
            'erro_resumo' => $execucao->erro_resumo,
            'falhas_consecutivas' => $monitoramento->falhas_consecutivas,
            'monitoramento_status' => $monitoramento->status,
        ];
    }

    $delta = $execucao->delta ?? [];

    return $base + [
        'primeira_execucao' => $delta['primeira_execucao'] ?? false,
        'resumo' => [
            'movimentos_novos' => $execucao->movimentos_novos,
            'documentos_novos' => $execucao->documentos_novos,
            'truncado' => $delta['truncado'] ?? false,
        ],
        'movimentos' => $delta['movimentos'] ?? [],
        'documentos' => $delta['documentos'] ?? [],
        'proxima_execucao_em' => $monitoramento->proxima_execucao_em?->toIso8601String(),
    ];
}
```

- [ ] **Step 4: Rodar (deve passar)**

- [ ] **Step 5: Commit** — `feat(monitoramento): job de webhook com retry, idempotência e headers de evento`

---

### Task 8: Commands de despacho/limpeza/reenvio + schedule + Horizon

**Files:**
- Create: `app/Console/Commands/MonitoramentosDespachar.php`
- Create: `app/Console/Commands/MonitoramentosLimparExecucoes.php`
- Create: `app/Console/Commands/MonitoramentosReenviarWebhook.php`
- Modify: `routes/console.php` (2 entradas no schedule)
- Modify: `config/horizon.php` (2 supervisores em `defaults` + `environments.production`/`local`)
- Test: `tests/Feature/Console/MonitoramentosDespacharTest.php`, `tests/Feature/Console/MonitoramentosLimparExecucoesTest.php`

**Interfaces:**
- Consumes: `ProcessoMonitoramento::vencidos()`, jobs das Tasks 6–7.
- Produces: `monitoramentos:despachar` (a cada 30 min), `monitoramentos:limpar-execucoes {--dias=90}` (diário 04:30), `monitoramentos:reenviar-webhook {execucao_id?} {--reset-tentativas} {--force}` (manual, espelho de `exportacoes:reenviar-webhook`).

- [ ] **Step 1: Escrever os testes**

`MonitoramentosDespacharTest` (`Queue::fake()`):
1. despacha só os vencidos/ativos (mistura com futuro, pausado, suspenso, bloqueado) na fila `monitoramento`;
2. reagenda `proxima_execucao_em ≈ now() + intervalo_horas` e seta `bloqueado_ate ≈ now() + 120min` (asserts com tolerância de minutos);
3. segunda rodada imediata não re-despacha ninguém (lock + reagendamento);
4. lock expirado (`bloqueado_ate < now()` e `proxima_execucao_em <= now()`) → re-despacha (recuperação de job perdido);
5. saída informa o total despachado.

`MonitoramentosLimparExecucoesTest`: execuções com `created_at` 100 dias atrás somem, recentes ficam; `--dias` customizável.

- [ ] **Step 2: Rodar (deve falhar)**

- [ ] **Step 3: Implementar**

`MonitoramentosDespachar`:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\ExecutarMonitoramentoProcessoJob;
use App\Models\ProcessoMonitoramento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonitoramentosDespachar extends Command
{
    protected $signature = 'monitoramentos:despachar';

    protected $description = 'Enfileira na fila serial os monitoramentos vencidos (roda a cada 30 min)';

    private const CHUNK = 200;

    public function handle(): int
    {
        $despachados = 0;

        do {
            $ids = DB::transaction(function () {
                $vencidos = ProcessoMonitoramento::query()
                    ->vencidos()
                    ->orderBy('proxima_execucao_em')
                    ->limit(self::CHUNK)
                    ->lock('for update skip locked')
                    ->get();

                foreach ($vencidos as $monitoramento) {
                    $monitoramento->update([
                        'bloqueado_ate' => now()->addMinutes((int) config('pje.monitoramento.bloqueio_despacho_minutos')),
                        'proxima_execucao_em' => now()->addHours($monitoramento->intervalo_horas),
                    ]);
                }

                return $vencidos->pluck('id');
            });

            foreach ($ids as $id) {
                ExecutarMonitoramentoProcessoJob::dispatch($id)->onQueue('monitoramento');
            }

            $despachados += $ids->count();
        } while ($ids->count() === self::CHUNK);

        $this->info("{$despachados} monitoramento(s) despachado(s).");
        Log::info("[Monitoramento] tick de despacho: {$despachados} enfileirado(s)");

        return self::SUCCESS;
    }
}
```

`MonitoramentosLimparExecucoes`: delete em chunk de `processo_monitoramento_execucoes` com `created_at < now()->subDays($dias)`.

`MonitoramentosReenviarWebhook`: espelho de `ExportacoesReenviarWebhook` trocando model/job/fila (`monitoramento-webhook`) e o filtro de pendentes (`whereNull('webhook_enviado_em')` + `created_at <= now()->subHour()`).

`routes/console.php`:

```php
Schedule::command('monitoramentos:despachar')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('monitoramentos:limpar-execucoes')->dailyAt('04:30');
```

`config/horizon.php` — em `defaults`:

```php
'supervisor-monitoramento' => [
    'connection' => 'redis',
    'queue' => ['monitoramento'],
    'balance' => false,              // sem autoscaling: serialização é contrato
    'minProcesses' => 1,
    'maxProcesses' => 1,
    'maxTime' => 3600,
    'maxJobs' => 0,
    'memory' => 512,
    'tries' => 1,
    'timeout' => 660,                // > timeout do job (600)
    'nice' => 0,
],

'supervisor-monitoramento-webhook' => [
    'connection' => 'redis',
    'queue' => ['monitoramento-webhook'],
    'balance' => 'simple',
    'autoScalingStrategy' => 'time',
    'minProcesses' => 1,
    'maxProcesses' => 2,
    'balanceMaxShift' => 1,
    'balanceCooldown' => 3,
    'maxTime' => 3600,
    'maxJobs' => 0,
    'memory' => 512,
    'tries' => 3,
    'timeout' => 60,
    'nice' => 0,
],
```

Em `environments.production` e `environments.local`: `supervisor-monitoramento => ['maxProcesses' => 1]` (explícito nos dois — ninguém "escala" isso por engano), `supervisor-monitoramento-webhook => ['maxProcesses' => 2]`.

- [ ] **Step 4: Rodar (deve passar)**

- [ ] **Step 5: Commit** — `feat(monitoramento): despacho agendado 30min, fila serial no horizon e comandos de manutenção`

---

### Task 9: API — FormRequests, Controller, rotas

**Files:**
- Create: `app/Http/Requests/Api/CriarMonitoramentoProcessoRequest.php`, `app/Http/Requests/Api/AtualizarMonitoramentoProcessoRequest.php`
- Create: `app/Http/Resources/Monitoramento/MonitoramentoResource.php`, `app/Http/Resources/Monitoramento/MonitoramentoExecucaoResource.php`
- Create: `app/Http/Controllers/Api/MonitoramentoProcessoController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/MonitoramentoProcessoControllerTest.php`

**Interfaces:**
- Consumes: `CredencialPjeService` (Task 2), models (Task 1), `ExecutarMonitoramentoProcessoJob` (Task 6), `$request->attributes->get('apiToken')`.
- Produces: as 7 rotas do contrato da spec sob `/api/processo/monitoramentos`.

- [ ] **Step 1: Escrever os testes** (helper local `headers($token)` → `['X-API-Token' => $plain]`; criar `ApiToken` guardando o plain antes do hash, ver `ValidateApiTokenTest` existente):

1. `401` sem token em todas as rotas;
2. `store` 201: cria com credencial → registro em `credenciais_pje` cifrado (`assertDatabaseMissing` texto puro), resposta com `uuid`, `status=ativo`, `proxima_execucao_em` ≈ agora, `credencial.login_mascarado`, **sem** `callback_token`/`login`/`senha`/`id` numérico;
3. `store` sem credenciais → `credencial_id` null, resposta com `credencial: null`;
4. `422`: `intervalo_horas` 0 / 721 / ausente; `callback_url` http, IP interno, ausente; `tribunal_id` inexistente ou inativo; `login_pje` sem `senha_pje` (par atômico);
5. `409` duplicado: segundo `store` de (token, tribunal, processo) com monitoramento vivo → `{error, uuid}` do existente; após `DELETE`, recriar → `201`;
6. `422` ao estourar `max_ativos_por_token` (com `config()->set(..., 2)`);
7. `index`: pagina, filtra `?status=`, **só do token** (criar registro de outro token e provar ausência);
8. `show`: detalhe + últimas execuções; uuid de outro token → `404`; uuid inexistente → `404`;
9. `update`: muda `intervalo_horas`/`callback_url`/`callback_token`; `status: pausado` pausa; `status: ativo` em suspenso → zera `falhas_consecutivas` e `proxima_execucao_em` ≈ agora; `status: cancelado` no PATCH → `422` (cancelar é o DELETE);
10. `destroy`: `204`, `status=cancelado` + soft delete; `GET` seguinte → `404`;
11. `executar` (`Queue::fake()`): `202` + `ExecutarMonitoramentoProcessoJob` na fila `monitoramento` com o id certo, `proxima_execucao_em` intacta;
12. `execucoes`: histórico paginado do monitoramento, isolado por token.

- [ ] **Step 2: Rodar (deve falhar)**

- [ ] **Step 3: Implementar**

`CriarMonitoramentoProcessoRequest` (padrão de `CriarExportacaoProcessoRequest`, incluindo `prepareForValidation` com `cleanNumeroProcesso`):

```php
public function rules(): array
{
    return [
        'numero_processo' => ['required', 'string', 'max:25'],
        'tribunal_id' => ['required', 'integer', Rule::exists('tribunais', 'id')->where('ativo', true)],
        'intervalo_horas' => ['required', 'integer',
            'min:' . config('pje.monitoramento.intervalo_min_horas'),
            'max:' . config('pje.monitoramento.intervalo_max_horas')],
        'callback_url' => ['required', 'string', 'max:2048', new \App\Rules\CallbackUrl],
        'callback_token' => ['required', 'string', 'max:500'],
        'login_pje' => ['nullable', 'string', 'max:255', 'required_with:senha_pje'],
        'senha_pje' => ['nullable', 'string', 'max:255', 'required_with:login_pje'],
    ];
}
```

`AtualizarMonitoramentoProcessoRequest`: todos `sometimes` (`intervalo_horas`, `callback_url`, `callback_token`, `status` com `Rule::in(['ativo', 'pausado'])`).

`MonitoramentoResource`:

```php
public function toArray($request): array
{
    return [
        'uuid' => $this->uuid,
        'numero_processo' => $this->numero_processo,
        'tribunal_id' => $this->tribunal_id,
        'intervalo_horas' => $this->intervalo_horas,
        'status' => $this->status,
        'callback_url' => $this->callback_url,
        'proxima_execucao_em' => $this->proxima_execucao_em?->toIso8601String(),
        'ultima_execucao_em' => $this->ultima_execucao_em?->toIso8601String(),
        'falhas_consecutivas' => $this->falhas_consecutivas,
        'credencial' => $this->credencial
            ? ['uuid' => $this->credencial->uuid, 'login_mascarado' => $this->credencial->login_mascarado]
            : null,
        'created_at' => $this->created_at,
    ];
}
```

`MonitoramentoExecucaoResource`: uuid, status, `houve_alteracao`, contadores, `erro_resumo`, `iniciado_em`/`finalizado_em`, `webhook_enviado_em`, `webhook_status_http`.

`MonitoramentoProcessoController` (esqueleto):

```php
class MonitoramentoProcessoController extends Controller
{
    private const DEFAULT_PER_PAGE = 10;

    public function store(CriarMonitoramentoProcessoRequest $request): JsonResponse
    {
        $token = $request->attributes->get('apiToken');
        $tribunal = Tribunal::findOrFail($request->tribunal_id);

        $ativos = ProcessoMonitoramento::doToken($token->id)
            ->whereIn('status', [ProcessoMonitoramento::STATUS_ATIVO, ProcessoMonitoramento::STATUS_PAUSADO, ProcessoMonitoramento::STATUS_SUSPENSO])
            ->count();
        if ($ativos >= (int) config('pje.monitoramento.max_ativos_por_token')) {
            return response()->json(['error' => 'Limite de monitoramentos ativos por token atingido'], 422);
        }

        $existente = ProcessoMonitoramento::doToken($token->id)
            ->where('tribunal_id', $tribunal->id)
            ->where('numero_processo', $request->numero_processo)
            ->where('status', '<>', ProcessoMonitoramento::STATUS_CANCELADO)
            ->first();
        if ($existente) {
            return response()->json(['error' => 'Já existe monitoramento para este processo', 'uuid' => $existente->uuid], 409);
        }

        $credencial = app(CredencialPjeService::class)
            ->resolver($token, $tribunal, $request->login_pje, $request->senha_pje);

        $monitoramento = ProcessoMonitoramento::create([
            'api_token_id' => $token->id,
            'tribunal_id' => $tribunal->id,
            'numero_processo' => $request->numero_processo,
            'intervalo_horas' => $request->intervalo_horas,
            'credencial_id' => $credencial?->id,
            'callback_url' => $request->callback_url,
            'callback_token' => $request->callback_token,
            'status' => ProcessoMonitoramento::STATUS_ATIVO,
            'proxima_execucao_em' => now(),   // entra no próximo tick do despachador
        ]);

        return (new MonitoramentoResource($monitoramento->load('credencial')))->response()->setStatusCode(201);
    }

    // index: doToken + when($request->status) + paginate(DEFAULT_PER_PAGE), via Resource::collection
    // show/update/destroy/execucoes/executar: buscarDoToken($request, $uuid) → firstOrFail (404 p/ outro token)

    private function buscarDoToken(Request $request, string $uuid): ProcessoMonitoramento
    {
        return ProcessoMonitoramento::doToken($request->attributes->get('apiToken')->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
```

`update`: aplica `only([...])`; se `status` vira `ativo` (de pausado/suspenso) → `falhas_consecutivas = 0`, `proxima_execucao_em = now()`.
`destroy`: `update(['status' => cancelado]) + delete()` → `204`.
`executar`: `ExecutarMonitoramentoProcessoJob::dispatch($m->id)->onQueue('monitoramento')` → `202` `{message}`.
`execucoes`: `$m->execucoes()->paginate(...)` via resource.

`routes/api.php` — grupo **separado**, só `ValidateApiToken` (constraint de spec — sem `InjectCredenciaisPjePadrao`):

```php
Route::middleware([ValidateApiToken::class])->group(function () {
    Route::group(['prefix' => '/processo/monitoramentos'], function () {
        Route::post('/', [MonitoramentoProcessoController::class, 'store']);
        Route::get('/', [MonitoramentoProcessoController::class, 'index']);
        Route::get('/{uuid}', [MonitoramentoProcessoController::class, 'show']);
        Route::patch('/{uuid}', [MonitoramentoProcessoController::class, 'update']);
        Route::delete('/{uuid}', [MonitoramentoProcessoController::class, 'destroy']);
        Route::get('/{uuid}/execucoes', [MonitoramentoProcessoController::class, 'execucoes']);
        Route::post('/{uuid}/executar', [MonitoramentoProcessoController::class, 'executar']);
    });
});
```

- [ ] **Step 4: Rodar (deve passar)** — e a suíte de API inteira: `php artisan test tests/Feature/Api`.

- [ ] **Step 5: Commit** — `feat(monitoramento): CRUD da API de monitoramento com isolamento por token`

---

### Task 10: OpenAPI, .env.example, CHANGELOG

**Files:**
- Modify: `docs/api/openapi.yaml`
- Modify: `.env.example`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: OpenAPI** — tag `Monitoramentos`; os 7 paths com schemas `Monitoramento`, `MonitoramentoExecucao`, `WebhookMonitoramentoPayload` (sucesso e falha, headers `X-Evento`/`X-Idempotency-Key` documentados na descrição); respostas `201/401/404/409/422`; nota de que credenciais vão no **body** e nunca retornam (só `login_mascarado`); nota do comportamento "metadados sem binário".
- [ ] **Step 2: `.env.example`** — `# PJE_MONITORAMENTO_MAX_ATIVOS_POR_TOKEN=500` comentado, na seção das vars PJe. Nota no bloco de comentário: rotação de `APP_KEY` invalida credenciais salvas em `credenciais_pje`.
- [ ] **Step 3: CHANGELOG** — entrada nova no topo descrevendo o recurso.
- [ ] **Step 4: Validar** — se houver validador de OpenAPI disponível (`npx @redocly/cli lint docs/api/openapi.yaml`), rodar; senão conferir indentação por leitura.
- [ ] **Step 5: Commit** — `docs(monitoramento): openapi, env de exemplo e changelog`

---

## Verificação final

- [ ] `php artisan test` completo — comparar com o baseline (falhas herdadas de exportação não contam como regressão).
- [ ] `php artisan migrate:rollback --step=3 && php artisan migrate` — migrations reversíveis.
- [ ] `php artisan schedule:list` — os 2 comandos aparecem com a cadência certa.
- [ ] Smoke manual (opcional, requer ambiente): criar monitoramento via curl, rodar `php artisan monitoramentos:despachar`, ver job na fila `monitoramento` no Horizon e o POST no callback (ex. webhook.site é público/https).
- [ ] Push: `git push -u origin claude/process-monitoring-api-x56a1y`.
