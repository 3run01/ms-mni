# Desacoplar a conexão DB `sim` — B2 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Copiar tribunais/tipos_documentos do `sim_producao` para o `ms_mni`, repontar os models para a conexão default e remover a conexão `sim` (config, env e infra de teste), completando o desacoplamento do SIM.

**Architecture:** Uma migration de cópia auto-suficiente (monta a conexão de leitura ao `sim` a partir dos env `DB_SIM_*` em runtime, preserva `id`, gera `uuid`, corrige sequences, no-op se `sim` ausente). Depois: remover `$connection='sim'` dos models + trocar os test traits sim por `DatabaseTransactions`; por fim remover o bloco `'sim'` do config e os `DB_SIM_*` do `.env.example`.

**Tech Stack:** Laravel 11 migrations, `DB` query builder cross-connection, Pest, Postgres, execução via docker (`docker compose exec -T php php artisan ...`).

## Global Constraints

- Migration de cópia: monta conexão `sim_migracao` a partir de `env('DB_SIM_*')` — NÃO depender do bloco `'sim'` do config (removido neste mesmo sub-projeto).
- Cópia preserva `id` (tipos referencia `tribunal_id`); ordem `tribunais` → `tipos_documentos`; gera `uuid` por tribunal (`sim` não tem a coluna); `updateOrInsert` por `id` (idempotente); corrige a sequence do Postgres com `setval` após inserir ids explícitos.
- Cópia é **no-op** (try/catch, sem lançar) se `DB_SIM_HOST` ausente ou `sim` inalcançável (CI).
- `down()` da migration de cópia: `truncate` de `tipos_documentos` e `tribunais`.
- Models `Tribunal`/`TipoDocumento`: remover `protected $connection = 'sim';` (passam a default). CRUD escreve no `ms_mni` automaticamente (usa o model) — NÃO alterar controllers.
- Deletar `tests/SimDatabaseTestCase.php` e `tests/MultiConnectionDatabaseTestCase.php`; `TribunalCrudTest` e `ProcessoConsultaTest` passam a `uses(Illuminate\Foundation\Testing\DatabaseTransactions::class)`.
- Remover o bloco `'sim' => [...]` de `config/database.php` e as vars `DB_SIM_*` do `.env.example`.
- Rodar tudo no container: `docker compose exec -T php php artisan ...`.
- Working tree tem arquivos não-relacionados não-commitados; subagents SEMPRE `git add` caminhos específicos, NUNCA `git add -A`/`-u`.
- Baseline de teste: ~8–10 falhas pré-existentes no domínio exportação (dados/env). Comparar contra baseline; não exigir suíte 100% verde herdada.
- Data dos arquivos de migration: prefixo `2026_07_14_1001xx` (após as do B1 `100002`).

---

### Task 1: Migration de cópia de dados (sim → ms_mni)

**Files:**
- Create: `database/migrations/2026_07_14_100101_copiar_dados_sim_para_local.php`
- Test: `tests/Feature/Migrations/CopiaDadosSimTest.php`

**Interfaces:**
- Consumes: schema canônico do B1 (`tribunais` com `uuid`, `tipos_documentos`), env `DB_SIM_*`.
- Produces: `ms_mni.tribunais` e `ms_mni.tipos_documentos` populados com os dados do `sim`, ids preservados, uuids gerados.

- [ ] **Step 1: Escrever o teste**

Criar `tests/Feature/Migrations/CopiaDadosSimTest.php` (usa a conexão `sim`, que ainda existe nesta task — só é removida na Task 3):

```php
<?php

use Illuminate\Support\Facades\DB;

it('copiou tribunais e tipos_documentos do sim para o default com ids e uuids', function () {
    $simTribunais = DB::connection('sim')->table('tribunais')->count();
    $simTipos = DB::connection('sim')->table('tipos_documentos')->count();

    expect($simTribunais)->toBeGreaterThan(0);

    expect(DB::connection()->table('tribunais')->count())->toBe($simTribunais);
    expect(DB::connection()->table('tipos_documentos')->count())->toBe($simTipos);

    // ids preservados: todo id do sim existe no default
    $idsSim = DB::connection('sim')->table('tribunais')->pluck('id')->all();
    $idsDefault = DB::connection()->table('tribunais')->pluck('id')->all();
    expect(array_diff($idsSim, $idsDefault))->toBe([]);

    // uuid gerado em todos os tribunais
    expect(DB::connection()->table('tribunais')->whereNull('uuid')->count())->toBe(0);

    // integridade: todo tipos_documento aponta para um tribunal existente
    expect(
        DB::connection()->table('tipos_documentos')
            ->whereNotIn('tribunal_id', DB::connection()->table('tribunais')->pluck('id'))
            ->count()
    )->toBe(0);
});
```

