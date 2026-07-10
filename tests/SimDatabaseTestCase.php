<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * NOTA: implementado como trait, não como classe abstrata que estende TestCase.
 *
 * `tests/Pest.php` já faz `pest()->extend(Tests\TestCase::class)->in('Feature', 'Unit')`,
 * vinculando toda a árvore `tests/Feature` à classe `Tests\TestCase`. O mecanismo interno
 * do Pest (`TestRepository::make()`) lança `TestCaseAlreadyInUse` se um arquivo de teste
 * tentar vincular uma segunda CLASSE de teste (via `uses(OutraClasse::class)`), mesmo que
 * essa classe estenda `Tests\TestCase` — só é permitido compor TRAITS adicionais. Por isso
 * este helper é uma trait: `uses(SimDatabaseTestCase::class)` continua funcionando (Pest
 * detecta via `trait_exists()` e injeta em `$testCase->traits`), sem colidir com o default
 * global do `Pest.php`. A propriedade abaixo é um valor padrão de classe (presente desde a
 * construção do objeto), então não há dependência de ordem com `beginDatabaseTransaction()`
 * (chamado em `setUpTraits()`, que usa `class_uses_recursive()` — resolve traits usadas
 * dentro de outras traits, então `DatabaseTransactions` é detectada normalmente).
 */
trait SimDatabaseTestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sim'];
}
