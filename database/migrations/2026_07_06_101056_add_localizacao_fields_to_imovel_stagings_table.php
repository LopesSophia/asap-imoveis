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
            $table->string('logradouro')->nullable()->after('bairro');
            $table->string('numero')->nullable()->after('logradouro');
            $table->string('cep')->nullable()->after('cidade');
            $table->string('complemento')->nullable()->after('cep');

            $table->json('localizacao')->nullable()->after('observacoes_visuais');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imovel_stagings', function (Blueprint $table) {
            $table->dropColumn(['logradouro', 'numero', 'cep', 'complemento', 'localizacao']);
        });
    }
};
