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
            // null = análise de fotos nunca rodou, ou rodou mas ficou inválida
            // (upload/remoção de foto depois dela). finalizar() exige isto
            // preenchido; analisar-fotos() não re-chama a IA se já preenchido.
            $table->timestamp('fotos_analisadas_em')->nullable()->after('alertas_fotos');

            // Separação de origem: diferenciais/diferenciais_outros (já
            // existentes) são fala/digitação/revisão humana — a análise de
            // fotos NUNCA escreve neles diretamente. Estes dois aqui são
            // exclusivamente o que a IA detectou nas imagens, e são
            // SUBSTITUÍDOS por inteiro a cada nova análise (nunca mesclados
            // com o resultado de uma análise anterior, que pode já estar
            // obsoleto se fotos foram trocadas).
            $table->json('diferenciais_fotos')->default('[]')->after('fotos_analisadas_em');
            $table->json('diferenciais_outros_fotos')->default('[]')->after('diferenciais_fotos');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imovel_stagings', function (Blueprint $table) {
            $table->dropColumn(['fotos_analisadas_em', 'diferenciais_fotos', 'diferenciais_outros_fotos']);
        });
    }
};
