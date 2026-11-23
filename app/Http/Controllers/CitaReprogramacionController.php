<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReprogramarCitaRequest;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\Eps;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\RemisionEps;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CitaReprogramacionController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->has('cita_id')) {
            return view('citas.reprogramar.index');
        }

        $validator = Validator::make($request->query(), ['cita_id' => ['required', 'integer', 'min:1']], [
            'cita_id.*' => 'Ingrese un ID de cita válido.',
        ]);
        if ($validator->fails()) {
            return redirect()->route('citas.reprogramar.index')->withErrors($validator);
        }

        return redirect()->route('citas.reprogramar.edit', $validator->validated()['cita_id']);
    }

    public function edit(Request $request, string $citaId): View|RedirectResponse
    {
        $validator = Validator::make($request->query(), ['fecha' => ['sometimes', 'required', 'string', 'date_format:Y-m-d']], [
            'fecha.*' => 'Ingrese una fecha válida (AAAA-MM-DD).',
        ]);
        if ($validator->fails()) {
            return redirect()->route('citas.reprogramar.edit', $citaId)->withErrors($validator);
        }

        $cita = Cita::query()->with(['paciente', 'medico.user', 'remision.eps'])->findOrFail($citaId);
        $fecha = $validator->validated()['fecha'] ?? max($cita->fecha->toDateString(), RemisionEps::hoy());
        $intervalos = [];
        $errorDisponibilidad = null;

        try {
            $this->validarCita($cita, $cita->paciente, $cita->medico, $fecha, $cita->remision, $cita->remision?->eps);
            $intervalos = $cita->medico->intervalosLibres($fecha, $cita->id);
        } catch (ValidationException $exception) {
            $errorDisponibilidad = $exception->getMessage();
        }

        return view('citas.reprogramar.edit', compact('cita', 'fecha', 'intervalos', 'errorDisponibilidad'));
    }

    public function update(ReprogramarCitaRequest $request, string $citaId): RedirectResponse
    {
        $data = $request->validated();
        $referencia = Cita::query()->with(['medico', 'remision'])->findOrFail($citaId);

        try {
            $reprogramada = DB::transaction(function () use ($request, $data, $referencia): bool {
                /**
                 * Orden común con agendamiento y disponibilidad: cuentas, paciente, EPS,
                 * remisión, médico. Leer la cita actual bajo bloqueo y abrir el snapshot
                 * solo después de adquirir todos los bloqueos.
                 */
                $ids = array_unique([$request->user()->id, $referencia->medico->user_id]);
                sort($ids, SORT_NUMERIC);
                $cuentas = [];
                foreach ($ids as $id) {
                    $cuentas[$id] = User::query()->sharedLock()->findOrFail($id);
                }
                $paciente = Paciente::query()->sharedLock()->findOrFail($referencia->paciente_id);
                $eps = $referencia->remision ? Eps::query()->sharedLock()->findOrFail($referencia->remision->eps_id) : null;
                $remision = $referencia->remision ? RemisionEps::query()->lockForUpdate()->findOrFail($referencia->remision_id) : null;
                $medico = Medico::query()->lockForUpdate()->findOrFail($referencia->medico_id);
                $cita = Cita::query()->lockForUpdate()->findOrFail($referencia->id);

                $actor = $cuentas[$request->user()->id];
                Gate::forUser($actor)->authorize('reprogram', Cita::class);
                if ($cita->paciente_id !== $paciente->id || $cita->medico_id !== $medico->id || $cita->remision_id !== $remision?->id
                    || ! isset($cuentas[$medico->user_id]) || $cita->versionHorario() !== $data['version_horario']) {
                    throw ValidationException::withMessages(['hora_inicio' => 'La cita cambió mientras elegía el horario. Revise la cita y confirme de nuevo.']);
                }

                $medico->setRelation('user', $cuentas[$medico->user_id]);
                $this->validarCita($cita, $paciente, $medico, $data['fecha'], $remision, $eps);
                if ($cita->fecha->toDateString() === $data['fecha'] && $cita->hora_inicio === $data['hora_inicio'].':00') {
                    return false;
                }

                if (! Cita::inicioEsFuturo($data['fecha'], $data['hora_inicio'])) {
                    throw ValidationException::withMessages(['hora_inicio' => 'El inicio debe ser posterior al momento actual de Colombia.']);
                }
                $intervalo = collect($medico->intervalosLibres($data['fecha'], $cita->id))->firstWhere('inicio', $data['hora_inicio']);
                if ($intervalo === null) {
                    throw ValidationException::withMessages(['hora_inicio' => 'El intervalo no está disponible. Seleccione un horario libre y completo.']);
                }

                $antes = $cita->horario();
                $cita->forceFill([
                    'fecha' => $data['fecha'], 'hora_inicio' => $intervalo['inicio'].':00',
                    'hora_fin' => $intervalo['fin'].':00', 'disponibilidad_medica_id' => $intervalo['bloque_id'],
                ])->save();
                Auditoria::query()->create([
                    'user_id' => $actor->id, 'accion' => 'CITA_REPROGRAMADA', 'entidad' => 'Cita', 'registro_id' => $cita->id,
                    'fecha_hora' => now(), 'resultado' => 'EXITO', 'descripcion' => 'Antes: '.$antes.'. Después: '.$cita->horario().'.',
                ]);

                return true;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'citas_medico_fecha_inicio_ocupado_unique') && ! str_contains($exception->getMessage(), 'citas.inicio_ocupado')) {
                throw $exception;
            }

            throw ValidationException::withMessages(['hora_inicio' => 'El intervalo ya está ocupado. Seleccione otro horario.']);
        }

        return redirect()->route('citas.reprogramar.edit', $citaId)->with('status', $reprogramada
            ? 'Consulta reprogramada correctamente.'
            : 'La cita ya tiene el horario seleccionado.');
    }

    private function validarCita(Cita $cita, Paciente $paciente, Medico $medico, string $fecha, ?RemisionEps $remision, ?Eps $eps): void
    {
        if ($cita->estado !== 'PROGRAMADA') {
            throw ValidationException::withMessages(['cita' => 'Solo se pueden reprogramar citas en estado PROGRAMADA.']);
        }
        if ($paciente->estado !== 'ACTIVO') {
            throw ValidationException::withMessages(['cita' => 'El paciente debe estar ACTIVO para reprogramar.']);
        }
        if (! $medico->estaHabilitado()) {
            throw ValidationException::withMessages(['cita' => 'El médico debe tener cuenta activa y perfil profesional completo.']);
        }
        if ($cita->modalidad === 'EPS' && ($remision === null || $remision->paciente_id !== $paciente->id
            || $eps?->estado !== 'ACTIVA' || $eps->id !== $remision->eps_id
            || ! RemisionEps::query()->whereKey($remision->id)->agendablePara($fecha, $cita->id)->exists())) {
            throw ValidationException::withMessages(['fecha' => 'La remisión vinculada debe estar vigente para la nueva fecha y pertenecer al mismo paciente y a una EPS activa.']);
        }
    }
}
