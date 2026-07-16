# Desacoplar a conexão DB `sim` — cópia de dados e cutover (Sub-projeto B2)

**Data:** 2026-07-14
**Tipo:** Migração de dados + refatoração (remoção de dependência)
**Serviço:** ms-mni
**Depende de:** Sub-projeto B1 (schema local canônico de `tribunais`/`tipos_documentos`
no banco `ms_mni`) — já concluído.

## Contexto

Após o B1, o banco `ms_mni` (conexão default) tem o schema canônico de `tribunais`
(com `uuid`) e `tipos_documentos`, mas **vazio**. Os models `Tribunal` e
`TipoDocumento` ainda leem/escrevem no banco do SIM (`sim_producao`) via
`protected $connection = 'sim'`.

Este sub-projeto completa o desacoplamento: copia os dados do SIM para o `ms_mni`,
repontar os models para o default, remove a conexão `sim` (config + env) e a
infraestrutura de teste específica do sim. O SIM deixa de ser dependência do
ms-mni (decisão do Sub-projeto B: `ms_mni` vira dono desses dados).

Volumes verificados (2026-07-14): `sim_producao.tribunais` = 8 linhas;
`sim_producao.tipos_documentos` = 2926 linhas.

## Decisões

- **Cópia dentro de uma migration**, resiliente e **auto-suficiente**: a migration
  monta sua própria conexão de leitura ao `sim` a partir dos env `DB_SIM_*` em
  runtime — **não depende do bloco `'sim'` em `config/database.php`** (que é removido
  no mesmo release). Copia se os env existem e o banco é alcançável; **no-op**
  (guard try/catch) se ausente (CI, ou env já limpos).
- **Cutover num único deploy:** como a migration lê o `sim` via env (não via config),
  o mesmo release pode remover o bloco `'sim'` do config E rodar a cópia. Ordem no
  deploy: `migrate` (copia, com `DB_SIM_*` ainda no `.env` real) → app novo (models
  no default). Sem janela de tabela vazia. Os `DB_SIM_*` do `.env` real ficam
  inertes após o deploy; o operador os remove quando quiser (limpeza).
- **Preservar `id`** na cópia (o `tipos_documentos.tribunal_id` referencia
  `tribunais.id`). Ordem: `tribunais` → `tipos_documentos`.
- **Gerar `uuid`** por tribunal na cópia (o `sim` não tem a coluna).
- **Cópia via query builder** (`DB::connection(...)->table(...)`), não via model —
  preserva `id`, controla `uuid` explicitamente e evita o hook `creating` do
  `Tribunal` (que auto-gera `uuid`). `updateOrInsert` por `id` → idempotente.
- **Repontar models:** remover `$connection = 'sim'` de `Tribunal` e `TipoDocumento`.
  O CRUD de tribunais passa a escrever no `ms_mni` **automaticamente** (usa o
  model) — sem mudança no controller.
- **Remover a conexão `sim`** de `config/database.php` e as vars `DB_SIM_*` do
  `.env.example` — última etapa.
- **Test infra:** deletar as traits `SimDatabaseTestCase` e
  `MultiConnectionDatabaseTestCase`; `TribunalCrudTest` e `ProcessoConsultaTest`
  passam a usar `DatabaseTransactions` direto (conexão default).
- **`down()` da migration de cópia:** `truncate` de `tribunais` e
  `tipos_documentos` (volta ao estado pós-B1: tabelas vazias).

## Arquitetura

### Migration de cópia — `copiar_dados_sim_para_local`

Nova migration (data corrente, após as do B1). Monta a conexão de leitura ao `sim`
a partir dos env em runtime, sob um nome temporário (ex. `sim_migracao`), para não
depender do bloco `'sim'` do config (removido no mesmo release):

```
registrarConexaoSim():
  config(['database.connections.sim_migracao' => [
    'driver'   => env('DB_SIM_CONNECTION', 'pgsql'),
    'host'     => env('DB_SIM_HOST'),
    'port'     => env('DB_SIM_PORT', '5432'),
    'database' => env('DB_SIM_DATABASE'),
    'username' => env('DB_SIM_USERNAME'),
    'password' => env('DB_SIM_PASSWORD'),
  ]])

simDisponivel(): bool
  try {
    if (! env('DB_SIM_HOST')) return false
    registrarConexaoSim()
    return Schema::connection('sim_migracao')->hasTable('tribunais')
  } catch { return false }

up():
  se NÃO simDisponivel(): return           // no-op em CI / env limpos
  DB::transaction(default):
    para cada linha de DB::connection('sim_migracao').table('tribunais'):
      DB::connection(default)->table('tribunais')->updateOrInsert(
        ['id' => row.id], { ...colunas..., uuid: row.uuid ?? Str::uuid() }
      )
    para cada linha de DB::connection('sim_migracao').table('tipos_documentos'):
      DB::connection(default)->table('tipos_documentos')->updateOrInsert(
        ['id' => row.id], { ...colunas... }
      )
    corrige as sequences do Postgres (setval) para max(id) nas duas tabelas

down():
  DB::connection(default)->table('tipos_documentos')->truncate()
  DB::connection(default)->table('tribunais')->truncate()
```

