# Relatório de entrega: erros-codigo-estavel-visualizar

- Data: 2026-10-08
- Commit verificado: `ab6e2da` (branch `feat/baixar-documentos-mni-em-lote`), comparado com o commit base `b9f0028`
- Referências: `SPEC.md` v1.1, `PLAN.md` (T16)
- Contrato oficial: `docs/api/openapi.yaml`
- Cópia acordada (só leitura, não alterada): `/Users/brunosantos/Projetos/sistema-juridico-lorran/.spec/features/consulta-processual-mni-credenciais-pje/.handoff/ms-mni-openapi.yaml`

## Resumo

| Verificação | Requisito | Resultado |
|---|---|---|
| Suíte completa (`docker compose exec -T php php artisan test --compact`) | RNF-04 | 705 testes aprovados, 4267 asserções, **0 falhas** |
| `git diff b9f0028 -- app/Services \| grep -E '^\+.*(response\(\|JsonResponse)'` | RNF-03 | **0 linhas** |
| Comparação estrutural dos 5 nós DEP-01 (oficial × cópia acordada) | RF-09, CT-01..03 | **0 diferenças** |
| `redocly lint` do oficial comparado com `b9f0028` | RNF-05 | **0 erros** nos dois; nenhum erro novo |

## (i) DEP-01: 0 diferenças

| Nó | Diferenças estruturais | Idêntico também sem ignorar texto livre |
|---|---|---|
| `paths./processo/visualizar.get.responses.403` | 0 | sim |
| `paths./processo/visualizar.get.responses.404` | 0 | sim |
| `paths./documento/visualizar.get.responses.403` | 0 | sim |
| `paths./documento/visualizar.get.responses.404` | 0 | sim |
| `components.schemas.ErroComCodigo` | 0 | sim |

Os dois arquivos marcam com `x-dependencia: DEP-01` exatamente esses 5 nós. A cópia também tem 2 nós `DEP-02` (`components.schemas.Tribunal.properties.sigla` e `.grau`), que ficaram fora do oficial como exige a RF-09.

### Método

1. Usei um script PHP descartável, `/tmp/t16/comparar.php`, fora do repositório. Ele foi copiado para o serviço `php` com `docker compose cp`, junto com:
   - `docs/api/openapi.yaml` → `/tmp/t16/oficial.yaml`;
   - a cópia acordada → `/tmp/t16/copia.yaml`.
2. Rodei o script com `docker compose exec -T php php /tmp/t16/comparar.php`. Os dois arquivos são lidos com `Symfony\Component\Yaml\Yaml::parseFile`, do `vendor` do app.
3. O script remove recursivamente as chaves `description`, `example`, `examples` e `summary` (Q-09) e mantém `x-dependencia`. Os nomes de campo dentro de `properties` nunca são removidos, mesmo que um campo se chame `description` ou `summary`.
4. A comparação é recursiva. Cada caminho é marcado como "só no oficial", "só na cópia" ou "valor diferente", e as listas são comparadas por índice. O script imprime a contagem de cada nó DEP-01 e depois o diff do documento inteiro.
5. Uma segunda passagem, `/tmp/t16/bruto.php`, comparou os 5 nós sem remover nenhuma chave. Os nós são idênticos também em `description` e `examples`, o que confirma a cópia literal.
6. Nada foi gravado no repositório. O `tests/Unit/OpenApiContratoDep01Test.php` (T14) cobre a mesma propriedade dentro da suíte e passa.

## (ii) Divergências fora de DEP-01

No diff do documento inteiro, com as chaves de texto livre removidas, há **11 caminhos divergentes**, todos fora de DEP-01:

| # | Caminho | Onde existe | Observação |
|---|---|---|---|
| 1 | `components.schemas.Tribunal.properties.sigla` | só na cópia | DEP-02, bloqueado e fora desta feature |
| 2 | `components.schemas.Tribunal.properties.grau` | só na cópia | DEP-02, bloqueado e fora desta feature |
| 3 | `components.schemas.PaginacaoProcessos.properties.first_page_url` | só no oficial | campo da paginação do Laravel |
| 4 | `components.schemas.PaginacaoProcessos.properties.from` | só no oficial | idem |
| 5 | `components.schemas.PaginacaoProcessos.properties.last_page_url` | só no oficial | idem |
| 6 | `components.schemas.PaginacaoProcessos.properties.next_page_url` | só no oficial | idem |
| 7 | `components.schemas.PaginacaoProcessos.properties.path` | só no oficial | idem |
| 8 | `components.schemas.PaginacaoProcessos.properties.prev_page_url` | só no oficial | idem |
| 9 | `components.schemas.PaginacaoProcessos.properties.to` | só no oficial | idem |
| 10 | `components.schemas.WebhookMonitoramentoPayload.properties.movimentos.items.properties` | só no oficial | `identificador_movimento`, `codigo_nacional`, `complemento`, `data_hora` |
| 11 | `components.schemas.WebhookMonitoramentoPayload.properties.documentos.items.properties` | só no oficial | `id_documento`, `descricao`, `tipo_documento`, `mimetype`, `data_hora`, `nivel_sigilo` |

