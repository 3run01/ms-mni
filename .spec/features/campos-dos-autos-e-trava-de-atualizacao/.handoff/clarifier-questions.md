# Perguntas do clarifier — campos-dos-autos-e-trava-de-atualizacao

- SPEC: `.spec/features/campos-dos-autos-e-trava-de-atualizacao/SPEC.md` (v1.0, tier `complete`)
- Modo: analyze (SPEC não editado)
- Marcadores `[NEEDS CLARIFICATION]`: 3 (NC-1, NC-2 e NC-3), todos nesta lista
- Ordem: risco de retrabalho (persistência e regra de negócio, depois contrato e plano, depois borda e homologação)

Responda no formato `Q-XX → letra` (com observação, se quiser).

---

## Q-01 [Marker] ref RF-14 (NC-2)

**Pergunta.** Uma consulta da lista do tribunal que traz `''` para um documento pode apagar o mimetype revelado pelo download do OCR (RF-09)?

**Impacto.**
- Hoje toda gravação da lista regrava o `mimetype` por `updateOrCreate` (`app/Services/Processo/SalvarDocumentoProcessoService.php:57-74`, linha 66).
- O próprio pedido de OCR regrava a lista completa antes de decidir (`SolicitarOcrProcessoService.php:153-158` → `ProcessoService.php:106-112`). Com (B), a cada pedido o vídeo revelado volta a `''`. Ele entra de novo em `total` como pendente e é baixado de novo do tribunal (LIM-03). Além disso, o pedido nunca responde `concluido` sem abrir processamento (`SolicitarOcrProcessoService.php:189`).
- O job de DEP-08, disparado a cada abertura dos autos fora da janela, também regrava a lista (`BaixarProcessoMNIJob.php:68-74`). Com (B), `/processo/visualizar` alterna o documento entre `ignorado` e `pendente`, e o filtro "Mídias" do Sistema oscila.
- Com (A), muda o `salvarDocumento`, que todas as consultas usam (síncrona, job, lista do OCR e `/processo/documentos/listar`). Nenhuma rota muda de contrato, mas o item (d) do handoff não cita essa mudança.

**Opções.**
- (A) A gravação da lista nunca troca um mimetype não vazio por `''`. Um valor não vazio vindo da lista continua sobrescrevendo.
- (B) Manter a regravação de hoje e aceitar a oscilação, com uma LIM nova.

**Recomendação: (A).** RF-14 passaria a dizer: "Se a lista do tribunal trouxer `''` para um documento com mimetype não vazio no banco, o ms-mni mantém o valor gravado". AC: com um vídeo revelado e um pedido de OCR em que a lista devolve `''`, o documento continua `video/mp4`, `ignorados` não muda e sai 0 job de download para ele.

---

## Q-02 [Marker] ref RF-16 (NC-1)

**Pergunta.** A janela de DEP-08 vale também para `/processo/consultar` e para o despacho a partir do expediente?

**Impacto.**
- Hoje, 3 pontos despacham o job:
  - `/processo/visualizar` (`ConsultarProcessoController.php:107`);
  - `/processo/consultar` (`:146`);
  - o comando de expediente (`ConsultarExpedienteService.php:48`, via `MNIConsultarExpediente`), sem par, ou seja, com a credencial do tribunal.
- O Sistema só chama `/processo/visualizar`: no `app/` do worktree, aparecem só `ConsultarAutosDoProcesso`, `ProcessarDocumentosDoProcesso` e `ConsultarProcessoPeloChat`. Então (B) e (C) não mudam nada para o Sistema e vão além do AC5.
- Com (C), um job do expediente (sem par) abriria a janela e suprimiria por 10 min o job com o par do membro.
- Com (A), RF-16 deve dizer que os outros 2 despachos continuam com 1 job por chamada. Isso descarta um `ShouldBeUnique` no job, que vale para os 3 despachos. Com ele, um job do expediente já na fila descartaria o despacho do visualizar logo depois de a janela abrir, e o AC de RF-15 ("o único job leva o par A") quebraria.

**Opções.**
- (A) Só `/processo/visualizar` (escopo do handoff, do DEP-08 do Sistema e do AC5).
- (B) `/processo/visualizar` e `/processo/consultar` dividem a mesma janela.
- (C) Todo despacho do job, inclusive o do expediente.

**Recomendação: (A),** com RF-16 dizendo explicitamente que `/processo/consultar` e o expediente não mudam.

---

## Q-03 [Contradiction] ref RF-16 (AC) e RF-15

**Pergunta.** A consulta síncrona de `/processo/visualizar` (processo fora do banco) abre a janela?

**Impacto.**
- O AC de RF-16 diz: "com o processo fora do banco, 2 chamadas seguidas fazem 2 consultas síncronas ao MNI fake e enfileiram 0 jobs". Isso não fecha com o fluxo. A 1ª chamada grava o processo (`ConsultarProcessoController.php:94-105`). A 2ª o encontra no banco e vai ao despacho (`:106-107`). O AC só passa com o `ProcessoService` mockado sem gravar, como nos testes de hoje (`tests/Feature/Api/ConsultarProcessoControllerTest.php`, casos "visualizar ... repassa ... ao ProcessoService").
- Se a consulta síncrona não abre a janela, a 1ª abertura de um processo novo faz a consulta completa ao tribunal. A abertura seguinte nos 10 min (outro membro, "Atualizar" ou outro fluxo do Sistema) enfileira mais 3 consultas SOAP sobre um banco que acabou de ser gravado.

