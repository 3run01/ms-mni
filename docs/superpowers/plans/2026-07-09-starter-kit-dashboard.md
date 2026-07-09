# Dashboard padrão do react-starter-kit — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Portar o layout sidebar + dashboard placeholder + login do laravel/react-starter-kit oficial para o ms-mni, com dark mode completo.

**Architecture:** Componentes copiados do clone do kit oficial com 3 adaptações mecânicas (Wayfinder→hrefs literais, layout v3→wrapper v2, radix scoped→meta-package `radix-ui`). Backend ganha só o middleware `HandleAppearance`, a shared prop `sidebarOpen` e o anti-FOUC no root blade. Zero deps novas.

**Tech Stack:** React 19, Inertia v2, Tailwind v4, shadcn (sidebar/avatar/sheet/tooltip/separator/skeleton/breadcrumb), radix-ui meta-package, Laravel 11.

**Spec:** `docs/superpowers/specs/2026-07-09-starter-kit-dashboard-design.md`

## Global Constraints

- **Fonte dos componentes**: clone do kit em `KIT=/tmp/claude-1000/-home-bruno-projetos-ms-mni/f7b9d1dd-a1f6-4547-a5a4-962e6298d970/scratchpad/react-starter-kit`. Se ausente: `git clone --depth 1 https://github.com/laravel/react-starter-kit.git "$KIT"`. Copiar de lá — NÃO redigitar o que o plano manda copiar.
- PHP/pest SÓ no container: `docker compose exec php php ...`. Wrapper `./php` quebrado — não usar. Container: `docker compose up -d --no-deps php` (redis externo: `docker start redis` se parado).
- npm/node no HOST. App dev: `http://localhost:8006`.
- Baseline da suíte: **8 failed, 54 passed** (8 pré-existentes do domínio exportação — não tocar). Aceite: nenhuma falha nova.
- Zero deps npm/composer novas. Imports radix SEMPRE via meta-package `radix-ui` (padrão do projeto), nunca `@radix-ui/react-*`.
- Sem Wayfinder/Ziggy (hrefs literais), sem SSR, sem settings/2FA/passkeys/register/reset.
- Identidade: `--primary`/`--ring` indigo `oklch(0.511 0.262 276.966)` (light) e `oklch(0.673 0.182 276.935)` (dark); `--font-sans` Figtree; logo = lucide `Scale` + "SIM-MNI".
- Desvios YAGNI da spec (aprovados no design, documentados aqui): `ui/collapsible` e `text-link` ficam FORA (nenhum consumidor no escopo); `ui/breadcrumb` entra (a spec o omitiu mas `breadcrumbs.tsx` depende dele).
- Branch de trabalho: criar `feat/starter-kit-dashboard` a partir de `feat/frontend-react-inertia`.
- Working tree tem trabalho paralelo do usuário (`database/seeders/UserSeeder.php` modificado, migration `2026_07_09_000000_seed_admin_user.php` untracked): NÃO commitar, NÃO reverter, NÃO tocar. Commits sempre com paths explícitos (nunca `git add -A` / `git add .`).

---

### Task 1: shadcn ui novos + hooks base + types

**Files:**

- Create: `resources/js/components/ui/sidebar.tsx`, `avatar.tsx`, `sheet.tsx`, `tooltip.tsx`, `separator.tsx`, `skeleton.tsx`, `breadcrumb.tsx`, `placeholder-pattern.tsx` (copiados do kit)
- Create: `resources/js/hooks/use-mobile.tsx`, `use-initials.tsx`, `use-mobile-navigation.ts` (copiados verbatim)
- Modify: `resources/js/types/index.d.ts`

**Interfaces:**

