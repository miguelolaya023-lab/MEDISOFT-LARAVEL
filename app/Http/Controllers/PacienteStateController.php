<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePacienteStateRequest;
use App\Models\Auditoria;
use App\Models\Paciente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PacienteStateController extends Controller
{
    public function show(Paciente $paciente): View
    {
        return view('pacientes.state', compact('paciente'));
    }

    public function inactivate(ChangePacienteStateRequest $request, Paciente $paciente): RedirectResponse
    {
        return $this->changeState($request, $paciente, 'ACTIVO', 'INACTIVO');
    }

    public function reactivate(ChangePacienteStateRequest $request, Paciente $paciente): RedirectResponse
    {
        return $this->changeState($request, $paciente, 'INACTIVO', 'ACTIVO');
    }

    /**
     * RN-23: al incorporar AtencionMedica, comprobar EN_CURSO bajo este bloqueo
     * antes de inactivar y avisar las citas PROGRAMADAS en la confirmación.
     * RF-012.1: agenda e inicio de atención deberán bloquear la misma fila y
     * comprobar ACTIVO antes de escribir; cancelar conservará las citas.
     */
    private function changeState(ChangePacienteStateRequest $request, Paciente $paciente, string $anterior, string $nuevo): RedirectResponse
    {
        DB::transaction(function () use ($request, $paciente, $anterior, $nuevo): void {
            $paciente = Paciente::query()->lockForUpdate()->findOrFail($paciente->getKey());
            if ($paciente->estado !== $anterior) {
                throw ValidationException::withMessages([
                    'motivo' => 'El estado del paciente ya no permite esta operación. Revise su estado actual.',
                ]);
            }
            $motivo = $request->validated('motivo');
            $paciente->forceFill([
                'estado' => $nuevo,
                ...($nuevo === 'INACTIVO' ? ['motivo_inactivacion' => $motivo] : []),
            ])->save();
            Auditoria::query()->create([
                'user_id' => $request->user()->getKey(),
                'accion' => $nuevo === 'INACTIVO' ? 'PACIENTE_INACTIVADO' : 'PACIENTE_REACTIVADO',
                'entidad' => 'Paciente', 'registro_id' => $paciente->getKey(),
                'fecha_hora' => now(), 'resultado' => 'EXITO', 'descripcion' => $motivo,
            ]);
        });

        $destino = $request->user()->can('view', $paciente) ? route('pacientes.show', $paciente) : route('pacientes.state', $paciente);

        return redirect($destino)->with('status', $nuevo === 'INACTIVO' ? 'Paciente inactivado correctamente.' : 'Paciente reactivado correctamente.');
    }
}
