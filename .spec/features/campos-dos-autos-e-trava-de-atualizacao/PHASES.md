# Phases: campos-dos-autos-e-trava-de-atualizacao

Gerado por /plan a partir de PLAN.md (SPEC v1.1): view executável para `./ralph.sh .spec/features/campos-dos-autos-e-trava-de-atualizacao/PHASES.md`. VE-01 e VE-02 (lado do Sistema) ficam só no PLAN, sem checkbox. Só a fase 6 toca `docs/api/openapi.yaml` (CT-04, Q-04).

## Phase 1: Fundações

Antes de implementar, leia:
1. `.spec/features/campos-dos-autos-e-trava-de-atualizacao/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/campos-dos-autos-e-trava-de-atualizacao/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T01 — Conjunto do total e situação calculada em FormatosOcr (RF-03, RNF-03)
      Arquivos: `app/Services/Ocr/FormatosOcr.php`, `tests/Unit/Services/Ocr/FormatosOcrTest.php`
      Mudança: DP-01. Constantes `MIMETYPE_NAO_INFORMADO = ''` e `SITUACAO_IGNORADO = 'ignorado'`. `entraNoTotal(?string)`: legível ou `''`. `ehIgnorado(?string)`: a negação, com `null` incluído. `situacaoNaLista(?string $mimetype, ?string $situacao): string`: ignorado → `ignorado`; senão `enviado`/`processado`/`erro` como estão e o resto, `null` inclusive, `pendente` (o critério de `ContagemOcrService.php:43`). `MIMETYPES_LEGIVEIS`, `ehLegivel` e `extensaoDoFilename` não mudam. Comentário pt-BR do porquê.
      Cobre: RF-03, RF-05 (base), RNF-03
      Acceptance criteria: o dataset de 10 linhas de RF-03 dá o resultado da tabela; o resultado é sempre 1 dos 5 valores, nunca `null`, e `''` nunca sai `ignorado`. No dataset de 12 mimetypes, `entraNoTotal` é verdadeiro só para os 8 legíveis e `''`, e `ehIgnorado` é a negação. Os testes atuais ficam verdes sem mudança (`ehLegivel('')` = `false`). `grep -rln "'image/webp'" app/` → só `FormatosOcr.php`.
      Testes: `tests/Unit/Services/Ocr/FormatosOcrTest.php` — `--filter=FormatosOcrTest`
- [ ] T02 — A gravação da lista não troca mimetype não vazio por '' (RF-14)
      Arquivos: `app/Services/Processo/SalvarDocumentoProcessoService.php` (`salvarDocumento`, l. 55-77), `tests/Unit/Services/Processo/SalvarDocumentoMimetypeDaListaTest.php` (novo)
      Mudança: DP-05. Com o mimetype da lista igual a `''`: `firstOrCreate($chave, $valores)` e, se já existia, `fill(Arr::except($valores, ['mimetype']))->save()`. Com outro valor: `updateOrCreate` como hoje. Os demais campos, o `null` da lista e o retorno não mudam. Comentário pt-BR (Q-01). É o ponto único das 4 gravações da lista (`ProcessoService.php:64`, `:111`, `:184`).
      Cobre: RF-14 (dataset), LIM-06
      Acceptance criteria: dataset gravado / lista → depois: `video/mp4`/`''` → `video/mp4`; `application/pdf`/`''` → `application/pdf`; `''`/`application/pdf` → `application/pdf`; `application/pdf`/`text/html` → `text/html`; `''`/`''` → `''`. Documento novo com `''` é criado. `descricao` e `data_juntada` são atualizados com `''` na lista. Por `ProcessoService::consultarDocumentos` (`simularIntegracaoMni`), um `video/mp4` com `''` na lista continua `video/mp4`. `SalvarDocumentoProcessoServiceTest` e `ProcessoServiceListaCompletaTest` verdes sem mudança.
      Testes: `tests/Unit/Services/Processo/SalvarDocumentoMimetypeDaListaTest.php` — `--filter=SalvarDocumentoMimetypeDaListaTest`
- [ ] T03 — Janela de atualização: configuração e serviço (RF-15, RNF-01, RNF-04)
      Arquivos: `config/pje.php` (bloco `atualizacao_processo`), `app/Services/Processo/JanelaAtualizacaoProcessoService.php` (novo), `tests/Unit/Services/Processo/JanelaAtualizacaoProcessoServiceTest.php` (novo), `tests/Unit/ConfigPjeTest.php`
      Mudança: DP-04. `config/pje.php` ganha `'atualizacao_processo' => ['janela_segundos' => 600]`, sem env, com comentário pt-BR (DEP-08, só `/processo/visualizar`, LIM-04). O serviço tem `chave(int, string)` estático (`baixar-processo:{tribunal_id}:{numero}`) e `abrir(int $tribunalId, string $numeroProcesso): bool` com `Cache::add(chave, true, max(1, config))`, lendo a config a cada chamada. Exceção do cache → `Log::warning` só com tribunal, número e classe → `false`. Sem parâmetro de par. Comentário do porquê do `add` e de não usar `ShouldBeUnique` (`CallQueuedHandler.php:91-93`, Q-02).
      Cobre: RF-15 (janela), RNF-04, RNF-01 (valor sem par)
      Acceptance criteria: store `array`: `abrir(1, N)` → `true`; de novo → `false`; +599 s → `false`; +601 s → `true`; `abrir(2, N)` → `true`. Store `database`: a mesma sequência, inclusive a reabertura depois da linha vencida, e a linha de `cache` sem login nem senha. `Cache::spy()`: 1 `add` e nenhum `has`/`get`/`put`. Cache lançando → `false` e 1 `warning` sem o par. `config('pje.atualizacao_processo.janela_segundos')` = 600.
      Testes: `tests/Unit/Services/Processo/JanelaAtualizacaoProcessoServiceTest.php`; `tests/Unit/ConfigPjeTest.php`

## Phase 2: Janela no visualizar, contagem e contrato do documento

Antes de implementar, leia:
1. `.spec/features/campos-dos-autos-e-trava-de-atualizacao/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/campos-dos-autos-e-trava-de-atualizacao/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T04 — Janela de 10 min no GET /processo/visualizar (RF-15, RF-16, CT-05, RNF-01)
      Arquivos: `app/Http/Controllers/Api/ConsultarProcessoController.php` (construtor e `show`, l. 57-125), `tests/Feature/Api/JanelaAtualizacaoVisualizarTest.php` (novo)
      Mudança: injetar `JanelaAtualizacaoProcessoService`. Com o processo no banco, o `BaixarProcessoMNIJob::dispatch` de `:107` só roda se `abrir((int) tribunal_id, $numero_processo)` devolver `true`, com o par da chamada. Com o processo fora do banco, depois do `consultarNumero` sem exceção, `abrir(...)` sem despacho (Q-03); a falha lança antes. A resposta é a mesma. `index`/`buscarPorNumeroProcesso`, `ConsultarExpedienteService` e `BaixarProcessoMNIJob` não mudam. O controller decide o despacho, e a regra do cache fica no serviço.
      Cobre: RF-15, RF-16, CT-05, RNF-01, RNF-04
      Acceptance criteria: (`Queue::fake`, `travel`) t0 A → 1 job; t0+1 B → total 1, com o par A; t0+9 → nenhum novo; t0+11 B → total 2. Outra linha em t0+1 → o seu job. As 3 respostas são 200 com as mesmas chaves. Sync falhando em t0 → sem janela, e com o processo gravado depois, t0+1 → 1 job. Fora do banco: 1ª chamada 1 consulta e 0 jobs; 2ª 0 e 0; +11 min 0 consultas e 1 job. `/processo/consultar` 2× → 2 jobs; visualizar +1 min → 3; consultar com a janela aberta → 4. Com a janela aberta, o despacho igual a `ConsultarExpedienteService.php:48` entra na fila; o serviço e o job não citam a janela, e o job não é `ShouldBeUnique`. Job do expediente na fila e sem janela → visualizar enfileira o seu job, com o seu par. RNF-01 (`queue` e `cache` = `database`): 0 ocorrências do par B em `jobs.payload`, `cache` e logs. `ConsultarProcessoControllerTest`, `JobsComParCifradosTest` e `OcrSigiloDoParTest` verdes sem mudança.
      Testes: `tests/Feature/Api/JanelaAtualizacaoVisualizarTest.php` — `--filter=JanelaAtualizacaoVisualizarTest`
