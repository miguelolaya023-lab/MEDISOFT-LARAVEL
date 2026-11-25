<?php

namespace App\Policies;

use App\Models\User;

class ComprobanteParticularPolicy
{
    public function emitirComprobanteParticular(User $user): bool
    {
        return $user->tienePermiso('RF-035');
    }
}
