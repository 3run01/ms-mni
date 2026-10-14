# Phases: baixar-documentos-mni-em-lote

Gerado por /plan a partir de PLAN.md — view executável para `./ralph.sh .spec/features/baixar-documentos-mni-em-lote/PHASES.md`.

## Phase 1: Fundamentos — parâmetros, normalizador, trava e gravação desacoplada

Antes de implementar, leia:
1. `.spec/features/baixar-documentos-mni-em-lote/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/baixar-documentos-mni-em-lote/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T01 — Parâmetros T e P do download em lote
      Arquivos: `config/pje.php`, `app/Services/Processo/ParametrosDownloadEmLote.php` (novo), `.env.example`, `tests/Unit/ConfigPjeTest.php`, `tests/Unit/Services/Processo/ParametrosDownloadEmLoteTest.php` (novo)
      Mudança: bloco `'download_documentos' => ['tamanho_lote' => env('PJE_DOWNLOAD_TAMANHO_LOTE', 20), 'max_paginas' => env('PJE_DOWNLOAD_MAX_PAGINAS')]` em `config/pje.php`, com comentário pt-BR (coding_guidelines §8 e §11). Classe sem estado `ParametrosDownloadEmLote`, que lê `config()` a cada chamada: `tamanhoLote()` (ausente, não numérico ou < 1 vira 20), `tamanhoPagina()` (= T, premissa Q-02) e `maxPaginas()` (ausente ou < 1 vira ceil(T / tamanhoPagina) + 1). As duas variáveis entram comentadas no `.env.example`, no padrão da linha 83.
      Cobre: RF-05
      Acceptance criteria: `config('pje.download_documentos')` tem `tamanho_lote` e `max_paginas`. Sem configuração, T = 20, tamanhoPagina = 20 e P = 2. T = 10 com override. T inválido (0, negativo, não numérico) vira 20. P = 3 com override; P = 0 volta ao padrão. `.env.example` contém `PJE_DOWNLOAD_TAMANHO_LOTE` e `PJE_DOWNLOAD_MAX_PAGINAS`.
      Testes: `tests/Unit/ConfigPjeTest.php` — chaves e mapeamento para as envs; `tests/Unit/Services/Processo/ParametrosDownloadEmLoteTest.php` — padrões, overrides válidos e inválidos, fórmula de P
- [ ] T02 — Exceção `ConsultaMNISemSucessoException` e `NormalizarDocumentoMniService`
      Arquivos: `app/Exceptions/ConsultaMNISemSucessoException.php` (novo), `app/Services/MNI/NormalizarDocumentoMniService.php` (novo), `tests/Unit/Exceptions/ConsultaMNISemSucessoExceptionTest.php` (novo), `tests/Unit/Services/MNI/NormalizarDocumentoMniServiceTest.php` (novo)
      Mudança: a exceção estende `MNIException` (padronização da coding_guidelines §9) e sinaliza `sucesso = false`, distinguível por `instanceof` de transporte, SOAP fault ou timeout. O normalizador é um helper puro, sem SOAP e sem persistência, com quatro métodos: `extrairDocumentos` (objeto ou array, `processos->documento`, `processos->processo->documento` e `documentoVinculado`, como `ConsultarDocumentoService.php:43-60`); `indexarPorId` (chave `(string) idDocumento`, última ocorrência vence); `classificar` (`vazio`, `bloqueado` por igualdade com o texto de `:63`, ou `ok`, sobre o conteúdo cru); `normalizarConteudo` (regra base64 idêntica a `:67-83`).
      Cobre: RF-06, RF-07, CT-01, CT-03
      Acceptance criteria: a exceção é `instanceof MNIException` e `getError()` padroniza a mensagem. O mapa por id usa chaves string e funciona para objeto, array e vinculado. `classificar` distingue `vazio`, `bloqueado` e `ok`. `normalizarConteudo` iguala `:67-83` para PDF, `audio/mpeg` já em base64, `audio/mpeg` cru e `text/html`.
      Testes: `tests/Unit/Exceptions/ConsultaMNISemSucessoExceptionTest.php` — herança e mensagem; `tests/Unit/Services/MNI/NormalizarDocumentoMniServiceTest.php` — formas de resposta, vinculado, chave string, classificação e tabela base64
- [ ] T03 — Trava por documento `TravaDownloadDocumento`
      Arquivos: `app/Services/Processo/TravaDownloadDocumento.php` (novo), `tests/Unit/Services/Processo/TravaDownloadDocumentoTest.php` (novo)
      Mudança: encapsula `Cache::lock("mni-download:documento:{id}", 1800, $dono)`. `adquirir()` usa `->get()` atômico. `liberar()` usa `Cache::restoreLock(...)->release()` e só libera com o dono certo. `liberarTodas()` libera uma lista. TTL = 1800, em paridade com `BaixarDocumentoMNIJob.php:17`, no cache store padrão.
      Cobre: RF-12
      Acceptance criteria: uma segunda aquisição com outro dono retorna false. Liberar com o dono certo permite readquirir; com dono errado não libera. Após 1801 s, `adquirir` retorna true.
      Testes: `tests/Unit/Services/Processo/TravaDownloadDocumentoTest.php` — os 4 cenários, no store `array`
- [ ] T04 — Desacoplar a gravação por mimetype e a marcação de erro em `SalvarDocumentoProcessoService`
      Arquivos: `app/Services/Processo/SalvarDocumentoProcessoService.php`, `tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php`
      Mudança: novo `salvarConteudo(ProcessoDocumento, object $documentoMni): string`, que roteia pelo mimetype persistido (`:108-120`) para html, pdf, audio/* e demais, e lança `InvalidArgumentException` para mp4/quicktime. O trecho pós-consulta de `downloadHTML/PDF/Audio/Outros` vai para `salvarConteudoHtml/Pdf/Audio/Outros`, com as mesmas chaves S3, `path_html` e `putComRetry`. Os `download*` viram wrappers `consultarDocumento()` + `salvarConteudo*()`, com os mesmos prefixos de mensagem (fluxo síncrono preservado). Novo `marcarComoErro($doc, $motivo, bool $incrementarTentativa = true)`: status `erro`, + 1 opcional, `save()` e exatamente 1 `Log::error("[ProcessoDocumento:{id}] ...", ['id_documento', 'motivo'])`, sem credenciais. Os `salvarConteudo*` não emitem `Log::error`.
      Cobre: RF-06, RF-07, RNF-03
      Acceptance criteria: com `Storage::fake('s3')`, as chaves por mimetype são html → `{id}.pdf` + `{id}.html` + `path_html`; pdf → `{id}.pdf`; `audio/mpeg` → `{id}.mp3`; `application/msword` → `{id}.doc`; desconhecido → `{id}.dat`. `video/mp4` lança exceção. `marcarComoErro` grava `erro`, + 1 (ou nada com false) e 1 `Log::error` com `id_documento`. `DocumentoControllerTest`, `SalvarDocumentoDownloadedAtTest` e os testes existentes continuam verdes.
      Testes: `tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php` — casos por mimetype e `marcarComoErro` com `Log::spy()`; regressão em `tests/Feature/Api/DocumentoControllerTest.php` e `tests/Feature/SalvarDocumentoDownloadedAtTest.php`

## Phase 2: Operações SOAP — fallback unitário e consultarProcessos

Antes de implementar, leia:
1. `.spec/features/baixar-documentos-mni-em-lote/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/baixar-documentos-mni-em-lote/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T05 — `ConsultarDocumentoService::buscar` para o fallback unitário (CT-03)
      Arquivos: `app/Services/MNI/Intercomunicacao/ConsultarDocumentoService.php`, `tests/Unit/Services/MNI/ConsultarDocumentoServiceTest.php` (novo)
      Mudança: novo `buscar(Tribunal, string $numeroProcesso, string $idDocumento, ?login, ?senha): ?object`, com a mesma requisição de `:22-35` (`url_webservice_mni`, flags false, 1 `documento`, `login ?? tribunal->login` e `senha ?? Crypt::decrypt`). `sucesso = false` lança `ConsultaMNISemSucessoException` sem re-embrulhar. `sucesso = true` devolve o documento casado, cru, via normalizador (inclui vinculado), ou `null`. Demais falhas viram `MNIException`. `execute()` mantém o contrato e só troca `:67-83` por `normalizarConteudo()`. `IntegracaoBase` passa a ser criada via `app()->makeWith(IntegracaoBase::class, ['url' => ...])`. Uma classe por operação, sem persistência.
      Cobre: CT-03, RF-08 (a), RF-11
      Acceptance criteria: `buscar` invoca `consultarProcesso` com 1 `documento` em `url_webservice_mni`, com `idConsultante` = login informado ou `tribunal->login`. `sucesso = false` lança `ConsultaMNISemSucessoException`. `SoapFault` lança `MNIException` não específica. Documento só em vinculado é encontrado; ausente devolve null. `execute()` mantém o comportamento atual (PDF vira base64, `audio/mpeg` já em base64 fica inalterado, bloqueado e `sucesso = false` lançam `MNIException`).
      Testes: `tests/Unit/Services/MNI/ConsultarDocumentoServiceTest.php` — `IntegracaoBase` mockado por binding, capturando a URL; casos de `buscar` e caracterização de `execute()`
