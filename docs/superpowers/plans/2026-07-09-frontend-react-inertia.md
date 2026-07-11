# Migração Frontend Blade → React + Inertia — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Substituir as 2 páginas web Blade (login, dashboard) por React 19 + Inertia v2 + TypeScript + Tailwind v4 + shadcn/ui, mantendo o backend de auth intocado.

**Architecture:** Instalação manual limpa do Inertia sobre o Laravel 11 existente. `AuthController@login/logout` não mudam — só a camada de view vira `Inertia::render()`. Estrutura de `resources/js/` segue convenções do starter kit oficial (pages/, layouts/, components/, lib/, types/). Templates de PDF (`layouts/relatorio/`, `processo/download.blade.php`) e mails ficam em Blade.

**Tech Stack:** Laravel 11, Inertia v2 (`inertiajs/inertia-laravel` + `@inertiajs/react`), React 19, TypeScript 5, Vite 5, Tailwind CSS v4 (`@tailwindcss/vite`), shadcn/ui, Pest 3.

**Spec:** `docs/superpowers/specs/2026-07-09-inertia-react-migration-design.md`

## Global Constraints

- PHP roda **dentro do container**: `docker compose exec php php ...` e `docker compose exec php composer ...`. O wrapper `./php` do repo usa `-u composer` que não existe na imagem — NÃO use o wrapper.
- Container: subir com `docker compose up -d --no-deps php` (o serviço redis do compose conflita com container `redis` já existente no host; se redis estiver parado: `docker start redis`).
- App roda em `http://localhost:8001` (CONTAINER_PORT default 8001).
- npm/node rodam no **host** (node v23, npm 10 disponíveis).
- Após qualquer `composer` no container, rodar `docker compose exec php chown -R 1000:1000 /var/www/app/composer.json /var/www/app/composer.lock /var/www/app/vendor` (container roda como root; bind mount em `/var/www/app`).
- **Baseline de testes: 9 failed, 49 passed.** 8 falhas são do domínio de exportação (pré-existentes, fora do escopo). 1 (`ExampleTest`, rota `/`) é corrigida na Task 4. Critério de aceite: **nenhuma falha nova** vs baseline + testes novos verdes.
- Páginas Blade quebram entre Task 1 e Task 4 (input do Vite muda antes das páginas serem convertidas). Esperado — branch de feature; nenhum teste existente renderiza essas páginas.
- Identidade visual: port, não redesign (indigo primário, cards azul/verde/amarelo, fonte Figtree).
- Sem SSR, sem dark mode, sem Ziggy/Wayfinder.
- Branch de trabalho: criar `feat/frontend-react-inertia` a partir de `feat/remocao-ocr-samia` (a spec está commitada lá) antes da Task 1.

---

### Task 1: Tooling frontend base (TS + Vite + Tailwind v4 + boot Inertia)

**Files:**

- Modify: `package.json` (reescrever)
- Create: `tsconfig.json`
- Modify: `vite.config.js` (reescrever)
- Modify: `resources/css/app.css` (reescrever)
- Create: `resources/js/app.tsx`
- Create: `resources/js/lib/utils.ts`
- Create: `resources/js/types/index.d.ts`
- Delete: `resources/js/app.js`, `resources/js/bootstrap.js`, `tailwind.config.js`, `postcss.config.js`

**Interfaces:**

- Consumes: nada (primeira task)
- Produces: `cn(...inputs: ClassValue[]): string` em `@/lib/utils`; tipos `User { id: number; name: string; email: string }` e `SharedProps { app: { name: string }; auth: { user: User | null } }` em `@/types`; alias `@/*` → `resources/js/*`; entrada Vite `resources/js/app.tsx` que resolve páginas via glob `./pages/**/*.tsx`; script npm `typecheck`.

- [ ] **Step 1: Criar branch**

```bash
git checkout -b feat/frontend-react-inertia
```

