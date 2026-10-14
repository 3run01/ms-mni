# Input confirmado — baixar-documentos-mni-em-lote

## Descrição do desenvolvedor (resumo fiel)

Hoje `app/Jobs/BaixarDocumentoMNIJob.php` recebe documento por documento; obter o conteúdo de cada documento exige várias consultas ao MNI, sobrecarregando o MNI. O desenvolvedor verificou que a operação `consultarProcessos` (plural) retorna vários documentos **com conteúdo** numa única chamada. A ideia é baixar **20 documentos de uma vez por JOB**, somente os documentos pendentes de download.

## Resumo

Baixar até 20 documentos pendentes do mesmo processo numa única chamada MNI `consultarProcessos` por job, em vez de um job + uma consulta por documento.

## Critérios de aceite confirmados (fonte da verdade)

1. Um job de download processa até 20 documentos do mesmo processo com **uma única** chamada `consultarProcessos`, enviando os `idDocumento` como múltiplos elementos `documento`.
2. Só entram no lote documentos com status `pendente` (ou `erro`, quando reprocessados); documento `baixado` com arquivo presente no S3 nunca é reenviado ao MNI.
3. Processo com mais de 20 documentos pendentes é dividido em lotes de no máximo 20, um job por lote; o tamanho do lote é configurável (padrão 20).
4. Cada documento retornado é salvo no S3 com o mesmo tratamento por mimetype de hoje (HTML, PDF, MP4, QuickTime, áudio) e marcado `baixado`.
5. Falha parcial: documento pedido que não volta na resposta (ou volta com conteúdo vazio/bloqueado) é marcado `erro` com `tentativas_download` incrementado, sem impedir que os demais do lote sejam salvos.
6. Falha total (exceção SOAP / `sucesso=false`) marca todos os documentos do lote como `erro`; o job é registrado como falho e recuperável por `mni:baixar-documento-pendente`.
7. Todos os pontos que hoje despacham `BaixarDocumentoMNIJob` passam a usar o fluxo em lote: `SalvarDocumentoProcessoService::salvarDocumento`, `mni:baixar-documento-pendente`, `MediaRedownload`, `MediaFixInconsistent`, `MediaRedownloadTest`.

## Observações do router (fatos do código, não requisitos)

- `ConsultarDocumentoService` usa hoje `consultarProcesso` (singular) com um único `documento`; todos os `download*()` de `SalvarDocumentoProcessoService` passam por `consultarDocumento()`.
- `consultarProcessos` aceita um único `numeroProcesso` → lote = documentos do mesmo processo (mesmo tribunal, mesmas credenciais).
- O conteúdo binário chega via MTOM/XOP (`xop:Include href="cid:..."`); existe tratamento MTOM em `SoapClientMNI` / `extrairConteudoMTOM`.
- Semântica de `pagina` / `tamanhoPagina` / `totalRegistros` na resposta não está clara (o exemplo pediu página 3, tamanho 3, retornou 2 documentos e `totalRegistros=1`).
- Não está confirmado se todos os tribunais cadastrados expõem `consultarProcessos` no WSDL.

## Amostra SOAP (sanitizada — credenciais, CPFs, nomes, assinaturas e certificados removidos)

### Request

```xml
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
 <soapenv:Header/>
 <soapenv:Body>
  <tns:consultarProcessos xmlns:ns2="http://www.cnj.jus.br/tipos-servico-intercomunicacao-2.2.3" xmlns:tns="http://www.cnj.jus.br/servico-intercomunicacao-2.2.3/">
   <ns2:idConsultante>{LOGIN}</ns2:idConsultante>
   <ns2:senhaConsultante>{SENHA}</ns2:senhaConsultante>
   <ns2:numeroProcesso>00060548120248030001</ns2:numeroProcesso>
   <ns2:movimentos>false</ns2:movimentos>
   <ns2:incluirCabecalho>false</ns2:incluirCabecalho>
   <ns2:incluirDocumentos>false</ns2:incluirDocumentos>
   <ns2:documento>31786959</ns2:documento>
   <ns2:documento>30928313</ns2:documento>
   <ns2:pagina>3</ns2:pagina>
   <ns2:tamanhoPagina>3</ns2:tamanhoPagina>
  </tns:consultarProcessos>
 </soapenv:Body>
</soapenv:Envelope>
```

