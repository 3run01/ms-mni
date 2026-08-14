<?php

use App\Exceptions\MNIException;
use App\Jobs\EnviarWebhookMonitoramentoJob;
use App\Jobs\ExecutarMonitoramentoProcessoJob;
use App\Models\CredencialPje;
use App\Models\Processo;
use App\Models\ProcessoMonitoramento;
use App\Models\ProcessoMonitoramentoExecucao;
use App\Models\ProcessoMovimento;
use App\Models\Tribunal;
use App\Services\Processo\ProcessoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;

uses(DatabaseTransactions::class);

beforeEach(function () {
    Queue::fake();

    $this->tribunal = Tribunal::factory()->create();
    $this->monitoramento = ProcessoMonitoramento::factory()->create([
        'tribunal_id' => $this->tribunal->id,
    ]);
});

function criarProcessoDoMonitoramento(ProcessoMonitoramento $monitoramento): Processo
{
    return Processo::factory()->create([
        'tribunal_id' => $monitoramento->tribunal_id,
        'numero_processo' => $monitoramento->numero_processo,
    ]);
}

function movimento(Processo $processo, string $identificador): ProcessoMovimento
{
    return ProcessoMovimento::create([
        'processo_id' => $processo->id,
        'identificador_movimento' => $identificador,
        'codigo_nacional' => 123,
        'complemento' => 'complemento',
        'data_hora' => '2026-08-12 09:12:00',
    ]);
}

it('grava execução de sucesso com delta e não baixa binários', function () {
    $processo = criarProcessoDoMonitoramento($this->monitoramento);
    movimento($processo, 'MOV-1');

    $this->mock(ProcessoService::class, function ($mock) use ($processo) {
        $mock->shouldReceive('consultarNumero')
            ->once()
            ->withArgs(function ($tribunal, $numero, $login, $senha, $dataReferencia, $baixarBinarios) {
                expect($baixarBinarios)->toBeFalse();
                expect($numero)->toBe($this->monitoramento->numero_processo);
                return true;
            })
            ->andReturnUsing(function () use ($processo) {
                movimento($processo, 'MOV-2');
                return $processo->fresh();
            });
    });

    (new ExecutarMonitoramentoProcessoJob($this->monitoramento->id))->handle();

    $execucao = ProcessoMonitoramentoExecucao::where('monitoramento_id', $this->monitoramento->id)->first();

    expect($execucao->status)->toBe(ProcessoMonitoramentoExecucao::STATUS_SUCESSO)
        ->and($execucao->houve_alteracao)->toBeTrue()
        ->and($execucao->movimentos_novos)->toBe(1)
        ->and($execucao->delta['movimentos'][0]['identificador_movimento'])->toBe('MOV-2');

    $monitoramento = $this->monitoramento->fresh();

    expect($monitoramento->ultima_execucao_em)->not->toBeNull()
        ->and($monitoramento->data_referencia)->toBe(now()->format('Ymd'))
        ->and($monitoramento->falhas_consecutivas)->toBe(0)
        ->and($monitoramento->bloqueado_ate)->toBeNull();

    Queue::assertPushed(EnviarWebhookMonitoramentoJob::class, fn ($job) => $job->execucaoId === $execucao->id
        && $job->queue === 'monitoramento-webhook');
});

it('marca primeira execução quando o processo ainda não existia', function () {
    $this->mock(ProcessoService::class, function ($mock) {
        $mock->shouldReceive('consultarNumero')->once()->andReturnUsing(function () {
            $processo = criarProcessoDoMonitoramento($this->monitoramento);
            movimento($processo, 'MOV-1');
            return $processo->fresh();
        });
    });

    (new ExecutarMonitoramentoProcessoJob($this->monitoramento->id))->handle();

    $execucao = ProcessoMonitoramentoExecucao::where('monitoramento_id', $this->monitoramento->id)->first();

    expect($execucao->delta['primeira_execucao'])->toBeTrue()
        ->and($execucao->movimentos_novos)->toBe(1);
});

it('usa a véspera da última execução como data_referencia', function () {
    $this->monitoramento->update(['data_referencia' => '20260810']);
    $processo = criarProcessoDoMonitoramento($this->monitoramento);

    $this->mock(ProcessoService::class, function ($mock) use ($processo) {
        $mock->shouldReceive('consultarNumero')
            ->once()
            ->withArgs(function ($tribunal, $numero, $login, $senha, $dataReferencia) {
                expect($dataReferencia)->toBe('2026-08-09');
                return true;
            })
            ->andReturn($processo);
    });

    (new ExecutarMonitoramentoProcessoJob($this->monitoramento->id))->handle();
});

it('envia data_referencia null na primeira execução', function () {
    $processo = criarProcessoDoMonitoramento($this->monitoramento);

    $this->mock(ProcessoService::class, function ($mock) use ($processo) {
        $mock->shouldReceive('consultarNumero')
            ->once()
            ->withArgs(function ($tribunal, $numero, $login, $senha, $dataReferencia) {
                expect($dataReferencia)->toBeNull();
                return true;
            })
            ->andReturn($processo);
    });

    (new ExecutarMonitoramentoProcessoJob($this->monitoramento->id))->handle();
});

