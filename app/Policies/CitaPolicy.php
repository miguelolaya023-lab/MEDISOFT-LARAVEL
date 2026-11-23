<?php

namespace App\Policies;

use App\Models\AtencionMedica;
use App\Models\Cita;
use App\Models\User;
use Illuminate\Auth\Access\Response;

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

    public function cancel(User $user): bool
    {
        return $user->tienePermiso('RF-027');
    }

    public function consultarAgendaDiaria(User $user): bool
    {
        return $user->tienePermiso('RF-032');
    }

    public function consultarAgendaAjena(User $user): bool
    {
        return $this->consultarAgendaDiaria($user)
            && $user->rol()->whereIn('nombre', ['Administrador', 'Administrativo'])->exists();
    }

    public function startAttendance(User $user): bool
    {
        return $user->tienePermiso('RF-028') && $user->medico()->habilitado()->exists();
    }

    public function verAgendaClinica(User $user): bool
    {
        return $this->startAttendance($user) || $user->can('viewAny', AtencionMedica::class);
    }

    public function notificarCita(User $user, ?Cita $cita = null): bool|Response
    {
        if (! $user->tienePermiso('RF-061')) {
            return false;
        }
        if ($cita === null) {
            return true;
        }

        $puedeConsultar = $user->rol()->whereIn('nombre', ['Administrador', 'Administrativo'])->exists()
            || $user->medico()->whereKey($cita->medico_id)->exists();

        return $puedeConsultar ? Response::allow() : Response::denyAsNotFound();
    }
}
