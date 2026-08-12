<?php

use App\Models\ApiToken;
use App\Models\CredencialPje;
use App\Models\Tribunal;
use App\Services\Monitoramento\CredencialPjeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->service = new CredencialPjeService();
    $this->token = ApiToken::factory()->create();
    $this->tribunal = Tribunal::factory()->create();
});

it('retorna null sem par completo (regra atômica)', function () {
    expect($this->service->resolver($this->token, $this->tribunal, null, null))->toBeNull()
        ->and($this->service->resolver($this->token, $this->tribunal, 'so-login', null))->toBeNull()
        ->and($this->service->resolver($this->token, $this->tribunal, null, 'so-senha'))->toBeNull()
        ->and(CredencialPje::count())->toBe(0);
});

it('cria credencial cifrada com login_hash', function () {
    $credencial = $this->service->resolver($this->token, $this->tribunal, '12345678900', 'segredo-pje');

    expect($credencial)->not->toBeNull()
        ->and($credencial->api_token_id)->toBe($this->token->id)
        ->and($credencial->tribunal_id)->toBe($this->tribunal->id)
        ->and($credencial->login_hash)->toBe(CredencialPje::hashLogin('12345678900'));

    $this->assertDatabaseMissing('credenciais_pje', ['login' => '12345678900']);
    $this->assertDatabaseMissing('credenciais_pje', ['senha' => 'segredo-pje']);
});

it('reusa credencial existente do mesmo (token, tribunal, login)', function () {
    $primeira = $this->service->resolver($this->token, $this->tribunal, '12345678900', 'segredo-pje');
    $segunda = $this->service->resolver($this->token, $this->tribunal, '12345678900', 'segredo-pje');

    expect($segunda->id)->toBe($primeira->id)
        ->and(CredencialPje::count())->toBe(1);
});

it('atualiza a senha quando muda para o mesmo login', function () {
    $primeira = $this->service->resolver($this->token, $this->tribunal, '12345678900', 'senha-antiga');
    $segunda = $this->service->resolver($this->token, $this->tribunal, '12345678900', 'senha-nova');

    expect($segunda->id)->toBe($primeira->id)
        ->and($segunda->fresh()->senha)->toBe('senha-nova')
        ->and(CredencialPje::count())->toBe(1);
});

it('não reusa credencial de outro token', function () {
    $outroToken = ApiToken::factory()->create();

    $minha = $this->service->resolver($this->token, $this->tribunal, '12345678900', 'segredo');
    $dele = $this->service->resolver($outroToken, $this->tribunal, '12345678900', 'segredo');

    expect($dele->id)->not->toBe($minha->id)
        ->and(CredencialPje::count())->toBe(2);
});
