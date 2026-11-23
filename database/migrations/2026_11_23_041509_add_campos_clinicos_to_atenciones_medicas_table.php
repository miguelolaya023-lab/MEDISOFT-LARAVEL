<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atenciones_medicas', function (Blueprint $table): void {
            $table->text('motivo_consulta')->nullable();
            $table->text('diagnostico_principal')->nullable();
            $table->text('observacion_final')->nullable();
            $table->timestamp('fecha_hora_fin')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('atenciones_medicas')->whereNotNull('motivo_consulta')
            ->orWhereNotNull('diagnostico_principal')->orWhereNotNull('observacion_final')
            ->orWhereNotNull('fecha_hora_fin')->orWhere('estado', 'FINALIZADA')->exists()) {
            throw new RuntimeException('La reversión eliminaría información clínica registrada.');
        }

        Schema::table('atenciones_medicas', function (Blueprint $table): void {
            $table->dropColumn(['motivo_consulta', 'diagnostico_principal', 'observacion_final', 'fecha_hora_fin']);
        });
    }
};
