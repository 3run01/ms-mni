<?php

use App\Models\ApiToken;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrAssinante;
use App\Models\ProcessoOcrAviso;
use App\Models\ProcessoOcrProcessamento;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const CALLBACK_URL_MODELS_OCR = 'https://sistema.example/mni/processamentos/9f1c/progresso?signature=abc';
const CALLBACK_TOKEN_MODELS_OCR = 'tok-processamento-5e7';

function processamentoModelsOcr(array $atributos = []): ProcessoOcrProcessamento
{
    return ProcessoOcrProcessamento::create(array_merge([
        'processo_id' => Processo::factory()->create()->id,
    ], $atributos));
}

function assinanteModelsOcr(ProcessoOcrProcessamento $processamento, array $atributos = []): ProcessoOcrAssinante
{
    return ProcessoOcrAssinante::create(array_merge([
        'processamento_id' => $processamento->id,
        'callback_url' => CALLBACK_URL_MODELS_OCR,
        'callback_token' => CALLBACK_TOKEN_MODELS_OCR,
    ], $atributos));
}

// ---------- ProcessoDocumento ----------

it('ProcessoDocumento expõe as situações do OCR como constantes', function () {
    expect(ProcessoDocumento::OCR_PENDENTE)->toBe('pendente')
        ->and(ProcessoDocumento::OCR_ENVIADO)->toBe('enviado')
        ->and(ProcessoDocumento::OCR_PROCESSADO)->toBe('processado')
        ->and(ProcessoDocumento::OCR_ERRO)->toBe('erro');
});

it('ProcessoDocumento aceita as colunas ocr_* em massa', function () {
    expect((new ProcessoDocumento())->getFillable())->toContain(
        'ocr_situacao', 'ocr_job_id', 'ocr_job_id_cancelar', 'ocr_enviado_em', 'ocr_concluido_em', 'ocr_erro',
    );
});

it('ProcessoDocumento::toArray() não tem nenhuma chave ocr_*', function () {
    [, $documentos] = criarDocumentosLote([[
        'data_juntada' => '2026-01-05 10:00:00',
        'ocr_situacao' => ProcessoDocumento::OCR_ENVIADO,
        'ocr_job_id' => (string) Str::uuid(),
        'ocr_job_id_cancelar' => (string) Str::uuid(),
        'ocr_enviado_em' => now(),
        'ocr_concluido_em' => now(),
        'ocr_erro' => 'Falha no workflow',
    ]]);

    $array = $documentos->first()->fresh()->toArray();

    expect(array_filter(array_keys($array), fn (string $chave) => str_starts_with($chave, 'ocr_')))->toBe([])
        ->and($array['data_juntada'])->toBe('2026-01-05 10:00:00');
});

it('ProcessoDocumento não ganha cast em data_juntada e lê as datas do OCR como Carbon', function () {
    [, $documentos] = criarDocumentosLote([['ocr_enviado_em' => '2026-11-06 10:00:00']]);
    $documento = $documentos->first()->fresh();

    expect($documento->getCasts())->not->toHaveKey('data_juntada')
        ->and($documento->ocr_enviado_em)->toBeInstanceOf(\Carbon\CarbonInterface::class)
        ->and($documento->ocr_enviado_em->format('Y-m-d H:i:s'))->toBe('2026-11-06 10:00:00');
});

// ---------- ProcessoOcrProcessamento ----------

it('ProcessoOcrProcessamento expõe as situações como constantes', function () {
    expect(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO)->toBe('em_andamento')
        ->and(ProcessoOcrProcessamento::SITUACAO_CONCLUIDO)->toBe('concluido')
        ->and(ProcessoOcrProcessamento::SITUACAO_FALHOU)->toBe('falhou');
});

it('ProcessoOcrProcessamento nasce em andamento, com aberto_em e datas como Carbon', function () {
    $this->travelTo('2026-11-06 12:00:00');

    $processamento = processamentoModelsOcr()->fresh();

    expect($processamento->situacao)->toBe(ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO)
        ->and($processamento->aberto_em->format('Y-m-d H:i:s'))->toBe('2026-11-06 12:00:00')
        ->and($processamento->ultimo_progresso_em)->toBeNull()
        ->and($processamento->finalizado_em)->toBeNull()
        ->and($processamento->codigo_falha)->toBeNull();

    $processamento->update(['ultimo_progresso_em' => now(), 'finalizado_em' => now()]);

    expect($processamento->fresh()->ultimo_progresso_em)->toBeInstanceOf(\Carbon\CarbonInterface::class)
        ->and($processamento->fresh()->finalizado_em)->toBeInstanceOf(\Carbon\CarbonInterface::class);
});

