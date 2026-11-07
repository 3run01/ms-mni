<?php

namespace App\Services\Ocr;

use App\Jobs\EnviarAvisoOcrJob;
use App\Models\ProcessoOcrAssinante;
use App\Models\ProcessoOcrAviso;
use App\Models\ProcessoOcrProcessamento;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Gera os avisos `ocr_progresso` (CT-02, schema AvisoDeProgressoDoOcr) aos
 * assinantes de um processamento: um ProcessoOcrAviso por assinante, com o
 * payload congelado nas contagens do momento (RF-14), e um
 * EnviarAvisoOcrJob por aviso na fila `ocr-aviso` (RF-16).
 *
 * Os jobs saem depois do commit: quem chama muda as contagens e gera os
 * avisos na mesma transação (D6), e um aviso de uma mudança desfeita não
 * pode chegar ao Sistema.
 *
 * Nada é gerado depois do aviso final (Q-03): o assinante que já tem o final
 * gerado não recebe mais nada desse processamento, nem um 2º final.
 */
class AvisarAssinantesOcrService
{
    public const FILA = 'ocr-aviso';

    public const SITUACAO_EM_ANDAMENTO = ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO;

    public const SITUACAO_CONCLUIDO = ProcessoOcrProcessamento::SITUACAO_CONCLUIDO;

    public const SITUACAO_FALHOU = ProcessoOcrProcessamento::SITUACAO_FALHOU;

    /**
     * O tribunal recusou o par do dono (RF-10). O dono recebe este código;
     * cada carona recebe CODIGO_RECUSA_OUTRO_PEDIDO (RF-15).
     */
    public const CODIGO_RECUSA = 'credencial_pje_recusada';

    public const CODIGO_RECUSA_OUTRO_PEDIDO = 'credencial_de_outro_pedido_recusada';

    private const CHAVES_CONTAGENS = ['total', 'processados', 'com_erro', 'ignorados'];

    /**
     * Aviso `em_andamento`, sem `codigo`, a cada assinante ativo (RF-14).
     * Chame antes de finalizar: com o processamento já encerrado, nada é
     * gerado (Q-03).
     *
     * @param  array{total: int, processados: int, com_erro: int, ignorados: int}  $contagens
     *                                                                                         ContagemOcr::paraCorpo()
     * @return int quantos avisos foram gerados
     */
    public function avisarProgresso(ProcessoOcrProcessamento $processamento, array $contagens): int
    {
        $contagens = $this->contagensValidas($contagens);

        if ($processamento->situacao !== ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO) {
            return 0;
        }

        return $this->gerar(
            $processamento,
            false,
            fn (ProcessoOcrAssinante $assinante) => $this->payload($processamento, $contagens, self::SITUACAO_EM_ANDAMENTO),
        );
    }

    /**
     * Aviso final a cada assinante ativo (RF-15): `concluido`, ou `falhou`
     * com `codigo` por papel só na recusa do par (`$codigoRecusa` =
     * CODIGO_RECUSA). O `falhou` por prazo (RF-13) vai sem `codigo`.
     *
     * @param  array{total: int, processados: int, com_erro: int, ignorados: int}  $contagens
     * @return int quantos avisos foram gerados
     */
    public function avisarFinal(
        ProcessoOcrProcessamento $processamento,
        array $contagens,
        string $situacao,
        ?string $codigoRecusa = null,
    ): int {
        $contagens = $this->contagensValidas($contagens);

        if (! in_array($situacao, [self::SITUACAO_CONCLUIDO, self::SITUACAO_FALHOU], true)) {
            throw new InvalidArgumentException("Situação final inválida para o aviso do OCR: {$situacao}");
        }

        if ($codigoRecusa !== null && $codigoRecusa !== self::CODIGO_RECUSA) {
            throw new InvalidArgumentException("Código de recusa inválido para o aviso do OCR: {$codigoRecusa}");
        }

        // O contrato só aceita `codigo` com `falhou` (if/then do schema).
        if ($codigoRecusa !== null && $situacao !== self::SITUACAO_FALHOU) {
            throw new InvalidArgumentException('O código de recusa só vai no aviso final falhou');
        }

        return $this->gerar(
            $processamento,
            true,
            function (ProcessoOcrAssinante $assinante) use ($processamento, $contagens, $situacao, $codigoRecusa) {
                $payload = $this->payload($processamento, $contagens, $situacao);

                if ($codigoRecusa !== null) {
                    $payload['codigo'] = $assinante->dono ? self::CODIGO_RECUSA : self::CODIGO_RECUSA_OUTRO_PEDIDO;
                }

                return $payload;
            },
        );
    }

    /**
     * @param  \Closure(ProcessoOcrAssinante): array<string, mixed>  $payloadDo
     */
    private function gerar(ProcessoOcrProcessamento $processamento, bool $final, \Closure $payloadDo): int
    {
        return DB::transaction(function () use ($processamento, $final, $payloadDo) {
            $avisos = $this->assinantesQueRecebem($processamento)
                ->map(fn (ProcessoOcrAssinante $assinante) => ProcessoOcrAviso::create([
                    'assinante_id' => $assinante->id,
                    'final' => $final,
                    'payload' => $payloadDo($assinante),
                ]));

            foreach ($avisos as $aviso) {
                EnviarAvisoOcrJob::dispatch($aviso->id)
                    ->onQueue(self::FILA)
                    ->afterCommit();
            }

            return $avisos->count();
        });
    }

    /**
     * Ativos (não encerrados por 4xx e sem o final entregue) e ainda sem o
     * aviso final gerado: o final gerado e ainda não entregue também fecha
     * o assinante para avisos novos (Q-03).
     *
     * @return Collection<int, ProcessoOcrAssinante>
     */
    private function assinantesQueRecebem(ProcessoOcrProcessamento $processamento): Collection
    {
        return ProcessoOcrAssinante::query()
            ->where('processamento_id', $processamento->id)
            ->ativos()
            ->whereDoesntHave('avisos', fn ($avisos) => $avisos->where('final', true))
            ->orderBy('id')
            ->get();
    }

    /**
     * As 7 chaves de AvisoDeProgressoDoOcr, na ordem do schema.
     *
     * @param  array{total: int, processados: int, com_erro: int, ignorados: int}  $contagens
     * @return array<string, int|string>
     */
    private function payload(ProcessoOcrProcessamento $processamento, array $contagens, string $situacao): array
    {
        $processo = $processamento->processo;

        return [
            'numero_processo' => (string) $processo->numero_processo,
            'tribunal_id' => (int) $processo->tribunal_id,
            'total' => $contagens['total'],
            'processados' => $contagens['processados'],
            'com_erro' => $contagens['com_erro'],
            'ignorados' => $contagens['ignorados'],
            'situacao' => $situacao,
        ];
    }

    /**
     * @return array{total: int, processados: int, com_erro: int, ignorados: int}
     */
    private function contagensValidas(array $contagens): array
    {
        $valida = [];

        foreach (self::CHAVES_CONTAGENS as $chave) {
            if (! array_key_exists($chave, $contagens) || ! is_numeric($contagens[$chave]) || (int) $contagens[$chave] < 0) {
                throw new InvalidArgumentException("Contagem do OCR ausente ou inválida: {$chave}");
            }

            $valida[$chave] = (int) $contagens[$chave];
        }

        return $valida;
    }
}
