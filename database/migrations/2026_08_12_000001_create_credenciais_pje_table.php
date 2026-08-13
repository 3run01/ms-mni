<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credenciais_pje', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('api_token_id')->constrained('api_tokens');
            $table->foreignId('tribunal_id')->constrained('tribunais');
            $table->text('login');
            $table->text('senha');
            $table->char('login_hash', 64);
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['api_token_id', 'tribunal_id', 'login_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credenciais_pje');
    }
};