- Consumes: `cn()` de `@/lib/utils`, `Button`/`Input` de `@/components/ui/*` (já existem), meta-package `radix-ui`.
- Produces: exports shadcn padrão — `Sidebar`, `SidebarProvider`, `SidebarInset`, `SidebarTrigger`, `SidebarHeader`, `SidebarContent`, `SidebarFooter`, `SidebarGroup`, `SidebarGroupLabel`, `SidebarMenu`, `SidebarMenuItem`, `SidebarMenuButton`, `useSidebar` (em `@/components/ui/sidebar`); `Avatar`/`AvatarImage`/`AvatarFallback`; `Sheet*`; `Tooltip*`; `Separator`; `Skeleton`; `Breadcrumb`/`BreadcrumbList`/`BreadcrumbItem`/`BreadcrumbLink`/`BreadcrumbPage`/`BreadcrumbSeparator`; `PlaceholderPattern`. Hooks: `useIsMobile(): boolean`, `useInitials(): (fullName: string) => string`, `useMobileNavigation(): () => void`. Types: `BreadcrumbItem`, `NavItem`, `User.avatar?`, `SharedProps.sidebarOpen`.

- [ ] **Step 1: Branch + garantir clone do kit**

```bash
git checkout -b feat/starter-kit-dashboard
KIT=/tmp/claude-1000/-home-bruno-projetos-ms-mni/f7b9d1dd-a1f6-4547-a5a4-962e6298d970/scratchpad/react-starter-kit
[ -d "$KIT" ] || git clone --depth 1 https://github.com/laravel/react-starter-kit.git "$KIT"
ls "$KIT/resources/js/components/ui/sidebar.tsx"
```

Expected: arquivo listado.

- [ ] **Step 2: Copiar os 8 ui/ do kit**

```bash
KIT=/tmp/claude-1000/-home-bruno-projetos-ms-mni/f7b9d1dd-a1f6-4547-a5a4-962e6298d970/scratchpad/react-starter-kit
for f in sidebar avatar sheet tooltip separator skeleton breadcrumb placeholder-pattern; do
  cp "$KIT/resources/js/components/ui/$f.tsx" resources/js/components/ui/$f.tsx
done
```

- [ ] **Step 3: Adaptar imports radix scoped → meta-package**

```bash
cd resources/js/components/ui
sed -i 's|import \* as AvatarPrimitive from "@radix-ui/react-avatar"|import { Avatar as AvatarPrimitive } from "radix-ui"|' avatar.tsx
sed -i 's|import \* as SheetPrimitive from "@radix-ui/react-dialog"|import { Dialog as SheetPrimitive } from "radix-ui"|' sheet.tsx
sed -i 's|import \* as TooltipPrimitive from "@radix-ui/react-tooltip"|import { Tooltip as TooltipPrimitive } from "radix-ui"|' tooltip.tsx
sed -i 's|import \* as SeparatorPrimitive from "@radix-ui/react-separator"|import { Separator as SeparatorPrimitive } from "radix-ui"|' separator.tsx
sed -i 's|import { Slot } from "@radix-ui/react-slot"|import { Slot as SlotPrimitive } from "radix-ui"|' sidebar.tsx breadcrumb.tsx
sed -i 's|asChild ? Slot :|asChild ? SlotPrimitive.Root :|g' sidebar.tsx breadcrumb.tsx
cd ../../../..
grep -rn "@radix-ui" resources/js/components/ui/ && echo "SOBROU SCOPED — FALHOU" || echo "imports ok"
```

Expected: `imports ok` (zero ocorrências de `@radix-ui`). (`sidebar.tsx` tem 5 usos de `Slot`, `breadcrumb.tsx` tem 1 — o sed global cobre todos.)

- [ ] **Step 4: Copiar hooks verbatim**

```bash
KIT=/tmp/claude-1000/-home-bruno-projetos-ms-mni/f7b9d1dd-a1f6-4547-a5a4-962e6298d970/scratchpad/react-starter-kit
mkdir -p resources/js/hooks
cp "$KIT/resources/js/hooks/use-mobile.tsx" "$KIT/resources/js/hooks/use-initials.tsx" "$KIT/resources/js/hooks/use-mobile-navigation.ts" resources/js/hooks/
ls resources/js/hooks/
```

Expected: `use-initials.tsx  use-mobile-navigation.ts  use-mobile.tsx`.

- [ ] **Step 5: Atualizar `resources/js/types/index.d.ts`** (conteúdo completo)

```ts
import { type LucideIcon } from 'lucide-react';

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    external?: boolean;
}

export interface SharedProps {
    app: { name: string };
    auth: { user: User | null };
    sidebarOpen: boolean;
    [key: string]: unknown;
}
```

- [ ] **Step 6: Verificar build**

