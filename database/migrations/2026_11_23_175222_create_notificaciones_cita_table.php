<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones_cita', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cita_id')->constrained('citas')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('contacto_destino')->nullable();
            $table->text('asunto');
            $table->text('mensaje');
            $table->enum('canal', ['SIMULADO']);
            $table->enum('estado', ['SIMULADA', 'NO_ENVIADA']);
            $table->timestamp('fecha_intento');
            $table->string('motivo_fallo', 255)->nullable();
            $table->uuid('clave_confirmacion')->unique();
            $table->index(['cita_id', 'fecha_intento', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones_cita');
    }
};
