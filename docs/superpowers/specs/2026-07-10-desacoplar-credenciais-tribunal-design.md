# Desacoplar credenciais do Tribunal — payload de consulta obrigatório

**Data:** 2026-07-10
**Status:** Aprovado
**Base:** branch `feat/tribunais-crud` (CRUD de tribunais já implementado; esta mudança o ajusta)

## Contexto e motivação

Hoje `App\Models\Tribunal` guarda `login` e `password` (credencial MNI) na tabela `tribunais` da conexão `sim`. Vários serviços de consulta usam essa credencial armazenada como *fallback* quando o payload não traz `login_pje`/`senha_pje`. A partir de agora a consulta de processo passa a **exigir** `login`/`senha` no payload — a credencial deixa de morar no tribunal. Resultado desejado: **zero credencial em repouso**.

Decisão do usuário (brainstorming 2026-07-10):
1. **Escopo:** full decouple — model + CRUD + 3 services interativos + controllers/API.
2. **Colunas + expediente:** **dropar** `login`/`password` no banco `sim` e **remover** o `ConsultarExpedienteService` (único path que precisa de credencial armazenada; comando não agendado).

### Achados da exploração (fatos que moldam o design)

1. **Colunas NOT NULL, sem default** (introspecção 2026-07-10): remover do path de create quebraria o INSERT — por isso as colunas são **dropadas**, não apenas ignoradas.
2. **Password é armazenada CRIPTOGRAFADA**: os serviços fazem `Crypt::decrypt($tribunal->password)`. O CRUD recém-construído gravava a senha em **texto puro** (sem mutator) — bug latente (`DecryptException` → 422 na consulta). Dropar a coluna elimina o bug junto.
3. **Consulta já meio-migrada:** `Api\ConsultarProcessoController@index` e `@show` já validam `login_pje`/`senha_pje` como `required`. Faltam outros endpoints que ainda passam `?? null`.
4. **Leitores de credencial armazenada:**
   | Path | Tipo | Fonte de credencial |
   | --- | --- | --- |
   | `ConsultarProcessoService` | interativo | `$login_pje ?? $tribunal->login` (fallback) |
   | `ConsultarDocumentoService` | interativo | fallback |
   | `SalvarDocumentoProcessoService` (2 blocos) | interativo | fallback (`if empty → tribunal`) |
   | `ConsultarExpedienteService` | sweep background | **só** `$tribunal->login` — sem payload possível |
5. **`ConsultarExpedienteService`** roda sobre todos os tribunais ativos sem requester (comando `mni:consultar-expedientes`, **não agendado** — Laravel 11 slim, sem Kernel/schedule). É estruturalmente incompatível com credencial no payload → **removido**.
6. **Blast radius do expediente:** o service é referenciado **só** pelo comando. `BaixarProcessoMNIJob` é compartilhado com `ConsultarProcessoController` (fica). O model `Expediente` não tem outro consumidor (vira órfão inofensivo).
7. **`usar_credencial_tribunal`** é flag **morta**: aparece só nos arquivos do CRUD, nenhum service a lê.
8. **`ValidarCredencialService`** é código morto (sem callers) e recebe credencial explícita — não lê credencial armazenada; fica intocado.

## Backend

### Migration (conexão `sim`)

Nova migration `drop_login_password_from_tribunais`:

- **`up()`:** `Schema::connection('sim')->table('tribunais', fn ($t) => $t->dropColumn(['login', 'password']))`. Conexão **explícita** — a migration de uuid rodou na conexão default por engano; não repetir.
- **`down()`:** recria `login`/`password` como **nullable** (`->string(...)->nullable()`). Os dados **não** são restaurados — perda irreversível.

`usar_credencial_tribunal`: sai do CRUD/fillable, mas a **coluna permanece** (não é credencial; dropar seria risco extra sem ganho). Registrado como sobra para limpeza futura.

### Services interativos → payload-only

- `ConsultarProcessoService::execute`: `'idConsultante' => $login_pje` e `'senhaConsultante' => $senha_pje` (remove `?? $tribunal->login` / `?? Crypt::decrypt(...)`). Remove `use ...Crypt` e o `catch (DecryptException)` (morto — senha do payload é texto puro).
- `ConsultarDocumentoService::execute`: idem (remove fallback + `Crypt`).
- `SalvarDocumentoProcessoService` (2 blocos ~338/479): remove o fallback pro tribunal; credenciais vazias → `throw` "credenciais MNI/PJ-e obrigatórias".

### API — tornar credenciais required

Adiciona `login_pje`/`senha_pje` `required|string` e troca `?? null` por passagem direta em:
- `Api\ConsultarProcessoController@consultarDadosBasicos`, `@consultarMovimentos` (e as variantes async que despacham job)
- `Api\DocumentoController@show`, `@listarDocumentos`

`@index` e `@show` do `ConsultarProcessoController` já estão required — mantidos.

### Expediente — remoção

- Deleta `app/Services/MNI/Intercomunicacao/ConsultarExpedienteService.php`.
- Deleta `app/Console/Commands/MNIConsultarExpediente.php`.
- `BaixarProcessoMNIJob` e model `Expediente` permanecem.

### Model `App\Models\Tribunal`

- `$fillable`: remove `login`, `password`, `usar_credencial_tribunal`.
- `$hidden`: remove `login`, `password` (colunas deixam de existir).
- `boot()`/uuid guard e `getTipos()`: intactos.

## Frontend / CRUD

- `TribunalRequest`: remove regras `login`, `password`, `usar_credencial_tribunal`.
- `TribunalController`: remove o drop-de-password no `update` e os 3 campos do `edit`/`only([...])`.
- `resources/js/components/tribunal-form.tsx`: remove a seção **Credenciais** inteira (login, password, usar_credencial_tribunal).
- `resources/js/types/index.d.ts`: remove os 3 campos do tipo do form.
- `database/factories/TribunalFactory.php`: remove `login`, `password`, `usar_credencial_tribunal` (escrever coluna dropada = erro de INSERT).

## Testes (Pest)

- **CRUD (`TribunalCrudTest`):** remove os 3 testes obsoletos — login obrigatório, password write-only, "mantém password em branco no update". Ajusta os demais para o factory sem credenciais.
- **API (`ConsultarProcessoControllerTest`):** atualiza os casos existentes; adiciona, para os endpoints tocados: "retorna 422 sem `login_pje`/`senha_pje`" e "usa a credencial do payload (sem fallback pro tribunal)".
- Escrita na conexão `sim` via trait `Tests\SimDatabaseTestCase` (rollback — não suja `sim_producao`).
- `npm run typecheck` + `npm run build` verdes.

## Riscos

1. **Migration destrutiva em banco prod-like:** no deploy, o `up()` dropa `login`/`password` no `sim` real — perda irreversível das credenciais das 8 rows. Intencional (payload-only), mas exige coordenação de deploy.
2. **Breaking change de contrato da API:** qualquer integração externa que hoje consulta processo **sem** mandar credencial no payload passa a receber **422**. Precisa ser comunicado aos consumidores da API antes do deploy.

## Fora de escopo

- Drop da coluna `usar_credencial_tribunal` (flag morta; fica para limpeza futura).
- Remoção do model `Expediente` / sua tabela (vira órfão; sem consumidor).
- Remoção de `ValidarCredencialService` (código morto; não lê credencial armazenada).
- Coluna `uuid` na conexão `sim` / conserto da `Api\TribunalController@show($uuid)` (achado antigo, decisão futura).
- Roles/permissões no CRUD (qualquer usuário autenticado acessa).
