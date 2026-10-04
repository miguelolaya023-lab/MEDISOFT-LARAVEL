<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });
        Schema::create('permisos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('descripcion');
            $table->timestamps();
        });
        Schema::create('permiso_rol', function (Blueprint $table) {
            $table->foreignId('rol_id')->constrained('roles')->restrictOnDelete();
            $table->foreignId('permiso_id')->constrained('permisos')->restrictOnDelete();
            $table->primary(['rol_id', 'permiso_id']);
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('rol_id')->nullable()->constrained('roles')->restrictOnDelete();
            $table->string('motivo_inactivacion', 500)->nullable();
            $table->timestamp('ultimo_acceso')->nullable();
        });
        Schema::create('medicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('registro_profesional', 100);
            $table->string('especialidad', 100);
            $table->timestamps();
        });
        Schema::create('auditorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('accion', 50)->index();
            $table->string('entidad', 100);
            $table->unsignedBigInteger('registro_id');
            $table->timestamp('fecha_hora')->index();
            $table->string('resultado', 30);
            $table->string('descripcion', 500);
            $table->timestamps();
            $table->index(['entidad', 'registro_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditorias');
        Schema::dropIfExists('medicos');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rol_id');
            $table->dropColumn(['motivo_inactivacion', 'ultimo_acceso']);
        });
        Schema::dropIfExists('permiso_rol');
        Schema::dropIfExists('permisos');
        Schema::dropIfExists('roles');
    }
};
