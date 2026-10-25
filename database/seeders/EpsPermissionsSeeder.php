<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EpsPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $catalogo = ['RF-015' => 'Registrar EPS', 'RF-016' => 'Editar EPS', 'RF-017' => 'Consultar EPS', 'RF-018' => 'Listar EPS'];
            foreach ($catalogo as $codigo => $descripcion) {
                Permiso::query()->firstOrCreate(['codigo' => $codigo], ['descripcion' => $descripcion]);
            }
            $permisos = Permiso::query()->whereIn('codigo', array_keys($catalogo))->pluck('id');
            foreach (['Administrador', 'Médico', 'Administrativo'] as $nombre) {
                Rol::query()->firstOrCreate(['nombre' => $nombre])->permisos()->syncWithoutDetaching($permisos);
            }
        });
    }
}
