<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PacientePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $catalogo = ['RF-008' => 'Registrar pacientes', 'RF-010' => 'Consultar pacientes', 'RF-011' => 'Listar pacientes'];
            foreach ($catalogo as $codigo => $descripcion) {
                Permiso::query()->firstOrCreate(['codigo' => $codigo], ['descripcion' => $descripcion]);
            }
            $permisos = Permiso::query()->whereIn('codigo', array_keys($catalogo))->pluck('id');
            foreach (['Administrador', 'Médico', 'Administrativo'] as $nombre) {
                $rol = Rol::query()->firstOrCreate(['nombre' => $nombre]);
                $rol->permisos()->syncWithoutDetaching($permisos);
            }
        });
    }
}
