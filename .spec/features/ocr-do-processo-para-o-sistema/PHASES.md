# Phases: ocr-do-processo-para-o-sistema

Gerado por /plan a partir de PLAN.md (SPEC v1.7): view executável para `./ralph.sh .spec/features/ocr-do-processo-para-o-sistema/PHASES.md`.

## Phase 1: Correção da recusa do par (RF-01)

Antes de implementar, leia:
1. `.spec/features/ocr-do-processo-para-o-sistema/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/ocr-do-processo-para-o-sistema/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T01 — Matriz da recusa do par nos caminhos já classificados (RF-01)
      Arquivos: `tests/Feature/Api/RecusaDoParVisualizarTest.php` (novo). Se algum caso falhar, a correção entra aqui, no ponto da falha: `ConsultarProcessoService.php:66-67`, `ConsultarDocumentoService.php:79-80`, `IntegracaoBase.php:46-60` ou `ProcessoService.php:26-65`.
      Mudança: teste Pest com `simularIntegracaoMni`. Dataset das 3 formas cruas da recusa: `sucesso=false` com "Erro ao realizar login via MNI. exception invoking: loginFailed"; `Exception` com esse texto, como o recibo de `IntegracaoBase.php:53-57`; `SoapFault` com esse texto. 4 caminhos: `/processo/visualizar` com o processo ausente; `/documento/visualizar` com o documento ausente do banco, PDF pendente e HTML pendente. Casos de controle com o par padrão: 500 sem `codigo`. O HTML legado fica com T02.
      Cobre: RF-01 (AC a, b, c), CT-06
      Acceptance criteria: o dataset tem 3 formas × 4 caminhos (12 casos 403 com `assertExactJson` do corpo `credencial_pje_recusada`) e os controles do par padrão (500 sem `codigo`). Tudo passa. Cada 403 tem 1 requisição SOAP com o par do cliente e um corpo sem `error`, `line`, `file`, login, senha e texto do tribunal. Uma correção em `app/`, se houver, fica num dos pontos listados, sem mudar nenhum teste DEP-01.
      Testes: `tests/Feature/Api/RecusaDoParVisualizarTest.php` — `docker compose exec -T php php artisan test --compact --filter=RecusaDoParVisualizarTest`
- [ ] T02 — HTML legado reconsulta com o par da requisição (RF-01, NC-2)
      Arquivos: `app/Services/Processo/SalvarDocumentoProcessoService.php` (l. 111-122), `tests/Feature/Api/DocumentoControllerTest.php` (substitui :449-472), `tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php` (substitui :513-534 e :536-558)
      Mudança: no ramo do `text/html` `baixado` sem `path_html`/`conteudo_html`, chamar `$this->downloadHTML($documento, $login_pje, $senha_pje)` e remover o `try/catch` que corta a cadeia. O `downloadHTML` já embrulha com `previous`. Comentário pt-BR do porquê. `DocumentoController` não muda.
      Cobre: RF-01 (AC a, b, c, e no HTML legado), CT-06, RNF-05
      Acceptance criteria: o ramo chama `downloadHTML` com o par e não tem mais o `catch` que corta a cadeia. Com o par do cliente, nas 3 formas: 403 com o corpo exato e 1 SOAP com `idConsultante`/`senhaConsultante` do cliente. "Processo não encontrado" → 404 `processo_nao_encontrado`. Sem o par e com o par padrão: o SOAP sai com o par padrão, e a recusa → 500 `{"message":"Erro ao processar documento HTML:"}` sem `codigo`. Sem par nenhum: o SOAP sai com `login-do-tribunal`. Os 3 testes antigos foram substituídos.
      Testes: `tests/Feature/Api/DocumentoControllerTest.php` — HTML legado com par do cliente recusado (403), não encontrado (404), par padrão recusado (500), sem par (credencial do tribunal); `tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php` — repassa o par e mantém a exceção classificada na cadeia
- [ ] T03 — Documentação da correção (RF-01)
      Arquivos: `docs/agents/domain_rules.md` (l. 184 e 188), `docs/agents/api_contracts.md` (l. 141)
      Mudança: remover a exclusão do HTML legado (l. 188). Em "Covered MNI calls", incluir a reconsulta do HTML legado com o par da requisição: cliente → 403; par padrão → 500; sem par → credencial do tribunal. Em `api_contracts.md`, o 403 de `/documento/visualizar` passa a valer também no HTML legado.
      Cobre: RF-01, RNF-06
      Acceptance criteria: o item da exclusão do HTML legado não existe mais em `domain_rules.md`. "Covered MNI calls" cita o HTML legado com o par da requisição. `api_contracts.md` cita o HTML legado no 403.
      Testes: revisão (só documentação)

## Phase 2: Fundações (cifra, callback, configuração, tabelas, Horizon e parâmetros sensíveis)

Antes de implementar, leia:
1. `.spec/features/ocr-do-processo-para-o-sistema/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/ocr-do-processo-para-o-sistema/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T04 — Cifra dos 6 jobs que já levam o par (RNF-01, NC-5)
      Arquivos: `app/Jobs/BaixarDocumentoMNIJob.php`, `app/Jobs/BaixarDocumentosMNIEmLoteJob.php`, `app/Jobs/BaixarProcessoMNIJob.php`, `app/Jobs/ConsultarDadosBasicosProcessoMNIJob.php`, `app/Jobs/ConsultarDocumentosProcessoMNIJob.php`, `app/Jobs/ConsultarMovimentosProcessoMNIJob.php`, `tests/Feature/Jobs/JobsComParCifradosTest.php` (novo)
      Mudança: `implements ShouldBeEncrypted` nos 6 jobs, mantendo `ShouldBeUnique` onde existe. `#[\SensitiveParameter]` no login e na senha dos construtores. Comentário pt-BR do porquê. Fila, `$tries`, `uniqueId()` e `handle()` iguais.
      Cobre: RNF-01 (escopo NC-5)
      Acceptance criteria: os 6 jobs implementam `ShouldBeEncrypted`. Para cada job, com `queue.default` = `database`: `jobs.payload` não tem `login-cifra-7a1` nem `senha-cifra-9b2`; `data.command` decifrado desserializa com o par; executado com `simularIntegracaoMni` (e `Http::fake` no callback), o job chama o MNI com o mesmo par. Um payload antigo sem cifra (`O:`) continua processável. Os testes atuais que despacham esses jobs passam sem mudar asserção.
      Testes: `tests/Feature/Jobs/JobsComParCifradosTest.php`
