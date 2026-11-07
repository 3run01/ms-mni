<?php

use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;

/*
 * RNF-02 (parte Pest): as filas do OCR têm supervisor em todos os ambientes
 * e, em produção, download + envio somam 20 workers. O plano é montado pelo
 * próprio ProvisioningPlan do Horizon, o mesmo do boot de `php artisan horizon`.
 */

const SUPERVISORES_OCR = [
    'supervisor-ocr-download' => 'ocr-download',
    'supervisor-ocr-envio' => 'ocr-envio',
    'supervisor-ocr-aviso' => 'ocr-aviso',
];

const AMBIENTES_HORIZON_OCR = ['production', 'local', '*'];

/**
 * @return array<string, array<string, SupervisorOptions>>
 */
function planoHorizonOcr(): array
{
    $plano = new ProvisioningPlan('ms-mni-teste', config('horizon.environments'), config('horizon.defaults'));

    return collect($plano->toSupervisorOptions())
        ->map(fn ($supervisores) => collect($supervisores)->all())
        ->all();
}

function opcoesSupervisorOcr(string $ambiente, string $supervisor): SupervisorOptions
{
    $opcoes = planoHorizonOcr()[$ambiente][$supervisor] ?? null;

    expect($opcoes)->not->toBeNull("{$supervisor} ausente em {$ambiente}");

    return $opcoes;
}

it('em produção, ocr-download + ocr-envio somam 20 maxProcesses', function () {
    $download = opcoesSupervisorOcr('production', 'supervisor-ocr-download');
    $envio = opcoesSupervisorOcr('production', 'supervisor-ocr-envio');

    expect($download->maxProcesses)->toBe(12)
        ->and($envio->maxProcesses)->toBe(8)
        ->and($download->maxProcesses + $envio->maxProcesses)->toBe(20)
        ->and(opcoesSupervisorOcr('production', 'supervisor-ocr-aviso')->maxProcesses)->toBe(2);
});

it('os 3 supervisores do OCR existem em production, local e *, com connection e fila próprias', function () {
    foreach (AMBIENTES_HORIZON_OCR as $ambiente) {
        foreach (SUPERVISORES_OCR as $supervisor => $fila) {
            $opcoes = opcoesSupervisorOcr($ambiente, $supervisor);

            expect($opcoes->connection)->toBe('redis', "{$ambiente}/{$supervisor}")
                ->and($opcoes->queue)->toBe($fila, "{$ambiente}/{$supervisor}");
        }
    }
});

it('usa os workers de desenvolvimento em local e no fallback *', function () {
    foreach (['local', '*'] as $ambiente) {
        expect(opcoesSupervisorOcr($ambiente, 'supervisor-ocr-download')->maxProcesses)->toBe(2)
            ->and(opcoesSupervisorOcr($ambiente, 'supervisor-ocr-envio')->maxProcesses)->toBe(2)
            ->and(opcoesSupervisorOcr($ambiente, 'supervisor-ocr-aviso')->maxProcesses)->toBe(1);
    }
});

it('download com balance auto, tries 3 e timeout 300; envio e aviso com tries 5 e timeout 60', function () {
    foreach (AMBIENTES_HORIZON_OCR as $ambiente) {
        $download = opcoesSupervisorOcr($ambiente, 'supervisor-ocr-download');

        expect($download->balance)->toBe('auto')
            ->and($download->maxTries)->toBe(3)
            ->and($download->timeout)->toBe(300)
            ->and($download->memory)->toBe(512)
            ->and($download->maxTime)->toBe(1800);

        foreach (['supervisor-ocr-envio', 'supervisor-ocr-aviso'] as $supervisor) {
            $opcoes = opcoesSupervisorOcr($ambiente, $supervisor);

            expect($opcoes->balance)->toBe('simple', "{$ambiente}/{$supervisor}")
                ->and($opcoes->maxTries)->toBe(5, "{$ambiente}/{$supervisor}")
                ->and($opcoes->timeout)->toBe(60, "{$ambiente}/{$supervisor}");
        }
    }
});

it('ocr-download e ocr-envio têm waits configurados', function () {
    expect(config('horizon.waits'))->toHaveKey('redis:ocr-download')
        ->and(config('horizon.waits.redis:ocr-download'))->toBe(60)
        ->and(config('horizon.waits'))->toHaveKey('redis:ocr-envio')
        ->and(config('horizon.waits.redis:ocr-envio'))->toBe(60);
});

it('supervisor-monitoramento continua com maxProcesses 1 em todos os ambientes', function () {
    foreach (planoHorizonOcr() as $ambiente => $supervisores) {
        expect($supervisores['supervisor-monitoramento']->maxProcesses)->toBe(1, "supervisor-monitoramento em {$ambiente}");
    }
});

it('supervisor-mni-download mantém os 8 workers de produção', function () {
    expect(opcoesSupervisorOcr('production', 'supervisor-mni-download')->maxProcesses)->toBe(8);
});
