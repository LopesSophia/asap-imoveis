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
        Schema::create('imovel_stagings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('corretor_id')->constrained('users')->cascadeOnDelete();

            $table->enum('tipo_imovel', ['apartamento', 'casa', 'terreno', 'comercial', 'cobertura']);
            $table->enum('negociacao', ['venda', 'locacao', 'venda_e_locacao'])->nullable();
            $table->enum('utilizacao', ['residencial', 'comercial'])->nullable();

            $table->string('bairro')->nullable();
            $table->string('cidade')->nullable();
            $table->decimal('metragem', 10, 2)->nullable();

            $table->unsignedInteger('quartos')->nullable();
            $table->unsignedInteger('suites')->nullable();
            $table->unsignedInteger('banheiros')->nullable();
            $table->unsignedInteger('vagas')->nullable();

            $table->decimal('valor', 12, 2)->nullable();
            $table->decimal('condominio', 12, 2)->nullable();
            $table->decimal('iptu', 12, 2)->nullable();

            $table->unsignedInteger('andar')->nullable();
            $table->unsignedInteger('ano_construcao')->nullable();

            $table->boolean('em_condominio')->nullable();
            $table->boolean('reformado')->nullable();
            $table->boolean('mobiliado')->nullable();

            $table->string('chaves')->nullable();

            $table->json('diferenciais')->nullable();

            $table->string('titulo_site')->nullable();
            $table->text('descricao_gerada')->nullable();
            $table->text('observacoes_corretor')->nullable();

            $table->enum('status_propagacao', ['pendente', 'propagado', 'manual'])->default('pendente');

            $table->timestamp('criado_em')->useCurrent();
            $table->timestamps();

            $table->index('status_propagacao');
            $table->index('corretor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imovel_stagings');
    }
};