- [ ] T05 — Contagem com o mimetype vazio em total (RF-07, CT-03, RNF-02)
      Arquivos: `app/Services/Ocr/ContagemOcrService.php`, `tests/Unit/Services/Ocr/ContagemOcrServiceTest.php`
      Mudança: o conjunto passa a ser `(mimetype IN (<8>) OR mimetype = ?)`, com `FormatosOcr::MIMETYPES_LEGIVEIS` e `MIMETYPE_NAO_INFORMADO`, em `total`, `processados`, `com_erro`, `pendentes` e `aguardando`. `ignorados` continua o `ELSE` do mesmo `CASE`. A consulta continua uma só, e o docblock perde o "inclusive vazio". `ContagemOcr`/`paraCorpo()` não mudam.
      Cobre: RF-07, CT-03, RNF-02
      Acceptance criteria: 1 PDF, 1 `''`, 1 MP4 e 1 MP3, todos null → `total` 2, `pendentes` 2, `ignorados` 2, demais 0. `''` com `erro` em `total` e `com_erro`, não em `ignorados`. Dataset com `''` em cada situação e em null mantém as 2 invariantes. "faz uma consulta só ao banco" verde. O teste de `:113-132` passa a esperar `total` 2, `pendentes` 1, `ignorados` 3 (o único antigo que muda).
      Testes: `tests/Unit/Services/Ocr/ContagemOcrServiceTest.php` — `--filter=ContagemOcrServiceTest`
