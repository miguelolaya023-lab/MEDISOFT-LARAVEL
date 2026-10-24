<?php

namespace App\Policies;

use App\Models\Paciente;
use App\Models\User;

class PacientePolicy
{
    public function create(User $user): bool
    {
        return $user->tienePermiso('RF-008');
    }

    public function search(User $user): bool
    {
        return $user->tienePermiso('RF-010');
    }

    public function view(User $user, Paciente $paciente): bool
    {
        return $this->search($user);
    }

    public function viewAny(User $user): bool
    {
        return $user->tienePermiso('RF-011');
    }

    public function update(User $user, Paciente $paciente): bool
    {
        return $user->tienePermiso('RF-009');
    }

    public function inactivate(User $user, Paciente $paciente): bool
    {
        return $user->tienePermiso('RF-012');
    }

    public function reactivate(User $user, Paciente $paciente): bool
    {
        return $this->update($user, $paciente);
    }

    public function changeState(User $user, Paciente $paciente): bool
    {
        return $this->inactivate($user, $paciente) || $this->reactivate($user, $paciente);
    }
}
