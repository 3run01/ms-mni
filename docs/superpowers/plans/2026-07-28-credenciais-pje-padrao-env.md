# Credenciais PJe padrão via .env — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que `login_pje`/`senha_pje` sejam omitidos nas requisições da API, caindo para um par padrão configurado no `.env` e, na falta dele, para as credenciais do tribunal.

**Architecture:** Um middleware no grupo de rotas da API injeta o par padrão (`config('pje.credenciais_padrao')`) no request quando o cliente não envia credenciais completas. A validação nos controllers passa de `required` para `nullable`. Nenhum service ou job muda — o fallback para as credenciais do tribunal já existe na camada de service.

**Tech Stack:** Laravel 11, Pest (feature/unit tests), PHP rodando em container Docker.

**Spec:** `docs/superpowers/specs/2026-07-28-credenciais-pje-padrao-env-design.md`

---

## Preparação (uma vez, antes da Task 1)

O PHP deste projeto roda em container. O wrapper `./php` na raiz executa `docker compose exec -u composer php php $@`.

```bash
cd /home/brunoneves/projetos/ms-mni
docker compose up -d
./php artisan --version
```

Esperado: a versão do Laravel (ex.: `Laravel Framework 11.x`). Se aparecer `service "php" is not running`, o container não subiu — resolva antes de continuar.

Comando de teste usado em todo o plano:

```bash
./php artisan test tests/Feature/Api/ConsultarProcessoControllerTest.php
```

## Estrutura de arquivos

| Arquivo | Responsabilidade |
| --- | --- |
| `config/pje.php` (criar) | Expõe o par padrão vindo do `.env` |
| `.env.example` (modificar) | Documenta `PJE_LOGIN_PADRAO` / `PJE_SENHA_PADRAO` |
| `app/Http/Middleware/InjectCredenciaisPjePadrao.php` (criar) | Único ponto que decide qual par de credenciais entra no request |
| `routes/api.php` (modificar) | Registra o middleware no grupo já protegido por `ValidateApiToken` |
| `app/Http/Controllers/Api/ConsultarProcessoController.php` (modificar) | `required` → `nullable` em 6 métodos |
| `app/Http/Controllers/Api/DocumentoController.php` (modificar) | `required` → `nullable` em 3 métodos |
| `tests/Pest.php` (modificar) | Helper global `definirCredenciaisPadrao()` |
| `tests/Unit/InjectCredenciaisPjePadraoTest.php` (criar) | Regras do middleware isoladas |
| `tests/Feature/Api/ConsultarProcessoControllerTest.php` (modificar) | Matriz de precedência nos endpoints de processo |
| `tests/Feature/Api/DocumentoControllerTest.php` (modificar) | Matriz de precedência nos endpoints de documento |
| `docs/api/openapi.yaml` (modificar) | Parâmetros deixam de ser obrigatórios |

---

## Task 1: Config `config/pje.php` e `.env.example`

**Files:**
- Create: `config/pje.php`
- Modify: `.env.example`
- Test: `tests/Unit/ConfigPjeTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Criar `tests/Unit/ConfigPjeTest.php`:

```php
<?php

it('expoe as chaves de credenciais padrao do PJe', function () {
    expect(config('pje.credenciais_padrao'))->toBeArray()
        ->toHaveKeys(['login', 'senha']);
});
```

O teste checa só a presença das chaves — nunca os valores, porque o `.env` da máquina do dev pode ter o par preenchido.

- [ ] **Step 2: Rodar o teste e confirmar que falha**

```bash
./php artisan test tests/Unit/ConfigPjeTest.php
```

Esperado: FAIL — `config('pje.credenciais_padrao')` é `null`, então `toBeArray()` quebra.

- [ ] **Step 3: Criar o arquivo de config**

Criar `config/pje.php`:

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credenciais PJe padrão
    |--------------------------------------------------------------------------
    |
    | Par usado quando a requisição não envia login_pje/senha_pje. Se estiver
    | vazio, a requisição segue sem credencial e o fallback das credenciais
    | cadastradas no tribunal (camada de service) decide.
    |
    */

    'credenciais_padrao' => [
        'login' => env('PJE_LOGIN_PADRAO'),
        'senha' => env('PJE_SENHA_PADRAO'),
    ],

];
```

- [ ] **Step 4: Rodar o teste e confirmar que passa**

```bash
./php artisan test tests/Unit/ConfigPjeTest.php
```

