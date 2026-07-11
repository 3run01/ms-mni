<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('sim')->statement('ALTER TABLE tribunais ALTER COLUMN login DROP NOT NULL');
        DB::connection('sim')->statement('ALTER TABLE tribunais ALTER COLUMN password DROP NOT NULL');
    }

    public function down(): void
    {
        DB::connection('sim')->statement('ALTER TABLE tribunais ALTER COLUMN login SET NOT NULL');
        DB::connection('sim')->statement('ALTER TABLE tribunais ALTER COLUMN password SET NOT NULL');
    }
};