A comparação ignora o texto livre, mas ele também diverge:

- **Exemplos dos webhooks, só no oficial:**
  - `webhooks.monitoramento.post.requestBody.content.application/json.examples`: `com_alteracao`, `sem_alteracao`, `falha`;
  - `webhooks.download.post.requestBody.content.application/json.examples`: `concluido`, `falhou`.
- **Bloco "Mudanças acordadas (DEP-01 e DEP-02)", só na cópia.** Ele fica no `info.description`, que é igual nos dois arquivos até a linha 11. Depois disso, a cópia acrescenta as linhas 12-25 com esse bloco. O bloco cita DEP-02 e caminhos de arquivos do cliente (`tests/Feature/Servicos/MniTest.php`, `tests/Fixtures/Mni/openapi.yaml`), por isso não foi copiado para o oficial.
- **Outros textos livres que divergem.** Não mudam a estrutura e são listados só para informação:
  - `description` e `example` de campos que existem só no oficial: `MonitoramentoCriar` (`numero_processo`, `intervalo_horas`, `callback_url`, `callback_token`), `Monitoramento` (`numero_processo`, `intervalo_horas`, `credencial`, `execucoes`), `MonitoramentoExecucao.uuid`, `WebhookMonitoramentoPayload` (`primeira_execucao`, `resumo`, `resumo.truncado`, `movimentos`, `documentos`, `erro_resumo`, `falhas_consecutivas`, `monitoramento_status`), `Tribunal` (`url_webservice_mni_consultar_processo`, `url_webservice_mni_criminal`), os `example` de `PaginacaoProcessos` (`current_page`, `last_page`, `per_page`, `total`) e `components.responses.Erro422.content.application/json.example`;
  - texto diferente: `components.responses.Erro422.description` e `components.responses.Erro404Monitoramento.description` (o oficial tem a versão mais longa, igual à de `b9f0028`);
  - texto só na cópia: `description` e `example`/`examples` de `Tribunal.sigla`/`grau` (DEP-02).

### Recomendação ao cliente (Sistema Jurídico)

1. Trocar as duas cópias do yaml mantidas pelo cliente (`.spec/features/consulta-processual-mni-credenciais-pje/.handoff/ms-mni-openapi.yaml` e `tests/Fixtures/Mni/openapi.yaml`) pelo `docs/api/openapi.yaml` oficial desta entrega. Depois, rodar `tests/Feature/Servicos/MniTest.php`, como pede o próprio bloco "Mudanças acordadas".
2. Com a troca, o cliente:
   - mantém os 5 nós DEP-01 sem nenhuma mudança;
   - ganha os 7 campos de `PaginacaoProcessos`, as `items.properties` do webhook de monitoramento e os exemplos dos webhooks;
   - perde `Tribunal.sigla`/`grau` (DEP-02 ainda não foi entregue pelo ms-mni) e o bloco "Mudanças acordadas".
3. Se algum código ou teste do cliente já lê `sigla`/`grau`, ele precisa tratar os dois campos como ausentes até DEP-02 ser entregue.
4. Este repositório não alterou nenhum arquivo do cliente (Scope Out).

## (iii) Limitações conhecidas

1. **Vídeo fora de RF-03/RF-04 (Q-05).** No download de `video/mp4` e `video/quicktime`, a recusa da credencial e o processo inexistente não viram 403/404 com `codigo`. O comportamento de `b9f0028` foi mantido, sem status fixado. O binário é baixado por `scripts/mni_consultar_documento.sh` (chamado por `SalvarDocumentoProcessoService::downloadMP4`/`downloadQuicktime`), que não foi alterado. O script tem dois bugs:
   - **Bug 1: `curl` sem `--fail`.**
     - O script só olha o código de saída do `curl`, que é 0 para qualquer resposta HTTP, inclusive um SOAP Fault com HTTP 500. A resposta é gravada em `$TEMP_FILE` (`--output`) seja qual for o resultado SOAP.
     - Do lado PHP, `extrairConteudoMTOM` devolve o conteúdo como está quando ele não é MTOM, e a assinatura MP4 inválida só gera `Log::warning`.
     - O resultado é um falso 200: o XML de recusa ou de fault vai para o S3 como `.mp4`, o documento é marcado como `baixado` e a API devolve um link para esse XML.
     - Só `--fail` não resolve tudo. Uma resposta `sucesso=false` chega com HTTP 200, então a correção também precisa recusar um corpo que não é MTOM nem MP4.
   - **Bug 2: URL fixa do TJAP 1º grau na linha 20** (`curl --location 'https://pje.tjap.jus.br/1g/intercomunicacao'`). O vídeo de processo de outro tribunal ou do 2º grau é consultado no endpoint errado. As linhas seguintes também fixam cookies de sessão do TJAP 1º grau (`JSESSIONID`, `__cf_bm`, `INGRESSCOOKIE`).
