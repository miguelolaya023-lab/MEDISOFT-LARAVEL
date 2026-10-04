<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tienePermiso('RF-006');
    }

    public function view(User $user, User $target): bool
    {
        return $target->esInterno() && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->tienePermiso('RF-004');
    }

    public function update(User $user, User $target): bool
    {
        return $target->esInterno() && $user->tienePermiso('RF-005');
    }

    public function changeState(User $user, User $target): bool
    {
        return $target->esInterno() && $user->tienePermiso('RF-007');
    }

    public function assignRole(User $user, User $target): bool
    {
        return $target->esInterno() && $user->tienePermiso('RF-055');
    }
}
