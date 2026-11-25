<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HistorialPagosPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $permiso = Permiso::query()->firstOrCreate(['codigo' => 'RF-014'], ['descripcion' => 'Consultar historial de pagos del paciente']);
            foreach (['Administrador', 'Médico', 'Administrativo'] as $nombre) {
                Rol::query()->where('nombre', $nombre)->firstOrFail()->permisos()->syncWithoutDetaching([$permiso->id]);
            }
        });
    }
}
