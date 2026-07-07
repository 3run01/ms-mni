# Substituição do OCR Tesseract pelo Microserviço SIM OCR

**Data:** 2026-05-07
**Branch:** feature/webhook-download

## Contexto

O sistema atual realiza OCR de documentos judiciais usando duas vias:

1. **`OCRRequestJob`** — envia para um microserviço externo (endpoint legado em `SIM_OCR_URL/api/documento/ocr`)
2. **`OCRDocumentoJob`** / **`OCRDocumentoService`** — processa localmente com Tesseract (binário instalado no Docker)

Foi desenvolvido o microserviço **SIM OCR** (`ocr.mpap.private`) com API padronizada, suporte a webhook nativo e integração direta com S3. O objetivo é substituir completamente o Tesseract local e o endpoint legado por esse novo serviço.

## Decisões de design

- **Formato de saída:** mantém `.txt` (configura `path_destino` com extensão `.txt` na chamada à API)
- **Mecanismo de retorno:** webhook (o microserviço chama de volta o sim-mni ao concluir)
- **Fonte do documento:** bucket S3 direto (`bucket_origem` + `path_origem`) — os arquivos já estão no S3
- **Escopo:** substituição completa — remove Tesseract, código morto e dependências Docker

## Fluxo após a mudança

```
SalvarDocumentoProcessoService::realizarOCR()
  └─ dispatch OCRRequestJob (atualizado)
       └─ POST {SIM_OCR_URL}/documents/process
            {
              bucket_origem:  <nome cadastrado no OCR admin>,
              path_origem:    <caminho do PDF no S3>,
              bucket_destino: <nome cadastrado no OCR admin>,
              path_destino:   documentos-processos/{numero}/{id}.txt,
              webhook_url:    {SIM_OCR_WEBHOOK_URL}
            }
       └─ resposta HTTP 202: { id: "uuid", status: "pending" }
       └─ salva job_id em processo_documentos.ocr_job_id
       └─ marca ocr_enviado_fila = true

[microserviço processa o PDF e salva .txt no bucket destino]

POST /api/ocr/webhook  ← novo endpoint (sem auth de usuário)
  └─ valida que job_id existe em processo_documentos (404 se não existir)
  └─ status "success":
       └─ ocr_processado = true
       └─ ocr_concluido_data = now()
       └─ se todos os docs do processo estão prontos:
            └─ dispatch JuntarOCRProcessoJob
  └─ status "error":
       └─ loga error_detail
       └─ ocr_enviado_fila = false (libera para reprocessamento)

JuntarOCRProcessoJob  ← sem alterações
  └─ lê .txt individuais do S3
  └─ concatena em documentos-processos/{numero}/processo.txt
```

## Componentes alterados

### `OCRRequestJob`
- Endpoint: `SIM_OCR_URL/api/documento/ocr` → `SIM_OCR_URL/documents/process`
- Payload: substitui formato legado por `bucket_origem`, `path_origem`, `bucket_destino`, `path_destino`, `webhook_url`
- Autenticação: `Authorization: Bearer {SIM_OCR_API_TOKEN}`
- Após receber HTTP 202: persiste `job_id` em `processo_documentos.ocr_job_id`

### `OCRDocumentoController` / `OCRProcessoController`
- Se dispararem `OCRDocumentoJob` diretamente, passam a disparar `OCRRequestJob`

## Componentes adicionados

### `OCRWebhookController` (novo)
- Rota: `POST /api/ocr/webhook`
- Sem middleware de autenticação de usuário
- Segurança: valida `job_id` contra `processo_documentos.ocr_job_id` — retorna 404 para job_id desconhecido
- Atualiza status do documento e dispara consolidação quando todos os docs do processo estão prontos

### Migration
- Tabela: `processo_documentos`
- Nova coluna: `ocr_job_id` (string, nullable, index)

## Componentes removidos

| Componente | Motivo |
|---|---|
| `app/Jobs/OCRDocumentoJob.php` | Processamento local Tesseract |
| `app/Services/Processo/OCRDocumentoService.php` | Lógica Tesseract/pdftoppm/pdftotext |
| `app/Console/Commands/DiagnoseOCRPerformance.php` | Diagnóstico específico do Tesseract |
| `composer.json`: `thiagoalessio/tesseract_ocr` | Dependência Tesseract PHP |
| Docker: `tesseract-ocr`, `tesseract-ocr-data-por` | Binário e dados PT |
| Docker: `poppler-utils`, `ghostscript` | Utilitários PDF usados só pelo Tesseract |

## Banco de dados

```sql
ALTER TABLE processo_documentos
  ADD COLUMN ocr_job_id VARCHAR(255) NULL,
  ADD INDEX idx_ocr_job_id (ocr_job_id);
```

## Variáveis de ambiente

| Variável | Status | Descrição |
|---|---|---|
| `SIM_OCR_URL` | existente (atualizar valor) | URL base do novo microserviço |
| `SIM_OCR_API_TOKEN` | nova | Bearer token do SIM OCR |
| `SIM_OCR_BUCKET_ORIGEM` | nova | Nome do bucket de origem cadastrado no admin do OCR |
| `SIM_OCR_BUCKET_DESTINO` | nova | Nome do bucket de destino cadastrado no admin do OCR |
| `SIM_OCR_WEBHOOK_URL` | nova | URL pública do sim-mni para receber callbacks |
| `API_TOKEN` | remover (se sem outro uso) | Token do serviço legado |

## Pré-requisito operacional

Antes do deploy, cadastrar os dois buckets no painel admin do SIM OCR (`http://ocr.mpap.private:8000/admin` > aba Buckets):

1. **Bucket origem** — bucket principal do sim-mni (fonte dos PDFs)
2. **Bucket destino** — bucket OCR do sim-mni (destino dos `.txt`)

Testar conexão em ambos antes de ativar o fluxo em produção.

## O que não muda

- `JuntarOCRProcessoJob` — lógica de consolidação inalterada
- Disco S3 `s3_ocr` e configuração de credenciais AWS OCR
- Formato e path dos arquivos `.txt` no S3
- `VERSAO_OCR` — continua sendo usada para cache invalidation
- `OCRDocumentoController` / `OCRProcessoController` — apenas ajuste de qual job é despachado
