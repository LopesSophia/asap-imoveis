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
            $table->enum('status_propagacao', ['rascunho', 'pendente', 'propagado', 'manual'])
                ->default('rascunho')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imovel_stagings', function (Blueprint $table) {
            $table->enum('status_propagacao', ['pendente', 'propagado', 'manual'])
                ->default('pendente')
                ->change();
        });
    }
};
