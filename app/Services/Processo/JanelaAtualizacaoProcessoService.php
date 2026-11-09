<?php

namespace App\Services\Processo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Janela de atualização do processo em segundo plano (DEP-08; RF-15, RNF-04).
 *
 * Enquanto a janela de um (tribunal_id, numero_processo) está aberta,
 * GET /processo/visualizar não enfileira de novo o BaixarProcessoMNIJob.
 * Cada linha (tribunal_id) tem a sua janela, e as chamadas suprimidas não a
 * estendem.
 *
 * Conferir e abrir é uma operação só, `Cache::add` ("gravar se ausente"):
 * atômica no `database` (insertOrIgnore na chave primária), no `redis` e no
 * `array` dos testes. Uma leitura seguida de escrita deixaria 2 workers do
 * Octane ou do Horizon verem a janela fechada ao mesmo tempo e enfileirarem
 * 2 jobs.
 *
 * Não é `ShouldBeUnique` no job: o Laravel solta a trava única quando o job
 * termina (vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php:91-93),
 * então ela não segura uma janela de tempo. E ela valeria também para
 * /processo/consultar e para o expediente, que continuam com 1 job por
 * chamada (decisão Q-02).
 *
 * Sem estado e sem o par do PJe (RNF-01): o valor guardado é `true`, a
 * validade é lida da configuração a cada chamada (o objeto vive entre
 * requisições no Octane), e o log leva só a linha, o número e a classe da
 * exceção.
 */
class JanelaAtualizacaoProcessoService
{
    /**
     * Chave da janela. O número já vem normalizado por quem chama.
     */
    public static function chave(int $tribunalId, string $numeroProcesso): string
    {
        return "baixar-processo:{$tribunalId}:{$numeroProcesso}";
    }

    /**
     * Abre a janela se ela não estiver aberta.
     *
     * Devolve `true` só quando esta chamada abriu a janela: é o sinal para
     * despachar o job. Com a janela já aberta, ou com o cache fora do ar,
     * devolve `false` e quem chama responde sem despachar.
     */
    public function abrir(int $tribunalId, string $numeroProcesso): bool
    {
        $segundos = max(1, (int) config('pje.atualizacao_processo.janela_segundos'));

        try {
            return Cache::add(self::chave($tribunalId, $numeroProcesso), true, $segundos) === true;
        } catch (Throwable $e) {
            // Sem a mensagem da exceção: o texto cru do driver não vai ao log.
            Log::warning('[JanelaAtualizacao] Falha ao abrir a janela de atualização no cache; o job não foi despachado.', [
                'tribunal_id' => $tribunalId,
                'numero_processo' => $numeroProcesso,
                'erro' => class_basename($e),
            ]);

            return false;
        }
    }
}
