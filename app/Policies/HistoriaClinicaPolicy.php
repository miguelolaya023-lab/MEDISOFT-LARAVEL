<?php

namespace App\Policies;

use App\Models\User;

class HistoriaClinicaPolicy
{
    public function viewConsultationHistory(User $user): bool
    {
        return $user->tienePermiso('RF-013')
            && in_array($user->rol?->nombre, ['Médico', 'Administrador'], true)
            && $user->medico()->habilitado()->exists();
    }

    public function viewAny(User $user): bool
    {
        return $user->tienePermiso('RF-059')
            && $user->rol?->nombre !== 'Administrativo'
            && $user->medico()->habilitado()->exists();
    }
}
