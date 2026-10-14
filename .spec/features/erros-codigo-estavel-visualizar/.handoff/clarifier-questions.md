# Perguntas do clarifier — erros-codigo-estavel-visualizar (SPEC v1.0, analyze)

Validação dos marcadores: M1 procede (só fixtures ConsultarProcessosServiceTest.php:238 e ConsultaMNISemSucessoExceptionTest.php:18; nenhuma captura real). M2 procede, mas a opção (b) da SPEC não resolve: o middleware sobrescreve o par (InjectCredenciaisPjePadrao.php:35-38) e o caso de SalvarDocumentoProcessoService.php:111 ocorre mesmo com o par completo do cliente (ver Q-01). M3 procede, mas a premissa "500 como hoje" não está verificada (ver Q-05).

## Q-01 · Alta · [Marker M2] · RF-01, RF-03
O 403 `credencial_pje_recusada` vale para qual credencial recusada?
- Evidência: sem o par completo na requisição, o middleware grava o par padrão em `login_pje`/`senha_pje`, então o controller não sabe pelo `$request` se o par veio do cliente. Mesmo com o par completo, um documento `text/html` já `baixado` sem `path_html`/`conteudo_html` passa por DocumentoController.php:77-78 → SalvarDocumentoProcessoService.php:109-111, onde `downloadHTML($documento)` usa a credencial do tribunal. A opção (b) da SPEC daria 403 nesse caso.
- Impacto: no cliente, o 403 marca a credencial do usuário como `invalida` e bloqueia as consultas até ele trocá-la. Uma recusa da credencial do tribunal bloquearia um usuário com credencial correta.
- (a) qualquer recusa de login → 403
- (b) 403 quando a requisição original (antes do middleware) trouxe o par completo
- **(c) [RECOMENDADA]** 403 só quando a chamada MNI recusada usou o par enviado pelo cliente; recusa do par padrão ou da credencial do tribunal segue RF-06. Única sem falso positivo e coerente com a cópia acordada (:139, "recusou o par").
- (d) (c) + repassar o par da requisição em :111 (fora do Scope hoje)

## Q-02 · Alta · [Contradiction] · RF-08 × RF-03 × RF-06
Documento `text/html` `baixado`, PDF no S3, `path_html` e `conteudo_html` vazios, reconsulta de :111 falha → o que responder?
- Evidência: hoje a exceção sobe antes do link e sai 500 `"Erro ao processar documento HTML: "` (SalvarDocumentoProcessoService.php:110-111 sem try; DocumentoController.php:121-127). RF-08 descreve 200 com `conteudo_html` null, o que o código não faz; RF-03 e RF-06 também cobrem o cenário.
- **(a) [RECOMENDADA]** como hoje (RF-06, 500); RF-08 só para documentos com `path_html`/`conteudo_html`; RF-03 exclui a recuperação complementar de HTML. Coerente com Q-01(c).
- (b) 403 (só com Q-01 a/b)
- (c) 200 com `conteudo_html` null (mudança de comportamento, AC novo)

## Q-03 · Alta · [Marker M1] · RF-02, RF-04, RNF-04
Qual é o sinal concreto de processo inexistente no tribunal?
- Impacto: sinal inventado → o 404 pode nunca disparar (vira 500) ou disparar em sigilo (o cliente diria que o processo não existe).
- **(a) [RECOMENDADA]** captura real antes do PLAN: TJAP 1º e 2º grau, CNJ válido mas inexistente, credencial válida; registrar `sucesso`, `mensagem` literal ou texto do fault, e ver se muda para processo sob sigilo. O RIGID fixa o literal.
- (b) entregar só o 403 agora; RF-02, RF-04 e o teste do 404 com codigo vão para feature seguinte (altera AC2/AC7 confirmados)
- (c) regra provisória: `sucesso=false` com `mensagem` contendo "processo não encontrado" (sem evidência)
- (d) `sucesso=true` sem `processo`/`dadosBasicos` (hoje 500 em ProcessoService.php:53; sem evidência)
- Subperguntas: (i) sigilo com texto distinguível → recomendado RF-06; se o texto for igual ao de inexistente, 404. (ii) RF-04 vale no caminho do download (documento no banco, processo sumiu do tribunal)? Recomendado sim, com AC próprio após a captura.

