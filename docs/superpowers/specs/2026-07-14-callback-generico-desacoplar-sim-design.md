# Callback genérico — desacoplar do sistema SIM (Sub-projeto A)

**Data:** 2026-07-14
**Tipo:** Refatoração / breaking change de API
**Serviço:** ms-mni
**Escopo:** Sub-projeto A (webhooks + limpeza de referências SIM). A remoção da
conexão de DB `sim` é o **Sub-projeto B**, tratado em spec separada.

## Contexto

Hoje o ms-mni é acoplado a um sistema cliente específico, o **SIM** (Sistema
Integrado do MP-AP), de duas formas:

1. **Webhook de exportação:** ao concluir a geração de um PDF (`POST
   /processo/download`), o ms-mni notifica uma URL fixa lida de config
   (`services.sim_webhook_download`, env `SIM_APP_URL`), enviando o caminho
   relativo do arquivo no S3 (`s3_path`).
2. **Notificações async:** os 3 jobs `Consultar*` (dados-básicos, movimentos,
   documentos) chamam `GET {SIM_APP_URL}/webhook/atualizar-processo/{numero}`
   após buscar do MNI.

Ambos os destinos são hardcoded para o SIM. Este sub-projeto **generaliza** a
entrega: o **chamador fornece a URL de callback e um token no payload da
requisição**, e o ms-mni notifica essa URL após concluir o trabalho. Isso remove
o acoplamento com o SIM e permite qualquer consumidor (testável via Postman)
receber as notificações.

A conexão de DB `sim` (usada por `Tribunal` e `TipoDocumento`) **não** é tocada
aqui — fica para o Sub-projeto B, porque `tribunais`/`tipos_documento` não têm
schema local (vivem no banco do SIM) e mover exige migração de dados.

## Decisões

- **Callback fornecido pelo chamador.** `callback_url` + `callback_token` no
  payload de `/processo/download` e dos 3 endpoints `*/async`.
- **Obrigatórios.** Ausência → `422`. O SIM era o único consumidor e está saindo;
  não há retrocompatibilidade a preservar.
- **Autenticação:** o ms-mni reenvia o `callback_token` do chamador no header
  `X-API-Token` ao notificar. Cada consumidor controla seu próprio segredo.
