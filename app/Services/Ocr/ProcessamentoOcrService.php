<?php

namespace App\Services\Ocr;

use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrProcessamento;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Máquina de estados do OCR de um processo na linha (T14): a situação de
 * cada documento (D1) e o fim do processamento aberto.
 *
 * Toda mudança roda em `DB::transaction` com a ordem de travas de D6:
 * primeiro o processamento aberto do processo (`lockForUpdate`), depois o
 * documento. Assim, as contagens, os avisos e o fim do processamento saem
 * serializados por processo, e o aviso final é gerado uma vez só: quem
 * espera a trava de um processamento que acabou de terminar relê a linha e
 * já não o encontra em andamento. Os avisos são despachados `afterCommit`
 * (AvisarAssinantesOcrService): uma mudança desfeita não avisa ninguém.
 *
 * O retorno vale para o job que quem chama leu no documento. Se, até a
 * trava, o `ocr_job_id` do documento mudou (devolvido à fila ou reenviado
 * por outro worker), a mudança não é aplicada: só o job atual é conhecido
 * (RF-12, R2-2).
 */
class ProcessamentoOcrService
{
    /** Tamanho da coluna `processo_documentos.ocr_erro`. */
    public const TAMANHO_MAXIMO_ERRO = 1000;

    /** Tamanho da coluna `processo_documentos.ocr_job_id`. */
    private const TAMANHO_MAXIMO_JOB_ID = 64;

    private const MASCARA = '***';

    public function __construct(
        private readonly ContagemOcrService $contagem,
        private readonly AvisarAssinantesOcrService $avisos,
    ) {}

    /**
     * O processamento em andamento do processo, se houver (no máximo 1, pelo
     * índice único parcial). Com `$travar`, chame dentro de uma transação:
     * é a 1ª trava da ordem de D6.
     */
    public function processamentoAberto(int $processoId, bool $travar = false): ?ProcessoOcrProcessamento
    {
        $consulta = ProcessoOcrProcessamento::query()
            ->where('processo_id', $processoId)
            ->emAndamento();

        if ($travar) {
            $consulta->lockForUpdate();
        }

        return $consulta->first();
    }

    /**
     * O workflow aceitou o documento (202 `{id}`, RF-11): ele passa a
     * aguardar retorno, e a idade A conta daqui (RF-18 item 1). O envio não
     * é progresso (R4-1): não mexe em `ultimo_progresso_em` nem avisa.
     *
     * @return bool false quando o documento já está processado (nunca volta)
     *              ou quando o job dele mudou desde a leitura de quem chama
     */
    public function registrarEnvio(ProcessoDocumento $documento, string $jobId): bool
    {
        $jobId = trim($jobId);

        if ($jobId === '' || mb_strlen($jobId) > self::TAMANHO_MAXIMO_JOB_ID) {
            throw new InvalidArgumentException('Job do workflow do OCR inválido para registrar o envio');
        }

        $registrado = $this->comTravas($documento, false, function (ProcessoDocumento $travado) use ($jobId) {
            if ($travado->ocr_situacao === ProcessoDocumento::OCR_PROCESSADO) {
                return false;
            }

            $travado->update([
                'ocr_situacao' => ProcessoDocumento::OCR_ENVIADO,
                'ocr_job_id' => $jobId,
                'ocr_enviado_em' => now(),
                'ocr_concluido_em' => null,
                'ocr_erro' => null,
            ]);

            return true;
        });

        if ($registrado) {
            Log::info("[ProcessoDocumento:{$documento->id}] enviado ao workflow do OCR", ['job_id' => $jobId]);
        }

        return $registrado;
    }

