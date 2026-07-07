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
        Schema::table('processos', function (Blueprint $table) {
            $table->string('knowledge_base_status_sync')->default('PENDING');
            $table->string('knowledge_base_sequence_job')->nullable();
            // $table->string('knowledge_base_ingestion_job_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processos', function (Blueprint $table) {
            $table->dropColumn('knowledge_base_status_sync');
            $table->dropColumn('knowledge_base_sequence_job');
            // $table->dropColumn('knowledge_base_ingestion_job_id');
        });
    }
};
