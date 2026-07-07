# Fluxo OCR + RAG entre SIM, SIM-MNI e SIM-OCR

Fluxo ponta a ponta envolvendo os três serviços:

| Serviço | Stack | Papel |
|---------|-------|-------|
| **sim** | Laravel / Filament / Livewire | UI do chat SamIA. Dispara o OCR, recebe progresso em tempo real e consulta o RAG. |
| **sim-mni** | Laravel (microserviço) | Orquestra o OCR: integra com MNI/PJe, baixa documentos e fala com o sim-ocr por documento. |
| **sim-ocr** | FastAPI + Celery + docling-serve + PGVector | Faz o OCR de cada arquivo, indexa no banco vetorial e responde consultas RAG. |

Blocos marcados `✗ desativado` estão comentados no código do sim-mni
("desativado temporariamente — sem Samia").

---

## 0. Antes × Depois (por que ficou mais rápido)

```mermaid
flowchart LR
    subgraph ANTIGA["🐢 Forma ANTIGA (JuntarOCRProcessoJob + SyncBaseConhecimentoSamiaJob)"]
        direction TB
        A1["OCR documento por documento"]
        A2["⏳ ESPERA todos os docs<br/>do processo terminarem"]
        A3["JuntarOCRProcessoJob<br/>baixa N .txt do S3 (sequencial)<br/>concatena → 1 processo.txt grande<br/>(timeout 600s)"]
        A4["SyncBaseConhecimentoSamiaJob<br/>FILA GLOBAL: 1 processo por vez<br/>(ordem sequence_job)"]
        A5["Envia processo.txt à<br/>Knowledge Base Samia (externa)"]
        A6["⏳ POLLING do status da KB<br/>STARTING → IN_PROGRESS → COMPLETE"]
        A7["Só então notifica o usuário<br/>(processo inteiro pronto)"]
        A1 --> A2 --> A3 --> A4 --> A5 --> A6 --> A7
    end

    subgraph ATUAL["⚡ Forma ATUAL (sim-ocr OCR+RAG por documento)"]
        direction TB
        B1["Cada documento entra<br/>numa task Celery independente"]
        B2["OCR + chunk + embeddings +<br/>upsert PGVector NO MESMO passo"]
        B3["Workers em PARALELO<br/>(vários docs ao mesmo tempo)"]
        B4["Webhook por documento →<br/>progresso incremental"]
        B5["RAG já consultável com os<br/>documentos parciais indexados"]
        B1 --> B2 --> B3 --> B4 --> B5
    end

    classDef slow fill:#fde2e2,stroke:#c0392b,color:#7b241c;
    classDef fast fill:#e9f7ef,stroke:#1e8449,color:#145a32;
    class A2,A3,A4,A6 slow;
    class B2,B3,B5 fast;
```

**Onde o tempo era perdido na forma antiga:**

| Gargalo | Antiga | Atual |
|---------|--------|-------|
| Disponibilidade | Nada utilizável até o **último** documento do processo terminar | Cada documento fica pesquisável **assim que termina** |
| Junção | `JuntarOCRProcessoJob` lê N arquivos do S3 em série e monta um `processo.txt` único (timeout 600s) | **Não existe** — indexação direta por documento |
| Sincronização | `SyncBaseConhecimentoSamiaJob`: fila **global, 1 processo por vez** (por `sequence_job`) | Tasks Celery **paralelas**, sem fila global |
| Detecção de fim | **Polling** periódico do status da KB (`STARTING→IN_PROGRESS→COMPLETE`) | **Event-driven**: webhook por documento |
| Dependência externa | Ingestão/embedding na Knowledge Base Samia (Bedrock) — tempo próprio | Embedding local/Gemini dentro da própria task |

Resumo: a forma antiga era **sequencial e em lote por processo** (espera tudo →
junta tudo → sincroniza um por vez → faz polling). A atual é **incremental e
paralela por documento** (cada doc segue sozinho até o PGVector), eliminando as
duas maiores esperas: a junção e a fila global de sincronização.

---

## 1. Visão geral — sequência entre os 3 serviços

