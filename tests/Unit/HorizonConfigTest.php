<?php

/*
 * Regressão: um supervisor listado em horizon.environments[<env>] sem par
 * correspondente em horizon.defaults fica sem a chave 'connection'. Como o
 * Horizon monta o ProvisioningPlan de TODOS os ambientes no boot
 * (ProvisioningPlan::get + SupervisorOptions::fromArray, que acessa
 * $array['connection'] direto), um bloco quebrado em QUALQUER ambiente
 * derruba `php artisan horizon` em produção também -> dashboard "Inactive",
 * 0 processos. Já aconteceu duas vezes (sim e sim-mni).
 *
 * Este teste replica o merge do Horizon e falha se algum supervisor, em
 * qualquer ambiente, não resolver 'connection'.
 */
test('todo supervisor do Horizon resolve uma connection em todos os ambientes', function () {
    $defaults = config('horizon.defaults');

    $orphans = [];

    foreach (config('horizon.environments') as $environment => $supervisors) {
        // Mesmo merge que ProvisioningPlan::applyDefaultOptions() executa.
        $merged = array_replace_recursive($defaults, $supervisors);

        foreach ($merged as $name => $options) {
            if (! is_array($options) || ! array_key_exists('connection', $options)) {
                $orphans[] = "{$environment}/{$name}";
            }
        }
    }

    expect($orphans)->toBe(
        [],
        'Supervisores sem "connection" derrubam o boot do Horizon em TODOS os ambientes '
        .'(orphan em horizon.environments sem par em horizon.defaults): '.implode(', ', $orphans)
    );
});
