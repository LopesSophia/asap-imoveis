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
        // Contador de chamadas ao Gemini por mês calendário ("YYYY-MM"),
        // usado como trava atômica de custo — ver EdicaoFotoCotaService.
        // Uma linha por mês; travar (lockForUpdate) esta linha é o que
        // serializa TODA reserva de cota do sistema, tornando também as
        // contagens por-foto/por-imóvel (baseadas em contar linhas de
        // imovel_staging_foto_edicoes) livres de corrida.
        Schema::create('gemini_uso_mensal', function (Blueprint $table) {
            $table->id();
            $table->string('ano_mes')->unique();
            $table->unsignedInteger('quantidade')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gemini_uso_mensal');
    }
};