    /**
     * Retorno do job atual do documento, pelo webhook (RF-12) ou pela
     * consulta do job (RF-13 item 1, RF-18 item 3):
     *
     * - `success` sobre `enviado` ou `erro` → processado. O `success`
     *   prevalece sobre o `com_erro` (Q-04);
     * - `success` sobre processado → Repetido;
     * - `error` sobre `enviado` → `erro`, com `ocr_erro` resumido;
     * - `error` sobre `erro` → Repetido;
     * - `error` sobre processado → SemMudanca: nunca rebaixa (Q-04);
     * - sobre pendente → SemMudanca: o job dele já não é conhecido (R2-2).
     *
     * A mudança conta como progresso do processamento aberto no momento do
     * retorno, venha o envio de qualquer processamento (RF-18 item 2). Sem
     * processamento aberto, só as contagens mudam, com 0 avisos.
     */
    public function aplicarRetorno(ProcessoDocumento $documento, bool $sucesso, ?string $detalhe = null): ResultadoRetornoOcr
    {
        $erro = $sucesso ? null : $this->resumirErro($detalhe, 'O workflow do OCR informou erro sem detalhe');

        return $this->mudarSituacao($documento, function (ProcessoDocumento $travado) use ($sucesso, $erro) {
            $situacao = $travado->ocr_situacao;

            if ($sucesso) {
                if ($situacao === ProcessoDocumento::OCR_PROCESSADO) {
                    return ResultadoRetornoOcr::Repetido;
                }

                if (! in_array($situacao, [ProcessoDocumento::OCR_ENVIADO, ProcessoDocumento::OCR_ERRO], true)) {
                    return ResultadoRetornoOcr::SemMudanca;
                }

                $travado->update([
                    'ocr_situacao' => ProcessoDocumento::OCR_PROCESSADO,
                    'ocr_concluido_em' => now(),
                    'ocr_erro' => null,
                ]);

                return ResultadoRetornoOcr::Aplicado;
            }

            if ($situacao === ProcessoDocumento::OCR_ERRO) {
                return ResultadoRetornoOcr::Repetido;
            }

            if ($situacao !== ProcessoDocumento::OCR_ENVIADO) {
                return ResultadoRetornoOcr::SemMudanca;
            }

            $travado->update([
                'ocr_situacao' => ProcessoDocumento::OCR_ERRO,
                'ocr_erro' => $erro,
            ]);

            return ResultadoRetornoOcr::Aplicado;
        }, $sucesso ? 'retorno success do OCR' : 'retorno error do OCR');
    }

    /**
     * Erro definitivo no download (RF-09) ou no envio (RF-11): pendente ou
     * `enviado` → `erro`, sem nova tentativa no mesmo processamento. Mantém o
     * `ocr_job_id`: um `success` atrasado desse job ainda prevalece (Q-04).
     * `ocr_erro` fica resumido em até 1000 caracteres, com login, senha e
     * tokens mascarados.
     */
    public function registrarErroDefinitivo(ProcessoDocumento $documento, string $motivo): ResultadoRetornoOcr
    {
        $erro = $this->resumirErro($motivo, 'Erro definitivo sem detalhe');

        return $this->mudarSituacao($documento, function (ProcessoDocumento $travado) use ($erro) {
            if ($travado->ocr_situacao === ProcessoDocumento::OCR_ERRO) {
                return ResultadoRetornoOcr::Repetido;
            }

            if ($travado->ocr_situacao === ProcessoDocumento::OCR_PROCESSADO) {
                return ResultadoRetornoOcr::SemMudanca;
            }

            $travado->update([
                'ocr_situacao' => ProcessoDocumento::OCR_ERRO,
                'ocr_erro' => $erro,
            ]);

            return ResultadoRetornoOcr::Aplicado;
        }, 'erro definitivo do OCR');
    }

    /**
     * Progresso do processamento aberto do processo (RF-13, RF-14): grava
     * `ultimo_progresso_em`, avisa cada assinante ativo com as contagens do
     * momento e, sem documento pendente nem aguardando retorno, termina
     * `concluido`, ainda que com `com_erro` > 0 (RF-15). Sem processamento
     * aberto, não faz nada: 0 avisos (RF-18 item 2).
     */
    public function registrarProgresso(int $processoId): void
    {
        DB::transaction(function () use ($processoId) {
            $aberto = $this->processamentoAberto($processoId, travar: true);

            if ($aberto !== null) {
                $this->progredir($aberto);
            }
        });
    }

    /**
     * O tribunal recusou o par do dono num download (RF-10). Termina
     * `falhou`, com `credencial_pje_recusada` ao dono e
     * `credencial_de_outro_pedido_recusada` a cada carona (RF-15). Os
     * documentos não baixados ficam pendentes e não contam em `com_erro`
     * (RF-10 item 3). Idempotente: os outros downloads do mesmo par podem
     * reconhecer a recusa ao mesmo tempo, e só o 1º encerra.
     *
     * @return bool false quando o processamento já não estava em andamento
     */
    public function encerrarPorRecusa(int $processamentoId): bool
    {
        return $this->encerrar(
            $processamentoId,
            fn (ContagemOcr $contagem) => [
                ProcessoOcrProcessamento::SITUACAO_FALHOU,
                AvisarAssinantesOcrService::CODIGO_RECUSA,
            ],
            'encerrado pela recusa do par',
        );
    }

