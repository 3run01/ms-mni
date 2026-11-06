# Perguntas do clarifier — ocr-do-processo-para-o-sistema (SPEC v1.0)

Ordem de prioridade: contrato, depois regra de negócio, caso de borda e premissa. A opção (a) é sempre a recomendada.

## Alta prioridade

### Q-01 [Contradiction] ref RF-08, RF-10, RF-13 (+RF-12)

**Pergunta:** quando o processamento termina em `falhou` (RF-10 ou RF-13), alguns documentos já foram enviados ao workflow (202 com `id`) e ainda não voltaram. Eles contam como pendentes no processamento seguinte e são enviados de novo?

**Conflito:**
- Pela definição de RF-08, pendente é "legível fora de `processados` e `com_erro`". Então o documento enviado sem retorno é pendente.
- O AC de RF-10 (recusa no 4º de 10 documentos) diz "enfileira só os 7 pendentes". Então ele não é.
- O RF-13 deixa `pending` e `running` como pendentes e libera um processamento novo.

**Impacto:**
- O nó "Indexar" do workflow só apaga trechos do mesmo `job_id` (`delete from juridico_ocr_embeddings where job_id = $1`). Um reenvio cria um job novo e duplica os trechos que a busca do Sistema usa.
- Define se o retorno do job antigo conta como progresso e gera aviso no processamento novo.
- Define quanto tempo a credencial do `webhook_url` precisa valer. A FLEXIBLE propõe ≥ P. Vencida, o retorno recebe 401 e o n8n não tenta de novo.

**Opções:**
- (a) Recomendado. Criar a situação "aguardando retorno":
  - o documento não é reenviado nem entra na fila seguinte;
  - o retorno dele (webhook ou consulta de RF-13) conta como progresso do processamento aberto naquele momento e gera aviso aos assinantes dele. Sem processamento aberto, só atualiza as contagens (como RF-10 item 4);
  - depois de uma idade máxima sem retorno (valor a definir), o ms-mni cancela o job antigo com `DELETE {base}/jobs/{job_id}/chunks` (nó "Excluir trechos", idempotente) e reenvia;
  - a credencial do `webhook_url` vale pelo menos essa idade máxima;
  - o AC de RF-10 não muda.
- (b) Reenviar todo legível fora de `processados`/`com_erro`, cancelando antes o job antigo pelo mesmo `DELETE`. O retorno do job antigo vira 404. O AC de RF-10 passa a "7 enfileirados + 3 reenviados".
- (c) Reenviar sem cancelar (leitura literal de RF-08) e registrar como LIM os trechos duplicados no índice.

**Sugerido:** (a). As opções (a) e (b) criam um contrato novo com o workflow, a registrar como CT.

### NC-1 [Marker] ref RF-11, CT-03, D-07

**Pergunta:** qual `filename` vai para as imagens?

**Fato:** o nó "Arquivo" faz `MIME[pedido.ext] ?? bin.mimeType`, e `.pdf` vira sempre `application/pdf`. Um PNG enviado como `{id}.pdf` vai ao Gemini como PDF e acaba em `com_erro`. O workflow já converte TIFF, BMP e GIF em PNG (`NATIVOS`/`converter`).

**Opções:**
- (a) Recomendado. Imagens vão com `{id_documento}.{extensão do mimetype}` (`png`, `jpg`, `webp`, `tif`, `bmp`, `gif`), e PDF e HTML mantêm `.pdf`. Não há conversão no ms-mni. O texto do AC10 passa a "`.pdf` para PDF e HTML". A D-07 vai ao Sistema, mas o CT-08 de lá já só exige "extensão aceita".
- (b) O ms-mni converte cada imagem em PDF, grava um objeto novo no S3 e mantém `.pdf` para tudo (AC10 literal). Isso traz uma dependência e um ponto de falha novos.
- (c) Imagens passam a `ignorados`. Contraria o AC10.

**Sugerido:** (a).

### NC-3 [Marker] ref RF-07, RF-08, RF-09 (AC5)

**Pergunta:** num pedido que abre processamento (não carona), um documento `com_erro` de um processamento anterior volta a ser pendente?

**Conflito:**
- O AC5 diz "não relê nada" e responde `concluido`.
- O contrato define `concluido` como "tudo já lido" (openapi.yaml:882).
- O RF-15 do Sistema diz que o ms-mni "continua de onde parou".

**Por que importa:**
- O RF-09 põe em `com_erro` o documento "bloqueado pelo MNI" para o par do dono. Outro membro, com acesso, nunca o leria.
- Um n8n fora do ar (5xx até esgotar as tentativas) deixa centenas de documentos em `com_erro` para sempre.