- [ ] T05 — Hosts liberados no CallbackUrlValidator fora de produção (RF-17)
      Arquivos: `app/Services/Callback/CallbackUrlValidator.php`, `config/pje.php` (`callback.hosts_liberados`), `tests/Unit/Services/Callback/CallbackUrlValidatorTest.php`, `tests/Feature/Api/CallbackHostsLiberadosTest.php` (novo)
      Mudança: `config/pje.php` lê `CALLBACK_HOSTS_LIBERADOS` (separado por vírgula, com `trim`, sem vazios, lista vazia por padrão). Em `assertValida`: com `config('app.env')` ≠ `production`, host na lista (sem diferenciar maiúsculas, qualquer porta e caminho) e esquema `http`/`https` → válido, mesmo com IP interno. Nos demais casos, valem as regras de hoje. Comentário pt-BR: vale para todo `callback_url`.
      Cobre: RF-17 (AC b, c no validador e numa rota existente), D-03
      Acceptance criteria: `local` com a lista `host.docker.internal` → `http://HOST.docker.internal:8006/x?y=1` válida. `production` com a mesma lista → inválida. Lista vazia ou outro host → inválida. `POST /api/processo/monitoramentos` com esse `callback_url` → 201 em `local` com a lista e 422 em `production`, com a mensagem de `CallbackUrl.php:14`. Os 5 testes atuais do validador ficam iguais.
      Testes: `tests/Unit/Services/Callback/CallbackUrlValidatorTest.php`; `tests/Feature/Api/CallbackHostsLiberadosTest.php`
- [ ] T06 — Configuração do OCR e chaves do .env.example
      Arquivos: `config/services.php`, `.env.example`, `tests/Unit/ConfigOcrTest.php` (novo)
      Mudança: bloco `n8n_ocr` com `url`, `token`, `base_conhecimento`, `webhook_base_url` (padrão `APP_URL`), `prazo_sem_progresso_minutos` (P) 25, `idade_maxima_sem_retorno_minutos` (A) 20, `idade_maxima_absoluta_minutos` (M) 60 (também a validade da credencial do `webhook_url`), `espera_da_trava_segundos` (W) 45, `trava_validade_segundos` 600 e `timeout_http_segundos` 30. `.env.example` ganha `N8N_OCR_*` sem valores e `CALLBACK_HOSTS_LIBERADOS=`, com comentário pt-BR.
      Cobre: RF-18 (AC i), RF-11, RF-06 (W), RF-13 (P)
      Acceptance criteria: as 10 chaves existem com os padrões. O teste confere A = 20, P = 25, M = 60, A < P, A < M, W = 45 e W < 60. `.env.example` tem as chaves novas sem valor. Nenhum `env()` fora de `config/`.
      Testes: `tests/Unit/ConfigOcrTest.php`
- [ ] T07 — Tabelas e colunas do OCR
      Arquivos: `database/migrations/2026_11_06_000001_create_processo_ocr_tables.php` (novo), `app/Models/ProcessoOcrProcessamento.php`, `app/Models/ProcessoOcrAssinante.php`, `app/Models/ProcessoOcrAviso.php` (novos), `app/Models/ProcessoDocumento.php`, `tests/Feature/Migrations/OcrTabelasTest.php`, `tests/Unit/Models/ProcessoOcrModelsTest.php` (novos)
      Mudança: tabelas `processo_ocr_processamentos` (`situacao`, `codigo_falha`, `aberto_em`, `ultimo_progresso_em`, `finalizado_em`, índice único parcial em `processo_id` com `situacao = 'em_andamento'`), `processo_ocr_assinantes` (`dono`, `callback_url` 2048, `callback_token` cast `encrypted`, `callback_hash`, `encerrado_em`, `aviso_final_entregue_em`, unique por processamento + hash) e `processo_ocr_avisos` (`uuid`, `final`, `payload` json, `enviado_em`, `tentativas`, `status_http`). Em `processo_documentos`: `ocr_situacao`, `ocr_job_id` (index), `ocr_job_id_cancelar`, `ocr_enviado_em`, `ocr_concluido_em`, `ocr_erro`, mais um index (`processo_id`, `ocr_situacao`). Constantes `OCR_*` e `SITUACAO_*`. As 6 colunas ficam em `$hidden` de `ProcessoDocumento`. Sem cast em `data_juntada`.
      Cobre: RF-06, RF-08, RF-12, RF-18, CT-06, RNF-01
      Acceptance criteria: `migrate` roda e `migrate:rollback --step=1` desfaz. Um 2º processamento `em_andamento` do mesmo processo lança `UniqueConstraintViolationException`, e um `concluido` não colide. O `callback_token` cru no banco difere do texto. `ProcessoDocumento::toArray()` não tem chaves `ocr_*`. Nenhuma coluna de login ou senha nas tabelas novas.
      Testes: `tests/Feature/Migrations/OcrTabelasTest.php`; `tests/Unit/Models/ProcessoOcrModelsTest.php`
- [ ] T08 — Supervisores do Horizon para as filas do OCR (RNF-02)
      Arquivos: `config/horizon.php`, `tests/Unit/HorizonConfigOcrTest.php` (novo)
      Mudança: `supervisor-ocr-download` (`ocr-download`, `balance` `auto`, tries 3, timeout 300), `supervisor-ocr-envio` (`ocr-envio`, tries 5, timeout 60) e `supervisor-ocr-aviso` (`ocr-aviso`, tries 5, timeout 60) em `defaults`. `maxProcesses`: `production` 12 / 8 / 2; `local` 2 / 2 / 1; `*` 2 / 2 / 1. `waits` para `ocr-download` e `ocr-envio`. Comentário pt-BR da soma 20 e de que `ocr-envio` também atende a consulta antes do reenvio.
      Cobre: RNF-02 (parte Pest)
      Acceptance criteria: em `production`, `ocr-download` + `ocr-envio` = 20 `maxProcesses`. Os 3 supervisores existem em `production`, `local` e `*`. `supervisor-monitoramento` = 1 em todos. `tests/Unit/HorizonConfigTest.php` passa sem mudança.
      Testes: `tests/Unit/HorizonConfigOcrTest.php`; `tests/Unit/HorizonConfigTest.php`
- [ ] T09 — Par marcado como sensível na cadeia MNI (RNF-01)
      Arquivos: `app/Services/IntegracaoBase.php`, `app/Services/MNI/Intercomunicacao/ConsultarProcessoService.php`, `app/Services/MNI/Intercomunicacao/ConsultarDocumentoService.php`, `app/Services/Processo/SalvarDocumentoProcessoService.php`, `app/Services/Processo/ProcessoService.php`, `app/Services/Processo/EnfileirarDownloadDocumentosService.php`, `tests/Unit/Services/MNI/ParSensivelNoTraceTest.php` (novo)
      Mudança: só `#[\SensitiveParameter]` nos parâmetros com login, senha ou o array SOAP (lista de métodos no PLAN, T09). Comentário pt-BR em `IntegracaoBase` sobre `zend.exception_ignore_args`.
      Cobre: RNF-01 (texto de exceção e trace)
      Acceptance criteria: com `zend.exception_ignore_args = 0` e `SoapFault` simulado, o `getTraceAsString()` de toda exceção da cadeia de `ProcessoService::consultarNumero` e de `baixarDocumento` de um PDF pendente não tem `login-trace-4c2` nem `senha-trace-8d6`. Por reflection, os parâmetros listados têm o atributo. A suíte não regride.
      Testes: `tests/Unit/Services/MNI/ParSensivelNoTraceTest.php`

