<?php

namespace App\Policies;

use App\Models\AntecedenteClinico;
use App\Models\Paciente;
use App\Models\User;

class AntecedenteClinicoPolicy
{
    public function viewAny(User $user): bool
    {
        $medico = $user->medico()->first();

        return $user->tienePermiso('RF-058') && $medico !== null
            && trim($medico->registro_profesional) !== '' && trim($medico->especialidad) !== '';
    }

    public function create(User $user, Paciente $paciente): bool
    {
        return $this->viewAny($user) && $paciente->estado === 'ACTIVO';
    }

    public function update(User $user, AntecedenteClinico $antecedente): bool
    {
        return $this->create($user, $antecedente->historiaClinica->paciente);
    }
}
