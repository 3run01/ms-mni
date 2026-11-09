<?php

namespace App\Services\Ocr;

use App\Models\ProcessoDocumento;

/**
 * Contagens de RF-08 sobre todos os documentos do processo na linha
 * (`processo_id` já implica o `tribunal_id`).
 *
 * Uma consulta só, com agregados condicionais: as 6 contagens saem do mesmo
 * instante do banco, sem a corrida de contar cada situação em separado
 * enquanto o webhook e os jobs mudam os documentos.
 */
class ContagemOcrService
{
    public function contar(int $processoId): ContagemOcr
    {
        // Conjunto do total (RF-07): legível ou mimetype não informado. As
        // constantes são as de FormatosOcr::entraNoTotal, a única fonte da
        // lista (RNF-03), para que a contagem e a situação calculada da lista
        // de /processo/visualizar nunca discordem.
        $legiveis = FormatosOcr::MIMETYPES_LEGIVEIS;
        $doConjunto = [...$legiveis, FormatosOcr::MIMETYPE_NAO_INFORMADO];
        $noTotal = '(mimetype IN ('.implode(', ', array_fill(0, count($legiveis), '?')).') OR mimetype = ?)';

        $situacoesComRetorno = [
            ProcessoDocumento::OCR_ENVIADO,
            ProcessoDocumento::OCR_PROCESSADO,
            ProcessoDocumento::OCR_ERRO,
        ];

        // O mimetype vazio conta em `total`: o download do OCR pede o
        // documento ao tribunal e grava o mimetype revelado (RF-09). Até lá,
        // ele segue as situações como qualquer legível.
        //
        // `ignorados` é o complemento de `total` (o ELSE do mesmo CASE): só
        // o mimetype não vazio fora da lista conta ali, e `total + ignorados`
        // fecha com os documentos do processo no banco.
        //
        // Pendente é "os demais do conjunto": `ocr_situacao` nulo, que é como
        // todo documento nasce, ou `pendente`. A condição é a negação das
        // outras 3 situações para que cada documento do conjunto caia numa só
        // contagem.
        $linha = ProcessoDocumento::query()
            ->toBase()
            ->where('processo_id', $processoId)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN '.$noTotal.' THEN 1 ELSE 0 END), 0) AS total, '
                .'COALESCE(SUM(CASE WHEN '.$noTotal.' AND ocr_situacao = ? THEN 1 ELSE 0 END), 0) AS processados, '
                .'COALESCE(SUM(CASE WHEN '.$noTotal.' AND ocr_situacao = ? THEN 1 ELSE 0 END), 0) AS com_erro, '
                .'COALESCE(SUM(CASE WHEN '.$noTotal.' THEN 0 ELSE 1 END), 0) AS ignorados, '
                .'COALESCE(SUM(CASE WHEN '.$noTotal.' AND (ocr_situacao IS NULL OR ocr_situacao NOT IN (?, ?, ?)) THEN 1 ELSE 0 END), 0) AS pendentes, '
                .'COALESCE(SUM(CASE WHEN '.$noTotal.' AND ocr_situacao = ? THEN 1 ELSE 0 END), 0) AS aguardando',
                [
                    ...$doConjunto,
                    ...$doConjunto, ProcessoDocumento::OCR_PROCESSADO,
                    ...$doConjunto, ProcessoDocumento::OCR_ERRO,
                    ...$doConjunto,
                    ...$doConjunto, ...$situacoesComRetorno,
                    ...$doConjunto, ProcessoDocumento::OCR_ENVIADO,
                ],
            )
            ->first();

        return new ContagemOcr(
            total: (int) $linha->total,
            processados: (int) $linha->processados,
            comErro: (int) $linha->com_erro,
            ignorados: (int) $linha->ignorados,
            pendentes: (int) $linha->pendentes,
            aguardando: (int) $linha->aguardando,
        );
    }
}