2. **LIM-01.** Quando o processo já está no banco, `GET /api/processo/visualizar` responde 200 com o registro local e enfileira `BaixarProcessoMNIJob`, sem consulta MNI síncrona (RF-07). Por isso não há 403/404 com `codigo`, mesmo com credencial inválida, e o job continua engolindo a exceção.
3. **Recusa do par padrão ou da credencial do tribunal → 500.** O 403 só vale quando a chamada recusada usou o par `login_pje`/`senha_pje` enviado pelo cliente (Q-01). A recusa do par injetado por `InjectCredenciaisPjePadrao` ou da credencial do tribunal segue RF-06:
   - em `/processo/visualizar`: 500 `{error, line, file}` com o texto padronizado;
   - em `/documento/visualizar`: 500 `{message}`.
4. **HTML legado → 500.** Um `text/html` baixado sem `path_html`/`conteudo_html` é reconsultado sem credenciais (`SalvarDocumentoProcessoService::baixarDocumento`). Uma falha nessa reconsulta continua 500 `{"message":"Erro ao processar documento HTML:"}`, mesmo com o par do cliente na requisição (Q-02), porque a cadeia `previous` é cortada de propósito. Na recuperação complementar de HTML (RF-08), a falha continua resultando em 200 com `conteudo_html` = `null`.
5. **Sigilo ou texto fora do padrão → 500.** Só `sucesso=false` com a mensagem inteira igual a `Processo de número <dígitos> não encontrado!` vira 404 (Q-03). Processo sob sigilo (texto não capturado), número com máscara, mensagem sem `!`, mensagem com prefixo e o mesmo texto vindo em SoapFault seguem RF-06. Uma recusa sem `loginfailed` (por exemplo `"Erro ao realizar login via MNI."`) também segue RF-06 (Q-04).
6. **Regex calibrado só no TJAP.** `ClassificarFalhaMniService::PADRAO_PROCESSO_NAO_ENCONTRADO` (`/^Processo de número \d+ não encontrado!$/u`) vem da captura real do TJAP 1º e 2º grau (2026-10-08). Não há captura de outros tribunais nem da variante com `documento` usada no download. Se outro tribunal usar outro texto, a resposta continua 500, como hoje.

## (iv) Resultados das verificações

### 1. Suíte completa (RNF-04)

```
$ docker compose exec -T php php artisan test --compact
  Tests:    705 passed (4267 assertions)
  Duration: 15.10s
```

O comando termina com código 0 e **0 falhas**, sem testes pulados ou marcados como arriscados. A suíte inclui os testes de caracterização (T03/T04), os unitários (T01, T05-T11), os de feature dos dois endpoints (T12/T13) e o `OpenApiContratoDep01Test` (T14).

### 2. RNF-03: nenhum `response()`/`JsonResponse` novo em `app/Services`

```
$ git diff b9f0028 -- app/Services | grep -E '^\+.*(response\(|JsonResponse)'
(nenhuma linha; grep exit 1)
```

O resultado é **0 linhas**. Desde `b9f0028`, o diff em `app/Services` tem 4 arquivos (+100/−6): `MNI/ClassificarFalhaMniService.php` (novo), `MNI/Intercomunicacao/ConsultarProcessoService.php`, `MNI/Intercomunicacao/ConsultarDocumentoService.php` e `Processo/SalvarDocumentoProcessoService.php`. O status HTTP é decidido só em `app/Http` (`Responses/ErroComCodigo.php` e os dois controllers).

### 4. Lint do redocly (T14, RNF-05)

Rodei `npx --yes @redocly/cli@latest lint <arquivo> --format=summary` (CLI 2.60.0, configuração `recommended` padrão). A base foi extraída com `git show b9f0028:docs/api/openapi.yaml` para `/tmp/t16`, fora do repositório.

| Arquivo | Erros | Warnings | Detalhe |
|---|---|---|---|
| `b9f0028:docs/api/openapi.yaml` | 0 | 23 | `operation-operationId` 22, `info-license` 1 |
| `docs/api/openapi.yaml` (atual) | 0 | 24 | `operation-operationId` 22, `info-license` 1, `no-required-schema-properties-undefined` 1 |
| cópia acordada | 0 | 24 | mesmos 24 do oficial atual |

- Nenhum erro novo, então a **RNF-05 está atendida**. O redocly declara o arquivo válido ("Your API description is valid"), e o `OpenApiContratoDep01Test` confirma que o YAML carrega e que `openapi` é `3.1.0`.
- O único warning novo é `no-required-schema-properties-undefined` em `#/paths/~1documento~1visualizar/get/responses/404/content/application~1json/schema/oneOf/1/allOf/1/not/required/0` (`docs/api/openapi.yaml:337`).
  - Ele vem do nó DEP-01 copiado literalmente, e a cópia acordada tem o mesmo warning.
  - O `not: { required: [codigo] }` é intencional: proíbe `codigo` no 404 "documento não obtido após 3 tentativas". A regra do redocly não leva em conta que o `required` está dentro de `not`.
  - Alterar o nó quebraria a igualdade com a cópia (RF-09), então o warning foi mantido.