**Opções:**
- (a) Recomendado. Sim. Os `com_erro` voltam a pendentes junto com os documentos novos, e um `processado` nunca volta. O 202 `concluido` sem trabalho só sai com `com_erro` = 0. Ponto a combinar com o Sistema: o `com_erro` cai no 202 da reabertura, e o RF-08 de lá não deixa o progresso regredir.
- (b) Não. `com_erro` é definitivo, e quando só restam documentos com erro a resposta é `concluido`. Registrar como LIM que falha transitória não se recupera sem intervenção manual.
- (c) Híbrido. Voltam só os de causa transitória: download sem resposta do MNI, tentativas de envio esgotadas por 5xx ou tempo esgotado, e 404 em RF-13. Ficam definitivos o 422 do workflow e o `status = error`.

**Sugerido:** (a). Use (c) se reler documentos quebrados a cada "sim" preocupar.

### NC-4 [Marker] ref RF-13 (+ validade do link de RF-11 e da credencial do webhook)

**Pergunta:** qual é o valor de P e a partir de quando ele conta?

**Contexto:** o Sistema trata 30 min sem aviso aceito como `falhou`. Com P > 30, o novo "sim" vira carona de um processamento parado, e o usuário espera de novo até P.

**Opções:**
- (a) Recomendado. P = 25 min, contados da abertura ou do último progresso, o que for mais recente. Assim o aviso final do ms-mni chega antes do limite de 30 min do Sistema. O link do S3 vale pelo menos P.
- (b) P = 30 min, igual ao Sistema. O Sistema pode marcar `falhou` antes do aviso do ms-mni.
- (c) P = 60 min, para tolerar documentos longos no Gemini. Aceita que o "sim" seguinte vire carona.

**Sugerido:** (a). Os números são proposta, e o valor precisa ser confirmado.

## Baixa prioridade

### NC-2 [Marker] ref RF-01 (AC9)

**Pergunta:** o HTML legado (`baixado`, sem `path_html` e sem `conteudo_html`) do `/documento/visualizar` entra no AC9?

**Fato:** os 500 do log vieram desse caminho, que reconsulta com a credencial do tribunal (SalvarDocumentoProcessoService.php:111-121). O resultado não depende do par do cliente.

**Opções:**
- (a) Recomendado. Reconsultar com o par da requisição. A recusa do par do cliente vira 403, e a do par padrão segue 500 (DEP-01). Isso muda a exclusão em domain_rules.md:188 e acrescenta o caso ao AC (a).
- (b) Sem reconsulta: responder 200 com o `link` do PDF já gravado e `conteudo_html = null`, como a recuperação complementar já faz (domain_rules.md:189).
- (c) Manter a exclusão (500).

**Sugerido:** (a). Em qualquer opção, convém o RF-09 dizer que, no fluxo do OCR, o HTML legado vai pelo PDF já gravado, sem reconsulta, porque o RF-02 proíbe a credencial do tribunal.

### NC-5 [Marker] ref RNF-01 (AC6), Scope Out

**Pergunta:** a cifra de jobs vale também para os jobs que já existem e levam o par?

**Fato:** o "sim" do Sistema chama `/processo/visualizar`, que enfileira jobs com o par em texto claro. A RNF-01 do Sistema afirma o contrário. Ligar `ShouldBeEncrypted` não quebra os payloads antigos que estiverem na fila (CallQueuedHandler.php:113-124 desserializa direto o que começa com `O:`).

**Opções:**
- (a) Recomendado. Cifrar os 6 jobs que recebem o par: `BaixarDocumentoMNIJob`, `BaixarDocumentosMNIEmLoteJob`, `BaixarProcessoMNIJob` e `Consultar{DadosBasicos,Documentos,Movimentos}ProcessoMNIJob`, com teste de payload. O item sai do Out.
- (b) Cifrar só os jobs do OCR e criar a D-08 para o Sistema corrigir a RNF-01 de lá.
- (c) Cifrar só os `Baixar*`.

**Sugerido:** (a).

### Q-02 [Gap] ref RF-04, RF-06, RNF-03

**Pergunta:** o que acontece quando a consulta síncrona ou a espera pela trava passa dos 60 s do cliente (`mni.timeout`)?

**Fato:**
- O SOAP tem `timeout` 1800 s (IntegracaoBase.php:26).
- O Sistema desiste em 60 s e não liga a conversa. O ms-mni segue, abre o processamento, e os avisos recebem 404 do Sistema, o que encerra o assinante.
- O contrato não tem 409 nem 503.

