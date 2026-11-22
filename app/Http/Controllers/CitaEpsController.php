<?php

namespace App\Http\Controllers;

use App\Http\Requests\QueryCitaEpsRequest;
use App\Http\Requests\StoreCitaEpsRequest;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\Eps;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\RemisionEps;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CitaEpsController extends Controller
{
    public function create(QueryCitaEpsRequest $request): View
    {
        $seleccion = $request->validated();
        $medicos = Medico::query()->habilitado()->with('user:id,nombres,apellidos')->orderBy('id')->get();
        $pacientes = Paciente::query()->where('estado', 'ACTIVO')->orderBy('nombres')->orderBy('id')->get(['id', 'tipo_documento', 'numero_documento', 'nombres', 'apellidos']);
        $intervalos = [];
        $remisiones = collect();

        if (isset($seleccion['paciente_id'], $seleccion['medico_id'], $seleccion['fecha'])) {
            $medico = $medicos->firstWhere('id', (int) $seleccion['medico_id']);
            if ($medico === null) {
                throw ValidationException::withMessages(['medico_id' => 'Seleccione un médico con cuenta activa y perfil profesional completo.']);
            }
            if (! $pacientes->contains('id', (int) $seleccion['paciente_id'])) {
                throw ValidationException::withMessages(['paciente_id' => 'El paciente debe estar ACTIVO para agendar.']);
            }

            $intervalos = $medico->intervalosLibres($seleccion['fecha']);
            if ($intervalos !== []) {
                $remisiones = RemisionEps::query()->where('paciente_id', $seleccion['paciente_id'])
                    ->agendablePara($seleccion['fecha'])->whereHas('eps', fn ($eps) => $eps->where('estado', 'ACTIVA'))
                    ->with('eps:id,nombre')->orderBy('id')->get(['id', 'eps_id', 'numero_remision', 'vigencia_hasta']);
            }
        }

        return view('citas.eps.create', compact('medicos', 'pacientes', 'seleccion', 'intervalos', 'remisiones'));
    }

    public function store(StoreCitaEpsRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $referenciaMedico = Medico::query()->findOrFail($data['medico_id']);
        $referenciaRemision = RemisionEps::query()->findOrFail($data['remision_id']);
        try {
            $cita = DB::transaction(function () use ($request, $data, $referenciaMedico, $referenciaRemision): Cita {
                /** No abrir el snapshot antes de adquirir los mutex de remisión y médico. */
                $ids = array_unique([$request->user()->id, $referenciaMedico->user_id]);
                sort($ids, SORT_NUMERIC);
                $cuentas = collect();
                foreach ($ids as $id) {
                    $cuentas->put($id, User::query()->sharedLock()->findOrFail($id));
                }
                $paciente = Paciente::query()->sharedLock()->findOrFail($data['paciente_id']);
                $eps = Eps::query()->sharedLock()->findOrFail($referenciaRemision->eps_id);
                $remision = RemisionEps::query()->lockForUpdate()->findOrFail($referenciaRemision->id);
                $medico = Medico::query()->lockForUpdate()->findOrFail($referenciaMedico->id);
                $actor = $cuentas->get($request->user()->id);
                Gate::forUser($actor)->authorize('createEps', Cita::class);

                if ($paciente->estado !== 'ACTIVO') {
                    throw ValidationException::withMessages(['paciente_id' => 'El paciente debe estar ACTIVO para agendar.']);
                }
                if ($medico->user_id !== $referenciaMedico->user_id) {
                    throw ValidationException::withMessages(['medico_id' => 'El perfil cambió; vuelva a seleccionar el médico.']);
                }
                $medico->setRelation('user', $cuentas->get($medico->user_id));
                if (! $medico->estaHabilitado()) {
                    throw ValidationException::withMessages(['medico_id' => 'El médico debe tener cuenta activa y perfil profesional completo.']);
                }
                if ($remision->paciente_id !== $paciente->id || $remision->eps_id !== $eps->id || $eps->estado !== 'ACTIVA'
                    || ! RemisionEps::query()->whereKey($remision->id)->agendablePara($data['fecha'])->exists()) {
                    throw ValidationException::withMessages(['remision_id' => StoreCitaEpsRequest::REMISION_NO_DISPONIBLE]);
                }
                if (! Cita::inicioEsFuturo($data['fecha'], $data['hora_inicio'])) {
                    throw ValidationException::withMessages(['hora_inicio' => 'El inicio debe ser posterior al momento actual de Colombia.']);
                }
                $intervalo = collect($medico->intervalosLibres($data['fecha']))->firstWhere('inicio', $data['hora_inicio']);
                if ($intervalo === null) {
                    throw ValidationException::withMessages(['hora_inicio' => 'El intervalo no está disponible. Seleccione un horario libre y completo.']);
                }

                $cita = new Cita;
                $cita->forceFill([
                    'paciente_id' => $paciente->id, 'medico_id' => $medico->id, 'disponibilidad_medica_id' => $intervalo['bloque_id'],
                    'remision_id' => $remision->id, 'fecha' => $data['fecha'], 'hora_inicio' => $intervalo['inicio'].':00', 'hora_fin' => $intervalo['fin'].':00',
                    'modalidad' => 'EPS', 'tipo_paciente_al_agendar' => $paciente->tipo_paciente, 'motivo_consulta' => $data['motivo_consulta'],
                    'valor_base' => null, 'estado' => 'PROGRAMADA', 'created_by' => $actor->id,
                ])->save();
                Auditoria::query()->create([
                    'user_id' => $actor->id, 'accion' => 'CITA_EPS_CREADA', 'entidad' => 'Cita', 'registro_id' => $cita->id,
                    'fecha_hora' => now(), 'resultado' => 'EXITO', 'descripcion' => 'Cita EPS PROGRAMADA; remisión vinculada sin consumo.',
                ]);

                return $cita;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'citas_remision_reservada_unique') || str_contains($exception->getMessage(), 'citas.remision_reservada')) {
                throw ValidationException::withMessages(['remision_id' => 'La remisión ya está vinculada a otra cita no cancelada.']);
            }
            if (str_contains($exception->getMessage(), 'citas_medico_fecha_inicio_ocupado_unique') || str_contains($exception->getMessage(), 'citas.inicio_ocupado')) {
                throw ValidationException::withMessages(['hora_inicio' => 'El intervalo ya está ocupado. Seleccione otro horario.']);
            }
            throw $exception;
        }

        return redirect()->route('citas.eps.confirmation', $cita)->with('status', 'Consulta EPS agendada correctamente.');
    }

    public function confirmation(Cita $cita): View
    {
        return view('citas.eps.confirmation', ['cita' => $cita->load(['paciente', 'medico.user', 'remision.eps'])]);
    }
}