- [ ] T06 — `IntegracaoBase::possuiOperacao` e a operação `ConsultarProcessosService` (CT-01)
      Arquivos: `app/Services/IntegracaoBase.php`, `app/Services/MNI/Intercomunicacao/ConsultarProcessosService.php` (novo), `tests/Fixtures/mni/intercomunicacao-com-consultarProcessos.wsdl` (novo), `tests/Fixtures/mni/intercomunicacao-sem-consultarProcessos.wsdl` (novo), `tests/Unit/Services/IntegracaoBaseTest.php` (novo), `tests/Unit/Services/MNI/ConsultarProcessosServiceTest.php` (novo)
      Mudança: em `IntegracaoBase`, método aditivo `possuiOperacao(string)` com `initiateSoap()` + `__getFunctions()` e casamento exato `/\sNOME\(/`. Falha de WSDL propaga, e `makeSoapRequest` não muda. `ConsultarProcessosService` tem `operacaoDisponivel(Tribunal)` (em `url_webservice_mni`) e `consultarPagina(Tribunal, numeroProcesso, idsDocumentos, pagina, tamanhoPagina, ?login, ?senha): array`, que envia os campos do CT-01 (`idConsultante`, `senhaConsultante`, `numeroProcesso`, `movimentos` false, `incluirCabecalho` false, `incluirDocumentos` false, `documento` como lista de ids string, `pagina`, `tamanhoPagina`). `sucesso = false` lança `ConsultaMNISemSucessoException`. `sucesso = true` devolve um mapa por id de documentos crus. `DecryptException` vira `MNIException` 422 e demais falhas viram `MNIException`. Nunca loga senha nem `lastRequest`.
      Cobre: CT-01, RF-01 (item 1), RF-08 (a), RF-11
      Acceptance criteria: `possuiOperacao('consultarProcessos')` retorna true e false conforme o WSDL fixture. `consultarPagina` envia os 9 campos do CT-01 em `url_webservice_mni` (não em `url_webservice_mni_consultar_processo`), com `idConsultante` `{LOGIN}` ou `tribunal->login`. A resposta vira um mapa com chaves string; `processos` sem `documento` vira `[]`. `sucesso = false` lança a exceção específica e `SoapFault` lança `MNIException` não específica.
      Testes: `tests/Unit/Services/IntegracaoBaseTest.php` — WSDLs fixture locais; `tests/Unit/Services/MNI/ConsultarProcessosServiceTest.php` — binding de `IntegracaoBase`, parâmetros (20 ids, pagina 2, tamanhoPagina 20), URL, credenciais, formas de resposta e erros

