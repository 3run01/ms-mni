# Entrada confirmada — erros-codigo-estavel-visualizar

**Resumo:** Erros com código estável (DEP-01) em `GET /processo/visualizar` e `GET /documento/visualizar`, conforme a cópia do contrato acordada com o Sistema Jurídico (cliente da API).

**Contrato acordado (fonte da verdade para nomes, status e campos):**
`/Users/brunosantos/Projetos/sistema-juridico-lorran/.spec/features/consulta-processual-mni-credenciais-pje/.handoff/ms-mni-openapi.yaml`
— itens com `x-dependencia: DEP-01` (respostas `403`/`404` dos dois endpoints e o schema `ErroComCodigo`). O cliente já foi implementado contra essa cópia: nomes, status e campos têm de ser exatamente estes.

## Critérios de aceite (confirmados)

1. Em `/processo/visualizar` e `/documento/visualizar`, quando o tribunal recusa o par `login_pje`/`senha_pje`: HTTP `403`, JSON `{"codigo": "credencial_pje_recusada", "message": "..."}`.
2. Nos mesmos endpoints, quando o processo não existe no tribunal: HTTP `404`, JSON `{"codigo": "processo_nao_encontrado", "message": "..."}`.
3. Em `/documento/visualizar`, o `404` atual ("Não foi possível obter o documento ... após 3 tentativas") continua existindo, **sem** o campo `codigo` (é assim que o cliente diferencia os dois 404).
4. `message` nunca contém o login, a senha nem a URL da requisição.
5. Qualquer outra falha continua como está hoje (500 com `error` em texto livre). Não muda o comportamento de responder do banco quando o processo já existe (limitação conhecida, aceita pelo cliente).
6. Hoje os casos 1 e 2 saem como 500 com `error` em texto livre: identificar a falha concreta que o MNI/tribunal devolve para credencial inválida e para processo inexistente (ver `app/Exceptions/MNIException.php`, `app/Services/MNI/PadronizarMensagemMniService.php` `REGRAS`) e mapear para os códigos acima.
7. Testes Pest cobrindo: 403 com código, 404 com código, 404 de documento sem código.
8. `docs/api/openapi.yaml` oficial atualizado com os mesmos nomes da cópia acordada (só os itens DEP-01); `docs/agents/` (ex.: `api_contracts.md`, `domain_rules.md`) ajustado se necessário.

## Fora de escopo (decidido)

- **DEP-02 (`sigla`/`grau` no schema Tribunal) — BLOQUEADO.** O banco local tem duas linhas ativas para a mesma sigla (`TJAP 1º Grau` id 1 e `TJAP 2º Grau` id 2, endpoints PJe `/1g` e `/2g`), o que viola a restrição "no máximo um tribunal ativo por sigla" do cliente. Aguarda decisão do cliente; será uma feature separada. Não incluir `sigla`/`grau` nem tocar no schema Tribunal.
- **Opcional (mapear 403/404 nos demais endpoints que recebem `login_pje`) — NÃO incluir.** Adicionaria respostas fora da cópia acordada e o openapi oficial divergiria dela.

## Entrega final

Ao concluir, reportar qualquer divergência do openapi oficial em relação à cópia acordada (itens DEP-01).