```mermaid
sequenceDiagram
    actor U as Usuário
    participant SIM as sim (chat SamIA)
    participant MNI as sim-mni
    participant MNIQ as sim-mni (filas/cron)
    participant OCR as sim-ocr (API)
    participant W as sim-ocr (worker Celery)
    participant DL as docling-serve
    participant PG as PGVector (embeddings)

    U->>SIM: Abre processo no chat
    SIM->>SIM: dispararOcrAutomatico()<br/>cria Notificacao local
    SIM->>MNI: POST /api/processo/ocr<br/>X-API-Token<br/>{numero_processo, tribunal_id, notificacao_id}
    MNI-->>SIM: 200 (job enfileirado) ou 404

    Note over MNI: OCRProcessoController
    MNI->>MNI: docs PENDENTE → BaixarDocumentoMNIJob<br/>(baixa do MNI/PJe)
    MNI->>MNIQ: docs BAIXADO → OCRRequestJob<br/>(fila ocr-request, 1 por documento)
    MNI->>MNI: knowledge_base_sequence_job + status STARTING

    loop Para cada documento
        MNIQ->>OCR: POST /documents/process<br/>Bearer token<br/>{bucket_origem/destino, path_*, webhook_url, metadata}
        OCR->>OCR: cria Job (PENDING) + send_task Celery
        OCR-->>MNIQ: 202 { id: job_id }
        MNIQ->>MNIQ: salva ocr_job_id + grava .metadata.json (s3_ocr)

        W->>OCR: download arquivo do bucket origem
        W->>DL: POST /v1/convert/file (OCR)
        DL-->>W: markdown
        W->>OCR: upload markdown no bucket_destino
        W->>W: chunk + embeddings
        W->>PG: upsert_embeddings (tabela embeddings)
        W->>MNI: POST /api/ocr/webhook<br/>{job_id, status}

        alt sem webhook (dev)
            MNIQ->>OCR: ocr:poll-status (cron 1min)<br/>GET /jobs/{job_id}
            OCR-->>MNIQ: { status, progress }
        end

        MNI->>MNI: documento.ocr_processado = true
        MNI->>SIM: POST /webhook/ocr-progresso<br/>X-API-Token { numero_processo }
        SIM->>SIM: conta processados/total<br/>ChatSessao.ocr_concluido se 100%
        SIM-->>U: broadcast OcrProgressoAtualizadoEvent<br/>(canal ocr_progresso_{numero}) → barra de progresso
    end

    Note over U,PG: Mais tarde — usuário faz uma pergunta no chat
    U->>SIM: Pergunta no chat
    SIM->>OCR: POST /rag/query (Bearer)<br/>{query, top_k, filter:{numero_processo}}
    OCR->>PG: busca por similaridade (cosine) filtrando metadata
    PG-->>OCR: trechos relevantes
    OCR-->>SIM: results[]
    SIM-->>U: resposta da IA (stream) com contexto
```

> **Importante:** a consulta RAG (`/rag/query`) vai **direto do sim para o
> sim-ocr** — não passa pelo sim-mni. O sim-mni só participa da fase de
> ingestão/OCR.

---

## 2. Detalhe — fila e callback no sim-mni

