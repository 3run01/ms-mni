<?php

namespace App\Services\Ocr;

use App\Exceptions\ConfiguracaoOcrIncompletaException;
use App\Exceptions\EsperaDaTravaOcrEsgotadaException;
use App\Jobs\BaixarDocumentoParaOcrJob;
use App\Jobs\EnviarDocumentoAoOcrJob;
use App\Jobs\VerificarReenvioOcrJob;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoOcrAssinante;
use App\Models\ProcessoOcrProcessamento;
use App\Models\Tribunal;
use App\Services\Processo\ProcessoService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pedido de OCR de um processo numa linha (T19; RF-04 a RF-07, RF-18 itens
 * 1, 3 e 4). Decide, de forma exclusiva por (`tribunal_id`, `numero_processo`),
 * entre entrar como carona num processamento aberto, abrir um processamento
 * novo ou responder `concluido` sem abrir nada.
 *
 * Ordem de `solicitar()`:
 * 1. sem `services.n8n_ocr.url` ou `token` → ConfiguracaoOcrIncompletaException,
 *    antes de qualquer SOAP;
 * 2. trava da decisão (D2), `Cache::lock` por linha e número com `block(W)`.
 *    Esgotada a espera → EsperaDaTravaOcrEsgotadaException, com 0 SOAP e 0
 *    assinantes. A consulta ao MNI roda dentro da trava, para que o 2º pedido
 *    simultâneo vire carona (RF-06 AC c);
 * 3. processamento aberto → carona: registra o assinante (novo, ou o mesmo
 *    pelo `callback_hash`) e devolve as contagens atuais, com 0 SOAP, 0 jobs
 *    e sem guardar o par (RF-06);
 * 4. senão, a lista completa do processo na linha, com o par do pedido e sem
 *    recorte de data (RF-04 item 1, D8). As falhas classificadas (recusa,
 *    processo inexistente) e as demais sobem antes de qualquer gravação do
 *    OCR (RF-05);
 * 5. numa transação, com o processamento travado (D6): sem pendente, sem
 *    `com_erro` e sem aguardando retorno → `concluido`, sem processamento,
 *    assinante, job nem aviso (RF-07). Senão, abre o processamento e o
 *    assinante dono do par e devolve à fila só os `com_erro` (NC-3). "A
 *    enfileirar" são os pendentes; "a consultar" são os aguardando retorno
 *    com idade ≥ A, que seguem `enviado`, com o job conhecido, até a decisão
 *    de D9. Os aguardando retorno com idade < A não entram na fila (RF-18
 *    itens 1 e 4). Uma UniqueConstraintViolationException na abertura (a
 *    trava venceu durante a consulta e outro pedido abriu) vira carona;
 * 6. depois do commit: "a enfileirar" legível, já `baixado` com `path` →
 *    EnviarDocumentoAoOcrJob, com 0 SOAP (RF-09, D3); senão
 *    BaixarDocumentoParaOcrJob, cifrado, com o par do dono. O mimetype vazio
 *    vai sempre ao download, que o revela pelo tribunal (RF-08, RF-13).
 *    "A consultar" → VerificarReenvioOcrJob, sem o par (D9);
 * 7. devolve `em_andamento` com as contagens depois da devolução (`com_erro`
 *    = 0, D-08).
 *
 * Só a linha `tribunal_id`: nenhum fan-out para outras linhas da mesma sigla
 * (RF-04 item 4). Os logs levam só ids e contagens, nunca o par nem o
 * callback (RNF-01).
 */
class SolicitarOcrProcessoService
{
    public function __construct(
        private readonly ProcessoService $processos,
        private readonly ProcessamentoOcrService $processamentos,
        private readonly ContagemOcrService $contagem,
    ) {}

    /**
     * Chave da trava da decisão por linha e número (D2).
     */
    public static function chaveDaTrava(int $tribunalId, string $numeroProcesso): string
    {
        return "ocr:processamento:{$tribunalId}:{$numeroProcesso}";
    }

