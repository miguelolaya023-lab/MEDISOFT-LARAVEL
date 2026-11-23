<?php

namespace App\Policies;

use App\Models\Cita;
use App\Models\User;

class CitaPolicy
{
    public function create(User $user): bool
    {
        return $user->tienePermiso('RF-024');
    }

    public function confirm(User $user, Cita $cita): bool
    {
        return $this->create($user) && $cita->modalidad === 'PARTICULAR' && $cita->created_by === $user->id;
    }

    public function createEps(User $user): bool
    {
        return $user->tienePermiso('RF-025');
    }

    public function confirmEps(User $user, Cita $cita): bool
    {
        return $this->createEps($user) && $cita->modalidad === 'EPS' && $cita->created_by === $user->id;
    }

    public function reprogram(User $user): bool
    {
        return $user->tienePermiso('RF-026');
    }
}