- [ ] **Step 2: Reescrever `package.json`**

Conteúdo completo (mantém `chokidar`/`concurrently` já existentes; remove `axios`, `postcss`, `autoprefixer`, `tailwindcss` v3):

```json
{
    "private": true,
    "type": "module",
    "scripts": {
        "build": "vite build",
        "dev": "vite",
        "typecheck": "tsc --noEmit"
    },
    "dependencies": {
        "@inertiajs/react": "^2.0",
        "clsx": "^2.1.1",
        "lucide-react": "^0.525.0",
        "react": "^19.0.0",
        "react-dom": "^19.0.0",
        "tailwind-merge": "^3.3.0"
    },
    "devDependencies": {
        "@tailwindcss/vite": "^4.1.0",
        "@types/react": "^19.0.0",
        "@types/react-dom": "^19.0.0",
        "@vitejs/plugin-react": "^4.3.0",
        "chokidar": "^4.0.3",
        "concurrently": "^9.0.1",
        "laravel-vite-plugin": "^1.0",
        "tailwindcss": "^4.1.0",
        "tw-animate-css": "^1.3.0",
        "typescript": "^5.7.0",
        "vite": "^5.4.0"
    }
}
```

Nota: se algum pacote falhar por versão inexistente, usar `npm install <pacote>@latest` para o pacote específico e seguir — os ranges acima são pisos, não tetos.

- [ ] **Step 3: Criar `tsconfig.json`**

```json
{
    "compilerOptions": {
        "target": "ES2022",
        "module": "ESNext",
        "moduleResolution": "bundler",
        "jsx": "react-jsx",
        "strict": true,
        "esModuleInterop": true,
        "skipLibCheck": true,
        "isolatedModules": true,
        "noEmit": true,
        "resolveJsonModule": true,
        "forceConsistentCasingInFileNames": true,
        "types": ["vite/client"],
        "baseUrl": ".",
        "paths": {
            "@/*": ["./resources/js/*"]
        }
    },
    "include": ["resources/js/**/*.ts", "resources/js/**/*.tsx", "resources/js/**/*.d.ts"]
}
```

- [ ] **Step 4: Reescrever `vite.config.js`**

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dirname = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(dirname, 'resources/js'),
        },
    },
});
```

- [ ] **Step 5: Reescrever `resources/css/app.css`** (Tailwind v4 CSS-first + tokens shadcn; primário = indigo-600 para manter identidade)

```css
@import 'tailwindcss';
@import 'tw-animate-css';

@custom-variant dark (&:is(.dark *));

@theme inline {
    --font-sans: 'Figtree', ui-sans-serif, system-ui, sans-serif;
    --radius-sm: calc(var(--radius) - 4px);
    --radius-md: calc(var(--radius) - 2px);
    --radius-lg: var(--radius);
    --radius-xl: calc(var(--radius) + 4px);
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
    --color-border: var(--border);
    --color-input: var(--input);
    --color-ring: var(--ring);
}

:root {
    --radius: 0.5rem;
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
    --border: oklch(0.922 0 0);
    --input: oklch(0.922 0 0);
    --ring: oklch(0.511 0.262 276.966);
}

@layer base {
    * {
        @apply border-border outline-ring/50;
    }

    body {
        @apply bg-background text-foreground font-sans antialiased;
    }
}
```

- [ ] **Step 6: Criar `resources/js/lib/utils.ts`**

```ts
import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}
```

- [ ] **Step 7: Criar `resources/js/types/index.d.ts`**

```ts
export interface User {
    id: number;
    name: string;
    email: string;
}

