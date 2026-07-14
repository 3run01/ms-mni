# Conteúdo HTML de documentos no S3 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Documentos HTML novos passam a ter o conteúdo bruto salvo no S3 (coluna nova `path_html`) em vez da coluna `conteudo_html`, mantendo o response de `/api/documento/visualizar` idêntico para consumidores e deixando a listagem leve.

**Architecture:** Coluna `path_html` em `processo_documentos` no padrão da `path` existente; `SalvarDocumentoProcessoService::downloadHTML` grava o `.html` no S3 ao lado do PDF e para de escrever na coluna; `obterConteudoHtml` hidrata o conteúdo (coluna para legado, S3 para novos) apenas no endpoint `visualizar`; `conteudo_html` entra no `$hidden` do model. Spec aprovado: `docs/superpowers/specs/2026-07-14-conteudo-html-s3-design.md`.

**Tech Stack:** Laravel 11 (PHP via docker: wrappers `./php` e `./composer` na raiz), Postgres, S3 (`Storage::disk('s3')`), dompdf (`Pdf::loadHTML`), Pest + Mockery.

## Global Constraints

- Rodar comandos PHP SEMPRE pelos wrappers da raiz do repo: `./php artisan ...` e `./composer ...` (executam dentro do docker compose). Nunca `php artisan` direto.
- Testes usam o Postgres real com `DatabaseTransactions` (rollback automático). Rode `./php artisan migrate` antes da suíte quando criar migration nova.
- Números de processo/id de documento em testes devem ser únicos por execução: use prefixo + `getmypid()` (padrão da suíte). `id_documento` tem cast `integer` — use valores numéricos.
- O response de `/api/documento/visualizar` mantém a chave `conteudo_html` SEMPRE presente (nula para documentos não-HTML). Nada é persistido de volta na coluna `conteudo_html` durante a hidratação.
- Path do HTML no S3: `documentos-processos/{numero_processo}/{id_documento}.html` (mesma pasta do PDF).
- Registros legados (coluna `conteudo_html` preenchida) continuam sendo servidos da coluna; sem backfill neste escopo.
- Commits em conventional commits, mensagem sem acentos (padrão do repo: `feat(processos): ...`), terminando com `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- Testes de API usam header `X-API-Token: tk-test` com o helper global `criarTokenApi()` (definido em `tests/Pest.php`).

---

### Task 1: Migration `path_html` + model `ProcessoDocumento` (flag, hidden, listagem leve)

**Files:**
- Create: `database/migrations/2026_07_14_000001_add_path_html_to_processo_documentos_table.php`
- Modify: `app/Models/ProcessoDocumento.php`
- Test: `tests/Unit/Models/ProcessoDocumentoTest.php` (novo)
- Test: `tests/Feature/Api/DocumentoControllerTest.php` (adicionar teste de listagem leve)

**Interfaces:**
- Consumes: nada (primeira task).
- Produces: coluna `processo_documentos.path_html` (string 255 nullable); `ProcessoDocumento::temConteudoHtml(): bool` (true se `conteudo_html` OU `path_html` preenchido); `path_html` no `$fillable`; `path_html` e `conteudo_html` no `$hidden`. Tasks 2–5 dependem disso.

- [ ] **Step 1: Escrever os testes de unidade do model (falhando)**

Criar `tests/Unit/Models/ProcessoDocumentoTest.php`:

```php
<?php

use App\Models\ProcessoDocumento;

it('temConteudoHtml retorna false sem coluna e sem path', function () {
    $documento = new ProcessoDocumento();

    expect($documento->temConteudoHtml())->toBeFalse();
});

it('temConteudoHtml retorna true com conteudo_html preenchido (legado)', function () {
    $documento = new ProcessoDocumento();
    $documento->conteudo_html = '<html><body>Ola</body></html>';

    expect($documento->temConteudoHtml())->toBeTrue();
});

it('temConteudoHtml retorna true com path_html preenchido', function () {
    $documento = new ProcessoDocumento(['path_html' => 'documentos-processos/123/456.html']);

    expect($documento->temConteudoHtml())->toBeTrue();
});

