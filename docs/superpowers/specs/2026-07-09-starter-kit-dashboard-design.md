# Dashboard padrão do Laravel react-starter-kit

**Data:** 2026-07-09
**Status:** Aprovado
**Base:** branch `feat/frontend-react-inertia` (migração React+Inertia concluída — spec `2026-07-09-inertia-react-migration-design.md`)

## Contexto e motivação

A migração para React+Inertia portou o visual antigo (top-nav + cards coloridos). O usuário quer o visual do starter kit oficial (<https://github.com/laravel/react-starter-kit>): sidebar colapsável, dashboard placeholder, dark mode — a base sobre a qual as telas futuras vão crescer.

O kit oficial atual usa Laravel 12 + Inertia v3 + Wayfinder + Fortify (2FA, passkeys, settings). O ms-mni usa Laravel 11 + Inertia v2, auth custom mínimo (login/logout), sem Wayfinder. O port adapta o kit a essa realidade.

**Fonte dos arquivos:** clone do repo oficial (`git clone --depth 1 https://github.com/laravel/react-starter-kit.git`). Copiar de lá, não redigitar.

## Decisões

| Decisão | Escolha | Motivo |
| --- | --- | --- |
| Conteúdo do dashboard | Placeholder puro do kit (3 cards `PlaceholderPattern` + 1 grande) | Pronto pra receber conteúdo real; cards coloridos atuais morrem |
| Dark mode | Sim — light/dark/system, toggle no menu do user | Kit já vem pronto; momento natural (revoga o "sem dark mode" da spec anterior) |
| Links Monitoramento | Grupo "Monitoramento" no `NavMain` da sidebar (Pulse/Horizon/Log Viewer, `_blank`) | Nav principal, padrão do kit |
| Login | Restyle para `auth-simple-layout` do kit | Visual alinhado; mesmos campos/fluxo |
| Abordagem | Port do kit clonado (não CLI, não upgrade v3) | shadcn CLI quebrado no ambiente (EACCES em dir root-owned); upgrade Inertia v3+Wayfinder explode escopo |

## Adaptações mecânicas (kit → ms-mni)

1. **Wayfinder → hrefs literais**: `dashboard()`/`home()`/`logout()` de `@/routes` viram `'/dashboard'`, `'/'`, POST `/logout` (padrão do projeto; consistente com "sem Ziggy/Wayfinder").
2. **Layout Inertia v3 → v2**: kit usa `Dashboard.layout = { breadcrumbs }`; aqui vira wrapper explícito `<AppLayout breadcrumbs={[...]}>` (e `<AuthLayout title description>` no login).
3. **Imports radix scoped → meta-package**: `import * as X from "@radix-ui/react-*"` vira `import { X } from "radix-ui"` — padrão dos `ui/` existentes. Zero deps npm novas (o meta já cobre avatar, dialog/sheet, tooltip, separator, collapsible).
4. **Sem Fortify**: settings/2FA/passkeys/register/reset/verify/forgot-password não entram (sem backend). `user-menu-content` perde "Settings", ganha submenu Tema + "Sair".
5. **`router.flushAll()`** (v3-only) no logout do kit → não usar; logout = `router.post('/logout')` como hoje.

## Arquivos

### `resources/js/components/ui/` — copiados do kit (adaptando só imports radix)

`sidebar.tsx`, `avatar.tsx`, `sheet.tsx`, `tooltip.tsx`, `separator.tsx`, `skeleton.tsx`, `collapsible.tsx`, `placeholder-pattern.tsx`.
Os já existentes (button, input, label, checkbox, card, dropdown-menu) ficam como estão.

### `resources/js/components/` — copiados/adaptados do kit

- `app-shell.tsx` — SidebarProvider wrapper (variant `sidebar`); recebe `defaultOpen` da shared prop `sidebarOpen`
- `app-sidebar.tsx` — sidebar `collapsible="icon" variant="inset"`; header = logo; content = `NavMain`; footer = `NavUser` (sem `NavFooter` — descartado, YAGNI)
- `app-sidebar-header.tsx` — header com `SidebarTrigger` + `Breadcrumbs`
- `app-content.tsx` — `SidebarInset` wrapper
- `app-logo.tsx` + `app-logo-icon.tsx` — **logo custom**: ícone lucide `Scale` num quadrado `bg-sidebar-primary` + texto "SIM-MNI" (substitui logo Laravel)
- `nav-main.tsx` — adaptado para 2 grupos: "Platform" (Dashboard, ícone `LayoutGrid`, `<Link>` Inertia) e "Monitoramento" (Pulse `/pulse`, Horizon `/horizon`, Log Viewer `/logs` — `<a target="_blank" rel="noreferrer">`, ícones `Activity`/`LayoutList`/`FileText`)
- `nav-user.tsx` — botão do user no footer da sidebar com dropdown (usa `useSidebar().isMobile` pro lado do popover)
- `user-info.tsx` — `Avatar` com iniciais (`use-initials`); user do projeto não tem campo avatar — componente tolera ausência
- `user-menu-content.tsx` — adaptado: `UserInfo` + separador + **submenu Tema** (Claro/Escuro/Sistema via `use-appearance`, ícones `Sun`/`Moon`/`Monitor`) + separador + "Sair" (`router.post('/logout')`, ícone `LogOut`)
- `breadcrumbs.tsx` — verbatim do kit
- `input-error.tsx`, `text-link.tsx` — verbatim (login usa)

### `resources/js/hooks/`

`use-appearance.tsx` (verbatim — localStorage + cookie + matchMedia via `useSyncExternalStore`), `use-mobile.tsx`, `use-initials.tsx`, `use-mobile-navigation.ts`.

### `resources/js/layouts/`

- `app-layout.tsx` — **substituído**: interface `{ children, breadcrumbs?: BreadcrumbItem[] }`; monta `AppShell` > `AppSidebar` + `AppContent` > `AppSidebarHeader` + children (port do `app-sidebar-layout` do kit)
- `auth-layout.tsx` — **substituído** pelo `auth-simple-layout`: logo centrado clicável (href `/`) + `title` + `description` + children

### `resources/js/pages/`

- `dashboard.tsx` — port do kit: grid 3 `PlaceholderPattern` (aspect-video) + card grande; wrapper `<AppLayout breadcrumbs={[{ title: 'Dashboard', href: '/dashboard' }]}>`
- `auth/login.tsx` — port do login do kit: `<AuthLayout title="Acesse sua conta" description="Sistema de Integração MNI">`, campos email/senha (`InputError` por campo), checkbox "Lembrar de mim", botão submit com spinner (`processing`); **sem** link forgot-password/register; mantém `useForm` + POST `/login` relativo; bloco de erro geral morre (kit usa só erro por campo)

### `resources/js/types/index.d.ts`

- `+ BreadcrumbItem { title: string; href: string }`
- `+ NavItem { title: string; href: string; icon?: LucideIcon; external?: boolean }`
- `SharedProps` ganha `sidebarOpen: boolean`

## CSS (`resources/css/app.css`)

Adota o `app.css` do kit inteiro: tokens `--sidebar-*` no `@theme inline`, bloco `:root` + bloco `.dark { ... }` completo, `@custom-variant dark` (já existe). **Duas customizações preservadas:**

- `--primary`/`--ring`: indigo `oklch(0.511 0.262 276.966)` no light; no dark, indigo mais claro `oklch(0.673 0.182 276.935)` (indigo-400) para contraste
- `--font-sans`: Figtree

`tw-animate-css` já importado, fica.

## Backend (mínimo)

1. **`app/Http/Middleware/HandleAppearance.php`** (novo, port do kit): `View::share('appearance', $request->cookie('appearance') ?? 'system')`. Registrado com `append` no grupo web em `bootstrap/app.php`.
2. **`HandleInertiaRequests`**: shared prop nova `'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true'`.
3. **`resources/views/app.blade.php`**: `<html ... @class(['dark' => ($appearance ?? 'system') == 'dark'])>` + script inline anti-FOUC do kit (resolve `system` via `prefers-color-scheme` antes do paint) + bloco `<style>` do kit que define `background-color` do html em light/dark.
4. **Cookies `appearance` e `sidebar_state` são client-side** (JS grava, backend só lê) → adicionar ambos ao `EncryptCookies`/`encrypt_cookies` except list — Laravel 11: `$middleware->encryptCookies(except: ['appearance', 'sidebar_state'])` em `bootstrap/app.php`.

Rotas, controllers, auth: **intocados**. Zero deps composer/npm novas.

## Limpeza

- Conteúdo antigo de `layouts/app-layout.tsx` e `layouts/auth-layout.tsx` substituído (top-nav com dropdown Monitoramento morre — funcionalidade migra pra sidebar).
- `pages/dashboard.tsx` antigo (cards coloridos) substituído.
- Nada mais deletado; `components/ui/` existentes ficam.

## Testes e verificação

- 4 feature tests existentes (LoginPageTest, DashboardTest) continuam válidos sem mudança — mesmos nomes de componente Inertia, mesma shared prop `auth.user`.
- Suíte esperada: **8 failed, 54 passed** (inalterada; 8 pré-existentes de exportação).
- `npm run typecheck` + `npm run build` verdes.
- E2E browser: login (visual novo, erro por campo), dashboard com sidebar (colapsar/expandir persiste via cookie), grupo Monitoramento (3 links `_blank`), menu do user (tema Claro/Escuro/Sistema aplica e persiste sem FOUC no reload), mobile (sidebar vira Sheet), logout, breadcrumb "Dashboard" visível.

## Fora de escopo

- Settings/profile/password, 2FA, passkeys, register/reset/verify-email, forgot-password, welcome page
- Upgrade Inertia v3, Wayfinder, `laravel-vite-plugin` v3
- Conteúdo real do dashboard (placeholders de propósito)
