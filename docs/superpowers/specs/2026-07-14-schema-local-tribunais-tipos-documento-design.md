# Schema local de tribunais e tipos_documentos (Sub-projeto B1)

**Data:** 2026-07-14
**Tipo:** Migração de schema (preparação para desacoplar da conexão SIM)
**Serviço:** ms-mni
**Escopo:** Sub-projeto B1 (só schema local). Cópia de dados, repontar models e
remover a conexão `sim` são o **Sub-projeto B2**, tratado depois.

## Contexto

Os models `Tribunal` e `TipoDocumento` leem hoje do banco do SIM
(`sim_producao`) via `protected $connection = 'sim'`. O objetivo maior (Sub-projeto
B) é o ms-mni deixar de depender desse banco e passar a ser dono desses dados de
referência no seu próprio banco (`ms_mni`, conexão default).

Estado atual verificado (2026-07-14):

| tabela | `sim_producao` (conexão `sim`) | `ms_mni` (default) |
|---|---|---|
| `tribunais` | 8 linhas — fonte de verdade, **sem `uuid`** | 7 linhas stale, schema **divergente**, **com `uuid`** |
| `tipos_documentos` | 2926 linhas | **não existe** |

`ms_mni.tribunais` está desatualizado: faltam 11 colunas
(`url_recuperar_senha_tribunal`, `codigo_peticao_inicial`, `codigo_peticao_avulsa`,
`codigo_certidao_inicio_fim`, `codigo_seeu`, `enviar_dados_criminais`,
`url_webservice_mni_criminal`, `versao_mni`, `usar_credencial_tribunal`,
`usar_codigo_documento_padrao`, `url_webservice_mni_consultar_processo`), tem
`url_recuperar_senha` (nome antigo, deveria ser `url_recuperar_senha_tribunal`) e
tem `uuid` extra. Não há migration que cria `tribunais` — a tabela foi criada fora
do versionamento, então um `migrate` limpo (CI / ambiente novo) **quebra** na
migration `2024_12_09_224602_add_uuid_to_tribunais_table` (faz `Schema::table` numa
tabela inexistente).

Este sub-projeto dá ao `ms_mni` o schema canônico dessas duas tabelas.

## Escopo

**Dentro (B1):**
- Migration que cria `tipos_documentos` no banco default.
- Migration que **dropa e recria** `tribunais` no banco default com o schema
  canônico (colunas do `sim` + `uuid` + `softDeletes` + `timestamps`).
- Guardas (`Schema::hasTable`) nas migrations legadas de `tribunais` para um
  `migrate` limpo não quebrar.

**Fora (B2 / YAGNI):**
- Cópia dos dados de `sim_producao` para `ms_mni`.
- Remover `protected $connection = 'sim'` de `Tribunal`/`TipoDocumento`.
- Remover a conexão `sim` de `config/database.php` e os env `DB_SIM_*`.
- Ajustar `SimDatabaseTestCase`/`MultiConnectionDatabaseTestCase`.
- CRUD de tribunais passar a escrever no `ms_mni`.

Como os models continuam apontando para a conexão `sim` até o B2, **nenhuma
mudança deste sub-projeto afeta o app em execução** — o app não lê
`ms_mni.tribunais`/`ms_mni.tipos_documentos`. O drop das 7 linhas stale de
`ms_mni.tribunais` é seguro.

## Decisões

- **`tribunais`: drop + recreate canônico** (não ALTER-align). Schema legado
  divergente é substituído por um create único e correto. As 7 linhas stale morrem
  (B2 repopula do sim).
- **Corrigir ambiente novo agora:** guardar as migrations legadas de `tribunais`.
- **Schema fiel ao `sim`** para facilitar a cópia em B2, acrescido de `uuid`
  (o app consulta `Api\TribunalController@show($uuid)`) e das convenções Laravel
  (`timestamps`, `softDeletes`) que os models já usam.
- **Sem novos índices únicos** além dos que o `sim` tem (só PK). Um único em
  `tipos_documentos(tribunal_id, codigo)` seria uma melhoria, mas fica fora para
  não arriscar a cópia de dados em B2 (pode haver duplicatas nos 2926 registros).

## Arquitetura

Três migrations novas (conexão default; data corrente, rodam por último) mais
guardas nas duas legadas.

### Migration `create_tipos_documentos_table`

Cria `tipos_documentos` (default). Fiel ao `sim` + convenções do model
(`SoftDeletes`, `HasFactory`):

| coluna | tipo | nulo |
|---|---|---|
| `id` | bigIncrements | NO |
| `tribunal_id` | integer | NO |
| `descricao` | string(255) | NO |
| `codigo` | string(255) | NO |
| `exibir_peticao_incidental` | boolean | NO |
| `exibir_peticao_inicial` | boolean | NO |
| `exibir_expediente` | boolean | NO |
| `created_at`/`updated_at` | timestamp | YES |
| `deleted_at` | timestamp | YES |