export interface SharedProps {
    app: { name: string };
    auth: { user: User | null };
    [key: string]: unknown;
}
```

- [ ] **Step 8: Criar `resources/js/app.tsx`**

```tsx
import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'SIM-MNI';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#4f46e5',
    },
});
```

- [ ] **Step 9: Deletar arquivos antigos**

```bash
rm resources/js/app.js resources/js/bootstrap.js tailwind.config.js postcss.config.js
```

- [ ] **Step 10: Instalar e verificar build**

```bash
npm install
npm run typecheck
npm run build
```

Expected: `npm install` sem erros de peer dependency; `typecheck` sem erros; `build` gera `public/build/manifest.json` contendo entrada `resources/js/app.tsx`.

- [ ] **Step 11: Commit**

```bash
git add package.json package-lock.json tsconfig.json vite.config.js resources/css/app.css resources/js tailwind.config.js postcss.config.js
git commit -m "feat(frontend): tooling base React 19 + TS + Tailwind v4 + boot Inertia"
```

---

### Task 2: Plumbing Inertia no backend

**Files:**

- Modify: `composer.json`/`composer.lock` (via composer require)
- Create: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `bootstrap/app.php`
- Create: `resources/views/app.blade.php`
- Create: `config/inertia.php`

**Interfaces:**

- Consumes: entrada Vite `resources/js/app.tsx` (Task 1).
- Produces: middleware `HandleInertiaRequests` com shared props `app.name` (string) e `auth.user` (`{id, name, email} | null`) — exatamente o shape de `SharedProps` da Task 1; root view Blade `app`; config de testing do Inertia apontando `page_paths` para `resources/js/pages` (necessário para `assertInertia->component()` nas Tasks 4/5).

- [ ] **Step 1: Garantir container de pé e instalar pacote**

```bash
docker compose up -d --no-deps php
docker compose exec php composer require inertiajs/inertia-laravel:^2.0
docker compose exec php chown -R 1000:1000 /var/www/app/composer.json /var/www/app/composer.lock /var/www/app/vendor
```

Expected: pacote instalado sem conflito de versão (Laravel 11, PHP 8.2+ atendem os requisitos do v2).

- [ ] **Step 2: Criar `app/Http/Middleware/HandleInertiaRequests.php`**

```php
<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
            ],
            'auth' => [
                'user' => $request->user()?->only(['id', 'name', 'email']),
            ],
        ];
    }
}
```

- [ ] **Step 3: Registrar middleware em `bootstrap/app.php`**

Trocar o bloco `->withMiddleware(...)` inteiro por:

```php
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'auth.web' => \App\Http\Middleware\AuthenticateWeb::class,
            'pulse.auth' => \App\Http\Middleware\AuthorizePulse::class,
        ]);
    })
```

- [ ] **Step 4: Criar root template `resources/views/app.blade.php`**

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'SIM-MNI') }}</title>

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

- [ ] **Step 5: Criar `config/inertia.php`** (paths de página em minúsculas — o default do pacote é `js/Pages`, que não existe aqui)

```php
<?php

return [
    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [
            resource_path('js/pages'),
        ],
        'page_extensions' => ['js', 'jsx', 'ts', 'tsx'],
    ],
];
```

- [ ] **Step 6: Verificar que nada quebrou**

```bash
docker compose exec php php artisan route:list --path=login
docker compose exec php php vendor/bin/pest
```

Expected: `route:list` mostra GET/POST `login` sem erro (middleware registrado ok); Pest: **9 failed, 49 passed** — idêntico ao baseline, nenhuma falha nova.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock app/Http/Middleware/HandleInertiaRequests.php bootstrap/app.php resources/views/app.blade.php config/inertia.php
git commit -m "feat(backend): plumbing Inertia (middleware, root view, config de testing)"
```

---

### Task 3: shadcn/ui — components.json + componentes base

**Files:**

- Create: `components.json`
- Create (via CLI): `resources/js/components/ui/button.tsx`, `input.tsx`, `label.tsx`, `checkbox.tsx`, `card.tsx`, `dropdown-menu.tsx`
- Modify (via CLI): `package.json`/`package-lock.json` (radix + class-variance-authority)

**Interfaces:**

