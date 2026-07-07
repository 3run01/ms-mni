<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processos', function (Blueprint $table) {
            if (!Schema::hasColumn('processos', 'ocr_status')) {
                $table->string('ocr_status', 32)->nullable()->after('knowledge_base_status_sync');
                $table->index('ocr_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('processos', function (Blueprint $table) {
            if (Schema::hasColumn('processos', 'ocr_status')) {
                $table->dropIndex(['ocr_status']);
                $table->dropColumn('ocr_status');
            }
        });
    }
};