```mermaid
flowchart TD
    SIM["sim · dispararOcrAutomatico<br/>POST /api/processo/ocr"] --> CTRL["OCRProcessoController::store"]
    CTRL --> FIND{"Processo encontrado?"}
    FIND -->|não| R404["HTTP 404"]
    FIND -->|sim| NOTIF["Cria Notificacao (TIPO_OCR_PROCESSO)"]

    NOTIF --> DOCSB["Docs status=BAIXADO<br/>!ocr_processado, !ocr_enviado_fila"]
    NOTIF --> DOCSP["Docs status=PENDENTE"]
    NOTIF --> SEQ["nextval(knowledge_base_sequence_job_seq)<br/>status_sync = STARTING"]

    DOCSP -->|cada doc| BAIXAR["BaixarDocumentoMNIJob (fila default)<br/>baixa do MNI/PJe"]
    BAIXAR -.->|vira BAIXADO| DOCSB
    DOCSB -->|claim atômico| OCRJOB["OCRRequestJob (fila ocr-request)"]
    SEQ -. "SyncBaseConhecimentoSamiaJob ✗ desativado" .-> FIMSYNC["(sync KB)"]

    OCRJOB --> GUARD{"refresh()"}
    GUARD -->|ocr_processado| SKIP1["return (idempotente)"]
    GUARD -->|ocr_job_id != null| SKIP2["return (já enviado)"]
    GUARD -->|pendente| POST["POST sim-ocr /documents/process<br/>Bearer · bucket_origem/destino · webhook_url · metadata"]

    POST --> POSTOK{"HTTP 2xx?"}
    POSTOK -->|não| RETRY["throw → retry (backoff 60/120/300/600)<br/>failed(): tem job_id ⇒ NÃO reseta;<br/>senão ocr_enviado_fila=false"]
    POSTOK -->|sim| SAVEJOB["ocr_job_id = response.id<br/>grava .metadata.json no s3_ocr"]

    SAVEJOB --> MS(["sim-ocr processa<br/>(ver seção 3)"])

    MS --> CALLBACK{"Conclusão"}
    CALLBACK -->|produção| WH["POST /api/ocr/webhook<br/>OCRWebhookController {job_id,status}"]
    CALLBACK -->|sem webhook / dev| POLL["cron ocr:poll-status (1min)<br/>GET sim-ocr /jobs/{job_id}"]

    WH --> LOOKUP["ProcessoDocumento::where(ocr_job_id)"]
    POLL --> LOOKUP
    LOOKUP -->|não achou| R404B["404 / reset job_id+fila (reenvia)"]
    LOOKUP --> ST{"status"}
    ST -->|success| SUCCESS["ocr_processado=true<br/>ocr_concluido_data=now()"]
    ST -->|error / failed| ERR["Log::error<br/>ocr_enviado_fila=false (reenvia)"]
    ST -->|outro| PEND["pendente (progress %)"]

    SUCCESS --> JUNCAO{"todos docs processados?<br/>e status==STARTING"}
    JUNCAO -->|sim| JJOB["JuntarOCRProcessoJob ✗ desativado<br/>→ SyncBaseConhecimentoSamiaJob ✗ desativado"]
    SUCCESS --> NOTSIM["notificarProgressoSim()<br/>POST sim /webhook/ocr-progresso"]
    NOTSIM --> SIMUI["sim: OcrProgressoWebhookController<br/>broadcast OcrProgressoAtualizadoEvent<br/>→ UI do chat atualiza progresso"]

    classDef disabled fill:#fde2e2,stroke:#c0392b,color:#7b241c;
    classDef external fill:#e8f0fe,stroke:#1f558a,color:#18446e;
    classDef terminal fill:#e9f7ef,stroke:#1e8449,color:#145a32;
    class JJOB,FIMSYNC disabled;
    class MS external;
    class SIMUI terminal;
```

---

## 3. Detalhe — interno do sim-ocr (OCR + RAG)

```mermaid
flowchart TD
    REQ["POST /documents/process<br/>(api/app/routers/documents.py)"] --> VAL["Valida extensão (ALLOWED_EXTENSIONS)<br/>resolve bucket_destino (bucket_configs)"]
    VAL --> SRC{"Fonte"}
    SRC -->|bucket S3| HEAD["head_object no bucket_origem<br/>404 se não existe"]
    SRC -->|url / base64| STAGE["baixa/decoda → MinIO interno (uploads/)"]
    HEAD --> JOB["INSERT jobs (status=PENDING, mode=OCR_RAG)"]
    STAGE --> JOB
    JOB --> SEND["celery send_task → fila rag_queue"]
    SEND --> R202["202 { id: job_id }"]

    SEND --> TASK["worker: process_ocr_rag_task<br/>(autoretry 3x, countdown 30s)"]
    TASK --> S0["status=RUNNING (0%)"]
    S0 --> CRED["resolve credenciais origem/destino<br/>(tabela bucket_configs)"]
    CRED --> DL["download arquivo do bucket origem (5%)"]
    DL --> OCR["docling-serve POST /v1/convert/file<br/>do_ocr=true, engine=easyocr (10%)"]
    OCR --> MD{"markdown vazio?"}
    MD -->|sim| FAIL
    MD -->|não| UP["upload markdown no bucket_destino<br/>(path_destino) + metadata (40%)"]
    UP --> CHUNK["SimpleChunker: ~512 tokens, overlap 50<br/>split por parágrafo (50%)"]
    CHUNK --> EMB["embeddings (60%)<br/>local: all-MiniLM-L6-v2 (384d)<br/>ou Gemini (3072d)"]
    EMB --> DEL["se reprocesso: delete_by_document<br/>(numero_documento+numero_processo)"]
    DEL --> UPSERT["upsert_embeddings → tabela embeddings<br/>(PGVector, índice ivfflat/hnsw cosine) (85%)"]
    UPSERT --> URL["presigned URL do resultado (95%)"]
    URL --> OK["status=SUCCESS (100%)"]
    OK --> WHS["se webhook_url:<br/>POST {status:success, job_id,<br/>bucket_destino, path_destino,<br/>indexed_chunks, searchable:true}"]

    FAIL["status=FAILED + error"] --> WHE["se webhook_url:<br/>POST {status:error, job_id, error_detail}"]
    WHE --> RAISE["raise → Celery retry (até 3x)"]

    QUERY["POST /rag/query (vindo do sim)<br/>{query, top_k, filter:{numero_processo}}"] --> EMBQ["embedding da pergunta"]
    EMBQ --> SEARCH["busca cosine na tabela embeddings<br/>filtrando metadata.numero_processo"]
    SEARCH --> RES["results[] (trechos + metadata)"]

    classDef external fill:#e8f0fe,stroke:#1f558a,color:#18446e;
    classDef terminal fill:#e9f7ef,stroke:#1e8449,color:#145a32;
    classDef err fill:#fde2e2,stroke:#c0392b,color:#7b241c;
    class OCR external;
    class OK,RES terminal;
    class FAIL,WHE,RAISE err;
```

