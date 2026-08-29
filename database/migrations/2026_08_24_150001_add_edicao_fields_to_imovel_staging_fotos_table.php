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
        Schema::table('imovel_staging_fotos', function (Blueprint $table) {
            // Sugestão da IA de análise (AnaliseFotosService), por foto — itens
            // temporários (pessoas, animais, objetos) potencialmente removíveis.
            // Puramente informativo: o corretor escolhe explicitamente o que
            // quer remover no momento de gerar uma edição, nunca é aplicado
            // automaticamente a partir daqui.
            $table->json('itens_removiveis_sugeridos')->nullable()->after('ordem');

            // Ponteiro para qual tentativa de edição (imovel_staging_foto_edicoes)
            // está ativa agora — mesmo idioma de imovel_stagings.foto_capa_id:
            // o "pai" aponta pra entidade ativa, nunca uma flag espalhada nos
            // filhos. null = foto exibe o arquivo original (caminho).
            // nullOnDelete (não cascadeOnDelete): não há endpoint de exclusão
            // de uma linha de edição hoje, mas se um dia existir, a foto deve
            // voltar a expor o original em vez de quebrar a FK.
            $table->foreignId('edicao_ativa_id')
                ->nullable()
                ->after('itens_removiveis_sugeridos')
                ->constrained('imovel_staging_foto_edicoes')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imovel_staging_fotos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('edicao_ativa_id');
            $table->dropColumn('itens_removiveis_sugeridos');
        });
    }
};
