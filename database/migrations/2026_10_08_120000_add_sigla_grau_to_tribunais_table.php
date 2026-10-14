<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * DEP-02: `sigla` (formato derivado do número CNJ) e `grau` em cada linha de
 * tribunal. Mais de uma linha ativa por sigla é esperado, como o PJe de 1º e
 * 2º grau do mesmo tribunal; o grau distingue as linhas e o cliente escolhe.
 *
 * O preenchimento deriva os dois campos do `nome` só onde a sigla ainda está
 * vazia, então rodar de novo não desfaz correção feita pelo admin. Linha cujo
 * nome não começa por uma sigla válida fica sem sigla e vai para o log.
 */
return new class extends Migration
{
    // TJM antes de TJ e o lookahead final separam TJMSP (militar) de TJMS e TRT2 de TRT24
    private const SIGLA_NO_INICIO_DO_NOME = '/^(STF|STJ|STM|TST|TSE|TJDFT|TRF[1-6]|TRT(?:2[0-4]|1[0-9]|[1-9])|TRE-[A-Z]{2}|TJM[A-Z]{2}|TJ[A-Z]{2})(?![A-Z0-9])/';

    private const GRAU_NO_NOME = '/\b([12])\s*[ºo°]\s*grau\b/iu';

    // valor gravado por engano em tribunais de justiça estaduais (ex.: TJAP)
    private const TIPO_STJ = 'Superior Tribunal de Justiça (STJ)';

    private const TIPO_TJ = 'Tribunal de Justiça';

    public function up(): void
    {
        if (! Schema::hasTable('tribunais')) {
            return;
        }

        Schema::table('tribunais', function (Blueprint $table) {
            if (! Schema::hasColumn('tribunais', 'sigla')) {
                $table->string('sigla', 10)->nullable();
            }

            if (! Schema::hasColumn('tribunais', 'grau')) {
                $table->string('grau', 1)->nullable();
            }
        });

        DB::table('tribunais')
            ->whereNull('sigla')
            ->orderBy('id')
            ->get(['id', 'nome', 'tipo'])
            ->each(function (object $tribunal) {
                if (! preg_match(self::SIGLA_NO_INICIO_DO_NOME, mb_strtoupper(trim((string) $tribunal->nome)), $sigla)) {
                    Log::warning('tribunais: sigla não derivável do nome, preencha pelo admin', [
                        'id' => $tribunal->id,
                        'nome' => $tribunal->nome,
                    ]);

                    return;
                }

                $dados = [
                    'sigla' => $sigla[1],
                    'grau' => preg_match(self::GRAU_NO_NOME, (string) $tribunal->nome, $grau) ? $grau[1] : null,
                ];

                $tribunalDeJustica = $sigla[1] === 'TJDFT' || preg_match('/^TJ[A-Z]{2}$/', $sigla[1]);

                if ($tribunalDeJustica && $tribunal->tipo === self::TIPO_STJ) {
                    $dados['tipo'] = self::TIPO_TJ;
                }

                DB::table('tribunais')->where('id', $tribunal->id)->update($dados);
            });
    }

    /**
     * Remove as colunas. A correção de `tipo` não é desfeita: voltar para STJ
     * reintroduziria o dado errado.
     */
    public function down(): void
    {
        if (! Schema::hasTable('tribunais')) {
            return;
        }

        Schema::table('tribunais', function (Blueprint $table) {
            foreach (['grau', 'sigla'] as $coluna) {
                if (Schema::hasColumn('tribunais', $coluna)) {
                    $table->dropColumn($coluna);
                }
            }
        });
    }
};
