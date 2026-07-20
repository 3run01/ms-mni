# Visualizar documento baixado na página do processo

**Data:** 2026-07-16
**Status:** Aprovado

## Objetivo

Na aba **Documentos** da página do processo (`/processos/{id}`), permitir visualizar documentos com status `baixado` sem sair da página, através de um modal com o conteúdo embutido.

## Contexto

- Documentos ficam em `processo_documentos`; arquivos no S3/R2 (`path` para o binário original, `path_html`/`conteudo_html` para versão HTML).
- Status relevante: `ProcessoDocumento::STATUS_BAIXADO` (`baixado`).
- O endpoint de API existente (`GET /api/.../documento/visualizar`) exige credenciais PJe — não serve para a UI web autenticada por sessão.
- `SalvarDocumentoProcessoService::obterConteudoHtml()` já resolve o conteúdo HTML (coluna legado ou objeto no S3).

## Design

### Backend

Novo método `documento()` em `App\Http\Controllers\ProcessoController`:

- **Rota:** `GET /processos/{processo}/documentos/{documento}`, nome `processos.documento`, dentro do grupo `auth:web`, com scoped binding (`{documento}` resolve `ProcessoDocumento` pertencente ao processo; 404 caso contrário).
- **Guardas:** `status !== 'baixado'` → 404.
- **Documento HTML** (`mimetype === 'text/html'`): obtém conteúdo via `obterConteudoHtml()`; `null` → 404; senão `response($html)` com `Content-Type: text/html; charset=utf-8`.
- **Demais mimetypes** (PDF etc.): `redirect()->away(Storage::disk('s3')->temporaryUrl($documento->path, now()->addMinutes(60)))`. O browser renderiza o PDF nativamente dentro do iframe.
- Falha ao gerar URL temporária → 404.

### Frontend

Em `resources/js/pages/processos/show.tsx`:

- Nova coluna de ações (header vazio) na tabela de documentos: botão ghost com ícone `Eye` (lucide), `aria-label="Visualizar documento"`, renderizado apenas quando `doc.status === 'baixado'`.
- `Dialog` (shadcn) com `max-w-4xl` e conteúdo de ~85vh de altura; título = `doc.descricao` (fallback: tipo/`—`).
- Corpo do modal: `<iframe src={/processos/${processo.id}/documentos/${doc.id}}>` ocupando toda a área. O atributo `sandbox` é aplicado **apenas quando `doc.mimetype === 'text/html'`** (isola scripts do HTML vindo do tribunal); iframes sandboxed bloqueiam o viewer nativo de PDF no Chrome, então PDFs usam iframe sem sandbox.
- Estado local: `docAberto: DocumentoItem | null`; fechar modal limpa o estado.

### Erros

Documento sem arquivo recuperável → iframe exibe a página 404 do Laravel. Aceitável para v1.

### Testes

Feature test da rota:

1. Documento HTML baixado → 200 com conteúdo HTML.
2. Documento PDF baixado → redirect para URL temporária.
3. Documento `pendente` → 404.
4. Documento de outro processo (binding escopado) → 404.
5. Não autenticado → redirect para login.

## Fora de escopo

- Botão de download separado.
- Re-download automático de documentos com erro/pendentes pela UI.
- Visualização para status diferente de `baixado`.
