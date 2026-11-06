# Entrada confirmada — ocr-do-processo-para-o-sistema

Confirmado pelo desenvolvedor em 2026-11-05. Estes critérios são a fonte da verdade da SPEC.

## Resumo

OCR dos documentos do processo para o Sistema Jurídico (DEP-03 a DEP-05), portado do sim-mni, e correção da recusa do par do PJe que hoje chega como 500 genérico.

## Escopo

- Tudo num plano só; a correção da recusa do par é a primeira fase.
- Tier: complete.

## Critérios de aceite

1. `POST /processo/ocr` válido responde 202 com `total`, `processados`, `com_erro`, `ignorados` e `situacao`. Sem o par, responde 422 e nunca usa o par padrão: a rota fica só com `ValidateApiToken`, fora do `InjectCredenciaisPjePadrao`.
2. Um par recusado responde 403 `credencial_pje_recusada`. Um processo inexistente responde 404 `processo_nao_encontrado`, via `ErroComCodigo`.
3. Um segundo pedido com processamento em andamento pega carona, pela trava por processo e linha. Responde 202 com a contagem atual, não enfileira nenhum job novo e não guarda o par. O assinante novo passa a receber os avisos.
4. Cada documento lido ou com erro definitivo gera um aviso a cada assinante, e o fim gera um aviso `concluido` ou `falhou`. Uma resposta 4xx encerra o assinante; uma 5xx leva a até 5 tentativas, com o backoff do webhook de monitoramento.
5. Pedir de novo um processo já concluído não relê nada e responde 202 `concluido`. O processamento é incremental.
6. O par nunca aparece em log, exceção, resposta ou payload de job sem cifra. Os jobs implementam `ShouldBeEncrypted`, e o job que envia ao workflow não carrega o par.
7. O `docs/api/openapi.yaml` do ms-mni fica igual ao contrato acordado em `~/Projetos/sistema-juridico-lorran/tests/Fixtures/Mni/openapi.yaml`, ou as diferenças são avisadas.
8. Homologação: 300 PDFs reais em até 10 minutos, com 20 workers somados nos supervisores do `config/horizon.php` (RNF-02), e a busca do Sistema acha um trecho real.
9. Correção: um par recusado responde 403 `credencial_pje_recusada` no `/documento/visualizar` e na consulta de um processo novo, e não mais 500 genérico. Um par recusado no meio do OCR gera o aviso `falhou` com esse código. Os demais assinantes recebem `credencial_de_outro_pedido_recusada`.
10. O OCR recebe PDF, HTML (pelo PDF renderizado) e as imagens que o workflow lê; os demais formatos contam em `ignorados`. Cada envio leva um link temporário do S3 que cubra a fila, `filename` `{id_documento}.pdf` para PDF e HTML e `{id_documento}.{extensão do mimetype}` para imagens (emenda de 2026-11-05, resposta NC-1), `webhook_url` assinado de volta ao ms-mni, `base_conhecimento` e o `metadata` do CT-08, sem `escritorio_id`. O webhook do workflow marca o documento como lido ou com erro definitivo.
11. O `CallbackUrlValidator` aceita a lista de hosts liberados só fora de produção. Assim, `http://host.docker.internal` funciona em desenvolvimento e continua recusado em produção.