    /**
     * @throws ConfiguracaoOcrIncompletaException sem a base ou o token do workflow
     * @throws EsperaDaTravaOcrEsgotadaException quando a decisão do mesmo
     *                                           processo e linha ficou presa por mais de W
     * @throws \App\Exceptions\MNIException na falha da consulta ao MNI:
     *                                      CredencialPjeRecusadaException, ProcessoNaoEncontradoNoTribunalException
     *                                      ou a MNIException sem código
     */
    public function solicitar(DadosSolicitacaoOcr $dados): ResultadoSolicitacaoOcr
    {
        $this->garantirConfiguracao();

        $tribunal = Tribunal::query()->where('ativo', true)->findOrFail($dados->tribunalId);

        $espera = max(0, (int) config('services.n8n_ocr.espera_da_trava_segundos'));
        $validade = max(1, (int) config('services.n8n_ocr.trava_validade_segundos'));

        $trava = Cache::lock(self::chaveDaTrava($tribunal->id, $dados->numeroProcesso), $validade);

        try {
            $trava->block($espera);
        } catch (LockTimeoutException $e) {
            Log::warning('Pedido de OCR desistiu da espera pela trava do processo', [
                'tribunal_id' => $tribunal->id,
                'numero_processo' => $dados->numeroProcesso,
                'espera_segundos' => $espera,
            ]);

            throw new EsperaDaTravaOcrEsgotadaException($espera, $e);
        }

        try {
            return $this->decidir($tribunal, $dados);
        } finally {
            $trava->release();
        }
    }

    private function garantirConfiguracao(): void
    {
        $ausentes = array_values(array_filter(
            ['services.n8n_ocr.url', 'services.n8n_ocr.token'],
            fn (string $chave) => blank(config($chave)),
        ));

        if ($ausentes !== []) {
            throw new ConfiguracaoOcrIncompletaException($ausentes);
        }
    }

    /**
     * Chamado com a trava da decisão em mãos.
     */
    private function decidir(Tribunal $tribunal, DadosSolicitacaoOcr $dados): ResultadoSolicitacaoOcr
    {
        // 3. Carona sem SOAP: só um processo já gravado na linha pode ter
        //    processamento aberto.
        $processoId = $this->processoGravado($tribunal->id, $dados->numeroProcesso);

        if ($processoId !== null) {
            $carona = DB::transaction(function () use ($processoId, $dados) {
                $aberto = $this->processamentos->processamentoAberto($processoId, travar: true);

                return $aberto !== null ? $this->registrarCarona($aberto, $dados) : null;
            });

            if ($carona !== null) {
                return $carona;
            }
        }

        // 4. Lista completa com o par do pedido. Uma falha aqui sobe sem
        //    nenhuma linha do OCR gravada (RF-05).
        $processo = $this->processos->consultarListaCompletaParaOcr(
            $tribunal,
            $dados->numeroProcesso,
            $dados->login,
            $dados->senha,
        );

        // 5. Decisão e abertura, numa transação.
        [$resultado, $aEnfileirar, $aConsultar] = DB::transaction(fn () => $this->abrir($processo->id, $dados));

        // 6. Depois do commit: um job nunca vê um processamento desfeito.
        if (! $resultado->carona && $resultado->processamentoId !== null) {
            $this->despachar($resultado->processamentoId, $aEnfileirar, $aConsultar, $dados);
        }

        return $resultado;
    }

