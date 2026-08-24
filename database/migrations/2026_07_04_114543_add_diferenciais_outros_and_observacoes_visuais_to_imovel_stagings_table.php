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
            $table->json('diferenciais_outros')->nullable()->after('diferenciais');
            $table->json('observacoes_visuais')->nullable()->after('diferenciais_outros');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imovel_stagings', function (Blueprint $table) {
            $table->dropColumn(['diferenciais_outros', 'observacoes_visuais']);
        });
    }
};
