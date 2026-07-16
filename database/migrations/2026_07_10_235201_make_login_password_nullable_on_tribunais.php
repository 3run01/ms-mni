<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->simTribunaisExiste()) {
            return;
        }

        DB::connection('sim')->statement('ALTER TABLE tribunais ALTER COLUMN login DROP NOT NULL');
        DB::connection('sim')->statement('ALTER TABLE tribunais ALTER COLUMN password DROP NOT NULL');
    }

    public function down(): void
    {
        if (! $this->simTribunaisExiste()) {
            return;
        }

        DB::connection('sim')->statement('ALTER TABLE tribunais ALTER COLUMN login SET NOT NULL');
        DB::connection('sim')->statement('ALTER TABLE tribunais ALTER COLUMN password SET NOT NULL');
    }

    private function simTribunaisExiste(): bool
    {
        try {
            return in_array('sim', array_keys(config('database.connections')), true)
                && Schema::connection('sim')->hasTable('tribunais');
        } catch (\Throwable $e) {
            return false;
        }
    }
};
