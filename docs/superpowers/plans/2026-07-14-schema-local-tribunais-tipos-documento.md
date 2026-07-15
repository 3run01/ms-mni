# Schema local de tribunais e tipos_documentos (Sub-projeto B1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar ao banco `ms_mni` (conexão default) o schema canônico de `tribunais` e `tipos_documentos`, espelhando `sim_producao`, para preparar o desacoplamento da conexão `sim` (Sub-projeto B2).

**Architecture:** Duas migrations de criação (uma cria `tipos_documentos`; a outra dropa e recria `tribunais` com o schema canônico) mais guardas `Schema::hasTable` nas duas migrations legadas de `tribunais`, para que um `migrate:fresh` (CI/ambiente novo) não quebre. Nenhuma mudança de model, conexão ou dados — o app continua lendo do `sim`.

**Tech Stack:** Laravel 11 migrations (Schema/Blueprint), Pest, Postgres, execução via container docker (`docker compose exec -T php php artisan ...`).

## Global Constraints

- Todas as migrations rodam na **conexão default** (`ms_mni`) — NÃO usar `->connection('sim')`.
- Schema fiel a `sim_producao` + acréscimos: `uuid` (unique, nullable), `timestamps`, `softDeletes`.
- `tribunais`: **drop + recreate** (não ALTER). `tipos_documentos`: create (não existe hoje).
- Sem FK e sem índices únicos além da PK (fiel ao `sim`; evita quebrar a cópia de dados do B2).
- NÃO tocar: models `Tribunal`/`TipoDocumento`, `config/database.php`, env, test cases `SimDatabaseTestCase`/`MultiConnectionDatabaseTestCase`, dados. Isso é B2.
- Rodar tudo no container: `docker compose exec -T php php artisan ...` (o `php` do host não existe; wrapper `./php` quebrado).
- Working tree tem arquivos não-relacionados não-commitados; subagents SEMPRE `git add` com caminhos específicos, NUNCA `git add -A`/`-u`.
- Baseline de teste: ~9 falhas pré-existentes no domínio exportação (dados/env). Comparar contra o baseline; não exigir suíte 100% verde herdada.
- Datas dos arquivos de migration: usar prefixo `2026_07_14_1000xx` para garantir que rodam DEPOIS das legadas.

---

### Task 1: Migration create_tipos_documentos_table

**Files:**
- Create: `database/migrations/2026_07_14_100001_create_tipos_documentos_table.php`
- Test: `tests/Feature/Migrations/SchemaLocalTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: tabela `tipos_documentos` na conexão default com colunas `id, tribunal_id, descricao, codigo, exibir_peticao_incidental, exibir_peticao_inicial, exibir_expediente, created_at, updated_at, deleted_at`.

- [ ] **Step 1: Escrever o teste de schema**

Criar `tests/Feature/Migrations/SchemaLocalTest.php`:

```php
<?php

use Illuminate\Support\Facades\Schema;

