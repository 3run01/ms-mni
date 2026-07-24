<?php

namespace App\Services\Dashboard;

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class DashboardMetricasService
{
    private const TIMEZONE = 'America/Sao_Paulo';

    public function metricas(int $periodoDias): array
    {
        $inicioLocal = CarbonImmutable::now(self::TIMEZONE)
            ->startOfDay()
            ->subDays($periodoDias - 1);
        $inicioUtc = $inicioLocal->setTimezone('UTC');

        return [
            'totais' => [
                'processos' => Processo::where('created_at', '>=', $inicioUtc)->count(),
                'documentosBaixados' => ProcessoDocumento::where('status', ProcessoDocumento::STATUS_BAIXADO)
                    ->where('downloaded_at', '>=', $inicioUtc)
                    ->count(),
                'documentosPendentes' => ProcessoDocumento::where('status', ProcessoDocumento::STATUS_PENDENTE)->count(),
                'documentosErro' => ProcessoDocumento::where('status', ProcessoDocumento::STATUS_ERRO)->count(),
            ],
            'processosPorDia' => $this->seriePorDia(
                Processo::query(),
                'created_at',
                $inicioLocal,
                $periodoDias,
            ),
            'documentosPorDia' => $this->seriePorDia(
                ProcessoDocumento::where('status', ProcessoDocumento::STATUS_BAIXADO),
                'downloaded_at',
                $inicioLocal,
                $periodoDias,
            ),
        ];
    }

    private function seriePorDia(Builder $query, string $coluna, CarbonImmutable $inicioLocal, int $periodoDias): array
    {
        // timestamps são armazenados em UTC; o dia útil pro usuário é o dia em SP
        $diaExpr = sprintf(
            "((%s AT TIME ZONE 'UTC') AT TIME ZONE '%s')::date",
            $coluna,
            self::TIMEZONE,
        );

        $contagens = $query
            ->where($coluna, '>=', $inicioLocal->setTimezone('UTC'))
            ->selectRaw("{$diaExpr} as dia, COUNT(*) as total")
            ->groupByRaw($diaExpr)
            ->pluck('total', 'dia');

        $serie = [];
        for ($i = 0; $i < $periodoDias; $i++) {
            $dia = $inicioLocal->addDays($i)->toDateString();
            $serie[] = ['dia' => $dia, 'total' => (int) ($contagens[$dia] ?? 0)];
        }

        return $serie;
    }
}
