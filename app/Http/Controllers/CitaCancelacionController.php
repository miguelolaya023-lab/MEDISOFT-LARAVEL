<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelarCitaRequest;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CitaCancelacionController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->has('cita_id')) {
            return view('citas.cancelar.index');
        }

        $validator = Validator::make($request->query(), ['cita_id' => ['required', 'integer', 'min:1']], [
            'cita_id.*' => 'Ingrese un ID de cita válido.',
        ]);
        if ($validator->fails()) {
            return redirect()->route('citas.cancelar.index')->withErrors($validator);
        }

        return redirect()->route('citas.cancelar.show', $validator->validated()['cita_id']);
    }

    public function show(string $citaId): View
    {
        $cita = Cita::query()->with(['paciente', 'medico.user'])->findOrFail($citaId);

        return view('citas.cancelar.show', compact('cita'));
    }

    public function update(CancelarCitaRequest $request, string $citaId): RedirectResponse
    {
        $referencia = Cita::query()->findOrFail($citaId);
        $destino = route('citas.cancelar.show', $citaId);

        try {
            $cancelada = DB::transaction(function () use ($request, $referencia): bool {
                /**
                 * Mismo orden de bloqueos que en agenda. Remisión y médico sincronizan
                 * la liberación de las reservas con el agendamiento y la reprogramación.
                 */
                $actor = User::query()->sharedLock()->findOrFail($request->user()->id);
                if ($referencia->remision_id !== null) {
                    $referencia->remision()->lockForUpdate()->firstOrFail();
                }
                $referencia->medico()->lockForUpdate()->firstOrFail();
                $cita = Cita::query()->lockForUpdate()->findOrFail($referencia->id);

                Gate::forUser($actor)->authorize('cancel', Cita::class);
                if ($cita->estado === 'CANCELADA') {
                    return false;
                }
                if ($cita->estado !== 'PROGRAMADA') {
                    throw ValidationException::withMessages(['cita' => 'Solo se pueden cancelar citas en estado PROGRAMADA.']);
                }
                if ($cita->medico_id !== $referencia->medico_id || $cita->remision_id !== $referencia->remision_id
                    || $cita->versionHorario() !== $request->validated('version_horario')) {
                    throw ValidationException::withMessages(['cita' => 'La cita cambió mientras revisaba el horario. Revise la cita y confirme de nuevo.']);
                }

                $cita->forceFill([
                    'estado' => 'CANCELADA',
                    'motivo_cancelacion' => $request->validated('motivo_cancelacion'),
                ])->save();
                Auditoria::query()->create([
                    'user_id' => $actor->id, 'accion' => 'CITA_CANCELADA', 'entidad' => 'Cita', 'registro_id' => $cita->id,
                    'fecha_hora' => now(), 'resultado' => 'EXITO',
                    'descripcion' => 'Estado PROGRAMADA a CANCELADA. Horario liberado: '.$cita->horario().'.',
                ]);

                return true;
            }, attempts: 3);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($destino);
        }

        return redirect($destino)->with('status', $cancelada
            ? 'Consulta cancelada correctamente.'
            : 'La cita ya está cancelada.');
    }
}