- [ ] T06 — Contrato do documento fora de /processo/visualizar (RF-04, RF-06, CT-02)
      Arquivos: `tests/Feature/Api/DocumentoContratoDep07Test.php` (novo); nada em `app/`
      Mudança: só testes, com `Storage::fake('s3')`, `buildTemporaryUrlsUsing` (como `DocumentoControllerTest.php:156`) e `simularIntegracaoMni`: `/documento/visualizar` de HTML `baixado` com `{id}.pdf` e `{id}.html` e de PDF; `/documento/visualizar` e `/processo/documentos/listar` com `ocr_*` gravados, com o processo no banco e fora dele.
      Cobre: RF-04, RF-06, CT-02
      Acceptance criteria: HTML: 200, `conteudo_html` igual ao `.html` e `link` para o `.pdf`. PDF: 200, com a chave `conteudo_html` presente e `null`. Nenhuma chave `ocr_*` no `documento` de `/documento/visualizar` nem nos itens de `/processo/documentos/listar`, nos 2 caminhos. Nenhuma coluna `conteudo_html`/`ocr_*` muda no banco.
      Testes: `tests/Feature/Api/DocumentoContratoDep07Test.php` — `--filter=DocumentoContratoDep07Test`

## Phase 3: Campos dos autos, pedido, revelação e transições

Antes de implementar, leia:
1. `.spec/features/campos-dos-autos-e-trava-de-atualizacao/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/campos-dos-autos-e-trava-de-atualizacao/PLAN.md` — decomposição completa, dependências e riscos
3. `/Users/brunosantos/orca/workspaces/sistema-juridico-lorran/fix-integracao-ia/tests/Fixtures/Mni/openapi.yaml` — schema `Documento` (l. 1373-1463) do contrato acordado, só para leitura: não copie para `docs/api/` nesta fase (CT-04)

- [ ] T07 — Campos dos autos e situação do OCR em /processo/visualizar (RF-01, RF-02, RF-03, CT-01, RNF-02)
      Arquivos: `app/Services/Processo/CamposDosAutosService.php` (novo), `app/Http/Controllers/Api/ConsultarProcessoController.php` (`select` de l. 82-84 e chamada antes do `response()->json`), `tests/Feature/Api/VisualizarCamposDosAutosTest.php` (novo)
      Mudança: o `select` ganha `data_juntada`, `usuario_juntada_arquivo`, `mimetype`, `file_size`, `ocr_situacao`; `id` e `processo_id` ficam, ocultos. `CamposDosAutosService::preparar(?Processo)`: `tipo` em 1 consulta (DP-03: `whereIn('codigo')->orderByDesc('id')->pluck('descricao', 'codigo')`, sem os excluídos); `ocr_situacao` = `FormatosOcr::situacaoNaLista(...)` e `makeVisible('ocr_situacao')` só nesta coleção (DP-02); `file_size` para `int` se o PDO devolver string; nunca `save()`. O `show` chama `preparar` nos 2 caminhos. O `$hidden` do model e `getTipoDocumento()` não mudam.
      Cobre: RF-01, RF-02, RF-03, CT-01, RNF-02, RNF-03
      Acceptance criteria: processo no banco, documento com `data_juntada` "2026-03-06 10:00:00", `usuario_juntada_arquivo` "LUCAS MONTEIRO PEREIRA", `mimetype` "application/pdf", `file_size` 655360 → exatamente as 13 chaves, com os 4 valores iguais às colunas (`file_size` inteiro). Fora do banco (MNI fake) → as 13 chaves. `''`/null → `"mimetype": ""`, `"file_size": null`. Nenhuma das 14 chaves proibidas. `tipo`: "Certidão" com o tipo de outra linha presente; `null` sem tipo; "Certidão" entre `id` 10 e `id` 11; excluído não conta. 1 e 50 documentos → mesmo número de consultas e 1 consulta a `tipos_documentos` em cada (token com `last_used_at` = agora). O dataset de RF-03 sai pela tabela, e a coluna `ocr_situacao` fica igual no banco. `ConsultarProcessoControllerTest`, `RecusaDoParVisualizarTest` e `JanelaAtualizacaoVisualizarTest` verdes.
      Testes: `tests/Feature/Api/VisualizarCamposDosAutosTest.php` — `--filter=VisualizarCamposDosAutosTest`
