<?php

namespace App\Policies;

use App\Models\User;

class PagoParticularPolicy
{
    public function registrarPagoParticular(User $user): bool
    {
        return $user->tienePermiso('RF-034');
    }
}
