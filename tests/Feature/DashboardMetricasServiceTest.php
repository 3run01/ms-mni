<?php

use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Services\Dashboard\DashboardMetricasService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function metricasService(): DashboardMetricasService
{
    return new DashboardMetricasService();
}

function docMetrica(Processo $processo, array $overrides = []): ProcessoDocumento
{
    static $seq = 0;
    return ProcessoDocumento::create(array_merge([
        'processo_id' => $processo->id,
        'id_documento' => 'DOC-MET-' . getmypid() . '-' . (++$seq),
        'tipo_documento' => 57,
        'descricao' => 'Doc métrica',
        'mimetype' => 'application/pdf',
        'data_hora' => '2026-01-05 10:00:00',
        'status' => ProcessoDocumento::STATUS_PENDENTE,
    ], $overrides));
}

it('conta processos do período e ignora os antigos (delta)', function () {
    $antes = metricasService()->metricas(7);

    Processo::factory()->create();                                    // dentro do período
    Processo::factory()->create(['created_at' => now()->subDays(30)]); // fora

    $depois = metricasService()->metricas(7);
    expect($depois['totais']['processos'] - $antes['totais']['processos'])->toBe(1);
});

it('conta documentos baixados por downloaded_at e pendentes/erro por estado (delta)', function () {
    $processo = Processo::factory()->create();
    $antes = metricasService()->metricas(7);

    docMetrica($processo, ['status' => ProcessoDocumento::STATUS_BAIXADO, 'downloaded_at' => now()]);
    docMetrica($processo, ['status' => ProcessoDocumento::STATUS_BAIXADO, 'downloaded_at' => now()->subDays(30)]); // fora do período
    docMetrica($processo, ['status' => ProcessoDocumento::STATUS_PENDENTE]);
    docMetrica($processo, ['status' => ProcessoDocumento::STATUS_ERRO]);

    $depois = metricasService()->metricas(7);
    expect($depois['totais']['documentosBaixados'] - $antes['totais']['documentosBaixados'])->toBe(1)
        ->and($depois['totais']['documentosPendentes'] - $antes['totais']['documentosPendentes'])->toBe(1)
        ->and($depois['totais']['documentosErro'] - $antes['totais']['documentosErro'])->toBe(1);
});

it('série diária tem N pontos contínuos terminando hoje (SP) e conta o delta de hoje', function () {
    $antes = metricasService()->metricas(7);
    Processo::factory()->create();
    $depois = metricasService()->metricas(7);

    expect($depois['processosPorDia'])->toHaveCount(7)
        ->and($depois['documentosPorDia'])->toHaveCount(7);

    $hoje = now('America/Sao_Paulo')->toDateString();
    $ultimoDepois = $depois['processosPorDia'][6];
    $ultimoAntes = $antes['processosPorDia'][6];
    expect($ultimoDepois['dia'])->toBe($hoje)
        ->and($ultimoDepois['total'] - $ultimoAntes['total'])->toBe(1);

    // série contínua: dias consecutivos sem buraco
    $dias = array_column($depois['processosPorDia'], 'dia');
    for ($i = 1; $i < count($dias); $i++) {
        expect($dias[$i])->toBe(
            \Carbon\CarbonImmutable::parse($dias[$i - 1])->addDay()->toDateString()
        );
    }
});

it('suporta períodos 30 e 90', function () {
    expect(metricasService()->metricas(30)['processosPorDia'])->toHaveCount(30)
        ->and(metricasService()->metricas(90)['processosPorDia'])->toHaveCount(90);
});
