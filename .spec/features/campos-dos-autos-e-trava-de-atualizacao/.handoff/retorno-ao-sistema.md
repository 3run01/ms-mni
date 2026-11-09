# Retorno ao Sistema — DEP-07 e DEP-08 entregues no ms-mni

- **Para:** o worktree do Sistema Jurídico em `/Users/brunosantos/orca/workspaces/sistema-juridico-lorran/fix-integracao-ia/` (feature `visualizador-de-processo`).
- **Responde a:** `.spec/features/visualizador-de-processo/.handoff/ms-mni-dep-07-e-08.md`, no mesmo worktree.
- **De:** ms-mni (`/Users/brunosantos/Projetos/ms-mni`), feature `campos-dos-autos-e-trava-de-atualizacao` (SPEC v1.1). O rastreio completo e a verificação estão no `RELATORIO.md` desta feature.
- **Data:** 2026-11-09.
- Este handoff não tem código. Os passos do Sistema estão em VE-01 e VE-02.

## Entregue

Branch do ms-mni: `feat/ocr-do-processo-para-o-sistema`, commits `7c11542` a `06f3734` (fases 1 a 6) sobre `3b756f8`. Os commits estão na branch local, ainda sem push, merge nem deploy. Não há migration.

| Fase | Commit | Conteúdo |
|------|--------|----------|
| 1 | `7c11542` | Regra única do conjunto do total, gravação da lista (RF-14) e serviço da janela |
| 2 | `9a4ff3b` | Janela no `/processo/visualizar`, contagem com o vazio em `total` e testes do contrato do documento |
| 3 | `95b173e` | Campos dos autos, pedido de OCR com o vazio, revelação do mimetype e transições |
| 4 | `b477b10` | Jobs de download e envio do OCR |
| 5 | `b6c8931` | Fluxos ponta a ponta e consistência lista × contagem |
| 6 | `06f3734` | `docs/api/openapi.yaml` oficial igual à cópia acordada e `docs/agents/` |

Suíte do ms-mni verde em 2026-11-09: `docker compose exec -T php php artisan test --compact` → 1634 testes aprovados, 13000 asserções, exit 0.

**DEP-07 (a): campos dos autos em `GET /processo/visualizar`**
- Cada item de `documentos` sai com exatamente 13 chaves:
  - as 7 de hoje, sem mudança: `id_documento`, `descricao`, `id_documento_vinculado`, `movimento`, `tipo_documento`, `data_hora`, `nivel_sigilo`;
  - as 6 novas: `tipo`, `data_juntada`, `usuario_juntada_arquivo`, `mimetype`, `file_size` e `ocr_situacao`.
- Vale nos 2 caminhos: processo já no banco e processo consultado na hora.
- `tipo` é a `descricao` do tipo do documento na linha do processo, resolvida em 1 consulta por resposta. Sem registro, sai `null`. Com tipo repetido na linha, vale o de menor `id`.
- `data_juntada`, `usuario_juntada_arquivo`, `mimetype` e `file_size` saem com o valor da coluna, sem conversão. `mimetype` sai `""` quando o tribunal não informou, e `file_size` é inteiro ou `null`.
- `ocr_situacao` é calculada só nessa resposta e nunca é `null`:
  - mimetype não vazio fora da lista legível → `ignorado`;
  - nos demais casos, a situação gravada (`pendente`, `enviado`, `processado` ou `erro`), com `null` → `pendente`;
  - um documento com mimetype `""` nunca sai `ignorado`.
- A lista e as contagens do OCR batem no mesmo estado do banco (RF-05): `processado` = `processados`, `erro` = `com_erro`, `ignorado` = `ignorados`, e todo documento que não sai `ignorado` conta em `total`.
- `/documento/visualizar` e `/processo/documentos/listar` continuam sem nenhuma chave `ocr_*`.

**DEP-07 (b): contrato**
- `docs/api/openapi.yaml` é cópia byte a byte da cópia acordada (seção "Contrato" abaixo).
- `GET /documento/visualizar` não mudou de comportamento. `documento.conteudo_html` está sempre presente, preenchido só em `text/html`, e num `text/html` o `documento.link` aponta para o PDF renderizado. O contrato passou a descrever isso.

**DEP-07 (c): contagem e mimetype vazio**
- O documento com mimetype `""` conta em `total` como pendente. `ignorados` passa a ser só o mimetype conhecido fora da lista.
- O pedido de OCR manda o documento vazio ao download do OCR, mesmo que ele já esteja baixado. O download pede o documento ao tribunal e grava o mimetype que o tribunal informa. Depois:
  - legível (PDF, HTML ou imagem) → vai ao OCR no mesmo processamento, sem aviso pela revelação;
  - conhecido e fora da lista (vídeo, áudio etc.) → não vai ao OCR, sai de `total`, entra em `ignorados`, e sai 1 aviso `ocr_progresso` com as contagens novas. Se nada mais resta, sai também o aviso final `concluido`;
  - ainda vazio → `com_erro`, com `ocr_erro` "mimetype não informado pelo tribunal", e sai aviso. No pedido seguinte, ele volta à fila como todo `com_erro`.
