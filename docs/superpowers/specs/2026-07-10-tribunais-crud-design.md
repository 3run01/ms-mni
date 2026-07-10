# Tela de Tribunais — listar, cadastrar, editar, ativar/desativar

**Data:** 2026-07-10
**Status:** Aprovado
**Base:** branch `feat/starter-kit-dashboard` (layout sidebar do starter kit já implementado)

## Contexto e motivação

`App\Models\Tribunal` guarda a configuração de integração MNI por tribunal (credenciais, URLs de webservice, códigos de documento, flags) na tabela `tribunais` da conexão **`sim`** (Postgres separado; local = `sim_producao`, 8 registros). Hoje só existe API read-only (`Api\TribunalController`); qualquer ajuste é feito direto no banco. Esta é a primeira CRUD web do app — estabelece o padrão para as próximas.

### Achados da exploração (fatos que moldam o design)

1. **Model diverge da tabela real.** `$fillable` contém `codigo_tribunal` e `segmento_justica` — colunas que **não existem**; a tabela tem `codigo_seeu`, `usar_credencial_tribunal` e `usar_codigo_documento_padrao` — que **faltam** no fillable.
2. **Coluna `uuid` não existe** no DB `sim` local (a migration `2024_12_09_add_uuid_to_tribunais_table` roda na conexão default `ms_mni`, não na `sim`). Consequência: `Api\TribunalController@show($uuid)` está quebrada contra este banco. **Fora do escopo desta tela** (rotas web usam `id`); registrado para decisão futura.
3. `password` do tribunal é credencial sensível — model já a esconde via `$hidden`; a tela nunca a exibe.

## Decisões

| Decisão | Escolha | Motivo |
| --- | --- | --- |
| Escopo | Listar + criar + editar + ativar/desativar | Config sem edição obriga recriar a cada typo; toggle cobre o caso comum; sem exclusão (desativar basta) |
| Campos | Tabela real (19 editáveis) + correção do fillable | Model passa a refletir o schema; form completo |
| Estrutura | Páginas dedicadas (index/create/edit) com form compartilhado | 20 campos não cabem em modal; padrão starter kit; vira template das próximas CRUDs |
| Identificação nas rotas | `id` (route model binding) | `uuid` não existe no DB local |
| Password | Write-only | Nunca exibida; no edit, campo vazio = mantém a atual |
| Feedback | Flash mínimo (`flash.success` shared + banner na index) | Sem sonner/toast — YAGNI |
| Paginação/busca | Não | 8 registros |

## Backend

### Rotas (`routes/web.php`, dentro do grupo `auth:web`)

```php
Route::get('/tribunais', [TribunalController::class, 'index'])->name('tribunais.index');
Route::get('/tribunais/criar', [TribunalController::class, 'create'])->name('tribunais.create');
Route::post('/tribunais', [TribunalController::class, 'store'])->name('tribunais.store');
Route::get('/tribunais/{tribunal}/editar', [TribunalController::class, 'edit'])->name('tribunais.edit');
Route::put('/tribunais/{tribunal}', [TribunalController::class, 'update'])->name('tribunais.update');
Route::patch('/tribunais/{tribunal}/ativo', [TribunalController::class, 'toggleAtivo'])->name('tribunais.toggle');
```

Import: `App\Http\Controllers\TribunalController` (novo — o `Api\TribunalController` fica intocado).

### Controller `app/Http/Controllers/TribunalController.php`

- `index`: todos os tribunais (inclusive inativos), ordenados por `nome`, com `select` explícito só das colunas da listagem (`id`, `nome`, `tipo`, `versao_mni`, `ativo`) → `Inertia::render('tribunais/index')`.
- `create`: `Inertia::render('tribunais/create', ['tipos' => Tribunal::getTipos()])`.
- `store(TribunalRequest)`: cria e redireciona para `tribunais.index` com `->with('success', 'Tribunal criado.')`.
- `edit(Tribunal $tribunal)`: render `tribunais/edit` com `tipos` + os campos editáveis atuais **exceto `password`** (`makeHidden` já cobre; enviar explicitamente só o necessário).
- `update(TribunalRequest, Tribunal $tribunal)`: se `password` vier vazia/null, remove do payload antes do `update()` (mantém a atual); redireciona com `->with('success', 'Tribunal atualizado.')`.
- `toggleAtivo(Tribunal $tribunal)`: inverte `ativo`, salva, `back()` com flash.

### FormRequest `app/Http/Requests/TribunalRequest.php` (store + update)