- [ ] T08 — Pedido de OCR com o mimetype vazio (RF-08, RF-13 atalho do pedido)
      Arquivos: `app/Services/Ocr/SolicitarOcrProcessoService.php` (`legiveis()` l. 355-360, `abrir()` l. 225-254, `despachar()` l. 323-350), `tests/Unit/Services/Ocr/SolicitarOcrProcessoServiceTest.php`
      Mudança: `legiveis()` → `doConjuntoDoTotal()` (legível ou `''`), usado na devolução do `com_erro`, em "a enfileirar" e em "a consultar"; "a enfileirar" lê também `mimetype`. Em `despachar()`, o atalho direto a `EnviarDocumentoAoOcrJob` exige `FormatosOcr::ehLegivel`, além de `baixado` e `path`; o vazio vai sempre a `BaixarDocumentoParaOcrJob` com o par do dono. O log ganha `downloads_sem_mimetype`. A carona não muda.
      Cobre: RF-08, RF-13 (AC a)
      Acceptance criteria: 1 PDF `processado` e 1 `''` `pendente` → 202 `em_andamento`, `total` 2, `processados` 1, `ignorados` 0, 1 job `ocr-download` e 0 `ocr-envio`. `''` `baixado` com `.dat` → 1 `ocr-download`, 0 `ocr-envio`. `''` com `erro` → volta a pendente, 1 `ocr-download` e `com_erro` 0 no 202. Só vazios pendentes → `em_andamento`. PDF `baixado` com `path` → direto a `ocr-envio`, com 0 SOAP. Testes atuais verdes sem mudança.
      Testes: `tests/Unit/Services/Ocr/SolicitarOcrProcessoServiceTest.php` — `--filter=SolicitarOcrProcessoServiceTest`
- [ ] T09 — Download que revela o mimetype (RF-09, parte do binário)
      Arquivos: `app/Services/Processo/SalvarDocumentoProcessoService.php` (`baixarRevelandoMimetype` novo; `?string $mimetype = null` em `salvarConteudoOutros` l. 365-385 e `salvarConteudoAudio` l. 740), `tests/Unit/Services/Processo/BaixarRevelandoMimetypeTest.php` (novo)
      Mudança: DP-06, passo 1. `baixarRevelandoMimetype(ProcessoDocumento, #[\SensitiveParameter] ?string $login, #[\SensitiveParameter] ?string $senha): string` faz 1 `consultarDocumento()`; as exceções tipadas sobem sem embrulho, e o resto vai em `\Exception('Erro ao processar documento sem mimetype: ...', 0, $e)`. Lê `trim((string) ($documentoMni->mimetype ?? ''))`, só da resposta. Grava pelo revelado, sem trocar o atributo `mimetype`: HTML → `salvarConteudoHtml`; PDF → `salvarConteudoPdf`; `audio/*` → `salvarConteudoAudio(..., $revelado)`; o resto, vídeo e `''` inclusive → `salvarConteudoOutros(..., $revelado)` (`mp4`; `dat`). Depois `marcarComoBaixado` e devolve o revelado, sem gravar `mimetype` (fica com T10). Os parâmetros novos com `null` mantêm o lote e os `download*` iguais.
      Cobre: RF-09 (serviço)
      Acceptance criteria: `''` `pendente` com o fake devolvendo PDF → devolve "application/pdf", `path` `.pdf`, `baixado`, 1 SOAP com o par, e a coluna `mimetype` continua `''`. `''` `baixado` com `.dat` no S3 → 1 SOAP. HTML → `.pdf` + `path_html` `.html`, os 2 no S3. "video/mp4" → `.mp4`; `''` → `.dat`. Recusa com o par informado → `recusaNaCadeia` = `true`, e o documento não muda. `SalvarDocumentoProcessoServiceTest` e `BaixarDocumentosEmLoteServiceTest` verdes sem mudança.
      Testes: `tests/Unit/Services/Processo/BaixarRevelandoMimetypeTest.php` — `--filter=BaixarRevelandoMimetypeTest`