it('tipos_documentos existe no banco default com as colunas canônicas', function () {
    expect(Schema::hasTable('tipos_documentos'))->toBeTrue();

    foreach ([
        'id', 'tribunal_id', 'descricao', 'codigo',
        'exibir_peticao_incidental', 'exibir_peticao_inicial', 'exibir_expediente',
        'created_at', 'updated_at', 'deleted_at',
    ] as $coluna) {
        expect(Schema::hasColumn('tipos_documentos', $coluna))->toBeTrue("faltou coluna {$coluna}");
    }
});
```

- [ ] **Step 2: Rodar o teste (deve falhar)**

Run: `docker compose exec -T php php artisan test tests/Feature/Migrations/SchemaLocalTest.php`
Expected: FAIL — `tipos_documentos` não existe no default (`Schema::hasTable` false).

- [ ] **Step 3: Criar a migration**

`database/migrations/2026_07_14_100001_create_tipos_documentos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_documentos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('tribunal_id');
            $table->string('descricao', 255);
            $table->string('codigo', 255);
            $table->boolean('exibir_peticao_incidental');
            $table->boolean('exibir_peticao_inicial');
            $table->boolean('exibir_expediente');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_documentos');
    }
};
```

- [ ] **Step 4: Aplicar a migration**

Run: `docker compose exec -T php php artisan migrate --path=database/migrations/2026_07_14_100001_create_tipos_documentos_table.php`
Expected: `DONE` — cria a tabela.

- [ ] **Step 5: Rodar o teste (deve passar)**

Run: `docker compose exec -T php php artisan test tests/Feature/Migrations/SchemaLocalTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_14_100001_create_tipos_documentos_table.php tests/Feature/Migrations/SchemaLocalTest.php
git commit -m "feat(schema): cria tabela tipos_documentos no banco local"
```

---

### Task 2: Migration recreate_tribunais_table (drop + recreate canônico)

**Files:**
- Create: `database/migrations/2026_07_14_100002_recreate_tribunais_table.php`
- Test: `tests/Feature/Migrations/SchemaLocalTest.php` (adicionar caso)

**Interfaces:**
- Consumes: nada.
- Produces: tabela `tribunais` na conexão default com o schema canônico (colunas do `sim` + `uuid` + timestamps + softDeletes).

- [ ] **Step 1: Escrever o teste**

Adicionar em `tests/Feature/Migrations/SchemaLocalTest.php`:

```php
it('tribunais existe no default com o schema canônico', function () {
    expect(Schema::hasTable('tribunais'))->toBeTrue();

    foreach ([
        'id', 'uuid', 'nome', 'login', 'password',
        'url_webservice_mni', 'url_webservice_mni_complementar',
        'url_webservice_mni_consultar_processo', 'url_webservice_mni_criminal',
        'url_consulta_pje', 'url_recuperar_senha_tribunal', 'tipo', 'ativo',
        'versao_mni', 'codigo_peticao_inicial', 'codigo_peticao_avulsa',
        'codigo_certidao_inicio_fim', 'codigo_seeu', 'usar_codigo_documento_padrao',
        'usar_credencial_tribunal', 'enviar_dados_criminais',
        'created_at', 'updated_at', 'deleted_at',
    ] as $coluna) {
        expect(Schema::hasColumn('tribunais', $coluna))->toBeTrue("faltou coluna {$coluna}");
    }

    // a coluna antiga divergente não deve existir mais
    expect(Schema::hasColumn('tribunais', 'url_recuperar_senha'))->toBeFalse();
});
```

- [ ] **Step 2: Rodar o teste (deve falhar)**

Run: `docker compose exec -T php php artisan test tests/Feature/Migrations/SchemaLocalTest.php --filter="schema canônico"`
Expected: FAIL — o `tribunais` atual não tem `uuid`... na verdade tem `uuid` mas faltam 11 colunas (ex.: `usar_credencial_tribunal`) e ainda tem `url_recuperar_senha`. O teste falha nessas asserções.

- [ ] **Step 3: Criar a migration**

`database/migrations/2026_07_14_100002_recreate_tribunais_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('tribunais');

        Schema::create('tribunais', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique()->nullable();
            $table->string('nome', 255);
            $table->string('login', 255)->nullable();
            $table->text('password')->nullable();
            $table->string('url_webservice_mni', 255);
            $table->string('url_webservice_mni_complementar', 255);
            $table->string('url_webservice_mni_consultar_processo', 255)->nullable();
            $table->string('url_webservice_mni_criminal', 255)->nullable();
            $table->string('url_consulta_pje', 255)->nullable();
            $table->string('url_recuperar_senha_tribunal', 255)->nullable();
            $table->string('tipo', 255)->nullable();
            $table->boolean('ativo')->nullable();
            $table->string('versao_mni', 255)->nullable();
            $table->string('codigo_peticao_inicial', 255)->nullable();
            $table->string('codigo_peticao_avulsa', 255)->nullable();
            $table->string('codigo_certidao_inicio_fim', 255)->nullable();
            $table->string('codigo_seeu', 255)->nullable();
            $table->string('usar_codigo_documento_padrao', 255)->nullable();
            $table->boolean('usar_credencial_tribunal')->default(false);
            $table->boolean('enviar_dados_criminais')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tribunais');
    }
};
```

- [ ] **Step 4: Aplicar a migration**

Run: `docker compose exec -T php php artisan migrate --path=database/migrations/2026_07_14_100002_recreate_tribunais_table.php`
Expected: `DONE` — dropa o tribunais divergente e recria canônico.

- [ ] **Step 5: Rodar o teste (deve passar)**

Run: `docker compose exec -T php php artisan test tests/Feature/Migrations/SchemaLocalTest.php`
Expected: PASS (ambos os casos).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_14_100002_recreate_tribunais_table.php tests/Feature/Migrations/SchemaLocalTest.php
git commit -m "feat(schema): recria tribunais canonico no banco local"
```

