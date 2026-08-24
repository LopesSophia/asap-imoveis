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
            // nullOnDelete (não cascadeOnDelete) nas duas FKs: remover UMA foto
            // nunca deve apagar o cadastro do imóvel — só limpa a referência
            // correspondente, que fica null até uma nova sugestão/escolha.
            //
            // Dois conceitos deliberadamente separados:
            // - foto_capa_sugerida_id/foto_capa_motivo: recomendação da IA,
            //   sempre atualizada a cada finalizar() quando houver candidata válida.
            // - foto_capa_id: a foto efetivamente ativa como capa — só é definida
            //   automaticamente a partir da sugestão na primeira vez (quando ainda
            //   null); depois disso só muda por escolha manual do corretor.
            $table->foreignId('foto_capa_sugerida_id')
                ->nullable()
                ->after('alertas_fotos')
                ->constrained('imovel_staging_fotos')
                ->nullOnDelete();

            $table->string('foto_capa_motivo')->nullable()->after('foto_capa_sugerida_id');

            $table->foreignId('foto_capa_id')
                ->nullable()
                ->after('foto_capa_motivo')
                ->constrained('imovel_staging_fotos')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imovel_stagings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('foto_capa_sugerida_id');
            $table->dropColumn('foto_capa_motivo');
            $table->dropConstrainedForeignId('foto_capa_id');
        });
    }
};
