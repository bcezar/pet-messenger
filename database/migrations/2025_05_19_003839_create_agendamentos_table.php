<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agendamentos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('client_phone');

            $table->string('nome_pet');
            $table->string('raca_pet')->nullable();
            $table->string('porte_pet')->nullable();
            $table->string('data_banho')->nullable();

            $table->boolean('primeira_vez')->default(false);

            $table->timestamps();

            $table->unique(['company_id', 'client_phone', 'data_banho']);
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('agendamentos');
    }
};
