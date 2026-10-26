<?php

namespace App\Policies;

use App\Models\RemisionEps;
use App\Models\User;

class RemisionEpsPolicy
{
    public function create(User $user): bool
    {
        return $user->tienePermiso('RF-019');
    }

    public function update(User $user, RemisionEps $remision): bool
    {
        return $user->tienePermiso('RF-020');
    }

    public function search(User $user): bool
    {
        return $user->tienePermiso('RF-021');
    }

    public function view(User $user, RemisionEps $remision): bool
    {
        return $this->search($user);
    }

    public function viewAny(User $user): bool
    {
        return $user->tienePermiso('RF-022');
    }

    public function changeState(User $user, RemisionEps $remision): bool
    {
        return $user->tienePermiso('RF-023');
    }
}
