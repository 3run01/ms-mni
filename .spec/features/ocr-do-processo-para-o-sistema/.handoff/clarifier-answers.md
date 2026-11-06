# Respostas do desenvolvedor — ocr-do-processo-para-o-sistema (SPEC v1.0)

Respondidas em 2026-11-05. O desenvolvedor escolheu a opção recomendada, (a), nas 11 perguntas de `clarifier-perguntas.md`.

| Pergunta | Escolha | Resumo |
|---|---|---|
| Q-01 | (a) | Situação "aguardando retorno": o documento não é reenviado e o retorno conta como progresso do processamento aberto. Passada uma idade máxima sem retorno, o ms-mni cancela o job antigo com `DELETE {base}/jobs/{job_id}/chunks` e reenvia. A credencial do `webhook_url` vale ao menos essa idade máxima. O AC de RF-10 não muda. Registrar o contrato novo com o workflow como CT. |
| NC-1 | (a) | Imagens vão com `{id_documento}.{extensão do mimetype}`, e PDF e HTML com `.pdf`. Nada é convertido no ms-mni. Ajustar o texto do AC10 e registrar a D-07 para o Sistema. |
| NC-3 | (a) | `com_erro` volta a pendente num pedido que abre processamento, e um `processado` nunca volta. O 202 `concluido` sem trabalho só sai com `com_erro` = 0. Registrar como ponto a combinar com o Sistema a queda do `com_erro` no 202 da reabertura. |
| NC-4 | (a) | P = 25 min, contados da abertura ou do último progresso, o que for mais recente. O link do S3 vale pelo menos P. |
| NC-2 | (a) | O HTML legado do `/documento/visualizar` reconsulta com o par da requisição. A recusa do par do cliente vira 403, e a do par padrão segue 500. Muda a exclusão em `docs/agents/domain_rules.md:188`. No fluxo do OCR, o HTML legado vai pelo PDF já gravado, sem reconsulta (RF-09). |
| NC-5 | (a) | Cifrar com `ShouldBeEncrypted` os 6 jobs que já existem e levam o par (`BaixarDocumentoMNIJob`, `BaixarDocumentosMNIEmLoteJob`, `BaixarProcessoMNIJob`, `Consultar{DadosBasicos,Documentos,Movimentos}ProcessoMNIJob`), com teste de payload. O item sai do Out. |
| Q-02 | (a) | A espera pela trava fica abaixo de 60 s. Esgotada a espera, responde 500 `{message}` sem registrar assinante. Registrar como LIM que um pedido abandonado pelo cliente ainda abre o processamento. |
| Q-03 | (a) | Nada é gerado depois do aviso final, e as tentativas pendentes do assinante saem sem enviar quando o final já foi entregue. AC: 0 entregas depois do final. |
| Q-04 | (a) | O 422 entra no RF-12. `success` prevalece sobre `com_erro`: ajusta as contagens e só avisa se o processamento estiver aberto. `error` nunca rebaixa um `processado`. |
| Q-05 | (a) | `data_juntada` em ISO-8601 com fuso, e `nivel_sigilo` sempre inteiro, com `null` enviado como 0. Corrigir o exemplo de CT-03. |
| Q-06 | (a) | Manter a meta de 300 em 10 min e registrar as pré-condições da homologação: RF-14 do Sistema importado no n8n, e concorrência do n8n e cota do Gemini suficientes. Acrescentar a medida "300 enviados pelo ms-mni em até X min". |

## Valores numéricos não fixados pelo desenvolvedor

Proponha cada valor coerente com as restrições da SPEC e registre como "valor proposto, configurável". Não abra marcador novo para eles. São três:
- a idade máxima sem retorno de Q-01, coerente com P = 25 min;
- a espera pela trava de Q-02, abaixo de 60 s;
- o X de Q-06.

## Rodada 2 (SPEC v1.1 → v1.2), respondida em 2026-11-05

| Ponto | Decisão |
|---|---|
| R2-1 [Contradição] RF-09 × NC-3/RF-18 | Reusar o S3. Um documento `baixado` com objeto no S3 não chama o MNI e vai direto ao envio ao workflow, com 0 SOAP. Ajustar o RF-09. |
| R2-2 [Lacuna] job antigo de `com_erro` devolvido | Cancelar sempre antes. Todo reenvio de documento que já tem `job_id` cancela antes o job antigo pelo CT-07 (`DELETE {base}/jobs/{job_id}/chunks`, idempotente), e o retorno do job antigo passa a receber 404. Isso fecha também a regra do `error` atrasado sobre documento pendente. |
| R2-3 [Baixa] idade A com processamento aberto | Reenviar no mesmo processamento. O acompanhamento (RF-13, a cada minuto) cancela o job antigo pelo CT-07 e reenvia o documento dentro do processamento aberto, sem esperar nova abertura. |
| R2-4 Interpretações | Confirmadas as duas: (1) pedido só com documentos aguardando retorno com idade < A abre processamento sem job e responde `em_andamento`; (2) `data_juntada` `null` quando não há data gravada. |