## Phase 3: Núcleo do download em lote

Antes de implementar, leia:
1. `.spec/features/baixar-documentos-mni-em-lote/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/baixar-documentos-mni-em-lote/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T07 — `BaixarDocumentosEmLoteService::executar`: elegibilidade, paginação, gravação e falhas parciais
      Arquivos: `app/Services/Processo/BaixarDocumentosEmLoteService.php` (novo), `tests/Unit/Services/Processo/BaixarDocumentosEmLoteServiceTest.php` (novo), `tests/Pest.php`
      Mudança: `executar(int $processoId, array $processoDocumentoIds, ?login, ?senha): void`, em Services/Processo, com SOAP só por classes de operação resolvidas via `app()`. Etapas: (1) recarrega o processo com o tribunal e os documentos; processo ausente gera `Log::warning` + return; tribunal ausente lança `MNIException`. (2) RF-03: `baixado` com path e objeto no S3 fica fora, sem escrita; sem elegíveis, return; elegíveis em `erro` voltam a `pendente`. (3) RF-01: `pagina` 1..P, `tamanhoPagina` do `ParametrosDownloadEmLote`, mesma lista de ids `(string) id_documento` e credenciais do lote em toda página; para quando todos os ids foram vistos, quando a página vem vazia ou em P; ids já vistos e documentos não pedidos são ignorados. (4) por documento: vazio ou bloqueado → `marcarComoErro`; ok → normaliza, `salvarConteudo`, confirma no S3, `marcarComoBaixado` + 1; exceção de gravação → `marcarComoErro`; no máximo 1 marcação por execução. (5) não vistos → `marcarComoErro` (não retornado). (6) liberar a memória por página. `error` sai só via `marcarComoErro`, e o serviço não usa `SalvarDocumentoProcessoService::consultarDocumento`. Helpers compartilhados `criarDocumentosLote()` e `documentoMniFake()` em `tests/Pest.php`.
      Cobre: RF-01, RF-03, RF-05 (P), RF-06, RF-07, RF-11, RNF-01 (b), RNF-03
      Acceptance criteria: RF-01 AC (a) a (d), RF-03 AC (a) a (c), RF-05 AC (b) e (c), RF-06 por mimetype (`baixado`, path, `downloaded_at`, + 1) e RF-07 AC [A PDF, B ausente, C bloqueado, D vazio] passam, este último em 2 ordens. Caminho feliz sem `buscar`. 1 `Log::error` com `id_documento` por erro. `{LOGIN}` em todas as páginas.
      Testes: `tests/Unit/Services/Processo/BaixarDocumentosEmLoteServiceTest.php` — mocks de `ConsultarProcessosService` (`operacaoDisponivel` true sem `once()`, `consultarPagina`) e de `ConsultarDocumentoService` (`shouldNotReceive('buscar')`), `Storage::fake('s3')`, `Log::spy()`

## Phase 4: Fallback e falha total no serviço + job de lote

Antes de implementar, leia:
1. `.spec/features/baixar-documentos-mni-em-lote/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/baixar-documentos-mni-em-lote/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T08 — Fallback unitário (RF-08 a) e falha total (RF-08 b)
      Arquivos: `app/Services/Processo/BaixarDocumentosEmLoteService.php`, `tests/Unit/Services/Processo/BaixarDocumentosEmLoteFallbackTest.php` (novo)
      Mudança: sem mudar a assinatura de `executar()` e sem editar `tests/Pest.php`. `operacaoDisponivel` false aciona o fallback de todos os elegíveis, com 0 `consultarPagina`. `ConsultaMNISemSucessoException` numa página aciona o fallback só dos não marcados nesta execução e encerra a paginação. O fallback chama `buscar()` em ordem estável, com as credenciais do lote: exceção de `sucesso = false` → `marcarComoErro` e segue; null → erro ausente; documento → tratamento do item 4 de T07. Qualquer outra exceção (`operacaoDisponivel`, página, unitária ou tribunal ausente) é falha total: `marcarComoErro` com `MNIException::getError()` só nos não marcados (inclusive os não pedidos), sem novas chamadas, e relança `MNIException`. Documento já `erro` nesta execução não recebe + 1 nem log extra (M9). Sem `Log::error` agregado.
      Cobre: RF-08 (a, b), CT-03, RF-07, RF-11, RNF-03
      Acceptance criteria: RF-08 AC (a1) a (a5) passam, com contagens exatas de `consultarPagina` e `buscar`. RF-08 AC (b1) a (b5) passam, com exceção lançada, + 1 sem duplicação e 1 log por documento. `buscar` recebe `{LOGIN}`.
      Testes: `tests/Unit/Services/Processo/BaixarDocumentosEmLoteFallbackTest.php` — os 10 cenários do RF-08 e RF-11 no fallback, com mocks, `Storage::fake('s3')` e `Log::spy()`
