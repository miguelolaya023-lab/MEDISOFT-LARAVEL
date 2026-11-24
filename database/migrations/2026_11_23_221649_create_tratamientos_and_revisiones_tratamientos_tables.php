<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tratamientos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('atencion_medica_id')->constrained('atenciones_medicas')->restrictOnDelete();
            $table->text('descripcion');
            $table->timestamp('fecha_registro');
            $table->uuid('clave_envio');
            $table->char('huella_envio', 64);
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['atencion_medica_id', 'clave_envio']);
        });

        Schema::create('revisiones_tratamientos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tratamiento_id')->constrained('tratamientos')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->text('descripcion_anterior');
            $table->timestamp('fecha_correccion');
            $table->unique(['tratamiento_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisiones_tratamientos');
        Schema::dropIfExists('tratamientos');
    }
};
