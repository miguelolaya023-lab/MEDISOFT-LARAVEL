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
}
