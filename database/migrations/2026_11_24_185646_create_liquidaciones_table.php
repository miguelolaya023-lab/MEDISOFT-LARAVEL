<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liquidaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('atencion_medica_id')->unique()->constrained('atenciones_medicas')->restrictOnDelete();
            $table->string('modalidad', 10);
            $table->decimal('valor_total', 12, 2);
            $table->dateTime('fecha_liquidacion');
            $table->foreignId('creado_por')->constrained('users')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $evento) {
                $sufijo = strtolower($evento);
                DB::unprepared("
                    CREATE TRIGGER liquidaciones_modalidad_valor_{$sufijo} BEFORE {$evento} ON liquidaciones
                    WHEN typeof(NEW.valor_total) NOT IN ('integer', 'real')
                        OR NEW.valor_total > 9999999999.99
                        OR round(NEW.valor_total, 2) <> NEW.valor_total
                        OR NOT ((NEW.modalidad = 'PARTICULAR' AND NEW.valor_total > 0)
                            OR (NEW.modalidad = 'EPS' AND NEW.valor_total >= 0))
                    BEGIN
                        SELECT RAISE(ABORT, 'Modalidad o valor de liquidación inválidos');
                    END
                ");
            }

            return;
        }

        DB::statement("
            ALTER TABLE liquidaciones ADD CONSTRAINT liquidaciones_modalidad_valor_check CHECK (
                (CAST(modalidad AS BINARY) = 'PARTICULAR' AND valor_total > 0)
                OR (CAST(modalidad AS BINARY) = 'EPS' AND valor_total >= 0)
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidaciones');
    }
};
