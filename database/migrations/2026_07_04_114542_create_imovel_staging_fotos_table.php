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
        Schema::create('imovel_staging_fotos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('imovel_staging_id')->constrained('imovel_stagings')->cascadeOnDelete();

            $table->string('caminho');
            $table->unsignedInteger('ordem');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imovel_staging_fotos');
    }
};
