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
        Schema::table('users', function (Blueprint $table) {
            $table->string('tipo_documento', 20)->nullable();
            $table->string('numero_documento', 30)->nullable()->unique();
            $table->string('nombres', 100)->nullable();
            $table->string('apellidos', 100)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('cargo', 100)->nullable();
            $table->string('estado', 20)->default('activo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['numero_documento']);
            $table->dropColumn([
                'tipo_documento',
                'numero_documento',
                'nombres',
                'apellidos',
                'telefono',
                'cargo',
                'estado',
            ]);
        });
    }
};