---

### Task 3: Guardar migrations legadas para migrate:fresh

**Files:**
- Modify: `database/migrations/2024_12_09_224602_add_uuid_to_tribunais_table.php`
- Modify: `database/migrations/2026_07_10_235201_make_login_password_nullable_on_tribunais.php`
- Test: `tests/Feature/Migrations/SchemaLocalTest.php` (adicionar caso de fresh, opcional — ver Step 1)

**Interfaces:**
- Consumes: as migrations criadas nas Tasks 1-2.
- Produces: migrations legadas idempotentes/guardadas, permitindo `migrate:fresh` limpo.

- [ ] **Step 1: Guardar add_uuid_to_tribunais**

Em `database/migrations/2024_12_09_224602_add_uuid_to_tribunais_table.php`, envolver o corpo com `Schema::hasTable`. Substituir `up()` e `down()` por (removendo o `Schema::table` aninhado duplicado do original):

```php
    public function up(): void
    {
        if (! Schema::hasTable('tribunais') || Schema::hasColumn('tribunais', 'uuid')) {
            return;
        }

        Schema::table('tribunais', function (Blueprint $table) {
            $table->uuid('uuid')->unique()->after('id')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tribunais') && Schema::hasColumn('tribunais', 'uuid')) {
            Schema::table('tribunais', function (Blueprint $table) {
                $table->dropColumn('uuid');
            });
        }
    }
```

Racional: em `migrate:fresh` a tabela `tribunais` ainda não existe quando esta migration antiga roda → no-op; o `uuid` passa a vir do create canônico (Task 2). A guarda `hasColumn` também evita erro de coluna duplicada.

- [ ] **Step 2: Guardar make_login_password_nullable_on_tribunais**

Em `database/migrations/2026_07_10_235201_make_login_password_nullable_on_tribunais.php`, envolver as chamadas `DB::connection('sim')` com uma guarda que não quebre quando a conexão `sim` não existir/estiver inacessível. Substituir `up()`/`down()` por:

```php
    public function up(): void
    {
        if (! $this->simTribunaisExiste()) {
            return;
        }

        DB::connection('sim')->statement('ALTER TABLE tribunais ALTER COLUMN login DROP NOT NULL');
        DB::connection('sim')->statement('ALTER TABLE tribunais ALTER COLUMN password DROP NOT NULL');
    }

    public function down(): void
    {
        if (! $this->simTribunaisExiste()) {
            return;
        }

        DB::connection('sim')->statement('ALTER TABLE tribunais ALTER COLUMN login SET NOT NULL');
        DB::connection('sim')->statement('ALTER TABLE tribunais ALTER COLUMN password SET NOT NULL');
    }

    private function simTribunaisExiste(): bool
    {
        try {
            return in_array('sim', array_keys(config('database.connections')), true)
                && Schema::connection('sim')->hasTable('tribunais');
        } catch (\Throwable $e) {
            return false;
        }
    }
```

Garantir os imports no topo do arquivo: `use Illuminate\Support\Facades\DB;` e `use Illuminate\Support\Facades\Schema;` (adicionar o de Schema se ausente).

