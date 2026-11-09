# Entrada confirmada — campos-dos-autos-e-trava-de-atualizacao

Confirmada pelo desenvolvedor em 2026-11-09 no checkpoint §3 do `/plan`.

## Resumo

No ms-mni, o `/processo/visualizar` passa a trazer os campos dos autos e a situação do OCR por documento. O contrato e a contagem do OCR ficam alinhados (DEP-07), e o `BaixarProcessoMNIJob` sai no máximo uma vez a cada 10 min por processo e tribunal (DEP-08).

## Tier

`complete`: sinal de multi-repo (critérios 6 e 7 tocam o Sistema), contrato OpenAPI alterado e provavelmente mais de 10 RFs.

## Decisões do checkpoint

- **Lado do Sistema = validação externa.** A troca das duas cópias do `openapi.yaml` no Sistema, o `MniTest.php` verde (crit. 6) e a homologação no processo 0000294-58.2023.8.03.0011 (crit. 7) ficam no PLAN como homologação e handoff de volta ao Sistema. **Não** viram checkbox no PHASES.md. O `ralph.sh` roda só no ms-mni.
- **Fontes do Sistema.** O handoff cita `~/Projetos/sistema-juridico-lorran`, mas esse checkout está na `main`, sem DEP-07. As fontes reais estão no worktree:
  `/Users/brunosantos/orca/workspaces/sistema-juridico-lorran/fix-integracao-ia/`
  - `tests/Fixtures/Mni/openapi.yaml`: contrato acordado, marcas `x-dependencia: DEP-07`
  - `.spec/features/visualizador-de-processo/SPEC.md` (v1.1): External Dependencies, CT-08, CT-09, RF-05, LIM-05 e Q-02
  - `.spec/features/visualizador-de-processo/PLAN.md`: seção "Dependências no ms-mni (DEP-07 e DEP-08, fora deste repo)"
  - `.spec/features/consulta-processual-mni-credenciais-pje/.handoff/ms-mni-openapi.yaml`: segunda cópia do contrato

## Descrição completa

`/Users/brunosantos/orca/workspaces/sistema-juridico-lorran/fix-integracao-ia/.spec/features/visualizador-de-processo/.handoff/ms-mni-dep-07-e-08.md`

Seções "O que entregar no ms-mni" (DEP-07 a–d, DEP-08) e "Fora".

## Critérios de aceite confirmados (fonte da verdade)

1. **Campos novos.** O `/processo/visualizar` traz, em cada documento, `tipo`, `data_juntada`, `usuario_juntada_arquivo`, `mimetype`, `file_size` e `ocr_situacao`. O `tipo` sai sem uma consulta por documento, e o teste confere a contagem de queries.
2. **Situação do OCR.**
   - Um `video/mp4` sai `ignorado`.
   - Um PDF com `ocr_situacao` null sai `pendente`.
   - Um documento com mimetype `''` sai `pendente`, nunca `ignorado`.
   - Um documento lido sai `processado`.
3. **HTML.** O `/documento/visualizar` de um documento HTML traz `conteudo_html`, e o contrato descreve o campo.
4. **Contagem.** Um documento com mimetype `''` conta em `total` como pendente e é baixado. Depois do download:
   - se o download revela um vídeo: `ignorados` +1 e `total` −1, com aviso;
   - se revela um PDF: o documento é enviado ao OCR;
   - se o mimetype continua vazio: o documento entra em `com_erro`.
5. **DEP-08.** Duas chamadas ao mesmo processo e tribunal, com 1 min de intervalo e pares diferentes, enfileiram 1 job. Uma terceira, 11 min depois da primeira, enfileira outro. Outro tribunal enfileira o seu.
6. **Contrato oficial.** O `docs/api/openapi.yaml` do ms-mni é atualizado, igual ao acordado ou com as diferenças avisadas ao Sistema.
   - *(Validação externa:)* no Sistema, as duas cópias são trocadas pelo oficial e `./vendor/bin/sail test tests/Feature/Servicos/MniTest.php` fica verde.
7. **Homologação (validação externa)** no processo 0000294-58.2023.8.03.0011:
   - os documentos lidos mostram "Lido pela IA", com "Resumir" e "Perguntar" habilitados;
   - o cabeçalho e o bloco do chat mostram a mesma contagem;
   - os documentos mostram o tipo e a data de juntada;
   - um vídeo aparece em "Mídias" e toca no player.

## Invariantes que continuam

- `processados + com_erro + pendentes + aguardando = total`
- `total + ignorados = documentos`
