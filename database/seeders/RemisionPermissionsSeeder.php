<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RemisionPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $catalogo = ['RF-019' => 'Registrar remisión EPS', 'RF-020' => 'Actualizar remisión EPS', 'RF-021' => 'Consultar remisión EPS', 'RF-022' => 'Listar remisiones EPS', 'RF-023' => 'Cambiar estado de remisión EPS'];
            foreach ($catalogo as $codigo => $descripcion) {
                Permiso::query()->firstOrCreate(['codigo' => $codigo], ['descripcion' => $descripcion]);
            }
            $ids = Permiso::query()->whereIn('codigo', array_keys($catalogo))->pluck('id');
            foreach (['Administrador', 'Médico', 'Administrativo'] as $nombre) {
                Rol::query()->firstOrCreate(['nombre' => $nombre])->permisos()->syncWithoutDetaching($ids);
            }
        });
    }
}
