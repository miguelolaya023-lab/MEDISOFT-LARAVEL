<?php

namespace App\Policies;

use App\Models\DisponibilidadMedica;
use App\Models\User;

class DisponibilidadMedicaPolicy
{
    public function viewAny(User $user): bool
    {
        $medico = $user->medico()->first();

        return $user->tienePermiso('RF-060') && $medico !== null
            && trim($medico->registro_profesional) !== '' && trim($medico->especialidad) !== '';
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, DisponibilidadMedica $disponibilidad): bool
    {
        return $this->viewAny($user) && $user->medico()->whereKey($disponibilidad->medico_id)->exists();
    }
}