it('temConteudoHtml retorna true com ambos preenchidos', function () {
    $documento = new ProcessoDocumento(['path_html' => 'documentos-processos/123/456.html']);
    $documento->conteudo_html = '<html></html>';

    expect($documento->temConteudoHtml())->toBeTrue();
});

it('nao serializa conteudo_html nem path_html', function () {
    $documento = new ProcessoDocumento([
        'descricao' => 'Peticao',
        'path_html' => 'documentos-processos/123/456.html',
    ]);
    $documento->conteudo_html = '<html><body>Pesado</body></html>';

    $array = $documento->toArray();

    expect($array)->not->toHaveKey('conteudo_html')
        ->and($array)->not->toHaveKey('path_html')
        ->and($array)->toHaveKey('descricao');
});
```

Nota: `conteudo_html` não está no `$fillable` (nunca esteve) — por isso os testes setam via atribuição direta de atributo, não pelo construtor.

- [ ] **Step 2: Rodar e ver falhar**

Run: `./php artisan test tests/Unit/Models/ProcessoDocumentoTest.php`
Expected: FAIL — `Call to undefined method App\Models\ProcessoDocumento::temConteudoHtml()` (e o teste de serialização falha porque `conteudo_html` aparece no array).

- [ ] **Step 3: Escrever o teste de listagem leve (falhando)**

Adicionar ao FINAL de `tests/Feature/Api/DocumentoControllerTest.php` (o arquivo já tem `uses(DatabaseTransactions::class)` e `beforeEach(fn () => criarTokenApi())`):

```php
it('listar documentos nao expoe conteudo_html nem path_html', function () {
    $numero = 'LISTALEVE' . getmypid();
    $processo = Processo::create([
        'numero_processo' => $numero,
        'tribunal_id' => 999999,
        'valor_causa' => '0.00',
    ]);
    \App\Models\ProcessoDocumento::create([
        'processo_id' => $processo->id,
        'id_documento' => 910001,
        'descricao' => 'Sentenca Legada',
        'mimetype' => 'text/html',
        'status' => 'baixado',
    ]);
    // legado: coluna preenchida direto no banco (fora do fillable)
    \App\Models\ProcessoDocumento::where('id_documento', 910001)
        ->update(['conteudo_html' => '<html><body>Conteudo legado pesado</body></html>']);

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/processo/documentos/listar?tribunal_id=999999&numero_processo={$numero}&login_pje=u&senha_pje=s")
        ->assertOk()
        ->assertJsonPath('0.descricao', 'Sentenca Legada')
        ->assertJsonMissingPath('0.conteudo_html')
        ->assertJsonMissingPath('0.path_html');
});
```

- [ ] **Step 4: Rodar e ver falhar**

Run: `./php artisan test tests/Feature/Api/DocumentoControllerTest.php`
Expected: FAIL no teste novo — `Found unexpected JSON at [0.conteudo_html]` (a coluna hoje é serializada). Os 4 testes antigos do arquivo continuam passando.

- [ ] **Step 5: Criar a migration**

Criar `database/migrations/2026_07_14_000001_add_path_html_to_processo_documentos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->string('path_html')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->dropColumn('path_html');
        });
    }
};
```

- [ ] **Step 6: Rodar a migration**

Run: `./php artisan migrate`
Expected: `2026_07_14_000001_add_path_html_to_processo_documentos_table ......... DONE`

- [ ] **Step 7: Atualizar o model**

Em `app/Models/ProcessoDocumento.php`, o `$fillable` ganha `path_html` (depois de `path`), o `$hidden` ganha `path_html` e `conteudo_html` (e perde o `processo_id` duplicado — está 2x hoje), e nasce o helper `temConteudoHtml()`:

```php
    protected $fillable = [
        'processo_id',
        'id_documento',
        'id_documento_vinculado',
        'tipo_documento',
        'data_hora',
        'mimetype',
        'movimento',
        'hash',
        'descricao',
        'usuario_juntada_arquivo',
        'data_juntada',
        'status',
        'url',
        'path',
        'path_html',
        'file_size',
        'tentativas_download',
        'erro_mni',
    ];

    protected $casts = [
        'id_documento' => 'integer',
    ];

    protected $hidden = [
        'id',
        'processo_id',
        'created_at',
        'updated_at',
        'deleted_at',
        'baixado',
        'url',
        'path',
        'path_html',
        'conteudo_html',
    ];

    public function temConteudoHtml(): bool
    {
        return !empty($this->conteudo_html) || !empty($this->path_html);
    }
