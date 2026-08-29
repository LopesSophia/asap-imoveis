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
            // Acompanhamento da geração ASSÍNCRONA da descrição (o título
            // continua síncrono/determinístico, sem IA — nunca precisou
            // disto). "pendente" = reservado, job ainda não rodou;
            // "processando" = job rodando; "concluida" = descricao_gerada
            // preenchida (pela IA OU porque o corretor editou manualmente
            // enquanto o job rodava); "erro" = esgotou tentativas ou falha
            // definitiva (401/403/config). Nullable: um staging que nunca
            // tentou gerar descrição não tem status nenhum.
            $table->string('descricao_geracao_status')->nullable()->after('descricao_gerada');

            // Mensagem SANITIZADA (nunca o erro técnico bruto) — detalhe
            // completo só no log.
            $table->text('descricao_geracao_erro')->nullable()->after('descricao_geracao_status');

            $table->timestamp('descricao_geracao_iniciada_em')->nullable()->after('descricao_geracao_erro');
            $table->timestamp('descricao_gerada_em')->nullable()->after('descricao_geracao_iniciada_em');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imovel_stagings', function (Blueprint $table) {
            $table->dropColumn([
                'descricao_geracao_status',
                'descricao_geracao_erro',
                'descricao_geracao_iniciada_em',
                'descricao_gerada_em',
            ]);
        });
    }
};
