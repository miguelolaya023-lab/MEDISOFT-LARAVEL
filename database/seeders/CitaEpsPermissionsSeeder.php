<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CitaEpsPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $permiso = Permiso::query()->firstOrCreate(['codigo' => 'RF-025'], ['descripcion' => 'Agendar consulta EPS']);
            foreach (['Administrador', 'Médico', 'Administrativo'] as $nombre) {
                Rol::query()->firstOrCreate(['nombre' => $nombre])->permisos()->syncWithoutDetaching([$permiso->id]);
            }
        });
    }
}