## Phase 3: Serviços base do OCR

Antes de implementar, leia:
1. `.spec/features/ocr-do-processo-para-o-sistema/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/ocr-do-processo-para-o-sistema/PLAN.md` — decomposição completa, dependências e riscos
3. `.spec/features/ocr-do-processo-para-o-sistema/workflow-ocr.openapi.yaml` — contrato do workflow (T11)
4. `.spec/features/ocr-do-processo-para-o-sistema/openapi.yaml` — schema `AvisoDeProgressoDoOcr` (T13)

- [ ] T10 — Formatos legíveis e contagens (RF-08)
      Arquivos: `app/Services/Ocr/FormatosOcr.php`, `app/Services/Ocr/ContagemOcr.php`, `app/Services/Ocr/ContagemOcrService.php` (novos), `tests/Unit/Services/Ocr/FormatosOcrTest.php`, `tests/Unit/Services/Ocr/ContagemOcrServiceTest.php` (novos)
      Mudança: `FormatosOcr` é puro: os 8 mimetypes legíveis, `ehLegivel` e `extensaoDoFilename` (pdf/html → `pdf`, png, jpeg → `jpg`, webp, tiff → `tif`, bmp, gif). `ContagemOcrService::contar($processoId)` faz uma consulta com agregados condicionais: `total`, `processados`, `comErro`, `ignorados`, `pendentes` (`ocr_situacao` nulo ou `pendente`) e `aguardando` (`enviado`). `paraCorpo()` devolve as 4 chaves do contrato.
      Cobre: RF-08, RF-04 (classificação), AC10
      Acceptance criteria: 3 PDF, 1 HTML, 1 PNG, 1 MP4 e 1 MP3 → `total` 5 e `ignorados` 2. `ocr_situacao` nulo conta como pendente. Em todo cenário, `processados + com_erro ≤ total` e `total + ignorados` = documentos do processo. As 6 imagens têm a extensão de RF-11.
      Testes: `tests/Unit/Services/Ocr/FormatosOcrTest.php`; `tests/Unit/Services/Ocr/ContagemOcrServiceTest.php`
- [ ] T11 — Cliente HTTP do workflow (CT-03, CT-05, CT-07)
      Arquivos: `app/Services/Ocr/ClienteWorkflowOcr.php`, `app/Services/Ocr/ResultadoEnvioOcr.php`, `app/Services/Ocr/SituacaoJobOcr.php` (enum), `app/Exceptions/FalhaTransitoriaWorkflowOcrException.php` (novos), `tests/Unit/Services/Ocr/ClienteWorkflowOcrTest.php` (novo)
      Mudança: `Http::withToken(token)->acceptJson()->timeout(...)` sobre `services.n8n_ocr.url`. `enviarDocumento` (`POST documents/process`): 202 com `id` → aceito; 422 → recusado com `detail`; 5xx, tempo esgotado, rede ou 202 sem `id` → exceção transitória. `consultarJob` (`GET jobs/{id}`) devolve o enum `SituacaoJobOcr`: `pending` → `Pendente`, `running` → `EmExecucao`, `success` → `Sucesso`, `failed` → `Falhou`, `cancelled` → `Cancelado`, 404 → `Inexistente`. `status` fora do enum, outras respostas, tempo esgotado ou rede → exceção transitória. `cancelarJob` (`DELETE jobs/{id}/chunks`): 200 → ok; o resto → exceção. Sem log do corpo. O cliente só traduz; a decisão fica com T16 e T18.
      Cobre: CT-03, CT-05, CT-07; RF-11, RF-13, RF-18
      Acceptance criteria: com `Http::fake`, os 3 métodos mandam `Authorization: Bearer <token>` para as URLs certas. Envio: 202 → aceito com `id`; 422 → recusado; 500 e `ConnectionException` → exceção. Consulta: `pending`, `running`, `success`, `failed` e `cancelled` → o caso respectivo; 404 → `Inexistente`; `status` desconhecido, 503 e `ConnectionException` → exceção. Cancelamento: 200 → ok; 500 → exceção. As formas batem com `workflow-ocr.openapi.yaml`.
      Testes: `tests/Unit/Services/Ocr/ClienteWorkflowOcrTest.php`
- [ ] T12 — Listagem completa do processo, sem recorte e sem download (RF-04 item 1)
      Arquivos: `app/Services/Processo/ProcessoService.php` (método novo), `tests/Unit/Services/Processo/ProcessoServiceListaCompletaTest.php` (novo)
      Mudança: `consultarListaCompletaParaOcr($tribunal, $numero, #[\SensitiveParameter] $login, #[\SensitiveParameter] $senha)` chama `execute(..., true, true, true, null)`, com `dataReferencia` nulo, e grava como `consultarNumero`, mas com `baixar_binarios = false`. As exceções classificadas sobem sem embrulho. `consultarNumero` não muda.
      Cobre: RF-04 (item 1, AC b, c), RF-02
      Acceptance criteria: a requisição sai com `dataReferencia` nulo e `incluirDocumentos` = `true`, para a URL do tribunal informado (0 para outra linha da mesma `sigla`). Um documento de 120 dias atrás é gravado. Há 0 `BaixarDocumentosMNIEmLoteJob` e 0 `BaixarDocumentoMNIJob`. Recusa → `CredencialPjeRecusadaException`; "não encontrado" → `ProcessoNaoEncontradoNoTribunalException`. Os testes de `consultarNumero` não mudam.
      Testes: `tests/Unit/Services/Processo/ProcessoServiceListaCompletaTest.php`