- [ ] T09 — Job `BaixarDocumentosMNIEmLoteJob`: fila, travas e falha definitiva
      Arquivos: `app/Jobs/BaixarDocumentosMNIEmLoteJob.php` (novo), `tests/Feature/Jobs/BaixarDocumentosMNIEmLoteJobTest.php` (novo)
      Mudança: `ShouldQueue` + `Queueable`, `$tries = 3`, `$timeout = 300` (alinhados a `config/horizon.php:220-234`), sem `ShouldBeUnique`. O construtor recebe ids: `processoId`, `processoDocumentoIds`, `tentativasNoDespacho` (id → tentativas no despacho), `donoTrava` e credenciais privadas como no job atual. `handle()` delega a `BaixarDocumentosEmLoteService::executar()`; sem exceção, chama `liberarTodas`; com exceção, relança sem liberar. `failed(?Throwable)` aplica o RF-13: recarrega os documentos; quem não está `baixado` com objeto no S3 (inclusive `baixado` sem objeto; erro na verificação conta como ausente) recebe `marcarComoErro` com + 1 só se `tentativas_download` atual <= snapshot e 1 `Log::error`; `baixado` com objeto não muda; as travas são liberadas em `finally`.
      Cobre: RF-12 (itens 2 e 3), RF-13, RNF-02 (limites), RNF-03
      Acceptance criteria: sem exceção, as travas são liberadas (outro dono adquire). Com exceção, o job relança e mantém as travas. Processo inexistente gera warning, libera e não lança. RF-13 AC (a), (b) (A n+1 não muda, B e C ganham + 1) e (c) passam, com a contagem exata de `Log::error`. `$tries` = 3 e `$timeout` = 300.
      Testes: `tests/Feature/Jobs/BaixarDocumentosMNIEmLoteJobTest.php` — serviço mockado em `handle()`, `failed()` chamado diretamente, `Storage::fake('s3')`, `Log::spy()`, travas verificadas via `TravaDownloadDocumento`