- Consumes: `cn()` de `@/lib/utils`, tokens CSS de `resources/css/app.css`, alias `@/*` (Task 1).
- Produces: componentes `Button` (props: `variant?: 'default'|'ghost'|..., size?: 'default'|'sm'|'icon', asChild?`), `Input`, `Label`, `Checkbox` (props: `checked`, `onCheckedChange`), `Card`/`CardHeader`/`CardTitle`/`CardDescription`/`CardContent`, `DropdownMenu`/`DropdownMenuTrigger`/`DropdownMenuContent`/`DropdownMenuItem`/`DropdownMenuLabel`/`DropdownMenuSeparator` em `@/components/ui/*` — exports nomeados, API padrão shadcn new-york.

- [ ] **Step 1: Criar `components.json`**

```json
{
    "$schema": "https://ui.shadcn.com/schema.json",
    "style": "new-york",
    "rsc": false,
    "tsx": true,
    "tailwind": {
        "config": "",
        "css": "resources/css/app.css",
        "baseColor": "neutral",
        "cssVariables": true
    },
    "iconLibrary": "lucide",
    "aliases": {
        "components": "@/components",
        "utils": "@/lib/utils",
        "ui": "@/components/ui",
        "lib": "@/lib",
        "hooks": "@/hooks"
    }
}
```

- [ ] **Step 2: Adicionar componentes**

```bash
npx shadcn@latest add button input label checkbox card dropdown-menu --yes --overwrite
```

Expected: 6 arquivos criados em `resources/js/components/ui/`; deps novas no `package.json` (`@radix-ui/react-checkbox`, `@radix-ui/react-dropdown-menu`, `@radix-ui/react-label`, `@radix-ui/react-slot`, `class-variance-authority`). Se o CLI falhar (rede/detecção), copiar o código dos componentes de <https://ui.shadcn.com/docs/components> e instalar as deps radix manualmente — NÃO reescrever componentes do zero.

- [ ] **Step 3: Verificar**

```bash
npm run typecheck
npm run build
```

Expected: ambos verdes.

- [ ] **Step 4: Commit**

```bash
git add components.json package.json package-lock.json resources/js/components
git commit -m "feat(frontend): shadcn/ui base (button, input, label, checkbox, card, dropdown-menu)"
```

---

### Task 4: Página de login em React (TDD)

**Files:**

- Test: `tests/Feature/Auth/LoginPageTest.php`
- Modify: `tests/Feature/ExampleTest.php`
- Create: `resources/js/layouts/auth-layout.tsx`
- Create: `resources/js/pages/auth/login.tsx`
- Modify: `app/Http/Controllers/AuthController.php`

**Interfaces:**

- Consumes: componentes shadcn (Task 3), `AuthLayout` (criado aqui), middleware/config Inertia (Task 2).
- Produces: página Inertia `auth/login`; `AuthLayout({ children }: PropsWithChildren)` default export em `@/layouts/auth-layout` (container centrado, usado só por páginas de auth).

- [ ] **Step 1: Escrever teste que falha — `tests/Feature/Auth/LoginPageTest.php`**

```php
<?php

use Inertia\Testing\AssertableInertia as Assert;

it('renderiza a página de login via Inertia', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/login'));
});

it('valida campos obrigatórios no login', function () {
    $this->from('/login')
        ->post('/login', [])
        ->assertRedirect('/login')
        ->assertInvalid(['email', 'password']);
});
```

- [ ] **Step 2: Rodar e ver falhar**

```bash
docker compose exec php php vendor/bin/pest tests/Feature/Auth/LoginPageTest.php
```

Expected: FAIL — primeiro teste falha ("Not a valid Inertia response", a rota ainda devolve Blade e a view `auth.login` referencia asset inexistente); segundo teste passa (validação já existe no controller).

- [ ] **Step 3: Criar `resources/js/layouts/auth-layout.tsx`**

