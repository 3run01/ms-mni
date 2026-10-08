# Respostas do desenvolvedor — erros-codigo-estavel-visualizar (2026-10-08)

Perguntas: `.handoff/clarifier-questions.md`.

## Q-03 / M1 — sinal de processo inexistente: (a) captura real feita

Captura em 2026-10-08, TJAP 1º e 2º grau, operação `consultarProcesso` (via `IntegracaoBase::makeSoapRequest`), credencial válida (par padrão `config('pje.credenciais_padrao')`), CNJ válido e inexistente, `incluirDocumentos=false`, `movimentos=false`:

| Tribunal | numeroProcesso | Retorno cru |
|---|---|---|
| TJAP 1º Grau (id 1, `/1g`) | `99999901120268030001` | `sucesso=false`, `mensagem="Processo de número 99999901120268030001 não encontrado!"`; chaves do retorno só `sucesso`, `mensagem` (sem `processo`); sem SoapFault |
| TJAP 2º Grau (id 2, `/2g`) | `99999901420268030000` | `sucesso=false`, `mensagem="Processo de número 99999901420268030000 não encontrado!"`; idem |

Caminho do serviço (`ConsultarProcessoService::execute`): lança `MNIException` code 500 com `getError()` = o mesmo texto, intacto (nenhuma regra de `PadronizarMensagemMniService::REGRAS` casa).

Decisão: o sinal de processo inexistente é `sucesso=false` com `mensagem` no formato `Processo de número <dígitos> não encontrado!` (fixar no RIGID esse literal/padrão; o número varia). Opções (c)/(d) descartadas.
- (i) Sigilo: não foi possível capturar (sem processo sigiloso conhecido). Seguir a recomendação: texto de sigilo distinguível → RF-06 (como hoje); só o padrão acima vira 404.
- (ii) RF-04 vale também no caminho do download (documento no banco, processo que não existe no tribunal): sim, com AC próprio.

## Q-01 / M2 — escopo do 403: (c)
403 `credencial_pje_recusada` só quando a chamada MNI recusada usou o par `login_pje`/`senha_pje` enviado pelo cliente na requisição. Recusa do par padrão (injetado por `InjectCredenciaisPjePadrao`) ou da credencial do tribunal segue RF-06 (como hoje).

## Q-05 / M3 — vídeos: (a)
`video/mp4` e `video/quicktime` ficam fora de RF-03/RF-04: "comportamento atual mantido", sem fixar status. Registrar como limitação conhecida; os dois bugs (falso 200 com XML salvo como .mp4; URL fixa do TJAP 1º grau em `scripts/mni_consultar_documento.sh:20`) entram no relatório final.

## Demais — todas as recomendações aceitas
- Q-02: (a) HTML legado (`text/html` baixado sem `path_html`/`conteudo_html`, reconsulta em SalvarDocumentoProcessoService.php:111 falha) fica como hoje (RF-06, 500); RF-08 só para documentos com `path_html`/`conteudo_html`; RF-03 exclui a recuperação complementar de HTML.
- Q-04: (b) classificar credencial recusada só por `loginfailed` (case-insensitive) no texto cru.
- Q-06: (a) falhas não classificadas "como hoje" em cada endpoint (`/processo/visualizar` `{error, line, file}`; `/documento/visualizar` `{message}`).
- Q-07: (a) manter idêntico o texto dos 500 não classificados nos wrappers de download; RF-06 ganha AC explícito.
- Q-08: (a) testes injetam a falha em ProcessoService/ConsultarProcessoService ou abaixo (consulta) e em `consultarDocumento`/IntegracaoBase ou abaixo (download); autorizado trocar `new ProcessoService()` (DocumentoController.php:172) pela instância injetada, sem mudança de comportamento.
- Q-09: (a) comparação estrutural ignorando `description`/`example(s)`/`summary`, incluindo `x-dependencia`, nós copiados literalmente.
- Q-10: (a) `message` com literais fixos da cópia: "Credencial PJe recusada pelo tribunal" e "Processo não encontrado no tribunal".
