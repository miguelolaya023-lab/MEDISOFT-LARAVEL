<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('antecedentes_clinicos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historia_clinica_id')->constrained('historias_clinicas')->restrictOnDelete();
            $table->foreignId('medico_id')->constrained('medicos')->restrictOnDelete();
            $table->string('tipo', 100)->nullable();
            $table->text('descripcion');
            $table->timestamp('fecha_registro');
            $table->timestamps();
        });
        Schema::create('antecedente_clinico_revisiones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('antecedente_clinico_id')->constrained('antecedentes_clinicos')->restrictOnDelete();
            $table->foreignId('medico_id')->constrained('medicos')->restrictOnDelete();
            $table->string('tipo', 100)->nullable();
            $table->text('descripcion');
            $table->timestamp('fecha_correccion');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('antecedente_clinico_revisiones');
        Schema::dropIfExists('antecedentes_clinicos');
    }
};
