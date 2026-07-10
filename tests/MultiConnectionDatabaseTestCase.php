<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Trait (não classe — ver nota em SimDatabaseTestCase) para testes que
 * escrevem na conexão default (pgsql: processos etc.) E na conexão sim
 * (tribunais). `null` = conexão default.
 */
trait MultiConnectionDatabaseTestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'sim'];
}
