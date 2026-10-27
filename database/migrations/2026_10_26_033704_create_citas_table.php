<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citas', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('paciente_id')->constrained('pacientes')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('medico_id')->constrained('medicos')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('disponibilidad_medica_id')->constrained('disponibilidades_medicas')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->string('modalidad', 20);
            $table->string('tipo_paciente_al_agendar', 20);
            $table->string('motivo_consulta', 2000);
            /** Límite técnico: diez enteros y dos decimales COP; no es una tarifa. */
            $table->decimal('valor_base', 12, 2);
            $table->string('estado', 20);
            /** Canceladas históricas pueden compartir inicio; el mutex evita solapes parciales. */
            $table->time('inicio_ocupado')->nullable()->storedAs("CASE WHEN estado <> 'CANCELADA' THEN hora_inicio ELSE NULL END");
            $table->unique(['medico_id', 'fecha', 'inicio_ocupado'], 'citas_medico_fecha_inicio_ocupado_unique');
            $table->index(['medico_id', 'fecha', 'hora_inicio']);
            $table->index(['paciente_id', 'fecha']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citas');
    }
};