- [ ] **Step 2: Rodar o teste (deve falhar)**

Run: `docker compose exec -T php php artisan test tests/Feature/Migrations/CopiaDadosSimTest.php`
Expected: FAIL — `ms_mni.tribunais`/`tipos_documentos` estão vazios (0 ≠ contagem do sim).

- [ ] **Step 3: Criar a migration**

`database/migrations/2026_07_14_100101_copiar_dados_sim_para_local.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->simDisponivel()) {
            return; // CI / env limpos: no-op
        }

        DB::connection()->transaction(function () {
            foreach (DB::connection('sim_migracao')->table('tribunais')->cursor() as $row) {
                $dados = (array) $row;
                $id = $dados['id'];
                unset($dados['id']);
                if (empty($dados['uuid'] ?? null)) {
                    $dados['uuid'] = (string) Str::uuid();
                }
                DB::connection()->table('tribunais')->updateOrInsert(['id' => $id], $dados);
            }

            foreach (DB::connection('sim_migracao')->table('tipos_documentos')->cursor() as $row) {
                $dados = (array) $row;
                $id = $dados['id'];
                unset($dados['id']);
                DB::connection()->table('tipos_documentos')->updateOrInsert(['id' => $id], $dados);
            }

            $this->corrigirSequence('tribunais');
            $this->corrigirSequence('tipos_documentos');
        });
    }

    public function down(): void
    {
        DB::connection()->table('tipos_documentos')->truncate();
        DB::connection()->table('tribunais')->truncate();
    }

    private function simDisponivel(): bool
    {
        try {
            if (! env('DB_SIM_HOST')) {
                return false;
            }
            config(['database.connections.sim_migracao' => [
                'driver' => env('DB_SIM_CONNECTION', 'pgsql'),
                'host' => env('DB_SIM_HOST'),
                'port' => env('DB_SIM_PORT', '5432'),
                'database' => env('DB_SIM_DATABASE'),
                'username' => env('DB_SIM_USERNAME'),
                'password' => env('DB_SIM_PASSWORD'),
            ]]);

            return Schema::connection('sim_migracao')->hasTable('tribunais');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function corrigirSequence(string $tabela): void
    {
        $max = DB::connection()->table($tabela)->max('id');
        if ($max) {
            DB::connection()->statement(
                "SELECT setval(pg_get_serial_sequence(?, 'id'), ?)",
                [$tabela, $max]
            );
        }
    }
};
```

- [ ] **Step 4: Aplicar a migration**

Run: `docker compose exec -T php php artisan migrate --path=database/migrations/2026_07_14_100101_copiar_dados_sim_para_local.php`
Expected: `DONE`. Copia ~8 tribunais e ~2926 tipos_documentos.

- [ ] **Step 5: Rodar o teste (deve passar)**

Run: `docker compose exec -T php php artisan test tests/Feature/Migrations/CopiaDadosSimTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_14_100101_copiar_dados_sim_para_local.php tests/Feature/Migrations/CopiaDadosSimTest.php
git commit -m "feat(sim): migration copia dados de tribunais/tipos_documentos para o local"
```

---

### Task 2: Repontar models + trocar test infra sim por DatabaseTransactions

**Files:**
- Modify: `app/Models/Tribunal.php:21` (remover `$connection`)
- Modify: `app/Models/TipoDocumento.php:13` (remover `$connection`)
- Delete: `tests/SimDatabaseTestCase.php`, `tests/MultiConnectionDatabaseTestCase.php`
- Modify: `tests/Feature/TribunalCrudTest.php` (uses), `tests/Feature/ProcessoConsultaTest.php` (uses)
- Test: `tests/Feature/DesacoplarSimTest.php` (novo)

