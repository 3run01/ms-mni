# Relatório — campos-dos-autos-e-trava-de-atualizacao

## Metadata
- Task: T17 (fase 7 do PLAN), verificação final e relatório.
- Cobre: RNF-06; CT-04 (AC do `cmp` e do `git log`); RNF-03 (`grep`).
- Fontes: `SPEC.md` v1.1 e `PLAN.md` desta pasta; critérios em `.handoff/confirmed-input.md`.
- Branch `feat/ocr-do-processo-para-o-sistema`, verificado sobre o commit `06f3734` (fase 6), em 2026-11-09.
- Ambiente: container `ms_mni` (PHP 8.5.11, Laravel 13.35.0). Os testes usam o PostgreSQL `ms_mni_postgres` com `DatabaseTransactions`.

Seções:
- [Verificação final](#verificação-final): as 4 conferências de T17, com a saída registrada.
- [1. Rastreio RF/RNF/CT → testes](#1-rastreio-rfrnfct--testes)
- [2. Testes que mudaram de propósito (RNF-06)](#2-testes-que-mudaram-de-propósito-rnf-06)
- [3. Decisões de desenho DP-01 a DP-09](#3-decisões-de-desenho-dp-01-a-dp-09)
- [4. Limitações conhecidas LIM-01 a LIM-06](#4-limitações-conhecidas-lim-01-a-lim-06)
- [5. Pendentes externos (VE-01, VE-02)](#5-pendentes-externos-ve-01-ve-02)

## Verificação final

| # | Conferência | Esperado | Resultado |
|---|-------------|----------|-----------|
| 1 | `docker compose exec -T php php artisan test --compact` | verde | **verde**: 1634 testes aprovados, 13000 asserções, exit 0 |
| 2 | `cmp` do YAML oficial com a cópia acordada | exit 0 | **exit 0** |
| 3 | `git log --format='%h %s' 3b756f8..HEAD -- docs/api/openapi.yaml` | só o commit da fase 6 | **1 commit**: `06f3734` |
| 4 | `grep -rln "'image/webp'" app/` | 1 arquivo | **1 arquivo**: `app/Services/Ocr/FormatosOcr.php` |

### 1. Suíte (RNF-06): verde

Comando: `docker compose exec -T php php artisan test --compact`, com a saída gravada e o código de saída anexado.

```
  ............................................................................
  ............................................................................
  (22 linhas de pontos, 1 ponto por teste aprovado)

  Tests:    1634 passed (13000 assertions)
  Duration: 38.53s

EXIT=0
```

- Nenhum `FAIL` nem `⨯` na saída.
- Nenhum teste `skipped`, `risky`, `incomplete` ou `deprecated` no resumo.
- 108 arquivos `*Test.php` em `tests/`. A feature criou 8 deles e acrescentou casos a 9 que já existiam (seção 1).
- O banco de testes é o de dev (memória do projeto: os testes rodam no `ms_mni_postgres`). Os testes que contam avisos ou rodam `ocr:acompanhar` se isolam na transação com `isolarAvisosOcrDoBancoDeDev()` e `isolarProcessamentosOcrDoBancoDeDev()` (`tests/Pest.php`). Nesta rodada, nada do banco de dev vazou para as asserções.
- Nenhum teste chama o MNI, o S3 ou o n8n reais. O SOAP vem de `simularIntegracaoMni`, o S3 de `Storage::fake('s3')`, o workflow e os callbacks de `Http::fake`, e as filas de `Queue::fake` ou de uma fila síncrona de teste (RNF-06).

### 2. `cmp` com a cópia acordada (CT-04): exit 0

```
$ cmp docs/api/openapi.yaml /Users/brunosantos/orca/workspaces/sistema-juridico-lorran/fix-integracao-ia/tests/Fixtures/Mni/openapi.yaml
exit=0
$ cmp docs/api/openapi.yaml /Users/brunosantos/orca/workspaces/sistema-juridico-lorran/fix-integracao-ia/.spec/features/consulta-processual-mni-credenciais-pje/.handoff/ms-mni-openapi.yaml
exit=0
$ shasum -a 256 docs/api/openapi.yaml <cópia acordada> <2ª cópia>
461fa57a769eb8d06471634610d10fb0347572793a89bc6414e4ff22e04f464a  docs/api/openapi.yaml
461fa57a769eb8d06471634610d10fb0347572793a89bc6414e4ff22e04f464a  .../fix-integracao-ia/tests/Fixtures/Mni/openapi.yaml
461fa57a769eb8d06471634610d10fb0347572793a89bc6414e4ff22e04f464a  .../fix-integracao-ia/.spec/features/consulta-processual-mni-credenciais-pje/.handoff/ms-mni-openapi.yaml
```

O SHA-256 é o mesmo fixado no PLAN na data do plano. As 3 cópias são idênticas byte a byte, e nenhuma divergência nova (D-09 ou seguinte) foi criada.

### 3. `git log` do YAML (CT-04, Q-04): 1 commit, o da fase 6

```
$ git log --format='%h %s' 3b756f8..HEAD -- docs/api/openapi.yaml
06f3734 feat(phase-6): Contrato oficial e documentação (última fase de DEP-07)
```

O intervalo `3b756f8..HEAD` tem 7 commits: `67cdf8a` (só os arquivos de `.spec/` desta feature e o relatório da feature anterior) e as fases 1 a 6 (`7c11542`, `9a4ff3b`, `95b173e`, `b477b10`, `b6c8931`, `06f3734`). Só o da fase 6 toca o YAML. As fases 1 a 5, inclusive as de DEP-08, não tocam o YAML.

### 4. `grep` da lista legível (RNF-03): 1 arquivo

```
$ grep -rln "'image/webp'" app/
app/Services/Ocr/FormatosOcr.php
```

A mesma varredura roda como teste em `FormatosOcrTest` ("a lista legível é declarada num só arquivo de app/ (RNF-03)") e em `OcrConsistenciaVisualizarTest` ("a varredura por image/webp acha só FormatosOcr"). Os consumidores leem a lista de `FormatosOcr`:
- a contagem (`ContagemOcrService.php:23-24`);
- o pedido (`SolicitarOcrProcessoService.php:339`, `:352` e `:382-383`);
- a situação calculada (`CamposDosAutosService.php:51`);
- os jobs (`BaixarDocumentoParaOcrJob.php:113`, `:122`, `:152`, `:158`, `:230`; `EnviarDocumentoAoOcrJob.php:112`, `:200`).

## 1. Rastreio RF/RNF/CT → testes

Cada ID RIGID aponta para pelo menos 1 teste Pest verde nesta rodada, salvo RNF-07, que é revisão. Os nomes são os do `it(...)`, abreviados quando longos. "Novo" marca um arquivo criado pela feature.

Arquivos citados:

| Sigla | Arquivo | Fase |
|-------|---------|------|
| FMT | `tests/Unit/Services/Ocr/FormatosOcrTest.php` (casos novos) | 1 |
| SDML | `tests/Unit/Services/Processo/SalvarDocumentoMimetypeDaListaTest.php` (novo) | 1 |
| JAN | `tests/Unit/Services/Processo/JanelaAtualizacaoProcessoServiceTest.php` (novo) | 1 |
| CFG | `tests/Unit/ConfigPjeTest.php` (caso novo) | 1 |
| JAV | `tests/Feature/Api/JanelaAtualizacaoVisualizarTest.php` (novo) | 2 |
| CONT | `tests/Unit/Services/Ocr/ContagemOcrServiceTest.php` (casos novos e 1 alterado) | 2 |
| DOC7 | `tests/Feature/Api/DocumentoContratoDep07Test.php` (novo) | 2 |
| VCA | `tests/Feature/Api/VisualizarCamposDosAutosTest.php` (novo) | 3 |
| SOL | `tests/Unit/Services/Ocr/SolicitarOcrProcessoServiceTest.php` (casos novos) | 3 |
| BRM | `tests/Unit/Services/Processo/BaixarRevelandoMimetypeTest.php` (novo) | 3 |
| PROC | `tests/Unit/Services/Ocr/ProcessamentoOcrServiceTest.php` (casos novos) | 3 |
| BDJ | `tests/Feature/Jobs/BaixarDocumentoParaOcrJobTest.php` (casos novos) | 4 |
| EDJ | `tests/Feature/Jobs/EnviarDocumentoAoOcrJobTest.php` (casos novos) | 4 |
| FLX | `tests/Feature/OcrFluxoTest.php` (fluxos novos a–g) | 5 |
| CONS | `tests/Feature/OcrConsistenciaVisualizarTest.php` (novo) | 5 |
| SIG | `tests/Feature/OcrSigiloDoParTest.php` (fluxo novo) | 5 |
| OA7 | `tests/Unit/OpenApiContratoDep07Test.php` (novo) | 6 |
| CIF | `tests/Feature/Jobs/JobsComParCifradosTest.php` (sem mudança) | — |
| DAPI | `tests/Feature/DocsApiTest.php` (sem mudança) | — |

### Requisitos funcionais

| ID | Testes |
|----|--------|
| RF-01 | VCA: "com o processo no banco, cada documento tem exatamente as 13 chaves e os 4 campos da coluna sem conversão"; "com o processo fora do banco (MNI fake), cada documento também tem exatamente as 13 chaves"; "mimetype vazio sai \"\" e file_size null sai null"; "nenhuma das 14 chaves proibidas aparece em nenhum item, nos 2 caminhos". OA7: "descreve em Documento as 13 chaves de RF-01 com os tipos de CT-01" |
| RF-02 | VCA: "tipo vem da linha do processo, e não do mesmo código em outra linha"; "tipo sai null sem registro…"; "com o mesmo código repetido na linha, vale o tipo de menor id"; "tipo excluído não conta"; "1 e 50 documentos fazem o mesmo número de consultas, com 1 consulta a tipos_documentos em cada" |
| RF-03 | FMT: "situacaoNaLista dá o resultado da tabela de RF-03"; "situacaoNaLista sempre dá 1 dos 5 valores, nunca null, e o vazio nunca sai ignorado"; "situacaoNaLista leva situação desconhecida… a pendente". VCA: "ocr_situacao é calculada pela tabela de RF-03"; "o dataset de RF-03 sai pela tabela num processo só, e a coluna ocr_situacao não muda no banco". OA7: "descreve ocr_situacao com os 5 valores e a marca DEP-07" |
| RF-04 | DOC7: "/documento/visualizar com o processo fora do banco não tem chave ocr_…"; "/processo/documentos/listar com o processo no banco não tem chave ocr_ nos itens…"; "/processo/documentos/listar com o processo fora do banco não tem chave ocr_ nos itens…"; e os 2 casos de `/documento/visualizar` (HTML e PDF), que também conferem a ausência de `ocr_*` |
| RF-05 | CONS: "as 4 igualdades de RF-05 valem entre /processo/visualizar e a contagem do mesmo processo, para o dataset de RF-03" |
| RF-06 | DOC7: "HTML baixado com o .pdf e o .html no S3: 200, conteudo_html igual ao .html e link para o .pdf"; "PDF: 200, com a chave conteudo_html presente e nula". OA7: "descreve conteudo_html em Documento com a marca DEP-07 (RF-06, CT-02)" |
| RF-07 | CONT: "1 PDF, 1 vazio, 1 MP4 e 1 MP3, todos sem situação, dão total 2, pendentes 2 e ignorados 2"; "o vazio com erro conta em total e em com_erro, e não em ignorados"; "o vazio segue a situação do OCR como um legível e nunca conta em ignorados" (dataset das 4 situações e null); "mantém as invariantes de RF-08 em todo cenário" |
| RF-08 | SOL: "1 PDF processado e 1 vazio pendente → em_andamento, total 2, processados 1, ignorados 0, 1 download e 0 envio"; "vazio pendente já baixado com path .dat no S3 → 1 download e 0 envio"; "vazio com erro volta a pendente, gera 1 download e o pedido sai com com_erro 0"; "só vazios pendentes → abre o processamento em_andamento, e não concluido". FLX: "(a) vazio revelado PDF…"; "(d) … o pedido seguinte devolve o documento à fila de download" |
| RF-09 | BRM: "vazio pendente revelado PDF: … 1 SOAP com o par…"; "vazio já baixado com o .dat no S3: pede de novo ao tribunal, com 1 SOAP"; "revelado HTML: path .pdf e path_html .html, os 2 objetos no S3"; "revelado vídeo: grava .mp4 com 1 SOAP…"; "a recusa do par informado sobe sem embrulho…". BDJ: "vazio pendente revelado legível: … 1 SOAP com o par do dono, 1 envio…"; "vazio já baixado com o .dat no S3: o atalho do S3 não vale…"; "recusa do par no vazio…"; "falha comum no vazio: … o failed() registra o erro com o mimetype ainda vazio". FLX: (a), (b) |
| RF-10 | PROC: "revelado legível → Enviar, mimetype gravado, sem progresso e com 0 avisos". BDJ: "vazio pendente revelado legível: … 1 envio em ocr-envio e 0 avisos (RF-09, RF-10)". FLX: "(a) … 1 envio com filename .pdf, 0 avisos da revelação…"; "(b) vazio revelado HTML: o url do envio aponta para o PDF renderizado, o filename é .pdf…" |
| RF-11 | PROC: "só 1 vazio pendente revelado vídeo → Ignorado, 1 em_andamento com total T−1 e ignorados I+1 e depois 1 final concluido"; "revelado fora da lista com outro documento pendente → 1 aviso em_andamento, e o processamento continua". BDJ: "vazio revelado vídeo como único pendente: … (RF-11)". FLX: "(c) só 1 vazio revelado video/mp4: … /processo/visualizar mostra video/mp4 ignorado" |
| RF-12 | PROC: "revelado vazio → SemMimetype, erro com o texto fixo e com_erro + 1 no aviso"; "o último documento revelado vazio termina concluido com com_erro > 0". BDJ: "vazio que o tribunal também não informa: erro com o texto fixo, objeto .dat, 0 envio e aviso com com_erro +1 (RF-12)". FLX: "(d) vazio que continua vazio no download: … /processo/visualizar mostra erro…" |
| RF-13 | SOL: "mídia baixada com path nunca vai ao envio nem ao download pelo pedido (RF-13)" (AC a). BDJ: "vazio já baixado com o .dat no S3…" (AC b); "audio/mpeg baixado no S3 sem mais nada a ler: … concluido com 1 final por assinante (RF-13, Q-05)" (AC c); "audio/mpeg baixado no S3 com outro documento pendente…". EDJ: "mimetype conhecido fora da lista, baixado no S3 e sem mais nada a ler…"; "mimetype conhecido fora da lista com outro documento pendente…". PROC: os 3 casos de `concluirSeNadaResta`. FLX: os 2 casos "(g)" (regravado pela lista antes do job) |
| RF-14 | SDML: "com o mimetype da lista, o gravado fica como na tabela de RF-14" (dataset de 5); "cria o documento novo com '' quando a lista não informa o mimetype"; "com '' na lista, os demais campos do documento existente são atualizados como hoje"; "pela consulta de documentos do tribunal, um video/mp4 continua video/mp4 com '' na lista". FLX: "(e) depois do vídeo revelado, um pedido cuja lista devolve vazio…"; "(f) depois do vídeo revelado, o BaixarProcessoMNIJob com a lista devolvendo vazio…" |
| RF-15 | JAN: "no store array abre, suprime por 600 s, reabre depois e separa as linhas"; "a janela de uma linha não fecha a mesma numeração em outra linha"; "no store database faz a mesma sequência…". JAV: "enfileira 1 job em t0 com o par A e nenhum outro até a janela vencer, com qualquer par"; "as chamadas suprimidas não estendem a janela"; "o mesmo número em outra linha enfileira o seu job em t0+1"; "as chamadas de t0, t0+1 e t0+11 respondem 200 com as mesmas chaves…"; "a consulta síncrona que falha em t0 não abre a janela…". CFG: "expoe a janela de atualizacao do processo em 600 segundos, sem variavel de ambiente" |
| RF-16 | JAV: "fora do banco: 1ª chamada 1 consulta e 0 jobs; 2ª 0 e 0; 11 min depois 0 consultas e 1 job"; "/processo/consultar enfileira 1 job por chamada, com ou sem a janela aberta pelo visualizar"; "com a janela aberta pelo visualizar, o despacho do expediente entra na fila"; "o serviço do expediente e o job não citam a janela, e o job não tem trava de unicidade" (confere também `ShouldBeEncrypted`); "com um job do expediente na fila e sem janela, o visualizar enfileira o seu job com o seu par". CIF: "o job implementa ShouldBeEncrypted"; "payload do job na fila não contém o par" (dataset com `BaixarProcessoMNIJob`) |

### Contratos

| ID | Testes |
|----|--------|
| CT-01 | VCA: os 4 casos de RF-01 (13 chaves nos 2 caminhos, `file_size` inteiro JSON, `mimetype` `""`). OA7: "descreve em Documento as 13 chaves de RF-01 com os tipos de CT-01"; "só usa em GET /processo/visualizar chaves de documento descritas em Documento" |
| CT-02 | DOC7: os 2 casos de RF-06 e os 3 de RF-04. OA7: "descreve conteudo_html em Documento com a marca DEP-07 (RF-06, CT-02)" |
| CT-03 | CONT: "paraCorpo devolve só as 4 chaves do contrato" e os casos de RF-07. FLX: todo 202 é validado contra `ProcessamentoDeOcr` e todo aviso contra `AvisoDeProgressoDoOcr` do YAML oficial, com as 2 invariantes, nos fluxos (a) a (g). Os nós DEP-03 a DEP-05 seguem em `OpenApiContratoOcrTest`, verde sem mudança |
| CT-04 | OA7: "carrega o contrato oficial como YAML OpenAPI 3.1.0"; "descreve Prioridade e RepresentanteProcessual e os referencia no Processo"; "resolve todos os $ref do contrato". DAPI: "serve a spec OpenAPI em yaml"; "serve a spec atualizada com o /processo/ocr e o webhook ocr_progresso (RNF-04)". Conferências 2 e 3 deste relatório (`cmp` 0 e 1 commit) |
| CT-05 | JAV: "as chamadas de t0, t0+1 e t0+11 respondem 200 com as mesmas chaves no corpo e no documento (CT-05)". OA7: "não tem texto de DEP-08 (CT-05)" |

### Requisitos não funcionais

| ID | Testes |
|----|--------|
| RNF-01 | JAV: "com fila e cache no banco, o par B não aparece em jobs.payload, nas linhas de cache nem nos logs". JAN: "no store database faz a mesma sequência…" (linha de `cache` sem login nem senha); "com o cache lançando exceção devolve false e registra 1 warning sem o par"; "a assinatura de abrir não recebe o par do PJe". BRM: "o par é parâmetro sensível". SIG: "revelação do mimetype pelo download (vídeo e vazio)…: o par e o callback_token não vazam…". CIF e os fluxos anteriores de SIG, verdes sem mudança |
| RNF-02 | VCA: "1 e 50 documentos fazem o mesmo número de consultas, com 1 consulta a tipos_documentos em cada". CONT: "faz uma consulta só ao banco" |
| RNF-03 | FMT: "entraNoTotal vale só para os 8 legíveis e o vazio, e ehIgnorado é a negação"; "o nulo fica fora do total e conta como ignorado, como o ELSE da contagem"; "o vazio entra no total, mas continua não legível e sem extensão de envio"; "a lista legível é declarada num só arquivo de app/ (RNF-03)". CONS: "conta em total ⇔ não sai ignorado na lista ⇔ o pedido o enfileira…" (dataset de 12 mimetypes); "os 8 mimetypes legíveis do dataset são exatamente a lista legível de FormatosOcr"; "a varredura por image/webp acha só FormatosOcr". Conferência 4 deste relatório |
| RNF-04 | JAN: "confere e abre com 1 add, sem has, get nem put"; "no store array…"; "no store database…"; "lê a validade da configuração a cada chamada". JAV: a sequência de RF-15. Revisão: `JanelaAtualizacaoProcessoService::abrir` faz uma operação só, `Cache::add(chave, true, $segundos)` (`app/Services/Processo/JanelaAtualizacaoProcessoService.php:56`), sem leitura antes da escrita |
| RNF-05 | PROC: "a 2ª chamada para o mesmo documento já revelado → JaRevelado…"; "mimetype regravado pela lista antes da trava → JaRevelado…"; "vazio já em erro revelado vazio de novo → SemMimetype, sem 2º aviso"; "vazio processado revelado vazio nunca rebaixa e não avisa"; "a revelação trava primeiro o processamento aberto e depois o documento"; "a revelação é desfeita junto com os avisos quando a transação falha"; "o log da revelação leva o mimetype, o resultado e o processamento_id". BDJ: "o job 2 vezes no mesmo vazio revelado vídeo, com outro documento pendente: 1 aviso, 0 envios e 1 SOAP no total (RNF-05)"; os 2 casos "lista regrava o vazio… (JaRevelado)". FLX: "(c)" |
| RNF-06 | A suíte inteira (conferência 1): 1634 aprovados, 0 falhas; cada RF, RNF e CT acima tem pelo menos 1 teste; nenhum teste chama o MNI, o S3 ou o n8n reais |
| RNF-07 | Revisão (sem teste). Os 3 arquivos de RNF-07 citam o mimetype vazio em `total`, a `ocr_situacao` calculada e a janela de 600 s só em `/processo/visualizar`, aberta também pela consulta síncrona. `domain_rules.md` cita a regra de RF-14, e `data_model.md` cita a chave `baixar-processo:{tribunal_id}:{numero}`. Os 4 arquivos de `docs/agents/` têm o comentário de edição à mão na linha 4, e `AGENTS.md` não mudou (`git diff --stat 3b756f8..HEAD -- AGENTS.md` vazio) |

## 2. Testes que mudaram de propósito (RNF-06)

### Asserção alterada: 1 teste

| Teste | Antes | Depois | Motivo |
|-------|-------|--------|--------|
| `tests/Unit/Services/Ocr/ContagemOcrServiceTest.php:113`, "situação do OCR em documento não legível não entra nas contagens dos legíveis" | `total` 1, `ignorados` 4, sem conferir `pendentes` | `total` 2, `pendentes` 1, `ignorados` 3 | RF-07: o documento `''` com situação null sai de `ignorados` e entra em `total` como pendente. É o único teste previsto em "Testes que mudam de propósito" do PLAN |

No mesmo arquivo, o docblock de `expectInvariantesContagemOcr` passou de "cada legível numa só situação" para "cada documento do conjunto do total (legível ou vazio, RF-07)". O código do helper não mudou.

Nenhum teste dos despachos de `/processo/consultar` e do expediente mudou, como pede o RNF-06. `ConsultarProcessoControllerTest`, `FormatosOcrTest` (casos antigos), `SolicitarOcrProcessoServiceTest`, `BaixarDocumentoParaOcrJobTest`, `EnviarDocumentoAoOcrJobTest`, `MontarEnvioOcrServiceTest`, `SalvarDocumentoProcessoServiceTest`, `JobsComParCifradosTest`, `DocsApiTest`, `OpenApiContratoDep01Test` e `OpenApiContratoOcrTest` ficaram sem asserção alterada.

### Mudança sem asserção alterada

Conferido pelo `git diff --numstat 3b756f8..HEAD -- tests/`: só 3 arquivos têm linhas removidas (`OcrFluxoTest` 5, `OcrSigiloDoParTest` 2, `ContagemOcrServiceTest` 4). Todas estão abaixo ou na tabela acima.

| Arquivo | Mudança | Motivo |
|---------|---------|--------|
| `OcrWebhookControllerTest`, `BaixarDocumentoParaOcrJobTest`, `EnviarDocumentoAoOcrJobTest`, `VerificarReenvioOcrJobTest`, `ProcessamentoOcrServiceTest`, `VerificarJobAntigoOcrServiceTest` (fase 1) | `isolarAvisosOcrDoBancoDeDev()` no início do `beforeEach` | Os testes rodam no banco de dev, que tem avisos reais da homologação do OCR. Os testes que contam avisos esperam partir de 0. O delete roda dentro da transação do teste e é desfeito no rollback |
| `OcrAcompanharTest`, `OcrFluxoTest`, `OcrSigiloDoParTest` (fase 5) | `isolarProcessamentosOcrDoBancoDeDev()` no início do `beforeEach` | Um processamento real em andamento no banco de dev fazia o `ocr:acompanhar` consultar jobs reais (`GET /jobs/<uuid>`) |
| `tests/Pest.php` | Os 2 helpers acima. Os dois recusam rodar fora de uma transação | Isolamento sem apagar dados do banco de dev |
| `OcrFluxoTest` (`mniFluxoOcr`) | O fake ganhou `->descricoes`, `->revelar` e `polo => []`. O download devolve o mimetype de `->revelar` e o HTML com `conteudo`. 3 linhas do docblock e 2 do fake foram trocadas | O tribunal simulado precisa revelar um mimetype diferente do da lista (RF-09). O `polo` vazio deixa o `BaixarProcessoMNIJob` gravar a lista inteira no fluxo (f). Os fluxos antigos não usam os campos novos e mantêm o mesmo comportamento |
| `OcrSigiloDoParTest` (`mniSigilo`) | O fake ganhou `->revelar`. 1 linha do docblock e 1 do fake foram trocadas | Mesmo motivo, no fluxo novo de sigilo da revelação |

## 3. Decisões de desenho DP-01 a DP-09

Decisões do PLAN ("Desenho da solução"), tomadas dentro do FLEXIBLE da SPEC, com onde ficaram no código.

| DP | Decisão | Onde ficou |
|----|---------|------------|
| DP-01 | Uma fonte da regra: `FormatosOcr` é a única declaração dos 8 mimetypes e ganhou `MIMETYPE_NAO_INFORMADO = ''`, `SITUACAO_IGNORADO`, `entraNoTotal`, `ehIgnorado` e `situacaoNaLista`. `ehLegivel('')` continua `false` | `app/Services/Ocr/FormatosOcr.php`, usado pela contagem, pelo pedido, pelos jobs e pelo `CamposDosAutosService` (conferência 4) |
| DP-02 | A situação calculada existe só na resposta de `/processo/visualizar`: o atributo é trocado em memória e fica visível só nessa coleção. O `$hidden` do model não muda, e nada chama `save()` | `app/Services/Processo/CamposDosAutosService.php` |
| DP-03 | `tipo` em 1 consulta: `where tribunal_id` + `whereIn codigo` + `orderByDesc id` + `pluck`, que fica com o de menor `id`. `SoftDeletes` tira os excluídos. `getTipoDocumento()` não muda | `CamposDosAutosService` |
| DP-04 | Janela por `Cache::add("baixar-processo:{tribunal_id}:{numero}", true, 600)`, lendo `config('pje.atualizacao_processo.janela_segundos')`. Despacha só com `true`. A consulta síncrona bem-sucedida também abre a janela, sem job (Q-03). Uma falha do cache vira `Log::warning` e `false`, e a resposta sai 200 sem despacho. Sem `ShouldBeUnique` (Q-02) | `JanelaAtualizacaoProcessoService`, `config/pje.php`, `ConsultarProcessoController::show` |
| DP-05 | O `''` da lista nunca grava numa atualização: `firstOrCreate` e, se o registro já existia, `fill(Arr::except($valores, ['mimetype']))`. Um não vazio continua por `updateOrCreate` | `SalvarDocumentoProcessoService::salvarDocumento` |
| DP-06 | Revelação em 2 passos. Passo 1, fora de transação: `baixarRevelandoMimetype`, com 1 SOAP e o S3, sem gravar o mimetype. Passo 2, sob as travas (processamento aberto, depois documento): `registrarMimetypeRevelado`, que grava só se ainda `''` e decide `Enviar`, `Ignorado`, `SemMimetype` ou `JaRevelado` | `SalvarDocumentoProcessoService`, `ProcessamentoOcrService`, `ResultadoRevelacaoOcr` (enum novo) |
| DP-07 | Saída Q-05: `concluirSeNadaResta` encerra `concluido` com o aviso final quando não sobra pendente nem aguardando. Não grava progresso nem gera `em_andamento` | `ProcessamentoOcrService`, chamado por `BaixarDocumentoParaOcrJob` e `EnviarDocumentoAoOcrJob` |
| DP-08 | `''` no job de envio, caso inalcançável: sai sem `DELETE` nem `POST`, sem mudar a situação e com `Log::warning`. O prazo P encerra o processamento | `EnviarDocumentoAoOcrJob` (teste EDJ "mimetype vazio no envio…"). Fica em aberto a PQ-02 do PLAN (alternativa: marcar `erro`), não bloqueante |
| DP-09 | Ordem das fases e o YAML: DEP-08 nas fases 1 e 2, sem tocar o YAML; o YAML só na fase 6, a última de DEP-07; a fase 7 só verifica e grava em `.spec/` | Conferência 3 (1 commit, `06f3734`). A fase 7 grava só este relatório e o handoff |

## 4. Limitações conhecidas LIM-01 a LIM-06

Texto resumido da SPEC. Todas foram aceitas no plano e não têm correção nesta feature.

- **LIM-01** (DEP-08): o job da janela usa o par da 1ª chamada. Se o tribunal recusar esse par, o job só registra no log, e o banco fica sem atualização até a janela vencer, mesmo que outra chamada traga um par válido. É o comportamento aceito pelo AC5.
- **LIM-02** (DEP-07 (c)): um documento cujo mimetype continua vazio vai a `com_erro` (RF-12) e volta à fila em cada pedido que abre processamento (D-08). Isso dá 1 acesso ao tribunal por documento desses em cada pedido. Coberto pelo fluxo (d) de `OcrFluxoTest`.
- **LIM-03** (DEP-07 (c)): um documento vazio que é um vídeo grande pode estourar o download do OCR (3 tentativas, 300 s e 512 MB no `supervisor-ocr-download`). Nesse caso, o documento vai a `com_erro` com mimetype vazio, e não a `ignorados`.
- **LIM-04** (DEP-08): o `cache:clear` de cada deploy (`build.sh:10`) apaga as janelas abertas, e a 1ª chamada depois do deploy enfileira um job.
- **LIM-05** (DEP-08; Q-02): a janela vale só para `/processo/visualizar`. `/processo/consultar` com o processo no banco e o despacho a partir do expediente continuam enfileirando 1 job por chamada, também com a janela aberta (RF-16).
- **LIM-06** (DEP-07 (c); Q-01): a lista do tribunal não consegue mais limpar um mimetype. Se o tribunal mandar `''` para um documento que já tem mimetype não vazio, o ms-mni mantém o valor gravado (RF-14).

## 5. Pendentes externos (VE-01, VE-02)

Não são requisitos implementáveis no ms-mni (decisão do checkpoint) e não têm checkbox no `PHASES.md`. O handoff está em `.handoff/retorno-ao-sistema.md` (T18).

- **VE-01** (AC6, lado do Sistema), no worktree `/Users/brunosantos/orca/workspaces/sistema-juridico-lorran/fix-integracao-ia/`: trocar as 2 cópias do contrato pelo `docs/api/openapi.yaml` oficial e rodar `./vendor/bin/sail test tests/Feature/Servicos/MniTest.php` até ficar verde. Pela conferência 2, a troca não muda nenhum byte. **Pendente**.
- **VE-02** (AC7), homologação depois do deploy do ms-mni, no processo 0000294-58.2023.8.03.0011 (linha 2): "Lido pela IA" com "Resumir" e "Perguntar" habilitados; a mesma contagem no cabeçalho e no chat; o tipo e a data de juntada; o áudio em "Mídias" tocando. O vídeo é homologado em outro processo da linha 1 com `video/mp4` `baixado`, por exemplo 0039922-84.2023.8.03.0001 (Q-06). **Pendente**.

Antes da homologação, o rollout do PLAN pede: merge, deploy (o `build.sh` roda `cache:clear`, LIM-04) e `php artisan horizon:terminate`, para os workers carregarem os jobs novos.

Ficam também as 2 Open Questions do PLAN, não bloqueantes: PQ-01 (o AC do expediente é provado pelo mesmo despacho de `ConsultarExpedienteService.php:48`, sem rodar `execute()`) e PQ-02 (DP-08).
