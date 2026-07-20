# Visualizar Documento Baixado — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Botão "Visualizar" na aba Documentos do processo abre modal com o documento baixado (PDF via URL temporária do S3/R2, HTML renderizado inline).

**Architecture:** Nova rota web autenticada `GET /processos/{processo}/documentos/{documento}` com scoped binding. Para `text/html`, devolve o conteúdo via `SalvarDocumentoProcessoService::obterConteudoHtml()`; para os demais mimetypes, redireciona para `temporaryUrl` do disco `s3`. O frontend abre um `Dialog` (shadcn) com `<iframe>` apontando para a rota.

**Tech Stack:** Laravel 11 + Pest (feature tests com `DatabaseTransactions`), Inertia + React 19, shadcn/ui estilo new-york com pacote monolítico `radix-ui`, Tailwind v4.

**Spec:** `docs/superpowers/specs/2026-07-16-visualizar-documento-design.md`

## Global Constraints

- Testes rodam com `php artisan test` (NÃO usar o wrapper `./php` do repo — está quebrado). Suite tem 8 falhas pré-existentes não relacionadas; validar apenas os testes novos/tocados.
- shadcn CLI está quebrado neste ambiente (diretório root-owned) — componentes ui novos são criados manualmente.
- Componentes ui importam de `"radix-ui"` (pacote monolítico), padrão `import { Dialog as DialogPrimitive } from "radix-ui"` — ver `resources/js/components/ui/sheet.tsx`.
- Typecheck frontend: `npm run typecheck`.
- Textos de UI em português (padrão do app).

---

### Task 1: Rota web + controller para servir o documento

**Files:**
- Modify: `routes/web.php` (dentro do grupo `auth:web`, junto das rotas de processos)
- Modify: `app/Http/Controllers/ProcessoController.php`
- Test: `tests/Feature/ProcessoDocumentoVisualizarTest.php` (novo)

**Interfaces:**
- Consumes: `SalvarDocumentoProcessoService::obterConteudoHtml(ProcessoDocumento): ?string`; `ProcessoDocumento::STATUS_BAIXADO`; disco `s3`.
- Produces: rota nomeada `processos.documento` → `GET /processos/{processo}/documentos/{documento}` usada pelo iframe da Task 3. HTML → 200 `text/html`; PDF/outros → 302 para URL temporária; casos inválidos → 404.

- [ ] **Step 1: Escrever os testes que falham**

Criar `tests/Feature/ProcessoDocumentoVisualizarTest.php`:

```php
<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

function loginVisualizar(): User
{
    return User::factory()->make(['id' => 1]);
}

function documentoBaixado(Processo $processo, array $overrides = []): ProcessoDocumento
{
    return ProcessoDocumento::create(array_merge([
        'processo_id' => $processo->id,
        'id_documento' => 'DOC-VIS-' . uniqid(),
        'tipo_documento' => 57,
        'descricao' => 'Documento de teste',
        'mimetype' => 'application/pdf',
        'data_hora' => '2026-01-05 10:00:00',
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'file_size' => 2048,
        'path' => 'documentos/teste.pdf',
    ], $overrides));
}

it('redireciona visitante para o login', function () {
    $processo = Processo::factory()->create();
    $documento = documentoBaixado($processo);

    $this->get("/processos/{$processo->id}/documentos/{$documento->id}")
        ->assertRedirect('/login');
});

it('serve conteudo html para documento text/html baixado', function () {
    Storage::fake('s3');
    $processo = Processo::factory()->create();
    $documento = documentoBaixado($processo, [
        'mimetype' => 'text/html',
        'path' => null,
        'path_html' => 'documentos/teste.html',
    ]);
    Storage::disk('s3')->put('documentos/teste.html', '<p>Sentença de teste</p>');

    $this->actingAs(loginVisualizar())
        ->get("/processos/{$processo->id}/documentos/{$documento->id}")
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertSee('Sentença de teste', false);
});

it('redireciona para url temporaria do s3 para documento pdf baixado', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('documentos/teste.pdf', '%PDF-fake');
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn ($path) => 'https://s3.fake/' . $path . '?assinada=1'
    );

    $processo = Processo::factory()->create();
    $documento = documentoBaixado($processo);

    $this->actingAs(loginVisualizar())
        ->get("/processos/{$processo->id}/documentos/{$documento->id}")
        ->assertRedirect('https://s3.fake/documentos/teste.pdf?assinada=1');
});

it('retorna 404 para documento nao baixado', function () {
    $processo = Processo::factory()->create();
    $documento = documentoBaixado($processo, ['status' => ProcessoDocumento::STATUS_PENDENTE]);

    $this->actingAs(loginVisualizar())
        ->get("/processos/{$processo->id}/documentos/{$documento->id}")
        ->assertNotFound();
});

it('retorna 404 para documento de outro processo (binding escopado)', function () {
    $processoA = Processo::factory()->create();
    $processoB = Processo::factory()->create();
    $documentoDeB = documentoBaixado($processoB);

    $this->actingAs(loginVisualizar())
        ->get("/processos/{$processoA->id}/documentos/{$documentoDeB->id}")
        ->assertNotFound();
});

it('retorna 404 para documento html sem conteudo recuperavel', function () {
    Storage::fake('s3');
    $processo = Processo::factory()->create();
    $documento = documentoBaixado($processo, [
        'mimetype' => 'text/html',
        'path' => null,
        'path_html' => null,
    ]);

    $this->actingAs(loginVisualizar())
        ->get("/processos/{$processo->id}/documentos/{$documento->id}")
        ->assertNotFound();
});

it('retorna 404 para pdf cujo arquivo nao existe no s3', function () {
    Storage::fake('s3');
    $processo = Processo::factory()->create();
    $documento = documentoBaixado($processo, ['path' => 'documentos/sumiu.pdf']);

    $this->actingAs(loginVisualizar())
        ->get("/processos/{$processo->id}/documentos/{$documento->id}")
        ->assertNotFound();
});
```