    /**
     * P venceu sem progresso (RF-13 item 2): termina `falhou`, sem `codigo`.
     * Se, quando a trava chega, já não sobra pendente nem aguardando
     * retorno, termina `concluido`, que é a regra do aviso final (RF-15).
     * Idempotente, como `encerrarPorRecusa`.
     *
     * @return bool false quando o processamento já não estava em andamento
     */
    public function encerrarPorPrazo(ProcessoOcrProcessamento $processamento): bool
    {
        $encerrado = $this->encerrar(
            $processamento->id,
            fn (ContagemOcr $contagem) => [
                $contagem->pendentes + $contagem->aguardando > 0
                    ? ProcessoOcrProcessamento::SITUACAO_FALHOU
                    : ProcessoOcrProcessamento::SITUACAO_CONCLUIDO,
                null,
            ],
            'encerrado pelo prazo sem progresso',
        );

        $processamento->refresh();

        return $encerrado;
    }

    /**
     * Devolve à fila um documento em `erro` (reabertura, NC-3) ou `enviado`
     * cujo job antigo vai ser cancelado e reenviado (D9). O job atual passa
     * a `ocr_job_id_cancelar` e deixa de ser conhecido: o retorno dele
     * recebe 404 desde já (RF-12), e o envio faz o `DELETE` antes do `POST`
     * (CT-07, R2-2). Sem job atual, um job a cancelar que ainda não foi
     * cancelado (o `DELETE` esgotou as tentativas) continua lá.
     *
     * Não é progresso: nenhuma contagem de `processados` muda, e a queda de
     * `com_erro` na reabertura sai no 202 do pedido (D-08), sem aviso.
     *
     * @return bool false para processado, pendente ou job mudado desde a leitura
     */
    public function devolverParaFila(ProcessoDocumento $documento): bool
    {
        $devolvido = $this->comTravas($documento, false, function (ProcessoDocumento $travado) {
            if (! in_array($travado->ocr_situacao, [ProcessoDocumento::OCR_ERRO, ProcessoDocumento::OCR_ENVIADO], true)) {
                return false;
            }

            $travado->update([
                'ocr_situacao' => ProcessoDocumento::OCR_PENDENTE,
                'ocr_job_id_cancelar' => $travado->ocr_job_id ?? $travado->ocr_job_id_cancelar,
                'ocr_job_id' => null,
                'ocr_enviado_em' => null,
                'ocr_concluido_em' => null,
                'ocr_erro' => null,
            ]);

            return true;
        });

        if ($devolvido) {
            Log::info("[ProcessoDocumento:{$documento->id}] devolvido à fila do OCR", [
                'job_id_cancelar' => $documento->ocr_job_id_cancelar,
            ]);
        }

        return $devolvido;
    }

    /**
     * Mudança de situação que conta como progresso quando aplicada.
     *
     * @param  Closure(ProcessoDocumento): ResultadoRetornoOcr  $regra
     */
    private function mudarSituacao(ProcessoDocumento $documento, Closure $regra, string $evento): ResultadoRetornoOcr
    {
        $processamentoId = null;

        $resultado = $this->comTravas(
            $documento,
            ResultadoRetornoOcr::SemMudanca,
            function (ProcessoDocumento $travado, ?ProcessoOcrProcessamento $aberto) use ($regra, &$processamentoId) {
                $resultado = $regra($travado);

                if ($resultado->mudou() && $aberto !== null) {
                    $processamentoId = $aberto->id;
                    $this->progredir($aberto);
                }

                return $resultado;
            },
        );

        Log::info("[ProcessoDocumento:{$documento->id}] {$evento}", [
            'resultado' => $resultado->value,
            'situacao' => $documento->ocr_situacao,
            'processamento_id' => $processamentoId,
        ]);

        return $resultado;
    }

