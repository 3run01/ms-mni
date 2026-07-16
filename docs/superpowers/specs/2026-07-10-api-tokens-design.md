# Tokens de API gerenciáveis — Design

**Data:** 2026-07-10
**Status:** Aprovado

## Problema

A API é protegida por um único token estático definido em `.env` (`API_TOKEN`), comparado no middleware `ValidateApiToken` contra `config('services.api.token')`. Não há como criar, nomear, revogar ou auditar tokens sem deploy.

## Objetivo

Tela no painel para gerar e gerenciar tokens de acesso à API. A API passa a aceitar somente tokens gerados por essa tela. O token do `.env` deixa de valer (corte seco).

## Decisões

- **Tokens de aplicação** (um por sistema consumidor, ex: clickpdv, SIM) — não vinculados a usuário.
- **Header mantido:** `X-API-Token`. Zero mudança de contrato nos clientes; só o valor do token muda.
- **Abordagem:** tabela própria `api_tokens` + middleware atual adaptado. Sanctum e Passport descartados (exigem tokenable/OAuth — máquina demais para token de aplicação com header customizado).
- **Corte seco:** `config('services.api.token')` e `API_TOKEN` removidos. Sem período de transição.

## Modelo de dados

Migration `create_api_tokens_table`:

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `name` | varchar(255) unique | ex: "clickpdv" |
| `token` | varchar(64) unique | SHA-256 do plaintext; plaintext nunca persiste |
| `ativo` | boolean default true | toggle sem excluir |
| `expires_at` | timestamp nullable | null = nunca expira |
| `last_used_at` | timestamp nullable | atualizado no uso |
| `created_at` / `updated_at` | timestamps | |

- Geração: `'mni_' . Str::random(48)`. Prefixo facilita identificação em logs e secret scanners.
- Model `ApiToken`: scope `valido()` (ativo + não expirado), helper `findValid(string $plainToken): ?self`.
- Factory para testes.

## Middleware / API

`ValidateApiToken` reescrito:

1. Lê `X-API-Token`. Ausente → 401 `{"message": "Token inválido ou não fornecido"}` (mensagem atual mantida).
2. `hash('sha256', $token)` → busca registro com `ativo = true` e (`expires_at` null ou > agora).
3. Não achou → mesmo 401 (não vaza se token existe/expirou). Achou → atualiza `last_used_at` e segue.

Detalhes:
- `last_used_at`: gravado via `withoutTimestamps()` (não toca `updated_at`), com throttle — só grava se `last_used_at` for null ou > 1 minuto atrás. Evita um write por request.
- Rotas da API inalteradas.
- Remover `services.api.token` de `config/services.php` e `API_TOKEN` de `.env.example`.

## Tela (padrão tribunais)

Rotas web autenticadas:

- `GET /tokens` → index (`tokens.index`)
- `GET /tokens/criar` → create (`tokens.create`)
- `POST /tokens` → store (`tokens.store`)
- `PATCH /tokens/{token}/ativo` → toggle (`tokens.toggle`)
- `DELETE /tokens/{token}` → destroy/revogar (`tokens.destroy`)

Sem edit: nome é definido na criação; para mudar, revoga e gera outro.

- Controller web `ApiTokenController` + FormRequest: `name` obrigatório/único, `expires_at` opcional e futura. Mensagens PT-BR.
- **store:** cria registro, redireciona ao index com o token plaintext em flash de sessão.

Páginas Inertia (`resources/js/pages/tokens/`):

- **index.tsx:** banner destacado quando há token em flash ("copie agora — não será mostrado novamente", botão copiar). Tabela: nome, status (switch ativo), expira em, último uso, criado em, ação revogar com confirmação.
- **create.tsx:** campos nome + data de expiração (opcional).
- Sidebar: item "Tokens de API".

## Testes

- **Middleware:** token válido passa; ausente / errado / inativo / expirado → 401; `last_used_at` atualizado.
- **CRUD web:** store gera `mni_*`, persiste hash (não plaintext), plaintext no flash; nome duplicado falha; toggle; destroy; rotas exigem auth.
- **Testes de API existentes:** hoje usam o token do config — passam a criar `ApiToken` via factory.

## Rollout

1. Deploy + `migrate`.
2. Gerar token na tela para cada consumidor.
3. Atualizar o valor do token nos clientes (mesmo header).
4. Remover `API_TOKEN` do `.env`.

Entre os passos 1 e 3 os consumidores ficam sem acesso (janela aceita — decisão de corte seco).
