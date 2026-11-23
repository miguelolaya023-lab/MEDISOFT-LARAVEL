<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CicloClinicoPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $permisos = [];
            foreach ([
                'RF-029' => 'Registrar motivo de consulta',
                'RF-030' => 'Registrar diagnóstico',
                'RF-031' => 'Finalizar atención médica',
            ] as $codigo => $descripcion) {
                $permisos[] = Permiso::query()->firstOrCreate(['codigo' => $codigo], ['descripcion' => $descripcion])->id;
            }

            foreach (['Administrador', 'Médico'] as $nombre) {
                Rol::query()->where('nombre', $nombre)->firstOrFail()->permisos()->syncWithoutDetaching($permisos);
            }
        });
    }
}