- Nenhum documento com mimetype fora da lista, inclusive o vazio, é enviado ao workflow do OCR.
- A gravação da lista do tribunal não troca mais um mimetype não vazio por `""` (RF-14). Assim, um vídeo revelado continua `video/mp4` e `ignorado` depois de uma nova consulta.

**DEP-07 (d)**: `/processo/documentos/listar`, `/documento/visualizar` e as rotas de OCR mantêm o comportamento, salvo o que a contagem muda. A única exceção é a regra de RF-14 na gravação da lista, que não muda o contrato de nenhuma rota.

**DEP-08: uma atualização por processo e linha a cada 10 min**
- Com o processo no banco, `GET /processo/visualizar` enfileira no máximo 1 `BaixarProcessoMNIJob` a cada 600 s por (`tribunal_id`, `numero_processo`), seja qual for o par da chamada. O job leva o par da chamada que abriu a janela.
- A janela abre em 2 casos: no despacho do job, ou no fim de uma consulta síncrona bem-sucedida (processo fora do banco), que não enfileira job. Uma consulta síncrona que falha não abre a janela.
- As chamadas suprimidas não estendem a janela, e cada linha tem a sua.
- A resposta é a mesma com ou sem despacho. O contrato não tem texto de DEP-08.
- Isso resolve o LIM-05 do Sistema (1 acesso ao tribunal por abertura) para o `/processo/visualizar`. Ver a limitação LIM-05 do ms-mni abaixo.

## Contrato

| Conferência | Resultado |
|-------------|-----------|
| `shasum -a 256 docs/api/openapi.yaml` (ms-mni) | `461fa57a769eb8d06471634610d10fb0347572793a89bc6414e4ff22e04f464a` |
| `shasum -a 256 tests/Fixtures/Mni/openapi.yaml` (Sistema) | `461fa57a769eb8d06471634610d10fb0347572793a89bc6414e4ff22e04f464a` |
| `shasum -a 256 .spec/features/consulta-processual-mni-credenciais-pje/.handoff/ms-mni-openapi.yaml` (Sistema) | `461fa57a769eb8d06471634610d10fb0347572793a89bc6414e4ff22e04f464a` |
| `cmp docs/api/openapi.yaml /Users/brunosantos/orca/workspaces/sistema-juridico-lorran/fix-integracao-ia/tests/Fixtures/Mni/openapi.yaml` | exit 0 |
| `cmp` do oficial com a 2ª cópia do Sistema | exit 0 |
| `git log --format='%h %s' 3b756f8..HEAD -- docs/api/openapi.yaml` | 1 commit: `06f3734 feat(phase-6): Contrato oficial e documentação (última fase de DEP-07)` |

- O contrato oficial é idêntico, byte a byte, à cópia acordada, com os 13 trechos de DEP-07. O texto de `info.description` antes de `## Mudanças acordadas (DEP-07)` não mudou.
- Nenhuma divergência nova (D-09 ou seguinte) foi criada. As observações O-01 a O-06 abaixo não são diferenças entre os YAML.
- Mesmo SHA-256 fixado no plano do ms-mni. Se uma das cópias do Sistema mudar antes de VE-01, os SHA deixam de bater, e o contrato precisa ser conferido de novo.

## VE-01

AC6, lado do Sistema. É validação externa e não foi executada pelo ms-mni.

No worktree `/Users/brunosantos/orca/workspaces/sistema-juridico-lorran/fix-integracao-ia/`:
1. Trocar as 2 cópias pelo `docs/api/openapi.yaml` oficial do ms-mni (branch `feat/ocr-do-processo-para-o-sistema`):
   - `tests/Fixtures/Mni/openapi.yaml`;
   - `.spec/features/consulta-processual-mni-credenciais-pje/.handoff/ms-mni-openapi.yaml`.
2. Rodar `./vendor/bin/sail test tests/Feature/Servicos/MniTest.php` até ficar verde.

Pela seção "Contrato", a troca não muda nenhum byte. As conferências de `MniTest.php:462-483` seguem valendo: as 2 cópias iguais, e o SHA-256 do `info.description` antes de DEP-07 (`e56371b4…4269`).

Para conferir antes da troca: `shasum -a 256` das 2 cópias e do oficial deve dar `461fa57a769eb8d06471634610d10fb0347572793a89bc6414e4ff22e04f464a`.

## VE-02

AC7, homologação externa. Depende do merge e do deploy do ms-mni, seguidos de `php artisan horizon:terminate`, para os workers carregarem os jobs novos. O `build.sh` do deploy roda `cache:clear` (LIM-04).