```bash
npm run typecheck && npm run build
```

Expected: ambos verdes. (Os ui/ novos ainda não são importados por ninguém — typecheck compila por estarem no include do tsconfig.)

- [ ] **Step 7: Commit**

```bash
git add resources/js/components/ui resources/js/hooks resources/js/types/index.d.ts
git commit -m "feat(frontend): ui shadcn do starter kit (sidebar, avatar, sheet, tooltip, separator, skeleton, breadcrumb, placeholder-pattern) + hooks base"
```

---

### Task 2: Dark mode + sidebar state — backend, CSS e boot (TDD no middleware)

**Files:**

- Test: `tests/Feature/AppearanceTest.php` (novo)
- Create: `app/Http/Middleware/HandleAppearance.php`
- Create: `resources/js/hooks/use-appearance.tsx` (copiado verbatim do kit)
- Modify: `bootstrap/app.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `resources/views/app.blade.php`
- Modify: `resources/css/app.css` (reescrito)
- Modify: `resources/js/app.tsx` (+2 linhas)

**Interfaces:**

- Consumes: nada das tasks anteriores.
- Produces: shared prop Inertia `sidebarOpen: boolean` (shape da Task 1); view-shared `$appearance` (`'light'|'dark'|'system'`); hook `useAppearance(): { appearance, resolvedAppearance, updateAppearance(mode) }` e `initializeTheme(): void` em `@/hooks/use-appearance`; tokens CSS `--sidebar-*` + bloco `.dark` que Tasks 3/4 consomem via classes.

Nota (adição à spec, justificada): a spec dizia "sem testes novos", mas esta task muda comportamento de SERVIDOR (middleware + shared prop) — 3 asserts baratos cobrem isso.

- [ ] **Step 1: Escrever teste que falha — `tests/Feature/AppearanceTest.php`**

```php
<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('aplica classe dark no html quando cookie appearance=dark', function () {
    $this->withUnencryptedCookie('appearance', 'dark')
        ->get('/login')
        ->assertOk()
        ->assertSee('class="dark"', false);
});

it('não aplica classe dark sem cookie', function () {
    $this->get('/login')
        ->assertOk()
        ->assertDontSee('class="dark"', false);
});

it('compartilha sidebarOpen a partir do cookie sidebar_state', function () {
    $user = User::factory()->make(['id' => 1]);

    $this->actingAs($user)
        ->withUnencryptedCookie('sidebar_state', 'false')
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('sidebarOpen', false));
});
```

- [ ] **Step 2: Rodar e ver falhar**

```bash
docker compose exec php php vendor/bin/pest tests/Feature/AppearanceTest.php
```

Expected: FAIL — teste 1 (`class="dark"` ausente do html) e teste 3 (prop `sidebarOpen` inexistente) falham; teste 2 passa por vacuidade.

- [ ] **Step 3: Criar `app/Http/Middleware/HandleAppearance.php`**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        View::share('appearance', $request->cookie('appearance') ?? 'system');

        return $next($request);
    }
}
```

- [ ] **Step 4: Registrar middleware + except de cookies em `bootstrap/app.php`**

Trocar o bloco `->withMiddleware(...)` inteiro por:

```php
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            \App\Http\Middleware\HandleAppearance::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'auth.web' => \App\Http\Middleware\AuthenticateWeb::class,
            'pulse.auth' => \App\Http\Middleware\AuthorizePulse::class,
        ]);
    })
```

- [ ] **Step 5: Shared prop `sidebarOpen` em `app/Http/Middleware/HandleInertiaRequests.php`**

No array de `share()`, após o bloco `'auth' => [...],` adicionar:

```php
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
```

- [ ] **Step 6: Anti-FOUC no `resources/views/app.blade.php`** (conteúdo completo)

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'SIM-MNI') }}</title>

    {{-- Detecta preferência dark do sistema e aplica antes do primeiro paint --}}
    <script>
        (function() {
            const appearance = '{{ $appearance ?? "system" }}';

            if (appearance === 'system') {
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                if (prefersDark) {
                    document.documentElement.classList.add('dark');
                }
            }
        })();
    </script>

    {{-- Background do html segue o tema antes do CSS carregar --}}
    <style>
        html {
            background-color: oklch(1 0 0);
        }

        html.dark {
            background-color: oklch(0.145 0 0);
        }
    </style>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

    @viteReactRefresh
    @vite('resources/js/app.tsx')
    @inertiaHead