    /**
     * @return array{0: ResultadoSolicitacaoOcr, 1: Collection<int, ProcessoDocumento>, 2: Collection<int, int>}
     */
    private function abrir(int $processoId, DadosSolicitacaoOcr $dados): array
    {
        $nada = [collect(), collect()];

        // D2: a trava do cache pode ter vencido durante uma consulta longa ao
        // MNI, e outro pedido já abriu. Este entra como carona.
        $aberto = $this->processamentos->processamentoAberto($processoId, travar: true);

        if ($aberto !== null) {
            return [$this->registrarCarona($aberto, $dados), ...$nada];
        }

        $antes = $this->contagem->contar($processoId);

        // RF-07: nada a ler. Sem `com_erro`, pela regra da devolução (NC-3).
        if ($antes->pendentes === 0 && $antes->comErro === 0 && $antes->aguardando === 0) {
            Log::info("[Processo:{$processoId}] pedido de OCR sem documento a ler; nada aberto", [
                'total' => $antes->total,
                'processados' => $antes->processados,
                'ignorados' => $antes->ignorados,
            ]);

            return [ResultadoSolicitacaoOcr::deContagem($dados, $antes, ResultadoSolicitacaoOcr::SITUACAO_CONCLUIDO), ...$nada];
        }

        // Num savepoint: o índice único parcial é a rede de segurança da
        // trava (D2). Se outro pedido abriu entre a leitura acima e o insert,
        // só o insert é desfeito, e este pedido vira carona do aberto.
        try {
            $processamento = DB::transaction(fn () => ProcessoOcrProcessamento::create(['processo_id' => $processoId]));
        } catch (UniqueConstraintViolationException $e) {
            $aberto = $this->processamentos->processamentoAberto($processoId, travar: true);

            if ($aberto === null) {
                throw $e;
            }

            return [$this->registrarCarona($aberto, $dados), ...$nada];
        }

        $dono = ProcessoOcrAssinante::create([
            'processamento_id' => $processamento->id,
            'api_token_id' => $dados->apiTokenId,
            'dono' => true,
            'callback_url' => $dados->callbackUrl,
            'callback_token' => $dados->callbackToken,
        ]);

        // NC-3: o `com_erro` de um processamento anterior volta a pendente, e
        // o job antigo dele passa a `ocr_job_id_cancelar` (RF-12, R2-2). Um
        // processado nunca volta.
        $devolvidos = 0;

        foreach ($this->doConjuntoDoTotal($processoId)->where('ocr_situacao', ProcessoDocumento::OCR_ERRO)->orderBy('id')->get() as $documento) {
            $devolvidos += $this->processamentos->devolverParaFila($documento) ? 1 : 0;
        }

        // O mimetype entra na leitura: o despacho só manda direto ao envio o
        // documento legível (RF-13), e o vazio vai ao download (RF-08).
        $aEnfileirar = $this->doConjuntoDoTotal($processoId)
            ->where(fn (Builder $q) => $q
                ->whereNull('ocr_situacao')
                ->orWhereNotIn('ocr_situacao', [
                    ProcessoDocumento::OCR_ENVIADO,
                    ProcessoDocumento::OCR_PROCESSADO,
                    ProcessoDocumento::OCR_ERRO,
                ]))
            ->orderBy('id')
            ->get(['id', 'status', 'path', 'mimetype']);

        // RF-18 item 3: o aguardando retorno com idade ≥ A só é reenviado
        // depois da consulta do job antigo (D9). Até lá segue `enviado`, com o
        // job conhecido pelo webhook.
        $idadeDeReenvio = (int) config('services.n8n_ocr.idade_maxima_sem_retorno_minutos');

        $aConsultar = $this->doConjuntoDoTotal($processoId)
            ->where('ocr_situacao', ProcessoDocumento::OCR_ENVIADO)
            ->where(fn (Builder $q) => $q
                ->whereNull('ocr_enviado_em')
                ->orWhere('ocr_enviado_em', '<=', now()->subMinutes($idadeDeReenvio)))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $contagem = $this->contagem->contar($processoId);

        Log::info("[ProcessamentoOcr:{$processamento->id}] processamento aberto", [
            'processo_id' => $processoId,
            'assinante_dono_id' => $dono->id,
            'devolvidos_do_erro' => $devolvidos,
            'a_enfileirar' => $aEnfileirar->count(),
            'a_consultar' => $aConsultar->count(),
            'aguardando_retorno' => $contagem->aguardando,
            'total' => $contagem->total,
            'processados' => $contagem->processados,
            'com_erro' => $contagem->comErro,
            'ignorados' => $contagem->ignorados,
        ]);

        return [
            ResultadoSolicitacaoOcr::deContagem($dados, $contagem, ResultadoSolicitacaoOcr::SITUACAO_EM_ANDAMENTO, $processamento->id),
            $aEnfileirar,
            $aConsultar,
        ];
    }

