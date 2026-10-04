<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SecuritySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $catalogo = ['RF-004' => 'Crear usuarios', 'RF-005' => 'Editar usuarios', 'RF-006' => 'Consultar usuarios', 'RF-007' => 'Cambiar estado de usuarios', 'RF-054' => 'Administrar roles', 'RF-055' => 'Asignar rol'];
            foreach ($catalogo as $codigo => $descripcion) {
                Permiso::query()->firstOrCreate(['codigo' => $codigo], ['descripcion' => $descripcion]);
            }
            $administrador = Rol::query()->firstOrCreate(['nombre' => 'Administrador'], ['descripcion' => 'Administración interna']);
            $administrador->permisos()->syncWithoutDetaching(Permiso::query()->whereIn('codigo', array_keys($catalogo))->pluck('id'));
            foreach (['Médico', 'Administrativo'] as $nombre) {
                Rol::query()->firstOrCreate(['nombre' => $nombre]);
            }
        });
    }
}