</head>

<body class="font-sans antialiased">
    @inertia
</body>

</html>
```

- [ ] **Step 7: Copiar hook e ligar no boot**

```bash
KIT=/tmp/claude-1000/-home-bruno-projetos-ms-mni/f7b9d1dd-a1f6-4547-a5a4-962e6298d970/scratchpad/react-starter-kit
cp "$KIT/resources/js/hooks/use-appearance.tsx" resources/js/hooks/
```

Em `resources/js/app.tsx`: adicionar no topo `import { initializeTheme } from '@/hooks/use-appearance';` e, na última linha do arquivo (depois do `createInertiaApp({...});`):

```tsx
initializeTheme();
```

- [ ] **Step 8: Reescrever `resources/css/app.css`** (conteúdo completo — CSS do kit + indigo/Figtree)

```css
@import 'tailwindcss';
@import 'tw-animate-css';

@source '../views';

@custom-variant dark (&:is(.dark *));

@theme {
    --font-sans: 'Figtree', ui-sans-serif, system-ui, sans-serif;

    --radius-lg: var(--radius);
    --radius-md: calc(var(--radius) - 2px);
    --radius-sm: calc(var(--radius) - 4px);

    --color-background: var(--background);
    --color-foreground: var(--foreground);
    --color-card: var(--card);
    --color-card-foreground: var(--card-foreground);
    --color-popover: var(--popover);
    --color-popover-foreground: var(--popover-foreground);
    --color-primary: var(--primary);
    --color-primary-foreground: var(--primary-foreground);
    --color-secondary: var(--secondary);
    --color-secondary-foreground: var(--secondary-foreground);
    --color-muted: var(--muted);
    --color-muted-foreground: var(--muted-foreground);
    --color-accent: var(--accent);
    --color-accent-foreground: var(--accent-foreground);
    --color-destructive: var(--destructive);
    --color-destructive-foreground: var(--destructive-foreground);
    --color-border: var(--border);
    --color-input: var(--input);
    --color-ring: var(--ring);
    --color-sidebar: var(--sidebar);
    --color-sidebar-foreground: var(--sidebar-foreground);
    --color-sidebar-primary: var(--sidebar-primary);
    --color-sidebar-primary-foreground: var(--sidebar-primary-foreground);
    --color-sidebar-accent: var(--sidebar-accent);
    --color-sidebar-accent-foreground: var(--sidebar-accent-foreground);
    --color-sidebar-border: var(--sidebar-border);
    --color-sidebar-ring: var(--sidebar-ring);
}

:root {
    --background: oklch(1 0 0);
    --foreground: oklch(0.145 0 0);
    --card: oklch(1 0 0);
    --card-foreground: oklch(0.145 0 0);
    --popover: oklch(1 0 0);
    --popover-foreground: oklch(0.145 0 0);
    --primary: oklch(0.511 0.262 276.966);
    --primary-foreground: oklch(0.985 0 0);
    --secondary: oklch(0.97 0 0);
    --secondary-foreground: oklch(0.205 0 0);
    --muted: oklch(0.97 0 0);
    --muted-foreground: oklch(0.556 0 0);
    --accent: oklch(0.97 0 0);
    --accent-foreground: oklch(0.205 0 0);
    --destructive: oklch(0.577 0.245 27.325);
    --destructive-foreground: oklch(0.577 0.245 27.325);
    --border: oklch(0.922 0 0);
    --input: oklch(0.922 0 0);
    --ring: oklch(0.511 0.262 276.966);
    --radius: 0.625rem;
    --sidebar: oklch(0.985 0 0);
    --sidebar-foreground: oklch(0.145 0 0);
    --sidebar-primary: oklch(0.511 0.262 276.966);
    --sidebar-primary-foreground: oklch(0.985 0 0);
    --sidebar-accent: oklch(0.97 0 0);
    --sidebar-accent-foreground: oklch(0.205 0 0);
    --sidebar-border: oklch(0.922 0 0);
    --sidebar-ring: oklch(0.87 0 0);
}

