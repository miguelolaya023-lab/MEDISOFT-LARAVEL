<?php

namespace App\Http\Controllers;

use App\Http\Requests\IniciarAtencionMedicaRequest;
use App\Models\AtencionMedica;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\Eps;
use App\Models\HistoriaClinica;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\RemisionEps;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AtencionMedicaController extends Controller
{
    /** Ampliación de D-08 pendiente de ratificación académica. */
    private const ANTICIPACION_MAXIMA_MINUTOS = 10;

    public function index(Request $request): View
    {
        $hoy = now('America/Bogota')->toDateString();
        $medico = $request->user()->medico()->firstOrFail();
        $citas = $medico->citas()->with('paciente:id,nombres,apellidos')
            ->where(function (Builder $citas) use ($hoy): void {
                $citas->where(fn (Builder $programadas) => $programadas->whereDate('fecha', $hoy)->where('estado', 'PROGRAMADA'))
                    ->orWhere('estado', 'EN_ATENCION');
            })
            ->orderBy('fecha')->orderBy('hora_inicio')->orderBy('id')->get();

        return view('atenciones.index', compact('citas'));
    }

    public function show(Request $request, string $citaId): View
    {
        $medico = $request->user()->medico()->firstOrFail();
        $cita = $medico->citas()->with(['paciente.historiaClinica', 'remision.eps', 'atencion'])->findOrFail($citaId);
        $atencion = $this->atencionEnCurso($cita, $cita->paciente);
        $errorInicio = null;
        if ($atencion === null) {
            try {
                $this->validarInicio($cita, $cita->paciente, now(), $cita->remision, $cita->remision?->eps);
            } catch (ValidationException $exception) {
                $errorInicio = $exception->getMessage();
            }
        }

        return view('atenciones.show', compact('cita', 'atencion', 'errorInicio'));
    }

    public function store(IniciarAtencionMedicaRequest $request, string $citaId): RedirectResponse
    {
        $medicoAutenticado = $request->user()->medico()->firstOrFail();
        $referencia = $medicoAutenticado->citas()->with('remision')->findOrFail($citaId);
        $destino = route('atenciones.show', $citaId);

        try {
            $atencion = DB::transaction(function () use ($request, $referencia): AtencionMedica {
                /** Orden de agenda: cuenta, paciente, EPS, remisión, médico y cita. */
                $actor = User::query()->sharedLock()->findOrFail($request->user()->id);
                $paciente = Paciente::query()->sharedLock()->findOrFail($referencia->paciente_id);
                $eps = $referencia->remision ? Eps::query()->sharedLock()->findOrFail($referencia->remision->eps_id) : null;
                $remision = $referencia->remision ? RemisionEps::query()->lockForUpdate()->findOrFail($referencia->remision_id) : null;
                $medico = Medico::query()->lockForUpdate()->findOrFail($referencia->medico_id);
                $cita = Cita::query()->lockForUpdate()->findOrFail($referencia->id);

                abort_unless($medico->user_id === $actor->id && $cita->medico_id === $medico->id, 404);
                Gate::forUser($actor)->authorize('startAttendance', Cita::class);
                if ($cita->paciente_id !== $paciente->id || $cita->remision_id !== $remision?->id) {
                    throw ValidationException::withMessages(['cita' => 'Los datos de la cita cambiaron. Revise la cita antes de iniciar.']);
                }
                $existente = $this->atencionEnCurso($cita, $paciente);
                if ($existente !== null) {
                    return $existente;
                }

                $inicio = now();
                $historia = $this->validarInicio($cita, $paciente, $inicio, $remision, $eps);
                $atencion = new AtencionMedica;
                $atencion->forceFill([
                    'medico_id' => $medico->id, 'historia_clinica_id' => $historia->id, 'fecha_hora_inicio' => $inicio,
                ]);
                $cita->atencion()->save($atencion);
                $cita->forceFill(['estado' => 'EN_ATENCION'])->save();
                Auditoria::query()->create([
                    'user_id' => $actor->id, 'accion' => 'ATENCION_INICIADA', 'entidad' => 'AtencionMedica', 'registro_id' => $atencion->id,
                    'fecha_hora' => $inicio, 'resultado' => 'EXITO',
                    'descripcion' => 'Cita #'.$cita->id.': PROGRAMADA a EN_ATENCION. Atención EN_CURSO.',
                ]);

                return $atencion;
            }, attempts: 3);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($destino);
        }

        return redirect($destino)->with('status', $atencion->wasRecentlyCreated
            ? 'Atención médica iniciada correctamente.'
            : 'La atención de esta cita ya está en curso.');
    }

    private function atencionEnCurso(Cita $cita, Paciente $paciente): ?AtencionMedica
    {
        if ($cita->estado !== 'EN_ATENCION' || $paciente->estado !== 'ACTIVO') {
            return null;
        }

        $atencion = $cita->atencion;

        return $atencion?->estado === 'EN_CURSO' && $atencion->medico_id === $cita->medico_id
            && $atencion->historia_clinica_id === $paciente->historiaClinica?->id ? $atencion : null;
    }

    private function validarInicio(Cita $cita, Paciente $paciente, CarbonInterface $inicio, ?RemisionEps $remision, ?Eps $eps): HistoriaClinica
    {
        if ($cita->estado !== 'PROGRAMADA') {
            throw ValidationException::withMessages(['cita' => 'Solo se puede iniciar una cita PROGRAMADA.']);
        }
        if ($cita->atencion !== null) {
            throw ValidationException::withMessages(['cita' => 'La cita ya tiene una atención registrada.']);
        }
        if ($paciente->estado !== 'ACTIVO') {
            throw ValidationException::withMessages(['cita' => 'El paciente debe estar ACTIVO para iniciar la atención.']);
        }
        $inicioLocal = $inicio->copy()->timezone('America/Bogota');
        $fechaLocal = $inicioLocal->toDateString();
        if ($cita->fecha->toDateString() !== $fechaLocal) {
            throw ValidationException::withMessages(['cita' => 'La atención se inicia en la fecha de la cita, según la hora de Colombia.']);
        }
        $inicioPermitido = CarbonImmutable::parse($fechaLocal.' '.$cita->hora_inicio, 'America/Bogota')
            ->subMinutes(self::ANTICIPACION_MAXIMA_MINUTOS);
        if ($inicioLocal->lessThan($inicioPermitido)) {
            throw ValidationException::withMessages([
                'cita' => 'La atención se puede iniciar desde las '.$inicioPermitido->format('H:i:s').' (hora de Colombia).',
            ]);
        }
        if ($cita->modalidad === 'EPS' && ($remision === null || $remision->paciente_id !== $paciente->id
            || $eps?->estado !== 'ACTIVA' || $eps->id !== $remision->eps_id
            || ! RemisionEps::query()->whereKey($remision->id)->agendablePara($fechaLocal, $cita->id)->exists())) {
            throw ValidationException::withMessages(['cita' => 'La cita EPS requiere una remisión vigente del mismo paciente y una EPS activa.']);
        }

        return $paciente->historiaClinica ?? throw ValidationException::withMessages(['cita' => 'El paciente no tiene una historia clínica vinculada.']);
    }
}
