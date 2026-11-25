<?php

namespace App\Policies;

use App\Models\User;

class ConsultaPagoParticularPolicy
{
    public function consultarPagosParticulares(User $user): bool
    {
        return $user->tienePermiso('RF-036');
    }
}