    /**
     * Trava na ordem de D6 e aplica `$mudanca` ao documento relido sob a
     * trava. Se o `ocr_job_id` relido não é o que quem chama leu, devolve
     * `$seOJobMudou` sem aplicar nada. O model de quem chama sai com o
     * estado gravado.
     *
     * @template T
     *
     * @param  T  $seOJobMudou
     * @param  Closure(ProcessoDocumento, ?ProcessoOcrProcessamento): T  $mudanca
     * @return T
     */
    private function comTravas(ProcessoDocumento $documento, mixed $seOJobMudou, Closure $mudanca): mixed
    {
        return DB::transaction(function () use ($documento, $seOJobMudou, $mudanca) {
            $aberto = $this->processamentoAberto($documento->processo_id, travar: true);

            $travado = ProcessoDocumento::query()
                ->whereKey($documento->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $resultado = $travado->ocr_job_id === $documento->ocr_job_id
                ? $mudanca($travado, $aberto)
                : $seOJobMudou;

            $documento->setRawAttributes($travado->getAttributes(), true);

            return $resultado;
        });
    }

    /**
     * Chamado com o processamento já travado (D6).
     */
    private function progredir(ProcessoOcrProcessamento $aberto): void
    {
        $aberto->update(['ultimo_progresso_em' => now()]);

        $contagem = $this->contagem->contar($aberto->processo_id);

        // O aviso do último documento sai antes do final: o assinante recebe
        // 1 `em_andamento` por documento e depois o final (RF-06 AC b, RF-18 AC e).
        $this->avisos->avisarProgresso($aberto, $contagem->paraCorpo());

        if ($contagem->pendentes === 0 && $contagem->aguardando === 0) {
            $this->finalizar($aberto, ProcessoOcrProcessamento::SITUACAO_CONCLUIDO, null, $contagem);
        }
    }

    /**
     * @param  Closure(ContagemOcr): array{0: string, 1: ?string}  $desfecho  situação e código
     */
    private function encerrar(int $processamentoId, Closure $desfecho, string $evento): bool
    {
        $encerrado = DB::transaction(function () use ($processamentoId, $desfecho) {
            $processamento = ProcessoOcrProcessamento::query()
                ->whereKey($processamentoId)
                ->lockForUpdate()
                ->first();

            if ($processamento === null || $processamento->situacao !== ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO) {
                return false;
            }

            $contagem = $this->contagem->contar($processamento->processo_id);
            [$situacao, $codigo] = $desfecho($contagem);

            $this->finalizar($processamento, $situacao, $codigo, $contagem);

            return true;
        });

        if ($encerrado) {
            Log::info("[ProcessamentoOcr:{$processamentoId}] {$evento}");
        }

        return $encerrado;
    }

    /**
     * Chamado com o processamento travado e ainda em andamento. A situação
     * muda antes do aviso final: depois dele, nenhum aviso novo é gerado
     * para os assinantes desse processamento (Q-03).
     */
    private function finalizar(ProcessoOcrProcessamento $processamento, string $situacao, ?string $codigo, ContagemOcr $contagem): void
    {
        $processamento->update([
            'situacao' => $situacao,
            'codigo_falha' => $codigo,
            'finalizado_em' => now(),
        ]);

        $avisos = $this->avisos->avisarFinal($processamento, $contagem->paraCorpo(), $situacao, $codigo);

        Log::info("[ProcessamentoOcr:{$processamento->id}] processamento finalizado", [
            'situacao' => $situacao,
            'codigo' => $codigo,
            'total' => $contagem->total,
            'processados' => $contagem->processados,
            'com_erro' => $contagem->comErro,
            'pendentes' => $contagem->pendentes,
            'aguardando' => $contagem->aguardando,
            'avisos' => $avisos,
        ]);
    }

    /**
     * Resumo de uma linha, sem credencial e com até 1000 caracteres. O
     * service nunca recebe o par (RNF-01), então mascara pelo formato: os
     * pares chave/valor de login, senha e token, os elementos
     * `idConsultante`/`senhaConsultante` do SOAP e o `Bearer` do workflow.
     */
    private function resumirErro(?string $texto, string $padrao): string
    {
        $texto = mb_scrub((string) $texto, 'UTF-8');

        $texto = preg_replace(
            '/<((?:[\w.-]+:)?(?:idConsultante|senhaConsultante))\b[^>]*>.*?<\/\1>/isu',
            '<$1>'.self::MASCARA.'</$1>',
            $texto,
        ) ?? '';

        $texto = preg_replace(
            '/\b(login(?:_pje)?|senha(?:_pje)?|idConsultante|senhaConsultante|password|passwd|(?:callback_|api_|x-api-)?token|authorization)\b(\s*["\']?\s*[:=]\s*["\']?)(?:bearer\s+)?[^\s"\'&,;<>]+/iu',
            '$1$2'.self::MASCARA,
            $texto,
        ) ?? '';

        $texto = preg_replace('/\bbearer\s+[^\s"\'&,;<>]+/iu', 'Bearer '.self::MASCARA, $texto) ?? '';

        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');

        if ($texto === '') {
            return $padrao;
        }

        if (mb_strlen($texto) > self::TAMANHO_MAXIMO_ERRO) {
            $texto = mb_substr($texto, 0, self::TAMANHO_MAXIMO_ERRO - 1).'…';
        }

        return $texto;
    }
}