.dark {
    --background: oklch(0.145 0 0);
    --foreground: oklch(0.985 0 0);
    --card: oklch(0.145 0 0);
    --card-foreground: oklch(0.985 0 0);
    --popover: oklch(0.145 0 0);
    --popover-foreground: oklch(0.985 0 0);
    --primary: oklch(0.673 0.182 276.935);
    --primary-foreground: oklch(0.145 0 0);
    --secondary: oklch(0.269 0 0);
    --secondary-foreground: oklch(0.985 0 0);
    --muted: oklch(0.269 0 0);
    --muted-foreground: oklch(0.708 0 0);
    --accent: oklch(0.269 0 0);
    --accent-foreground: oklch(0.985 0 0);
    --destructive: oklch(0.396 0.141 25.723);
    --destructive-foreground: oklch(0.637 0.237 25.331);
    --border: oklch(0.269 0 0);
    --input: oklch(0.269 0 0);
    --ring: oklch(0.673 0.182 276.935);
    --sidebar: oklch(0.205 0 0);
    --sidebar-foreground: oklch(0.985 0 0);
    --sidebar-primary: oklch(0.673 0.182 276.935);
    --sidebar-primary-foreground: oklch(0.145 0 0);
    --sidebar-accent: oklch(0.269 0 0);
    --sidebar-accent-foreground: oklch(0.985 0 0);
    --sidebar-border: oklch(0.269 0 0);
    --sidebar-ring: oklch(0.439 0 0);
}

@layer base {
    * {
        @apply border-border;
    }

    body {
        @apply bg-background text-foreground;
    }
}
```

(Diferenças vs kit: sem `@source` do Pagination — sem paginação Blade aqui; `--font-sans` Figtree; primary/ring/sidebar-primary indigo nos dois modos; sem tokens `--chart-*` — nenhum gráfico no escopo.)

- [ ] **Step 9: Rodar testes e ver passar**

```bash
docker compose exec php php vendor/bin/pest tests/Feature/AppearanceTest.php
docker compose exec php php vendor/bin/pest
npm run typecheck && npm run build
```

Expected: AppearanceTest 3 passed; suíte **8 failed, 57 passed**; typecheck/build verdes.

- [ ] **Step 10: Commit**

```bash
git add tests/Feature/AppearanceTest.php app/Http/Middleware/HandleAppearance.php app/Http/Middleware/HandleInertiaRequests.php bootstrap/app.php resources/views/app.blade.php resources/css/app.css resources/js/app.tsx resources/js/hooks/use-appearance.tsx
git commit -m "feat: dark mode (middleware appearance, anti-FOUC, tokens do kit) e sidebar state compartilhado"
```

---

### Task 3: Shell da sidebar — components e layouts

**Files:**

- Create: `resources/js/components/app-shell.tsx`, `app-content.tsx`, `app-sidebar-header.tsx`, `app-sidebar.tsx`, `app-logo.tsx`, `app-logo-icon.tsx`, `breadcrumbs.tsx`, `nav-main.tsx`, `nav-user.tsx`, `user-info.tsx`, `user-menu-content.tsx`, `input-error.tsx`
- Modify: `resources/js/layouts/app-layout.tsx` (reescrito), `resources/js/layouts/auth-layout.tsx` (reescrito)

**Interfaces:**

- Consumes: ui/hooks/types da Task 1; `useAppearance` da Task 2; shared props `auth.user` e `sidebarOpen`.
- Produces: `AppLayout({ breadcrumbs?: BreadcrumbItem[], children })` default export em `@/layouts/app-layout`; `AuthLayout({ title: string, description: string, children })` default export em `@/layouts/auth-layout`; `InputError({ message?: string })` em `@/components/input-error`. Task 4 consome exatamente essas assinaturas.

- [ ] **Step 1: Copiar os verbatim do kit**

```bash
KIT=/tmp/claude-1000/-home-bruno-projetos-ms-mni/f7b9d1dd-a1f6-4547-a5a4-962e6298d970/scratchpad/react-starter-kit
cp "$KIT/resources/js/components/breadcrumbs.tsx" "$KIT/resources/js/components/user-info.tsx" "$KIT/resources/js/components/nav-user.tsx" "$KIT/resources/js/components/app-sidebar-header.tsx" "$KIT/resources/js/components/input-error.tsx" resources/js/components/
```

- [ ] **Step 2: Ajustar tipos nos copiados**

`nav-user.tsx` e `app-sidebar-header.tsx` compilam como estão. Em `nav-user.tsx`, a linha `const { auth } = usePage().props;` não tipa `auth` — trocar por:

```tsx
import { type SharedProps } from '@/types';
```

(adicionar ao bloco de imports) e:

```tsx
    const { auth } = usePage<SharedProps>().props;