- [ ] T13 — Avisos ocr_progresso: geração e entrega (RF-14, RF-15, RF-16, CT-02)
      Arquivos: `app/Services/Ocr/AvisarAssinantesOcrService.php`, `app/Jobs/EnviarAvisoOcrJob.php` (novos), `tests/Unit/Services/Ocr/AvisarAssinantesOcrServiceTest.php`, `tests/Feature/Jobs/EnviarAvisoOcrJobTest.php` (novos)
      Mudança: `avisarProgresso($p, array $contagens)` e `avisarFinal($p, $contagens, $situacao, ?$codigoRecusa)` criam um `ProcessoOcrAviso` por assinante ativo, com o payload exato de `AvisoDeProgressoDoOcr`. Com recusa, o dono recebe `credencial_pje_recusada` e cada outro `credencial_de_outro_pedido_recusada`. O despacho é `afterCommit` na fila `ocr-aviso`. `EnviarAvisoOcrJob`: `$tries` 5, `backoff()` `[10, 60, 300, 900, 3600]`, `$timeout` 30, `lockForUpdate`. Sai sem enviar se o aviso já foi enviado, se o assinante está encerrado ou se o aviso não é final e já há final entregue (Q-03). Envia com `CallbackNotifier`, `X-Evento: processo.ocr.progresso` e `X-Idempotency-Key`. 2xx → `enviado_em`; no final, `aviso_final_entregue_em`. 4xx ou URL inválida → assinante encerrado e `fail()`. Outras falhas relançam.
      Cobre: RF-14, RF-15 (Q-03), RF-16, CT-02
      Acceptance criteria: com 2 assinantes, a URL é byte a byte o `callback_url`, com a query, e o `X-API-Token` é o do assinante. O corpo tem exatamente 7 chaves, e `codigo` só no final `falhou` com recusa, por papel. 410 no 2º aviso → assinante encerrado, com 0 requisições depois, e o outro recebe todas. `backoff()` e `$tries` = 5 conferidos. Final entregue → a nova tentativa pendente sai com 0 requisições. Aviso já enviado → 0 requisições.
      Testes: `tests/Unit/Services/Ocr/AvisarAssinantesOcrServiceTest.php`; `tests/Feature/Jobs/EnviarAvisoOcrJobTest.php`

## Phase 4: Máquina de estados do processamento

