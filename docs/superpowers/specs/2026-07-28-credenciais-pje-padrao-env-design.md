# Credenciais PJe padrão via .env

**Data:** 2026-07-28
**Status:** Aprovado

## Objetivo

Permitir que `login_pje` e `senha_pje` deixem de ser obrigatórios nas requisições da API, usando um par padrão configurado no `.env` quando o cliente não os enviar.

## Contexto

Hoje `login_pje` e `senha_pje` são `required|string` em 6 métodos de `app/Http/Controllers/Api/ConsultarProcessoController.php` e 3 de `app/Http/Controllers/Api/DocumentoController.php`. Sem eles a requisição retorna 422.

A camada de service já tem fallback para as credenciais cadastradas no tribunal — `$login_pje ?? $tribunal->login` e `$senha_pje ?? Crypt::decrypt($tribunal->password)` (`app/Services/MNI/Intercomunicacao/ConsultarProcessoService.php:31`). O que bloqueia a chamada é apenas a validação no controller.

A obrigatoriedade foi introduzida deliberadamente em `docs/superpowers/specs/2026-07-07-remocao-ocr-samia-credenciais-obrigatorias-design.md`. Este spec **reverte essa decisão** de forma consciente: a credencial passa a ser opcional, com um default global no `.env`.

## Decisões

| Decisão | Escolha |
| --- | --- |
| Escopo | `ConsultarProcessoController` **e** `DocumentoController` |
| Precedência | requisição → `.env` → credencial do tribunal |
| Onde resolver | Middleware no grupo de rotas da API |
| Par de credenciais | Atômico — nunca mistura login da requisição com senha do `.env` |
| Sem `.env` e sem requisição | Segue `null`; fallback do tribunal decide (sem 422) |

## 1. Configuração

Novo arquivo `config/pje.php`:

```php
<?php

return [
    'credenciais_padrao' => [
        'login' => env('PJE_LOGIN_PADRAO'),
        'senha' => env('PJE_SENHA_PADRAO'),
    ],
];
```

`.env.example` recebe as duas chaves vazias, com comentário indicando que são opcionais e que, quando ausentes, o cliente da API deve enviar `login_pje`/`senha_pje` ou o tribunal precisa ter credencial cadastrada.

## 2. Middleware `InjectCredenciaisPjePadrao`

`app/Http/Middleware/InjectCredenciaisPjePadrao.php`:

1. Se a requisição traz `login_pje` **e** `senha_pje` preenchidos (`$request->filled()`), não altera nada.
2. Caso contrário, se `config('pje.credenciais_padrao.login')` **e** `...senha` estão preenchidos, faz `$request->merge()` injetando o par completo — substituindo qualquer valor parcial que tenha vindo na requisição.
3. Caso contrário, não altera nada. Os controllers passam `null` adiante e o fallback do tribunal, já existente nos services, decide.

O par é atômico: uma requisição com apenas `login_pje` recebe o par inteiro do `.env`, evitando a combinação login-do-cliente + senha-do-servidor.

Todos os endpoints afetados são `GET` com query params; `$request->merge()` alimenta `$request->input()`, que é o que o acesso mágico `$request->login_pje` usa.

## 3. Registro

`routes/api.php`, grupo já existente:

```php
Route::middleware([ValidateApiToken::class, InjectCredenciaisPjePadrao::class])->group(function () {
```

Cobre os dois controllers e qualquer endpoint novo adicionado ao grupo.

## 4. Controllers

Trocar `'required|string'` por `'nullable|string'` nas regras de `login_pje` e `senha_pje`:

- `ConsultarProcessoController`: `index`, `show`, `consultarDadosBasicos`, `consultarMovimentos`, `consultarDadosBasicosAsync`, `consultarMovimentosAsync`
- `DocumentoController`: `show`, `listarDocumentos`, `consultarDocumentosAsync`

`callback_url` e `callback_token` continuam `required` nos endpoints assíncronos. Nenhum service ou job muda.

## 5. Testes

Os casos existentes que assertam 422 na ausência de credenciais são reescritos (não removidos):

- `tests/Feature/Api/ConsultarProcessoControllerTest.php` — "consultar sem login_pje e senha_pje retorna 422", "consultar com login_pje mas sem senha_pje retorna 422", "visualizar sem credenciais retorna 422 mesmo com processo em banco", "dados-basicos sem credenciais retorna 422" e demais equivalentes
- `tests/Feature/Api/DocumentoControllerTest.php` — "documento visualizar sem credenciais retorna 422", "documentos listar sem credenciais retorna 422", "documentos async sem credenciais retorna 422"

Nova matriz de cobertura, com `config()->set('pje.credenciais_padrao.login'|'senha')` para simular o `.env`:

| Requisição | `.env` | Esperado |
| --- | --- | --- |
| sem credenciais | preenchido | job/service recebe o par do `.env` |
| com credenciais | preenchido | prevalece o par da requisição |
| só `login_pje` | preenchido | usa o par do `.env` (atômico) |
| sem credenciais | vazio | chega `null`; sem 422 |

As asserções seguem o padrão já usado no projeto: `Queue::assertPushed(..., fn ($job) => $job->login_pje === '...')`.

## 6. Documentação

`docs/api/openapi.yaml`:

- `required: true` → `required: false` nos parâmetros `login_pje` e `senha_pje`
- descrição dos parâmetros explicando a cadeia requisição → `.env` → credencial do tribunal
- o exemplo do erro 422 que cita `login_pje: ["The login pje field is required."]` passa a usar outro campo obrigatório (ex.: `callback_url`)

O aviso de segurança sobre não registrar em log as URLs completas permanece.

## Segurança

A senha padrão fica em texto plano no `.env`, mesmo padrão das demais credenciais do projeto. Diferente da senha do tribunal, que é armazenada criptografada no banco, o valor do `.env` é usado direto. Consequência operacional: quem tem token válido da API passa a conseguir consultar processos sem apresentar credencial PJe própria — o controle de acesso passa a depender inteiramente do `ValidateApiToken`.
