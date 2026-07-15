<?php

use App\Models\Tribunal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $model = new Tribunal();
        $conexao = $model->getConnectionName();

        try {
            if (! Schema::connection($conexao)->hasTable($model->getTable())
                || ! Schema::connection($conexao)->hasColumn($model->getTable(), 'uuid')) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        Tribunal::whereNull('uuid')->each(function ($tribunal) {
            $tribunal->update(['uuid' => Str::uuid()]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