```tsx
import { type PropsWithChildren } from 'react';

export default function AuthLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4 py-12 sm:px-6 lg:px-8">
            <div className="w-full max-w-md">{children}</div>
        </div>
    );
}
```

- [ ] **Step 4: Criar `resources/js/pages/auth/login.tsx`**

```tsx
import { Head, useForm } from '@inertiajs/react';
import { LockKeyhole } from 'lucide-react';
import { type FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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

    const errorMessages = Object.values(errors);

    return (
        <AuthLayout>
            <Head title="Login" />

            <Card>
                <CardHeader className="text-center">
                    <CardTitle className="text-2xl">Acesse sua conta</CardTitle>
                    <CardDescription>Sistema de Integração MNI</CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="space-y-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                autoComplete="email"
                                required
                                placeholder="Endereço de email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                aria-invalid={!!errors.email}
                            />
                            {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="password">Senha</Label>
                            <Input
                                id="password"
                                type="password"
                                autoComplete="current-password"
                                required
                                placeholder="Senha"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                aria-invalid={!!errors.password}
                            />
                            {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                        </div>

                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="remember"
                                checked={data.remember}
                                onCheckedChange={(checked) => setData('remember', checked === true)}
                            />
                            <Label htmlFor="remember" className="font-normal">
                                Lembrar de mim
                            </Label>
                        </div>

                        <Button type="submit" className="w-full" disabled={processing}>
                            <LockKeyhole /> Entrar
                        </Button>

                        {errorMessages.length > 0 && (
                            <div className="rounded-md bg-red-50 p-4">
                                <h3 className="text-sm font-medium text-red-800">Erro na autenticação</h3>
                                <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-red-700">
                                    {errorMessages.map((message) => (
                                        <li key={message}>{message}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </form>
                </CardContent>
            </Card>
        </AuthLayout>
    );
}
```

Nota: o Blade antigo montava o `action` do form com `env('FORCE_HTTPS')`/`secure_url()` — hack removido de propósito; `post('/login')` relativo respeita o scheme da página (HTTPS forçado é papel de proxy/`URL::forceScheme`).

- [ ] **Step 5: Trocar a view no `AuthController`**

Em `app/Http/Controllers/AuthController.php`, adicionar imports e trocar `showLogin`:

```php
use Inertia\Inertia;
use Inertia\Response;
```

```php
    /**
     * Show the login form.
     */
    public function showLogin(): Response
    {
        return Inertia::render('auth/login');
    }
```

`login()` e `logout()` ficam exatamente como estão.

- [ ] **Step 6: Rodar testes e ver passar**

```bash
docker compose exec php php vendor/bin/pest tests/Feature/Auth/LoginPageTest.php
```

Expected: PASS (2 testes).

- [ ] **Step 7: Corrigir `ExampleTest` (falha de baseline na rota `/`)**

Em `tests/Feature/ExampleTest.php`, trocar o método de teste por:

```php
    /**
     * A rota raiz redireciona para o login.
     */
    public function test_the_application_redirects_root_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
```

- [ ] **Step 8: Rodar suíte + build**

```bash
docker compose exec php php vendor/bin/pest
npm run typecheck && npm run build
```

Expected: Pest **8 failed, 52 passed** (as 8 falhas restantes = domínio exportação, pré-existentes); build verde.

- [ ] **Step 9: Commit**

```bash
git add tests/Feature/Auth/LoginPageTest.php tests/Feature/ExampleTest.php resources/js/layouts/auth-layout.tsx resources/js/pages/auth/login.tsx app/Http/Controllers/AuthController.php
git commit -m "feat(frontend): página de login em React/Inertia"
```

---

### Task 5: Dashboard + AppLayout em React (TDD)

**Files:**

- Test: `tests/Feature/DashboardTest.php`
- Create: `resources/js/layouts/app-layout.tsx`
- Create: `resources/js/pages/dashboard.tsx`
- Modify: `routes/web.php`

