<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacientes', function (Blueprint $table): void {
            $table->id();
            $table->string('tipo_documento', 20);
            $table->string('numero_documento', 30);
            $table->string('nombres', 100);
            $table->string('apellidos', 100);
            $table->date('fecha_nacimiento');
            $table->string('sexo', 50);
            $table->string('telefono', 30);
            $table->string('correo_electronico')->nullable();
            $table->string('direccion');
            $table->string('ciudad', 100)->index();
            $table->enum('tipo_paciente', ['PARTICULAR', 'EPS'])->index();
            $table->enum('estado', ['ACTIVO', 'INACTIVO'])->default('ACTIVO')->index();
            $table->timestamps();
            $table->unique(['tipo_documento', 'numero_documento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pacientes');
    }
};
