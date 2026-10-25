<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $mysql = Schema::getConnection()->getDriverName() === 'mysql';
        Schema::create('eps', function (Blueprint $table) use ($mysql): void {
            $table->engine('InnoDB');
            $table->id();
            $nit = $table->string('nit', 50);
            if ($mysql) {
                $nit->collation('utf8mb4_bin');
            }
            $table->unique('nit');
            $table->string('nombre', 150);
            $table->string('telefono', 30);
            $table->string('correo_electronico');
            $table->string('direccion');
            $table->string('ciudad', 100)->index();
            $table->enum('estado', ['ACTIVA', 'INACTIVA'])->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eps');
    }
};