it('ProcessoOcrProcessamento liga o processo e os assinantes', function () {
    $processamento = processamentoModelsOcr();
    $assinante = assinanteModelsOcr($processamento, ['dono' => true]);

    expect($processamento->processo)->toBeInstanceOf(Processo::class)
        ->and($processamento->assinantes->pluck('id')->all())->toBe([$assinante->id])
        ->and($assinante->processamento->is($processamento))->toBeTrue();
});

// ---------- ProcessoOcrAssinante ----------

it('ProcessoOcrAssinante grava o callback_token cifrado e lê o texto', function () {
    $assinante = assinanteModelsOcr(processamentoModelsOcr());

    $cru = DB::table('processo_ocr_assinantes')->where('id', $assinante->id)->value('callback_token');

    expect($cru)->not->toBe(CALLBACK_TOKEN_MODELS_OCR)
        ->and($cru)->not->toContain(CALLBACK_TOKEN_MODELS_OCR)
        ->and(Crypt::decryptString($cru))->toBe(CALLBACK_TOKEN_MODELS_OCR)
        ->and($assinante->fresh()->callback_token)->toBe(CALLBACK_TOKEN_MODELS_OCR);
});

it('ProcessoOcrAssinante calcula o callback_hash da URL e do token', function () {
    $assinante = assinanteModelsOcr(processamentoModelsOcr());
    $esperado = hash('sha256', CALLBACK_URL_MODELS_OCR . "\n" . CALLBACK_TOKEN_MODELS_OCR);

    expect(ProcessoOcrAssinante::hashCallback(CALLBACK_URL_MODELS_OCR, CALLBACK_TOKEN_MODELS_OCR))->toBe($esperado)
        ->and($assinante->fresh()->callback_hash)->toBe($esperado);

    $assinante->update(['callback_token' => 'tok-novo']);

    expect($assinante->fresh()->callback_hash)->toBe(hash('sha256', CALLBACK_URL_MODELS_OCR . "\ntok-novo"));
});

it('ProcessoOcrAssinante esconde o token e o hash na serialização', function () {
    $assinante = assinanteModelsOcr(processamentoModelsOcr(), ['dono' => true]);
    $array = $assinante->fresh()->toArray();

    expect($array)->not->toHaveKeys(['callback_token', 'callback_hash'])
        ->and($array['dono'])->toBeTrue()
        ->and(json_encode($array))->not->toContain(CALLBACK_TOKEN_MODELS_OCR);
});

it('ProcessoOcrAssinante::ativos deixa de fora o encerrado e o que já recebeu o aviso final', function () {
    $processamento = processamentoModelsOcr();
    $ativo = assinanteModelsOcr($processamento, ['callback_token' => 'tok-ativo']);
    assinanteModelsOcr($processamento, ['callback_token' => 'tok-encerrado', 'encerrado_em' => now()]);
    assinanteModelsOcr($processamento, ['callback_token' => 'tok-final', 'aviso_final_entregue_em' => now()]);

    expect($processamento->assinantes()->ativos()->pluck('id')->all())->toBe([$ativo->id]);
});

it('ProcessoOcrAssinante aceita o token de API do pedido e o perde quando o token é apagado', function () {
    $token = ApiToken::create(['name' => 'token-ocr-' . Str::random(6), 'token' => ApiToken::hashToken('tk-ocr'), 'ativo' => true]);
    $assinante = assinanteModelsOcr(processamentoModelsOcr(), ['api_token_id' => $token->id]);

    $token->delete();

    expect($assinante->fresh()->api_token_id)->toBeNull();
});

// ---------- ProcessoOcrAviso ----------

it('ProcessoOcrAviso gera o uuid e guarda o payload como array', function () {
    $assinante = assinanteModelsOcr(processamentoModelsOcr());
    $payload = [
        'numero_processo' => '12345674220268260100',
        'tribunal_id' => 12,
        'total' => 3,
        'processados' => 1,
        'com_erro' => 0,
        'ignorados' => 0,
        'situacao' => 'em_andamento',
    ];

    $aviso = $assinante->avisos()->create(['payload' => $payload])->fresh();

    expect(Str::isUuid($aviso->uuid))->toBeTrue()
        ->and($aviso->payload)->toBe($payload)
        ->and($aviso->final)->toBeFalse()
        ->and($aviso->tentativas)->toBe(0)
        ->and($aviso->enviado_em)->toBeNull()
        ->and($aviso->status_http)->toBeNull()
        ->and($aviso->assinante->is($assinante))->toBeTrue();
});

it('ProcessoOcrAviso mantém o uuid informado', function () {
    $uuid = (string) Str::uuid();
    $aviso = assinanteModelsOcr(processamentoModelsOcr())->avisos()->create([
        'uuid' => $uuid,
        'final' => true,
        'payload' => ['situacao' => 'concluido'],
    ]);

    expect($aviso->fresh()->uuid)->toBe($uuid)
        ->and($aviso->fresh()->final)->toBeTrue();
});
