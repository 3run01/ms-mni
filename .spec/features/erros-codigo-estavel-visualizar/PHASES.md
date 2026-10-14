# Phases: erros-codigo-estavel-visualizar

Gerado por /plan a partir de PLAN.md: view executável para `./ralph.sh .spec/features/erros-codigo-estavel-visualizar/PHASES.md`.

## Phase 1: Seams sem mudança de comportamento

Antes de implementar, leia:
1. `.spec/features/erros-codigo-estavel-visualizar/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/erros-codigo-estavel-visualizar/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T01 — Seam do cliente SOAP em ConsultarProcessoService e simulador MNI para testes
      Arquivos: `app/Services/MNI/Intercomunicacao/ConsultarProcessoService.php`, `tests/Pest.php`, `tests/Unit/Services/MNI/ConsultarProcessoServiceTest.php` (novo)
      Mudança: trocar `new IntegracaoBase($tribunal->url_webservice_mni_consultar_processo)` (linha 40) por `app()->makeWith(IntegracaoBase::class, ['url' => $tribunal->url_webservice_mni_consultar_processo])`, com comentário pt-BR do seam (como em ConsultarDocumentoService.php:116). Nada mais muda: try/catch, códigos e textos ficam idênticos. Em `tests/Pest.php`, criar o helper global `simularIntegracaoMni(Closure $responder): stdClass`. Ele faz o bind de `IntegracaoBase` no container com um mock de `makeSoapRequest` que registra `operacao`/`parametros` em `->requisicoes` e delega ao `$responder` (retorna objeto ou lança SoapFault). A consulta de documento é a que tem a chave `documento` em `parametros`.
      Cobre: RNF-04; pré-requisito de RF-01, RF-02, RF-06, RF-07
      Acceptance criteria: ConsultarProcessoService.php não contém `new IntegracaoBase(` e resolve `IntegracaoBase::class` via `app()->makeWith` com `url_webservice_mni_consultar_processo`; o resto do método fica igual ao de `b9f0028`; `tests/Pest.php` define `simularIntegracaoMni`; os testes novos passam e a suíte não regride.
      Testes: `tests/Unit/Services/MNI/ConsultarProcessoServiceTest.php` — resolve IntegracaoBase pelo container com a URL certa; repassa o par informado como idConsultante/senhaConsultante; `sucesso=false` "MNI indisponível" → MNIException 500 com o texto; SoapFault → MNIException 500; senha do tribunal indecifrável → 422 sem chamar o MNI (`docker compose exec -T php php artisan test --compact --filter=ConsultarProcessoServiceTest`)
- [ ] T02 — DocumentoController usa o ProcessoService injetado
      Arquivos: `app/Http/Controllers/Api/DocumentoController.php`, `tests/Feature/Api/DocumentoControllerTest.php`
      Mudança: em `vericarExistenciaDocumento` (linhas 171-173), trocar `new ProcessoService()` + `consultarNumero` por `$this->processoService->consultarNumero($tribunal, $numero_processo, $login_pje, $senha_pje)`, usando a instância do construtor. Mesmos argumentos, sem outra mudança (Q-08).
      Cobre: RNF-04, RF-06 (AC "Documento não encontrado")
      Acceptance criteria: DocumentoController.php não contém `new ProcessoService(`; `vericarExistenciaDocumento` chama `$this->processoService->consultarNumero` com os 4 argumentos de antes; o teste novo passa.
      Testes: `tests/Feature/Api/DocumentoControllerTest.php` — `$this->mock(ProcessoService::class)` com `consultarNumero` chamado uma vez com o par da query e sem efeito; documento continua ausente → 500 `assertExactJson(['message' => 'Documento não encontrado'])`, sem `codigo`

## Phase 2: Caracterização do comportamento atual

Antes de implementar, leia:
1. `.spec/features/erros-codigo-estavel-visualizar/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/erros-codigo-estavel-visualizar/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T03 — Testes de caracterização de /api/processo/visualizar (RF-06, RF-07)
      Arquivos: `tests/Feature/Api/ConsultarProcessoControllerTest.php`
      Mudança: criar o bloco "erros MNI em /api/processo/visualizar" com testes que passam no código atual e usam `simularIntegracaoMni`, tribunal de factory (senha `Crypt::encrypt` quando o cenário usa a credencial do tribunal) e processo ausente. Cenários:
      (a) par do cliente + `sucesso=false` "MNI indisponível" → 500 `error`/`line`/`file`, sem `codigo`;
      (b) par padrão + loginFailed → 500 com `error` "Erro ao realizar login via MNI. Verifique suas credenciais.";
      (c) dataset de textos fora do padrão RF-02 → 500 com `error` = texto;
      (d) par do cliente + "Erro ao realizar login via MNI." → 500 normalizado;
      (e) senha do tribunal indecifrável → 422 "Credenciais MNI/PJ-e tribunal inválidas.", 0 SOAP;
      (f) RF-07: processo no banco + `Queue::fake` → 200, `BaixarProcessoMNIJob` com o par, 0 SOAP;
      (g) SoapFault "Could not connect to host" → 500;
      (h) credencial do tribunal + loginFailed → 500 normalizado.
      Cobre: RF-06, RF-07, RNF-04
      Acceptance criteria: os cenários (a)–(h) existem como `it('<frase pt-BR>')` no arquivo, nenhum corpo verificado tem `codigo`, e todos passam sem alterar `app/` além do que T01/T02 alteraram.
      Testes: `tests/Feature/Api/ConsultarProcessoControllerTest.php` — `docker compose exec -T php php artisan test --compact --filter=ConsultarProcessoControllerTest`
- [ ] T04 — Testes de caracterização de /api/documento/visualizar (RF-05, RF-06, RF-08)
      Arquivos: `tests/Feature/Api/DocumentoControllerTest.php`
      Mudança: testes que passam no código atual, com `simularIntegracaoMni` e processo ligado a tribunal real com senha cifrada (`criarDocumentosLote()` ou factory). Cenários:
      (a) `->assertJsonMissingPath('codigo')` no teste existente :252-273;
      (b) RF-05 sem partialMock de getDocumento: PDF `baixado` no S3 fake e `buildTemporaryUrlsUsing` lançando exceção → 404 "após 3 tentativas", sem `codigo`, 0 SOAP;
      (c) documento ausente + par do cliente + "MNI indisponível" → 500 `{"message":"MNI indisponível"}`;
      (d) par padrão + documento ausente + loginFailed → 500 com a mensagem normalizada;
      (e) par padrão + PDF `pendente` + loginFailed → 500 `{"message":"Erro ao processar documento PDF:"}`;
      (f) par do cliente + PDF `pendente` + "MNI indisponível" → 500 `{"message":"Erro ao processar documento PDF:"}`;
      (g) HTML legado sem `path_html`/`conteudo_html` + par do cliente, com loginFailed ou processo não encontrado → 500 `{"message":"Erro ao processar documento HTML:"}`;
      (h) RF-08: `path_html` para objeto ausente, reconsulta com loginFailed ou processo não encontrado → 200, `documento.link` preenchido, `conteudo_html` null;
      (i) credencial do tribunal + documento ausente + loginFailed → 500 com a mensagem normalizada.
      Cobre: RF-05, RF-06, RF-08, RNF-04
      Acceptance criteria: o teste :252-273 tem `assertJsonMissingPath('codigo')`; os cenários (b)–(i) existem como `it('<frase pt-BR>')` com `assertExactJson` nos corpos 500; tudo passa sem alterar `app/` além do que T01/T02 alteraram.
      Testes: `tests/Feature/Api/DocumentoControllerTest.php` — `docker compose exec -T php php artisan test --compact --filter=DocumentoControllerTest`

## Phase 3: Blocos da classificação

Antes de implementar, leia:
1. `.spec/features/erros-codigo-estavel-visualizar/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/erros-codigo-estavel-visualizar/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T05 — Exceções classificadas da falha MNI
      Arquivos: `app/Exceptions/CredencialPjeRecusadaException.php` (novo), `app/Exceptions/ProcessoNaoEncontradoNoTribunalException.php` (novo), `tests/Unit/Exceptions/ExcecoesClassificadasMniTest.php` (novo)
      Mudança: duas subclasses vazias de `MNIException`, no padrão de `ConsultaMNISemSucessoException`, com docblock pt-BR: levam o sinal do tribunal até a camada HTTP, o texto é padronizado e lido por `getError()`, e elas não escolhem status HTTP. Nenhuma das duas estende `ConsultaMNISemSucessoException`.
      Cobre: RF-01, RF-02, RF-03, RF-04 (suporte), RNF-03
      Acceptance criteria: as duas classes existem, estendem `MNIException` diretamente e não têm métodos nem referências a `response()`/`JsonResponse`/status HTTP; os testes passam.
      Testes: `tests/Unit/Exceptions/ExcecoesClassificadasMniTest.php` — são instanceof MNIException e não são ConsultaMNISemSucessoException; CredencialPjeRecusadaException com o texto loginFailed tem getError padronizado e getCode 500; ProcessoNaoEncontradoNoTribunalException mantém o texto cru
- [ ] T06 — Classificador da falha MNI pelo texto cru
      Arquivos: `app/Services/MNI/ClassificarFalhaMniService.php` (novo), `tests/Unit/Services/MNI/ClassificarFalhaMniServiceTest.php` (novo)
      Mudança: métodos estáticos puros. `credencialRecusada(?string $textoCru): bool` faz `str_contains(mb_strtolower((string) $textoCru), 'loginfailed')`, e só isso (Q-04). `processoNaoEncontrado(?string $mensagemCrua): bool` faz `preg_match` com a constante pública `'/^Processo de número \d+ não encontrado!$/u'` (RF-02). `PadronizarMensagemMniService` não muda.
      Cobre: RF-01, RF-02, RF-03, RF-04
      Acceptance criteria: a classe tem os dois métodos e a constante com o regex exato; `credencialRecusada` não usa `erro ao realizar login via mni`; `PadronizarMensagemMniService.php` fica sem diff; os datasets passam.
      Testes: `tests/Unit/Services/MNI/ClassificarFalhaMniServiceTest.php` — credencialRecusada é verdadeiro para o loginFailed cru, o fault CXF e "LOGINFAILED", e falso para "Erro ao realizar login via MNI.", o texto padronizado, "" e null; processoNaoEncontrado é verdadeiro para os 2 textos capturados e falso para "Processo não encontrado", "...ou sem permissão de acesso", texto sem "!", com prefixo, com espaço inicial, número com máscara e null
- [ ] T07 — Middleware marca se o par é do cliente
      Arquivos: `app/Http/Middleware/InjectCredenciaisPjePadrao.php`, `tests/Unit/InjectCredenciaisPjePadraoTest.php`
      Mudança: criar a constante pública `ATRIBUTO_PAR_DO_CLIENTE = 'credenciais_pje_do_cliente'`. No ramo de par completo (linhas 25-27), `$request->attributes->set(..., true)`; nos demais, `set(..., false)`. `login_pje`/`senha_pje` e o `merge` ficam iguais, e o comentário why explica o motivo em pt-BR (Q-01).
      Cobre: RF-01, RF-03, RF-06 (par padrão)
      Acceptance criteria: a constante existe com o valor `credenciais_pje_do_cliente`; o atributo é `true` só quando os dois campos vêm preenchidos na requisição; o `merge` é idêntico ao de `b9f0028`; os 6 testes existentes passam sem alteração.
      Testes: `tests/Unit/InjectCredenciaisPjePadraoTest.php` — atributo true com par completo (mesmo igual ao padrão); false com par padrão injetado, com par incompleto e sem par padrão

## Phase 4: Services classificam e helper HTTP

Antes de implementar, leia:
1. `.spec/features/erros-codigo-estavel-visualizar/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/erros-codigo-estavel-visualizar/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T08 — ConsultarProcessoService lança as exceções classificadas
      Arquivos: `app/Services/MNI/Intercomunicacao/ConsultarProcessoService.php`, `tests/Unit/Services/MNI/ConsultarProcessoServiceTest.php`
      Mudança: `$credencialInformada = $login_pje !== null && $senha_pje !== null` antes do `try`. No `sucesso=false`, com credencial informada e `credencialRecusada(mensagem)` → `CredencialPjeRecusadaException(mensagem, 500)`; senão, com `processoNaoEncontrado(mensagem)` → `ProcessoNaoEncontradoNoTribunalException(mensagem, 500)`; senão, como hoje. Novo `catch (CredencialPjeRecusadaException|ProcessoNaoEncontradoNoTribunalException $e) { throw $e; }` entre o `catch (DecryptException)` e o `catch (MNIException)`. No `catch (\Exception)`, credencial informada + `credencialRecusada($e->getMessage())` → `CredencialPjeRecusadaException`; senão, como hoje. Sem status HTTP no service (RNF-03).
      Cobre: RF-01, RF-02, RF-06, RNF-02, RNF-03
      Acceptance criteria: a classificação usa `ClassificarFalhaMniService` sobre o texto cru; o 404 só é lançado no ramo `sucesso=false`; a recusa exige credencial informada; os caminhos não classificados lançam a mesma `MNIException` de hoje; não há `response()`/`JsonResponse` no arquivo; 1 `makeSoapRequest` por `execute`.
      Testes: `tests/Unit/Services/MNI/ConsultarProcessoServiceTest.php` — loginFailed com par informado (sucesso=false e fault CXF) → CredencialPjeRecusadaException com getCode 500; loginFailed com credencial do tribunal → MNIException comum; "Erro ao realizar login via MNI." → comum; texto capturado → ProcessoNaoEncontradoNoTribunalException com e sem par; SoapFault com o texto de não encontrado → comum; textos fora do padrão → comum; 1 chamada SOAP
- [ ] T09 — ConsultarDocumentoService::execute lança as exceções classificadas
      Arquivos: `app/Services/MNI/Intercomunicacao/ConsultarDocumentoService.php`, `tests/Unit/Services/MNI/ConsultarDocumentoServiceTest.php`
      Mudança: em `execute`, a mesma regra de T08: `$credencialInformada`, classificação no `sucesso=false` (linhas 61-62), `catch` das classificadas relançando antes do `catch (MNIException)` (linhas 64-65) e recusa por loginfailed no `catch (\Exception)` (linhas 66-67), só com credencial informada. `buscar()` (lote) não muda. Sem status HTTP no service.
      Cobre: RF-03(b), RF-04(b), RNF-02, RNF-03
      Acceptance criteria: `execute` lança as classificadas pelas regras de T08 e o restante como hoje; `buscar()` fica sem diff; não há `response()`/`JsonResponse` no arquivo; os testes novos e os existentes de `buscar` passam.
      Testes: `tests/Unit/Services/MNI/ConsultarDocumentoServiceTest.php` — execute com loginFailed e par informado (sucesso=false e SoapFault) → CredencialPjeRecusadaException; com credencial do tribunal → MNIException comum; texto capturado → ProcessoNaoEncontradoNoTribunalException; fora do padrão → comum; buscar continua lançando ConsultaMNISemSucessoException para loginFailed e para não encontrado
- [ ] T10 — Helper HTTP ErroComCodigo
      Arquivos: `app/Http/Responses/ErroComCodigo.php` (novo), `tests/Unit/Http/ErroComCodigoTest.php` (novo)
      Mudança: classe `final` com as constantes dos códigos e dos literais (Q-10). `classificar(\Throwable $e, bool $parDoCliente)` percorre `$e` e os `getPrevious()` e devolve a primeira `ProcessoNaoEncontradoNoTribunalException`, ou a primeira `CredencialPjeRecusadaException` quando `$parDoCliente`; senão, `null`. `resposta($classificada): JsonResponse` monta 403 `{"codigo":"credencial_pje_recusada","message":"Credencial PJe recusada pelo tribunal"}` ou 404 `{"codigo":"processo_nao_encontrado","message":"Processo não encontrado no tribunal"}`, sem usar `getError()` nem o texto do tribunal.
      Cobre: RF-01, RF-02, RF-03, RF-04, CT-01, CT-02, CT-03, RNF-01, RNF-03
      Acceptance criteria: o arquivo fica em `app/Http/Responses/`; `resposta` devolve exatamente as duas chaves com os literais fixos e status 403/404; `classificar` ignora a recusa quando `$parDoCliente` é falso e percorre a cadeia `previous`; os testes passam.
      Testes: `tests/Unit/Http/ErroComCodigoTest.php` — corpos e status exatos; recusa só com par do cliente; "não encontrado" com par do cliente ou não; acha a classificada como previous de `\Exception`; null para MNIException comum e RuntimeException; o corpo não contém loginFailed, "Processo de número", login_pje=, senha_pje=, http:// nem https://

## Phase 5: Download preserva o sinal

Antes de implementar, leia:
1. `.spec/features/erros-codigo-estavel-visualizar/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/erros-codigo-estavel-visualizar/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T11 — O download preserva o sinal do tribunal
      Arquivos: `app/Services/Processo/SalvarDocumentoProcessoService.php`, `tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php`
      Mudança: em `consultarDocumento`, novo `catch` das classificadas antes do `catch (MNIException)`: mesmo `Log::error` e `throw $e`. Os wrappers `downloadHTML` (:232), `downloadPDF` (:321), `downloadOutros` (:353) e `downloadAudio` (:719) mantêm a mensagem idêntica e passam `$e` como `previous` (`new \Exception('Erro ao processar documento PDF: ' . $e->getMessage(), 0, $e)`). Em `baixarDocumento` (:110-111), a reconsulta de HTML legado ganha `try/catch (\Exception $e) { throw new \Exception($e->getMessage()); }`, que corta a cadeia, com comentário why pt-BR (Q-02). `downloadMP4`/`downloadQuicktime` e `salvarConteudo*` não mudam, e o service não usa status HTTP.
      Cobre: RF-03(b), RF-04(b), RF-06, RF-08
      Acceptance criteria: as mensagens dos 4 wrappers são idênticas às de `b9f0028` e todos passam `previous`; `consultarDocumento` relança as classificadas sem `new MNIException`; a reconsulta de HTML legado relança sem `previous`; vídeo fica sem diff; não há `response()`/`JsonResponse` no arquivo; os testes existentes :134-149 e :357-382 passam.
      Testes: `tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php` — dataset dos 4 wrappers com consultarDocumento lançando a classificada → mesma mensagem com getPrevious classificada; consultarDocumento com `simularIntegracaoMni` e o texto de não encontrado → ProcessoNaoEncontradoNoTribunalException; baixarDocumento de HTML legado → mesma mensagem com getPrevious null

## Phase 6: Mapeamento HTTP nos controllers

Antes de implementar, leia:
1. `.spec/features/erros-codigo-estavel-visualizar/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/erros-codigo-estavel-visualizar/PLAN.md` — decomposição completa, dependências e riscos
3. `/Users/brunosantos/Projetos/sistema-juridico-lorran/.spec/features/consulta-processual-mni-credenciais-pje/.handoff/ms-mni-openapi.yaml` — cópia acordada (só leitura): respostas DEP-01 `403`/`404` e `ErroComCodigo` que os controllers precisam produzir

- [ ] T12 — /api/processo/visualizar responde 403/404 com codigo
      Arquivos: `app/Http/Controllers/Api/ConsultarProcessoController.php`, `tests/Feature/Api/ConsultarProcessoControllerTest.php`
      Mudança: só em `show` (`index` fica fora). No `catch (MNIException $e)`, antes da resposta atual, `$classificada = ErroComCodigo::classificar($e, (bool) $request->attributes->get(InjectCredenciaisPjePadrao::ATRIBUTO_PAR_DO_CLIENTE, false))`; se houver, `return ErroComCodigo::resposta($classificada)`. As demais respostas ficam idênticas.
      Cobre: RF-01, RF-02, RF-06, RF-07, CT-01, RNF-01, RNF-02, RNF-03, RNF-04
      Acceptance criteria: só `show` muda, `index` fica sem diff; par do cliente + loginFailed → 403 exato e o sinal capturado → 404 exato, sem `error`/`line`/`file`; os testes de T03 passam sem alteração.
      Testes: `tests/Feature/Api/ConsultarProcessoControllerTest.php` — par do cliente distintivo + `sucesso=false` loginFailed → 403 `assertExactJson`, 1 SOAP; fault CXF loginFailed → 403; "Processo de número 99999901120268030001 não encontrado!" → 404 exato com par do cliente, par padrão e credencial do tribunal; RNF-01: corpo sem os valores de login/senha, login_pje=, senha_pje=, http:// ou https://
- [ ] T13 — /api/documento/visualizar responde 403/404 com codigo
      Arquivos: `app/Http/Controllers/Api/DocumentoController.php`, `tests/Feature/Api/DocumentoControllerTest.php`
      Mudança: `show` calcula `$parDoCliente` pelo atributo do middleware e o repassa a `getDocumento`, que ganha o último parâmetro opcional `bool $parDoCliente = false`. Novo `catch (CredencialPjeRecusadaException|ProcessoNaoEncontradoNoTribunalException $e)` antes do `catch (MNIException)`: `Log::warning` sem credenciais e `ErroComCodigo::resposta($e)`. Nos dois `catch` de `getDocumento`, depois do `Log::error`, a classificada é relançada quando `ErroComCodigo::classificar($e, $parDoCliente)` a encontrar; senão, o reembrulho fica idêntico ao de hoje. `anexarConteudoHtml` e o 404 de 3 tentativas não mudam.
      Cobre: RF-03, RF-04, RF-05, RF-06, RF-08, CT-02, RNF-01, RNF-02, RNF-03, RNF-04
      Acceptance criteria: 403/404 exatos nos caminhos (a) consulta do processo e (b) download PDF/HTML, com a exceção encerrando o laço sem novas tentativas; fora desses casos, o reembrulho de `getDocumento` é idêntico ao de `b9f0028`; os testes de T04 passam sem alteração.
      Testes: `tests/Feature/Api/DocumentoControllerTest.php` — documento ausente + loginFailed → 403, 1 SOAP; PDF pendente + loginFailed → 403, 1 chamada com `documento`; HTML pendente → 403; documento ausente + não encontrado → 404, 1 SOAP; PDF pendente + não encontrado → 404, 1 chamada com `documento`; RNF-01 em todos os corpos com `codigo`

## Phase 7: Contrato e documentação

Antes de implementar, leia:
1. `.spec/features/erros-codigo-estavel-visualizar/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/erros-codigo-estavel-visualizar/PLAN.md` — decomposição completa, dependências e riscos
3. `/Users/brunosantos/Projetos/sistema-juridico-lorran/.spec/features/consulta-processual-mni-credenciais-pje/.handoff/ms-mni-openapi.yaml` — cópia acordada (só leitura): nós DEP-01 nas linhas 137-158, 323-358 e 707-719

- [ ] T14 — Contrato oficial com os 5 nós DEP-01
      Arquivos: `docs/api/openapi.yaml`, `tests/Unit/OpenApiContratoDep01Test.php` (novo)
      Mudança: copiar literalmente da cópia acordada: `403`/`404` de `/processo/visualizar` (cópia :137-158, entre `401` e `422`); `403` de `/documento/visualizar` (:323-333, entre `401` e `404`); substituir o `404` atual de `/documento/visualizar` (oficial :287-292) pelo `oneOf` da cópia (:334-358); `components.schemas.ErroComCodigo` (:707-719, depois de `ErroMensagem`). Manter `x-dependencia: DEP-01`. Não copiar o bloco "Mudanças acordadas (DEP-01 e DEP-02)" do `info.description` nem nada de DEP-02, e não remover itens que só existem no oficial.
      Cobre: RF-09, CT-01, CT-02, CT-03, RNF-05
      Acceptance criteria: o teste de contrato passa (0 diferenças nos 5 nós, ignorando description/example/examples/summary e incluindo `x-dependencia`); `Tribunal.properties` sem `sigla`/`grau`; `PaginacaoProcessos` com os 7 campos; `DocsApiTest` verde; `npx @redocly/cli lint` sem erro que não exista no lint de `b9f0028`.
      Testes: `tests/Unit/OpenApiContratoDep01Test.php` — parseFile com symfony/yaml; `openapi` = 3.1.0; os 5 nós iguais à estrutura esperada embutida; Tribunal sem sigla/grau; PaginacaoProcessos com first_page_url, from, last_page_url, next_page_url, path, prev_page_url e to
- [ ] T15 — docs/agents descrevem as novas respostas
      Arquivos: `docs/agents/api_contracts.md`, `docs/agents/domain_rules.md`
      Mudança: em `api_contracts.md`, a coluna Errors das linhas `/api/processo/visualizar` e `/api/documento/visualizar` passa a listar 403 e 404 (com e sem `codigo` no documento); "Common error shapes" ganha os exemplos `ErroComCodigo` 403 e 404 (só nesses endpoints; 403 só para o par do cliente); "Documentos" passa a ter os dois 404. Em `domain_rules.md`, "Document visualization" (ou a seção "Erros com código estável (DEP-01)") registra o mapeamento recusa do par do cliente (`loginfailed`) → 403 `credencial_pje_recusada`, o `sucesso=false` com `Processo de número <dígitos> não encontrado!` → 404 `processo_nao_encontrado` e as exclusões; "MNI message standardization" passa a citar que `loginfailed` também decide o 403.
      Cobre: RF-10
      Acceptance criteria: as linhas da tabela dos dois endpoints em `api_contracts.md` listam 403 e 404; a seção "Documentos" descreve os 404 com e sem `codigo`; `domain_rules.md` registra os dois mapeamentos para os dois endpoints.
      Testes: revisão manual de RF-10 (só documentação)

## Phase 8: Verificação final e relatório

Antes de implementar, leia:
1. `.spec/features/erros-codigo-estavel-visualizar/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/erros-codigo-estavel-visualizar/PLAN.md` — decomposição completa, dependências e riscos
3. `/Users/brunosantos/Projetos/sistema-juridico-lorran/.spec/features/consulta-processual-mni-credenciais-pje/.handoff/ms-mni-openapi.yaml` — cópia acordada (só leitura) para a comparação estrutural

- [ ] T16 — Verificação final e relatório de entrega
      Arquivos: `.spec/features/erros-codigo-estavel-visualizar/RELATORIO.md` (novo; único arquivo escrito)
      Mudança:
      (1) `docker compose exec -T php php artisan test --compact` com 0 falhas;
      (2) RNF-03: `git diff b9f0028 -- app/Services | grep -E '^\+.*(response\(|JsonResponse)'` sem nenhuma linha;
      (3) comparação estrutural do oficial com a cópia acordada por um script PHP descartável (symfony/yaml), rodado no serviço `php` com os arquivos copiados para /tmp por `docker compose cp` e nada gravado no repo; o script ignora description/example/examples/summary e lista todos os caminhos divergentes;
      (4) registrar o resultado do redocly lint de T14;
      (5) escrever RELATORIO.md em pt-BR com: (i) DEP-01 com 0 diferenças e o método; (ii) as divergências fora de DEP-01 (Tribunal.sigla/grau DEP-02 só na cópia; os 7 campos de PaginacaoProcessos, `WebhookMonitoramentoPayload.movimentos/documentos.items.properties` e os exemplos dos webhooks só no oficial; o bloco "Mudanças acordadas" do `info.description` da cópia), com a recomendação de o cliente trocar as cópias; (iii) as limitações conhecidas: vídeo fora de RF-03/RF-04 com os 2 bugs de `scripts/mni_consultar_documento.sh` (curl sem `--fail` → falso 200 com XML salvo como .mp4; URL fixa do TJAP 1º grau na linha 20), LIM-01, recusa do par padrão ou do tribunal → 500, HTML legado → 500, sigilo ou texto fora do padrão → 500, regex calibrado só no TJAP; (iv) os resultados de (1), (2) e (4).
      Cobre: RF-09 (comparação com a cópia), RNF-03, RNF-04, RNF-05
      Acceptance criteria: a suíte completa termina com 0 falhas; o grep de RNF-03 devolve 0 linhas; RELATORIO.md existe e contém as seções (i)–(iv) com 0 diferenças nos 5 nós DEP-01, a lista de divergências fora de DEP-01 e as limitações de vídeo com os 2 bugs do script; só RELATORIO.md é escrito nesta task.
      Testes: `docker compose exec -T php php artisan test --compact` — suíte completa
