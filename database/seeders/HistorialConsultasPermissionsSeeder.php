<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HistorialConsultasPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $permiso = Permiso::query()->firstOrCreate(['codigo' => 'RF-013'], ['descripcion' => 'Consultar historial de consultas del paciente']);
            foreach (['Administrador', 'Médico'] as $nombre) {
                Rol::query()->where('nombre', $nombre)->firstOrFail()->permisos()->syncWithoutDetaching([$permiso->id]);
            }
        });
    }
}