## Phase 5: Despacho em lote

Antes de implementar, leia:
1. `.spec/features/baixar-documentos-mni-em-lote/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/baixar-documentos-mni-em-lote/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T10 — Despacho em lote `EnfileirarDownloadDocumentosService`
      Arquivos: `app/Services/Processo/EnfileirarDownloadDocumentosService.php` (novo), `tests/Unit/Services/Processo/EnfileirarDownloadDocumentosServiceTest.php` (novo)
      Mudança: `enfileirar(iterable $documentos, ?login, ?senha): array` com as chaves `lotes`, `documentos_em_lote`, `videos` e `ignorados_trava`. Deduplica por id e mantém só `pendente`/`erro`, com status null contando como `pendente`. Cada vídeo mp4/quicktime gera `BaixarDocumentoMNIJob::dispatch($doc, $login, $senha)->onQueue('mni-download')`. Os não-vídeo são agrupados por `processo_id`, passam por `TravaDownloadDocumento::adquirir` (dono = UUID por chamada; sem trava, fica fora e não conta em N), são divididos com `chunk(T)` e viram `BaixarDocumentosMNIEmLoteJob::dispatch(...)->onQueue('mni-download')` com `tentativasNoDespacho` = `(int) (tentativas_download ?? 0)`. Se um dispatch falhar, as travas daquele lote são liberadas e a exceção é relançada.
      Cobre: RF-02, RF-04 (a, b), RF-05 (a), RF-10, RF-12 (a, c)
      Acceptance criteria: RF-02 AC dá os 5 ids. RF-04 AC (a): N = 45 → 20, 20 e 5; N = 20 e N = 1 → 1 lote; 2 processos sem mistura; união = N e interseção vazia. RF-04 AC (b): 3 PDF + 2 mp4 → 1 lote + 2 jobs por documento. RF-05 AC (a): T = 10 → 5 lotes. RF-12 AC (a) e (c) passam. Todo job vai para `mni-download` e as credenciais são repassadas.
      Testes: `tests/Unit/Services/Processo/EnfileirarDownloadDocumentosServiceTest.php` — `Queue::fake()`, os cenários acima e status null tratado como pendente

## Phase 6: Migração dos pontos de despacho

Antes de implementar, leia:
1. `.spec/features/baixar-documentos-mni-em-lote/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/baixar-documentos-mni-em-lote/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T11 — Migrar `SalvarDocumentoProcessoService::execute` para o despacho em lote
      Arquivos: `app/Services/Processo/SalvarDocumentoProcessoService.php`, `tests/Feature/Jobs/SalvarDocumentoSemBinarioTest.php`, `tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php`
      Mudança: `salvarDocumento()` (`:34-58`) retorna o `ProcessoDocumento` e não despacha mais (sai `:55-56`; a assinatura é mantida). `execute()` coleta, quando `$baixar_binarios`, os documentos com status diferente de `baixado` (incluindo vinculados) e, depois do loop, chama uma vez `app(EnfileirarDownloadDocumentosService::class)->enfileirar($coletados, $login_pje, $senha_pje)`. `ProcessoService.php:62` e `:134` não mudam.
      Cobre: RF-10 (persistência), RF-11, RF-04
      Acceptance criteria: `baixar_binarios = false` enfileira 0 jobs. PDF gera lote na fila `mni-download` e 0 `BaixarDocumentoMNIJob`. mp4 gera exatamente 1 `BaixarDocumentoMNIJob`. Documento + vinculado com `{LOGIN}`/`{SENHA}` geram 1 lote com os 2 ids e as credenciais. `baixado` não entra.
      Testes: `tests/Feature/Jobs/SalvarDocumentoSemBinarioTest.php` — atualizar `:42-46` e adicionar o caso de vídeo; `tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php` — reescrever `:28-67` para o lote com credenciais
