<?php

namespace App\Policies;

use App\Models\User;

class LiquidacionPolicy
{
    public function liquidarParticular(User $user): bool
    {
        return $user->tienePermiso('RF-033');
    }
}
