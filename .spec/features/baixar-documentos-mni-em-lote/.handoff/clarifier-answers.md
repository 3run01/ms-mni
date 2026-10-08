# Respostas do desenvolvedor — clarificação SPEC v1.0

Perguntas originais: Q-01..Q-05 do clarifier (modo analyze). Abaixo, a decisão do desenvolvedor para cada uma.

## Q-01 (M2) — endpoint e fallback → opção (c)
- `consultarProcessos` é chamado em `url_webservice_mni`.
- Fallback para `consultarProcesso` unitário (um por documento, no mesmo job) quando: (i) o WSDL do tribunal não expõe `consultarProcessos`, OU (ii) a chamada em lote retorna `sucesso=false`.
- Exceção de transporte / SOAP fault / timeout continua sendo falha total do lote (RF-08).
- Consequência: AC-1 / RNF-01 valem no caminho feliz; reescrever RF-08 para incluir o fallback.

## Q-02 (M1) — paginação → opção (c) "Paginar até o fim" (contraria a recomendação)
- O job pagina `consultarProcessos` repetindo a chamada enquanto houver páginas.
- **AC-1 e RNF-01 confirmados mudam**: de "exatamente 1 chamada por job" para "1 chamada `consultarProcessos` por página; nenhuma chamada por documento no caminho feliz". Registrar a mudança de AC no changelog do SPEC.
- Critério de parada (Q-02b, decisão do desenvolvedor): parar quando **todos os ids pedidos já tiverem voltado** OU **a página vier sem documentos**; com **teto de páginas configurável** para evitar loop. Não depender de `totalRegistros`.
- `pagina` começa em 1; `tamanhoPagina` = T (salvo evidência em contrário no planejamento).
- Documento pedido que não voltou após o fim da paginação → `erro` (RF-07).

## Q-03 (M4) — mídia → opção (c) "Vídeo fora do lote"
- `video/mp4` e `video/quicktime` continuam com 1 job por documento pelo caminho atual (script `scripts/mni_consultar_documento.sh`). Exceção explícita no RF-10 / AC-7 e no RF-04 / AC-1.
- `audio/*` entra no lote comum com T. Risco de memória/timeout (512 MB / 300 s) do áudio fica **aceito e documentado** como risco.

## Q-04 parte 1 (M3) — unicidade → opção (b) "Trava por documento"
- Trava por documento no despacho, TTL 1800 s, liberada ao fim do job (sucesso e falha). Documentos com trava ativa ficam fora do novo lote.
- AC proposto aceito: o mesmo documento despachado duas vezes em < 1800 s sem conclusão do primeiro lote aparece em exatamente 1 lote.
- N do RF-04 passa a ser "documentos elegíveis sem trava ativa".

## Q-04 parte 2 — `media:redownload --batch` → opção (i) "Cada página do --batch"
- Para esse comando, "seleção" = cada página do `--batch`; ajustar o AC do RF-04 para esse comando (pode gerar mais de ⌈N/T⌉ jobs por processo dividido entre páginas).

## Q-05 parte 1 (M5) — falha parcial → opção (a) "Termina sem exceção"
- Job termina normalmente; documentos com falha ficam `erro`. Recuperação via `mni:baixar-documento-pendente` ou próxima consulta.

## Q-05 parte 2 — órfãos por timeout/OOM → opção (x) "Marcar erro no failed()"
- Na falha definitiva do job (tentativas esgotadas, incluindo timeout do worker / OOM / kill), marcar `erro` em todo documento do lote ainda não `baixado`, com o log do RNF-03, e liberar as travas (Q-04).
- Não limitar o timeout SOAP (opção y não escolhida).

## Notas do clarifier a aplicar sem pergunta
1. Corrigir `Tier` em `## Metadata` para `complete` (reclassificado pelo desenvolvedor; 12 RF).
2. Documento `baixado` sem objeto no S3 no momento da execução → tratar como elegível (rebaixar), como hoje.
3. AC do RF-12 conforme Q-04 parte 1.

---

# Rodada 2 — respostas do desenvolvedor (SPEC v1.1)

## AC-6 — mudança confirmada
- O desenvolvedor confirma a mudança: `sucesso=false` aciona o fallback unitário (Q-01); falha total (exceção de transporte / SOAP fault / timeout) marca `erro` apenas nos documentos ainda não `baixado`. Registrar no changelog como confirmado (remover a pendência de confirmação).

## M6 — falha no fallback unitário → misto por tipo de erro
- Chamada unitária com `sucesso=false` → falha só daquele documento (RF-07: `erro`, +1, segue com os demais; job termina sem exceção).
- Chamada unitária que lança exceção (transporte / SOAP fault / timeout) → falha total, igual à regra do lote (RF-08 b).

## M7 — teto padrão de páginas
- P padrão = ⌈T / `tamanhoPagina`⌉ + 1 (com `tamanhoPagina` = T → P = 2). Configurável.

## M8 — `tentativas_download` na falha definitiva (RF-13)
- +1 apenas nos documentos que não receberam +1 em nenhuma tentativa deste job (ex.: timeout do worker / OOM); os demais ficam como estão (sem contagem dupla).

---

# Rodada 3 — respostas do desenvolvedor (SPEC v1.2)

## M9 — documento já `erro` por RF-07 quando ocorre falha total na mesma execução
- Não recebe outro +1 nem outro log: mantém o `erro` e o +1 que já recebeu (consistente com RF-01 item 3 e M8). A falha total (RF-08 b) só afeta documentos ainda não marcados nesta execução.

## Lacuna RF-13 × documento `baixado` sem objeto no S3
- Corrigir agora: na falha definitiva (RF-13), documento do lote que está `baixado` mas sem objeto no S3 também é marcado `erro` (alinhar com RF-08 b), seguindo a regra de contador da M8.

---

# Confirmação final (SPEC v1.3)
- Desenvolvedor aceitou o alinhamento do RF-08 (a): documento já marcado `erro` nesta execução não é repedido no fallback unitário (AC a5). SPEC v1.3 aprovada para planejamento.