```

- [ ] **Step 3: Criar `resources/js/components/app-shell.tsx`** (adaptado — sem variant header, YAGNI)

```tsx
import { usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

import { SidebarProvider } from '@/components/ui/sidebar';
import { type SharedProps } from '@/types';

export function AppShell({ children }: { children: ReactNode }) {
    const isOpen = usePage<SharedProps>().props.sidebarOpen;

    return <SidebarProvider defaultOpen={isOpen}>{children}</SidebarProvider>;
}
```

- [ ] **Step 4: Criar `resources/js/components/app-content.tsx`**

```tsx
import * as React from 'react';

import { SidebarInset } from '@/components/ui/sidebar';

export function AppContent({ children, ...props }: React.ComponentProps<'main'>) {
    return <SidebarInset {...props}>{children}</SidebarInset>;
}
```

- [ ] **Step 5: Criar logo — `resources/js/components/app-logo-icon.tsx` e `app-logo.tsx`**

`app-logo-icon.tsx`:

```tsx
import { Scale } from 'lucide-react';
import { type ComponentProps } from 'react';

export default function AppLogoIcon(props: ComponentProps<typeof Scale>) {
    return <Scale {...props} />;
}
```

`app-logo.tsx`:

```tsx
import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                <AppLogoIcon className="size-5" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-semibold">SIM-MNI</span>
            </div>
        </>
    );
}
```

- [ ] **Step 6: Criar `resources/js/components/nav-main.tsx`** (adaptado: label por grupo + links externos; sem use-current-url do kit — `page.url` basta)

```tsx
import { Link, usePage } from '@inertiajs/react';

import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { type NavItem } from '@/types';

export function NavMain({ label, items }: { label: string; items: NavItem[] }) {
    const page = usePage();

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={!item.external && page.url.startsWith(item.href)}
                            tooltip={{ children: item.title }}
                        >
                            {item.external ? (
                                <a href={item.href} target="_blank" rel="noreferrer">
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </a>
                            ) : (
                                <Link href={item.href} prefetch>
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </Link>
                            )}
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
```

- [ ] **Step 7: Criar `resources/js/components/user-menu-content.tsx`** (adaptado: sem Settings; submenu Tema + Sair)

```tsx
import { router } from '@inertiajs/react';
import { Check, LogOut, Monitor, Moon, Sun } from 'lucide-react';

import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useAppearance, type Appearance } from '@/hooks/use-appearance';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { type User } from '@/types';

const themes: { value: Appearance; label: string; icon: typeof Sun }[] = [
    { value: 'light', label: 'Claro', icon: Sun },
    { value: 'dark', label: 'Escuro', icon: Moon },
    { value: 'system', label: 'Sistema', icon: Monitor },
];

