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
            $table->enum('estado_conservacao', ['novo', 'reformado', 'usado', 'a_reformar'])
                ->nullable()
                ->after('reformado');

            $table->enum('vagas_cobertura', ['coberta', 'descoberta', 'mista'])
                ->nullable()
                ->after('vagas');

            $table->string('nome_edificio')->nullable()->after('mobiliado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imovel_stagings', function (Blueprint $table) {
            $table->dropColumn(['estado_conservacao', 'vagas_cobertura', 'nome_edificio']);
        });
    }
};