**Opções.**
- (A) A consulta síncrona que termina com sucesso abre a janela de 600 s, sem job. Uma falha não abre. AC: 1ª chamada → 1 consulta síncrona e 0 jobs; 2ª em seguida → 0 consultas e 0 jobs; 3ª depois de 11 min → 1 job.
- (B) Só o despacho abre a janela, como está escrito. AC: 1ª → 1 consulta síncrona e 0 jobs; 2ª → 0 consultas e 1 job.
- (C) Manter a regra de (B) e reescrever o AC para chamadas isoladas (MNI fake que não grava, ou processo apagado entre as chamadas).

**Recomendação: (A).** É o objetivo de DEP-08 e do LIM-05 do Sistema: um acesso ao tribunal por processo e linha a cada 10 min.

---

## Q-04 [Ambiguity] ref CT-04

**Pergunta.** Se o código de DEP-07 for dividido em fases, em qual commit entra o `docs/api/openapi.yaml`?

**Impacto.**
- CT-04 manda o YAML "no mesmo commit da mudança de código de DEP-07". Mas o YAML é uma cópia byte a byte que descreve (a), (b) e (c) juntos, nos 13 trechos. Ele não pode ser dividido sem quebrar o `cmp` e o SHA-256 conferidos pelo `MniTest` (`MniTest.php:462-483`).
- O ralph faz 1 commit por fase (ex.: `31ed427`, "Rota POST /api/processo/ocr e contrato publicado"). Com RF-01 a RF-06 e RF-07 a RF-14 em fases diferentes, nenhum commit cumpre CT-04 ao pé da letra.

**Opções.**
- (A) O YAML entra no commit da última fase de DEP-07, quando (a), (b) e (c) estão prontos. As fases anteriores não tocam no YAML. A mudança (branch/PR) continua única, como pede o `AGENTS.md` §3.
- (B) O YAML entra no commit da 1ª fase de DEP-07. Nas fases intermediárias, o contrato descreve (c) antes do código.
- (C) DEP-07 inteiro (RF-01 a RF-14) e o YAML numa fase só.

**Recomendação: (A).** CT-04 passaria a dizer "na mesma branch, no commit da última fase de DEP-07".

---

## Q-05 [Gap] ref RF-13 e RNF-05

**Pergunta.** Um job do OCR (download ou envio) pode encontrar um documento com mimetype conhecido fora da lista sem que esse mimetype tenha sido revelado no próprio job. Isso conta como progresso do processamento?

**Impacto.**
- Caso real: um documento `''` está na fila do OCR, e o job de DEP-08 (de quem abriu os autos durante o processamento) regrava o mimetype vindo da lista. RF-13 e RNF-05 só dizem que o job sai sem SOAP e sem envio.
- Se não for progresso e o documento era o último pendente, o processamento fica `em_andamento` até o prazo P (25 min, `config/services.php:73`). Só então termina `concluido`, por `encerrarPorPrazo`. Nesse tempo, o Sistema fica sem o aviso final.
- Tratar sempre como progresso, com um aviso por documento, contradiz a medida de RNF-05 ("rodar 2 vezes o job do mesmo documento revelado 'video/mp4' dá 1 aviso").

**Opções.**
- (A) O job sai sem aviso, e o prazo P fecha o processamento (LIM nova).
- (B) Conta como progresso, como RF-11: aviso `em_andamento` e encerramento se nada resta. RNF-05 passa a aceitar um aviso repetido, com as mesmas contagens, quando o job roda de novo.
- (C) Sem aviso intermediário. Se não sobra pendente nem aguardando retorno, o processamento termina `concluido`, com o aviso final.

**Recomendação: (C).** É compatível com RNF-05: depois que RF-11 encerra o processamento, o job repetido sai no item 1 (processamento encerrado). Se ainda há pendentes, o job repetido não avisa.

---

## Q-06 [Marker] ref VE-02 (NC-3)

**Pergunta.** O processo do AC7 (0000294-58.2023.8.03.0011, linha 2) não tem vídeo no banco de desenvolvimento: tem 84 PDF, 209 HTML e 1 `audio/mpeg`. Como homologar o item "um vídeo aparece em 'Mídias' e toca no player"?

**Impacto.** Não muda o código do ms-mni, só o roteiro de homologação do PLAN. No banco de desenvolvimento, a linha 1 tem `video/mp4` `baixado` com `path` em 0039922-84.2023.8.03.0001, 6001731-51.2026.8.03.0003 e 0026590-16.2024.8.03.0001.

**Opções.**
- (A) No processo do AC7, conferir que o áudio aparece em "Mídias" e toca. Homologar o vídeo em outro processo da linha 1 com `video/mp4` baixado (por exemplo, 0039922-84.2023.8.03.0001).
- (B) Voltar a condição "se o processo tiver": o vídeo fica sem homologação.
- (C) Ler "um vídeo" como "uma mídia": o áudio do processo do AC7 basta.

**Recomendação: (A).**

---

## Nota sem pergunta (contexto para Q-01 e Q-06)

Nenhum dos 1.319 documentos do banco de desenvolvimento tem `mimetype` `''`. O atributo `mimetype` é opcional no WSDL (`tests/Fixtures/mni/intercomunicacao-com-consultarProcessos.wsdl:24`). Quando ele falta, `salvarDocumento` grava `null` (`SalvarDocumentoProcessoService.php:66`), e a coluna `NOT NULL` recusa. Então o `''` só nasce se o tribunal mandar o atributo vazio. Isso não muda o AC4, porque o contrato acordado já põe o vazio em `total`. Mas RF-08 a RF-14 ficam cobertos só por testes Pest com fixture: o VE-02 não exercita esse caminho.
