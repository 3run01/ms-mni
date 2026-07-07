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
        Schema::create('processo_prioridades', function (Blueprint $table) {
            $table->id();
            $table->integer('processo_id');
            $table->string('descricao');
            $table->foreign('processo_id')->references('id')->on('processos')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processo_prioridades');
    }
};