---

## Notas

1. **Quem dispara o OCR:** o componente `sim` `chat-fullpage`
   (`dispararOcrAutomatico()`) ao abrir/encontrar um processo. Ele cria uma
   `Notificacao` local e chama `sim-mni` `/api/processo/ocr` com header
   `X-API-Token` (config `services.ms_mni`).

2. **RAG não passa pelo sim-mni:** a indexação acontece dentro do `sim-ocr`
   (chunk → embeddings → PGVector) durante o `process_ocr_rag_task`. A consulta
   (`/rag/query`) é feita **direto pelo `sim`** (VectorChatService) ao
   `sim-ocr`. O `sim-mni` só participa da ingestão.

3. **Webhook vs. polling (sim-mni):** `OCRWebhookController` e `OCRPollStatus`
   têm a mesma lógica (`dispararJuncaoSeCompleto` duplicada). O polling
   (`ocr:poll-status`, agendado a cada minuto em `routes/console.php`) cobre
   ambientes sem webhook.

4. **Progresso em tempo real:** `sim-mni` notifica `sim`
   (`/webhook/ocr-progresso`); o `OcrProgressoWebhookController` recalcula
   processados/total, marca `ChatSessao.ocr_concluido` quando 100% e dispara
   `OcrProgressoAtualizadoEvent` no canal `ocr_progresso_{numero}` para o chat.

5. **Blocos desativados (`✗`):** `JuntarOCRProcessoJob` e
   `SyncBaseConhecimentoSamiaJob` estão comentados no sim-mni. Hoje o fluxo vai
   até marcar cada documento `ocr_processado` + notificar progresso — não
   consolida `processo.txt` nem sincroniza KB própria, pois o sim-ocr já indexa
   por documento no PGVector.

6. **Resiliência:** `OCRRequestJob` é idempotente (não reenvia se já há
   `ocr_job_id`); `process_ocr_rag_task` tem `autoretry` 3× (30s); status
   `error`/`failed` libera o documento para reenvio no sim-mni.

## Arquivos de referência

| Serviço | Etapa | Arquivo |
|---------|-------|---------|
| sim | Dispara OCR | `resources/views/livewire/samia/chat-fullpage.blade.php` (`dispararOcrAutomatico`) |
| sim | Recebe progresso | `app/Http/Controllers/Webhook/OcrProgressoWebhookController.php` |
| sim | Consulta RAG | `app/Services/VectorChatService.php` |
| sim-mni | Orquestra OCR | `app/Http/Controllers/Api/OCRProcessoController.php` |
| sim-mni | Envia ao microserviço | `app/Jobs/OCRRequestJob.php` |
| sim-mni | Webhook de retorno | `app/Http/Controllers/Api/OCRWebhookController.php` |
| sim-mni | Polling (fallback) | `app/Console/Commands/OCRPollStatus.php` |
| sim-mni | Consolidação (✗) | `app/Jobs/JuntarOCRProcessoJob.php` |
| sim-ocr | Entrada da API | `api/app/routers/documents.py` (`/documents/process`) |
| sim-ocr | OCR + RAG | `worker/app/tasks/ocr_rag_task.py` |
| sim-ocr | OCR engine | `worker/app/services/docling_client.py` |
| sim-ocr | Chunk / Embed / Vetor | `worker/app/services/{chunker,embedder,vector_store}.py` |
| sim-ocr | Webhook | `worker/app/services/webhook.py` |
| sim-ocr | Consulta RAG | `api/app/routers/rag.py` (`/rag/query`) |
