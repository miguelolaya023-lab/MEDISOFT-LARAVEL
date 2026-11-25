<?php

namespace App\Policies;

use App\Models\User;

class HistorialPagosPacientePolicy
{
    public function consultarHistorialPagos(User $user): bool
    {
        return $user->tienePermiso('RF-014');
    }
}