it('usa a credencial cifrada do monitoramento quando existe', function () {
    $credencial = CredencialPje::factory()->create([
        'tribunal_id' => $this->tribunal->id,
        'login' => '12345678900',
        'senha' => 'segredo-pje',
    ]);
    $this->monitoramento->update(['credencial_id' => $credencial->id]);
    $processo = criarProcessoDoMonitoramento($this->monitoramento);

    $this->mock(ProcessoService::class, function ($mock) use ($processo) {
        $mock->shouldReceive('consultarNumero')
            ->once()
            ->withArgs(function ($tribunal, $numero, $login, $senha) {
                expect($login)->toBe('12345678900')->and($senha)->toBe('segredo-pje');
                return true;
            })
            ->andReturn($processo);
    });

    (new ExecutarMonitoramentoProcessoJob($this->monitoramento->id))->handle();
});

it('cai no par padrão do .env quando não há credencial', function () {
    config()->set('pje.credenciais_padrao', ['login' => 'login-padrao', 'senha' => 'senha-padrao']);
    $processo = criarProcessoDoMonitoramento($this->monitoramento);

    $this->mock(ProcessoService::class, function ($mock) use ($processo) {
        $mock->shouldReceive('consultarNumero')
            ->once()
            ->withArgs(function ($tribunal, $numero, $login, $senha) {
                expect($login)->toBe('login-padrao')->and($senha)->toBe('senha-padrao');
                return true;
            })
            ->andReturn($processo);
    });

    (new ExecutarMonitoramentoProcessoJob($this->monitoramento->id))->handle();
});

it('sem credencial e sem par padrão manda null (fallback do tribunal)', function () {
    config()->set('pje.credenciais_padrao', ['login' => null, 'senha' => null]);
    $processo = criarProcessoDoMonitoramento($this->monitoramento);

    $this->mock(ProcessoService::class, function ($mock) use ($processo) {
        $mock->shouldReceive('consultarNumero')
            ->once()
            ->withArgs(function ($tribunal, $numero, $login, $senha) {
                expect($login)->toBeNull()->and($senha)->toBeNull();
                return true;
            })
            ->andReturn($processo);
    });

    (new ExecutarMonitoramentoProcessoJob($this->monitoramento->id))->handle();
});

it('registra falha, incrementa contador e notifica mesmo assim', function () {
    $this->mock(ProcessoService::class, function ($mock) {
        $mock->shouldReceive('consultarNumero')->once()->andThrow(new MNIException('MNI indisponível', 500));
    });

    (new ExecutarMonitoramentoProcessoJob($this->monitoramento->id))->handle();

    $execucao = ProcessoMonitoramentoExecucao::where('monitoramento_id', $this->monitoramento->id)->first();

    expect($execucao->status)->toBe(ProcessoMonitoramentoExecucao::STATUS_FALHA)
        ->and($execucao->erro_resumo)->toContain('MNI indisponível')
        ->and($this->monitoramento->fresh()->falhas_consecutivas)->toBe(1)
        ->and($this->monitoramento->fresh()->status)->toBe(ProcessoMonitoramento::STATUS_ATIVO)
        ->and($this->monitoramento->fresh()->bloqueado_ate)->toBeNull();

    Queue::assertPushed(EnviarWebhookMonitoramentoJob::class);
});

it('grava a mensagem de login já padronizada no erro_resumo', function () {
    // é este texto que aparece no tooltip da dashboard e no payload do webhook
    $this->mock(ProcessoService::class, function ($mock) {
        $mock->shouldReceive('consultarNumero')
            ->once()
            ->andThrow(new MNIException('Erro ao realizar login via MNI. exception invoking: loginFailed', 500));
    });

    (new ExecutarMonitoramentoProcessoJob($this->monitoramento->id))->handle();

    $execucao = ProcessoMonitoramentoExecucao::where('monitoramento_id', $this->monitoramento->id)->first();

    expect($execucao->erro_resumo)->toBe('Erro ao realizar login via MNI. Verifique suas credenciais.');
});

it('suspende o monitoramento na quinta falha consecutiva', function () {
    $this->monitoramento->update(['falhas_consecutivas' => 4]);

    $this->mock(ProcessoService::class, function ($mock) {
        $mock->shouldReceive('consultarNumero')->once()->andThrow(new MNIException('erro', 500));
    });

    (new ExecutarMonitoramentoProcessoJob($this->monitoramento->id))->handle();

    expect($this->monitoramento->fresh()->status)->toBe(ProcessoMonitoramento::STATUS_SUSPENSO)
        ->and($this->monitoramento->fresh()->falhas_consecutivas)->toBe(5);
});

it('não lança nem notifica quando o monitoramento não existe', function () {
    (new ExecutarMonitoramentoProcessoJob(999999))->handle();

    Queue::assertNotPushed(EnviarWebhookMonitoramentoJob::class);
});