Racional: mantém o comportamento onde a conexão `sim` existe e é alcançável; vira no-op (sem lançar) em CI/ambiente novo sem `sim`.

- [ ] **Step 3: Verificar migrate:fresh de ponta a ponta**

Run: `docker compose exec -T php php artisan migrate:fresh --force 2>&1 | tail -30`
Expected: roda até o fim sem erro (todas as migrations, incluindo as legadas guardadas e os creates canônicos). Ao final, `tribunais` e `tipos_documentos` existem no default.

Observação: `migrate:fresh` recria TODO o schema do `ms_mni` (dropa tudo e roda todas as migrations). Isso é seguro no banco de teste/dev; NÃO rodar contra produção. Se o ambiente docker compartilhar o banco de dev, os dados de dev do `ms_mni` são recriados — aceitável para dev/teste.

- [ ] **Step 4: Confirmar schema pós-fresh**

Run:
```bash
docker compose exec -T php php artisan tinker --execute="echo 'tribunais='.(Schema::hasTable('tribunais')?'ok':'FALTA').' uuid='.(Schema::hasColumn('tribunais','uuid')?'ok':'FALTA').' usar_credencial='.(Schema::hasColumn('tribunais','usar_credencial_tribunal')?'ok':'FALTA').' tipos='.(Schema::hasTable('tipos_documentos')?'ok':'FALTA').PHP_EOL;"
```
Expected: `tribunais=ok uuid=ok usar_credencial=ok tipos=ok`.

- [ ] **Step 5: Rodar a suíte de schema + comparar baseline**

Run: `docker compose exec -T php php artisan test tests/Feature/Migrations/SchemaLocalTest.php`
Expected: PASS.

Run (baseline): `docker compose exec -T php php artisan test 2>&1 | grep "Tests:"`
Expected: sem novas falhas atribuíveis a este sub-projeto (comparar com ~9 falhas de exportação pré-existentes). Nota: após `migrate:fresh` o banco de dev fica vazio, então testes data-dependentes podem variar; o gate é "nenhuma falha NOVA de schema/tribunais", não a contagem absoluta.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2024_12_09_224602_add_uuid_to_tribunais_table.php database/migrations/2026_07_10_235201_make_login_password_nullable_on_tribunais.php tests/Feature/Migrations/SchemaLocalTest.php
git commit -m "fix(schema): guarda migrations legadas de tribunais para migrate:fresh"
```

---

### Task 4: Verificação final

**Files:** nenhum (validação).

- [ ] **Step 1: Confirmar que os models e a conexão NÃO foram tocados**

Run:
```bash
git diff 03374b1..HEAD --stat -- app/ config/ | cat
```
Expected: vazio (nenhuma mudança em `app/` ou `config/` — só `database/migrations/` e `tests/`). Se houver, é escopo B2 vazado — reverter.

- [ ] **Step 2: App em execução inalterado (models ainda leem do sim)**

Run:
```bash
docker compose exec -T php php artisan tinker --execute="echo 'Tribunal conn='.(new App\Models\Tribunal)->getConnectionName().' TipoDoc conn='.(new App\Models\TipoDocumento)->getConnectionName().PHP_EOL;"
```
Expected: `Tribunal conn=sim TipoDoc conn=sim` (models intactos, ainda no `sim` — B2 mudará isso).

- [ ] **Step 3: Schema final confere**

Run o mesmo comando do Task 3 Step 4.
Expected: `tribunais=ok uuid=ok usar_credencial=ok tipos=ok`.

---

## Notas de execução

- `migrate:fresh` (Task 3) recria o banco `ms_mni` inteiro — só em dev/teste, nunca produção. Em produção real, o deploy roda `migrate` (não fresh), que aplica só as 2 novas migrations sobre o schema existente.
- As Tasks 1 e 2 aplicam migrations com `--path` (uma de cada vez) para o TDD; o Task 3 valida o caminho `migrate:fresh` completo.
- NÃO alterar models, `config/database.php`, env, nem os test cases de conexão `sim` — é o Sub-projeto B2.
