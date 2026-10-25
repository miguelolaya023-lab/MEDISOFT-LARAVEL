<?php

namespace App\Policies;

use App\Models\Eps;
use App\Models\User;

class EpsPolicy
{
    public function create(User $user): bool
    {
        return $user->tienePermiso('RF-015');
    }

    public function update(User $user, Eps $eps): bool
    {
        return $user->tienePermiso('RF-016');
    }

    public function search(User $user): bool
    {
        return $user->tienePermiso('RF-017');
    }

    public function view(User $user, Eps $eps): bool
    {
        return $this->search($user);
    }

    public function viewAny(User $user): bool
    {
        return $user->tienePermiso('RF-018');
    }
}