Nota: `conteudo_html` está em `$hidden` no model mas isso não afeta a rota (não é serialização JSON). `obterConteudoHtml()` cai para `path_html` no S3 fake.

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `php artisan test tests/Feature/ProcessoDocumentoVisualizarTest.php`
Expected: FAIL — 404 nas rotas (rota não existe ainda). O teste de visitante pode falhar com 404 em vez de redirect; esperado nesta fase.

- [ ] **Step 3: Implementar rota e controller**

Em `routes/web.php`, logo após a rota `processos.show`:

```php
    Route::get('/processos/{processo}/documentos/{documento}', [ProcessoController::class, 'documento'])
        ->name('processos.documento')
        ->scopeBindings();
```

O scoped binding resolve `{documento}` pela relação `documentos()` de `Processo` — documento de outro processo vira 404 automaticamente.

Em `app/Http/Controllers/ProcessoController.php`, adicionar imports (se ausentes) e o método:

```php
use App\Models\ProcessoDocumento;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
```

```php
    public function documento(Processo $processo, ProcessoDocumento $documento, SalvarDocumentoProcessoService $service)
    {
        abort_if($documento->status !== ProcessoDocumento::STATUS_BAIXADO, 404);

        if ($documento->mimetype === 'text/html') {
            $html = $service->obterConteudoHtml($documento);
            abort_if($html === null, 404);

            return response($html)->header('Content-Type', 'text/html; charset=utf-8');
        }

        $path = $documento->getRawOriginal('path');
        abort_if(empty($path) || !Storage::disk('s3')->exists($path), 404);

        try {
            return redirect()->away(
                Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(60))
            );
        } catch (\Exception $e) {
            Log::error('Erro ao gerar URL temporária para visualizar documento', [
                'documento_id' => $documento->id,
                'error' => $e->getMessage(),
            ]);
            abort(404);
        }
    }
```

Atenção: usar `getRawOriginal('path')` — o model tem accessor `getUrlAttribute` que lê `$this->path`, mas `path` em si não tem accessor; ainda assim, `getRawOriginal` garante o valor cru da coluna.

- [ ] **Step 4: Rodar os testes e confirmar que passam**

Run: `php artisan test tests/Feature/ProcessoDocumentoVisualizarTest.php`
Expected: PASS (7 testes).

- [ ] **Step 5: Commit**

```bash
git add routes/web.php app/Http/Controllers/ProcessoController.php tests/Feature/ProcessoDocumentoVisualizarTest.php
git commit -m "feat(processos): rota web para visualizar documento baixado"
```