**Interfaces:**
- Consumes: dados copiados (Task 1).
- Produces: models `Tribunal`/`TipoDocumento` na conexão default.

Nota de ordem: repontar o model muda ONDE `TribunalCrudTest` escreve (de `sim` para default), então a troca do trait de transação PRECISA vir junto — senão a transação envolveria a conexão errada e vazaria escritas. Por isso model + test infra na mesma task.

- [ ] **Step 1: Escrever o teste de conexão**

Criar `tests/Feature/DesacoplarSimTest.php`:

```php
<?php

use App\Models\Tribunal;
use App\Models\TipoDocumento;

it('models Tribunal e TipoDocumento usam a conexão default (não sim)', function () {
    $default = config('database.default');
    expect((new Tribunal)->getConnectionName())->toBe($default)
        ->and((new TipoDocumento)->getConnectionName())->toBe($default);
});
```

- [ ] **Step 2: Rodar (deve falhar)**

Run: `docker compose exec -T php php artisan test tests/Feature/DesacoplarSimTest.php`
Expected: FAIL — `getConnectionName()` retorna `sim`.

- [ ] **Step 3: Remover `$connection` dos models**

Em `app/Models/Tribunal.php`, apagar a linha `protected $connection = 'sim';`.
Em `app/Models/TipoDocumento.php`, apagar a linha `protected $connection = 'sim';`.

- [ ] **Step 4: Deletar traits sim e repontar os testes**

```bash
git rm tests/SimDatabaseTestCase.php tests/MultiConnectionDatabaseTestCase.php
```

Em `tests/Feature/TribunalCrudTest.php`: trocar
```php
use Tests\SimDatabaseTestCase;

uses(SimDatabaseTestCase::class);
```
por
```php
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);
```

Em `tests/Feature/ProcessoConsultaTest.php`: trocar
```php
use Tests\MultiConnectionDatabaseTestCase;

uses(MultiConnectionDatabaseTestCase::class);
```
por
```php
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);
```

- [ ] **Step 5: Rodar os testes afetados (devem passar)**

Run: `docker compose exec -T php php artisan test tests/Feature/DesacoplarSimTest.php tests/Feature/TribunalCrudTest.php tests/Feature/ProcessoConsultaTest.php`
Expected: PASS. `DesacoplarSimTest` confirma default; `TribunalCrudTest`/`ProcessoConsultaTest` continuam verdes agora escrevendo/transacionando o default (onde `tribunais` vive após a cópia da Task 1).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Tribunal.php app/Models/TipoDocumento.php tests/Feature/TribunalCrudTest.php tests/Feature/ProcessoConsultaTest.php tests/Feature/DesacoplarSimTest.php
git commit -m "refactor(sim): repontar Tribunal/TipoDocumento para default e trocar test infra"
```

---

### Task 3: Remover a conexão `sim` do config e env

**Files:**
- Modify: `config/database.php` (remover bloco `'sim'`)
- Modify: `.env.example` (remover `DB_SIM_*`)
- Test: `tests/Feature/DesacoplarSimTest.php` (adicionar caso)

**Interfaces:**
- Consumes: models já no default (Task 2), cópia já feita (Task 1).
- Produces: ausência total da conexão `sim` no código.

- [ ] **Step 1: Adicionar o teste de ausência**

Adicionar em `tests/Feature/DesacoplarSimTest.php`:

```php
it('a conexão sim não existe mais no config', function () {
    expect(array_key_exists('sim', config('database.connections')))->toBeFalse();
});
```

- [ ] **Step 2: Rodar (deve falhar)**

Run: `docker compose exec -T php php artisan test tests/Feature/DesacoplarSimTest.php --filter="conexão sim não existe"`
Expected: FAIL — `sim` ainda está em `config('database.connections')`.

- [ ] **Step 3: Remover do config**

Em `config/database.php`, apagar o bloco:
```php
        'sim' => [
            'driver' => env('DB_SIM_CONNECTION', 'pgsql'),
            'host' => env('DB_SIM_HOST', '127.0.0.1'),
            'port' => env('DB_SIM_PORT', '5432'),
            'database' => env('DB_SIM_DATABASE', 'sim'),
            'username' => env('DB_SIM_USERNAME', 'docker'),
            'password' => env('DB_SIM_PASSWORD', 'docker'),
        ],
