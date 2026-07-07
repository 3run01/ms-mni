<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            if (!Schema::hasColumn('processo_documentos', 'ocr_job_id')) {
                $table->string('ocr_job_id')->nullable()->after('ocr_concluido_data');
                $table->index('ocr_job_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processo_documentos', function (Blueprint $table) {
            if (Schema::hasColumn('processo_documentos', 'ocr_job_id')) {
                $table->dropIndex(['ocr_job_id']);
                $table->dropColumn('ocr_job_id');
            }
        });
    }
};