- [ ] T10 — Transições da revelação e saída Q-05 (RF-10, RF-11, RF-12, RF-13, RNF-05)
      Arquivos: `app/Services/Ocr/ProcessamentoOcrService.php`, `app/Services/Ocr/ResultadoRevelacaoOcr.php` (novo enum), `tests/Unit/Services/Ocr/ProcessamentoOcrServiceTest.php`
      Mudança: enum `Enviar`, `Ignorado`, `SemMimetype`, `JaRevelado`. `registrarMimetypeRevelado(ProcessoDocumento, string $mimetype)` pelo `comTravas` (processamento aberto, depois documento). Mimetype já não vazio → `JaRevelado`, sem aviso. Senão, grava o revelado não vazio e decide: legível → `Enviar`, sem progresso e sem aviso; fora da lista → `progredir` se houver processamento aberto (aviso, P, final `concluido`) e `Ignorado`; `''` → `erro` com `ocr_erro` "mimetype não informado pelo tribunal" (processado nunca rebaixa), `progredir` e `SemMimetype`. Avisos `afterCommit`. Log com mimetype, resultado e `processamento_id`. `concluirSeNadaResta(int)`: trava o processamento e, em andamento sem pendente nem aguardando, chama `finalizar(concluido)`; não grava `ultimo_progresso_em`, não avisa `em_andamento` e é idempotente.
      Cobre: RF-10, RF-11, RF-12, RF-13 (Q-05), RNF-05
      Acceptance criteria: PDF → `Enviar`, mimetype gravado, 0 avisos, `ultimo_progresso_em` igual. Só 1 `''` (T, I) revelado vídeo → `Ignorado`, 1 `em_andamento` por assinante com `total` T−1 e `ignorados` I+1, depois 1 final `concluido`, com as invariantes em cada aviso. Com outro pendente → 1 aviso e `em_andamento`. `''` → `SemMimetype`, `erro` com o texto fixo e `com_erro` +1. 2ª chamada → `JaRevelado` e 0 avisos. Sem processamento aberto → mimetype gravado e 0 avisos. `concluirSeNadaResta`: nada resta → `concluido`, 1 final e 0 `em_andamento`; com pendente → `false` e 0 avisos; encerrado → `false`. Testes atuais verdes.
      Testes: `tests/Unit/Services/Ocr/ProcessamentoOcrServiceTest.php` — `--filter=ProcessamentoOcrServiceTest`

## Phase 4: Jobs do OCR

