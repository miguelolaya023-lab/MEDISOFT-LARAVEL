<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('citas', function (Blueprint $table): void {
            $table->string('motivo_cancelacion', 500)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('citas')->whereNotNull('motivo_cancelacion')->exists()) {
            throw new RuntimeException('La reversión de RF-027 requiere conservar los motivos de cancelación mediante una intervención controlada.');
        }

        Schema::table('citas', function (Blueprint $table): void {
            $table->dropColumn('motivo_cancelacion');
        });
    }
};
