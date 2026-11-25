<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ConsultaPagoParticularPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $permiso = Permiso::query()->firstOrCreate(['codigo' => 'RF-036'], ['descripcion' => 'Consultar pagos particulares']);
            foreach (['Administrador', 'Médico', 'Administrativo'] as $nombre) {
                Rol::query()->where('nombre', $nombre)->firstOrFail()->permisos()->syncWithoutDetaching([$permiso->id]);
            }
        });
    }
}