Esperado: PASS (1 passed).

- [ ] **Step 5: Documentar as variáveis no `.env.example`**

Acrescentar ao final de `.env.example`:

```
# Credenciais PJe padrao usadas quando a requisicao nao envia login_pje/senha_pje.
# Opcionais: se ficarem vazias, o cliente da API precisa enviar as credenciais ou
# o tribunal precisa ter credencial cadastrada no banco.
PJE_LOGIN_PADRAO=
PJE_SENHA_PADRAO=
```

- [ ] **Step 6: Commit**

```bash
git add config/pje.php .env.example tests/Unit/ConfigPjeTest.php
git commit -m "feat(pje): config de credenciais padrao via env"
```

---

## Task 2: Middleware `InjectCredenciaisPjePadrao`

**Files:**
- Create: `app/Http/Middleware/InjectCredenciaisPjePadrao.php`
- Modify: `routes/api.php:16`
- Test: `tests/Unit/InjectCredenciaisPjePadraoTest.php`

- [ ] **Step 1: Escrever os testes que falham**

Criar `tests/Unit/InjectCredenciaisPjePadraoTest.php`:

```php
<?php

use App\Http\Middleware\InjectCredenciaisPjePadrao;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Roda o middleware sobre uma requisição GET fake e devolve o request
 * como ele chegou no próximo handler.
 */
function passarPeloMiddlewarePje(array $query): Request
{
    $request = Request::create('/api/processo/consultar', 'GET', $query);
    $capturado = null;

    (new InjectCredenciaisPjePadrao())->handle($request, function (Request $r) use (&$capturado) {
        $capturado = $r;

        return new Response();
    });

    return $capturado;
}

beforeEach(function () {
    config()->set('pje.credenciais_padrao.login', null);
    config()->set('pje.credenciais_padrao.senha', null);
});

it('injeta o par padrao quando a requisicao nao envia credenciais', function () {
    config()->set('pje.credenciais_padrao.login', 'env-login');
    config()->set('pje.credenciais_padrao.senha', 'env-senha');

    $request = passarPeloMiddlewarePje(['tribunal_id' => 1]);

    expect($request->input('login_pje'))->toBe('env-login')
        ->and($request->input('senha_pje'))->toBe('env-senha');
});

it('preserva as credenciais enviadas na requisicao', function () {
    config()->set('pje.credenciais_padrao.login', 'env-login');
    config()->set('pje.credenciais_padrao.senha', 'env-senha');

    $request = passarPeloMiddlewarePje([
        'login_pje' => 'req-login',
        'senha_pje' => 'req-senha',
    ]);

    expect($request->input('login_pje'))->toBe('req-login')
        ->and($request->input('senha_pje'))->toBe('req-senha');
});

it('substitui o par inteiro quando a requisicao envia so o login', function () {
    config()->set('pje.credenciais_padrao.login', 'env-login');
    config()->set('pje.credenciais_padrao.senha', 'env-senha');

    $request = passarPeloMiddlewarePje(['login_pje' => 'req-login']);

    expect($request->input('login_pje'))->toBe('env-login')
        ->and($request->input('senha_pje'))->toBe('env-senha');
});

it('nao injeta nada quando o par padrao esta vazio', function () {
    $request = passarPeloMiddlewarePje(['tribunal_id' => 1]);

    expect($request->input('login_pje'))->toBeNull()
        ->and($request->input('senha_pje'))->toBeNull();
});

it('nao injeta nada quando so o login padrao esta configurado', function () {
    config()->set('pje.credenciais_padrao.login', 'env-login');

    $request = passarPeloMiddlewarePje(['tribunal_id' => 1]);

    expect($request->input('login_pje'))->toBeNull()
        ->and($request->input('senha_pje'))->toBeNull();
});
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

```bash
./php artisan test tests/Unit/InjectCredenciaisPjePadraoTest.php
```

Esperado: FAIL — `Class "App\Http\Middleware\InjectCredenciaisPjePadrao" not found`.

- [ ] **Step 3: Implementar o middleware**

Criar `app/Http/Middleware/InjectCredenciaisPjePadrao.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Preenche login_pje/senha_pje com o par padrão do .env quando a requisição
 * não traz as duas credenciais. O par é atômico: uma requisição com apenas
 * uma das credenciais recebe o par padrão inteiro, nunca uma combinação
 * de login do cliente com senha do servidor.
 *
 * Sem par padrão configurado, o request segue como veio e o fallback das
 * credenciais do tribunal (camada de service) decide.
 */
