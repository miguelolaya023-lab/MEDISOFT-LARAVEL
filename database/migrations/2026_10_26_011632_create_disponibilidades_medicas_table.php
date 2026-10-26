<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disponibilidades_medicas', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('medico_id')->constrained('medicos')->restrictOnDelete()->restrictOnUpdate();
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->unsignedSmallInteger('duracion_minutos');
            $table->timestamps();
            $table->unique(['medico_id', 'fecha', 'hora_inicio'], 'disponibilidad_medico_fecha_inicio_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disponibilidades_medicas');
    }
};