export function UserMenuContent({ user }: { user: User }) {
    const cleanup = useMobileNavigation();
    const { appearance, updateAppearance } = useAppearance();
    const activeTheme = themes.find((t) => t.value === appearance) ?? themes[2];
    const ActiveIcon = activeTheme.icon;

    function handleLogout() {
        cleanup();
        router.post('/logout');
    }

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuSub>
                    <DropdownMenuSubTrigger>
                        <ActiveIcon className="mr-2 size-4" />
                        Tema
                    </DropdownMenuSubTrigger>
                    <DropdownMenuSubContent>
                        {themes.map(({ value, label, icon: Icon }) => (
                            <DropdownMenuItem key={value} onSelect={() => updateAppearance(value)}>
                                <Icon /> {label}
                                {appearance === value && <Check className="ml-auto size-4" />}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuSubContent>
                </DropdownMenuSub>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem onSelect={handleLogout}>
                <LogOut className="mr-2" />
                Sair
            </DropdownMenuItem>
        </>
    );
}
```

- [ ] **Step 8: Criar `resources/js/components/app-sidebar.tsx`** (adaptado: 2 grupos, sem NavFooter)

```tsx
import { Link } from '@inertiajs/react';
import { Activity, FileText, LayoutGrid, LayoutList } from 'lucide-react';

import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { type NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
        icon: LayoutGrid,
    },
];

const monitoringNavItems: NavItem[] = [
    { title: 'Laravel Pulse', href: '/pulse', icon: Activity, external: true },
    { title: 'Horizon', href: '/horizon', icon: LayoutList, external: true },
    { title: 'Log Viewer', href: '/logs', icon: FileText, external: true },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain label="Platform" items={mainNavItems} />
                <NavMain label="Monitoramento" items={monitoringNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
```

- [ ] **Step 9: Reescrever `resources/js/layouts/app-layout.tsx`** (substitui o top-nav antigo)

```tsx
import { type ReactNode } from 'react';

import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { type BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: ReactNode;
}) {
    return (
        <AppShell>
            <AppSidebar />
            <AppContent className="overflow-x-hidden">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
```

- [ ] **Step 10: Reescrever `resources/js/layouts/auth-layout.tsx`** (port do auth-simple-layout)

```tsx
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

import AppLogoIcon from '@/components/app-logo-icon';

export default function AuthLayout({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link href="/" className="flex flex-col items-center gap-2 font-medium">
                            <div className="mb-1 flex h-9 w-9 items-center justify-center rounded-md">
                                <AppLogoIcon className="size-9 text-foreground" />
                            </div>
                            <span className="sr-only">{title}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">{title}</h1>
                            <p className="text-center text-sm text-muted-foreground">{description}</p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
```

- [ ] **Step 11: Verificar** — pages antigos ainda usam as assinaturas antigas dos layouts, então este step SÓ compila o que existe:

```bash
npm run typecheck
```

Expected: **FALHA** em `pages/auth/login.tsx` (AuthLayout agora exige `title`/`description`) e possivelmente `pages/dashboard.tsx`. Essa quebra é esperada e é resolvida na Task 4 — NÃO conserte as pages aqui. Se falhar em QUALQUER outro arquivo (components/, layouts/), conserte antes de commitar.

- [ ] **Step 12: Commit**

```bash
git add resources/js/components resources/js/layouts
git commit -m "feat(frontend): shell da sidebar do starter kit (app-shell, sidebar, nav, user menu, layouts)"
```

---

### Task 4: Pages — dashboard placeholder e login restyle (TDD)

**Files:**

- Modify: `resources/js/pages/dashboard.tsx` (reescrito)
- Modify: `resources/js/pages/auth/login.tsx` (reescrito)
- Test: existentes `tests/Feature/DashboardTest.php` + `tests/Feature/Auth/LoginPageTest.php` (sem mudanças — são o gate)

**Interfaces:**

- Consumes: `AppLayout({ breadcrumbs, children })`, `AuthLayout({ title, description, children })`, `InputError({ message })`, `PlaceholderPattern` — tudo das Tasks 1/3.
- Produces: páginas Inertia `dashboard` e `auth/login` (mesmos nomes de componente — testes existentes não mudam).

- [ ] **Step 1: Rodar testes existentes ANTES (gate de regressão)**

```bash
docker compose exec php php vendor/bin/pest tests/Feature/DashboardTest.php tests/Feature/Auth/LoginPageTest.php
```

Expected: 4 passed (backend não mudou; pages antigas ainda renderizam — typecheck é que está quebrado).

- [ ] **Step 2: Reescrever `resources/js/pages/dashboard.tsx`** (port do kit)

```tsx
import { Head } from '@inertiajs/react';

import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

export default function Dashboard() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="grid auto-rows-min gap-4 md:grid-cols-3">
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                </div>
                <div className="relative min-h-[100vh] flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
                    <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                </div>
            </div>
        </AppLayout>
    );
}
```

- [ ] **Step 3: Reescrever `resources/js/pages/auth/login.tsx`** (visual do kit, `useForm` v2 mantido)

```tsx
import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEvent } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/login');
    }

    return (
        <AuthLayout title="Acesse sua conta" description="Sistema de Integração MNI">
            <Head title="Login" />

            <form onSubmit={submit} className="flex flex-col gap-6">
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="email">Email</Label>
                        <Input
                            id="email"
                            type="email"
                            required
                            autoFocus
                            autoComplete="email"
                            placeholder="email@exemplo.com"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password">Senha</Label>
                        <Input
                            id="password"
                            type="password"
                            required
                            autoComplete="current-password"
                            placeholder="Senha"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="flex items-center gap-3">
                        <Checkbox
                            id="remember"
                            checked={data.remember}
                            onCheckedChange={(checked) => setData('remember', checked === true)}
                        />
                        <Label htmlFor="remember" className="font-normal">
                            Lembrar de mim
                        </Label>
                    </div>

                    <Button type="submit" className="mt-4 w-full" disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Entrar
                    </Button>
                </div>
            </form>
        </AuthLayout>
    );
}
```

(Bloco de erro geral do login antigo morre de propósito — kit usa só erro por campo via `InputError`.)

- [ ] **Step 4: Rodar tudo e ver passar**

```bash
docker compose exec php php vendor/bin/pest
npm run typecheck && npm run build
```

Expected: suíte **8 failed, 57 passed** (idêntica ao fim da Task 2); typecheck agora VERDE (pages novas casam com as assinaturas dos layouts); build verde.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/dashboard.tsx resources/js/pages/auth/login.tsx
git commit -m "feat(frontend): dashboard placeholder e login no visual do starter kit"
```

---

### Task 5: Verificação e2e no browser

**Files:** nenhum (verificação; fixes pontuais se algo falhar).

**Interfaces:**

- Consumes: tudo das Tasks 1–4.
- Produces: confirmação real (spec: sidebar colapsa/persiste, tema aplica/persiste sem FOUC, mobile Sheet, logout, breadcrumb).

- [ ] **Step 1: Build fresco + app de pé**

```bash
npm run build
docker compose up -d --no-deps php
docker compose exec php php artisan optimize:clear
curl -sI http://localhost:8006/login | head -1
```

Expected: `HTTP/1.1 200 OK`.

- [ ] **Step 2: Fluxo no browser** (MCP Playwright; ATENÇÃO: usar a tool `browser_resize` do MCP — nunca `page.setViewportSize` dentro de run_code, corrompe o input do browser)

1. `/login`: logo + "Acesse sua conta" + form do kit; submit inválido → `InputError` por campo, sem reload.
2. Login válido (precisa de user no banco local — criar via tinker `User::factory()->create(['email' => 'teste@e2e.local'])`, senha `password`; deletar no fim) → `/dashboard`.
3. Dashboard: sidebar inset à esquerda (logo SIM-MNI, grupos Platform + Monitoramento), header com trigger + breadcrumb "Dashboard", 3 cards placeholder + 1 grande.
4. Colapsar sidebar (trigger) → vira ícones; reload → continua colapsada (cookie `sidebar_state`).
5. Menu do user (footer sidebar) → dropdown com nome/email, submenu Tema: escolher "Escuro" → página escurece na hora; reload → continua escura SEM flash branco; "Sistema" e "Claro" também aplicam.
6. Mobile (browser_resize 375×812): sidebar some, trigger abre Sheet com a nav completa.
7. Sair → `/login`; `/dashboard` deslogado → redirect `/login`.
8. Console do browser: zero erros.

- [ ] **Step 3: Se algo falhar** — corrigir, re-rodar `docker compose exec php php vendor/bin/pest` + `npm run build`, commitar fix como `fix(frontend): <o que era>`.

---

## Critério de aceite global

1. `docker compose exec php php vendor/bin/pest` → **8 failed, 57 passed** (8 = pré-existentes exportação; 3 novos de Appearance + 4 existentes verdes).
2. `npm run typecheck && npm run build` verdes.
3. Fluxo e2e da Task 5 completo (sidebar, tema, mobile, logout).
4. `git diff feat/frontend-react-inertia -- routes/ app/Http/Controllers/` vazio (rotas e controllers intocados).
5. Zero deps novas: `git diff feat/frontend-react-inertia -- package.json composer.json` vazio.
