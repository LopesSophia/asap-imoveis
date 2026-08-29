<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imovel_stagings', function (Blueprint $table) {
            $table->date('data_confirmacao_proprietario')->nullable()->after('nome_edificio');

            $table->enum('condominio_situacao', ['valor_informado', 'isento', 'sob_consulta'])
                ->nullable()
                ->after('data_confirmacao_proprietario');

            $table->enum('iptu_situacao', ['valor_informado', 'isento', 'sob_consulta'])
                ->nullable()
                ->after('condominio_situacao');

            $table->enum('iptu_periodicidade', ['mensal', 'anual'])->nullable()->after('iptu_situacao');

            $table->string('outros_encargos')->nullable()->after('iptu_periodicidade');
            $table->string('disponibilidade_visita')->nullable()->after('outros_encargos');
            $table->string('previsao_entrega')->nullable()->after('disponibilidade_visita');

            // Motor de validação de qualidade (ValidadorQualidadeAnuncioService)
            // — calculado sob demanda, nunca a cada request; nullable porque
            // um cadastro nunca validado ainda não tem nem pontuação nem data.
            $table->unsignedTinyInteger('pontuacao_qualidade')->nullable()->after('previsao_entrega');
            $table->timestamp('data_ultima_validacao')->nullable()->after('pontuacao_qualidade');

            // Alertas/sugestões que o corretor já reconheceu e decidiu
            // ignorar — filtrados do resultado de validações seguintes até
            // o dado relacionado mudar (nunca reescrito automaticamente
            // pelo motor, só pelo endpoint dedicado de confirmação).
            $table->json('pendencias_confirmadas')->nullable()->default('[]')->after('data_ultima_validacao');
        });
    }

    public function down(): void
    {
        Schema::table('imovel_stagings', function (Blueprint $table) {
            $table->dropColumn([
                'data_confirmacao_proprietario',
                'condominio_situacao',
                'iptu_situacao',
                'iptu_periodicidade',
                'outros_encargos',
                'disponibilidade_visita',
                'previsao_entrega',
                'pontuacao_qualidade',
                'data_ultima_validacao',
                'pendencias_confirmadas',
            ]);
        });
    }
};