- **Validação de URL (anti-SSRF):** `callback_url` deve ser **https** e é
  rejeitada se resolver para localhost / IPs privados (`127.0.0.0/8`,
  `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `::1`).
- **Entrega do PDF:** o callback de exportação envia uma **presigned URL do S3**
  (validade 60 min, mesmo padrão de `/documento/visualizar`), não o `s3_path`
  relativo.
- **Persistência:** `processo_exportacoes` ganha `callback_url` e `callback_token`
  (o job async precisa saber para onde/como notificar). Os jobs `Consultar*`
  recebem os dois como argumentos.
- **Idempotência/retry preservados:** os campos e a lógica existentes de reenvio
  (`webhook_enviado_em`, contagem de tentativas, `WebhookPermanentException`,
  fila `exportar-processo`) são mantidos; muda apenas o alvo (dinâmico) e o auth.
- **Limpeza SIM:** remover config `services.sim_webhook_download`, envs
  `SIM_APP_URL`/`SIM_WEBHOOK_TIMEOUT` e as vars órfãs do `.env.example`
  (`SIM_WEBHOOK_DOWNLOAD_URL`, `SIM_API_TOKEN`), além de strings/comentários
  "SIM" no código. Docs históricos (`docs/superpowers/specs|plans`) **não** são
  alterados.

## Arquitetura

### Componentes

**`CallbackUrlValidator`** (novo) — `app/Services/Callback/CallbackUrlValidator.php`
- Responsabilidade única: validar uma URL de callback (https + bloqueio de IP
  interno). Lança `InvalidCallbackUrlException` em URL inválida.
- Usado na validação de request (FormRequest) e defensivamente antes do POST.
- Interface: `assertValida(string $url): void` (ou `ehValida(string $url): bool`).

**`CallbackNotifier`** (renomeado de `WebhookDownloadClient`) —
`app/Services/Callback/CallbackNotifier.php`
- Responsabilidade: `POST {url}` com header `X-API-Token: {token}` e corpo JSON.
- `notificar(string $url, string $token, array $payload): void`.
- 4xx → `CallbackPermanentException` (não retenta). 5xx → lança para a fila
  retentar (comportamento atual de `WebhookPermanentException`).
- Genérico: reusado por exportação e async.

**Regra de validação de request** — `Rules\CallbackUrl` (ou validação inline no
FormRequest) que delega ao `CallbackUrlValidator`.

### Fluxo — exportação

```
POST /processo/download { ..., callback_url, callback_token }
  → valida (inclui callback_url https + não-interno)
  → cria processo_exportacoes (persiste callback_url, callback_token)
  → GerarPdfExportacaoJob → EnviarParaS3ExportacaoJob → EnviarWebhookDownloadJob
       └─ CallbackNotifier.notificar(callback_url, callback_token, payload)
              payload concluido: { user_id, titulo, formato, status:"concluido",
                                   download_url: <presigned 60min>, tamanho_bytes }
              payload falhou:    { user_id, titulo, formato, status:"falhou",
                                   erro_resumo }
```

### Fluxo — async (atualizar-processo)

```
GET /processo/consultar/{dados-basicos|movimentos|documentos}/async
    { ..., callback_url, callback_token }
  → valida callback_url
  → Consultar{X}ProcessoMNIJob::dispatch(..., callback_url, callback_token)
       └─ processa MNI, depois CallbackNotifier.notificar(callback_url,
          callback_token, { numero_processo, tipo, status })
```

### Mudança de dados

Migration nova: adiciona `callback_url` (string) e `callback_token` (string) a
`processo_exportacoes`. Ambas nullable para não quebrar linhas existentes; a
obrigatoriedade é garantida na entrada (FormRequest), não no schema.

**Nota de segurança:** `callback_token` fica em texto no banco (secret at rest),
equivalente ao token que hoje vive em config. Aceitável para este escopo;
registrado como trade-off.

## Contrato HTTP

### `POST /api/processo/download`

Body — adiciona aos campos atuais:

| campo | tipo | obrig. | regra |
|---|---|---|---|
| `callback_url` | string (uri) | sim | https, host não-interno |
| `callback_token` | string | sim | — |

Erros: `422` se `callback_url`/`callback_token` ausentes ou `callback_url`
inválida (não-https / IP interno).

### `GET /api/processo/consultar/{tipo}/async`

Query params — adiciona `callback_url` (obrigatório) e `callback_token`
(obrigatório), mesmas regras.

### Callback de saída (o **chamador** implementa)

```
POST {callback_url}
Headers: X-API-Token: {callback_token}
```

Exportação — `concluido`:
```json
{ "user_id": 1, "titulo": "...", "formato": "pdf", "status": "concluido",
  "download_url": "https://<s3-presigned>...", "tamanho_bytes": 12345 }
```
Exportação — `falhou`:
```json
{ "user_id": 1, "titulo": "...", "formato": "pdf", "status": "falhou",
  "erro_resumo": "..." }
```
Async:
```json
{ "numero_processo": "...", "tipo": "movimentos", "status": "concluido" }
```

## Tratamento de erro

- `callback_url` inválida no request → `422` (validação), nenhum job despachado.
- Falha no POST do callback: 4xx → permanente, loga e não retenta; 5xx/timeout →
  fila retenta (política atual da `EnviarWebhookDownloadJob`).
- Presigned URL: se a geração falhar, o job trata como falha de entrega (loga),
  igual à geração de link temporário em `DocumentoController`.

## Documentação (OpenAPI)

- `openapi.yaml`: request body de `/processo/download` e params dos `*/async`
  ganham `callback_url` + `callback_token`.
- A seção `webhooks.download` é **reescrita** como contrato genérico do callback
  (o chamador implementa), com os 3 formatos de payload acima; remover a menção
  a "cliente SIM" e ao `s3_path`, documentar `download_url` presigned e o header
  `X-API-Token: {callback_token}`.
- Adicionar um `webhook` para o callback async (`atualizar-processo`).

## Testes

- `CallbackUrlValidator`: aceita https público; rejeita http, localhost, cada
  faixa de IP privado, host que resolve para IP interno.
- FormRequest: `422` sem `callback_url`/`callback_token`; `422` com URL inválida.
- `CallbackNotifier`: envia header `X-API-Token` com o token do chamador; 4xx →
  `CallbackPermanentException`; 5xx → relança.
- Pipeline de exportação: `processo_exportacoes` persiste callback_url/token; o
  callback recebe `download_url` presigned (não `s3_path`) e o token do chamador.
- Async: os 3 jobs propagam callback_url/token e notificam com o payload
  `{numero_processo, tipo, status}`.
- Regressão: garantir que nenhuma referência a `SIM_APP_URL` /
  `services.sim_webhook_download` permanece (grep no CI/local).

## Fora de escopo (Sub-projeto B / YAGNI)

- **Remoção da conexão de DB `sim`** e migração de `Tribunal`/`TipoDocumento`
  para o banco default (exige criar schema + migrar dados; spec própria).
- Allowlist de domínios para callback (escolhido: só https + bloqueio de IP
  interno).
- Assinatura HMAC do payload do callback (usamos token compartilhado por
  chamador; HMAC fica para evolução futura).
- Retenção/expiração do arquivo no S3.
- Alteração dos docs históricos em `docs/superpowers/`.
