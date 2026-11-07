<?php

namespace App\Console\Commands;

use App\Services\Ocr\AcompanharProcessamentosOcrService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Rodada do acompanhamento de reserva do OCR (RF-13, RF-18 item 3). Fino:
 * a regra fica em AcompanharProcessamentosOcrService. Agendado a cada
 * minuto em routes/console.php, sem sobreposição: o fim por P chega antes
 * dos 30 min sem aviso do Sistema, e a consulta antes do reenvio sai no
 * minuto em que a idade passa de A (R2-3).
 */
class OcrAcompanhar extends Command
{
    protected $signature = 'ocr:acompanhar';

    protected $description = 'Acompanha os processamentos de OCR em andamento: fim por prazo sem progresso e consulta antes do reenvio (roda a cada minuto)';

    public function handle(AcompanharProcessamentosOcrService $acompanhamento): int
    {
        $resumo = $acompanhamento->rodada();

        $this->info(sprintf(
            '%d processamento(s) em andamento; %d com o prazo vencido (%d consulta(s), %d encerrado(s)); %d consulta(s) antes do reenvio despachada(s); %d falha(s).',
            $resumo['processamentos'],
            $resumo['vencidos'],
            $resumo['consultas'],
            $resumo['encerrados'],
            $resumo['verificacoes'],
            $resumo['falhas'],
        ));

        Log::info('[ProcessamentoOcr] rodada do acompanhamento', $resumo);

        return $resumo['falhas'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