Notas:
- **Conexão via env, não config:** a migration é auto-suficiente; remover o bloco
  `'sim'` do `config/database.php` no mesmo release não a quebra.
- **Sequences:** como preservamos `id` via insert explícito, a sequence de auto-incremento
  do Postgres não avança; corrigir com `setval(pg_get_serial_sequence(...), max(id))`
  para não colidir em inserts futuros do CRUD.
- **`uuid`:** o `sim.tribunais` não tem a coluna, então `row.uuid` é sempre nulo →
  gera `Str::uuid()`. O `updateOrInsert` por `id` torna a migration re-executável sem
  duplicar.
- Colunas copiadas espelham o schema canônico do B1 (todas as do `sim` + defaults
  onde o `sim` não tiver o dado).

### Repontar models

`app/Models/Tribunal.php` e `app/Models/TipoDocumento.php`: remover a linha
`protected $connection = 'sim';`. Passam a usar a conexão default. O hook
`creating` do `Tribunal` (auto-`uuid`) continua válido — a tabela default tem
`uuid`, então novos tribunais via CRUD recebem `uuid`.

### Remover a conexão `sim`

- `config/database.php`: remover o bloco `'sim' => [ ... ]`.
- `.env.example`: remover `DB_SIM_CONNECTION/HOST/PORT/DATABASE/USERNAME/PASSWORD`.

### Test infra

- Deletar `tests/SimDatabaseTestCase.php` e `tests/MultiConnectionDatabaseTestCase.php`.
- `tests/Feature/TribunalCrudTest.php`: trocar `uses(SimDatabaseTestCase::class)` por
  `uses(Illuminate\Foundation\Testing\DatabaseTransactions::class)` (transaciona a
  conexão default — onde `tribunais` agora vive).
- `tests/Feature/ProcessoConsultaTest.php`: idem, trocar
  `MultiConnectionDatabaseTestCase` por `DatabaseTransactions` (tudo é default agora).

## Ordem de execução / cutover

Num deploy (release único):
1. `php artisan migrate` → a migration de cópia monta a conexão `sim_migracao` a
   partir dos `DB_SIM_*` (ainda presentes no `.env` real) → popula `ms_mni`.
2. Novo código ativo: models no default; `config/database.php` sem o bloco `'sim'`.
3. Limpeza (quando o operador quiser): remover os `DB_SIM_*` do `.env` real — já
   inertes.

Como migrations rodam antes do app servir tráfego, não há janela em que o model
default leia tabela vazia. Em ambientes sem `sim` (CI, sem `DB_SIM_*`), a migration
é no-op e o schema (B1) + fixtures de teste cobrem os dados.

## Tratamento de erro

- `sim` inalcançável no deploy de produção: a migration vira no-op (guard) e o
  `ms_mni` fica vazio — **risco**. Mitigação: a verificação pós-deploy (contagens)
  falha explicitamente se as tabelas estiverem vazias quando deveriam ter dados;
  rodar o deploy com `sim` comprovadamente alcançável. (Documentar no runbook.)
- Falha parcial da cópia: a transação default faz rollback; `updateOrInsert` por
  `id` permite re-execução manual.

## Testes

- **Cópia:** com `sim` disponível (docker), após a migration: `tribunais` = 8 e
  `tipos_documentos` = 2926 no default; `id`s preservados; todo tribunal tem `uuid`
  não-nulo; um `tipos_documentos` referencia um `tribunal_id` existente.
- **Idempotência:** rodar a lógica de cópia 2× não duplica (contagens estáveis).
- **No-op:** `simDisponivel()` retorna false → a migration não lança e não altera dados.
- **Models repontados:** `(new Tribunal)->getConnectionName()` e
  `(new TipoDocumento)->getConnectionName()` retornam a conexão default (não `sim`).
- **CRUD:** criar um tribunal via `TribunalController@store` grava no `ms_mni` e
  gera `uuid`; `Api\TribunalController@show($uuid)` passa a funcionar (uuid existe).
- **Regressão:** grep por `connection('sim')`, `'sim' =>` (em config), `DB_SIM`,
  `SimDatabaseTestCase`, `MultiConnectionDatabaseTestCase` = 0. Suíte no baseline.

## Critérios de sucesso

1. Após `migrate` com `sim` disponível, `ms_mni.tribunais` (8) e
   `ms_mni.tipos_documentos` (2926) populados, ids preservados, uuids gerados.
2. Models leem/escrevem no default; nenhuma referência à conexão `sim` no código.
3. CRUD de tribunais grava no `ms_mni`; `Api show($uuid)` funciona.
4. Conexão `sim` e `DB_SIM_*` removidos; traits de teste sim deletadas.
5. Suíte sem novas falhas vs baseline; `migrate:fresh` continua limpo.

## Fora de escopo (YAGNI)

- Sync contínuo com o SIM (decisão do Sub-projeto B: cópia única, `ms_mni` vira dono).
- Renomear/limpar o hook `creating` memoizado do `Tribunal` (funciona como está).
- Índice único em `tipos_documentos(tribunal_id, codigo)` (fora do B1; não introduzir
  aqui para não arriscar a cópia dos 2926 registros).
- Adicionar `postgresql-client` ao `docker/Dockerfile` (follow-up de infra do B1).
- Migração de dados de outros ambientes além do procedimento de deploy descrito.
