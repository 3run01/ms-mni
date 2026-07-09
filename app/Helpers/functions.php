<?php

use Carbon\Carbon;
use LSNepomuceno\LaravelA1PdfSign\ManageCert;

if (!function_exists('validateCertificateDate')) {
    /**
     * validateCertificateDate - Helper to validate if certificate has expired
     *
     * @return bool
     *
     */
    function validateCertificateDate()
    {
        try {
            $cert = new ManageCert;
            $cert->setPreservePfx();
            $cert->fromPfx(storage_path() . '/certificate/' . env('FILENAME_A1'), env('SENHA_A1'));
            $data = $cert->getCert()->data;
            $dateValidTo = Carbon::createFromFormat('ymdHisP', $data['validTo']);
            $now = Carbon::now();
            if ($now->gt($dateValidTo)) {
                throw new Exception("Certificado A1 vencido, entre em contato com o suporte!", 1);
            }
            return true;
        } catch (\Throwable $th) {
            throw $th;
        }
    }
}


if (!function_exists('getHashA1Base64')) {
    /**
     * getHashA1Base64 - Helper to validate if certificate has expired
     *
     * @return String
     * @throws Throwable
     */
    function getHashA1Base64()
    {
        try {
            $cert = new ManageCert;
            $cert->setPreservePfx();
            $cert->fromPfx(storage_path() . '/certificate/' . env('FILENAME_A1'), env('SENHA_A1'));
            $hash = $cert->getCert()->data['hash'];
            return base64_encode($hash);
        } catch (\Throwable $th) {
            throw $th;
        }
    }
}

function cleanCPF_CNPJ($valor)
{
    $valor = trim($valor);
    $valor = str_replace(".", "", $valor);
    $valor = str_replace(",", "", $valor);
    $valor = str_replace("-", "", $valor);
    $valor = str_replace("/", "", $valor);
    return $valor;
}

function cleanFormatReal($valor)
{
    $valor1 = str_replace(".", "", $valor);
    $valor2 = str_replace(",", ".", $valor1);
    return $valor2;
}

function formatReal($valor)
{
    return number_format($valor, 2, ',', '.');
}

function cleanNumeroProcesso($valor)
{
    $valor = trim($valor);
    $valor = str_replace(".", "", $valor);
    $valor = str_replace("-", "", $valor);
    return $valor;
}

/**
 * Extrai o codigo CNJ do tribunal (2 digitos, posicoes 14-15 zero-based) do
 * numero do processo. Formato CNJ: NNNNNNN-DD.AAAA.J.TR.OOOO (20 digitos limpo).
 * Retorna null se o numero nao for valido (menos de 16 digitos apos limpeza).
 */
function extrairCodigoTribunal($numeroProcesso): ?string
{
    $limpo = cleanNumeroProcesso((string) $numeroProcesso);

    if (strlen($limpo) < 16) {
        return null;
    }

    return substr($limpo, 14, 2);
}

/**
 * Extrai o segmento da justica (J, 1 digito, posicao 13 zero-based) do numero
 * CNJ. Segmentos: 1=STF, 2=CNJ, 3=STJ, 4=Federal, 5=Trabalho, 6=Eleitoral,
 * 7=Militar Uniao, 8=Estadual, 9=Militar Estadual. Retorna null se invalido.
 */
function extrairSegmentoJustica($numeroProcesso): ?string
{
    $limpo = cleanNumeroProcesso((string) $numeroProcesso);

    if (strlen($limpo) < 14) {
        return null;
    }

    return substr($limpo, 13, 1);
}

function formatCpfCnpj($documento)
{
    // Remove caracteres não numéricos
    $documento = preg_replace("/[^0-9]/", "", $documento);

    // Verifica se é CPF ou CNPJ
    if (strlen($documento) === 11) {
        // Formata CPF
        return substr($documento, 0, 3) . '.' . substr($documento, 3, 3) . '.' . substr($documento, 6, 3) . '-' . substr($documento, 9, 2);
    } elseif (strlen($documento) === 14) {
        // Formata CNPJ
        return substr($documento, 0, 2) . '.' . substr($documento, 2, 3) . '.' . substr($documento, 5, 3) . '/' . substr($documento, 8, 4) . '-' . substr($documento, 12, 2);
    } else {
        // Retorna o documento sem formatação se não for CPF nem CNPJ
        return $documento;
    }
}

function formatNumeroProcesso($number)
{
    // Converte o número para string caso não esteja
    $str = strval($number);

    // Divide a string nas posições específicas e formata
    $part1 = substr($str, 0, 7);
    $part2 = substr($str, 7, 2);
    $part3 = substr($str, 9, 4);
    $part4 = substr($str, 13, 1);
    $part5 = substr($str, 14, 2);
    $part6 = substr($str, 16, 4);

    // Junta as partes formatadas
    $formatted = sprintf('%s-%s.%s.%s.%s.%s', $part1, $part2, $part3, $part4, $part5, $part6);

    return $formatted;
}

function getMimeType($fileType)
{
    switch ($fileType) {
        case 'pdf':
            return 'application/pdf';
        case 'png':
            return 'image/png';
        case 'mpeg':
            return 'audio/mpeg';
        case 'ogg':
            return 'audio/ogg';
        case 'vorbis':
            return 'audio/vorbis';
        case 'mp4':
            return 'video/mp4';
        case 'quicktime':
            return 'video/quicktime';
        default:
            return 'application/octet-stream'; // Tipo padrão se não corresponder a nenhum dos tipos especificados
    }
}

function generateBase64Image($path)
{
    // $path = 'myfolder/myimage.png';
    $type = pathinfo($path, PATHINFO_EXTENSION);
    $data = file_get_contents($path);
    $base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);
    return $base64;
}

function verificarPermissao($permissao)
{
    // Verifica se o usuário está autenticado
    if (auth()->check()) {
        $permissoesUsuario = auth()->user()->perfil->permissoes->pluck('permissao')->toArray();
        return in_array($permissao, $permissoesUsuario);
    }

    return false; // Retorna false se o usuário não estiver autenticado
}