```

(O restante do model — constantes, `$table`, relações, `getUrlAttribute` — permanece intacto.)

- [ ] **Step 8: Rodar os testes e ver passar**

Run: `./php artisan test tests/Unit/Models/ProcessoDocumentoTest.php tests/Feature/Api/DocumentoControllerTest.php`
Expected: PASS (5 unit + 5 feature).

Rodar também o teste existente que garante a página web de detalhe sem conteúdo pesado:

Run: `./php artisan test tests/Feature/ProcessoConsultaTest.php`
Expected: PASS (o `assertJsonMissingPath('props.documentos.0.conteudo_html')` continua verde — agora também por causa do `$hidden`).

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_07_14_000001_add_path_html_to_processo_documentos_table.php app/Models/ProcessoDocumento.php tests/Unit/Models/ProcessoDocumentoTest.php tests/Feature/Api/DocumentoControllerTest.php
git commit -m "feat(processos): adiciona path_html e oculta conteudo_html da serializacao

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: `downloadHTML` salva o `.html` no S3 e para de escrever na coluna

**Files:**
- Modify: `app/Services/Processo/SalvarDocumentoProcessoService.php:142-194` (método `downloadHTML`; extrair `putComRetry`)
- Test: `tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php` (novo)

**Interfaces:**
- Consumes: `path_html` no `$fillable`/model (Task 1).
- Produces: `downloadHTML($documento, $login_pje = null, $senha_pje = null): string` (retorna o path do PDF, como hoje) agora grava também `documentos-processos/{numero}/{id_documento}.html` no disk `s3`, seta `$documento->path_html` e salva; NÃO escreve mais em `conteudo_html`. Método privado `putComRetry(string $path, string $conteudo, string $rotulo): void` (3 tentativas, sleep 1s, lança exceção ao esgotar). Task 5 depende do comportamento de `downloadHTML`.

- [ ] **Step 1: Escrever o teste de unidade (falhando)**

Criar `tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php`:

```php
<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Services\Processo\SalvarDocumentoProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

function criarDocumentoHtml(array $overrides = []): ProcessoDocumento
{
    $processo = Processo::factory()->create([
        'numero_processo' => 'SVCS3' . getmypid(),
    ]);

    return ProcessoDocumento::create(array_merge([
        'processo_id' => $processo->id,
        'id_documento' => 920001,
        'descricao' => 'Documento HTML',
        'mimetype' => 'text/html',
        'status' => ProcessoDocumento::STATUS_PENDENTE,
    ], $overrides));
}

