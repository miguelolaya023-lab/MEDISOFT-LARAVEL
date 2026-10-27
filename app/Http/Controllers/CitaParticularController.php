<?php

namespace App\Http\Controllers;

use App\Http\Requests\QueryCitaParticularRequest;
use App\Http\Requests\StoreCitaParticularRequest;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CitaParticularController extends Controller
{
    public function create(QueryCitaParticularRequest $request): View
    {
        $seleccion = $request->validated();
        $medicos = Medico::query()->habilitado()->with('user:id,nombres,apellidos')->orderBy('id')->get();
        $intervalos = [];
        if (isset($seleccion['medico_id'], $seleccion['fecha'])) {
            $medico = $medicos->firstWhere('id', (int) $seleccion['medico_id']);
            if ($medico === null) {
                throw ValidationException::withMessages(['medico_id' => 'Seleccione un médico con cuenta activa y perfil profesional completo.']);
            }
            $intervalos = $this->intervalosLibres($medico, $seleccion['fecha']);
        }

        return view('citas.particulares.create', [
            'medicos' => $medicos, 'seleccion' => $seleccion, 'intervalos' => $intervalos,
            'pacientes' => Paciente::query()->where('estado', 'ACTIVO')->orderBy('nombres')->orderBy('id')->get(['id', 'tipo_documento', 'numero_documento', 'nombres', 'apellidos']),
        ]);
    }

    public function store(StoreCitaParticularRequest $request): RedirectResponse
    {
        $data = $request->validated();
        /** La asociación se descubre fuera para no abrir el snapshot antes del mutex. */
        $referencia = Medico::query()->findOrFail($data['medico_id']);
        try {
            $cita = DB::transaction(function () use ($request, $data, $referencia): Cita {
                /**
                 * User compartido evita ciclos con las FK de auditoría. Paciente
                 * precede a Médico para coordinarse con las FK clínicas de RF-058.
                 * Ninguna consulta consistente debe preceder estos bloqueos.
                 */
                $ids = array_unique([$request->user()->id, $referencia->user_id]);
                sort($ids, SORT_NUMERIC);
                $cuentas = collect();
                foreach ($ids as $id) {
                    $cuentas->put($id, User::query()->sharedLock()->findOrFail($id));
                }
                $paciente = Paciente::query()->sharedLock()->findOrFail($data['paciente_id']);
                $medico = Medico::query()->lockForUpdate()->findOrFail($referencia->id);
                $actor = $cuentas->get($request->user()->id);
                if ($medico->user_id !== $referencia->user_id) {
                    throw ValidationException::withMessages(['medico_id' => 'El perfil cambió; vuelva a seleccionar el médico.']);
                }
                $medico->setRelation('user', $cuentas->get($medico->user_id));
                Gate::forUser($actor)->authorize('create', Cita::class);
                if ($paciente->estado !== 'ACTIVO') {
                    throw ValidationException::withMessages(['paciente_id' => 'El paciente debe estar ACTIVO para agendar.']);
                }
                if (! $medico->estaHabilitado()) {
                    throw ValidationException::withMessages(['medico_id' => 'El médico debe tener cuenta activa y perfil profesional completo.']);
                }
                if (! Cita::inicioEsFuturo($data['fecha'], $data['hora_inicio'])) {
                    throw ValidationException::withMessages(['hora_inicio' => 'El inicio debe ser posterior al momento actual de Colombia.']);
                }
                $intervalo = collect($this->intervalosLibres($medico, $data['fecha']))->firstWhere('inicio', $data['hora_inicio']);
                if ($intervalo === null) {
                    throw ValidationException::withMessages(['hora_inicio' => 'El intervalo no está disponible. Seleccione un horario libre y completo.']);
                }
                $cita = new Cita;
                $cita->forceFill([
                    'paciente_id' => $paciente->id, 'medico_id' => $medico->id, 'disponibilidad_medica_id' => $intervalo['bloque_id'],
                    'fecha' => $data['fecha'], 'hora_inicio' => $intervalo['inicio'].':00', 'hora_fin' => $intervalo['fin'].':00',
                    'modalidad' => 'PARTICULAR', 'tipo_paciente_al_agendar' => $paciente->tipo_paciente,
                    'motivo_consulta' => $data['motivo_consulta'], 'valor_base' => (string) $data['valor_base'],
                    'estado' => 'PROGRAMADA', 'created_by' => $actor->id,
                ])->save();
                Auditoria::query()->create([
                    'user_id' => $actor->id, 'accion' => 'CITA_PARTICULAR_CREADA', 'entidad' => 'Cita', 'registro_id' => $cita->id,
                    'fecha_hora' => now(), 'resultado' => 'EXITO', 'descripcion' => 'Cita particular PROGRAMADA; sin atención ni pago.',
                ]);

                return $cita;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'citas_medico_fecha_inicio_ocupado_unique') && ! str_contains($exception->getMessage(), 'citas.inicio_ocupado')) {
                throw $exception;
            }
            throw ValidationException::withMessages(['hora_inicio' => 'El intervalo ya está ocupado. Seleccione otro horario.']);
        }

        return redirect()->route('citas.confirmation', $cita)->with('status', 'Consulta particular agendada correctamente.');
    }

    public function confirmation(Cita $cita): View
    {
        return view('citas.particulares.confirmation', ['cita' => $cita->load(['paciente', 'medico.user'])]);
    }

    /**
     * RF-024/025/026/027: User(s) compartidos por ID -> Paciente compartido ->
     * EPS compartida/Remisión exclusiva cuando se implementen -> Médico exclusivo
     * -> bloque/Cita por PK si se modifican. No abrir snapshot antes del último
     * mutex ni convertir User compartido en exclusivo. Nunca bloquear rangos.
     * La vista previa no reserva; al escribir se llama bajo el mutex Médico.
     *
     * @return list<array{inicio: string, fin: string, bloque_id: int}>
     */
    private function intervalosLibres(Medico $medico, string $fecha): array
    {
        $bloques = $medico->disponibilidades()->whereDate('fecha', $fecha)->orderBy('hora_inicio')->get();
        $ocupadas = $medico->citas()->ocupaHorario()->whereDate('fecha', $fecha)->get(['hora_inicio', 'hora_fin']);
        $intervalos = [];
        foreach ($bloques as $bloque) {
            foreach ($bloque->intervalos() as $intervalo) {
                if (Cita::inicioEsFuturo($fecha, $intervalo['inicio']) && ! $ocupadas->contains(fn (Cita $cita) => $cita->hora_inicio < $intervalo['fin'].':00' && $cita->hora_fin > $intervalo['inicio'].':00')) {
                    $intervalos[] = [...$intervalo, 'bloque_id' => $bloque->id];
                }
            }
        }

        return $intervalos;
    }
}
