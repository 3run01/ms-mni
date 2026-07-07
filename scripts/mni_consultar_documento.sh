#!/bin/bash

# Parâmetros recebidos
NUMERO_PROCESSO=$1
ID_DOCUMENTO=$2
TEMP_FILE=$3
LOGIN_PJE=$4
SENHA_PJE=$5

# Validar parâmetros obrigatórios
if [ -z "$NUMERO_PROCESSO" ] || [ -z "$ID_DOCUMENTO" ] || [ -z "$TEMP_FILE" ] || [ -z "$LOGIN_PJE" ] || [ -z "$SENHA_PJE" ]; then
    echo "Erro: Parâmetros obrigatórios não fornecidos"
    echo "Uso: $0 <numero_processo> <id_documento> <temp_file> <login_pje> <senha_pje>"
    exit 1
fi

echo "Baixando vídeo do processo: $NUMERO_PROCESSO, documento: $ID_DOCUMENTO (Login: $LOGIN_PJE)"

# Fazer a requisição SOAP e salvar o resultado no arquivo temporário
curl --location 'https://pje.tjap.jus.br/1g/intercomunicacao' \
--header 'Content-Type: application/xml' \
--header 'Cookie: JSESSIONID=siNt82ca5avW1y20Luh4mB7WQ4wkhxgiO9K_Gb2C.prd-mni-1g-7b4fb4cf44-65bc2; PJE-TJAP-1G-StickySessionRule="prd-mni-1g-7b4fb4cf44-65bc2:pje-tjap-1g"; __cf_bm=Hi.ELAwjhOxHN93bnZX1q0HYTri5JI2Uv.YpAc7oIA4-1764865281.2090957-1.0.1.1-E_NsVzZ6MCaE8dTLtQm4WlL7_KxIw6MpJD9ZLHj2g4srjPzfEamd61ZDKklWiRgj.lnRXqGk8Xl2N9CGCsoejoz2qJr9qTiA0VONGx...A6LWTBom3t5OQ4qJKa.X.y6; INGRESSCOOKIE=e0a8fa0f8b343bb2' \
--data-raw "<soapenv:Envelope xmlns:soapenv=\"http://schemas.xmlsoap.org/soap/envelope/\" xmlns:ser=\"http://www.cnj.jus.br/servico-intercomunicacao-2.2.2/\" xmlns:tip=\"http://www.cnj.jus.br/tipos-servico-intercomunicacao-2.2.2\">
   <soapenv:Header/>
   <soapenv:Body>
      <ser:consultarProcesso>
         <tip:idConsultante>$LOGIN_PJE</tip:idConsultante>
         <tip:senhaConsultante>$SENHA_PJE</tip:senhaConsultante>
         <tip:numeroProcesso>$NUMERO_PROCESSO</tip:numeroProcesso>
         <tip:movimentos>false</tip:movimentos>
         <tip:incluirCabecalho>false</tip:incluirCabecalho>
         <tip:incluirDocumentos>false</tip:incluirDocumentos>
         <tip:documento>$ID_DOCUMENTO</tip:documento>
      </ser:consultarProcesso>
   </soapenv:Body>
</soapenv:Envelope>" \
--output "$TEMP_FILE"

# Verificar se o download foi bem-sucedido
if [ $? -eq 0 ]; then
    echo "Vídeo baixado com sucesso para: $TEMP_FILE"
    exit 0
else
    echo "Erro ao baixar o vídeo"
    exit 1
fi
