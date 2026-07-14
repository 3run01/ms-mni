<?php

namespace App\Services\Processo;

use App\Exceptions\MNIException;
use App\Jobs\BaixarDocumentoMNIJob;
use App\Models\ProcessoDocumento;
use App\Services\MNI\Intercomunicacao\ConsultarDocumentoService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SalvarDocumentoProcessoService
{
    public function execute($processo, $documentos)
    {
        $documentos = is_array($documentos) ? $documentos : [$documentos];

        foreach ($documentos as $documento) {

            $this->salvarDocumento($processo, $documento);
            if (isset($documento->documentoVinculado)) {
                // dd($documento->documentoVinculado);
                $documentoVinculado = is_array($documento->documentoVinculado) ? $documento->documentoVinculado : [$documento->documentoVinculado];
                foreach ($documentoVinculado as $documentoVinculado) {
                    // dd($documentoVinculado);
                    $this->salvarDocumento($processo, $documentoVinculado);
                }
            }
        }
    }

    public function salvarDocumento($processo, $documento, $login_pje = null, $senha_pje = null)
    {
        $processoDocumento = ProcessoDocumento::updateOrCreate(
            [
                'processo_id' => $processo->id,
                'id_documento' => $documento->idDocumento,
            ],
            [
                'id_documento_vinculado' => !empty($documento->idDocumentoVinculado) ? $documento->idDocumentoVinculado : null,
                'tipo_documento' => isset($documento->tipoDocumento) ? $documento->tipoDocumento : null,
                'data_hora' => isset($documento->dataHora) ? Carbon::parse($documento->dataHora)->format('Y-m-d H:i:s') : null,
                'mimetype' => isset($documento->mimetype) ? $documento->mimetype : null,
                'movimento' => !empty($documento->movimento) ? $documento->movimento : null,
                'hash' => isset($documento->hash) ? $documento->hash : null,
                'nivel_sigilo' => !empty($documento->nivelSigilo) ? $documento->nivelSigilo : null,
                'descricao' => isset($documento->descricao) ? $documento->descricao : null,
                'usuario_juntada_arquivo' => $this->extrairOutroParametro($documento, 'pessoaJuntadaArquivo'),
                'data_juntada' => $this->extrairDataJuntada($documento),
            ]
        );

        if ($processoDocumento->status != ProcessoDocumento::STATUS_BAIXADO) {
            BaixarDocumentoMNIJob::dispatch($processoDocumento, $login_pje, $senha_pje)->onQueue('mni-download');
        }
    }

    private function extrairOutroParametro($documento, $nome)
    {
        if (empty($documento->outroParametro)) {
            return null;
        }

        $parametros = is_array($documento->outroParametro) ? $documento->outroParametro : [$documento->outroParametro];

        foreach ($parametros as $parametro) {
            if (isset($parametro->nome) && $parametro->nome === $nome && isset($parametro->valor)) {
                return $parametro->valor;
            }
        }

        return null;
    }

    private function extrairDataJuntada($documento)
    {
        $valor = $this->extrairOutroParametro($documento, 'mni:pje:documento:dataJuntada');

        if ($valor) {
            return Carbon::createFromTimestampMs($valor)->format('Y-m-d H:i:s');
        }

        return null;
    }

    public function baixarDocumento($documento, $login_pje = null, $senha_pje = null)
    {
        // try {
        // Verifica se o documento já está baixado e existe no S3
        if ($documento->status == ProcessoDocumento::STATUS_BAIXADO && Storage::disk('s3')->exists($documento->path)) {
            if ($documento->mimetype == 'text/html' && !$documento->temConteudoHtml()) {
                $this->downloadHTML($documento);
            }

            return ProcessoDocumento::find($documento->id);
        }

        // Verifica se o documento está em erro
        if ($documento->status == ProcessoDocumento::STATUS_ERRO) {
            $documento->status = ProcessoDocumento::STATUS_PENDENTE;
            $documento->save();
        }

        $path = null;
        // Tenta baixar o documento baseado no mimetype
        if ($documento->mimetype == 'text/html') {
            $path = $this->downloadHTML($documento, $login_pje, $senha_pje);
        } else if ($documento->mimetype == 'application/pdf') {
            $path = $this->downloadPDF($documento, $login_pje, $senha_pje);
        } else if ($documento->mimetype == 'video/mp4') {
            $path = $this->downloadMP4($documento, $login_pje, $senha_pje);
        } else if ($documento->mimetype == 'video/quicktime') {
            $path = $this->downloadQuicktime($documento, $login_pje, $senha_pje);
        } else if (str_starts_with($documento->mimetype, 'audio/')) {
            $path = $this->downloadAudio($documento, $login_pje, $senha_pje);
        } else {
            $path = $this->downloadOutros($documento, $login_pje, $senha_pje);
        }

        // Verifica se o documento foi baixado com sucesso
        if ($path && Storage::disk('s3')->exists($path)) {
            $documento->status = ProcessoDocumento::STATUS_BAIXADO;
            $documento->path = $path;
            $documento->save();

            // Recarrega o documento do banco para garantir dados atualizados
            return ProcessoDocumento::find($documento->id);
        }

        throw new \Exception('Falha ao baixar o documento: ' . $documento->id_documento);
        // } catch (\Exception $e) {
        //     $documento->status = ProcessoDocumento::STATUS_ERRO;
        //     $documento->save();
        //     throw new \Exception('Erro ao baixar documento: ' . $e->getMessage());
        // }
    }

    public function downloadHTML($documento, $login_pje = null, $senha_pje = null)
    {
        try {
            $documentoMNI = $this->consultarDocumento($documento, $login_pje, $senha_pje);

            if (empty($documentoMNI->conteudo)) {
                throw new \Exception('Conteúdo do documento HTML está vazio');
            }

            $conteudoDecodificado = base64_decode($documentoMNI->conteudo);
            if ($conteudoDecodificado === false) {
                throw new \Exception('Falha ao decodificar o conteúdo base64 do HTML');
            }

            $pdf = Pdf::loadHTML($conteudoDecodificado);
            $pasta = "documentos-processos";
            $filename = $pasta . "/" . $documento->processo->numero_processo . "/" . $documento->id_documento . ".pdf";
            $filenameHtml = $pasta . "/" . $documento->processo->numero_processo . "/" . $documento->id_documento . ".html";

            $this->putComRetry($filename, $pdf->output(), 'PDF');
            $this->putComRetry($filenameHtml, $conteudoDecodificado, 'HTML');

            $documento->path_html = $filenameHtml;
            $documento->save();

            return $filename;
        } catch (\Exception $e) {
            throw new \Exception('Erro ao processar documento HTML: ' . $e->getMessage());
        }
    }

    private function putComRetry(string $path, string $conteudo, string $rotulo): void
    {
        $maxTentativas = 3;
        $tentativa = 0;
        $sucesso = false;

        while (!$sucesso && $tentativa < $maxTentativas) {
            try {
                $sucesso = Storage::disk('s3')->put($path, $conteudo);
                if (!$sucesso) {
                    $tentativa++;
                    if ($tentativa < $maxTentativas) {
                        sleep(1);
                    }
                }
            } catch (\Exception $e) {
                $tentativa++;
                if ($tentativa >= $maxTentativas) {
                    throw $e;
                }
                sleep(1);
            }
        }

        if (!$sucesso) {
            throw new \Exception('Erro ao salvar o ' . $rotulo . ' no S3 após ' . $maxTentativas . ' tentativas');
        }
    }

    public function obterConteudoHtml(ProcessoDocumento $documento): ?string
    {
        if (!empty($documento->conteudo_html)) {
            return $documento->conteudo_html;
        }

        if (empty($documento->path_html)) {
            return null;
        }

        $conteudo = Storage::disk('s3')->get($documento->path_html);

        if ($conteudo === null || $conteudo === '') {
            Log::error('Erro ao ler conteudo HTML do S3', [
                'documento_id' => $documento->id_documento,
                'path_html' => $documento->path_html,
            ]);

            return null;
        }

        return $conteudo;
    }

    public function downloadPDF($documento, $login_pje = null, $senha_pje = null)
    {
        try {
            $documentoMNI = $this->consultarDocumento($documento, $login_pje, $senha_pje);

            if (empty($documentoMNI->conteudo)) {
                throw new \Exception('Conteúdo do PDF está vazio');
            }

            $conteudoDecodificado = base64_decode($documentoMNI->conteudo);
            if ($conteudoDecodificado === false) {
                throw new \Exception('Falha ao decodificar o conteúdo base64 do PDF');
            }

            $pasta = "documentos-processos";
            $filename = $pasta . "/" . $documento->processo->numero_processo . "/" . $documento->id_documento . ".pdf";

            if (!Storage::disk('s3')->put($filename, $conteudoDecodificado)) {
                throw new \Exception('Erro ao salvar o PDF no S3');
            }

            return $filename;
        } catch (\Exception $e) {
            throw new \Exception('Erro ao processar documento PDF: ' . $e->getMessage());
        }
    }

    public function downloadOutros($documento, $login_pje = null, $senha_pje = null)
    {
        try {
            $documentoMNI = $this->consultarDocumento($documento, $login_pje, $senha_pje);

            if (empty($documentoMNI->conteudo)) {
                throw new \Exception('Conteúdo do documento está vazio');
            }

            $conteudoDecodificado = base64_decode($documentoMNI->conteudo);
            if ($conteudoDecodificado === false) {
                throw new \Exception('Falha ao decodificar o conteúdo base64');
            }

            $pasta = "documentos-processos";
            $extensao = $this->getFileExtension($documento->mimetype);
            $filename = $pasta . "/" . $documento->processo->numero_processo . "/" . $documento->id_documento . "." . $extensao;

            if (!Storage::disk('s3')->put($filename, $conteudoDecodificado)) {
                throw new \Exception('Erro ao salvar o documento no S3');
            }

            return $filename;
        } catch (\Exception $e) {
            throw new \Exception('Erro ao processar documento: ' . $e->getMessage());
        }
    }

    public function getFileExtension($mimetype)
    {
        // dd($mimetype);
        $extensoes = [
            'text/html' => 'html',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'text/plain' => 'txt',
            'application/xml' => 'xml',
            'application/zip' => 'zip',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/avi' => 'avi',
            'video/x-msvideo' => 'avi',
            'video/mpeg' => 'mpeg',
            'video/webm' => 'webm',
            'video/3gpp' => '3gp',
            'video/x-flv' => 'flv',
            'audio/mpeg' => 'mp3',
            'audio/mp3' => 'mp3',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/aac' => 'aac',
            'audio/flac' => 'flac',
            'audio/x-ms-wma' => 'wma'
        ];

        return $extensoes[$mimetype] ?? 'dat'; // 'dat' como extensão padrão para tipos desconhecidos
    }

    public function consultarDocumento($documento, $login_pje = null, $senha_pje = null)
    {
        try {
            if (!$documento->processo) {
                throw new MNIException('Documento não possui processo associado', 500);
            }

            if (!$documento->processo->tribunal) {
                throw new MNIException('Processo não possui tribunal associado', 500);
            }

            $consultarDocumentoService = new ConsultarDocumentoService();
            return $consultarDocumentoService->execute(
                $documento->processo->tribunal,
                $documento->processo->numero_processo,
                $documento->id_documento,
                $login_pje,
                $senha_pje
            );
        } catch (MNIException $e) {
            Log::error('Erro MNI ao consultar documento', [
                'documento_id' => $documento->id_documento ?? 'N/A',
                'error' => $e->getError(),
                'code' => $e->getCode()
            ]);
            throw new MNIException($e->getError() ?: 'Erro ao consultar documento no MNI', 500);
        } catch (\Exception $e) {
            Log::error('Erro ao consultar documento', [
                'documento_id' => $documento->id_documento ?? 'N/A',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw new MNIException('Erro ao consultar documento: ' . $e->getMessage(), 500);
        }
    }

    public function downloadMP4($documento, $login_pje = null, $senha_pje = null)
    {
        try {
            if (!$documento->processo) {
                throw new \Exception('Documento não possui processo associado');
            }

            // Se login e senha não forem informados, usar as credenciais do tribunal
            if (empty($login_pje) || empty($senha_pje)) {
                $tribunal = $documento->processo->tribunal;
                if (!$tribunal) {
                    throw new \Exception('Processo não possui tribunal associado');
                }

                $login_pje = $tribunal->login;
                $senha_pje = Crypt::decrypt($tribunal->password);

                if (empty($login_pje) || empty($senha_pje)) {
                    throw new \Exception('Tribunal não possui credenciais de acesso configuradas');
                }
            }

            $numeroProcesso = $documento->processo->numero_processo;
            $idDocumento = $documento->id_documento;

            $bashScript = base_path('scripts/mni_consultar_documento.sh');
            $tempFile = storage_path('app/temp/' . $idDocumento . '.mp4');

            // Criar diretório temp se não existir
            if (!file_exists(storage_path('app/temp'))) {
                mkdir(storage_path('app/temp'), 0755, true);
            }

            // Executar o script bash passando os parâmetros.
            // Timeout maior que o default (60s) porque MP4/Quicktime de audiencia podem ser grandes.
            $process = Process::timeout(300)
                ->run("bash {$bashScript} {$numeroProcesso} {$idDocumento} {$tempFile} " . escapeshellarg($login_pje) . " " . escapeshellarg($senha_pje));

            if (!$process->successful()) {
                Log::error('Erro ao executar script bash', [
                    'documento_id' => $documento->id_documento,
                    'output' => $process->output(),
                    'error' => $process->errorOutput()
                ]);
                throw new \Exception('Erro ao executar script bash: ' . $process->errorOutput());
            }

            // Verificar se o arquivo foi criado
            if (!file_exists($tempFile)) {
                throw new \Exception('Arquivo temporário não foi criado pelo script bash');
            }

            // Ler e processar o conteúdo do arquivo
            $conteudoDocumento = file_get_contents($tempFile);

            if (empty($conteudoDocumento)) {
                throw new \Exception('Conteúdo do arquivo baixado está vazio');
            }

            // Processar resposta MTOM/XOP para extrair o conteúdo binário
            $conteudoDocumento = $this->extrairConteudoMTOM($conteudoDocumento);

            if (empty($conteudoDocumento)) {
                throw new \Exception('Não foi possível extrair o conteúdo do vídeo da resposta MTOM');
            }

            // Validar tamanho do conteúdo (limite de 5GB)
            $tamanhoEstimado = strlen($conteudoDocumento);
            $limiteBytes = 5 * 1024 * 1024 * 1024; // 5GB
            $tamanhoGB = round($tamanhoEstimado / 1024 / 1024 / 1024, 2);

            if ($tamanhoEstimado > $limiteBytes) {
                Log::warning('Documento excede tamanho máximo permitido', [
                    'documento_id' => $documento->id_documento,
                    'size_gb' => $tamanhoGB
                ]);
                @unlink($tempFile);
                throw new MNIException('Documento excede tamanho máximo permitido (5GB). Tamanho: ' . $tamanhoGB . 'GB', 500);
            }

            // Verificar assinatura MP4
            $signature = substr($conteudoDocumento, 0, 8);
            $signatureHex = bin2hex($signature);
            $validSignatures = ['66747970', '6d6f6f76', '6d646174'];
            $isValidMP4 = false;

            foreach ($validSignatures as $validSig) {
                if (strpos(strtolower($signatureHex), $validSig) !== false) {
                    $isValidMP4 = true;
                    break;
                }
            }

            if (!$isValidMP4) {
                Log::warning('Arquivo MP4 pode estar corrompido', [
                    'documento_id' => $documento->id_documento,
                    'signature' => $signatureHex
                ]);
            }

            // Salvar no S3
            $fileSize = strlen($conteudoDocumento);
            $pasta = "documentos-processos";
            $filename = $pasta . "/" . $documento->processo->numero_processo . "/" . $documento->id_documento . ".mp4";

            if (!Storage::disk('s3')->put($filename, $conteudoDocumento)) {
                @unlink($tempFile);
                throw new \Exception('Erro ao salvar o MP4 no S3');
            }

            // Atualizar documento
            $documento->file_size = $fileSize;
            $documento->status = ProcessoDocumento::STATUS_BAIXADO;
            $documento->path = $filename;
            $documento->save();

            @unlink($tempFile);

            return $filename;
        } catch (MNIException $e) {
            $errorMessage = $e->getError();
            Log::error('Erro MNI ao processar documento MP4', [
                'documento_id' => $documento->id_documento ?? 'N/A',
                'error' => $errorMessage,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception('Erro ao processar documento MP4: ' . $errorMessage, $e->getCode(), $e);
        } catch (\Exception $e) {
            Log::error('Erro ao processar documento MP4', [
                'documento_id' => $documento->id_documento ?? 'N/A',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception('Erro ao processar documento MP4: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function downloadQuicktime($documento, $login_pje = null, $senha_pje = null)
    {
        try {
            if (!$documento->processo) {
                throw new \Exception('Documento não possui processo associado');
            }

            // Se login e senha não forem informados, usar as credenciais do tribunal
            if (empty($login_pje) || empty($senha_pje)) {
                $tribunal = $documento->processo->tribunal;
                if (!$tribunal) {
                    throw new \Exception('Processo não possui tribunal associado');
                }

                $login_pje = $tribunal->login;
                $senha_pje = Crypt::decrypt($tribunal->password);

                if (empty($login_pje) || empty($senha_pje)) {
                    throw new \Exception('Tribunal não possui credenciais de acesso configuradas');
                }
            }

            $numeroProcesso = $documento->processo->numero_processo;
            $idDocumento = $documento->id_documento;

            $bashScript = base_path('scripts/mni_consultar_documento.sh');
            $tempFile = storage_path('app/temp/' . $idDocumento . '.mov');

            // Criar diretório temp se não existir
            if (!file_exists(storage_path('app/temp'))) {
                mkdir(storage_path('app/temp'), 0755, true);
            }

            // Executar o script bash passando os parâmetros.
            // Timeout maior que o default (60s) porque MP4/Quicktime de audiencia podem ser grandes.
            $process = Process::timeout(300)
                ->run("bash {$bashScript} {$numeroProcesso} {$idDocumento} {$tempFile} " . escapeshellarg($login_pje) . " " . escapeshellarg($senha_pje));

            if (!$process->successful()) {
                Log::error('Erro ao executar script bash', [
                    'documento_id' => $documento->id_documento,
                    'output' => $process->output(),
                    'error' => $process->errorOutput()
                ]);
                throw new \Exception('Erro ao executar script bash: ' . $process->errorOutput());
            }

            // Verificar se o arquivo foi criado
            if (!file_exists($tempFile)) {
                throw new \Exception('Arquivo temporário não foi criado pelo script bash');
            }

            // Ler e processar o conteúdo do arquivo
            $conteudoDocumento = file_get_contents($tempFile);

            if (empty($conteudoDocumento)) {
                throw new \Exception('Conteúdo do arquivo baixado está vazio');
            }

            // Processar resposta MTOM/XOP para extrair o conteúdo binário
            $conteudoDocumento = $this->extrairConteudoMTOM($conteudoDocumento);

            if (empty($conteudoDocumento)) {
                throw new \Exception('Não foi possível extrair o conteúdo do vídeo da resposta MTOM');
            }

            // Verificar assinatura QuickTime
            $signature = substr($conteudoDocumento, 0, 12);
            $signatureHex = bin2hex($signature);
            $validSignatures = ['66747970717420', '6d6f6f76', '6d646174', '667479706d703432'];
            $isValidQuicktime = false;

            foreach ($validSignatures as $validSig) {
                if (strpos(strtolower($signatureHex), $validSig) !== false) {
                    $isValidQuicktime = true;
                    break;
                }
            }

            if (!$isValidQuicktime) {
                Log::warning('Arquivo QuickTime pode estar corrompido', [
                    'documento_id' => $documento->id_documento,
                    'signature' => $signatureHex
                ]);
            }

            // Salvar no S3
            $fileSize = strlen($conteudoDocumento);
            $pasta = "documentos-processos";
            $filename = $pasta . "/" . $documento->processo->numero_processo . "/" . $documento->id_documento . ".mov";

            if (!Storage::disk('s3')->put($filename, $conteudoDocumento)) {
                @unlink($tempFile);
                throw new \Exception('Erro ao salvar o QuickTime no S3');
            }

            // Atualizar documento
            $documento->file_size = $fileSize;
            $documento->status = ProcessoDocumento::STATUS_BAIXADO;
            $documento->path = $filename;
            $documento->save();

            @unlink($tempFile);

            return $filename;
        } catch (MNIException $e) {
            $errorMessage = $e->getError();
            Log::error('Erro MNI ao processar documento QuickTime', [
                'documento_id' => $documento->id_documento ?? 'N/A',
                'error' => $errorMessage,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception('Erro ao processar documento QuickTime: ' . $errorMessage, $e->getCode(), $e);
        } catch (\Exception $e) {
            Log::error('Erro ao processar documento QuickTime', [
                'documento_id' => $documento->id_documento ?? 'N/A',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception('Erro ao processar documento QuickTime: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function downloadAudio($documento, $login_pje = null, $senha_pje = null)
    {
        try {
            $documentoMNI = $this->consultarDocumento($documento, $login_pje, $senha_pje);

            if (empty($documentoMNI->conteudo)) {
                throw new \Exception('Conteúdo do áudio está vazio');
            }

            // O conteúdo pode vir como referência XOP ou diretamente
            $conteudoDocumento = null;

            // Primeiro, tentar decodificar como base64 (conteúdo já processado pelo XOP)
            $conteudoDecodificado = base64_decode($documentoMNI->conteudo, true);
            if ($conteudoDecodificado !== false) {
                // Conteúdo está em base64, usar o decodificado
                $conteudoDocumento = $conteudoDecodificado;
                Log::info('Audio: Conteúdo decodificado de base64', ['size' => strlen($conteudoDocumento)]);
            } else {
                // Conteúdo não está em base64, usar direto
                $conteudoDocumento = $documentoMNI->conteudo;
                Log::info('Audio: Conteúdo usado diretamente', ['size' => strlen($conteudoDocumento)]);
            }

            if (empty($conteudoDocumento)) {
                throw new \Exception('Não foi possível extrair o conteúdo do áudio');
            }

            // Verificar se o arquivo começa com assinatura de áudio válida
            $signature = substr($conteudoDocumento, 0, 12);
            $signatureHex = bin2hex($signature);

            // Assinaturas válidas para diferentes formatos de áudio
            $validSignatures = [
                'fffb', // MP3
                'fff3', // MP3
                'fff2', // MP3
                '4944', // ID3 tag
                '5249', // RIFF (WAV)
                '664c', // FLAC
                '4f67', // OGG
            ];
            $isValidAudio = false;

            foreach ($validSignatures as $validSig) {
                if (strpos(strtolower($signatureHex), $validSig) !== false) {
                    $isValidAudio = true;
                    break;
                }
            }

            if (!$isValidAudio) {
                Log::warning('Arquivo de áudio pode estar corrompido. Assinatura: ' . $signatureHex);
            } else {
                Log::info('Arquivo de áudio com assinatura válida: ' . $signatureHex);
            }

            $pasta = "documentos-processos";
            $extensao = $this->getFileExtension($documento->mimetype);
            $filename = $pasta . "/" . $documento->processo->numero_processo . "/" . $documento->id_documento . "." . $extensao;

            if (!Storage::disk('s3')->put($filename, $conteudoDocumento)) {
                throw new \Exception('Erro ao salvar o áudio no S3');
            }

            Log::info('Áudio salvo com sucesso', [
                'filename' => $filename,
                'mimetype' => $documento->mimetype,
                'size' => strlen($conteudoDocumento),
                'signature' => $signatureHex,
                'valid' => $isValidAudio
            ]);

            return $filename;
        } catch (\Exception $e) {
            throw new \Exception('Erro ao processar documento de áudio: ' . $e->getMessage());
        }
    }

    /**
     * Extrai o conteúdo binário de uma resposta MTOM/XOP
     *
     * @param string $conteudo Resposta MTOM completa
     * @return string|null Conteúdo binário extraído
     */
    private function extrairConteudoMTOM($conteudo)
    {
        try {
            // Verificar se é uma resposta MTOM multipart
            if (strpos($conteudo, 'Content-Type: application/xop+xml') === false) {
                return $conteudo;
            }

            // Encontrar o boundary
            preg_match('/--uuid:([a-f0-9-]+)/', $conteudo, $matches);
            if (empty($matches[1])) {
                Log::warning('Boundary não encontrado na resposta MTOM');
                return null;
            }

            $boundary = '--uuid:' . $matches[1];

            // Dividir o conteúdo em partes
            $partes = explode($boundary, $conteudo);
            array_pop($partes);
            array_shift($partes);

            // Procurar pela parte que contém o vídeo/áudio
            foreach ($partes as $parte) {
                $parteSplit = preg_split('/\r?\n\r?\n/', $parte, 2);

                if (count($parteSplit) < 2) {
                    continue;
                }

                $headers = $parteSplit[0];
                $conteudoParte = $parteSplit[1];

                // Pular parte SOAP (XML)
                if (stripos($headers, 'application/xop+xml') !== false || stripos($headers, 'text/xml') !== false) {
                    continue;
                }

                // Verificar se contém vídeo/áudio binário
                if (
                    stripos($headers, 'video/') !== false ||
                    stripos($headers, 'audio/') !== false ||
                    stripos($headers, 'application/octet-stream') !== false ||
                    stripos($headers, 'Content-Transfer-Encoding: binary') !== false
                ) {
                    $conteudoParte = ltrim($conteudoParte, "\r\n");

                    if (stripos($headers, 'Content-Transfer-Encoding: base64') !== false) {
                        $conteudoParte = base64_decode($conteudoParte);
                    }

                    return $conteudoParte;
                }
            }

            Log::warning('Nenhuma parte com conteúdo binário foi encontrada na resposta MTOM');
            return null;
        } catch (\Exception $e) {
            Log::error('Erro ao processar MTOM', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return null;
        }
    }
}