**Interfaces:**

- Consumes: shadcn `Button`/`DropdownMenu*` (Task 3), `SharedProps` de `@/types` (Task 1), shared prop `auth.user` (Task 2).
- Produces: página Inertia `dashboard`; `AppLayout({ children }: PropsWithChildren)` default export em `@/layouts/app-layout` — layout autenticado com nav (usado por toda tela futura).

- [ ] **Step 1: Escrever teste que falha — `tests/Feature/DashboardTest.php`**

```php
<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('renderiza o dashboard via Inertia com o usuário autenticado', function () {
    $user = User::factory()->make(['id' => 1]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('auth.user.name', $user->name)
            ->where('auth.user.email', $user->email));
});

it('redireciona visitante do dashboard para o login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});
```

(`User::factory()->make()` não toca o banco — `actingAs` aceita model não persistido.)

- [ ] **Step 2: Rodar e ver falhar**

```bash
docker compose exec php php vendor/bin/pest tests/Feature/DashboardTest.php
```

Expected: FAIL — primeiro teste ("Not a valid Inertia response"); segundo passa (middleware auth já redireciona).

- [ ] **Step 3: Criar `resources/js/layouts/app-layout.tsx`**

```tsx
import { Link, router, usePage } from '@inertiajs/react';
import { Activity, ChevronDown, FileText, LayoutList, Menu } from 'lucide-react';
import { type PropsWithChildren } from 'react';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { type SharedProps } from '@/types';

const monitoringLinks = [
    { href: '/pulse', label: 'Laravel Pulse', icon: Activity },
    { href: '/horizon', label: 'Horizon', icon: LayoutList },
    { href: '/logs', label: 'Log Viewer', icon: FileText },
];

export default function AppLayout({ children }: PropsWithChildren) {
    const { auth } = usePage<SharedProps>().props;

    function logout() {
        router.post('/logout');
    }

    return (
        <div className="min-h-screen bg-gray-100">
            <nav className="border-b border-gray-200 bg-white shadow-sm">
                <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center gap-8">
                        <h1 className="text-xl font-bold text-gray-900">SIM-MNI</h1>

                        <div className="hidden items-center gap-6 sm:flex">
                            <Link
                                href="/dashboard"
                                className="text-sm font-medium text-gray-500 hover:text-gray-700"
                            >
                                Dashboard
                            </Link>

                            <DropdownMenu>
                                <DropdownMenuTrigger className="flex items-center gap-1 text-sm font-medium text-gray-500 hover:text-gray-700">
                                    Monitoramento <ChevronDown className="size-4" />
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="start">
                                    {monitoringLinks.map(({ href, label, icon: Icon }) => (
                                        <DropdownMenuItem key={href} asChild>
                                            <a href={href} target="_blank" rel="noreferrer">
                                                <Icon /> {label}
                                            </a>
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>

                    <div className="hidden items-center gap-3 sm:flex">
                        <span className="text-sm text-gray-700">{auth.user?.name}</span>
                        <Button variant="ghost" size="sm" onClick={logout}>
                            Sair
                        </Button>
                    </div>

                    <div className="sm:hidden">
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon" aria-label="Abrir menu principal">
                                    <Menu />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                <DropdownMenuLabel>
                                    <div>{auth.user?.name}</div>
                                    <div className="text-xs font-normal text-muted-foreground">
                                        {auth.user?.email}
                                    </div>
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem asChild>
                                    <Link href="/dashboard">Dashboard</Link>
                                </DropdownMenuItem>
                                {monitoringLinks.map(({ href, label, icon: Icon }) => (
                                    <DropdownMenuItem key={href} asChild>
                                        <a href={href} target="_blank" rel="noreferrer">
                                            <Icon /> {label}
                                        </a>
                                    </DropdownMenuItem>
                                ))}
                                <DropdownMenuSeparator />
                                <DropdownMenuItem onSelect={logout}>Sair</DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
            </nav>

            <main>{children}</main>
        </div>
    );
}
```