Sem FK para `tribunais` (o `sim` não tem; evita ordem de criação/órfãos na cópia).
Só PK. `$fillable` do model (`tribunal_id, descricao, codigo, exibir_*`) é coberto.

### Migration `recreate_tribunais_table`

`up()`: `Schema::dropIfExists('tribunais')` e recria com o schema canônico
(conexão default):

| coluna | tipo | nulo |
|---|---|---|
| `id` | bigIncrements | NO |
| `uuid` | uuid, unique, nullable | YES |
| `nome` | string(255) | NO |
| `login` | string(255) | YES |
| `password` | text | YES |
| `url_webservice_mni` | string(255) | NO |
| `url_webservice_mni_complementar` | string(255) | NO |
| `url_webservice_mni_consultar_processo` | string(255) | YES |
| `url_webservice_mni_criminal` | string(255) | YES |
| `url_consulta_pje` | string(255) | YES |
| `url_recuperar_senha_tribunal` | string(255) | YES |
| `tipo` | string(255) | YES |
| `ativo` | boolean | YES |
| `versao_mni` | string(255) | YES |
| `codigo_peticao_inicial` | string(255) | YES |
| `codigo_peticao_avulsa` | string(255) | YES |
| `codigo_certidao_inicio_fim` | string(255) | YES |
| `codigo_seeu` | string(255) | YES |
| `usar_codigo_documento_padrao` | string(255) | YES |
| `usar_credencial_tribunal` | boolean | NO, default false |
| `enviar_dados_criminais` | boolean | NO, default false |
| `created_at`/`updated_at` | timestamp | YES |
| `deleted_at` | timestamp | YES |

`down()`: `Schema::dropIfExists('tribunais')` (irreversível ao estado legado — é
uma recriação canônica; aceitável para este cutover).

Nota: os defaults `false` em `usar_credencial_tribunal`/`enviar_dados_criminais`
são para satisfazer NOT NULL na recriação da tabela vazia; a cópia em B2 traz os
valores reais.

### Guardas nas migrations legadas

- `2024_12_09_224602_add_uuid_to_tribunais_table`: envolver o `up()`/`down()` com
  `if (Schema::hasTable('tribunais'))`. Em ambiente novo, a tabela ainda não existe
  quando essa migration antiga roda → vira no-op; o `uuid` passa a vir do create
  canônico. Em ambiente existente, já rodou (idempotente pelo estado do
  `migrations`).
- `2026_07_10_235201_make_login_password_nullable_on_tribunais`: usa
  `DB::connection('sim')`. Envolver com `if (Schema::connection('sim')->hasTable('tribunais'))`
  para não quebrar quando a conexão `sim` for removida em B2 / não existir em CI.

## Ordem e efeito

`php artisan migrate` numa base **existente** (`ms_mni`):
1. legadas já marcadas como run (com as guardas, seriam no-op de qualquer forma).
2. `create_tipos_documentos_table` → cria a tabela (não existia).
3. `recreate_tribunais_table` → dropa o tribunais divergente + recria canônico.

`php artisan migrate` numa base **limpa** (CI/novo):
1. legadas de tribunais → no-op (guarda `hasTable` falsa; conexão `sim` pode nem
   existir).
2. cria `tipos_documentos` e `tribunais` canônicos.
Ambos os caminhos convergem para o mesmo schema.

## Testes

- Migration test / esquema: após `migrate`, `Schema::hasTable('tipos_documentos')`
  e `Schema::hasTable('tribunais')` verdadeiros na conexão default; `tribunais` tem
  as colunas canônicas incluindo `uuid`, `url_recuperar_senha_tribunal`,
  `usar_credencial_tribunal`; `tipos_documentos` tem `codigo`, `descricao`,
  `exibir_*`.
- Fresh-migrate: `migrate:fresh` (default) completa sem erro (valida as guardas
  das legadas).
- Rollback: `recreate_tribunais_table::down()` e `create_tipos_documentos_table::down()`
  removem as tabelas sem erro.
- Regressão: suíte atual continua no baseline (~9 falhas de exportação
  pré-existentes); nenhuma nova falha. Como os models ainda usam a conexão `sim`,
  os testes `SimDatabaseTestCase` seguem inalterados neste sub-projeto.

## Critérios de sucesso

1. `migrate` numa base existente cria `tipos_documentos` e recria `tribunais`
   canônico no `ms_mni` sem tocar `sim_producao`.
2. `migrate:fresh` roda limpo (ambiente novo deixa de quebrar).
3. Schema das duas tabelas no default espelha `sim_producao` + `uuid`/timestamps/
   softDeletes.
4. App em execução inalterado (models ainda leem do `sim`).
5. Nenhuma nova falha de teste vs baseline.

## Fora de escopo (reafirmado)

Cópia de dados, repontar models, remover conexão `sim`, ajustar test cases,
redirecionar o CRUD — tudo isso é o **Sub-projeto B2**, com spec própria.