    /**
     * Chamado dentro de uma transação, com o processamento aberto travado
     * (D6): o assinante entra antes de qualquer fim do processamento e
     * recebe o aviso final dele (RF-06). Só o callback é guardado; o par do
     * pedido não vai para nenhuma tabela nem job.
     */
    private function registrarCarona(ProcessoOcrProcessamento $aberto, DadosSolicitacaoOcr $dados): ResultadoSolicitacaoOcr
    {
        $assinante = ProcessoOcrAssinante::firstOrCreate(
            [
                'processamento_id' => $aberto->id,
                'callback_hash' => ProcessoOcrAssinante::hashCallback($dados->callbackUrl, $dados->callbackToken),
            ],
            [
                'api_token_id' => $dados->apiTokenId,
                'dono' => false,
                'callback_url' => $dados->callbackUrl,
                'callback_token' => $dados->callbackToken,
            ],
        );

        $contagem = $this->contagem->contar($aberto->processo_id);

        Log::info("[ProcessamentoOcr:{$aberto->id}] pedido aceito como carona", [
            'assinante_id' => $assinante->id,
            'assinante_novo' => $assinante->wasRecentlyCreated,
            'total' => $contagem->total,
            'processados' => $contagem->processados,
            'com_erro' => $contagem->comErro,
            'ignorados' => $contagem->ignorados,
        ]);

        return ResultadoSolicitacaoOcr::deContagem(
            $dados,
            $contagem,
            ResultadoSolicitacaoOcr::SITUACAO_EM_ANDAMENTO,
            $aberto->id,
            carona: true,
        );
    }

    /**
     * @param  Collection<int, ProcessoDocumento>  $aEnfileirar
     * @param  Collection<int, int>  $aConsultar
     */
    private function despachar(int $processamentoId, Collection $aEnfileirar, Collection $aConsultar, DadosSolicitacaoOcr $dados): void
    {
        $downloads = 0;
        $downloadsSemMimetype = 0;
        $envios = 0;

        foreach ($aEnfileirar as $documento) {
            // D3: a decisão olha só o banco; o envio confere o objeto no S3.
            // O atalho vale só para mimetype legível (RF-13): o vazio, mesmo
            // `baixado` com `path` (o `.dat`), vai ao download, que pede o
            // documento ao tribunal com o par do dono e revela o mimetype
            // (RF-08, RF-09).
            if (
                FormatosOcr::ehLegivel($documento->mimetype)
                && $documento->status === ProcessoDocumento::STATUS_BAIXADO
                && filled($documento->path)
            ) {
                dispatch(new EnviarDocumentoAoOcrJob($processamentoId, $documento->id));
                $envios++;

                continue;
            }

            dispatch(new BaixarDocumentoParaOcrJob($processamentoId, $documento->id, $dados->login, $dados->senha));
            $downloads++;

            if ($documento->mimetype === FormatosOcr::MIMETYPE_NAO_INFORMADO) {
                $downloadsSemMimetype++;
            }
        }

        foreach ($aConsultar as $documentoId) {
            dispatch(new VerificarReenvioOcrJob($processamentoId, $documentoId));
        }

        Log::info("[ProcessamentoOcr:{$processamentoId}] jobs do pedido despachados", [
            'downloads' => $downloads,
            'downloads_sem_mimetype' => $downloadsSemMimetype,
            'envios' => $envios,
            'consultas_antes_do_reenvio' => $aConsultar->count(),
        ]);
    }

    /**
     * Documentos do conjunto do total (RF-07): legível ou mimetype não
     * informado, o mesmo critério de FormatosOcr::entraNoTotal e da contagem
     * (RNF-03). Vale para a devolução do `com_erro`, para "a enfileirar" e
     * para "a consultar": o vazio entra na fila e é revelado pelo download.
     *
     * @return Builder<ProcessoDocumento>
     */
    private function doConjuntoDoTotal(int $processoId): Builder
    {
        return ProcessoDocumento::query()
            ->where('processo_id', $processoId)
            ->where(fn (Builder $q) => $q
                ->whereIn('mimetype', FormatosOcr::MIMETYPES_LEGIVEIS)
                ->orWhere('mimetype', FormatosOcr::MIMETYPE_NAO_INFORMADO));
    }

    private function processoGravado(int $tribunalId, string $numeroProcesso): ?int
    {
        // toBase(): sem as relações que o model Processo carrega sempre.
        $id = Processo::query()
            ->toBase()
            ->where('tribunal_id', $tribunalId)
            ->where('numero_processo', $numeroProcesso)
            ->orderBy('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }
}