### Response

```xml
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
 <soap:Body>
  <ns8:consultarProcessosResposta
    xmlns:ns8="http://www.cnj.jus.br/servico-intercomunicacao-2.2.3/"
    xmlns:ns2="http://www.cnj.jus.br/intercomunicacao-2.2.3"
    xmlns="http://www.cnj.jus.br/tipos-servico-intercomunicacao-2.2.3">
   <sucesso>true</sucesso>
   <mensagem>Processos consultados com sucesso</mensagem>
   <processos>
    <ns2:documento idDocumento="31786959" tipoDocumento="4050017" dataHora="20261007143251" mimetype="text/html" nivelSigilo="0" movimento="27749996" hash="ab3ba6ca026a1c2cb631f9024c5927e0" descricao="Intimação">
     <ns2:conteudo>
      <xop:Include xmlns:xop="http://www.w3.org/2004/08/xop/include" href="cid:0fcfd245-...-9944@www.cnj.jus.br"/>
     </ns2:conteudo>
     <ns2:assinatura assinatura="..." dataAssinatura="20261007143251" cadeiaCertificado="..." algoritmoHash="MD5" codificacaoCertificado="PkiPath"/>
     <ns2:outroParametro nome="mni:pje:documento:storageId" valor="1099f027..."/>
     <ns2:outroParametro nome="mni:pje:documento:tamanho" valor="39490"/>
     <ns2:outroParametro nome="docValido" valor="true"/>
     <ns2:outroParametro nome="nomeDocumento" valor="Intimação"/>
     <ns2:outroParametro nome="mni:pje:documento:dataInclusao" valor="1791394221043"/>
     <ns2:outroParametro nome="mni:pje:documento:dataJuntada" valor="1791394371954"/>
     <ns2:outroParametro nome="mni:pje:linkValidacao" valor="https://pje.tjap.jus.br:443/1gmp/Processo/ConsultaDocumento/listView.seam?x=..."/>
     <!-- demais outroParametro (criadorArquivo, usuarioJuntadaArquivo, documentoIdentificacao...) omitidos -->
    </ns2:documento>
    <ns2:documento idDocumento="30928313" tipoDocumento="57" dataHora="20260903083044" mimetype="application/pdf" nivelSigilo="0" movimento="26931611" hash="d65b496f25f7e34797c4ff8e99174128" descricao="IP 1801/2024 - 6a Remessa Parcial_...">
     <ns2:conteudo>
      <xop:Include xmlns:xop="http://www.w3.org/2004/08/xop/include" href="cid:0fcfd245-...-9945@www.cnj.jus.br"/>
     </ns2:conteudo>
     <ns2:assinatura assinatura="..." dataAssinatura="20260903083030" cadeiaCertificado="..." algoritmoHash="SHA-256" codificacaoCertificado="PkiPath"/>
     <ns2:outroParametro nome="mni:pje:documento:tamanho" valor="215565"/>
     <ns2:outroParametro nome="docValido" valor="true"/>
     <ns2:outroParametro nome="mni:pje:documento:dataJuntada" valor="1788435044874"/>
     <!-- demais outroParametro omitidos -->
    </ns2:documento>
   </processos>
   <pagina>3</pagina>
   <tamanhoPagina>3</tamanhoPagina>
   <totalRegistros>1</totalRegistros>
  </ns8:consultarProcessosResposta>
 </soap:Body>
</soap:Envelope>
```

Notas da amostra:
- Os documentos vêm como filhos diretos de `<processos>` (não aninhados em `<processo>`), cada um com `<conteudo>` via XOP.
- `idConsultante` e `senhaConsultante` vêm dentro do body (como em `consultarProcesso`); o header WS-Security do exemplo original era o placeholder padrão do SoapUI.