---

### Task 2: Componente Dialog (shadcn/ui)

**Files:**
- Create: `resources/js/components/ui/dialog.tsx`

**Interfaces:**
- Consumes: pacote `radix-ui` (`Dialog` primitive), `cn` de `@/lib/utils`, `XIcon` de `lucide-react`.
- Produces: exports `Dialog`, `DialogContent`, `DialogHeader`, `DialogTitle` (e demais subcomponentes padrão) usados na Task 3. `Dialog` aceita `open: boolean` e `onOpenChange: (open: boolean) => void`.

O shadcn CLI está quebrado neste ambiente — criar o arquivo manualmente, seguindo o estilo de `resources/js/components/ui/sheet.tsx` (import monolítico de `radix-ui`, funções com `data-slot`).

- [ ] **Step 1: Criar `resources/js/components/ui/dialog.tsx`**

```tsx
import { Dialog as DialogPrimitive } from "radix-ui"
import { XIcon } from "lucide-react"
import * as React from "react"

import { cn } from "@/lib/utils"

function Dialog({ ...props }: React.ComponentProps<typeof DialogPrimitive.Root>) {
  return <DialogPrimitive.Root data-slot="dialog" {...props} />
}

function DialogTrigger({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Trigger>) {
  return <DialogPrimitive.Trigger data-slot="dialog-trigger" {...props} />
}

function DialogPortal({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Portal>) {
  return <DialogPrimitive.Portal data-slot="dialog-portal" {...props} />
}

function DialogClose({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Close>) {
  return <DialogPrimitive.Close data-slot="dialog-close" {...props} />
}

function DialogOverlay({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Overlay>) {
  return (
    <DialogPrimitive.Overlay
      data-slot="dialog-overlay"
      className={cn(
        "data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed inset-0 z-50 bg-black/50",
        className
      )}
      {...props}
    />
  )
}

function DialogContent({
  className,
  children,
  showCloseButton = true,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Content> & {
  showCloseButton?: boolean
}) {
  return (
    <DialogPortal data-slot="dialog-portal">
      <DialogOverlay />
      <DialogPrimitive.Content
        data-slot="dialog-content"
        className={cn(
          "bg-background data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed top-[50%] left-[50%] z-50 grid w-full max-w-[calc(100%-2rem)] translate-x-[-50%] translate-y-[-50%] gap-4 rounded-lg border p-6 shadow-lg duration-200 sm:max-w-lg",
          className
        )}
        {...props}
      >
        {children}
        {showCloseButton && (
          <DialogPrimitive.Close
            data-slot="dialog-close"
            className="ring-offset-background focus:ring-ring data-[state=open]:bg-accent data-[state=open]:text-muted-foreground absolute top-4 right-4 rounded-xs opacity-70 transition-opacity hover:opacity-100 focus:ring-2 focus:ring-offset-2 focus:outline-hidden disabled:pointer-events-none [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4"
          >
            <XIcon />
            <span className="sr-only">Fechar</span>
          </DialogPrimitive.Close>
        )}
      </DialogPrimitive.Content>
    </DialogPortal>
  )
}

function DialogHeader({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="dialog-header"
      className={cn("flex flex-col gap-2 text-center sm:text-left", className)}
      {...props}
    />
  )
}

function DialogFooter({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="dialog-footer"
      className={cn(
        "flex flex-col-reverse gap-2 sm:flex-row sm:justify-end",
        className
      )}
      {...props}
    />
  )
}

function DialogTitle({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Title>) {
  return (
    <DialogPrimitive.Title
      data-slot="dialog-title"
      className={cn("text-lg leading-none font-semibold", className)}
      {...props}
    />
  )
}

function DialogDescription({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Description>) {
  return (
    <DialogPrimitive.Description
      data-slot="dialog-description"
      className={cn("text-muted-foreground text-sm", className)}
      {...props}
    />
  )
}

export {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogOverlay,
  DialogPortal,
  DialogTitle,
  DialogTrigger,
}
```

- [ ] **Step 2: Typecheck**

Run: `npm run typecheck`
Expected: sem erros novos (comparar com estado anterior se houver erros pré-existentes).

- [ ] **Step 3: Commit**

