<?php

namespace App\Policies;

use App\Models\AtencionMedica;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AtencionMedicaPolicy
{
    private function tienePermisoClinico(User $user): bool
    {
        return $user->tienePermiso('RF-029') || $user->tienePermiso('RF-030') || $user->tienePermiso('RF-031');
    }

    public function viewAny(User $user): bool
    {
        return $this->tienePermisoClinico($user) && $user->medico()->habilitado()->exists();
    }

    public function view(User $user, AtencionMedica $atencion): Response
    {
        return $this->autorizarTitular($user, $atencion, $this->tienePermisoClinico($user));
    }

    public function registrarMotivo(User $user, AtencionMedica $atencion): Response
    {
        return $this->autorizarTitular($user, $atencion, $user->tienePermiso('RF-029'));
    }

    public function registrarDiagnostico(User $user, AtencionMedica $atencion): Response
    {
        return $this->autorizarTitular($user, $atencion, $user->tienePermiso('RF-030'));
    }

    public function finalizar(User $user, AtencionMedica $atencion): Response
    {
        return $this->autorizarTitular($user, $atencion, $user->tienePermiso('RF-031'));
    }

    public function listarTratamientos(User $user): bool
    {
        return $this->permiteDocumentarAtencion($user, 'RF-056') && $user->medico()->habilitado()->exists();
    }

    public function registrarTratamiento(User $user, AtencionMedica $atencion): Response
    {
        return $this->autorizarTitular($user, $atencion, $this->permiteDocumentarAtencion($user, 'RF-056'));
    }

    public function listarFormulasMedicas(User $user): bool
    {
        return $this->permiteDocumentarAtencion($user, 'RF-057') && $user->medico()->habilitado()->exists();
    }

    public function registrarFormulaMedica(User $user, AtencionMedica $atencion): Response
    {
        return $this->autorizarTitular($user, $atencion, $this->permiteDocumentarAtencion($user, 'RF-057'));
    }

    private function permiteDocumentarAtencion(User $user, string $permiso): bool
    {
        return $user->tienePermiso($permiso) && in_array($user->rol?->nombre, ['Médico', 'Administrador'], true);
    }

    private function autorizarTitular(User $user, AtencionMedica $atencion, bool $permiso): Response
    {
        if (! $permiso) {
            return Response::deny();
        }

        $medicoId = $user->medico()->habilitado()->value('id');
        if ($medicoId === null) {
            return Response::deny();
        }

        return $medicoId === $atencion->medico_id ? Response::allow() : Response::denyAsNotFound();
    }
}
