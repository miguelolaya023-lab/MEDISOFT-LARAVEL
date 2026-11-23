<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atenciones_medicas', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('cita_id')->unique()->constrained('citas')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('medico_id')->index()->constrained('medicos')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('historia_clinica_id')->index()->constrained('historias_clinicas')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('fecha_hora_inicio');
            $table->string('estado', 20)->default('EN_CURSO');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('atenciones_medicas')->exists()) {
            throw new RuntimeException('La reversión de RF-028 requiere conservar las atenciones iniciadas y su estado en agenda.');
        }

        Schema::dropIfExists('atenciones_medicas');
    }
};