it('downloadHTML salva html e pdf no S3, seta path_html e nao escreve conteudo_html', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml();
    $numero = $documento->processo->numero_processo;
    $html = '<html><body><h1>Sentenca</h1></body></html>';

    $service = Mockery::mock(SalvarDocumentoProcessoService::class)->makePartial();
    $service->shouldReceive('consultarDocumento')
        ->once()
        ->andReturn((object) ['conteudo' => base64_encode($html)]);

    $pathPdf = $service->downloadHTML($documento);

    expect($pathPdf)->toBe("documentos-processos/{$numero}/920001.pdf");
    Storage::disk('s3')->assertExists("documentos-processos/{$numero}/920001.pdf");
    Storage::disk('s3')->assertExists("documentos-processos/{$numero}/920001.html");
    expect(Storage::disk('s3')->get("documentos-processos/{$numero}/920001.html"))->toBe($html);

    $documento->refresh();
    expect($documento->path_html)->toBe("documentos-processos/{$numero}/920001.html")
        ->and($documento->conteudo_html)->toBeNull();
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./php artisan test tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php`
Expected: FAIL — `Storage::disk('s3')->assertExists(".../920001.html")` não encontra o arquivo (o código atual só sobe o PDF), e `conteudo_html` vem preenchido em vez de null.

- [ ] **Step 3: Implementar**

Em `app/Services/Processo/SalvarDocumentoProcessoService.php`, substituir o método `downloadHTML` inteiro (linhas 142–194) por:

```php
    public function downloadHTML($documento, $login_pje = null, $senha_pje = null)
    {
        try {
            $documentoMNI = $this->consultarDocumento($documento, $login_pje, $senha_pje);

            if (empty($documentoMNI->conteudo)) {
                throw new \Exception('Conteúdo do documento HTML está vazio');
            }

            $conteudoDecodificado = base64_decode($documentoMNI->conteudo);
            if ($conteudoDecodificado === false) {
                throw new \Exception('Falha ao decodificar o conteúdo base64 do HTML');
            }

            $pdf = Pdf::loadHTML($conteudoDecodificado);
            $pasta = "documentos-processos";
            $filename = $pasta . "/" . $documento->processo->numero_processo . "/" . $documento->id_documento . ".pdf";
            $filenameHtml = $pasta . "/" . $documento->processo->numero_processo . "/" . $documento->id_documento . ".html";

            $this->putComRetry($filename, $pdf->output(), 'PDF');
            $this->putComRetry($filenameHtml, $conteudoDecodificado, 'HTML');

            $documento->path_html = $filenameHtml;
            $documento->save();

            return $filename;
        } catch (\Exception $e) {
            throw new \Exception('Erro ao processar documento HTML: ' . $e->getMessage());
        }
    }

    private function putComRetry(string $path, string $conteudo, string $rotulo): void
    {
        $maxTentativas = 3;
        $tentativa = 0;
        $sucesso = false;

        while (!$sucesso && $tentativa < $maxTentativas) {
            try {
                $sucesso = Storage::disk('s3')->put($path, $conteudo);
                if (!$sucesso) {
                    $tentativa++;
                    if ($tentativa < $maxTentativas) {
                        sleep(1);
                    }
                }
            } catch (\Exception $e) {
                $tentativa++;
                if ($tentativa >= $maxTentativas) {
                    throw $e;
                }
                sleep(1);
            }
        }

        if (!$sucesso) {
            throw new \Exception('Erro ao salvar o ' . $rotulo . ' no S3 após ' . $maxTentativas . ' tentativas');
        }
    }
```

O que mudou em relação ao original: o loop de retry inline do PDF virou `putComRetry` (mesma semântica: 3 tentativas, `sleep(1)` entre elas, exceção ao esgotar); o upload do `.html` reusa o mesmo método; `$documento->conteudo_html = $conteudoDecodificado` foi REMOVIDO e no lugar grava-se `path_html`. A assinatura e o retorno (path do PDF) não mudam.

- [ ] **Step 4: Rodar e ver passar**

Run: `./php artisan test tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Processo/SalvarDocumentoProcessoService.php tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php
git commit -m "feat(processos): salva conteudo HTML de documentos no S3 em vez da coluna

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Flag combinada em `baixarDocumento`

**Files:**
- Modify: `app/Services/Processo/SalvarDocumentoProcessoService.php:95` (dentro de `baixarDocumento`)
- Test: `tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php` (adicionar 2 testes)

**Interfaces:**
- Consumes: `temConteudoHtml()` (Task 1); `downloadHTML` (Task 2).
- Produces: `baixarDocumento` só re-baixa o HTML de documento já baixado quando NEM `conteudo_html` NEM `path_html` existem. Comportamento para não-HTML inalterado.

- [ ] **Step 1: Escrever os testes (falhando)**

Adicionar ao final de `tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php`:

```php
it('baixarDocumento re-baixa html quando documento baixado nao tem coluna nem path_html', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml([
        'id_documento' => 920002,
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => 'documentos-processos/x/920002.pdf',
    ]);
    Storage::disk('s3')->put('documentos-processos/x/920002.pdf', 'pdf-fake');

    $service = Mockery::mock(SalvarDocumentoProcessoService::class)->makePartial();
    $service->shouldReceive('downloadHTML')->once()->andReturn('documentos-processos/x/920002.pdf');

    $resultado = $service->baixarDocumento($documento);

    expect($resultado->id)->toBe($documento->id);
});

it('baixarDocumento NAO re-baixa html quando documento baixado ja tem path_html', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml([
        'id_documento' => 920003,
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => 'documentos-processos/x/920003.pdf',
        'path_html' => 'documentos-processos/x/920003.html',
    ]);
    Storage::disk('s3')->put('documentos-processos/x/920003.pdf', 'pdf-fake');

    $service = Mockery::mock(SalvarDocumentoProcessoService::class)->makePartial();
    $service->shouldNotReceive('downloadHTML');

    $resultado = $service->baixarDocumento($documento);

    expect($resultado->id)->toBe($documento->id);
});
```

Atenção: `criarDocumentoHtml` cria processos com o mesmo `numero_processo` por PID — cada teste usa `id_documento` distinto (920002, 920003) para não colidir dentro da transação.

- [ ] **Step 2: Rodar e ver falhar**

Run: `./php artisan test tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php`
Expected: o primeiro teste novo PASSA (o código atual também re-baixa quando a coluna está vazia), o segundo FALHA — `Mockery ... downloadHTML ... should not be called` (o código atual ignora `path_html` e chama `downloadHTML` mesmo assim).

- [ ] **Step 3: Implementar**

Em `app/Services/Processo/SalvarDocumentoProcessoService.php`, dentro de `baixarDocumento`, trocar:

```php
        if ($documento->status == ProcessoDocumento::STATUS_BAIXADO && Storage::disk('s3')->exists($documento->path)) {
            if ($documento->mimetype == 'text/html' && !$documento->conteudo_html) {
                $this->downloadHTML($documento);
            }
```

por:

```php
        if ($documento->status == ProcessoDocumento::STATUS_BAIXADO && Storage::disk('s3')->exists($documento->path)) {
            if ($documento->mimetype == 'text/html' && !$documento->temConteudoHtml()) {
                $this->downloadHTML($documento);
            }
```

- [ ] **Step 4: Rodar e ver passar**

Run: `./php artisan test tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php`
Expected: PASS (3 testes).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Processo/SalvarDocumentoProcessoService.php tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php
git commit -m "feat(processos): usa flag combinada de conteudo HTML no re-download

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: `obterConteudoHtml` no service (hidratação coluna → S3)

**Files:**
- Modify: `app/Services/Processo/SalvarDocumentoProcessoService.php` (novo método público, logo após `downloadHTML`)
- Test: `tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php` (adicionar 4 testes)

**Interfaces:**
- Consumes: `path_html` (Task 1).
- Produces: `obterConteudoHtml(ProcessoDocumento $documento): ?string` — retorna `conteudo_html` da coluna se preenchida (legado); senão lê `path_html` do disk `s3`; retorna `null` se nada existir ou se a leitura falhar (loga o erro). NUNCA persiste nada. Task 5 consome.

- [ ] **Step 1: Escrever os testes (falhando)**

Adicionar ao final de `tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php`:

```php
it('obterConteudoHtml retorna a coluna quando preenchida (legado), sem tocar o S3', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml(['id_documento' => 920004]);
    ProcessoDocumento::where('id', $documento->id)
        ->update(['conteudo_html' => '<html><body>Legado</body></html>']);
    $documento->refresh();

    $service = new SalvarDocumentoProcessoService();

    expect($service->obterConteudoHtml($documento))->toBe('<html><body>Legado</body></html>');
});

it('obterConteudoHtml le do S3 via path_html quando a coluna esta vazia', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml([
        'id_documento' => 920005,
        'path_html' => 'documentos-processos/x/920005.html',
    ]);
    Storage::disk('s3')->put('documentos-processos/x/920005.html', '<html><body>Novo</body></html>');

    $service = new SalvarDocumentoProcessoService();

    expect($service->obterConteudoHtml($documento))->toBe('<html><body>Novo</body></html>');
});

it('obterConteudoHtml retorna null quando path_html aponta para objeto inexistente', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml([
        'id_documento' => 920006,
        'path_html' => 'documentos-processos/x/920006.html',
    ]);

    $service = new SalvarDocumentoProcessoService();

    expect($service->obterConteudoHtml($documento))->toBeNull();
});

it('obterConteudoHtml retorna null sem coluna e sem path_html', function () {
    Storage::fake('s3');
    $documento = criarDocumentoHtml(['id_documento' => 920007]);

    $service = new SalvarDocumentoProcessoService();

    expect($service->obterConteudoHtml($documento))->toBeNull();
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./php artisan test tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php`
Expected: FAIL — `Call to undefined method ... obterConteudoHtml()`.

- [ ] **Step 3: Implementar**

Em `app/Services/Processo/SalvarDocumentoProcessoService.php`, adicionar logo após `putComRetry` (o `use App\Models\ProcessoDocumento;` e o `use Illuminate\Support\Facades\Log;` já existem no arquivo):

```php
    public function obterConteudoHtml(ProcessoDocumento $documento): ?string
    {
        if (!empty($documento->conteudo_html)) {
            return $documento->conteudo_html;
        }

        if (empty($documento->path_html)) {
            return null;
        }

        try {
            $conteudo = Storage::disk('s3')->get($documento->path_html);

            return ($conteudo === null || $conteudo === '') ? null : $conteudo;
        } catch (\Exception $e) {
            Log::error('Erro ao ler conteudo HTML do S3', [
                'documento_id' => $documento->id_documento,
                'path_html' => $documento->path_html,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
```

(O disk `s3` tem `'throw' => false` — objeto ausente vira `get() === null`, coberto pelo ternário; o try/catch cobre indisponibilidade real do S3.)

- [ ] **Step 4: Rodar e ver passar**

Run: `./php artisan test tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php`
Expected: PASS (7 testes).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Processo/SalvarDocumentoProcessoService.php tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php
git commit -m "feat(processos): adiciona leitura do conteudo HTML da coluna ou do S3

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Hidratação no endpoint `/api/documento/visualizar`

**Files:**
- Modify: `app/Http/Controllers/Api/DocumentoController.php:68-130` (método `getDocumento` + helper privado novo)
- Test: `tests/Feature/Api/DocumentoControllerTest.php` (adicionar 4 testes)

**Interfaces:**
- Consumes: `temConteudoHtml()` (Task 1), `downloadHTML` (Task 2), `obterConteudoHtml` (Task 4).
- Produces: response do `visualizar` com a chave `conteudo_html` sempre presente — valor da coluna (legado), do S3 (novo), ou `null` (não-HTML / falha) — sem persistir nada; auto-correção re-executa `downloadHTML` uma vez quando um doc HTML fica sem conteúdo recuperável.

- [ ] **Step 1: Escrever os testes de feature (falhando)**

Adicionar ao final de `tests/Feature/Api/DocumentoControllerTest.php`. O arquivo precisa de imports novos no topo (junto aos existentes): `use App\Models\ProcessoDocumento;`, `use Illuminate\Support\Facades\Storage;` e `use Illuminate\Testing\Fluent\AssertableJson;`.

```php
function criarDocumentoVisualizar(string $numero, int $idDocumento, array $overrides = []): ProcessoDocumento
{
    $processo = Processo::firstOrCreate(
        ['numero_processo' => $numero, 'tribunal_id' => 999999],
        ['valor_causa' => '0.00']
    );

    return ProcessoDocumento::create(array_merge([
        'processo_id' => $processo->id,
        'id_documento' => $idDocumento,
        'descricao' => 'Documento Teste',
        'mimetype' => 'text/html',
        'status' => ProcessoDocumento::STATUS_BAIXADO,
        'path' => "documentos-processos/{$numero}/{$idDocumento}.pdf",
    ], $overrides));
}

function fakeS3ComLinks(): void
{
    Storage::fake('s3');
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn ($path, $expiration, $options = []) => 'https://s3.fake/' . $path
    );
}

it('visualizar hidrata conteudo_html do S3 para documento novo', function () {
    fakeS3ComLinks();
    $numero = 'VIS' . getmypid() . 'A';
    $html = '<html><body>Conteudo novo no S3</body></html>';
    criarDocumentoVisualizar($numero, 930001, [
        'path_html' => "documentos-processos/{$numero}/930001.html",
    ]);
    Storage::disk('s3')->put("documentos-processos/{$numero}/930001.pdf", 'pdf-fake');
    Storage::disk('s3')->put("documentos-processos/{$numero}/930001.html", $html);

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/documento/visualizar?tribunal_id=999999&numero_processo={$numero}&id_documento=930001&login_pje=u&senha_pje=s")
        ->assertOk()
        ->assertJsonPath('documento.conteudo_html', $html)
        ->assertJsonPath('documento.link', "https://s3.fake/documentos-processos/{$numero}/930001.pdf")
        ->assertJsonMissingPath('documento.path_html')
        ->assertJsonMissingPath('documento.path');
});

it('visualizar serve conteudo_html da coluna para documento legado', function () {
    fakeS3ComLinks();
    $numero = 'VIS' . getmypid() . 'B';
    $htmlLegado = '<html><body>Conteudo legado na coluna</body></html>';
    $documento = criarDocumentoVisualizar($numero, 930002);
    ProcessoDocumento::where('id', $documento->id)->update(['conteudo_html' => $htmlLegado]);
    Storage::disk('s3')->put("documentos-processos/{$numero}/930002.pdf", 'pdf-fake');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/documento/visualizar?tribunal_id=999999&numero_processo={$numero}&id_documento=930002&login_pje=u&senha_pje=s")
        ->assertOk()
        ->assertJsonPath('documento.conteudo_html', $htmlLegado);
});

it('visualizar mantem a chave conteudo_html nula para documento nao-HTML', function () {
    fakeS3ComLinks();
    $numero = 'VIS' . getmypid() . 'C';
    criarDocumentoVisualizar($numero, 930003, ['mimetype' => 'application/pdf']);
    Storage::disk('s3')->put("documentos-processos/{$numero}/930003.pdf", 'pdf-fake');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/documento/visualizar?tribunal_id=999999&numero_processo={$numero}&id_documento=930003&login_pje=u&senha_pje=s")
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('documento.conteudo_html', null)
            ->etc());
});

it('visualizar responde conteudo_html nulo quando objeto sumiu do S3 e a auto-correcao falha', function () {
    fakeS3ComLinks();
    $numero = 'VIS' . getmypid() . 'D';
    // path_html aponta para objeto que nao existe; tribunal_id 999999 nao existe
    // na conexao sim, entao o re-download via MNI falha sem tocar a rede.
    criarDocumentoVisualizar($numero, 930004, [
        'path_html' => "documentos-processos/{$numero}/930004.html",
    ]);
    Storage::disk('s3')->put("documentos-processos/{$numero}/930004.pdf", 'pdf-fake');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/documento/visualizar?tribunal_id=999999&numero_processo={$numero}&id_documento=930004&login_pje=u&senha_pje=s")
        ->assertOk()
        ->assertJsonPath('documento.link', "https://s3.fake/documentos-processos/{$numero}/930004.pdf")
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('documento.conteudo_html', null)
            ->etc());
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./php artisan test tests/Feature/Api/DocumentoControllerTest.php`
Expected: os 4 testes novos FALHAM — `conteudo_html` está no `$hidden` (Task 1) e o controller ainda não hidrata, então a chave não aparece no JSON (`Unable to find JSON at path [documento.conteudo_html]` / `where` falha por chave ausente).

- [ ] **Step 3: Implementar**

Em `app/Http/Controllers/Api/DocumentoController.php`, substituir o método `getDocumento` inteiro (linhas 68–130) por:

```php
    public function getDocumento($id_documento, $numero_processo, $tribunal, $login_pje = null, $senha_pje = null)
    {
        try {
            $service = new SalvarDocumentoProcessoService();
            $documento = $this->vericarExistenciaDocumento($id_documento, $numero_processo, $tribunal, $login_pje, $senha_pje);

            // baixa o documento caso ainda nao tenha conteudo html (coluna legado ou path_html)
            if (!$documento->temConteudoHtml()) {
                $documento = $service->baixarDocumento($documento, $login_pje, $senha_pje);
            }

            // Se o documento já estiver baixado e existir no S3, apenas gera o link
            if ($documento->status == ProcessoDocumento::STATUS_BAIXADO && Storage::disk('s3')->exists($documento->path)) {
                try {
                    $documento->link = Storage::disk('s3')->temporaryUrl(
                        $documento->path,
                        now()->addMinutes(60)
                    );

                    return $this->anexarConteudoHtml($service, $documento, $login_pje, $senha_pje);
                } catch (\Exception $e) {
                    Log::error('Erro ao gerar link temporário para documento: ' . $e->getMessage(), [
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            $documento = $service->baixarDocumento(
                $documento,
                $login_pje ?? null,
                $senha_pje ?? null
            );

            // Verifica se o documento foi baixado com sucesso
            if ($documento && $documento->status == ProcessoDocumento::STATUS_BAIXADO && Storage::disk('s3')->exists($documento->path)) {
                try {
                    $documento->link = Storage::disk('s3')->temporaryUrl(
                        $documento->path,
                        now()->addMinutes(60)
                    );

                    return $this->anexarConteudoHtml($service, $documento, $login_pje, $senha_pje);
                } catch (\Exception $e) {
                    Log::error('Erro ao gerar link temporário para documento após download: ' . $e->getMessage(), [
                        'trace' => $e->getTraceAsString()
                    ]);
                    return null;
                }
            }

            return null;
        } catch (MNIException $e) {
            Log::error('MNIException ao obter documento: ' . $e->getError());
            throw new MNIException($e->getError(), 500);
        } catch (\Exception $e) {
            Log::error('Erro ao obter documento: ' . $e->getMessage());
            throw new MNIException($e->getMessage(), 500);
        }
    }

    /**
     * Anexa o conteudo HTML ao documento apenas para a resposta (nada é persistido).
     * Se um documento HTML ficou sem conteudo recuperavel (objeto sumiu do S3),
     * tenta a auto-correcao re-executando o downloadHTML uma vez.
     */
    private function anexarConteudoHtml(SalvarDocumentoProcessoService $service, ProcessoDocumento $documento, $login_pje = null, $senha_pje = null): ProcessoDocumento
    {
        $conteudo = $service->obterConteudoHtml($documento);

        if ($conteudo === null && $documento->mimetype === 'text/html') {
            try {
                $service->downloadHTML($documento, $login_pje, $senha_pje);
                $conteudo = $service->obterConteudoHtml($documento);
            } catch (\Exception $e) {
                Log::error('Erro ao recuperar conteudo HTML do documento', [
                    'documento_id' => $documento->id_documento,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $documento->conteudo_html = $conteudo;
        $documento->makeVisible('conteudo_html');

        return $documento;
    }
```

Mudanças em relação ao original: a checagem da linha 77 usa `temConteudoHtml()`; os dois pontos de retorno com link agora passam por `anexarConteudoHtml`, que injeta o conteúdo (coluna → S3 → auto-correção) e o expõe via `makeVisible`, sem nenhum `save()` — nada volta para a coluna. Documentos não-HTML recebem `conteudo_html` nulo, mantendo a chave no JSON como hoje.

- [ ] **Step 4: Rodar e ver passar**

Run: `./php artisan test tests/Feature/Api/DocumentoControllerTest.php`
Expected: PASS (9 testes).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/DocumentoController.php tests/Feature/Api/DocumentoControllerTest.php
git commit -m "feat(api): hidrata conteudo_html do S3 no endpoint de visualizar documento

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: Verificação final — suíte completa

**Files:**
- Nenhum arquivo novo; correções pontuais apenas se a suíte apontar regressão.

**Interfaces:**
- Consumes: tudo das tasks 1–5.
- Produces: suíte inteira verde; branch pronta para revisão/merge.

- [ ] **Step 1: Rodar a suíte completa**

Run: `./php artisan test`
Expected: PASS em todos os testes (incluindo `ProcessoConsultaTest`, `ConsultarProcessoControllerTest` e os demais que tocam documentos). Nenhum teste existente pode regredir.

- [ ] **Step 2: Conferir que nada além do planejado mudou**

Run: `git status && git log --oneline -8`
Expected: working tree sem alterações não commitadas relacionadas a esta feature; 5 commits novos da feature na branch (Tasks 1–5).

- [ ] **Step 3: Verificação manual do comportamento (opcional, se houver ambiente com S3 e MNI)**

Chamar `GET /api/documento/visualizar` de um documento HTML novo e conferir no bucket que existem `{id_documento}.pdf` E `{id_documento}.html` na pasta do processo, e que a linha correspondente em `processo_documentos` tem `path_html` preenchido e `conteudo_html` NULL.
