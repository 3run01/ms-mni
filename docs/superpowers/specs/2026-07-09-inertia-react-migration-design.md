# Migração do frontend: Blade → React + Inertia

**Data:** 2026-07-09
**Status:** Aprovado

## Contexto e motivação

O ms-mni é um microserviço Laravel 11 (API MNI) com superfície web mínima: login e dashboard. O frontend atual usa Blade + Tailwind 3 + Alpine (via CDN), sem framework JS. A UI vai crescer (novas telas de consulta de processos, tribunais, documentos), então a migração para React + Inertia prepara essa base agora, enquanto a superfície é mínima.

Escopo real descoberto na exploração:

- **Páginas web de verdade: 2** — `auth/login.blade.php` e `dashboard.blade.php` (mais o layout `layouts/app.blade.php` e `welcome.blade.php`, que só redireciona).
- `processo/download.blade.php` **não é página web** — é template de PDF renderizado via `PDF::loadView` em `ExportacaoProcessoService`. Fica em Blade.
- A rota web `GET /processo/download` aponta para `DownloadProcessoController::index`, **método que não existe** (500 se acessada). Rota morta.
- O layout atual carrega Tailwind duas vezes (build Vite + CDN) e Alpine via CDN.

## Decisões

| Decisão | Escolha | Motivo |
| --- | --- | --- |
| Framework | React 19 + Inertia v2 | Pedido do usuário; base para crescer |
| Linguagem | TypeScript | Padrão do ecossistema; segurança conforme UI cresce |
| UI kit | shadcn/ui | Padrão dos starter kits Laravel; componentes no repo, customizáveis |
| Tailwind | Upgrade v3.4 → v4 | Momento mais barato (2 páginas); padrão do shadcn atual |
| Abordagem | Instalação manual limpa | Zero conflito com `AuthController` custom; estrutura de pastas segue convenções do starter kit oficial |
| SSR | Não | Ferramenta interna atrás de login |
| Ziggy/Wayfinder | Não (por ora) | 2 páginas, hrefs literais bastam; adicionar quando UI crescer |

## Stack e dependências

**Composer (novo):**

- `inertiajs/inertia-laravel` ^2.0

**npm (novo):**

- `react` ^19, `react-dom` ^19, `@inertiajs/react` ^2
- `@vitejs/plugin-react`
- `typescript`, `@types/react`, `@types/react-dom`
- `tailwindcss` ^4, `@tailwindcss/vite`
- shadcn/ui via CLI (`components.json`); componentes iniciais: button, input, label, checkbox, card, dropdown-menu

**npm (removido):** `autoprefixer`, `postcss` (Tailwind v4 via plugin Vite dispensa ambos) e `axios` do `package.json` (usado só pelo `bootstrap.js`, que será deletado; o Inertia traz o axios dele como dependência transitiva).

**Vite:** sobe de versão se exigido por `@tailwindcss/vite` / `@vitejs/plugin-react` (versão exata fixada no plano de implementação).

## Backend

Mudança mínima — a lógica de auth não muda.

1. **Middleware `HandleInertiaRequests`** (`app/Http/Middleware/`), registrado no grupo `web` em `bootstrap/app.php`. Shared props:
   - `auth.user`: `{ id, name, email }` ou `null`
   - `app.name`
   - (errors/flash já fluem pelo padrão do Inertia)
2. **Root template** `resources/views/app.blade.php`: `@inertia`, `@viteReactRefresh`, `@vite('resources/js/app.tsx')`, meta CSRF, fonte Figtree (bunny.net). **Sem** CDN de Tailwind/Alpine.
3. **Rotas (`routes/web.php`):**
   - `AuthController@showLogin` → `Inertia::render('auth/login')`
   - Closure do dashboard → `Inertia::render('dashboard')`
   - `POST /login` e `POST /logout` intocados — Inertia submete e segue redirects; erros de validação chegam ao React pela prop `errors`
   - **Remover** rota morta `GET /processo/download`
4. **Octane:** nada especial; `HandleInertiaRequests` é middleware padrão.

## Frontend

```text
resources/js/
├─ app.tsx                # createInertiaApp + resolvePageComponent
├─ pages/
│  ├─ auth/login.tsx      # AuthLayout; useForm({email, password, remember});
│  │                      #   erros de validação por campo + bloco de erro geral
│  └─ dashboard.tsx       # AppLayout; 3 cards estáticos (Consulta, Monitoramento, Logs)
├─ layouts/
│  ├─ app-layout.tsx      # nav superior: link Dashboard; dropdown Monitoramento
│  │                      #   (/pulse, /horizon, /logs, target _blank); nome do user;
│  │                      #   botão Sair (POST /logout via router do Inertia); menu mobile
│  └─ auth-layout.tsx     # container centrado
├─ components/ui/         # shadcn (gerados via CLI)
├─ lib/utils.ts           # cn()
└─ types/index.d.ts       # User, PageProps { auth: { user: User | null } }
```

- **Visual: port, não redesign.** Mesma identidade atual (indigo, cards azul/verde/amarelo no dashboard), reconstruída com componentes shadcn.
- Dropdowns Alpine → `DropdownMenu` do shadcn.
- `resources/css/app.css` reescrito: `@import "tailwindcss"` + tokens de tema do shadcn (CSS-first, sem `tailwind.config.js`).
- **Hack removido:** o form de login atual monta o action com `env('FORCE_HTTPS')`/`secure_url()`. O Inertia usa URL relativa; forçar HTTPS é responsabilidade de `URL::forceScheme`/proxy, não do form.
- `tsconfig.json` com alias `@/*` → `resources/js/*` (mesmo alias no Vite).

## Limpeza

**Deletar:**

- `resources/views/welcome.blade.php`, `dashboard.blade.php`, `auth/login.blade.php`, `layouts/app.blade.php`
- `resources/js/app.js`, `resources/js/bootstrap.js`
- `tailwind.config.js`, `postcss.config.js`

**Não tocar:**

- `resources/views/mail/**`, `resources/views/vendor/**`
- `resources/views/layouts/relatorio/**` e `resources/views/processo/download.blade.php` (templates de PDF)
- Toda a API (`routes/api.php`, controllers, services)

## Tratamento de erros

- Validação continua 100% no backend (`AuthController`). No login, erros por campo + bloco geral vêm da prop `errors` do Inertia (equivalente ao `@error`/`$errors->any()` atual).
- Falha de sessão expirada (419) segue o comportamento padrão do Laravel/Inertia; sem tratamento custom nesta fase.

## Testes e verificação

- Suíte Pest existente deve continuar verde (asserts de status/redirect de auth não dependem de Blade).
- Novos testes de feature com `AssertableInertia`: `GET /login` renderiza componente `auth/login`; `GET /dashboard` autenticado renderiza `dashboard` com `auth.user` preenchido; guest em `/dashboard` redireciona.
- `npm run build` verde (TypeScript compila, bundle gera).
- Verificação manual: login com credencial inválida (erros aparecem) → login válido → dashboard → dropdown Monitoramento → logout.

## Deploy

Pipeline (Docker/Coolify) já executa `vite build`; nada muda além dos artefatos gerados (React bundles). Nenhuma env var nova.

## Fora de escopo

- Novas telas (consulta de processos etc.) — vêm depois, sobre esta base
- SSR, dark mode, Ziggy/Wayfinder
- Qualquer mudança na API ou nos fluxos de PDF/mail
