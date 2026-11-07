# Relatório — ocr-do-processo-para-o-sistema

## Metadata
- Task: T24 (fase 11 do PLAN), verificação final e roteiro de homologação.
- Cobre: RNF-05; RNF-02 e RNF-03 (homologação); RF-01 AC (d).
- Fontes: `SPEC.md` v1.7 e `PLAN.md` desta pasta.
- Branch `feat/ocr-do-processo-para-o-sistema`, verificado sobre o commit `4bfe484` (fase 10), em 2026-11-06.
- Ambiente da verificação: container `ms_mni` (PHP 8.5.11, Laravel 13.35.0), banco de testes PostgreSQL `ms_mni_postgres` com `DatabaseTransactions`.

Seções:
- [Verificação final](#verificação-final): saídas registradas de `composer test`, `npm run typecheck`, `route:list` e `schedule:list`.
- [1. Rastreio RF/RNF/CT → testes](#1-rastreio-rfrnfct--testes)
- [2. Testes que mudaram de propósito (RNF-05)](#2-testes-que-mudaram-de-propósito-rnf-05)
- [3. Divergências D-01 a D-08 e Open Questions para o Sistema](#3-divergências-d-01-a-d-08-e-open-questions-para-o-sistema)
- [4. Roteiro de homologação](#4-roteiro-de-homologação)
- [5. Itens manuais pendentes](#5-itens-manuais-pendentes)

## Verificação final

### `composer test` (RNF-05): verde

Comando: `docker compose exec -T php composer test`. O script do `composer.json` roda `php artisan config:clear` e depois `php artisan test`.

```
   INFO  Configuration cache cleared successfully.

   PASS  Tests\Unit\ConfigOcrTest
   ...
  Tests:    1435 passed (10758 assertions)
  Duration: 35.66s

docker compose exec -T php composer test  0,06s user 0,10s system 0% cpu 36,396 total
EXIT=0
```

- 100 arquivos de teste, 100 `PASS` e nenhum `FAIL`.
- 0 testes `skipped`, `risky`, `incomplete` ou `deprecated` na saída.
- A contagem por classe está abaixo. A soma dá 1435, com cada caso de dataset contado à parte.

<details>
<summary>Testes aprovados por classe (saída de 2026-11-06)</summary>

| Classe | Testes aprovados |
|---|---|
| `Tests\Unit\ConfigOcrTest` | 10 |
| `Tests\Unit\ConfigPjeTest` | 5 |
| `Tests\Unit\ExampleTest` | 1 |
| `Tests\Unit\Exceptions\ConsultaMNISemSucessoExceptionTest` | 5 |
| `Tests\Unit\Exceptions\ExcecoesClassificadasMniTest` | 6 |
| `Tests\Unit\Exceptions\MNIExceptionTest` | 4 |
| `Tests\Unit\HorizonConfigOcrTest` | 7 |
| `Tests\Unit\HorizonConfigTest` | 1 |
| `Tests\Unit\Http\ErroComCodigoTest` | 19 |
| `Tests\Unit\InjectCredenciaisPjePadraoTest` | 13 |
| `Tests\Unit\Models\CredencialPjeTest` | 4 |
| `Tests\Unit\Models\ProcessoDocumentoTest` | 5 |
| `Tests\Unit\Models\ProcessoExportacaoTest` | 7 |
| `Tests\Unit\Models\ProcessoMonitoramentoTest` | 5 |
| `Tests\Unit\Models\ProcessoOcrModelsTest` | 14 |
| `Tests\Unit\OpenApiContratoDep01Test` | 9 |
| `Tests\Unit\OpenApiContratoOcrTest` | 12 |
| `Tests\Unit\Services\Callback\CallbackNotifierTest` | 6 |
| `Tests\Unit\Services\Callback\CallbackUrlValidatorTest` | 11 |
| `Tests\Unit\Services\ExportacaoProcessoServiceTest` | 11 |
| `Tests\Unit\Services\IntegracaoBaseTest` | 9 |
| `Tests\Unit\Services\MNI\ClassificarFalhaMniServiceTest` | 25 |
| `Tests\Unit\Services\MNI\ConsultarDocumentoServiceTest` | 38 |
| `Tests\Unit\Services\MNI\ConsultarProcessoServiceTest` | 19 |
| `Tests\Unit\Services\MNI\ConsultarProcessosServiceTest` | 23 |
| `Tests\Unit\Services\MNI\NormalizarDocumentoMniServiceTest` | 33 |
| `Tests\Unit\Services\MNI\PadronizarMensagemMniServiceTest` | 13 |
| `Tests\Unit\Services\MNI\ParSensivelNoTraceTest` | 25 |
| `Tests\Unit\Services\Monitoramento\CredencialPjeServiceTest` | 5 |
| `Tests\Unit\Services\Ocr\AvisarAssinantesOcrServiceTest` | 21 |
| `Tests\Unit\Services\Ocr\ClienteWorkflowOcrTest` | 46 |
| `Tests\Unit\Services\Ocr\ContagemOcrServiceTest` | 12 |
| `Tests\Unit\Services\Ocr\FormatosOcrTest` | 40 |
| `Tests\Unit\Services\Ocr\MontarEnvioOcrServiceTest` | 19 |
| `Tests\Unit\Services\Ocr\ProcessamentoOcrServiceTest` | 47 |
| `Tests\Unit\Services\Ocr\SolicitarOcrProcessoServiceTest` | 26 |
| `Tests\Unit\Services\Ocr\VerificarJobAntigoOcrServiceTest` | 28 |
| `Tests\Unit\Services\Processo\BaixarDocumentosEmLoteFallbackTest` | 15 |
| `Tests\Unit\Services\Processo\BaixarDocumentosEmLoteServiceTest` | 26 |
| `Tests\Unit\Services\Processo\DetectarAlteracoesProcessoServiceTest` | 5 |
| `Tests\Unit\Services\Processo\EnfileirarDownloadDocumentosServiceTest` | 17 |
| `Tests\Unit\Services\Processo\ParametrosDownloadEmLoteTest` | 18 |
| `Tests\Unit\Services\Processo\ProcessoServiceListaCompletaTest` | 13 |
| `Tests\Unit\Services\Processo\TravaDownloadDocumentoTest` | 9 |
| `Tests\Unit\Services\SalvarDocumentoProcessoServiceTest` | 55 |
| `Tests\Feature\Api\CallbackHostsLiberadosTest` | 8 |
| `Tests\Feature\Api\ConsultarProcessoControllerTest` | 35 |
| `Tests\Feature\Api\DocumentoControllerTest` | 38 |
| `Tests\Feature\Api\DownloadProcessoControllerTest` | 8 |
| `Tests\Feature\Api\MonitoramentoProcessoControllerTest` | 31 |
| `Tests\Feature\Api\OcrProcessoControllerTest` | 49 |
| `Tests\Feature\Api\OcrWebhookControllerTest` | 33 |
| `Tests\Feature\Api\RecusaDoParVisualizarTest` | 24 |
| `Tests\Feature\Api\TribunalControllerTest` | 3 |
| `Tests\Feature\Api\ValidateApiTokenTest` | 8 |
| `Tests\Feature\ApiTokenCrudTest` | 9 |
| `Tests\Feature\ApiTokenModelTest` | 8 |
| `Tests\Feature\AppearanceTest` | 3 |
| `Tests\Feature\Auth\LoginPageTest` | 2 |
| `Tests\Feature\Console\ExportacoesReenviarWebhookTest` | 4 |
| `Tests\Feature\Console\MNIBaixarDocumentoPendenteTest` | 6 |
| `Tests\Feature\Console\MediaComandosDownloadEmLoteTest` | 7 |
| `Tests\Feature\Console\MonitoramentosDespacharTest` | 5 |
| `Tests\Feature\Console\MonitoramentosLimparExecucoesTest` | 3 |
| `Tests\Feature\Console\MonitoramentosReenviarWebhookTest` | 4 |
| `Tests\Feature\Console\OcrAcompanharTest` | 33 |
| `Tests\Feature\DashboardMetricasServiceTest` | 5 |
| `Tests\Feature\DashboardTest` | 8 |
| `Tests\Feature\DesacoplarSimTest` | 2 |
| `Tests\Feature\DocsApiTest` | 3 |
| `Tests\Feature\DownloadDocumentosEmLoteFluxoTest` | 6 |
| `Tests\Feature\ExampleTest` | 1 |
| `Tests\Feature\ExportacaoPipelineTest` | 1 |
| `Tests\Feature\Jobs\BaixarDocumentoMNIJobTest` | 2 |
| `Tests\Feature\Jobs\BaixarDocumentoParaOcrJobTest` | 24 |
| `Tests\Feature\Jobs\BaixarDocumentosMNIEmLoteJobTest` | 13 |
| `Tests\Feature\Jobs\BaixarProcessoMNIJobTest` | 1 |
| `Tests\Feature\Jobs\ConsultarAsyncJobsTest` | 9 |
| `Tests\Feature\Jobs\EnviarAvisoOcrJobTest` | 23 |
| `Tests\Feature\Jobs\EnviarDocumentoAoOcrJobTest` | 34 |
| `Tests\Feature\Jobs\EnviarParaS3ExportacaoJobTest` | 4 |
| `Tests\Feature\Jobs\EnviarWebhookDownloadJobTest` | 2 |
| `Tests\Feature\Jobs\EnviarWebhookMonitoramentoJobTest` | 7 |
| `Tests\Feature\Jobs\ExecutarMonitoramentoProcessoJobTest` | 11 |
| `Tests\Feature\Jobs\GerarPdfExportacaoJobTest` | 4 |
| `Tests\Feature\Jobs\JobsComParCifradosTest` | 43 |
| `Tests\Feature\Jobs\SalvarDocumentoSemBinarioTest` | 4 |
| `Tests\Feature\Jobs\VerificarReenvioOcrJobTest` | 30 |
| `Tests\Feature\Migrations\DownloadedAtTest` | 1 |
| `Tests\Feature\Migrations\OcrTabelasTest` | 11 |
| `Tests\Feature\Migrations\PreencherSiglaGrauTribunaisTest` | 13 |
| `Tests\Feature\Migrations\SchemaLocalTest` | 2 |
| `Tests\Feature\MonitoramentoDashboardTest` | 11 |
| `Tests\Feature\OcrFluxoTest` | 11 |
| `Tests\Feature\OcrSigiloDoParTest` | 11 |
| `Tests\Feature\ProcessoConsultaTest` | 20 |
| `Tests\Feature\ProcessoDocumentoVisualizarTest` | 7 |
| `Tests\Feature\SalvarDocumentoDownloadedAtTest` | 2 |
| `Tests\Feature\TribunalCrudTest` | 30 |
| `Tests\Feature\UserCrudTest` | 16 |

</details>

### `npm run typecheck`: sem mudança

```
> typecheck
> tsc --noEmit

EXIT=0
```

`git diff main..HEAD -- resources/ package.json tsconfig.json` vem vazio. A feature não toca o front.

### `php artisan route:list --path=api -v`: confere

```
  POST       api/ocr/webhook .... ocr.webhook › Api\OcrWebhookController@store
             ⇂ api
             ⇂ App\Http\Middleware\ForcarRespostaJson
             ⇂ App\Http\Middleware\ValidarAssinaturaWebhookOcr
  POST       api/processo/ocr ................ Api\OcrProcessoController@store
             ⇂ api
             ⇂ App\Http\Middleware\ValidateApiToken
             ⇂ App\Http\Middleware\ForcarRespostaJson
```

| Rota | Esperado | Resultado |
|---|---|---|
| `POST api/processo/ocr` | com `ValidateApiToken` e sem `InjectCredenciaisPjePadrao` (RF-02) | Confere |
| `POST api/ocr/webhook` | sem `ValidateApiToken`; credencial da URL assinada (CT-04) | Confere: só `ForcarRespostaJson` e `ValidarAssinaturaWebhookOcr` |

As rotas de consulta seguem com `ValidateApiToken` + `InjectCredenciaisPjePadrao`, e as de monitoramento só com `ValidateApiToken`, como antes.

### `php artisan schedule:list`: confere

```
  0    4 * * *  php artisan mni:consultar-orgao ......... Next Due: em 5 horas
  0    4 * * *  php artisan cnj:consultar-classes ....... Next Due: em 5 horas
  0    5 * * 0  php artisan cnj:consultar-assuntos ........ Next Due: em 1 dia
  0    3 * * *  php artisan mni:consultar-tipos-documentos  Next Due: em 4 horas
  */30 * * * *  php artisan monitoramentos:despachar .. Next Due: em 4 minutos
  30   4 * * *  php artisan monitoramentos:limpar-execucoes  Next Due: em 5 horas
  *    * * * *  php artisan ocr:acompanhar .......... Next Due: em 45 segundos
```

`ocr:acompanhar` roda a cada minuto (`* * * * *`), como pede RF-13 (R2-3). `withoutOverlapping` e `runInBackground` são conferidos em `tests/Feature/Console/OcrAcompanharTest.php` ("agenda ocr:acompanhar a cada minuto, sem sobreposição e em segundo plano").

## 1. Rastreio RF/RNF/CT → testes

Todos os caminhos são relativos à raiz do repo. Os nomes entre aspas são os do `it()` do Pest, às vezes abreviados com "…". Cada linha aponta para pelo menos 1 teste. As exceções são os itens de homologação, que levam a marca **[H]** e o passo da seção 4.

### Requisitos funcionais

| ID | Testes | Homologação |
|---|---|---|
| RF-01 | `tests/Feature/Api/RecusaDoParVisualizarTest.php`: "responde 403 credencial_pje_recusada quando o tribunal recusa o par do cliente" (3 formas cruas × 4 caminhos = 12 casos, com 1 SOAP e o par do cliente, sem `error`/`line`/`file`) e "segue com 500 sem codigo quando o tribunal recusa o par padrão" (12 controles). `tests/Feature/Api/DocumentoControllerTest.php`: "visualizar HTML legado com o par do cliente recusado responde 403 com codigo", "… com processo não encontrado responde 404 com codigo", "… com o par padrão recusado responde 500 sem codigo", "… sem par usa a credencial do tribunal" e "… reconsulta com o par do cliente e responde 200 com o conteudo_html". `tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php`: "baixarDocumento de HTML legado chama downloadHTML com o par…" e "… repassa o par e mantém a exceção classificada na cadeia" | **[H]** AC (d): passo H-1 |
| RF-02 | `tests/Feature/Api/OcrProcessoControllerTest.php`: "registra a rota só com ValidateApiToken e ForcarRespostaJson, fora de InjectCredenciaisPjePadrao", "sem token válido responde 401 com o corpo exato, 0 SOAP e 0 jobs" (AC a) e "com o par padrão configurado e o corpo sem o par do pedido, responde 422…" (AC b). `tests/Feature/OcrFluxoTest.php`: todo fluxo confere 100% do SOAP com o par do dono (AC c). `tests/Feature/Jobs/BaixarDocumentoParaOcrJobTest.php`: "o payload em jobs.payload não tem o par, e o job decifrado chama o MNI com o par do dono". `tests/Unit/Services/Processo/ProcessoServiceListaCompletaTest.php`: "consulta só a linha informada, com o par do pedido…" | — |
| RF-03 | `tests/Feature/Api/OcrProcessoControllerTest.php`: "campo ausente ou inválido responde 422 com a chave em errors, 0 SOAP, 0 jobs, 0 linhas do OCR e sem o par nem o callback_token no corpo" (dataset por campo), "aceita callback_url com exatamente 2048 caracteres (D-05)" e "responde o 422 em JSON mesmo sem Accept: application/json" | — |
| RF-04 | `tests/Unit/Services/Ocr/SolicitarOcrProcessoServiceTest.php`: "abre o processamento: 3 PDF, HTML, PNG, MP4 e MP3 → total 5, ignorados 2, em_andamento e 5 downloads…" (AC a) e "processa só a linha tribunal_id…" (AC c). `tests/Unit/Services/Processo/ProcessoServiceListaCompletaTest.php`: "… dataReferencia nulo e documentos incluídos", "grava o documento juntado há 120 dias…" (AC b), "grava o processo na linha informada, e não na outra linha da mesma sigla" e "não enfileira download de binário…". `tests/Feature/Api/OcrProcessoControllerTest.php`: "pedido válido responde 202 ProcessamentoDeOcr com exatamente as chaves do contrato…" | — |
| RF-05 | `tests/Feature/Api/OcrProcessoControllerTest.php`: "a falha da consulta responde com o status e o corpo exatos, sem error, line, file nem o par, com 1 SOAP, 0 processamentos e 0 jobs" (403, 404 e 500) e "na outra falha, registra Log::error só com a linha, o número e a classe da exceção". `tests/Unit/Services/Ocr/SolicitarOcrProcessoServiceTest.php`: "a falha da consulta sobe com a exceção respectiva…" e "a recusa com processo já gravado também não abre nada" | — |
| RF-06 | `tests/Unit/Services/Ocr/SolicitarOcrProcessoServiceTest.php`: "carona: contagens atuais, 0 SOAP, 0 jobs e o par da carona em 0 linhas e 0 payloads" (AC a), "2 pedidos em sequência: 1 processamento, 1 consultarProcesso…" (AC c), "a corrida perdida na abertura vira carona do processamento aberto (índice único, D2)", "com a trava presa por outro pedido e W = 1, desiste em menos de 2 s…" (AC d) e "carona com o mesmo callback reusa o assinante pelo hash". `tests/Feature/Api/OcrProcessoControllerTest.php`: "o 2º pedido … entra como carona…" e "com W = 1 e a decisão presa por outro pedido, responde 500 em menos de 2 s…" (AC d via HTTP). `tests/Feature/OcrFluxoTest.php`: "carona com 4 de 10 processados: … o carona recebe 6 avisos em_andamento e 1 final" (AC a, b). `tests/Feature/Migrations/OcrTabelasTest.php`: "um 2º processamento em_andamento do mesmo processo lança UniqueConstraintViolationException" | — |
| RF-07 | `tests/Unit/Services/Ocr/SolicitarOcrProcessoServiceTest.php`: "300 processados → concluido com 0 jobs; com 2 novos no MNI → total 302 e só os 2 enfileirados" (AC a, b), "8 processados e 2 em erro → com_erro 0, em_andamento e só os 2 enfileirados…" (AC d) e "só vídeo e áudio → total 0, ignorados 2, concluido…" (AC c). `tests/Feature/Api/OcrProcessoControllerTest.php`: "sem documento a ler responde 202 concluido, sem processamento e sem job" | — |
| RF-08 | `tests/Unit/Services/Ocr/ContagemOcrServiceTest.php`: "3 PDF, 1 HTML, 1 PNG, 1 MP4 e 1 MP3 dão total 5 e ignorados 2…", "ocr_situacao nulo conta como pendente…" e "mantém as invariantes de RF-08 em todo cenário". `tests/Unit/Services/Ocr/FormatosOcrTest.php`: "os mimetypes legíveis são exatamente PDF, HTML e as 6 imagens do workflow". `tests/Feature/OcrFluxoTest.php`: todo 202 e todo aviso conferem `processados + com_erro ≤ total` e `total + ignorados` = documentos da linha | — |
| RF-09 | `tests/Feature/Jobs/BaixarDocumentoParaOcrJobTest.php`: "depois do download do OCR, /documento/visualizar responde 200 com link e 0 SOAP" (AC a), "falha sem loginfailed em todas as tentativas: … com_erro + 1 só no failed()", "o payload em jobs.payload não tem o par…" (AC c), "HTML legado pendente vai ao workflow pelo .pdf gravado, com 0 SOAP…" (AC d) e "documento baixado com o objeto no S3 vai direto ao envio, com 0 SOAP" (AC e). `tests/Feature/OcrFluxoTest.php`: "1 de 5 documentos falha sempre no download do MNI: com_erro 1, processados 4…" (AC b). `tests/Unit/Services/Ocr/SolicitarOcrProcessoServiceTest.php`: "pendente já baixado com path → envio direto, sem job de download" (AC e). `tests/Feature/Jobs/EnviarDocumentoAoOcrJobTest.php`: "sem o objeto no S3: com_erro no OCR e binário em erro… (D3)" | — |
| RF-10 | `tests/Feature/Jobs/BaixarDocumentoParaOcrJobTest.php`: "recusa do par em cada forma crua: encerra falhou por papel, sem relançar, sem com_erro…" e "10 documentos com recusa a partir do 4º download: 0 SOAP depois, com_erro sem mudança, 7 pendentes e 1 final falhou por papel (RF-10 AC)". `tests/Feature/OcrFluxoTest.php`: "recusa do par a partir do 4º download de 10: … um par novo abre outro processamento com os 7 pendentes". `tests/Unit/Services/Ocr/ProcessamentoOcrServiceTest.php`: "encerrarPorRecusa deixa os pendentes pendentes…" e "encerrarPorRecusa chamado 2 vezes gera 1 final por assinante". `tests/Unit/Services/MNI/ClassificarFalhaMniServiceTest.php`: "reconhece a recusa do par na própria exceção e na cadeia de previous" | — |
| RF-11 | `tests/Unit/Services/Ocr/MontarEnvioOcrServiceTest.php`: "o corpo de 1 PDF tem exatamente as 5 chaves e o metadata de RF-11, sem escritorio_id" (AC a), "HTML vai pelo PDF já gravado…" (AC d), "cada imagem vai com a extensão do mimetype…" (AC e), "data_juntada sai em ISO-8601 com fuso e nivel_sigilo null sai 0" (AC f) e "o link temporário vale pelo menos P = 25 min depois do envio" (AC g). `tests/Feature/Jobs/EnviarDocumentoAoOcrJobTest.php`: "o job recebe só os ids, e o payload na fila não tem o par (RF-11 AC b)", "PDF: 1 POST com Authorization Bearer e o corpo de 5 chaves…", "422: 1 tentativa só, e com_erro cresce 1 na hora", "500 em todas: 5 tentativas com o backoff…" (AC c) e "o webhook_url enviado ainda é aceito com o relógio 20 min (A) adiante" (AC g). `tests/Unit/Services/Ocr/FormatosOcrTest.php`: "extensaoDoFilename dá a extensão de RF-11 para cada mimetype legível" | — |
| RF-12 | `tests/Feature/Api/OcrWebhookControllerTest.php`, 1 teste por caso: "401: sem credencial válida…", "422 com credencial e 401 sem credencial… (AC c)", "404: job_id desconhecido…", "success: …", "error: …", "success sobre erro: processados + 1 e com_erro − 1… (AC a)", "error sobre processado: 200, sem mudança… (AC b)", "repetido: …" e "documento devolvido à fila: o job antigo recebe 404… (AC d)". Também "aceita o retorno sem X-API-Token…". `tests/Unit/Services/Ocr/ProcessamentoOcrServiceTest.php`: as regras de `aplicarRetorno` | — |
| RF-13 | `tests/Feature/Console/OcrAcompanharTest.php`: "success na consulta depois de P: concluido… (RF-13 AC a)", "running na consulta depois de P: falhou sem codigo… (RF-13 AC b)", "com o último progresso há 24 min, 0 GET…; aos 25 min, a consulta e o aviso final (RF-13 AC c)", "um progresso aos 10 min empurra o fim para os 35 min…", "na rodada de P, consulta cada documento aguardando retorno, inclusive de processamento anterior…" (item 1) e "agenda ocr:acompanhar a cada minuto…". `tests/Unit/Services/Ocr/ProcessamentoOcrServiceTest.php`: "encerrarPorPrazo termina falhou sem codigo e é idempotente". `tests/Unit/ConfigOcrTest.php`: "usa os padrões da SPEC para os prazos" | — |
| RF-14 | `tests/Feature/OcrFluxoTest.php`: "5 documentos e 2 assinantes desde o início: 5 avisos em_andamento e 1 final concluido por assinante, na URL exata e com o token de cada um" (AC a; cada aviso valida contra o schema `AvisoDeProgressoDoOcr` do `docs/api/openapi.yaml`, AC b). `tests/Feature/Jobs/EnviarAvisoOcrJobTest.php`: "com 2 assinantes, cada aviso vai à URL exata do callback_url, com a query e o X-API-Token do próprio assinante" e "o corpo tem exatamente as 7 chaves…". `tests/Unit/Services/Ocr/AvisarAssinantesOcrServiceTest.php`: "avisarProgresso cria um aviso em_andamento por assinante ativo, com as 7 chaves do contrato" | — |
| RF-15 | `tests/Unit/Services/Ocr/ProcessamentoOcrServiceTest.php`: "3 processados e 1 erro geram 1 final concluido {total 4, processados 3, com_erro 1} por assinante" (AC a) e "retorno depois do final, sem outro processamento aberto, gera 0 avisos" (AC c). `tests/Unit/Services/Ocr/AvisarAssinantesOcrServiceTest.php`: "avisarFinal falhou com recusa dá credencial_pje_recusada ao dono e credencial_de_outro_pedido_recusada a cada carona" (AC b) e "avisarFinal chamado 2 vezes gera 1 final por assinante". `tests/Feature/OcrFluxoTest.php`: "503 na 1ª tentativa do 3º aviso: o final é entregue antes da nova tentativa…" (AC d). `tests/Feature/Jobs/EnviarAvisoOcrJobTest.php`: "final entregue antes da nova tentativa: a tentativa pendente do aviso anterior sai com 0 requisições (Q-03)" | — |
| RF-16 | `tests/Feature/Jobs/EnviarAvisoOcrJobTest.php`: "declara 5 tentativas, o backoff do webhook de monitoramento e timeout 30", "410 no 2º aviso encerra o assinante: 0 requisições depois, nem o final, e o outro recebe todos" (AC a), "4xx encerra o assinante e falha o job sem nova tentativa", "5xx e falha de rede relançam…" e "503 em todas: cada tentativa sai, e o aviso seguinte ainda é tentado" (AC b) | — |
| RF-17 | `tests/Unit/Services/Callback/CallbackUrlValidatorTest.php`: "aceita host liberado com http fora de produção, sem diferenciar maiúsculas…", "ignora a lista de hosts liberados em produção" e "rejeita fora de produção com a lista vazia ou com outro host". `tests/Feature/Api/CallbackHostsLiberadosTest.php`: o mesmo em `POST /api/processo/monitoramentos` e no `CallbackNotifier`. `tests/Feature/Api/OcrProcessoControllerTest.php`: "RF-17: em local, com host.docker.internal na lista, aceita o callback http e o aviso vai para essa URL" (AC a) e "RF-17: o callback http de host liberado responde 422 em production, com a lista vazia e com host fora da lista" (AC b, c) | — |
| RF-18 | `tests/Unit/ConfigOcrTest.php`: "A é menor que P e menor que M na configuração padrão" (AC i). `tests/Unit/Services/Ocr/SolicitarOcrProcessoServiceTest.php`: "só um enviado com idade < A → abre em_andamento, com 0 jobs…" (item 4), "enviado com idade A + 1 → 1 VerificarReenvioOcrJob, 0 envios…" e "depois de um processamento falho pela recusa, outro par abre outro processamento… (RF-18 AC a)". `tests/Feature/Jobs/VerificarReenvioOcrJobTest.php`: AC c, d, l, m, n, o e p. `tests/Feature/Console/OcrAcompanharTest.php`: AC f, h, k, j, l, m, n, o e p na rodada. `tests/Feature/Jobs/EnviarDocumentoAoOcrJobTest.php`: "com_erro devolvido pela reabertura com job_id: 1 DELETE antes do POST; sem job_id: 0 DELETE (RF-18 AC g)" e "DELETE 500 em todas as tentativas: 0 POST e com_erro no failed()". `tests/Feature/OcrFluxoTest.php`: AC a, c, e, j e o, ponta a ponta. `tests/Unit/Services/Ocr/VerificarJobAntigoOcrServiceTest.php`: a tabela de decisão de D9 | — |

### Requisitos não funcionais

| ID | Testes | Homologação |
|---|---|---|
| RNF-01 | `tests/Feature/OcrSigiloDoParTest.php`, com `app.debug` ligado e desligado em cada fluxo: "pedido novo, carona, download, envio, retorno, avisos e o pedido concluido…", "download com falha definitiva e o job em failed_jobs…", "download com recusa do par no meio…", "envio, consulta do job antes do reenvio, retorno, aviso e acompanhamento até o fim por P…", "pedidos com 401, 403, 404, 422 e 500…" e "por reflection, todo job que leva o par é ShouldBeEncrypted…". `tests/Feature/Jobs/JobsComParCifradosTest.php`, para os 6 jobs antigos: "payload do job na fila não contém o par", "data.command decifrado desserializa no mesmo job com o par", "job decifrado chama o MNI com o mesmo par" e "payload antigo sem cifra continua processável". `tests/Unit/Services/MNI/ParSensivelNoTraceTest.php`. `tests/Feature/Migrations/OcrTabelasTest.php`: "nenhuma tabela nova tem coluna para o login ou a senha do PJe". `tests/Unit/Models/ProcessoOcrModelsTest.php`: "ProcessoOcrAssinante grava o callback_token cifrado e lê o texto" | — |
| RNF-02 | `tests/Unit/HorizonConfigOcrTest.php`: "em produção, ocr-download + ocr-envio somam 20 maxProcesses", "os 3 supervisores do OCR existem em production, local e *…" e "supervisor-monitoramento continua com maxProcesses 1 em todos os ambientes". `tests/Unit/HorizonConfigTest.php`, sem mudança | **[H]** 300 PDFs em ≤ 10 min (H-4), 300 enviados em ≤ X = 5 min (H-3), a busca acha um trecho (H-6) e as pré-condições P-1 a P-3 |
| RNF-03 | `tests/Unit/ConfigOcrTest.php`: "W fica abaixo dos 60 s do cliente". `tests/Feature/Api/OcrProcessoControllerTest.php`: "com W = 1 e a decisão presa por outro pedido, responde 500 em menos de 2 s…" e a carona com 0 SOAP | **[H]** cada `POST` em < 60 s (H-2, H-5) |
| RNF-04 | `tests/Unit/OpenApiContratoOcrTest.php`: "tem os nós DEP-03 e DEP-05 iguais à cópia acordada, ignorando o texto livre, com maxLength 2048 em callback_url (D-05)", "resolve todo $ref…", "mantém as respostas comuns…", "lista as divergências D-01 a D-08 no info do contrato" e "documenta o /ocr/webhook… (D-01)". `tests/Feature/DocsApiTest.php`: "serve a spec atualizada com o /processo/ocr e o webhook ocr_progresso (RNF-04)". `tests/Unit/OpenApiContratoDep01Test.php`, sem mudança | — |
| RNF-05 | A suíte inteira: `composer test` verde, com 1435 testes ([Verificação final](#verificação-final)). Os testes antigos de DEP-01, monitoramento, exportação, download em lote e callback passam. As únicas asserções mudadas estão na seção 2 | — |
| RNF-06 | Esta tabela: cada RF e cada RNF de 01 a 04 tem pelo menos 1 teste Pest. Os 27 arquivos novos de teste usam `Http::fake`, `Storage::fake('s3')`, `simularIntegracaoMni` (binding de `IntegracaoBase`) ou `Queue::fake`, e nenhum chama o MNI, o n8n ou o S3 reais. A documentação (`AGENTS.md` e `docs/agents/`) foi atualizada na fase 10 (T23) | — |

### Contratos

| ID | Testes |
|---|---|
| CT-01 | `tests/Feature/Api/OcrProcessoControllerTest.php` (202, 401, 403, 404, 422 e 500); `tests/Unit/OpenApiContratoOcrTest.php` (nós `/processo/ocr`, `SolicitacaoDeOcr` e `ProcessamentoDeOcr`) |
| CT-02 | `tests/Feature/Jobs/EnviarAvisoOcrJobTest.php`; `tests/Unit/Services/Ocr/AvisarAssinantesOcrServiceTest.php`; `tests/Feature/OcrFluxoTest.php` (cada aviso contra o schema `AvisoDeProgressoDoOcr`); `tests/Unit/OpenApiContratoOcrTest.php` (webhook `ocr_progresso`) |
| CT-03 | `tests/Unit/Services/Ocr/ClienteWorkflowOcrTest.php` ("envio manda o corpo com Bearer para documents/process…", "… batem com o contrato do workflow"); `tests/Unit/Services/Ocr/MontarEnvioOcrServiceTest.php`; `tests/Feature/Jobs/EnviarDocumentoAoOcrJobTest.php` |
| CT-04 | `tests/Feature/Api/OcrWebhookControllerTest.php` (rota fora de `X-API-Token`, URL assinada válida por M, 200, 401, 404 e 422) |
| CT-05 | `tests/Unit/Services/Ocr/ClienteWorkflowOcrTest.php` ("consulta manda GET com Bearer para jobs/{id}…", "o enum SituacaoJobOcr lê exatamente os status do contrato do workflow"); `tests/Unit/Services/Ocr/VerificarJobAntigoOcrServiceTest.php`; `tests/Feature/Console/OcrAcompanharTest.php` |
| CT-06 | `tests/Feature/Api/RecusaDoParVisualizarTest.php`; `tests/Feature/Api/DocumentoControllerTest.php` (HTML legado); `tests/Unit/Models/ProcessoOcrModelsTest.php` ("ProcessoDocumento::toArray() não tem nenhuma chave ocr_*": nenhum campo novo nas respostas) |
| CT-07 | `tests/Unit/Services/Ocr/ClienteWorkflowOcrTest.php` ("cancelamento manda DELETE com Bearer e sem corpo para jobs/{id}/chunks…"); `tests/Feature/Jobs/EnviarDocumentoAoOcrJobTest.php` ("com ocr_job_id_cancelar: 1 DELETE do job antigo antes de 1 POST…", "DELETE com falha relança, faz 0 POST…"); `tests/Feature/Jobs/VerificarReenvioOcrJobTest.php` |

### Ressalvas da cobertura
- RF-06 AC (c), "dois pedidos simultâneos": o Pest roda num processo só e não tem 2 requisições em paralelo de verdade. Três testes cobrem o caso. A trava (`Cache::lock` com `block(W)`) cobre os "2 pedidos em sequência". O índice único parcial e a conversão em carona cobrem "a corrida perdida na abertura vira carona". A trava presa por outro dono cobre W. A corrida real fica para a homologação (H-5).
- RNF-01 no Redis/Horizon: os testes leem `jobs.payload` e `failed_jobs` no driver `database`. O payload que o Horizon guarda no Redis é o mesmo `payload` cifrado pelo `ShouldBeEncrypted`. A leitura direta no Redis fica no passo H-7.

## 2. Testes que mudaram de propósito (RNF-05)

Só os testes de RF-01 (HTML legado, NC-2) mudaram de asserção, como o PLAN previa. Nenhum teste de monitoramento, exportação, download em lote, callback ou DEP-01 perdeu ou trocou asserção.

| Arquivo | Antes (em `main`) | Depois | Motivo |
|---|---|---|---|
| `tests/Feature/Api/DocumentoControllerTest.php` | "visualizar HTML legado sem path_html nem conteudo_html responde 500 com a mensagem do wrapper quando a reconsulta pela credencial do tribunal falha" (dataset: login recusado e processo não encontrado). O teste conferia que a reconsulta não repassava o par da requisição | Substituído por 5 testes: "visualizar HTML legado com o par do cliente recusado responde 403 com codigo", "… com processo não encontrado responde 404 com codigo", "… com o par padrão recusado responde 500 sem codigo", "… sem par usa a credencial do tribunal" e "… reconsulta com o par do cliente e responde 200 com o conteudo_html" | RF-01 e NC-2: o HTML legado reconsulta com o par da requisição e segue a tabela do DEP-01. A recusa do par do cliente vira 403 |
| `tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php` | "baixarDocumento de HTML legado mantém a mensagem e não leva a exceção classificada na cadeia" (`downloadHTML` com o documento só, e `getPrevious()` nulo) | "baixarDocumento de HTML legado chama downloadHTML com o par e propaga a falha sem cortar a cadeia" (`downloadHTML($documento, login, senha)`, e o `previous` é a exceção classificada) | NC-2: o `catch` que cortava a cadeia saiu (T02) |
| `tests/Unit/Services/SalvarDocumentoProcessoServiceTest.php` | "baixarDocumento de HTML legado reconsulta com a credencial do tribunal e corta a cadeia" (SOAP com `login-do-tribunal`, `getPrevious()` nulo) | "baixarDocumento de HTML legado repassa o par e mantém a exceção classificada na cadeia". O dataset tem par informado com recusa → `CredencialPjeRecusadaException`, par informado com processo inexistente → `ProcessoNaoEncontradoNoTribunalException`, e sem par → credencial do tribunal com `MNIException`. Confere `idConsultante`/`senhaConsultante` e que a falha não grava nada | NC-2: com par, o SOAP sai com ele; sem par, vale o fallback de hoje para a credencial do tribunal |

Os outros arquivos de teste pré-existentes tocados pela feature só ganharam testes, sem remover nem trocar asserção (`git diff main..HEAD` com 0 linhas removidas):
- `tests/Unit/Services/Callback/CallbackUrlValidatorTest.php`: +6 testes de RF-17. Os 5 testes de antes ficaram iguais.
- `tests/Unit/Services/MNI/ClassificarFalhaMniServiceTest.php`: +2 testes de `recusaNaCadeia` (T17).
- `tests/Feature/DocsApiTest.php`: +1 teste de RNF-04.

A cifra dos 6 jobs (RNF-01, T04) e os hosts liberados (RF-17, T05) não exigiram mudança de asserção. Os testes listados no PLAN para conferência em T04 passam sem mudança.

## 3. Divergências D-01 a D-08 e Open Questions para o Sistema

### Divergências com o contrato acordado

Estão no `info.description` do `docs/api/openapi.yaml` (teste "lista as divergências D-01 a D-08 no info do contrato"). Nenhuma muda os 6 campos do pedido, o schema do 202 ou o do aviso.

| ID | Divergência | Impacto no Sistema | Situação no ms-mni |
|---|---|---|---|
| D-01 | `POST /api/ocr/webhook` (CT-04) está no `docs/api/openapi.yaml` do ms-mni e não está na cópia acordada | Nenhum: só o workflow chama a rota. É uma adição | Documentado, com `security: []` e as respostas 200, 401, 404 e 422 |
| D-02 | O 422 sai com a mensagem do Laravel em inglês (`APP_LOCALE=en`), e não com o exemplo pt-BR da cópia. O corpo é `ErroMensagem` + `errors` | Só o exemplo muda. O Sistema já trata qualquer 422 como `mni_indisponivel` | Implementado assim |
| D-03 | Fora de `production`, `callback_url` aceita `http` e IP interno para os hosts de `CALLBACK_HOSTS_LIBERADOS` (RF-17) | Nenhum em produção. Em desenvolvimento, permite `http://host.docker.internal` | Lista vazia por padrão e ignorada em `production` |
| D-04 | O par fica só em jobs cifrados. Um job que falha de vez guarda o payload cifrado em `failed_jobs` e no Horizon por até 10.080 min. A cópia diz que o par "deixa de existir quando esses jobs terminam" | Texto do contrato. Ver OQ-S1 | A recusa não vai a `failed_jobs` (D7). A falha esgotada vai, cifrada |
| D-05 | `callback_url` tem `maxLength: 2048` | Nenhum para a URL assinada do aviso. É uma restrição nova no contrato | É a única diferença estrutural conferida por `OpenApiContratoOcrTest` |
| D-06 | `numero_processo` com máscara é aceito e normalizado (`cleanNumeroProcesso`). O schema segue `^[0-9]{20}$` | Nenhum | Implementado |
| D-07 | As imagens vão ao workflow com `filename` `{id_documento}.{png\|jpg\|webp\|tif\|bmp\|gif}`. PDF e HTML vão com `.pdf` | Nenhum no comportamento. Muda o texto que cita `.pdf` para todo envio | Implementado |
| D-08 | Um `com_erro` volta a pendente quando um pedido abre processamento. O `com_erro` do 202 da reabertura cai, por exemplo de 5 para 0 | A combinar. Ver OQ-S2 | Implementado (RF-07 AC d) |

### Open Questions para o Sistema

Vão junto com o `docs/api/openapi.yaml` oficial (item M-5 da seção 5).

| ID | Pergunta | Origem | Proposta do ms-mni |
|---|---|---|---|
| OQ-S1 | O Sistema aceita trocar o texto do contrato sobre o par, de "deixa de existir quando esses jobs terminam" para "só em jobs cifrados"? | D-04, PQ-01 | Trocar o texto. Se o Sistema exigir apagar os jobs falhos do OCR, uma task nova limpa `failed_jobs` e o Horizon no `failed()` de `BaixarDocumentoParaOcrJob` |
| OQ-S2 | O RF-08 do Sistema compara as contagens só dentro do mesmo processamento? A barra e o contexto do chat usam o 202 da reabertura? | D-08 | O texto de `com_erro` passa a "erro definitivo neste processamento" |
| OQ-S3 | O Sistema ignora um aviso com contagem menor que a do último aviso recebido, dentro do mesmo processamento? | PQ-04 | Com 2 workers em `ocr-aviso` e novas tentativas, um aviso antigo pode chegar depois de um novo. Cada aviso leva as contagens do momento e o header `X-Idempotency-Key`, um uuid por aviso, que serve para deduplicar. O aviso leva também `X-Evento: processo.ocr.progresso` |
| OQ-S4 | O callback de uma conversa que o Sistema abandonou, depois dos 60 s do cliente, responde 4xx (por exemplo 404)? | LIM-01 | O 4xx encerra o assinante (RF-16). Um 5xx faria o ms-mni tentar 5 vezes por aviso |
| OQ-S5 | Como o Sistema apresenta o `falhou` sem `codigo` de RF-13? O usuário pode precisar de mais de um "sim" para o mesmo documento | LIM-02, LIM-03 | Mensagem de "tente de novo". Um novo "sim" continua de onde parou (RF-07) |
| OQ-S6 | O Sistema troca as duas cópias do contrato pelo `docs/api/openapi.yaml` oficial e roda `~/Projetos/sistema-juridico-lorran/tests/Feature/Servicos/MniTest.php`? | Distribution by Repo | Sim, depois do merge |

## 4. Roteiro de homologação

Cobre RF-01 AC (d), RNF-02 e RNF-03. Nenhum passo foi executado ainda: todos estão pendentes (seção 5). Registrar cada valor medido na tabela de resultados (4.4).

### 4.1 Pré-condições: conferir e registrar antes da medida

| ID | Pré-condição | Como conferir | Valor registrado |
|---|---|---|---|
| P-1 | O workflow "Jurídico OCR (Gemini)" do n8n de homologação tem o RF-14 da SPEC do Sistema importado. O nó "Validar pedido" aceita documento dos autos sem `escritorio_id` (`dosAutos`) | Comparar o workflow ativo no n8n com `~/Projetos/sistema-juridico-lorran/docker/n8n/juridico-ocr.json`. Um `POST {N8N_OCR_URL}/documents/process` manual, com `metadata` sem `escritorio_id`, deve responder 202 `{id}` | _pendente_ (data e versão do import) |
| P-2 | A concorrência do n8n basta para 300 documentos em 10 min | Registrar o modo de execução (`main` ou `queue`), o número de workers e o limite de concorrência de produção do n8n | _pendente_ |
| P-3 | A cota do Gemini basta para 300 documentos em 10 min | Registrar o modelo, o RPM e o TPM da chave usada no nó "Config". A troca da chave é decisão do desenvolvedor, fora do escopo | _pendente_ |
| P-4 | O ms-mni de homologação está com este branch e configurado | `php artisan migrate --force` aplicado (`2026_11_06_000001_create_processo_ocr_tables`). `N8N_OCR_URL`, `N8N_OCR_TOKEN`, `N8N_OCR_BASE_CONHECIMENTO` (igual ao `SIM_OCR_BASE_CONHECIMENTO` do Sistema) e `N8N_OCR_WEBHOOK_BASE_URL` (a base do ms-mni que o n8n alcança) definidos. `php artisan horizon:terminate` depois do deploy. `php artisan schedule:list` mostra `ocr:acompanhar` a cada minuto, e o scheduler está rodando | _pendente_ |
| P-5 | Os supervisores do OCR estão nos valores de produção: `ocr-download` 12, `ocr-envio` 8 e `ocr-aviso` 2 | `php artisan horizon:supervisors`. **Atenção**: o Horizon escolhe os valores pelo `APP_ENV`. Só `production` tem 12/8/2; qualquer outro valor cai no `*`, com 2/2/1, e não alcança a meta. Com `APP_ENV=production`, a lista de RF-17 é ignorada, e o `callback_url` do Sistema precisa ser `https` com IP público | _pendente_ (`APP_ENV` e `maxProcesses` observados) |
| P-6 | O Sistema de homologação aponta para o ms-mni de homologação | `X-API-Token` válido e callback alcançável pelo ms-mni | _pendente_ |
| P-7 | Há um processo real com 300 documentos PDF, ainda sem OCR | Registrar `numero_processo`, `tribunal_id` e o `total`/`ignorados` do 202. Outros legíveis (HTML e imagens) também entram em `total` | _pendente_ |
| P-8 | Há um par válido do membro e uma senha errada para o TJAP | Fazer 1 tentativa por rota com a senha errada, para não bloquear a conta no PJe | _pendente_ |

### 4.2 Passos

Nos comandos abaixo, `$MNI` é a base do ms-mni de homologação e `$TOKEN` é o `X-API-Token`. Use `--data-urlencode` para não deixar o par no histórico do shell em texto montado, e limpe o histórico depois.

**H-1 — RF-01 AC (d): TJAP real com senha errada → 403 nas 2 rotas.**

```bash
# processo ausente do banco do ms-mni
curl -sS -G "$MNI/api/processo/visualizar" -H "X-API-Token: $TOKEN" -H 'Accept: application/json' \
  --data-urlencode "tribunal_id=$TJAP_ID" --data-urlencode "numero_processo=$NUMERO_AUSENTE" \
  --data-urlencode "login_pje=$LOGIN" --data-urlencode "senha_pje=$SENHA_ERRADA" -w '\nHTTP %{http_code}\n'

# documento PDF ainda não baixado (status pendente)
curl -sS -G "$MNI/api/documento/visualizar" -H "X-API-Token: $TOKEN" -H 'Accept: application/json' \
  --data-urlencode "tribunal_id=$TJAP_ID" --data-urlencode "numero_processo=$NUMERO" \
  --data-urlencode "id_documento=$ID_PDF_PENDENTE" \
  --data-urlencode "login_pje=$LOGIN" --data-urlencode "senha_pje=$SENHA_ERRADA" -w '\nHTTP %{http_code}\n'
```

Esperado nas 2 rotas: `HTTP 403`, com o corpo exato `{"codigo":"credencial_pje_recusada","message":"Credencial PJe recusada pelo tribunal"}`, sem `error`, `line`, `file`, login, senha nem texto do tribunal.

**H-2 — RNF-03: pedido de OCR em < 60 s, e o instante T0.**

```bash
curl -sS -X POST "$MNI/api/processo/ocr" -H "X-API-Token: $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d @pedido-ocr.json -w '\nHTTP %{http_code} em %{time_total}s\n'; date -u +%FT%T.%3NZ
```

`pedido-ocr.json` tem os 6 campos de `SolicitacaoDeOcr`, com o par válido e o `callback_url`/`callback_token` do Sistema. Apague o arquivo depois. Esperado: `HTTP 202`, `situacao` `em_andamento` e `time_total` < 60 s. T0 é o horário do 202. No banco, `processo_ocr_processamentos.aberto_em` fica a poucos ms de T0.

**H-3 — RNF-02, medida do ms-mni: 300 `ocr_enviado_em` em ≤ X = 5 min depois de T0.**

```sql
-- :processamento_id = id do processamento aberto em H-2
SELECT count(*) FILTER (WHERE d.ocr_enviado_em IS NOT NULL)                                AS enviados,
       count(*) FILTER (WHERE d.ocr_enviado_em <= p.aberto_em + interval '5 minutes')     AS enviados_ate_x,
       max(d.ocr_enviado_em) - p.aberto_em                                                 AS ultimo_envio_apos_abertura
FROM processo_documentos d
JOIN processo_ocr_processamentos p ON p.processo_id = d.processo_id
WHERE p.id = :processamento_id AND d.mimetype = 'application/pdf'
GROUP BY p.aberto_em;
```

`ocr_enviado_em` é gravado no 202 `{id}` do workflow (CT-03) e só é limpo quando o documento volta à fila. Esperado: `enviados_ate_x` = 300.

**H-4 — RNF-02, meta: aviso final `concluido` em ≤ 10 min depois de T0.**

```sql
SELECT p.situacao, p.codigo_falha, p.aberto_em, p.finalizado_em, p.finalizado_em - p.aberto_em AS duracao,
       (SELECT min(a.enviado_em) FROM processo_ocr_avisos a
          JOIN processo_ocr_assinantes s ON s.id = a.assinante_id
         WHERE s.processamento_id = p.id AND a.final) - p.aberto_em AS final_entregue_apos_abertura
FROM processo_ocr_processamentos p WHERE p.id = :processamento_id;
```

Esperado: `situacao` = `concluido` e `final_entregue_apos_abertura` ≤ 10 min. O desenvolvedor aceita de 7 a 10 min. Conferir também o horário de chegada do aviso final no log do Sistema.

Leitura do resultado (Q-06):
- H-3 cumprido e H-4 perdido: a falha está fora do ms-mni, no n8n ou no Gemini. Conferir P-2 e P-3.
- H-3 perdido: a falha é do ms-mni. Conferir os supervisores (P-5), o Horizon (`horizon:status`, filas `ocr-download` e `ocr-envio`) e o tempo do MNI.

**H-5 — RNF-03 e RF-06: carona em < 60 s.** Durante o processamento de H-2, repetir o `POST` com outro `callback_url`/`callback_token`. Esperado: 202 `em_andamento` com a contagem atual e `time_total` < 60 s. Não há nova chamada ao MNI: o processo não ganha documento novo no banco, e o log não registra nova consulta. O carona recebe os avisos seguintes e o final.

**H-6 — RNF-02: a busca acha um trecho.** Depois do `concluido`, fazer a busca do Sistema no processo (CT-10 da SPEC do Sistema, filtro `{numero_processo, tribunal_id}`) com uma frase tirada de um dos PDFs. Esperado: pelo menos 1 trecho cujo texto aparece no PDF.

**H-7 — Conferências depois da execução.**
- `com_erro` do aviso final e `SELECT id, ocr_erro FROM processo_documentos WHERE processo_id = … AND ocr_situacao = 'erro'`.
- `failed_jobs` da janela.
- `storage/logs/laravel.log` sem o login, a senha nem o `callback_token`: `grep -c` com cada valor deve dar 0.
- Um payload de `ocr-download` lido direto no Redis do Horizon (chaves `*horizon:*`) sem o par.
- Casos de LIM-03 observados (documento preso em `pending`/`running`), se houver.

### 4.3 Critérios de aprovação

| Requisito | Critério |
|---|---|
| RF-01 AC (d) | H-1: 403 com o corpo exato nas 2 rotas |
| RNF-02 | P-1 a P-3 registrados; H-3: 300 enviados em ≤ 5 min; H-4: `concluido` em ≤ 10 min; H-6: pelo menos 1 trecho |
| RNF-03 | H-2 e H-5: cada `POST` em < 60 s |

### 4.4 Resultados (preencher na homologação)

| Passo | Esperado | Medido | Data | OK? |
|---|---|---|---|---|
| H-1 `/processo/visualizar` | 403 `credencial_pje_recusada` | _pendente_ | | |
| H-1 `/documento/visualizar` | 403 `credencial_pje_recusada` | _pendente_ | | |
| H-2 `POST /api/processo/ocr` | 202 em < 60 s | _pendente_ | | |
| H-3 enviados até X | 300 em ≤ 5 min | _pendente_ | | |
| H-4 aviso final | `concluido` em ≤ 10 min | _pendente_ | | |
| H-5 carona | 202 em < 60 s, 0 SOAP | _pendente_ | | |
| H-6 busca | ≥ 1 trecho real | _pendente_ | | |
| H-7 sigilo e erros | 0 ocorrências do par e do token | _pendente_ | | |

## 5. Itens manuais pendentes

| ID | Item | Quem | Situação |
|---|---|---|---|
| M-1 | Pré-condições P-1 a P-8 da homologação, com os valores registrados em 4.1 | Desenvolvedor e operação | Pendente |
| M-2 | Homologação H-1 a H-7 e a tabela 4.4 (RF-01 AC d, RNF-02, RNF-03) | Desenvolvedor | Pendente |
| M-3 | A chave do Gemini no nó "Config" do workflow, decisão do desenvolvedor antes do primeiro OCR real (Scope → Out) | Desenvolvedor | Pendente |
| M-4 | Rollout: `N8N_OCR_*` em produção e homologação, e `CALLBACK_HOSTS_LIBERADOS` só em desenvolvimento. `migrate --force` fora do pico: o `ALTER TABLE processo_documentos` cria 2 índices. Depois, `php artisan horizon:terminate` e `schedule:list` | Operação | Pendente |
| M-5 | Mandar ao Sistema o `docs/api/openapi.yaml` oficial, com D-01 a D-08 e as Open Questions OQ-S1 a OQ-S6 | Desenvolvedor | Pendente |
| M-6 | Rodar `/ai-context` depois do merge e conferir que filas, rotas, tabelas e regras do OCR continuam em `AGENTS.md` e `docs/agents/`. O comentário no topo do `AGENTS.md` pede isso | Desenvolvedor | Pendente |
| M-7 | Não há gate de CI: rodar `composer test` e `npm run typecheck` antes do push (`AGENTS.md` §1) | Desenvolvedor | Feito nesta verificação para o commit `4bfe484`. Repetir se houver commit novo |
| M-8 | Retenção de `processo_ocr_avisos` e `processo_ocr_processamentos` (PQ-06), por exemplo com um comando no padrão de `monitoramentos:limpar-execucoes`. Fora do escopo da SPEC | Backlog | Em aberto |
| M-9 | Registrar aqui os casos de LIM-03 (job preso até M) vistos em homologação ou produção, conforme a tabela de Risks do PLAN | Operação | Pendente |