## Q-04 · Média-alta · [Ambiguity] · RF-01, RF-03
Qual trecho do texto classifica "credencial recusada"?
- Evidência: a regra de login de REGRAS casa `erro ao realizar login via mni` OU `loginfailed` (PadronizarMensagemMniService.php:35-44). As duas formas verificadas contêm `loginFailed`. Depois do construtor, `getError()` devolve o texto normalizado (MNIException.php:18) e não dá para saber qual trecho casou.
- (a) os dois trechos de REGRAS
- **(b) [RECOMENDADA]** só `loginfailed` (case-insensitive) no texto cru. Cobre toda a evidência e reduz o falso positivo.

## Q-05 · Média · [Marker M3 / Premise] · RF-03, RF-04, RF-06
O que vale para `video/mp4` e `video/quicktime`?
- Evidência: `curl` sem `--fail` sai 0 mesmo com HTTP 500 (scripts/mni_consultar_documento.sh:20-46); sem MTOM, `extrairConteudoMTOM` devolve o corpo cru (SalvarDocumentoProcessoService.php:806-808); assinatura inválida só gera warning (:545-550, :668-673) e o arquivo é gravado como `baixado` (:557-562). Uma recusa pode virar 200 com link para um XML salvo como .mp4, em cache. URL fixa do TJAP 1º grau (:20).
- **(a) [RECOMENDADA]** tirar vídeo de RF-03/RF-04 ("comportamento atual mantido", sem fixar status); registrar limitação conhecida e reportar os dois bugs no relatório final.
- (b) exigir 403/404 inspecionando o arquivo (exige captura e mexe no script)
- (c) feature separada para vídeo

## Q-06 · Média · [Contradiction] · RF-06 (AC5)
AC5 diz "500 com `error`"; vale ao pé da letra em `/documento/visualizar`?
- Evidência: hoje `/documento/visualizar` responde 500 `{message}` (DocumentoController.php:61-67) e a cópia acordada declara 500 = `ErroMensagem` (:360-364). `/processo/visualizar` responde `{error, line, file}`.
- **(a) [RECOMENDADA]** "como hoje" em cada endpoint (redação atual de RF-06).
- (b) unificar em `error` (diverge de CT-02 e do Scope)

## Q-07 · Média · [Ambiguity] · RF-06 (download)
Preservar o sinal mexe nos wrappers (:232, :321, :353, :719). Manter o texto degenerado dos 500 não classificados?
- Evidência: hoje sai `"Erro ao processar documento PDF: "` com sufixo vazio, porque `MNIException` não chama `parent::__construct` (MNIException.php:14-21).
- **(a) [RECOMENDADA]** manter idêntico; RF-06 ganha AC explícito para falha MNI não classificada no download de PDF.
- (b) wrappers passam a usar `getError()` (ampliação de escopo; conferir RNF-01)
- (c) corrigir `parent::__construct` globalmente (FLEXIBLE desaconselha)

## Q-08 · Média-baixa · [Gap] · RNF-04
Onde os testes de feature injetam a falha?
- Evidência: sem seam na consulta (`new ProcessoService()` DocumentoController.php:172; `new ConsultarProcessoService()` ProcessoService.php:19; `new IntegracaoBase` ConsultarProcessoService.php:40). No download há seam: `app()->makeWith(IntegracaoBase::class)` (ConsultarDocumentoService.php:119). `partialMock` de `getDocumento` pula o reembrulho de :121-127.
- **(a) [RECOMENDADA]** injetar em ProcessoService/ConsultarProcessoService ou abaixo (consulta) e em `consultarDocumento`/IntegracaoBase ou abaixo (download); autoriza trocar `new ProcessoService()` pela instância injetada, sem mudança de comportamento.
- (b) qualquer seam, inclusive `partialMock` de `getDocumento`/`vericarExistenciaDocumento`

## Q-09 · Baixa · [Ambiguity] · RF-09
Critério do "0 diferenças" entre openapi oficial e cópia?
- **(a) [RECOMENDADA]** comparação estrutural ignorando `description`/`example(s)`/`summary`, incluindo `x-dependencia`, nós copiados literalmente.
- (b) nós idênticos byte a byte
- (c) remover `x-dependencia` (gera diferença; muda o AC)

## Q-10 · Baixa · [Ambiguity] · RNF-01, RF-01 a RF-04
`message` dos corpos com `codigo` deve ser literal fixo?
- **(a) [RECOMENDADA]** fixar os literais da cópia: "Credencial PJe recusada pelo tribunal" e "Processo não encontrado no tribunal".
- (b) texto livre pt-BR, verificado só pela ausência de credenciais e URL

## Observações sem pergunta (FLEXIBLE)
- Testar com "MNIException com texto cru de login" mockando ProcessoService só dá 403 se a classificação for por texto no controller.
- `partialMock` de `getDocumento` não exercita o código alterado (Q-08).