```bash
git add resources/js/components/ui/dialog.tsx
git commit -m "feat(ui): adiciona componente dialog (shadcn)"
```

---

### Task 3: Botão Visualizar + modal na aba Documentos

**Files:**
- Modify: `resources/js/pages/processos/show.tsx`

**Interfaces:**
- Consumes: rota `processos.documento` da Task 1 (`/processos/{processo.id}/documentos/{doc.id}`); `Dialog`, `DialogContent`, `DialogHeader`, `DialogTitle` da Task 2; tipo `DocumentoItem` (já tem `id`, `descricao`, `mimetype`, `status`).
- Produces: coluna de ações na tabela de documentos com botão de visualizar (apenas `status === 'baixado'`) e modal com iframe.

- [ ] **Step 1: Editar `resources/js/pages/processos/show.tsx`**

1. Ajustar imports:

```tsx
import { useState } from 'react';
import { Copy, Eye, FileText } from 'lucide-react';

import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
```

(`Eye` entra no import existente de `lucide-react`; `Dialog...` é linha nova junto dos outros imports de ui.)

2. No início do componente `ProcessoShow`, adicionar o estado:

```tsx
    const [docAberto, setDocAberto] = useState<DocumentoItem | null>(null);
```

3. Na tabela de documentos, adicionar coluna de ações. No `TableHeader`:

```tsx
                                                <TableHead>Status</TableHead>
                                                <TableHead className="w-10" />
```

No `TableBody`, após a célula de status:

```tsx
                                                    <TableCell className="text-muted-foreground">{doc.status ?? '—'}</TableCell>
                                                    <TableCell>
                                                        {doc.status === 'baixado' && (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                aria-label="Visualizar documento"
                                                                onClick={() => setDocAberto(doc)}
                                                            >
                                                                <Eye className="size-4" />
                                                            </Button>
                                                        )}
                                                    </TableCell>
```

4. Antes do fechamento de `</AppLayout>` (após `</Tabs>` e antes de `</div>` final), adicionar o modal:

```tsx
                <Dialog open={docAberto !== null} onOpenChange={(open) => !open && setDocAberto(null)}>
                    <DialogContent className="flex h-[85vh] flex-col gap-3 sm:max-w-4xl">
                        <DialogHeader>
                            <DialogTitle className="pr-8">
                                {docAberto?.descricao ?? 'Documento'}
                            </DialogTitle>
                        </DialogHeader>
                        {docAberto && (
                            <iframe
                                src={`/processos/${processo.id}/documentos/${docAberto.id}`}
                                title={docAberto.descricao ?? 'Documento'}
                                className="w-full flex-1 rounded-md border bg-white"
                                {...(docAberto.mimetype === 'text/html' ? { sandbox: '' } : {})}
                            />
                        )}
                    </DialogContent>
                </Dialog>
```

Nota: `sandbox` só para HTML (isola scripts do conteúdo do tribunal); iframe sandboxed bloqueia o viewer nativo de PDF no Chrome, então PDFs ficam sem sandbox. `bg-white` evita fundo escuro atrás de HTML sem estilo no dark mode.

- [ ] **Step 2: Typecheck e build**

Run: `npm run typecheck && npm run build`
Expected: sem erros.

- [ ] **Step 3: Verificação manual end-to-end**

Com o app rodando em `localhost:8006` (ambiente dev já ativo):

1. Abrir `http://localhost:8006/processos/329`, aba Documentos.
2. Clicar no ícone de olho de um documento `text/html` (ex.: "Sentença") → modal abre com conteúdo renderizado.
3. Clicar em um `application/pdf` → modal abre com PDF no viewer do browser (se o objeto existir no S3/R2 do dev; caso contrário, 404 no iframe é o comportamento esperado).
4. Fechar com X e com ESC → estado limpa, reabrir funciona.

Preferir MCP playwright (`browser_navigate`, `browser_click`, `browser_take_screenshot`) para evidência.

- [ ] **Step 4: Rodar testes de feature relacionados**

Run: `php artisan test tests/Feature/ProcessoDocumentoVisualizarTest.php tests/Feature/ProcessoConsultaTest.php`
Expected: PASS (sem regressão na página do processo).

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/processos/show.tsx
git commit -m "feat(processos): botao visualizar documento baixado em modal"
```
