<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MODALIDAD_CHECK = "(modalidad = 'PARTICULAR' AND remision_id IS NULL AND valor_base IS NOT NULL AND valor_base > 0) OR (modalidad = 'EPS' AND remision_id IS NOT NULL AND valor_base IS NULL)";

    private const REMISION_RESERVADA = "CASE WHEN estado <> 'CANCELADA' THEN remision_id ELSE NULL END";

    public function up(): void
    {
        $check = self::MODALIDAD_CHECK;
        $reserva = self::REMISION_RESERVADA;

        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('citas', fn (Blueprint $table) => $table->decimal('valor_base', 12, 2)->nullable()->change());
            DB::statement("ALTER TABLE citas ADD COLUMN remision_id INTEGER REFERENCES remisiones_eps(id) ON DELETE RESTRICT ON UPDATE RESTRICT CONSTRAINT citas_modalidad_check CHECK ($check)");
            DB::statement("ALTER TABLE citas ADD COLUMN remision_reservada INTEGER GENERATED ALWAYS AS ($reserva) VIRTUAL");
            Schema::table('citas', function (Blueprint $table): void {
                $table->index('remision_id', 'citas_remision_id_index');
                $table->unique('remision_reservada', 'citas_remision_reservada_unique');
            });

            return;
        }

        DB::statement("ALTER TABLE citas
            MODIFY valor_base DECIMAL(12, 2) NULL,
            ADD COLUMN remision_id BIGINT UNSIGNED NULL,
            ADD COLUMN remision_reservada BIGINT UNSIGNED GENERATED ALWAYS AS ($reserva) STORED,
            ADD INDEX citas_remision_id_index (remision_id),
            ADD UNIQUE INDEX citas_remision_reservada_unique (remision_reservada),
            ADD CONSTRAINT citas_remision_fk FOREIGN KEY (remision_id) REFERENCES remisiones_eps(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            ADD CONSTRAINT citas_modalidad_check CHECK ($check)");
    }

    public function down(): void
    {
        if (DB::table('citas')->whereNotNull('remision_id')->exists()) {
            throw new RuntimeException('La reversión de RF-025 requiere conservar las citas EPS mediante una intervención controlada.');
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX citas_remision_reservada_unique');
            DB::statement('DROP INDEX citas_remision_id_index');
            DB::statement('ALTER TABLE citas DROP COLUMN remision_reservada');
            DB::statement('ALTER TABLE citas DROP COLUMN remision_id');
            Schema::table('citas', fn (Blueprint $table) => $table->decimal('valor_base', 12, 2)->nullable(false)->change());

            return;
        }

        DB::statement('ALTER TABLE citas
            DROP CHECK citas_modalidad_check,
            DROP FOREIGN KEY citas_remision_fk,
            DROP INDEX citas_remision_id_index,
            DROP INDEX citas_remision_reservada_unique,
            DROP COLUMN remision_reservada,
            DROP COLUMN remision_id,
            MODIFY valor_base DECIMAL(12, 2) NOT NULL');
    }
};