- `nome`: `required|string|max:255`
- `tipo`: `required|string|in:` (valores de `Tribunal::getTipos()`)
- `login`: `nullable|string|max:255`
- `password`: `required|string` no store; `nullable|string` no update (detecta via método HTTP/rota)
- URLs (`url_webservice_mni`, `url_webservice_mni_consultar_processo`, `url_webservice_mni_complementar`, `url_consulta_pje`, `url_webservice_mni_criminal`, `url_recuperar_senha_tribunal`): `nullable|url|max:2048`
- Códigos (`codigo_peticao_inicial`, `codigo_peticao_avulsa`, `codigo_certidao_inicio_fim`, `codigo_seeu`): `nullable|integer`
- `versao_mni`: `nullable|string|max:50`
- Booleans (`ativo`, `enviar_dados_criminais`, `usar_credencial_tribunal`, `usar_codigo_documento_padrao`): `boolean`

### Model (melhoria direcionada)

- `$fillable`: **remover** `codigo_tribunal`, `segmento_justica` (colunas fantasma); **adicionar** `codigo_seeu`, `usar_credencial_tribunal`, `usar_codigo_documento_padrao`.
- `boot()`/uuid: fica como está por padrão. Risco conhecido: `creating` seta atributo `uuid` e a coluna não existe no DB local — o **teste de store da implementação decide**: se o insert falhar, o `creating` ganha guard (`Schema::connection('sim')->hasColumn('tribunais', 'uuid')` memoizado ou try silencioso). Resolução fica explícita no plano após rodar o teste.

### Shared prop de flash

`HandleInertiaRequests::share()` ganha:

```php
'flash' => [
    'success' => $request->session()->get('success'),
],
```

(`SharedProps` TS ganha `flash: { success: string | null }`.)

## Frontend

### ui/ novos (3)

- `select.tsx`: copiado do kit clone (`sed` radix scoped → meta-package, mesmo processo das tasks anteriores).
- `table.tsx`, `switch.tsx`: código canônico shadcn (kit não os tem); radix `Switch` via meta-package. Zero deps npm novas.

### Páginas `resources/js/pages/tribunais/`

- `index.tsx` — `<AppLayout breadcrumbs={[{ title: 'Tribunais', href: '/tribunais' }]}>`; header com título "Tribunais" + botão "Novo tribunal" (`Link` → `/tribunais/criar`); banner de sucesso quando `flash.success` (dispensável via estado local); `Table` com colunas Nome | Tipo | Versão MNI | Ativo | "" (ações). Ativo = `Switch` que dispara `router.patch(`/tribunais/${id}/ativo`, {}, { preserveScroll: true })`. Ações = link "Editar".
- `create.tsx` — wrapper: breadcrumbs Tribunais → Novo; `<TribunalForm tipos={tipos} />` (useForm com defaults vazios, POST `/tribunais`).
- `edit.tsx` — wrapper: breadcrumbs Tribunais → nome do tribunal; `<TribunalForm tipos={tipos} tribunal={tribunal} />` (PUT `/tribunais/{id}`, password inicia vazia).

### Form compartilhado `resources/js/components/tribunal-form.tsx`

`useForm` + seções com heading:

1. **Identificação**: nome (Input), tipo (Select com `tipos`), versao_mni (Input), ativo (Checkbox)
2. **Credenciais**: login (Input), password (Input type=password, autocomplete="new-password"; no edit, placeholder/hint "Preencha somente para trocar a senha"), usar_credencial_tribunal (Checkbox)
3. **URLs MNI**: os 6 campos url (Input type=url)
4. **Códigos**: os 4 campos (Input type=number)
5. **Flags**: enviar_dados_criminais, usar_codigo_documento_padrao (Checkbox)

`InputError` por campo; submit com spinner (`processing`), botão "Salvar"; link "Cancelar" de volta pra index.

### Sidebar

`app-sidebar.tsx`: item "Tribunais" (`href: '/tribunais'`, ícone `Landmark`) no grupo Platform, abaixo de Dashboard. O `startsWith` do `nav-main` mantém o item ativo nas subrotas (criar/editar) — comportamento desejado.

## Testes (Pest)

- `database/factories/TribunalFactory.php` nova (valores fake pros campos reais).
- Testes de escrita usam `Illuminate\Foundation\Testing\DatabaseTransactions` com `protected array $connectionsToTransact = ['sim'];` (rollback automático — não suja o `sim_producao` local).
- `tests/Feature/TribunalCrudTest.php`:
  - index renderiza `tribunais/index` com lista
  - create renderiza `tribunais/create` com `tipos`
  - store cria registro e redireciona com flash
  - store valida: `nome`/`tipo`/`password` obrigatórios; `tipo` fora da lista rejeitado; URL inválida rejeitada
  - update altera campos; **password em branco mantém a senha atual**
  - toggle inverte `ativo`
  - guest em `/tribunais` redireciona pra `/login`
- `npm run typecheck` + `npm run build` verdes.
- E2E browser: listar, criar tribunal de teste, editar, toggle, validação visível.

## Fora de escopo

- Coluna `uuid` na conexão `sim` / conserto da API `show($uuid)` (achado registrado; decisão do usuário depois)
- Exclusão (soft delete), busca, paginação, ordenação por coluna
- Roles/permissões (qualquer usuário autenticado acessa)
- Criptografia da coluna `password` no banco (estado atual mantido)