- [ ] T12 — Migrar `mni:baixar-documento-pendente`
      Arquivos: `app/Console/Commands/MNIBaixarDocumentoPendente.php`, `tests/Feature/Console/MNIBaixarDocumentoPendenteTest.php` (novo)
      Mudança: a seleção `erro` (`:30`) não muda. O loop de `:31-33` vira `app(EnfileirarDownloadDocumentosService::class)->enfileirar($documentos)`, sem credenciais (vale o tribunal), e o comando imprime o resumo. A assinatura não muda e o comando só despacha.
      Cobre: RF-09, RF-10, RF-11, CT-02
      Acceptance criteria: 20 documentos `erro` de um processo + 5 de outro geram 2 lotes (20 e 5) e 0 jobs por documento. + 1 mp4 em `erro` gera exatamente 1 `BaixarDocumentoMNIJob`. Os lotes saem sem credenciais. A assinatura não muda.
      Testes: `tests/Feature/Console/MNIBaixarDocumentoPendenteTest.php` — `Queue::fake()` e asserções por id (banco de dev compartilhado)
- [ ] T13 — Migrar `media:redownload`, `media:fix-inconsistent` e `media:redownload-test`
      Arquivos: `app/Console/Commands/MediaRedownload.php`, `app/Console/Commands/MediaFixInconsistent.php`, `app/Console/Commands/MediaRedownloadTest.php`, `app/Console/Commands/MediaHelp.php`, `tests/Feature/Console/MediaComandosDownloadEmLoteTest.php` (novo)
      Mudança: seleções e confirmações não mudam. `media:redownload` chama `enfileirar()` uma vez por página de `--batch` (`:79-122`), com os documentos atualizados para `pendente`; falha no `enfileirar` conta como erro da página e é logada. `media:fix-inconsistent` e `media:redownload-test` chamam `enfileirar()` uma vez depois do loop. Os contadores vêm do resumo. `MediaHelp.php:64` passa a dizer que áudio vai por lote e que `BaixarDocumentoMNIJob` fica só para vídeo. Assinaturas inalteradas.
      Cobre: RF-04 (c), RF-10, CT-02
      Acceptance criteria: `--batch=2` com 3 `audio/mpeg` do mesmo processo gera 2 lotes (2 e 1). Nos 3 comandos, áudio vai para lote, cada mp4/quicktime gera 1 `BaixarDocumentoMNIJob` e nenhum áudio gera job por documento. `--batch` mantém padrão 50 e `limit` padrão 5.
      Testes: `tests/Feature/Console/MediaComandosDownloadEmLoteTest.php` — `Queue::fake()`, `Storage::fake('s3')`, `expectsConfirmation(..., 'yes')`, seleção isolada dentro da transação

## Phase 7: Fluxo ponta a ponta

Antes de implementar, leia:
1. `.spec/features/baixar-documentos-mni-em-lote/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/baixar-documentos-mni-em-lote/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T14 — Testes de fluxo ponta a ponta (RNF-01, RF-12 b, RF-13 a, RF-11)
      Arquivos: `tests/Feature/DownloadDocumentosEmLoteFluxoTest.php` (novo)
      Mudança: só testes. `Queue::fake()` captura os lotes despachados por `execute()` e por `mni:baixar-documento-pendente`. Depois, `handle()`/`failed()` de cada job capturado roda com as operações SOAP mockadas e `Storage::fake('s3')`.
      Cobre: RNF-01 (a, b), RF-12 (b), RF-13 (a), RF-11, RF-10
      Acceptance criteria: N = 45, T = 20 e ids na página 1 geram 3 jobs, exatamente 3 `consultarPagina` e 0 `buscar`. Depois de `handle()` sem exceção, o documento ainda elegível entra num novo lote. Depois de `failed()`, os documentos em `erro` entram num novo lote. `execute(..., '{LOGIN}', '{SENHA}')` leva `{LOGIN}` a todas as páginas; pelo comando, o login é null.
      Testes: `tests/Feature/DownloadDocumentosEmLoteFluxoTest.php` — os cenários acima, com os helpers de `tests/Pest.php`
