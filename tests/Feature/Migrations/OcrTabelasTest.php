<?php

use App\Models\Processo;
use App\Models\ProcessoOcrAssinante;
use App\Models\ProcessoOcrProcessamento;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * T07: tabelas do OCR e colunas `ocr_*` em `processo_documentos`.
 * O `down()`/`up()` roda dentro da transação do teste (o PostgreSQL faz DDL
 * transacional), como em PreencherSiglaGrauTribunaisTest: nada sai do banco.
 */

uses(DatabaseTransactions::class);

const MIGRATION_OCR_TABELAS = '2026_11_06_000001_create_processo_ocr_tables';
const TABELAS_OCR = ['processo_ocr_processamentos', 'processo_ocr_assinantes', 'processo_ocr_avisos'];
const COLUNAS_OCR_DOCUMENTO = ['ocr_situacao', 'ocr_job_id', 'ocr_job_id_cancelar', 'ocr_enviado_em', 'ocr_concluido_em', 'ocr_erro'];

function migrationOcrTabelas(): object
{
    static $migration;

    return $migration ??= require database_path('migrations/' . MIGRATION_OCR_TABELAS . '.php');
}

/**
 * @return array<string, array{unique: bool, columns: array<int, string>}>
 */
function indicesDaTabelaOcr(string $tabela): array
{
    return collect(Schema::getIndexes($tabela))
        ->mapWithKeys(fn (array $indice) => [$indice['name'] => ['unique' => $indice['unique'], 'columns' => $indice['columns']]])
        ->all();
}

function definicaoDoIndiceOcr(string $nome): ?string
{
    return DB::table('pg_indexes')->where('indexname', $nome)->value('indexdef');
}

/**
 * Executa numa transação aninhada (savepoint): a violação de unique não
 * aborta a transação do teste.
 */
function inserirComSavepointOcr(Closure $inserir): ?Throwable
{
    try {
        DB::transaction($inserir);
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

it('a migration do OCR está aplicada', function () {
    expect(DB::table('migrations')->where('migration', MIGRATION_OCR_TABELAS)->exists())->toBeTrue();
});

it('cria processo_ocr_processamentos com situação, datas e o índice único parcial', function () {
    expect(Schema::hasColumns('processo_ocr_processamentos', [
        'id', 'processo_id', 'situacao', 'codigo_falha', 'aberto_em', 'ultimo_progresso_em', 'finalizado_em', 'created_at', 'updated_at',
    ]))->toBeTrue();

    $indices = indicesDaTabelaOcr('processo_ocr_processamentos');

    expect($indices['processo_ocr_processamentos_aberto_unico'])->toBe(['unique' => true, 'columns' => ['processo_id']])
        ->and(definicaoDoIndiceOcr('processo_ocr_processamentos_aberto_unico'))->toContain("WHERE ((situacao)::text = 'em_andamento'::text)")
        ->and($indices['processo_ocr_processamentos_situacao_index']['columns'])->toBe(['situacao']);
});

it('cria processo_ocr_assinantes com o callback e o unique por processamento e hash', function () {
    expect(Schema::hasColumns('processo_ocr_assinantes', [
        'id', 'processamento_id', 'api_token_id', 'dono', 'callback_url', 'callback_token', 'callback_hash',
        'encerrado_em', 'aviso_final_entregue_em', 'created_at', 'updated_at',
    ]))->toBeTrue();

    $colunas = collect(Schema::getColumns('processo_ocr_assinantes'))->keyBy('name');

    expect($colunas['callback_url']['type'])->toBe('character varying(2048)')
        ->and($colunas['callback_token']['type_name'])->toBe('text')
        ->and($colunas['callback_hash']['type'])->toBe('character(64)')
        ->and(indicesDaTabelaOcr('processo_ocr_assinantes')['processo_ocr_assinantes_processamento_id_callback_hash_unique'])
        ->toBe(['unique' => true, 'columns' => ['processamento_id', 'callback_hash']]);
});

it('cria processo_ocr_avisos com uuid, payload json e tentativas', function () {
    expect(Schema::hasColumns('processo_ocr_avisos', [
        'id', 'uuid', 'assinante_id', 'final', 'payload', 'enviado_em', 'tentativas', 'status_http', 'created_at', 'updated_at',
    ]))->toBeTrue();

    $colunas = collect(Schema::getColumns('processo_ocr_avisos'))->keyBy('name');
    $indices = indicesDaTabelaOcr('processo_ocr_avisos');

    expect($colunas['payload']['type_name'])->toBe('json')
        ->and($colunas['tentativas']['default'])->toBe("'0'::smallint")
        ->and($indices['processo_ocr_avisos_uuid_unique']['unique'])->toBeTrue()
        ->and($indices['processo_ocr_avisos_assinante_id_final_index']['columns'])->toBe(['assinante_id', 'final']);
});

it('acrescenta as colunas ocr_* e os índices em processo_documentos', function () {
    expect(Schema::hasColumns('processo_documentos', COLUNAS_OCR_DOCUMENTO))->toBeTrue();

    $colunas = collect(Schema::getColumns('processo_documentos'))->keyBy('name');

    foreach (COLUNAS_OCR_DOCUMENTO as $coluna) {
        expect($colunas[$coluna]['nullable'])->toBeTrue("{$coluna} deve ser nullable")
            ->and($colunas[$coluna]['default'])->toBeNull("{$coluna} sem default");
    }

    $indices = indicesDaTabelaOcr('processo_documentos');

    expect($colunas['ocr_erro']['type'])->toBe('character varying(1000)')
        ->and($indices['processo_documentos_ocr_job_id_index']['columns'])->toBe(['ocr_job_id'])
        ->and($indices['processo_documentos_processo_id_ocr_situacao_index']['columns'])->toBe(['processo_id', 'ocr_situacao']);
});

it('nenhuma tabela nova tem coluna para o login ou a senha do PJe', function () {
    $colunas = collect(TABELAS_OCR)
        ->flatMap(fn (string $tabela) => array_map(fn ($coluna) => "{$tabela}.{$coluna}", Schema::getColumnListing($tabela)))
        ->merge(array_map(fn ($coluna) => "processo_documentos.{$coluna}", COLUNAS_OCR_DOCUMENTO));

    expect($colunas->filter(fn (string $coluna) => preg_match('/login|senha|password|credencia/i', $coluna))->values()->all())->toBe([]);
});

it('o down() desfaz tudo e o up() refaz', function () {
    migrationOcrTabelas()->down();

    foreach (TABELAS_OCR as $tabela) {
        expect(Schema::hasTable($tabela))->toBeFalse("{$tabela} continuou depois do down()");
    }

    foreach (COLUNAS_OCR_DOCUMENTO as $coluna) {
        expect(Schema::hasColumn('processo_documentos', $coluna))->toBeFalse("{$coluna} continuou depois do down()");
    }

    expect(array_keys(indicesDaTabelaOcr('processo_documentos')))
        ->not->toContain('processo_documentos_ocr_job_id_index', 'processo_documentos_processo_id_ocr_situacao_index');

    migrationOcrTabelas()->up();

    foreach (TABELAS_OCR as $tabela) {
        expect(Schema::hasTable($tabela))->toBeTrue();
    }

    expect(Schema::hasColumns('processo_documentos', COLUNAS_OCR_DOCUMENTO))->toBeTrue()
        ->and(definicaoDoIndiceOcr('processo_ocr_processamentos_aberto_unico'))->not->toBeNull();
});

it('um 2º processamento em_andamento do mesmo processo lança UniqueConstraintViolationException', function () {
    $processo = Processo::factory()->create();
    ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);

    $erro = inserirComSavepointOcr(fn () => ProcessoOcrProcessamento::create([
        'processo_id' => $processo->id,
        'situacao' => ProcessoOcrProcessamento::SITUACAO_EM_ANDAMENTO,
    ]));

    expect($erro)->toBeInstanceOf(UniqueConstraintViolationException::class)
        ->and(ProcessoOcrProcessamento::where('processo_id', $processo->id)->emAndamento()->count())->toBe(1);
});

it('processamentos concluído e falho não colidem com o em andamento do mesmo processo', function () {
    $processo = Processo::factory()->create();

    ProcessoOcrProcessamento::create(['processo_id' => $processo->id, 'situacao' => ProcessoOcrProcessamento::SITUACAO_CONCLUIDO]);
    ProcessoOcrProcessamento::create(['processo_id' => $processo->id, 'situacao' => ProcessoOcrProcessamento::SITUACAO_FALHOU]);
    ProcessoOcrProcessamento::create(['processo_id' => $processo->id, 'situacao' => ProcessoOcrProcessamento::SITUACAO_CONCLUIDO]);
    $aberto = ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);

    // Encerrado o aberto, outro pode abrir: é a reabertura do pedido seguinte.
    $aberto->update(['situacao' => ProcessoOcrProcessamento::SITUACAO_FALHOU]);
    ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);

    expect(ProcessoOcrProcessamento::where('processo_id', $processo->id)->count())->toBe(5)
        ->and(ProcessoOcrProcessamento::where('processo_id', $processo->id)->emAndamento()->count())->toBe(1);
});