(No Blade antigo o menu mobile tinha escopos `x-data` separados e nunca abria — bug pré-existente; a versão React usa um `DropdownMenu`, que funciona.)

- [ ] **Step 4: Criar `resources/js/pages/dashboard.tsx`**

```tsx
import { Head } from '@inertiajs/react';
import { BarChart3, FileText, ScrollText } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';

const cards = [
    {
        title: 'Consulta de Processos',
        description: 'Consulte processos judiciais via API',
        icon: FileText,
        container: 'border-blue-200 bg-blue-50',
        iconColor: 'text-blue-600',
        titleColor: 'text-blue-900',
        descriptionColor: 'text-blue-700',
    },
    {
        title: 'Monitoramento',
        description: 'Acompanhe métricas do sistema',
        icon: BarChart3,
        container: 'border-green-200 bg-green-50',
        iconColor: 'text-green-600',
        titleColor: 'text-green-900',
        descriptionColor: 'text-green-700',
    },
    {
        title: 'Logs do Sistema',
        description: 'Visualize logs de aplicação',
        icon: ScrollText,
        container: 'border-yellow-200 bg-yellow-50',
        iconColor: 'text-yellow-600',
        titleColor: 'text-yellow-900',
        descriptionColor: 'text-yellow-700',
    },
];

export default function Dashboard() {
    return (
        <AppLayout>
            <Head title="Dashboard" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                        <h1 className="mb-6 text-2xl font-bold">Dashboard - SIM-MNI</h1>

                        <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                            {cards.map(
                                ({ title, description, icon: Icon, container, iconColor, titleColor, descriptionColor }) => (
                                    <div
                                        key={title}
                                        className={`flex items-center gap-4 rounded-lg border p-6 ${container}`}
                                    >
                                        <Icon className={`size-8 shrink-0 ${iconColor}`} />
                                        <div>
                                            <h3 className={`text-lg font-medium ${titleColor}`}>{title}</h3>
                                            <p className={descriptionColor}>{description}</p>
                                        </div>
                                    </div>
                                ),
                            )}
                        </div>

                        <div className="mt-8">
                            <h2 className="mb-4 text-xl font-semibold">Bem-vindo ao SIM-MNI</h2>
                            <p className="text-gray-600">
                                Sistema de Integração MNI para consulta e monitoramento de processos judiciais. Use o
                                menu acima para navegar pelas funcionalidades disponíveis.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
```

- [ ] **Step 5: Trocar a rota do dashboard em `routes/web.php`**

Adicionar `use Inertia\Inertia;` no topo e trocar a closure:

```php
    Route::get('/dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
```

- [ ] **Step 6: Rodar testes e ver passar**

```bash
docker compose exec php php vendor/bin/pest tests/Feature/DashboardTest.php
npm run typecheck && npm run build
```

Expected: PASS (2 testes); typecheck e build verdes.

- [ ] **Step 7: Commit**

```bash
git add tests/Feature/DashboardTest.php resources/js/layouts/app-layout.tsx resources/js/pages/dashboard.tsx routes/web.php
git commit -m "feat(frontend): dashboard e AppLayout em React/Inertia"
```

---

### Task 6: Limpeza — Blade morto e rota morta

**Files:**

- Delete: `resources/views/welcome.blade.php`, `resources/views/dashboard.blade.php`, `resources/views/auth/login.blade.php`, `resources/views/layouts/app.blade.php`
- Modify: `routes/web.php`

**Interfaces:**

- Consumes: páginas React das Tasks 4/5 (as views Blade não são mais referenciadas).
- Produces: nada novo — remove código morto.

- [ ] **Step 1: Confirmar que nada referencia as views**

```bash
grep -rn "welcome\|auth.login\|layouts.app\|'dashboard'" app/ routes/ resources/views/ --include="*.php" | grep -v "resources/views/app.blade.php" | grep -iv "inertia"
```

