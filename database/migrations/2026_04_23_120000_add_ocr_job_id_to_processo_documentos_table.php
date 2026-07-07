<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->string('ocr_job_id', 64)->nullable()->after('ocr_enviado_fila');
            $table->index('ocr_job_id');
        });
    }

    public function down(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            $table->dropIndex(['ocr_job_id']);
            $table->dropColumn('ocr_job_id');
        });
    }
};