Antes de implementar, leia:
1. `.spec/features/ocr-do-processo-para-o-sistema/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/ocr-do-processo-para-o-sistema/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T14 — Máquina de estados do processamento (RF-08, RF-10, RF-12, RF-15, RF-18 item 2)
      Arquivos: `app/Services/Ocr/ProcessamentoOcrService.php`, `app/Services/Ocr/ResultadoRetornoOcr.php` (novos), `tests/Unit/Services/Ocr/ProcessamentoOcrServiceTest.php` (novo)
      Mudança: em `DB::transaction`, trava primeiro o processamento aberto e depois o documento (D6). Métodos:
        `registrarEnvio`: `enviado`, `ocr_job_id` e `ocr_enviado_em`; não é progresso.
        `aplicarRetorno`: `success` vence `erro`; `error` nunca rebaixa `processado`; repetido → `Repetido`.
        `registrarErroDefinitivo`: `ocr_erro` resumido, sem credencial.
        `registrarProgresso`: sem processamento aberto, 0 avisos. Com processamento aberto: `ultimo_progresso_em`, `avisarProgresso` e, com `pendentes` e `aguardando` = 0, final `concluido`.
        `encerrarPorRecusa`: idempotente; `falhou` com `credencial_pje_recusada` e final por papel.
        `encerrarPorPrazo`: `falhou` sem `codigo`.
        `devolverParaFila`: `ocr_job_id` → `ocr_job_id_cancelar`.
      Cobre: RF-08, RF-10 (itens 1 e 3), RF-12, RF-14, RF-15, RF-18 (item 2)
      Acceptance criteria: `success` sobre `erro` → `processados` + 1 e `com_erro` − 1, com 1 aviso por assinante com processamento aberto e 0 sem. `error` sobre `processado` → sem mudança e 0 avisos. Repetido → 0 avisos. 3 processados + 1 erro → 1 final `concluido` `{"total": 4, "processados": 3, "com_erro": 1}`. `encerrarPorRecusa` deixa os pendentes pendentes, não aumenta `com_erro`, dá o final por papel e, chamado 2 vezes, gera 1 final. Retorno depois do final → 0 avisos. Retorno do processamento 1 com o 2 aberto → aviso só aos assinantes do 2. `devolverParaFila` move o `ocr_job_id`.
      Testes: `tests/Unit/Services/Ocr/ProcessamentoOcrServiceTest.php`

## Phase 5: Webhook do workflow

Antes de implementar, leia:
1. `.spec/features/ocr-do-processo-para-o-sistema/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/ocr-do-processo-para-o-sistema/PLAN.md` — decomposição completa, dependências e riscos
3. `.spec/features/ocr-do-processo-para-o-sistema/openapi.yaml` — nó `/ocr/webhook` (CT-04, D-01)

- [ ] T15 — Webhook do workflow: rota, credencial e controller (RF-12, CT-04, D-01)
      Arquivos: `routes/api.php`, `app/Http/Middleware/ForcarRespostaJson.php`, `app/Http/Middleware/ValidarAssinaturaWebhookOcr.php`, `app/Http/Requests/Api/RetornoWorkflowOcrRequest.php`, `app/Http/Controllers/Api/OcrWebhookController.php`, `app/Services/Ocr/WebhookOcrUrl.php` (novos), `docs/api/openapi.yaml`, `tests/Feature/Api/OcrWebhookControllerTest.php` (novo)
      Mudança: rota `POST /ocr/webhook`, nomeada `ocr.webhook`, fora dos grupos, com `ForcarRespostaJson` e `ValidarAssinaturaWebhookOcr` (`hasValidRelativeSignature()`; sem ela, 401 `{message}` antes da validação) e comentário pt-BR. FormRequest: `job_id` `required|string|max:64` e `status` `required|in:success,error`. O controller fino busca o documento com `id` = `query('documento')` e `ocr_job_id` = `job_id` (404 se não achar), chama `aplicarRetorno` e responde 200, sem logar o corpo. `WebhookOcrUrl::gerar` usa `temporarySignedRoute` relativa com validade M (`idade_maxima_absoluta_minutos`), prefixada por `webhook_base_url` (D4), com comentário pt-BR: M cobre o retorno entre A e M sem reenvio. O `openapi.yaml` oficial ganha `/ocr/webhook`.
      Cobre: RF-12, CT-04, RF-11 (AC g), D-01
      Acceptance criteria: há 1 teste por caso de RF-12 (401, 422, 404, `success`, `error`, `success` sobre `erro`, `error` sobre `processado`, repetido), sem `X-API-Token`. Corpo `{"status":"success"}` ou `{"status":"outro","job_id":"<uuid>"}`: 422 com credencial e 401 sem credencial. Documento devolvido à fila → o job antigo recebe 404 com 0 avisos. URL aceita depois de `travel(20)` minutos e aos 59; recusada (401) aos 61. `documento` adulterado → 401. `docs/api/openapi.yaml` tem `/ocr/webhook` com `security: []`. `OpenApiContratoDep01Test` passa.
      Testes: `tests/Feature/Api/OcrWebhookControllerTest.php`; `tests/Unit/OpenApiContratoDep01Test.php`

## Phase 6: Envio ao workflow e consulta do job antes do reenvio

Antes de implementar, leia:
1. `.spec/features/ocr-do-processo-para-o-sistema/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/ocr-do-processo-para-o-sistema/PLAN.md` — decomposição completa, dependências e riscos
3. `.spec/features/ocr-do-processo-para-o-sistema/workflow-ocr.openapi.yaml` — `/documents/process`, `GET /jobs/{job_id}` e `DELETE /jobs/{job_id}/chunks` (CT-03, CT-05, CT-07)

- [ ] T16 — Envio ao workflow e consulta do job antes do reenvio (RF-11, RF-18 item 3, CT-03, CT-05, CT-07)
      Arquivos: `app/Services/Ocr/MontarEnvioOcrService.php`, `app/Jobs/EnviarDocumentoAoOcrJob.php`, `app/Services/Ocr/DecisaoReenvioOcr.php`, `app/Services/Ocr/VerificarJobAntigoOcrService.php`, `app/Jobs/VerificarReenvioOcrJob.php` (novos); `tests/Unit/Services/Ocr/MontarEnvioOcrServiceTest.php`, `tests/Feature/Jobs/EnviarDocumentoAoOcrJobTest.php`, `tests/Unit/Services/Ocr/VerificarJobAntigoOcrServiceTest.php`, `tests/Feature/Jobs/VerificarReenvioOcrJobTest.php` (novos)
      Mudança: `corpo()` monta 5 chaves: `url` (`temporaryUrl` do `path`, P min), `filename` (`{id}.{extensaoDoFilename}`), `webhook_url` (`WebhookOcrUrl`), `base_conhecimento` e `metadata`. O `metadata` leva `numero_processo`, `tribunal_id` int, `numero_documento` string, `tipo_documento` (descrição do `TipoDocumento` ou null), `descricao`, `movimento`, `mimetype`, `data_juntada` ISO-8601 com fuso ou null e `nivel_sigilo` int (null → 0); sem `escritorio_id`. `EnviarDocumentoAoOcrJob($processamentoId, $documentoId)`: sem o par, `ShouldBeUnique` por documento, fila `ocr-envio`, tries 5, `backoff` `[60, 120, 300, 600]`, timeout 60. `handle()`, em ordem:
        1. processamento encerrado → sai;
        2. documento `processado` ou `enviado` → sai;
        3. sem objeto no S3 → `registrarErroDefinitivo` + `marcarComoErro` do binário;
        4. com `ocr_job_id_cancelar` → `cancelarJob`, que relança na falha, e limpa a coluna;
        5. `enviarDocumento`: aceito → `registrarEnvio`; 422 → `registrarErroDefinitivo`, sem nova tentativa; transitória → relança.
        `failed()` → `registrarErroDefinitivo`.
        `VerificarJobAntigoOcrService::verificar($doc)` (D9) consulta o job antigo: `Sucesso` → `aplicarRetorno(true)`, decisão `Processado`, sem reenvio; `Inexistente`, `Falhou` ou `Cancelado` → `Reenviar`, sem marcar erro; `Pendente` ou `EmExecucao` → `Reenviar` com idade ≥ M, senão `Aguardar`; exceção transitória → `Aguardar` + `Log::warning`.
        `VerificarReenvioOcrJob($processamentoId, $documentoId)`: sem o par, fila `ocr-envio`, `ShouldBeUnique` por documento (120 s), `$tries = 1`, timeout 60, com comentário pt-BR (a nova consulta é a da rodada seguinte). Sai se o processamento está encerrado, se o documento não está `enviado` ou se a idade é < A. Com `Reenviar` → `devolverParaFila` + `EnviarDocumentoAoOcrJob`, que faz o `DELETE` antes do `POST`.
      Cobre: RF-11 (AC a–g), RF-18 (item 3; AC c, d, g, l, m, n, o, p), CT-03, CT-05, CT-07, RF-09 (D3)
      Acceptance criteria: Envio:
        o corpo de 1 PDF tem exatamente as 5 chaves e o `metadata` sem `escritorio_id`, com `Authorization: Bearer`; HTML → `url` e `filename` `.pdf`; as 6 imagens → a extensão de RF-11 e 0 objetos novos no S3;
        `data_juntada` `2026-01-05 10:00:00` e `nivel_sigilo` `null` → `"2026-01-05T10:00:00+00:00"` e `0`; link com expiração ≥ `now()` + 25 min;
        os payloads dos 2 jobs não têm o par;
        422 → 1 tentativa e `com_erro` + 1; 500 sempre → `com_erro` + 1 no `failed()`;
        `ocr_job_id_cancelar` → 1 `DELETE` antes de 1 `POST`; sem `job_id` antigo → 0 `DELETE`; processamento encerrado → 0 requisições.
        Consulta, com fila `sync`:
        `failed` → `GET`, `DELETE` e `POST`, nessa ordem, com 0 SOAP, e depois o webhook do job antigo responde 404 com 0 avisos;
        404 e `DELETE` 500 sempre → 0 `POST` e `erro` depois do `failed()`;
        `running`/`pending` com idade < M → 0 `DELETE`/`POST`, documento `enviado` com o job conhecido;
        `success` → 0 `DELETE`/`POST`, `processados` + 1, 1 aviso por assinante e `ultimo_progresso_em` atualizado;
        503 ou tempo esgotado → 0 `DELETE`/`POST`, sem exceção;
        `running` com idade ≥ M → 1 `DELETE` e 1 `POST`; `cancelled` → 1 `DELETE` e 1 `POST`;
        processamento encerrado ou documento fora de `enviado` → 0 requisições.
      Testes: `tests/Unit/Services/Ocr/MontarEnvioOcrServiceTest.php`; `tests/Feature/Jobs/EnviarDocumentoAoOcrJobTest.php`; `tests/Unit/Services/Ocr/VerificarJobAntigoOcrServiceTest.php`; `tests/Feature/Jobs/VerificarReenvioOcrJobTest.php`

## Phase 7: Download e acompanhamento de reserva

Antes de implementar, leia:
1. `.spec/features/ocr-do-processo-para-o-sistema/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/ocr-do-processo-para-o-sistema/PLAN.md` — decomposição completa, dependências e riscos
3. `.spec/features/ocr-do-processo-para-o-sistema/workflow-ocr.openapi.yaml` — `GET /jobs/{job_id}` e regras de cada `status` (CT-05, T18)

- [ ] T17 — Download para o OCR, com a recusa encerrando o processamento (RF-09, RF-10)
      Arquivos: `app/Jobs/BaixarDocumentoParaOcrJob.php` (novo), `app/Services/MNI/ClassificarFalhaMniService.php` (`recusaNaCadeia`), `tests/Feature/Jobs/BaixarDocumentoParaOcrJobTest.php` (novo), `tests/Unit/Services/MNI/ClassificarFalhaMniServiceTest.php`
      Mudança: `recusaNaCadeia(Throwable): bool` percorre `getPrevious()` atrás de `CredencialPjeRecusadaException`; é puro. Job `BaixarDocumentoParaOcrJob($processamentoId, $documentoId, #[\SensitiveParameter] $login, #[\SensitiveParameter] $senha)`: `ShouldBeEncrypted`, fila `ocr-download`, tries 3, `backoff` `[10, 30, 60]`, timeout 300. `handle()`, em ordem:
        1. processamento encerrado → sai com 0 SOAP;
        2. documento `processado` → sai;
        3. `baixado` com o objeto no S3 → despacha o envio, com 0 SOAP;
        4. senão, `baixarDocumento($doc, $login, $senha)` e depois despacha o envio.
        Na recusa (`recusaNaCadeia`) → `encerrarPorRecusa` e `return`, sem relançar e sem `com_erro`. Outras falhas → `Log::warning` com a classe e relança. `failed()`: com o processamento aberto, `registrarErroDefinitivo`.
      Cobre: RF-09 (AC a–e), RF-10 (AC), RF-02 (AC c), RNF-01
      Acceptance criteria: depois do job, `/documento/visualizar` → 200 com `link` e 0 SOAP. Falha sem `loginfailed` em todas as tentativas + `failed()` → `com_erro` + 1. `jobs.payload` não tem o par. HTML legado e PDF/PNG `baixado` → 0 SOAP e envio despachado. 10 documentos com recusa no 4º → 0 SOAP depois, `com_erro` sem mudança, 7 pendentes e final `falhou` por papel. Todo SOAP leva o par do dono.
      Testes: `tests/Feature/Jobs/BaixarDocumentoParaOcrJobTest.php`; `tests/Unit/Services/MNI/ClassificarFalhaMniServiceTest.php`
- [ ] T18 — Acompanhamento de reserva e consulta antes do reenvio (RF-13, RF-18 item 3, LIM-02, LIM-03)
      Arquivos: `app/Services/Ocr/AcompanharProcessamentosOcrService.php`, `app/Console/Commands/OcrAcompanhar.php` (novos), `routes/console.php`, `tests/Feature/Console/OcrAcompanharTest.php` (novo)
      Mudança: agenda `ocr:acompanhar` com `everyMinute()->withoutOverlapping()->runInBackground()`. Em cada rodada, para cada processamento aberto, com `base = max(aberto_em, ultimo_progresso_em)`:
        se P venceu: `consultarJob` de cada documento `enviado`, de qualquer processamento. `Sucesso` → `aplicarRetorno(true)`; `Falhou`, `Cancelado` ou `Inexistente` → `aplicarRetorno(false)`; `Pendente`, `EmExecucao` ou exceção → nada. Se sobrar pendente ou aguardando, `encerrarPorPrazo`. A rodada para ali, sem consulta de reenvio e sem reenvio (R4-1).
        se P não venceu: cada `enviado` com idade ≥ A → `VerificarReenvioOcrJob` na fila `ocr-envio` (D9). A rodada não devolve nada à fila.
        O comando é fino.
      Cobre: RF-13 (AC a, b, c), RF-18 (AC f, h, j, k, l, m, n, o, p na rodada), CT-05, LIM-02, LIM-03
      Acceptance criteria: com fila `sync` e `travel()`:
        `success` depois de P → `concluido`; `running` → `falhou` sem `codigo`, e um novo pedido abre outro processamento; 24 min → 0 `GET`; 25 min → consulta e final; progresso aos 10 → fim aos 35;
        progresso a cada 10 min e `failed` com idade ≥ A → na rodada seguinte, 1 `GET`, 1 `DELETE` e 1 `POST`, 0 SOAP, e o processamento segue `em_andamento`;
        sem progresso e com 404 → aos 20 min, 1 `GET`, 1 `DELETE` e 1 `POST`; aos 25, `GET` só do job novo e `falhou`;
        enviado aos 5 min → aos 25, a consulta de P responde `running`, `falhou`, 0 `DELETE`/`POST`;
        `running` → 0 `DELETE`/`POST` e 1 novo `GET` na rodada seguinte; com 404 → 1 `DELETE` e 1 `POST`; `pending` dá o mesmo;
        `success` → 0 `DELETE`/`POST`, `processados` + 1, aviso e P recomeça;
        503 ou tempo esgotado → 0 `DELETE`/`POST` e 1 novo `GET` na rodada seguinte;
        `running` sempre, com progresso → 0 reenvios até M e, na primeira rodada com idade ≥ M, 1 `DELETE` e 1 `POST`;
        `cancelled` → 1 `DELETE` e 1 `POST`;
        `schedule:list` mostra `ocr:acompanhar` a cada minuto.
      Testes: `tests/Feature/Console/OcrAcompanharTest.php`

## Phase 8: Pedido de OCR

Antes de implementar, leia:
1. `.spec/features/ocr-do-processo-para-o-sistema/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/ocr-do-processo-para-o-sistema/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T19 — Pedido de OCR: trava, carona, listagem e abertura (RF-04, RF-05, RF-06, RF-07, RF-18 itens 1, 3 e 4)
      Arquivos: `app/Services/Ocr/SolicitarOcrProcessoService.php`, `app/Services/Ocr/DadosSolicitacaoOcr.php`, `app/Services/Ocr/ResultadoSolicitacaoOcr.php`, `app/Exceptions/EsperaDaTravaOcrEsgotadaException.php`, `app/Exceptions/ConfiguracaoOcrIncompletaException.php` (novos), `tests/Unit/Services/Ocr/SolicitarOcrProcessoServiceTest.php` (novo)
      Mudança: `solicitar()`, em ordem:
        1. sem `n8n_ocr.url` ou `token` → `ConfiguracaoOcrIncompletaException`;
        2. `Cache::lock` por tribunal e número com `block(W)`; esgotada → `EsperaDaTravaOcrEsgotadaException`;
        3. processamento aberto → carona: assinante novo ou reusado por hash, contagens atuais, 0 SOAP e sem guardar o par;
        4. senão, `consultarListaCompletaParaOcr`;
        5. em transação, devolve à fila só os `erro`. "A enfileirar" = pendentes; "a consultar" = `enviado` com idade ≥ A, que seguem `enviado`. Sem pendentes e sem `enviado` → `concluido`, sem processamento. Senão, cria o processamento e o assinante dono; uma `UniqueConstraintViolationException` vira carona;
        6. depois do commit: "a enfileirar" com `baixado` e `path` → `EnviarDocumentoAoOcrJob`, senão `BaixarDocumentoParaOcrJob` com o par do dono; "a consultar" → `VerificarReenvioOcrJob`, sem o par;
        7. devolve `em_andamento` com `com_erro` 0. Só a linha `tribunal_id`.
      Cobre: RF-04 (AC a, b, c), RF-05, RF-06 (AC a, c, d), RF-07 (AC a–d), RF-18 (itens 1, 3 e 4; AC a, e, g no pedido), RF-02 (AC c), D-08
      Acceptance criteria: com `Queue::fake`:
        3 PDF, HTML, PNG, MP4 e MP3 → `{total 5, ignorados 2, em_andamento}` e 5 jobs de download, 0 para vídeo e áudio;
        recusa, inexistente e outra falha → a exceção respectiva, com 0 processamentos, 0 assinantes e 0 jobs;
        carona → 0 SOAP e 0 jobs, e o par da carona em 0 linhas e 0 payloads;
        2 pedidos em sequência → 1 processamento e 1 `consultarProcesso`;
        trava presa e W = 1 → exceção em < 2 s, com 0 SOAP e 0 assinantes;
        300 processados → `concluido` e 0 jobs; 2 novos → `total 302` e 2 jobs;
        8 processados e 2 `erro` → `com_erro` 0 e 2 jobs;
        só vídeo e áudio → `{total 0, ignorados 2, concluido}`;
        `enviado` com idade < A → `em_andamento` e 0 jobs;
        `enviado` com idade A + 1 → 1 `VerificarReenvioOcrJob`, 0 `EnviarDocumentoAoOcrJob`, o mesmo `ocr_job_id` e `ocr_job_id_cancelar` nulo;
        `baixado` → envio, sem download.
      Testes: `tests/Unit/Services/Ocr/SolicitarOcrProcessoServiceTest.php`

## Phase 9: Rota POST /api/processo/ocr e contrato publicado

Antes de implementar, leia:
1. `.spec/features/ocr-do-processo-para-o-sistema/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/ocr-do-processo-para-o-sistema/PLAN.md` — decomposição completa, dependências e riscos
3. `.spec/features/ocr-do-processo-para-o-sistema/openapi.yaml` — nós `/processo/ocr`, `ocr_progresso` e schemas a copiar para `docs/api/openapi.yaml`

- [ ] T20 — Rota POST /api/processo/ocr e contrato publicado (RF-02, RF-03, RF-05, RF-17, CT-01, RNF-04)
      Arquivos: `routes/api.php`, `app/Http/Requests/Api/SolicitarOcrProcessoRequest.php`, `app/Http/Controllers/Api/OcrProcessoController.php` (novos), `docs/api/openapi.yaml`, `tests/Feature/Api/OcrProcessoControllerTest.php`, `tests/Unit/OpenApiContratoOcrTest.php` (novos)
      Mudança: a rota entra no grupo só com `ValidateApiToken` (o do monitoramento), fora do prefixo, com `ForcarRespostaJson` e comentário pt-BR: sem par padrão. FormRequest (`authorize()` = `true`; `cleanNumeroProcesso` em `prepareForValidation`): `numero_processo` `regex:/^\d{20}$/`; `tribunal_id` integer, min 1, existente com `ativo` = true; `login_pje` e `senha_pje` `required|string`; `callback_url` `max:2048` + `CallbackUrl`; `callback_token` de 1 a 255. O controller fino responde 202 `ProcessamentoDeOcr`. `catch Throwable`: `ErroComCodigo::classificar($e, true)` → `resposta()`; espera esgotada → 500 `{message}`; outra → `Log::error` só com ids e a classe, e 500 `{"message": "Não foi possível processar o pedido de OCR"}`. `docs/api/openapi.yaml` recebe `/processo/ocr`, `ocr_progresso`, os 3 schemas e a lista D-01 a D-08 no `info`. `OpenApiContratoOcrTest` compara os nós sem texto livre, com a cópia acordada + `maxLength: 2048` (D-05).
      Cobre: RF-02 (AC a, b), RF-03, RF-05, RF-06 (AC d), RF-17 (AC a, b, c), CT-01, RNF-04, D-01 a D-08
      Acceptance criteria:
        sem token → 401 `{"message": "Token inválido ou não fornecido"}`, 0 SOAP e 0 jobs;
        par padrão configurado e sem `senha_pje` → 422, 0 SOAP e 0 jobs;
        cada campo ausente ou inválido → 422 com a chave em `errors`, 0 SOAP, 0 jobs e 0 linhas do OCR, e o corpo sem login, senha e `callback_token`;
        recusa → 403 com o corpo exato; inexistente → 404 com o corpo exato; outra → 500 `{message}`. Nos 3, sem `error`, `line` e `file`, com 1 SOAP e 0 processamentos;
        W = 1 com a trava presa → 500 em < 2 s;
        `local` com a lista e `http://host.docker.internal:8006/mni/aviso?assinatura=x` → 202, e o aviso vai para essa URL;
        `production` → 422 com a mensagem de `CallbackUrl.php:14`; lista vazia → 422;
        `OpenApiContratoOcrTest` e `OpenApiContratoDep01Test` passam, e `GET /docs/api/openapi.yaml` contém `/processo/ocr`.
      Testes: `tests/Feature/Api/OcrProcessoControllerTest.php`; `tests/Unit/OpenApiContratoOcrTest.php`; `tests/Feature/DocsApiTest.php`

## Phase 10: Ponta a ponta, sigilo e documentação

Antes de implementar, leia:
1. `.spec/features/ocr-do-processo-para-o-sistema/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/ocr-do-processo-para-o-sistema/PLAN.md` — decomposição completa, dependências e riscos
3. `.spec/features/ocr-do-processo-para-o-sistema/openapi.yaml` — schema `AvisoDeProgressoDoOcr` para validar os avisos (T21)
4. `.spec/features/ocr-do-processo-para-o-sistema/workflow-ocr.openapi.yaml` — respostas do workflow simuladas em T21

- [ ] T21 — Fluxos ponta a ponta do OCR (AC1, AC3, AC4, AC5, AC9, AC10)
      Arquivos: `tests/Feature/OcrFluxoTest.php` (novo)
      Mudança: só testes, com fila `sync`, `Storage::fake('s3')`, `simularIntegracaoMni` e `Http::fake` do workflow (202 com ids sequenciais, `GET jobs` e `DELETE` por cenário) e dos callbacks. Os retornos são simulados por `postJson` no `webhook_url` capturado em `Http::recorded()`. Um helper valida cada aviso contra `AvisoDeProgressoDoOcr` de `docs/api/openapi.yaml`. Todo 202 e todo aviso conferem o invariante de RF-08.
      Cobre: RF-06 (AC b), RF-08 (AC), RF-09 (AC b), RF-10 (AC), RF-14 (AC a, b), RF-15 (AC a, b, c, d), RF-18 (AC a, c, e, j, o no pedido), RF-02 (AC c)
      Acceptance criteria: 1 teste por fluxo:
        5 documentos e 2 assinantes → 5 `em_andamento` + 1 final por assinante;
        carona com 4/10 → 6 + 1 final;
        1 de 5 falha sempre no MNI → `com_erro` 1 e `processados` 4;
        recusa no 4º de 10 → finais por papel, e um par novo abre outro processamento com 7 jobs;
        o retorno do documento 2 do processamento 1 conta no 2;
        1 aguardando há menos de A, com `success` → 1 `em_andamento` + 1 `concluido`;
        1 aguardando há A + 1 min, com o job antigo em `failed` → o pedido seguinte faz `GET`, `DELETE` e `POST`, nessa ordem, e o webhook do job antigo dá 404 com 0 avisos;
        depois de um `falhou` por P com o documento aguardando, o pedido seguinte com `failed` faz 1 `GET`, 1 `DELETE` e 1 reenvio;
        sem processamento aberto, aguardando com idade ≥ M e job em `running` → o pedido seguinte faz 1 `GET`, 1 `DELETE` e 1 `POST`;
        503 no 3º aviso → final entregue antes e 0 requisições depois;
        100% dos SOAP com o par do dono; todos os avisos validam contra o schema.
      Testes: `tests/Feature/OcrFluxoTest.php` — `docker compose exec -T php php artisan test --compact --filter=OcrFluxoTest`
- [ ] T22 — Varredura de sigilo do par (RNF-01)
      Arquivos: `tests/Feature/OcrSigiloDoParTest.php` (novo)
      Mudança: só testes. `app.debug` `true` e `false`; par `login-sigilo-7f3a`/`senha-sigilo-9c1d`; `callback_token` `tok-sigilo-2b8e`; `zend.exception_ignore_args = 0`; fila `database` processada por `queue:work --once --stop-when-empty`. Fluxos: pedido novo, carona e `concluido`; 401, 403, 404, 422 e 500; download ok, com falha definitiva e com recusa; envio, consulta antes do reenvio, retorno, aviso e acompanhamento; job em `failed_jobs`. Coleta: logs (`MessageLogged`, com o contexto e `(string) $exception`), respostas (corpo e headers), linhas cruas das tabelas do OCR, `processo_documentos`, `jobs` e `failed_jobs`, e as requisições de saída. Reflection: os jobs do OCR com o par são `ShouldBeEncrypted`, e `EnviarDocumentoAoOcrJob`, `VerificarReenvioOcrJob` e `EnviarAvisoOcrJob` não têm o par.
      Cobre: RNF-01, RF-06 (AC a), RF-09 (AC c), RF-11 (AC b)
      Acceptance criteria: em todos os fluxos e nos 2 valores de `app.debug`, 0 ocorrências do login e da senha em logs, respostas, linhas e payloads crus e chamadas de saída, e 0 do `callback_token` em logs e respostas. A checagem por reflection passa.
      Testes: `tests/Feature/OcrSigiloDoParTest.php`
- [ ] T23 — Documentação: docs/agents e AGENTS.md (RNF-06)
      Arquivos: `AGENTS.md`, `docs/agents/api_contracts.md`, `docs/agents/domain_rules.md`, `docs/agents/data_model.md`, `docs/agents/architecture.md`
      Mudança: `AGENTS.md`: filas `ocr-download`, `ocr-envio` e `ocr-aviso` (l. 48) e o comando `ocr:acompanhar`. `api_contracts.md`: as 2 rotas, o webhook `ocr_progresso`, as filas (com `VerificarReenvioOcrJob` em `ocr-envio`), as saídas ao n8n e a validade M da credencial do `webhook_url`. `domain_rules.md`: a seção "OCR do processo (DEP-03 a DEP-05)" (legíveis, contagens, situações, carona e trava, P/A/M/W e a ordem R4-1, consulta do job antes do reenvio, LIM-02 e LIM-03, recusa, avisos e Q-03, hosts liberados) e a nota de que o OCR não usa o par padrão. `data_model.md`: as 3 tabelas e as 6 colunas. `architecture.md`: a topologia de filas e o fluxo do OCR. Nota para rodar `/ai-context` depois do merge.
      Cobre: RNF-06
      Acceptance criteria: `grep` acha `ocr-download`, `ocr-envio` e `ocr-aviso` em `AGENTS.md` e `architecture.md`; `/processo/ocr` e `/ocr/webhook` em `api_contracts.md`; `processo_ocr_processamentos` em `data_model.md`; a seção "OCR do processo" em `domain_rules.md`, com a consulta antes do reenvio e LIM-03, citando P = 25, A = 20, M = 60 e W = 45.
      Testes: revisão (só documentação)

## Phase 11: Verificação final e roteiro de homologação

Antes de implementar, leia:
1. `.spec/features/ocr-do-processo-para-o-sistema/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/ocr-do-processo-para-o-sistema/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T24 — Verificação final, relatório e roteiro de homologação (RNF-02, RNF-03, RNF-05, RF-01 AC d)
      Arquivos: `.spec/features/ocr-do-processo-para-o-sistema/RELATORIO.md` (novo)
      Mudança: rodar `docker compose exec -T php composer test` (verde) e `npm run typecheck` (sem mudança). Conferir `route:list --path=api` (`POST api/processo/ocr` com `ValidateApiToken` e sem `InjectCredenciaisPjePadrao`; `POST api/ocr/webhook` sem `ValidateApiToken`) e `schedule:list` (`ocr:acompanhar` a cada minuto). O relatório traz:
        a tabela RF/RNF/CT → testes;
        os testes que mudaram de propósito;
        D-01 a D-08 e as Open Questions para o Sistema;
        o roteiro de homologação: pré-condições (workflow com o RF-14 do Sistema importado, concorrência do n8n e cota do Gemini registradas); TJAP real com senha errada → 403 nas 2 rotas; 300 PDFs ≤ 10 min; 300 `ocr_enviado_em` ≤ X = 5 min; `POST` < 60 s; a busca acha um trecho;
        os itens manuais pendentes.
      Cobre: RNF-05, RNF-02 e RNF-03 (homologação), RF-01 (AC d)
      Acceptance criteria: `composer test` termina verde, com a saída registrada. `route:list` e `schedule:list` conferem. `RELATORIO.md` tem as 5 seções, e cada RF-01..RF-18 e RNF-01..RNF-04 aponta para pelo menos 1 teste, salvo os itens de homologação.
      Testes: `docker compose exec -T php composer test`