Expected: nenhuma linha apontando para `view('welcome')`, `view('auth.login')`, `view('dashboard')` ou `@extends('layouts.app')` (as rotas já usam `Inertia::render`). Se aparecer referência, investigar antes de deletar.

- [ ] **Step 2: Deletar views mortas**

```bash
git rm resources/views/welcome.blade.php resources/views/dashboard.blade.php resources/views/auth/login.blade.php resources/views/layouts/app.blade.php
```

**NÃO tocar**: `resources/views/app.blade.php` (root do Inertia), `resources/views/mail/**`, `resources/views/vendor/**`, `resources/views/layouts/relatorio/**`, `resources/views/processo/download.blade.php` (template de PDF usado por `ExportacaoProcessoService` via `PDF::loadView`).

- [ ] **Step 3: Reescrever `routes/web.php`** (remove rota morta `GET /processo/download` — aponta para `DownloadProcessoController::index`, método inexistente — e os imports/comentários mortos)

```php
<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Rotas públicas
Route::get('/', function () {
    return redirect()->route('login');
});

// Rotas de autenticação
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:web')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});
```

- [ ] **Step 4: Suíte completa + build**

```bash
docker compose exec php php vendor/bin/pest
npm run typecheck && npm run build
```

Expected: Pest **8 failed, 54 passed** (só as falhas pré-existentes de exportação); typecheck/build verdes.

- [ ] **Step 5: Commit**

```bash
git add -A resources/views routes/web.php
git commit -m "refactor: remove views Blade mortas e rota /processo/download sem handler"
```

---

### Task 7: Verificação manual end-to-end

**Files:** nenhum (verificação; correções pontuais se algo falhar).

**Interfaces:**

- Consumes: tudo das Tasks 1–6.
- Produces: confirmação de funcionamento real (spec: "Verificação manual: login com credencial inválida → login válido → dashboard → dropdown Monitoramento → logout").

- [ ] **Step 1: Build fresco + app de pé**

```bash
npm run build
docker compose up -d --no-deps php
docker compose exec php php artisan optimize:clear
```

Expected: app respondendo em `http://localhost:8001` (`curl -sI http://localhost:8001/login` → HTTP 200).

- [ ] **Step 2: Fluxo no browser** (usar MCP Playwright se disponível; senão, pedir ao usuário)

1. Abrir `http://localhost:8001/` → redireciona para `/login`, card "Acesse sua conta" renderizado (React, sem tela branca).
2. Submeter credencial inválida (`x@x.com` / `errada`) → bloco vermelho "Erro na autenticação" aparece sem full page reload.
3. Login com usuário válido do banco local → vai para `/dashboard`, 3 cards coloridos visíveis, nome do usuário no topo.
4. Dropdown "Monitoramento" abre com Pulse/Horizon/Log Viewer.
5. Viewport mobile (375px) → menu hambúrguer abre com todos os itens.
6. "Sair" → volta para `/login`; acessar `/dashboard` deslogado → redireciona para `/login`.

Expected: todos os passos ok. Console do browser sem erros de JS/asset 404.

- [ ] **Step 3: Se algo falhar** — corrigir, re-rodar `docker compose exec php php vendor/bin/pest` + `npm run build`, e commitar o fix com mensagem `fix(frontend): <o que era>`.

---

## Critério de aceite global

1. `docker compose exec php php vendor/bin/pest` → **8 failed, 54 passed** (falhas = só as 8 pré-existentes do domínio exportação; 4 testes novos + ExampleTest corrigido verdes).
2. `npm run typecheck && npm run build` → verdes.
3. Fluxo manual da Task 7 completo.
4. Nenhum arquivo de PDF/mail/vendor tocado (`git diff --stat main -- resources/views/mail resources/views/vendor resources/views/layouts/relatorio resources/views/processo` vazio).