**Opções:**
- (a) Recomendado. Limitar a espera pela trava a menos de 60 s (valor a definir). Esgotada a espera, responder 500 `{message}` sem registrar assinante. Registrar como LIM que um pedido abandonado pelo cliente ainda abre o processamento.
- (b) Fazer (a) e também limitar a consulta síncrona a menos de 60 s. Risco: processo grande em tribunal lento nunca passa.
- (c) Sem limites novos, só a LIM.

**Sugerido:** (a).

### Q-03 [Ambiguity] ref RF-15 x RF-16

**Pergunta:** "nenhum aviso depois dele" vale para aviso gerado ou entregue?

**Fato:** cada aviso tenta de novo por conta própria por até cerca de 81 min, e o AC (b) de RF-16 exige que o aviso seguinte saia em paralelo. Um `em_andamento` pode chegar depois do aviso final. O Sistema tolera (RF-08 de lá).

**Opções:**
- (a) Recomendado. Nada é gerado depois do final, e as novas tentativas pendentes desse assinante saem sem enviar quando o final já foi entregue. AC: 0 entregas depois do final.
- (b) Vale só para aviso gerado, e entregas atrasadas são aceitas.
- (c) Ordem estrita por assinante. Contraria o AC (b) de RF-16 e atrasa o final em até cerca de 81 min.

**Sugerido:** (a).

### Q-04 [Contradiction] ref RF-12 x CT-04

**Pergunta:**
1. O CT-04 lista um 422 (sem `job_id` ou com `status` inválido) que o RF-12 não tem. Ele entra no RF-12?
2. Qual resultado vale quando chegam dois retornos finais diferentes? Por exemplo, a consulta de RF-13 marcou `com_erro` (404) e depois chega um webhook `success`, com os trechos já no índice.

**Opções:**
- (a) Recomendado. O 422 entra no RF-12. `success` prevalece sobre `com_erro`: ajusta as contagens e só avisa se o processamento estiver aberto. `error` nunca rebaixa um `processado`.
- (b) O 422 entra, e o primeiro resultado final vale.
- (c) O 422 entra, e o último resultado vale.

**Sugerido:** (a). Com (b) e NC-3 em (a), um documento já indexado seria reenviado e duplicaria os trechos.

### Q-05 [Contradiction] ref RF-11 `metadata`, CT-03

**Pergunta:** em que formato vai `data_juntada`, e qual valor vai em `nivel_sigilo`?

**Fato:**
- O exemplo de CT-03 usa `"2026-01-05 10:00:00"`, o formato da coluna (SalvarDocumentoProcessoService.php:101). A FLEXIBLE pede ISO-8601.
- `nivel_sigilo` 0 é gravado como `null` (`!empty`, :69), mas o CT-03 mostra `0`.

**Opções:**
- (a) Recomendado. ISO-8601 com fuso, como o webhook de monitoramento, e `nivel_sigilo` sempre inteiro, com `null` enviado como 0. Corrigir o exemplo de CT-03.
- (b) Formato da coluna e `nivel_sigilo` como está gravado. Tirar o ISO-8601 da FLEXIBLE.
- (c) ISO-8601 e `nivel_sigilo` como está gravado.

**Sugerido:** (a).

### Q-06 [Premise] ref RNF-02 (AC8)

**Pergunta:** a meta de 300 PDFs em 10 min é medida de ponta a ponta. Como tratar o que está fora do ms-mni?

**Fato:** a meta depende da vazão do n8n e do Gemini, que estão fora do ms-mni. Também depende de muitos downloads paralelos com o mesmo par no TJAP.

**Opções:**
- (a) Recomendado. Manter a meta e registrar como pré-condições da homologação: o RF-14 do Sistema importado, e concorrência do n8n e cota do Gemini suficientes. Acrescentar uma medida só do ms-mni ("300 enviados em até X min", X a definir) para saber onde está a falha.
- (b) Manter como está.
- (c) Fazer (a) e também limitar downloads simultâneos por par (valor a definir), para o tribunal não bloquear o login. Isso pode impedir a meta para um único processo.

**Sugerido:** (a).

---

Arquivos de referência:
- `/Users/brunosantos/Projetos/ms-mni/.spec/features/ocr-do-processo-para-o-sistema/SPEC.md`
- `/Users/brunosantos/Projetos/sistema-juridico-lorran/docker/n8n/juridico-ocr.json` (nós "Indexar", "Excluir trechos" e "Arquivo")
- `/Users/brunosantos/Projetos/ms-mni/app/Services/IntegracaoBase.php:26`
- `/Users/brunosantos/Projetos/ms-mni/app/Services/Processo/SalvarDocumentoProcessoService.php:69,101,111-121`

Recomendação do clarifier: depois das respostas, rodar o modo resolve e reanalisar se Q-01 (a) ou (b) entrar, porque as duas criam um CT novo com o workflow.