```

- [ ] **Step 4: Remover do .env.example**

Em `.env.example`, apagar as linhas `DB_SIM_CONNECTION`, `DB_SIM_HOST`, `DB_SIM_PORT`, `DB_SIM_DATABASE`, `DB_SIM_USERNAME`, `DB_SIM_PASSWORD` (e o comentário de cabeçalho do bloco, se houver).

- [ ] **Step 5: Rodar (deve passar) + grep**

Run: `docker compose exec -T php php artisan test tests/Feature/DesacoplarSimTest.php`
Expected: PASS (3 casos).

Run:
```bash
grep -rnE "connection\(['\"]sim['\"]\)|->connection\('sim'\)|\\\$connection\s*=\s*'sim'|'sim'\s*=>|SimDatabaseTestCase|MultiConnectionDatabaseTestCase" --include="*.php" -- app config tests | grep -vE "vendor/|sim_migracao"
```
Expected: vazio (o único uso legítimo remanescente é `sim_migracao` dentro da migration de cópia da Task 1, excluído pelo grep).

- [ ] **Step 6: Commit**

```bash
git add config/database.php .env.example tests/Feature/DesacoplarSimTest.php
git commit -m "chore(sim): remove conexao sim do config e env"
```

---

### Task 4: Verificação final

**Files:** nenhum (validação).

- [ ] **Step 1: migrate:fresh continua limpo**

Run: `docker compose exec -T php php artisan migrate:fresh --force 2>&1 | tail -20`
Expected: roda sem erro. A migration de cópia é no-op se `sim` não estiver acessível pós-fresh, ou copia se estiver — ambos sem lançar. (Se `postgresql-client` faltar no container, instalar como no B1: `docker compose exec -T php sh -lc 'apt-get update && apt-get install -y postgresql-client'` — ambiental, não commitar.)

- [ ] **Step 2: CRUD escreve no ms_mni + uuid**

Run:
```bash
docker compose exec -T php php artisan tinker --execute="\$t = App\Models\Tribunal::create(['nome'=>'TESTE B2','url_webservice_mni'=>'x','url_webservice_mni_complementar'=>'y','ativo'=>true,'enviar_dados_criminais'=>false]); echo 'conn='.\$t->getConnectionName().' uuid='.(\$t->uuid?'ok':'FALTA').PHP_EOL; \$t->forceDelete();"
```
Expected: `conn=<default> uuid=ok` (grava no default, uuid auto-gerado pelo hook `creating`).

- [ ] **Step 3: Grep final de sim**

Run:
```bash
grep -rnE "->connection\('sim'\)|connection\(\"sim\"\)|\\\$connection\s*=\s*'sim'|DB_SIM_|SimDatabaseTestCase|MultiConnectionDatabaseTestCase" --include="*.php" -- app config tests | grep -vE "vendor/|sim_migracao"
```
Expected: vazio.

- [ ] **Step 4: Suíte completa vs baseline**

Run: `docker compose exec -T php php artisan test 2>&1 | grep "Tests:"`
Expected: sem novas falhas atribuíveis a este sub-projeto (comparar com o baseline ~8–10 exportação). Registrar contagem.

---

## Notas de execução

- Ordem obrigatória: Task 1 (popula default) antes da Task 2 (repontar) — senão os models leriam tabela vazia. Task 3 (remover conexão) só depois de Task 1 já ter copiado (a migration lê via env, mas o teste da Task 1 usa `DB::connection('sim')` que some na Task 3).
- A migration de cópia lê o `sim` via env (`sim_migracao`), então remover o bloco `'sim'` do config (Task 3) não a quebra em re-runs/`migrate:fresh`.
- NÃO alterar controllers — o CRUD escreve no `ms_mni` automaticamente ao repontar o model.
- `migrate:fresh` recria o banco `ms_mni` dev/teste — só dev, nunca produção.
