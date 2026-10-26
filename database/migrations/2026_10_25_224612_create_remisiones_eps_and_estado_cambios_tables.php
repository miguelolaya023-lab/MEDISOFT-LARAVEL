<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $mysql = Schema::getConnection()->getDriverName() === 'mysql';
        Schema::create('remisiones_eps', function (Blueprint $table) use ($mysql): void {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('paciente_id')->constrained('pacientes')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('eps_id')->constrained('eps')->restrictOnDelete()->restrictOnUpdate();
            $numero = $table->string('numero_remision', 100);
            if ($mysql) {
                $numero->collation('utf8mb4_bin');
            }
            $table->date('fecha_remision');
            $table->string('servicio_autorizado', 1000);
            $table->date('vigencia_hasta')->index();
            $table->enum('estado', ['VIGENTE', 'VENCIDA', 'USADA', 'ANULADA'])->index();
            $table->text('observacion')->nullable();
            $table->string('motivo_cambio_estado', 500)->nullable();
            $table->unique(['eps_id', 'numero_remision']);
            $table->timestamps();
        });
        Schema::create('cambios_estado_remision', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('remision_id')->constrained('remisiones_eps')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('estado_anterior', 20);
            $table->string('estado_nuevo', 20);
            $table->string('motivo', 500);
            $table->timestamp('fecha_cambio');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cambios_estado_remision');
        Schema::dropIfExists('remisiones_eps');
    }
};
