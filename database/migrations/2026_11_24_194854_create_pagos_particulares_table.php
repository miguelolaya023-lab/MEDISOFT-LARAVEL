<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos_particulares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('liquidacion_id')->constrained('liquidaciones')->restrictOnDelete();
            $table->string('numero_recibo', 29)->unique();
            $table->decimal('valor_pagado', 12, 2);
            $table->date('fecha_pago');
            $table->dateTime('fecha_registro');
            $table->string('metodo_pago', 16);
            $table->text('observacion')->nullable();
            $table->string('estado', 12);
            $table->foreignId('creado_por')->constrained('users')->restrictOnDelete();
            $table->uuid('clave_envio');
            $table->char('huella_envio', 64);
            $table->unique(['liquidacion_id', 'clave_envio'], 'pagos_particulares_envio_unique');
            $table->unsignedBigInteger('liquidacion_vigente_id')->nullable()
                ->storedAs("CASE WHEN estado = 'REGISTRADO' THEN liquidacion_id ELSE NULL END")
                ->unique('pagos_particulares_vigente_unique');
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $evento) {
                $sufijo = strtolower($evento);
                DB::unprepared("
                    CREATE TRIGGER pagos_particulares_dominio_{$sufijo} BEFORE {$evento} ON pagos_particulares
                    WHEN typeof(NEW.valor_pagado) NOT IN ('integer', 'real') OR NEW.valor_pagado <= 0
                        OR NEW.valor_pagado > 9999999999.99 OR round(NEW.valor_pagado, 2) <> NEW.valor_pagado
                        OR NEW.estado NOT IN ('REGISTRADO', 'ANULADO')
                        OR NEW.metodo_pago NOT IN ('EFECTIVO', 'TRANSFERENCIA', 'TARJETA')
                    BEGIN
                        SELECT RAISE(ABORT, 'Importe, estado o método de pago inválidos');
                    END
                ");
            }

            return;
        }

        DB::statement("
            ALTER TABLE pagos_particulares ADD CONSTRAINT pagos_particulares_dominio_check CHECK (
                valor_pagado > 0
                AND CAST(estado AS BINARY) IN ('REGISTRADO', 'ANULADO')
                AND CAST(metodo_pago AS BINARY) IN ('EFECTIVO', 'TRANSFERENCIA', 'TARJETA')
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_particulares');
    }
};
