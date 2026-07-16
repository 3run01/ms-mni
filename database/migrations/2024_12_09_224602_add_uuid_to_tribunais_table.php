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
        if (! Schema::hasTable('tribunais') || Schema::hasColumn('tribunais', 'uuid')) {
            return;
        }

        Schema::table('tribunais', function (Blueprint $table) {
            $table->uuid('uuid')->unique()->after('id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('tribunais') && Schema::hasColumn('tribunais', 'uuid')) {
            Schema::table('tribunais', function (Blueprint $table) {
                $table->dropColumn('uuid');
            });
        }
    }
};
