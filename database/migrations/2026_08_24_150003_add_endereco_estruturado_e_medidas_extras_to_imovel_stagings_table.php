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
        Schema::table('imovel_stagings', function (Blueprint $table) {
            // Endereço completo do imóvel (tela inicial, separado do relato
            // falado/digitado) — logradouro/numero/bairro/cidade/cep/
            // complemento já existiam; faltavam estado (UF) e a marcação
            // explícita de "sem número" (terrenos/esquinas sem numeração).
            $table->string('estado', 2)->nullable()->after('cep');
            $table->boolean('sem_numero')->default(false)->after('numero');

            // "Medidas e características" da entrega ao Prontos: metragem
            // já existente passa a representar especificamente a área ÚTIL;
            // área total e salas são campos novos, sempre opcionais.
            $table->decimal('area_total', 10, 2)->nullable()->after('metragem');
            $table->unsignedInteger('salas')->nullable()->after('banheiros');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imovel_stagings', function (Blueprint $table) {
            $table->dropColumn(['estado', 'sem_numero', 'area_total', 'salas']);
        });
    }
};
