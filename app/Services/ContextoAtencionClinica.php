<?php

namespace App\Services;

use App\Models\AtencionMedica;
use App\Models\Cita;
use App\Models\Eps;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\RemisionEps;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ContextoAtencionClinica
{
    /** @param Closure(AtencionMedica, User, Paciente): string $operacion */
    public function escribir(User $actorOriginal, AtencionMedica $atencion, string $habilidad, Closure $operacion, bool $bloquearRemision = false): string
    {
        $referencia = $atencion->cita;
        $remisionPrevia = $bloquearRemision ? $referencia->remision : null;

        return DB::transaction(function () use ($actorOriginal, $atencion, $referencia, $remisionPrevia, $habilidad, $operacion, $bloquearRemision): string {
            $actor = User::query()->sharedLock()->findOrFail($actorOriginal->id);
            $paciente = Paciente::query()->sharedLock()->findOrFail($referencia->paciente_id);
            $remision = null;
            if ($remisionPrevia !== null) {
                Eps::query()->sharedLock()->findOrFail($remisionPrevia->eps_id);
                $remision = RemisionEps::query()->lockForUpdate()->findOrFail($remisionPrevia->id);
                abort_unless($remision->eps_id === $remisionPrevia->eps_id, 409);
            }
            Medico::query()->sharedLock()->findOrFail($atencion->medico_id);
            $cita = Cita::query()->lockForUpdate()->findOrFail($atencion->cita_id);
            $actual = AtencionMedica::query()->lockForUpdate()->findOrFail($atencion->id);
            Gate::forUser($actor)->authorize($habilidad, $actual);
            $this->comprobarVinculos($actual, $cita);
            abort_unless($cita->paciente_id === $referencia->paciente_id && $cita->remision_id === $referencia->remision_id
                && $actual->medico_id === $atencion->medico_id && $actual->historia_clinica_id === $atencion->historia_clinica_id, 409);
            if ($bloquearRemision) {
                $cita->setRelation('remision', $remision);
            }
            $actual->setRelation('cita', $cita);

            return $operacion($actual, $actor, $paciente);
        }, attempts: 3);
    }

    /** @return Builder<AtencionMedica> */
    public function atencionesDelMedico(Medico $medico): Builder
    {
        return AtencionMedica::query()
            ->select('atenciones_medicas.*')
            ->join('citas', 'citas.id', '=', 'atenciones_medicas.cita_id')
            ->join('historias_clinicas', 'historias_clinicas.id', '=', 'atenciones_medicas.historia_clinica_id')
            ->where('atenciones_medicas.medico_id', $medico->id)
            ->whereColumn('citas.medico_id', 'atenciones_medicas.medico_id')
            ->whereColumn('citas.paciente_id', 'historias_clinicas.paciente_id')
            ->with('cita.paciente')
            ->orderByDesc('atenciones_medicas.fecha_hora_inicio')->orderByDesc('atenciones_medicas.id');
    }

    public function comprobarVinculos(AtencionMedica $atencion, Cita $cita): void
    {
        abort_unless($atencion->cita_id === $cita->id && $atencion->medico_id === $cita->medico_id
            && $atencion->historiaClinica->paciente_id === $cita->paciente_id, 404);
    }

    public function exigirEnCurso(AtencionMedica $atencion): void
    {
        if (! $this->estaEnCurso($atencion)) {
            throw ValidationException::withMessages(['atencion' => 'Solo se puede modificar una atención EN_CURSO con cita EN_ATENCION.']);
        }
    }

    public function estaEnCurso(AtencionMedica $atencion): bool
    {
        return $atencion->estado === 'EN_CURSO' && $atencion->fecha_hora_fin === null && $atencion->cita->estado === 'EN_ATENCION';
    }
}
