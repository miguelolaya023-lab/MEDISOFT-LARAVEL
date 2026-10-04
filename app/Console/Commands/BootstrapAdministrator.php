<?php

namespace App\Console\Commands;

use App\Models\Auditoria;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BootstrapAdministrator extends Command
{
    protected $signature = 'medisoft:bootstrap-admin {identificador : Correo o documento de una cuenta existente}';

    protected $description = 'Asigna explícitamente la primera administración a una cuenta interna activa existente';

    public function handle(): int
    {
        return DB::transaction(function (): int {
            Rol::query()->orderBy('id')->lockForUpdate()->get();
            $usuarios = User::query()->where('estado', 'activo')->lockForUpdate()->get();
            if ($usuarios->contains(fn (User $user): bool => $user->puedeAdministrarUsuarios())) {
                $this->error('Ya existe una administración activa. Utilice el flujo autorizado de asignación de rol.');

                return self::FAILURE;
            }
            $identificador = trim((string) $this->argument('identificador'));
            $campo = str_contains($identificador, '@') ? 'email' : 'numero_documento';
            $usuario = User::query()->where($campo, $campo === 'email' ? strtolower($identificador) : $identificador)->first();
            $rol = Rol::query()->where('nombre', 'Administrador')->first();
            if (! $usuario || ! $usuario->esInterno() || ! $usuario->estaActivo() || ! $rol) {
                $this->error('Se requiere cuenta interna activa y catálogo SecuritySeeder preparado.');

                return self::FAILURE;
            }
            $usuario->update(['rol_id' => $rol->id]);
            if (! $usuario->puedeAdministrarUsuarios()) {
                throw new \RuntimeException('El rol no tiene permisos administrativos.');
            }
            Auditoria::registrar('ROL_INICIAL_ASIGNADO', $usuario, 'Asignación inicial explícita por consola; origen SISTEMA.');
            $this->info('Rol administrativo inicial asignado; contraseña y datos personales conservados.');

            return self::SUCCESS;
        });
    }
}