Nota: o router atualizou o AC10 em `acs-confirmados.md` com a emenda da NC-1.

## Rodada 3 (SPEC v1.2 → v1.3), respondida em 2026-11-06

| Ponto | Decisão |
|---|---|
| R3-1 [Contradição] A = P = 25 min, fim de RF-13 e reenvio de RF-18 na mesma rodada | A = 20 min (configurável, sempre < P). O reenvio de RF-18 (R2-3) acontece sempre antes do fim de RF-13, e o documento reenviado tem pelo menos 5 min. P continua 25 min. A credencial do `webhook_url` continua valendo ≥ A. Registrar a restrição A < P como invariante de configuração. |

## Rodada 4 (SPEC v1.3 → v1.4), decidida em 2026-11-06

| Ponto | Decisão |
|---|---|
| R4-1 [Empate] RF-13 × RF-18 na mesma rodada | O prazo vem primeiro, com ordem fixa em cada rodada do acompanhamento: (1) o RF-13 avalia P; se P venceu, o processamento termina `falhou` e a rodada para ali, sem reenvio; (2) só com o processamento ainda aberto, o RF-18 cancela e reenvia os documentos com idade ≥ A. O documento que fica aguardando retorno quando P vence segue a regra da abertura seguinte do RF-18. O envio e o reenvio não contam como progresso para P. Registrar como LIM o caso raro em que um documento enviado ≥ 5 min depois do último progresso faz o processamento terminar `falhou`. |

## Rodada 5 (SPEC v1.4 → v1.5), respondida em 2026-11-06 (origem: PQ-02 do planner)

| Ponto | Decisão |
|---|---|
| R5-1 [PQ-02] RF-18 reenvia com idade ≥ A sem consultar o job | Consultar o job antes de reenviar. Com idade ≥ A, o ms-mni consulta `GET {base}/jobs/{job_id}` (CT-05) antes de cancelar e reenviar. Com `pending` ou `running`, não reenvia: o documento segue aguardando retorno e é consultado de novo na rodada seguinte. Só reenvia, cancelando antes pelo CT-07, quando o job não existe (404), terminou em `error` ou a consulta indica que o job não está mais em andamento. O fim por P de RF-13 continua valendo e é avaliado antes (R4-1). Aplicar no RF-18 e no que for afetado (RF-13, ACs, LIM-02, CT-05). |
| Confirmações do planner | PQ-03 (classificação por exceções tipadas dos services no job, `ErroComCodigo` só nos controllers) e PQ-05 (corpos `{"message": ...}` no `/ocr/webhook`) aceitos como estão no PLAN. |

## Rodada 6 (SPEC v1.5 → v1.6), decidida em 2026-11-06 (defaults do router, avisados ao desenvolvedor)

| Ponto | Decisão |
|---|---|
| R6-1 [Contradição] GET `success` com idade ≥ A | `success` nunca leva a reenvio. O documento é marcado `processado` pela regra de RF-12/Q-04 (`success` prevalece) e conta como progresso se o processamento estiver aberto. Só levam a cancelar (CT-07) e reenviar: 404, `error`, ou `running` além de M (R6-3). Corrigir a redação "não está mais em andamento" da Rodada 5. |
| R6-2 [Lacuna] GET com falha (timeout, 5xx, erro de rede) | Não reenvia naquela rodada. O documento segue aguardando retorno e é consultado de novo na rodada seguinte. P continua protegendo contra processamento parado. |
| R6-3 [Lacuna] job sempre `pending`/`running` | Idade máxima absoluta M = 60 min (valor proposto, configurável, invariante A < M). Com idade ≥ M e job ainda `pending`/`running`, o job é considerado travado: o ms-mni cancela pelo CT-07 e reenvia, se o processamento estiver aberto, ou deixa para a abertura seguinte. Um processamento aberto ainda pode terminar `falhou` por P antes de M; registrar isso em LIM-02 ou numa LIM nova. |

## Rodada 7 (SPEC v1.6 → v1.7), decidida em 2026-11-06 (default do router)

| Ponto | Decisão |
|---|---|
| R7-1 [Lacuna] GET `cancelled` com idade ≥ A | Tratar como `error` ou 404: cancelar pelo CT-07 (idempotente) e reenviar com o processamento aberto, ou deixar para a abertura seguinte. Com idade < A, `cancelled` segue a regra que o RF-13 já tem. |