it('o mesmo callback não entra duas vezes no mesmo processamento', function () {
    $processo = Processo::factory()->create();
    $processamento = ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);
    $outro = ProcessoOcrProcessamento::create(['processo_id' => Processo::factory()->create()->id]);
    $callback = ['callback_url' => 'https://sistema.example/mni/aviso?assinatura=x', 'callback_token' => 'tok-processamento'];

    ProcessoOcrAssinante::create(['processamento_id' => $processamento->id, 'dono' => true] + $callback);

    $erro = inserirComSavepointOcr(fn () => ProcessoOcrAssinante::create(['processamento_id' => $processamento->id] + $callback));

    expect($erro)->toBeInstanceOf(UniqueConstraintViolationException::class);

    // Outro processamento, ou outro token no mesmo processamento, entram.
    ProcessoOcrAssinante::create(['processamento_id' => $outro->id] + $callback);
    ProcessoOcrAssinante::create(['processamento_id' => $processamento->id, 'callback_token' => 'tok-outro'] + $callback);

    expect($processamento->assinantes()->count())->toBe(2)
        ->and($outro->assinantes()->count())->toBe(1);
});

it('apagar o processo apaga processamentos, assinantes e avisos', function () {
    $processo = Processo::factory()->create();
    $processamento = ProcessoOcrProcessamento::create(['processo_id' => $processo->id]);
    $assinante = ProcessoOcrAssinante::create([
        'processamento_id' => $processamento->id,
        'callback_url' => 'https://sistema.example/aviso',
        'callback_token' => 'tok',
    ]);
    $assinante->avisos()->create(['payload' => ['situacao' => 'em_andamento']]);

    $processo->delete();

    expect(ProcessoOcrProcessamento::whereKey($processamento->id)->exists())->toBeFalse()
        ->and(ProcessoOcrAssinante::whereKey($assinante->id)->exists())->toBeFalse()
        ->and(DB::table('processo_ocr_avisos')->where('assinante_id', $assinante->id)->count())->toBe(0);
});
