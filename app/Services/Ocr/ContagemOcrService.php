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
        $legiveis = FormatosOcr::MIMETYPES_LEGIVEIS;
        $legivel = 'mimetype IN ('.implode(', ', array_fill(0, count($legiveis), '?')).')';

        $situacoesComRetorno = [
            ProcessoDocumento::OCR_ENVIADO,
            ProcessoDocumento::OCR_PROCESSADO,
            ProcessoDocumento::OCR_ERRO,
        ];

        // `ignorados` é o complemento de `total` (o ELSE do mesmo IN): todo
        // mimetype fora da lista, inclusive vazio, conta ali, e
        // `total + ignorados` fecha com os documentos do processo no banco.
        //
        // Pendente é "os demais legíveis" (RF-08): `ocr_situacao` nulo, que é
        // como todo documento nasce, ou `pendente`. A condição é a negação das
        // outras 3 situações para que cada legível caia numa só contagem.
        $linha = ProcessoDocumento::query()
            ->toBase()
            ->where('processo_id', $processoId)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN '.$legivel.' THEN 1 ELSE 0 END), 0) AS total, '
                .'COALESCE(SUM(CASE WHEN '.$legivel.' AND ocr_situacao = ? THEN 1 ELSE 0 END), 0) AS processados, '
                .'COALESCE(SUM(CASE WHEN '.$legivel.' AND ocr_situacao = ? THEN 1 ELSE 0 END), 0) AS com_erro, '
                .'COALESCE(SUM(CASE WHEN '.$legivel.' THEN 0 ELSE 1 END), 0) AS ignorados, '
                .'COALESCE(SUM(CASE WHEN '.$legivel.' AND (ocr_situacao IS NULL OR ocr_situacao NOT IN (?, ?, ?)) THEN 1 ELSE 0 END), 0) AS pendentes, '
                .'COALESCE(SUM(CASE WHEN '.$legivel.' AND ocr_situacao = ? THEN 1 ELSE 0 END), 0) AS aguardando',
                [
                    ...$legiveis,
                    ...$legiveis, ProcessoDocumento::OCR_PROCESSADO,
                    ...$legiveis, ProcessoDocumento::OCR_ERRO,
                    ...$legiveis,
                    ...$legiveis, ...$situacoesComRetorno,
                    ...$legiveis, ProcessoDocumento::OCR_ENVIADO,
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
