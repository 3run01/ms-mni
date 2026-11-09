# Respostas do desenvolvedor — clarifier

Respondidas em 2026-11-09. Perguntas em `.handoff/clarifier-questions.md`.

- **Q-01 → A** (NC-2, RF-14): a gravação da lista nunca troca um mimetype não vazio por `''`. Um valor não vazio vindo da lista continua sobrescrevendo.
- **Q-02 → A** (NC-1, RF-16): a janela vale só para `/processo/visualizar`.
  - O RF-16 diz explicitamente que `/processo/consultar` e o expediente continuam com 1 job por chamada.
  - Sem `ShouldBeUnique` no job.
- **Q-03 → A** (RF-15/RF-16): a consulta síncrona de `/processo/visualizar` (processo fora do banco) que termina com sucesso abre a janela de 600 s, sem job. Uma falha não abre.
  - AC: 1ª chamada → 1 consulta síncrona e 0 jobs.
  - 2ª em seguida → 0 consultas e 0 jobs.
  - 3ª depois de 11 min → 1 job.
- **Q-04 → A** (CT-04): o `docs/api/openapi.yaml` entra no commit da última fase de DEP-07, na mesma branch. As fases anteriores não tocam no YAML.
- **Q-05 → C** (RF-13/RNF-05): o job sai sem aviso intermediário. Se não sobra pendente nem aguardando retorno, o processamento termina `concluido`, com o aviso final.
- **Q-06 → A** (NC-3, VE-02): no processo 0000294-58.2023.8.03.0011, o áudio aparece em "Mídias" e toca. O vídeo é homologado em outro processo da linha 1 com `video/mp4` baixado (ex.: 0039922-84.2023.8.03.0001).