class InjectCredenciaisPjePadrao
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->filled('login_pje') && $request->filled('senha_pje')) {
            return $next($request);
        }

        $login = config('pje.credenciais_padrao.login');
        $senha = config('pje.credenciais_padrao.senha');

        if (filled($login) && filled($senha)) {
            $request->merge([
                'login_pje' => $login,
                'senha_pje' => $senha,
            ]);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Rodar os testes e confirmar que passam**

```bash
./php artisan test tests/Unit/InjectCredenciaisPjePadraoTest.php
```

Esperado: PASS (5 passed).

- [ ] **Step 5: Registrar o middleware no grupo de rotas da API**

Em `routes/api.php`, adicionar o import junto aos existentes:

```php
use App\Http\Middleware\InjectCredenciaisPjePadrao;
```

E trocar a linha 16:

```php
Route::middleware(ValidateApiToken::class)->group(function () {
```

por:

```php
Route::middleware([ValidateApiToken::class, InjectCredenciaisPjePadrao::class])->group(function () {
```

- [ ] **Step 6: Rodar a suíte de API para garantir que nada quebrou**

```bash
./php artisan test tests/Feature/Api
```

Esperado: PASS. Os controllers ainda validam `required`, e como nenhum teste configura `pje.credenciais_padrao`, o middleware não injeta nada — o comportamento atual segue idêntico.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/InjectCredenciaisPjePadrao.php routes/api.php tests/Unit/InjectCredenciaisPjePadraoTest.php
git commit -m "feat(pje): middleware que injeta credenciais padrao na API"
```

---

## Task 3: `ConsultarProcessoController` — validação `nullable` e testes

**Files:**
- Modify: `tests/Pest.php`
- Modify: `tests/Feature/Api/ConsultarProcessoControllerTest.php`
- Modify: `app/Http/Controllers/Api/ConsultarProcessoController.php`

- [ ] **Step 1: Adicionar o helper global de config nos testes**

Ao final de `tests/Pest.php`, depois de `criarTokenApi()`:

```php
function definirCredenciaisPadrao(?string $login, ?string $senha): void
{
    config()->set('pje.credenciais_padrao.login', $login);
    config()->set('pje.credenciais_padrao.senha', $senha);
}
```

- [ ] **Step 2: Reescrever os testes de `ConsultarProcessoControllerTest`**

Em `tests/Feature/Api/ConsultarProcessoControllerTest.php`, trocar o `beforeEach` (linhas 13-15) por:

```php
beforeEach(function () {
    criarTokenApi();
    // isola do .env da máquina: cada teste declara o par padrão que quer
    definirCredenciaisPadrao(null, null);
});
```

**Substituir** o teste `it('consultar sem login_pje e senha_pje retorna 422', ...)` (linhas 28-34) por:

```php
it('consultar sem credenciais usa o par padrao do .env', function () {
    Queue::fake();
    definirCredenciaisPadrao('env-login', 'env-senha');
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertOk();

    Queue::assertPushed(
        BaixarProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'env-login' && $job->senha_pje === 'env-senha'
    );
});
```

**Substituir** `it('consultar com login_pje mas sem senha_pje retorna 422', ...)` (linhas 36-43) por:

```php
it('consultar com par incompleto usa o par padrao do .env inteiro', function () {
    Queue::fake();
    definirCredenciaisPadrao('env-login', 'env-senha');
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=usuario')
        ->assertOk();

    Queue::assertPushed(
        BaixarProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'env-login' && $job->senha_pje === 'env-senha'
    );
});

it('consultar com credenciais na requisicao ignora o par padrao do .env', function () {
    Queue::fake();
    definirCredenciaisPadrao('env-login', 'env-senha');
    criarProcessoParaConsulta('0600125-81.2024.8.03.0003');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&login_pje=req-login&senha_pje=req-senha')
        ->assertOk();

    Queue::assertPushed(
        BaixarProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'req-login' && $job->senha_pje === 'req-senha'
    );
});
```

**Substituir** `it('visualizar sem credenciais retorna 422 mesmo com processo em banco', ...)` (linhas 72-80) por:

```php
it('visualizar sem credenciais e sem par padrao repassa null ao ProcessoService', function () {
    $this->mock(ProcessoService::class, function ($mock) {
        $mock->shouldReceive('consultarNumero')
            ->once()
            ->withArgs(fn ($tribunal, $numero, $login, $senha) => $login === null && $senha === null);
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/visualizar?tribunal_id=1&numero_processo=9999999-99.2024.8.03.9999')
        ->assertOk();
});
```

**Substituir** `it('dados-basicos sem credenciais retorna 422', ...)` (linhas 123-130) por:

```php
it('dados-basicos sem credenciais usa o par padrao do .env', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');

    $this->mock(ProcessoService::class, function ($mock) {
        $mock->shouldReceive('consultarDadosBasicos')
            ->once()
            ->withArgs(fn ($tribunal, $numero, $login, $senha) => $login === 'env-login' && $senha === 'env-senha')
            ->andReturn(new Processo());
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/dados-basicos?tribunal_id=1&numero_processo=9999999-99.2024.8.03.9999')
        ->assertOk();
});
```

**Substituir** `it('movimentos sem credenciais retorna 422', ...)` (linhas 145-152) por:

```php
it('movimentos sem credenciais usa o par padrao do .env', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');
    $processo = criarProcessoParaConsulta('0600125-81.2024.8.03.0003');
    $processo->setRelation('movimentos', collect());

    $this->mock(ProcessoService::class, function ($mock) use ($processo) {
        $mock->shouldReceive('consultarMovimentos')
            ->once()
            ->withArgs(fn ($tribunal, $numero, $login, $senha, $dataRef) => $login === 'env-login' && $senha === 'env-senha')
            ->andReturn($processo);
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/movimentos/listar?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertOk();
});
```

**Substituir** `it('dados-basicos async sem credenciais retorna 422', ...)` (linhas 172-177) por:

```php
it('dados-basicos async sem credenciais despacha job com o par padrao do .env', function () {
    Queue::fake();
    definirCredenciaisPadrao('env-login', 'env-senha');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/dados-basicos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&callback_url=https://example.com/webhook&callback_token=tok-x')
        ->assertOk();

    Queue::assertPushed(
        ConsultarDadosBasicosProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'env-login' && $job->senha_pje === 'env-senha'
    );
});

it('dados-basicos async sem callback continua retornando 422', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/dados-basicos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['callback_url', 'callback_token'])
        ->assertJsonMissingValidationErrors(['login_pje', 'senha_pje']);
});
```

**Substituir** `it('movimentos async sem credenciais retorna 422', ...)` (linhas 193-198) por:

```php
it('movimentos async sem credenciais despacha job com o par padrao do .env', function () {
    Queue::fake();
    definirCredenciaisPadrao('env-login', 'env-senha');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/movimentos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&callback_url=https://example.com/webhook&callback_token=tok-x')
        ->assertOk();

    Queue::assertPushed(
        ConsultarMovimentosProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'env-login' && $job->senha_pje === 'env-senha'
    );
});
```

Os demais testes do arquivo (os que já enviam credenciais na query) ficam intactos.

- [ ] **Step 3: Rodar os testes e confirmar que falham**

```bash
./php artisan test tests/Feature/Api/ConsultarProcessoControllerTest.php
```

Esperado: FAIL nos testes reescritos — as respostas vêm 422 porque o controller ainda valida `required` (ex.: `Expected response status code [200] but received [422]`).

- [ ] **Step 4: Trocar `required` por `nullable` no controller**

Em `app/Http/Controllers/Api/ConsultarProcessoController.php`, nos métodos `index`, `show`, `consultarDadosBasicos`, `consultarMovimentos`, `consultarDadosBasicosAsync` e `consultarMovimentosAsync`, trocar:

```php
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
```

por:

```php
            'login_pje' => 'nullable|string',
            'senha_pje' => 'nullable|string',
```

São 6 ocorrências. `callback_url` e `callback_token` continuam `required` nos dois métodos async.

- [ ] **Step 5: Rodar os testes e confirmar que passam**

```bash
./php artisan test tests/Feature/Api/ConsultarProcessoControllerTest.php
```

Esperado: PASS em todos os testes do arquivo.

- [ ] **Step 6: Commit**

```bash
git add tests/Pest.php tests/Feature/Api/ConsultarProcessoControllerTest.php app/Http/Controllers/Api/ConsultarProcessoController.php
git commit -m "feat(pje): credenciais opcionais em ConsultarProcessoController"
```

---

## Task 4: `DocumentoController` — validação `nullable` e testes

**Files:**
- Modify: `tests/Feature/Api/DocumentoControllerTest.php`
- Modify: `app/Http/Controllers/Api/DocumentoController.php`

- [ ] **Step 1: Reescrever os testes de `DocumentoControllerTest`**

Em `tests/Feature/Api/DocumentoControllerTest.php`, trocar o `beforeEach` (linhas 14-16) por:

```php
beforeEach(function () {
    criarTokenApi();
    definirCredenciaisPadrao(null, null);
});
```

**Remover** os três testes de 422 do topo do arquivo (linhas 18-42): `it('documento visualizar sem credenciais retorna 422', ...)`, `it('documentos listar sem credenciais retorna 422', ...)` e `it('documentos async sem credenciais retorna 422', ...)`.

No lugar dos dois primeiros, inserir logo abaixo do `beforeEach`:

```php
it('documentos listar sem credenciais usa o par padrao do .env', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');
    $numero = 'LISTENV' . getmypid();

    $processo = Processo::create([
        'numero_processo' => $numero,
        'tribunal_id' => 999999,
        'valor_causa' => '0.00',
    ]);
    $processo->setRelation('documentos', collect());

    $this->mock(\App\Services\Processo\ProcessoService::class, function ($mock) use ($processo) {
        $mock->shouldReceive('consultarDocumentos')
            ->once()
            ->withArgs(fn ($tribunal, $num, $login, $senha, $dataRef) => $login === 'env-login' && $senha === 'env-senha')
            ->andReturn($processo);
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/processo/documentos/listar?tribunal_id=999999&numero_processo={$numero}")
        ->assertOk();
});

it('documentos listar sem credenciais e sem par padrao repassa null ao ProcessoService', function () {
    $numero = 'LISTNULL' . getmypid();

    $processo = Processo::create([
        'numero_processo' => $numero,
        'tribunal_id' => 999999,
        'valor_causa' => '0.00',
    ]);
    $processo->setRelation('documentos', collect());

    $this->mock(\App\Services\Processo\ProcessoService::class, function ($mock) use ($processo) {
        $mock->shouldReceive('consultarDocumentos')
            ->once()
            ->withArgs(fn ($tribunal, $num, $login, $senha, $dataRef) => $login === null && $senha === null)
            ->andReturn($processo);
    });

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/processo/documentos/listar?tribunal_id=999999&numero_processo={$numero}")
        ->assertOk();
});
```

No lugar do terceiro (async), inserir logo acima de `it('documentos async despacha job com as credenciais do payload', ...)`:

```php
it('documentos async sem credenciais despacha job com o par padrao do .env', function () {
    Queue::fake();
    definirCredenciaisPadrao('env-login', 'env-senha');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/documentos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003&callback_url=https://example.com/webhook&callback_token=tok-x')
        ->assertOk();

    Queue::assertPushed(
        ConsultarDocumentosProcessoMNIJob::class,
        fn ($job) => $job->login_pje === 'env-login' && $job->senha_pje === 'env-senha'
    );
});

it('documentos async sem callback continua retornando 422', function () {
    definirCredenciaisPadrao('env-login', 'env-senha');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson('/api/processo/consultar/documentos/async?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['callback_url', 'callback_token'])
        ->assertJsonMissingValidationErrors(['login_pje', 'senha_pje']);
});
```

E, para cobrir o `show`, adicionar **ao final do arquivo** (depois das funções `criarDocumentoVisualizar()` e `fakeS3ComLinks()`, que já existem no arquivo):

```php
it('visualizar sem credenciais responde 200 usando o par padrao do .env', function () {
    fakeS3ComLinks();
    definirCredenciaisPadrao('env-login', 'env-senha');
    $numero = 'VISENV' . getmypid();
    criarDocumentoVisualizar($numero, 930010);
    Storage::disk('s3')->put("documentos-processos/{$numero}/930010.pdf", 'pdf-fake');

    $this->withHeaders(['X-API-Token' => 'tk-test'])
        ->getJson("/api/documento/visualizar?tribunal_id=999999&numero_processo={$numero}&id_documento=930010")
        ->assertOk()
        ->assertJsonPath('documento.link', "https://s3.fake/documentos-processos/{$numero}/930010.pdf");
});
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

```bash
./php artisan test tests/Feature/Api/DocumentoControllerTest.php
```

Esperado: FAIL nos testes novos — status 422 em vez de 200, porque o controller ainda exige as credenciais.

- [ ] **Step 3: Trocar `required` por `nullable` no controller**

Em `app/Http/Controllers/Api/DocumentoController.php`, nos métodos `show` (linhas 29-32), `listarDocumentos` (linhas 189-192) e `consultarDocumentosAsync` (linhas 222-227), trocar:

```php
            'login_pje' => 'required|string',
            'senha_pje' => 'required|string',
```

por:

```php
            'login_pje' => 'nullable|string',
            'senha_pje' => 'nullable|string',
```

São 3 ocorrências. Em `consultarDocumentosAsync`, `callback_url` e `callback_token` continuam `required`.

- [ ] **Step 4: Rodar os testes e confirmar que passam**

```bash
./php artisan test tests/Feature/Api/DocumentoControllerTest.php
```

Esperado: PASS em todos os testes do arquivo.

- [ ] **Step 5: Rodar a suíte inteira**

```bash
./php artisan test
```

Esperado: PASS. Se algum teste fora de `tests/Feature/Api` assertava 422 por credencial ausente, ajuste-o seguindo o mesmo padrão dos testes desta task.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/Api/DocumentoControllerTest.php app/Http/Controllers/Api/DocumentoController.php
git commit -m "feat(pje): credenciais opcionais em DocumentoController"
```

---

## Task 5: Atualizar a documentação OpenAPI

**Files:**
- Modify: `docs/api/openapi.yaml:713-724` (parâmetros `LoginPje` / `SenhaPje`) e `:762-777` (resposta `Erro422`)

- [ ] **Step 1: Marcar os parâmetros como opcionais**

Em `docs/api/openapi.yaml`, substituir o bloco:

```yaml
    LoginPje:
      name: login_pje
      in: query
      required: true
      description: Login do usuário no PJe. Enviado na query string (ver aviso de segurança).
      schema: { type: string }
    SenhaPje:
      name: senha_pje
      in: query
      required: true
      description: Senha do usuário no PJe. Enviada na query string (ver aviso de segurança).
      schema: { type: string }
```

por:

```yaml
    LoginPje:
      name: login_pje
      in: query
      required: false
      description: >-
        Login do usuário no PJe. Opcional: quando omitido (ou quando só uma das
        duas credenciais é enviada), o servidor usa o par padrão configurado em
        PJE_LOGIN_PADRAO/PJE_SENHA_PADRAO; sem par padrão, valem as credenciais
        cadastradas no tribunal. Enviado na query string (ver aviso de segurança).
      schema: { type: string }
    SenhaPje:
      name: senha_pje
      in: query
      required: false
      description: >-
        Senha do usuário no PJe. Opcional, seguindo a mesma cadeia de login_pje:
        requisição → PJE_SENHA_PADRAO → credencial do tribunal. Enviada na query
        string (ver aviso de segurança).
      schema: { type: string }
```

- [ ] **Step 2: Corrigir o exemplo do erro 422**

Substituir o bloco:

```yaml
    Erro422:
      description: Falha de validação (ex. login_pje/senha_pje ausentes).
```

por:

```yaml
    Erro422:
      description: Falha de validação (ex. callback_url/callback_token ausentes nos endpoints assíncronos).
```

E, no mesmo bloco, substituir:

```yaml
          example:
            message: "The login pje field is required."
            errors: { login_pje: ["The login pje field is required."] }
```

por:

```yaml
          example:
            message: "The callback url field is required."
            errors: { callback_url: ["The callback url field is required."] }
```

- [ ] **Step 3: Verificar que a spec continua sendo servida**

```bash
./php artisan test tests/Feature/DocsApiTest.php
```

Esperado: PASS (2 passed).

- [ ] **Step 4: Commit**

```bash
git add docs/api/openapi.yaml
git commit -m "docs(api): login_pje/senha_pje passam a ser opcionais na spec"
```

---

## Verificação final

- [ ] **Rodar a suíte completa**

```bash
./php artisan test
```

Esperado: todos os testes passando.

- [ ] **Conferir o comportamento manual com o `.env` preenchido**

Preencher `PJE_LOGIN_PADRAO` e `PJE_SENHA_PADRAO` no `.env` local, rodar `./php artisan config:clear` e chamar um endpoint sem credenciais:

```bash
curl -s -H "X-API-Token: <token-valido>" \
  "http://localhost:8001/api/processo/dados-basicos?tribunal_id=1&numero_processo=0600125-81.2024.8.03.0003" | head -c 400
```

Esperado: resposta do processo (não um 422 de validação).