No processo **0000294-58.2023.8.03.0011** (linha 2, TJAP 2º grau):
- [ ] os documentos lidos mostram "Lido pela IA", com "Resumir" e "Perguntar" habilitados;
- [ ] o cabeçalho e o bloco do chat mostram a mesma contagem;
- [ ] os documentos mostram o tipo e a data de juntada;
- [ ] o áudio (`audio/mpeg`) do processo aparece em "Mídias" e toca no player.

Vídeo (decisão Q-06): o processo do AC7 não tem vídeo no banco de desenvolvimento (84 PDF e 209 HTML `processado`, e 1 `audio/mpeg`). O vídeo é homologado em outro processo da linha 1 com `video/mp4` `baixado`, por exemplo **0039922-84.2023.8.03.0001**:
- [ ] o vídeo aparece em "Mídias" e toca no player.

Base no ms-mni: RF-01 (campos), RF-03 (situação calculada) e RF-05 (lista e contagem batendo).

## Semântica das contagens

Afeta o 202 de `POST /processo/ocr` (`ProcessamentoDeOcr`) e o webhook `ocr_progresso` (`AvisoDeProgressoDoOcr`). As chaves, o `required` e o `enum` não mudam (CT-03). Muda o significado:
- `total` inclui o documento com mimetype `""` como pendente.
- `ignorados` é só o documento com mimetype conhecido fora da lista legível.
- **Entre avisos do mesmo processamento, `total` pode cair e `ignorados` subir.** A variação máxima é o número de documentos vazios do início do processamento. Isso acontece quando o download revela um mimetype fora da lista, por exemplo um vídeo (RF-11).
- As invariantes continuam em todo 202 e em todo aviso:
  - `processados + com_erro + pendentes + aguardando = total`;
  - `total + ignorados` = documentos do processo no banco.
- Um documento que continua vazio depois do download conta em `com_erro`, e não em `ignorados` (RF-12).

O Sistema não deve tratar `total` como valor fixo durante um processamento. Por exemplo, uma barra de progresso deve usar o `total` de cada aviso.

## Limitações

As limitações do ms-mni que o Sistema percebe. Texto da SPEC.

- **LIM-01** (DEP-08): o job da janela usa o par da 1ª chamada. Se o tribunal recusar esse par, o job só registra no log, e o banco fica sem atualização até a janela vencer, mesmo que outra chamada traga um par válido. É o comportamento aceito pelo AC5 ("pares diferentes enfileiram 1 job").
- **LIM-04** (DEP-08): o `cache:clear` de cada deploy apaga as janelas abertas, e a 1ª chamada depois do deploy enfileira um job.
- **LIM-05** (DEP-08; decisão Q-02): a janela vale só para `/processo/visualizar`. `/processo/consultar` com o processo no banco e o despacho a partir do expediente continuam enfileirando 1 job por chamada, também com a janela aberta. O limite de 1 job a cada 10 min por processo e linha vale só para o que o Sistema chama hoje (`/processo/visualizar`).

As demais (LIM-02, LIM-03 e LIM-06) são internas ao OCR do ms-mni e estão no `RELATORIO.md`. Em resumo: um documento que continua vazio volta ao download em cada pedido; um vídeo grande sem mimetype pode estourar o download e ir a `com_erro`; e a lista do tribunal não consegue mais limpar um mimetype já gravado.

## Observações

Não são diferenças entre os dois YAML e não mudam nesta feature. Texto da SPEC.

- **O-01**: a descrição acordada de `Documento.mimetype` diz que "a string vazia é o documento ainda não baixado". No ms-mni, um documento `baixado` também pode ter mimetype `''`: por download fora do OCR ou por RF-12. O texto acordado fica (CT-04); a leitura correta é "mimetype não informado pelo tribunal". O comportamento do Sistema não muda, porque a SPEC dele já trata o vazio assim.
- **O-02**: `nivel_sigilo` (processo e documento) é `varchar` no banco e sai como string JSON ("0"), mas o contrato diz inteiro ou null. A divergência já existia, e o handoff manda não mexer no sigilo.
- **O-03**: `data_hora` e `data_juntada` saem no formato "AAAA-MM-DD HH:MM:SS", sem `T` e sem fuso (sem cast, verified at app/Models/ProcessoDocumento.php:51-57), mas o contrato diz `format: date-time`. A divergência já existia, e o RF-04 do Sistema converte.
- **O-04**: em `/processo/documentos/listar`, o `tipo` sai como objeto do tipo quando o processo não está no banco (verified at app/Http/Controllers/Api/DocumentoController.php:240-244) e não sai quando está (verified at :228-230), mas o contrato diz string. A divergência já existia, e o item (d) mantém a rota como está.
- **O-05**: o item (b) do handoff pede para descrever `nivel_sigilo`, mas o contrato oficial e o acordado já o descrevem (`docs/api/openapi.yaml:1352-1355` e `:1398-1401`). Não há o que fazer.
- **O-06**: o item (d) do handoff cita `/documento/listar`, mas a rota é `/processo/documentos/listar` (verified at routes/api.php:33).
