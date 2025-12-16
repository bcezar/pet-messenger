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

            // 🔑 multi-tenant
            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            // 📞 cliente
            $table->string('client_phone');

            // 🐶 dados do pet
            $table->string('nome_pet');
            $table->string('raca_pet')->nullable();
            $table->string('porte_pet')->nullable();

            // 📅 agendamento
            $table->dateTime('data_banho')->nullable();

            // ℹ️ metadata
            $table->boolean('primeira_vez')->default(false);

            $table->timestamps();

            // 🔒 evita duplicidade acidental
            $table->index(['company_id', 'data_banho']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agendamentos');
    }
};
