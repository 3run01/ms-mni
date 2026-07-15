<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->simDisponivel()) {
            return; // CI / env limpos: no-op
        }

        DB::connection()->transaction(function () {
            foreach (DB::connection('sim_migracao')->table('tribunais')->cursor() as $row) {
                $dados = (array) $row;
                $id = $dados['id'];
                unset($dados['id']);
                if (empty($dados['uuid'] ?? null)) {
                    $dados['uuid'] = (string) Str::uuid();
                }
                DB::connection()->table('tribunais')->updateOrInsert(['id' => $id], $dados);
            }

            foreach (DB::connection('sim_migracao')->table('tipos_documentos')->cursor() as $row) {
                $dados = (array) $row;
                $id = $dados['id'];
                unset($dados['id']);
                DB::connection()->table('tipos_documentos')->updateOrInsert(['id' => $id], $dados);
            }

            $this->corrigirSequence('tribunais');
            $this->corrigirSequence('tipos_documentos');
        });
    }

    public function down(): void
    {
        DB::connection()->table('tipos_documentos')->truncate();
        DB::connection()->table('tribunais')->truncate();
    }

    private function simDisponivel(): bool
    {
        try {
            if (! env('DB_SIM_HOST')) {
                return false;
            }
            config(['database.connections.sim_migracao' => [
                'driver' => env('DB_SIM_CONNECTION', 'pgsql'),
                'host' => env('DB_SIM_HOST'),
                'port' => env('DB_SIM_PORT', '5432'),
                'database' => env('DB_SIM_DATABASE'),
                'username' => env('DB_SIM_USERNAME'),
                'password' => env('DB_SIM_PASSWORD'),
            ]]);

            return Schema::connection('sim_migracao')->hasTable('tribunais');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function corrigirSequence(string $tabela): void
    {
        $max = DB::connection()->table($tabela)->max('id');
        if ($max) {
            DB::connection()->statement(
                "SELECT setval(pg_get_serial_sequence(?, 'id'), ?)",
                [$tabela, $max]
            );
        }
    }
};
