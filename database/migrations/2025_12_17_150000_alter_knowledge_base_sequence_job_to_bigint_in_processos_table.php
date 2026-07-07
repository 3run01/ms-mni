<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Altera o tipo do campo knowledge_base_sequence_job de VARCHAR para BIGINT
        DB::statement('
            ALTER TABLE processos
            ALTER COLUMN knowledge_base_sequence_job TYPE BIGINT USING knowledge_base_sequence_job::BIGINT
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverte para VARCHAR caso necessário
        DB::statement('
            ALTER TABLE processos
            ALTER COLUMN knowledge_base_sequence_job TYPE VARCHAR(255) USING knowledge_base_sequence_job::VARCHAR
        ');
    }
};
