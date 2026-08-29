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
        Schema::create('imovel_staging_foto_edicoes', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete (não nullOnDelete): uma tentativa de edição não
            // existe sem a foto lógica dona dela — some junto se a foto for
            // removida. Diferente das FKs "ponteiro de ativa" (que usam
            // nullOnDelete), esta é a direção "filho pertence ao pai".
            $table->foreignId('imovel_staging_foto_id')->constrained('imovel_staging_fotos')->cascadeOnDelete();

            // Sem Sanctum ativo ainda no projeto, preenchido com Auth::id()
            // quando existir — hoje sempre null (mesmo TODO já registrado em
            // StoreImovelStagingRequest para corretor_id).
            $table->foreignId('solicitado_por_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->json('itens_solicitados');
            $table->text('prompt_enviado');
            $table->string('provider');
            $table->string('modelo');

            // pendente|processando|gerada|aprovada|rejeitada|erro — ver
            // EdicaoFotoGeminiService/GerarEdicaoFotoJob para a máquina de
            // estados completa. String simples (não enum do banco) para não
            // exigir migration nova a cada novo estado.
            $table->string('status')->default('pendente');

            $table->string('caminho_arquivo_editado')->nullable();
            $table->text('mensagem_erro')->nullable();
            $table->timestamp('iniciada_em')->nullable();
            $table->timestamp('concluida_em')->nullable();

            $table->foreignId('decidido_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decidida_em')->nullable();

            $table->timestamps();

            $table->index(['imovel_staging_foto_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imovel_staging_foto_edicoes');
    }
};
