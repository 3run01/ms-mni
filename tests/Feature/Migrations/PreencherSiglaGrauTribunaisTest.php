<?php

use App\Models\Tribunal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;

uses(DatabaseTransactions::class);

function migrationSiglaGrauTribunais(): object
{
    static $migration;

    return $migration ??= require database_path('migrations/2026_10_08_120000_add_sigla_grau_to_tribunais_table.php');
}

function tribunalSemSigla(string $nome, ?string $tipo = null): Tribunal
{
    return Tribunal::factory()->create(['nome' => $nome, 'tipo' => $tipo, 'sigla' => null, 'grau' => null]);
}

it('deriva sigla e grau do nome', function (string $nome, ?string $sigla, ?string $grau) {
    $tribunal = tribunalSemSigla($nome);

    migrationSiglaGrauTribunais()->up();

    expect($tribunal->fresh()->sigla)->toBe($sigla);
    expect($tribunal->fresh()->grau)->toBe($grau);
})->with([
    'TJAP 1º grau' => ['TJAP 1º Grau', 'TJAP', '1'],
    'TJAP 2º grau' => ['TJAP 2º Grau', 'TJAP', '2'],
    'TRT2 não vira TRT24' => ['TRT2 2º grau', 'TRT2', '2'],
    'TRT24' => ['TRT24 - Mato Grosso do Sul', 'TRT24', null],
    'TJMSP é militar' => ['TJMSP', 'TJMSP', null],
    'TJMS é estadual' => ['TJMS 1o Grau', 'TJMS', '1'],
    'TRE com hífen' => ['TRE-SP', 'TRE-SP', null],
    'TJDFT com °' => ['TJDFT 2° Grau', 'TJDFT', '2'],
    'superior' => ['STJ', 'STJ', null],
    'TRF fora da faixa' => ['TRF10', null, null],
]);

it('corrige o tipo STJ só em tribunal de justiça estadual', function () {
    $tjap = tribunalSemSigla('TJAP 1º Grau', 'Superior Tribunal de Justiça (STJ)');
    $tjdft = tribunalSemSigla('TJDFT', 'Superior Tribunal de Justiça (STJ)');
    $stj = tribunalSemSigla('STJ', 'Superior Tribunal de Justiça (STJ)');
    $trt = tribunalSemSigla('TRT2', 'Superior Tribunal de Justiça (STJ)');

    migrationSiglaGrauTribunais()->up();

    expect($tjap->fresh()->tipo)->toBe('Tribunal de Justiça');
    expect($tjdft->fresh()->tipo)->toBe('Tribunal de Justiça');
    expect($stj->fresh()->tipo)->toBe('Superior Tribunal de Justiça (STJ)');
    expect($trt->fresh()->tipo)->toBe('Superior Tribunal de Justiça (STJ)');
});

it('não sobrescreve sigla já preenchida', function () {
    $tribunal = Tribunal::factory()->create(['nome' => 'TJAP 1º Grau', 'sigla' => 'TJSP', 'grau' => null]);

    migrationSiglaGrauTribunais()->up();

    expect($tribunal->fresh()->sigla)->toBe('TJSP');
    expect($tribunal->fresh()->grau)->toBeNull();
});

it('registra no log a linha cuja sigla não sai do nome', function () {
    Log::spy();
    $tribunal = tribunalSemSigla('Tribunal Desconhecido');

    migrationSiglaGrauTribunais()->up();

    expect($tribunal->fresh()->sigla)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensagem, array $contexto) => str_contains($mensagem, 'sigla não derivável')
            && $contexto['id'] === $tribunal->id)
        ->once();
});