Antes de implementar, leia:
1. `.spec/features/campos-dos-autos-e-trava-de-atualizacao/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/campos-dos-autos-e-trava-de-atualizacao/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T11 — Job de download do OCR com o mimetype vazio (RF-09 a RF-13, RNF-05)
      Arquivos: `app/Jobs/BaixarDocumentoParaOcrJob.php` (`handle()` l. 72-136, `temObjetoNoS3()` l. 172-177), `tests/Feature/Jobs/BaixarDocumentoParaOcrJobTest.php`
      Mudança: itens 1 a 3 iguais. 4: mimetype não vazio fora da lista, não revelado neste job → log, `concluirSeNadaResta` e `return` (0 SOAP, 0 envio, 0 aviso intermediário; Q-05). 5: `''` → `baixarRevelandoMimetype` com o par do dono, no mesmo `try/catch` de hoje (recusa → `encerrarPorRecusa`; o resto relança; `failed()` igual); depois `registrarMimetypeRevelado`: `Enviar` → `despacharEnvio`; `Ignorado`/`SemMimetype` → `return`; `JaRevelado` → relê e segue os itens 4, 6 ou 7, sem nova chamada SOAP. 6: o atalho do S3 exige `FormatosOcr::ehLegivel` em `temObjetoNoS3()`. 7: legível → `baixarDocumento` como hoje. Cifra, fila, `$tries`, `backoff()` e `$timeout` iguais.
      Cobre: RF-09, RF-10, RF-11, RF-12, RF-13, RNF-05
      Acceptance criteria: `''` revelado PDF → `mimetype` PDF, `path` `.pdf`, `baixado`, 1 SOAP com o par do dono e 1 envio em `ocr-envio`. `''` `baixado` com `.dat` → 1 SOAP. Vídeo → 0 envio e `path` `.mp4`. `''` → `erro` com o texto fixo e 0 envio. `audio/mpeg` `baixado` no S3 → 0 SOAP, 0 envio e 0 `em_andamento`; nada resta → `concluido` com 1 final por assinante; com outro pendente → 0 avisos e `em_andamento`. Recusa no `''` → `falhou` por papel, sem relançar, documento `''` pendente. Job 2× no `''` revelado vídeo, com outro pendente → 1 aviso, 0 envios e 1 SOAP. Testes atuais verdes, inclusive o do payload cifrado.
      Testes: `tests/Feature/Jobs/BaixarDocumentoParaOcrJobTest.php` — `--filter=BaixarDocumentoParaOcrJobTest`
- [ ] T12 — Envio ao OCR só com mimetype legível (RF-13, Q-05)
      Arquivos: `app/Jobs/EnviarDocumentoAoOcrJob.php` (`handle()`, depois do item 2, l. 98-102), `tests/Feature/Jobs/EnviarDocumentoAoOcrJobTest.php`
      Mudança: item novo antes da conferência do S3. Com `! FormatosOcr::ehLegivel($documento->mimetype)`, nenhum `DELETE`/`POST`, nenhum `registrarErroDefinitivo` e nenhum aviso intermediário: não vazio → log e `concluirSeNadaResta` (DP-07); `''` → `Log::warning` e `return` (DP-08). `MontarEnvioOcrService` não muda.
      Cobre: RF-13
      Acceptance criteria: `audio/mpeg` `baixado` no S3 → 0 requisições ao workflow, sem exceção e documento igual. Sem outro pendente → `concluido` com 1 final por assinante; com outro pendente → 0 avisos e `em_andamento`. `''` → 0 requisições, 0 exceções e documento pendente. `EnviarDocumentoAoOcrJobTest` e `MontarEnvioOcrServiceTest` atuais verdes sem mudança.
      Testes: `tests/Feature/Jobs/EnviarDocumentoAoOcrJobTest.php` — `--filter=EnviarDocumentoAoOcrJobTest`

## Phase 5: Ponta a ponta e consistência

Antes de implementar, leia:
1. `.spec/features/campos-dos-autos-e-trava-de-atualizacao/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/campos-dos-autos-e-trava-de-atualizacao/PLAN.md` — decomposição completa, dependências e riscos

- [ ] T13 — Fluxos ponta a ponta do mimetype vazio (AC4; RF-08 a RF-14, RNF-05)
      Arquivos: `tests/Feature/OcrFluxoTest.php`; se um fluxo falhar, a correção entra no ponto da falha, nos arquivos de T02 e T08 a T12
      Mudança: só testes, no padrão do arquivo (pedido pela rota, `FilaSyncFluxoOcr`, `simularIntegracaoMni`, `Http::fake`, retorno pelo `webhook_url`; todo 202 e aviso com as 2 invariantes e o schema). Fluxos: (a) `''` revelado PDF; (b) `''` revelado HTML; (c) só 1 `''` revelado vídeo; (d) `''` que segue `''` e o pedido seguinte; (e) RF-14 no pedido depois de (c); (f) RF-14 no `BaixarProcessoMNIJob` depois de (c); (g) RF-13 com o `''` regravado vídeo pela lista antes do job (capturado por `Queue::fake([BaixarDocumentoParaOcrJob::class])`), com e sem outro pendente.
      Cobre: RF-08, RF-09, RF-10, RF-11, RF-12, RF-13, RF-14, RNF-05
      Acceptance criteria: (a) 1 SOAP, 1 `POST documents/process` com `filename` `{id_documento}.pdf` e 0 avisos da revelação; o `success` → aviso e final. (b) `url` do envio para o `.pdf` e `filename` `.pdf`. (c) 0 `POST`, 1 `em_andamento` por assinante com `total` −1 e `ignorados` +1, depois `concluido`; `/processo/visualizar` mostra "video/mp4" e `ignorado`. (d) `erro` com o texto fixo, `com_erro` +1, 0 `POST`, `/processo/visualizar` com `erro`, e o pedido seguinte com 1 job `ocr-download`. (e) segue `video/mp4`, `ignorados` igual e 0 download dele. (f) `/processo/visualizar` com "video/mp4" e `ignorado`. (g) 0 SOAP, 0 `POST`, 0 `em_andamento`, `concluido` com 1 final por assinante; com outro pendente, 0 avisos e `em_andamento`. Os fluxos atuais ficam verdes.
      Testes: `tests/Feature/OcrFluxoTest.php` — `--filter=OcrFluxoTest`
- [ ] T14 — Consistência lista × contagem × pedido e sigilo da revelação (RF-05, RNF-03, RNF-01)
      Arquivos: `tests/Feature/OcrConsistenciaVisualizarTest.php` (novo), `tests/Feature/OcrSigiloDoParTest.php`
      Mudança: só testes. RF-05: dataset de RF-03 num processo, `GET /processo/visualizar` × `ContagemOcrService::contar`. RNF-03: dataset de 12 mimetypes (8 legíveis, `''`, `video/mp4`, `audio/mpeg`, `application/zip`), cada um num processo `pendente` com `ocr_situacao` null; contagem × lista × pedido (`Queue::fake`); varredura de `app/**/*.php` por `'image/webp'`. RNF-01: fluxo de revelação (vídeo e `''`) no `OcrSigiloDoParTest`, com a coleta que o arquivo já faz.
      Cobre: RF-05, RNF-03, RNF-01
      Acceptance criteria: as 4 igualdades de RF-05 valem (`processado` = `processados`, `erro` = `com_erro`, `ignorado` = `ignorados`, não `ignorado` = `total`). Para cada mimetype: em `total` ⇔ não `ignorado` na lista ⇔ enfileirado pelo pedido (download ou envio). A varredura acha só `app/Services/Ocr/FormatosOcr.php`. 0 ocorrências do par em logs, respostas, linhas e payloads no fluxo de revelação, com `app.debug` `true` e `false`. Os fluxos atuais do `OcrSigiloDoParTest` ficam verdes.
      Testes: `tests/Feature/OcrConsistenciaVisualizarTest.php`; `tests/Feature/OcrSigiloDoParTest.php`

## Phase 6: Contrato oficial e documentação (última fase de DEP-07)

Antes de implementar, leia:
1. `.spec/features/campos-dos-autos-e-trava-de-atualizacao/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/campos-dos-autos-e-trava-de-atualizacao/PLAN.md` — decomposição completa, dependências e riscos
3. `/Users/brunosantos/orca/workspaces/sistema-juridico-lorran/fix-integracao-ia/tests/Fixtures/Mni/openapi.yaml` — contrato acordado, copiado sem edição para `docs/api/openapi.yaml` (CT-04)

- [ ] T15 — Contrato oficial igual à cópia acordada (CT-04)
      Arquivos: `docs/api/openapi.yaml`, `tests/Unit/OpenApiContratoDep07Test.php` (novo)
      Mudança: antes de copiar, `shasum -a 256` da cópia acordada = `461fa57a769eb8d06471634610d10fb0347572793a89bc6414e4ff22e04f464a`, e `cmp` com `.../fix-integracao-ia/.spec/features/consulta-processual-mni-credenciais-pje/.handoff/ms-mni-openapi.yaml` sai 0. Se falhar, parar sem copiar e registrar (divergência nova). Senão, `cp` da cópia acordada sobre `docs/api/openapi.yaml`, sem edição. Teste novo (`Yaml::parseFile`, sem ler fora do repo): `openapi` 3.1.0; `Documento` com as 13 chaves de RF-01 e os tipos de CT-01; `ocr_situacao` com o `enum` dos 5 valores e `x-dependencia: DEP-07`; `conteudo_html` presente; `Prioridade` e `RepresentanteProcessual` existem, e os `$ref` resolvem; sem a string `DEP-08`; as chaves dos documentos de um `GET /processo/visualizar` real são propriedades de `Documento`.
      Cobre: CT-04, CT-01, CT-02, CT-03, CT-05, RF-06 (contrato)
      Acceptance criteria: `cmp docs/api/openapi.yaml /Users/brunosantos/orca/workspaces/sistema-juridico-lorran/fix-integracao-ia/tests/Fixtures/Mni/openapi.yaml` sai 0. `OpenApiContratoDep07Test` passa. `DocsApiTest`, `OpenApiContratoDep01Test` e `OpenApiContratoOcrTest` verdes sem mudança. Nenhuma fase anterior alterou o YAML.
      Testes: `tests/Unit/OpenApiContratoDep07Test.php`; `--filter='DocsApiTest|OpenApiContrato'`
- [ ] T16 — Documentação docs/agents (RNF-07)
      Arquivos: `docs/agents/domain_rules.md`, `docs/agents/api_contracts.md`, `docs/agents/architecture.md`, `docs/agents/data_model.md` (seção "Cache", l. 231-243)
      Mudança: em cada arquivo, atualizar o comentário de edição à mão (l. 4) para citar DEP-07 e DEP-08. `domain_rules.md`: "Readable formats and counts" (`''` em `total`, `ignorados` só conhecido fora da lista, situação calculada); a linha de `GET /processo/visualizar` em "Processo persistence and caching" (janela de 600 s com `Cache::add`, só nessa rota, aberta também pela consulta síncrona; `/processo/consultar` e o expediente seguem 1 job por chamada); a regra de RF-14; o "Download" do OCR (revelação, 3 decisões, Q-05, atalho só legível); LIM-01 a LIM-06. `api_contracts.md`: 13 chaves com exemplo, `ocr_situacao` nunca `null`, `conteudo_html` no contrato, semântica de CT-03 e a janela na linha de `/processo/visualizar`. `architecture.md`: macro flow do OCR com a revelação e a janela no fluxo de consulta. `data_model.md`: a chave `baixar-processo:{tribunal_id}:{numero}` em "App-level `Cache::` use".
      Cobre: RNF-07
      Acceptance criteria: os 3 arquivos de RNF-07 citam o mimetype vazio em `total`, `ocr_situacao` calculada e a janela de 600 s só em `/processo/visualizar`, aberta também pela consulta síncrona. `domain_rules.md` cita a regra de RF-14. `data_model.md` cita a chave da janela. `AGENTS.md` não muda.
      Testes: revisão (só documentação)

## Phase 7: Verificação final e handoff ao Sistema

Antes de implementar, leia:
1. `.spec/features/campos-dos-autos-e-trava-de-atualizacao/SPEC.md` — requisitos RIGID que esta fase cobre
2. `.spec/features/campos-dos-autos-e-trava-de-atualizacao/PLAN.md` — decomposição completa, dependências e riscos
3. `.spec/features/campos-dos-autos-e-trava-de-atualizacao/.handoff/confirmed-input.md` — critérios 6 e 7 (VE-01, VE-02)

- [ ] T17 — Verificação final e relatório (RNF-06, CT-04, RNF-03)
      Arquivos: `.spec/features/campos-dos-autos-e-trava-de-atualizacao/RELATORIO.md` (novo)
      Mudança: rodar e registrar: `docker compose exec -T php php artisan test --compact`; `cmp` do YAML com a cópia acordada; `git log --format='%h %s' 3b756f8..HEAD -- docs/api/openapi.yaml`; `grep -rln "'image/webp'" app/`. O relatório traz a tabela RF-01..RF-16, RNF-01..RNF-07 e CT-01..CT-05 → testes, os testes que mudaram de propósito com o motivo, DP-01 a DP-09, LIM-01 a LIM-06 e os pendentes externos VE-01 e VE-02.
      Cobre: RNF-06, CT-04, RNF-03
      Acceptance criteria: a suíte verde, com a saída resumida no relatório; `cmp` sai 0; o `git log` mostra exatamente 1 commit, o da fase 6; o `grep` acha 1 arquivo; cada ID RIGID aponta para pelo menos 1 teste, salvo RNF-07 (revisão).
      Testes: `docker compose exec -T php php artisan test --compact`
- [ ] T18 — Handoff de volta ao Sistema (VE-01, VE-02, O-01 a O-06)
      Arquivos: `.spec/features/campos-dos-autos-e-trava-de-atualizacao/.handoff/retorno-ao-sistema.md` (novo)
      Mudança: gravar o handoff para o worktree `/Users/brunosantos/orca/workspaces/sistema-juridico-lorran/fix-integracao-ia/`, sem código. Conteúdo: o que foi entregue (DEP-07 a–c, DEP-08) e a branch; o SHA-256 de `docs/api/openapi.yaml` e o `cmp` com a cópia acordada; VE-01 (trocar as 2 cópias e rodar `./vendor/bin/sail test tests/Feature/Servicos/MniTest.php`); VE-02 (homologação no processo 0000294-58.2023.8.03.0011, linha 2: "Lido pela IA", mesma contagem, tipo e data de juntada, áudio em "Mídias"; o vídeo em 0039922-84.2023.8.03.0001, linha 1, Q-06); a semântica de CT-03 (`total` pode cair entre avisos); LIM-01, LIM-04 e LIM-05; e O-01 a O-06 com o texto da SPEC.
      Cobre: VE-01, VE-02 (handoff), O-01 a O-06
      Acceptance criteria: o arquivo tem as seções "Entregue", "Contrato", "VE-01", "VE-02", "Semântica das contagens", "Limitações" e "Observações"; O-01 a O-06 aparecem pelo id; o SHA-256 registrado é igual ao de `shasum -a 256 docs/api/openapi.yaml`; nenhum arquivo fora de `.spec/features/campos-dos-autos-e-trava-de-atualizacao/` muda.
      Testes: revisão; `shasum -a 256 docs/api/openapi.yaml`
