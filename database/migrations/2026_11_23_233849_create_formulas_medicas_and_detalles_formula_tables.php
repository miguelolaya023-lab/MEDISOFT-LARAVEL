<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulas_medicas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('atencion_medica_id')->constrained('atenciones_medicas')->restrictOnDelete();
            $table->timestamp('fecha_emision');
            $table->text('observacion')->nullable();
            $table->uuid('clave_envio');
            $table->char('huella_envio', 64);
            $table->timestamps();
            $table->unique(['atencion_medica_id', 'clave_envio']);
        });

        Schema::create('detalles_formula', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('formula_medica_id')->constrained('formulas_medicas')->restrictOnDelete();
            $table->unsignedSmallInteger('posicion');
            $table->string('medicamento');
            $table->string('dosis');
            $table->string('frecuencia')->nullable();
            $table->string('duracion')->nullable();
            $table->text('indicaciones')->nullable();
            $table->unique(['formula_medica_id', 'posicion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalles_formula');
        Schema::dropIfExists('formulas_medicas');
    }
};
