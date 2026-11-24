<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuardarAtencionClinicaRequest;
use App\Models\AtencionMedica;
use App\Models\Auditoria;
use App\Models\User;
use App\Services\ContextoAtencionClinica;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class AtencionClinicaController extends Controller
{
    public function __construct(private ContextoAtencionClinica $contexto) {}

    public function show(AtencionMedica $atencion): View
    {
        $atencion->load(['cita.paciente', 'historiaClinica']);
        $cita = $atencion->cita;
        $this->contexto->comprobarVinculos($atencion, $cita);

        return view('atenciones.show', ['cita' => $cita, 'atencion' => $atencion, 'errorInicio' => null]);
    }

    public function motivo(GuardarAtencionClinicaRequest $request, AtencionMedica $atencion): RedirectResponse
    {
        return $this->guardarTexto($request, $atencion, 'motivo_consulta', 'registrarMotivo', 'MOTIVO_CONSULTA_REGISTRADO');
    }

    public function diagnostico(GuardarAtencionClinicaRequest $request, AtencionMedica $atencion): RedirectResponse
    {
        return $this->guardarTexto($request, $atencion, 'diagnostico_principal', 'registrarDiagnostico', 'DIAGNOSTICO_PRINCIPAL_REGISTRADO');
    }

    public function finalizar(GuardarAtencionClinicaRequest $request, AtencionMedica $atencion): RedirectResponse
    {
        return $this->actualizar($request, $atencion, 'finalizar', function (AtencionMedica $actual, User $actor) use ($request): string {
            if ($actual->estado === 'FINALIZADA') {
                return 'La atención ya está finalizada.';
            }
            $this->contexto->exigirEnCurso($actual);
            $pendientes = [];
            foreach (['motivo_consulta' => 'Falta el motivo clínico de consulta.', 'diagnostico_principal' => 'Falta el diagnóstico principal.'] as $campo => $mensaje) {
                if (Str::trim((string) $actual->$campo) === '') {
                    $pendientes[$campo] = $mensaje;
                }
            }
            if ($pendientes) {
                throw ValidationException::withMessages($pendientes);
            }
            $this->comprobarVersion($actual, 'cierre', $request->validated('version', ''));

            $fin = now();
            if ($actual->cita->modalidad === 'EPS') {
                $this->consumirRemision($actual, $actor, $fin);
            }
            $actual->forceFill([
                'estado' => 'FINALIZADA', 'fecha_hora_fin' => $fin, 'observacion_final' => $request->validated('observacion_final'),
            ]);
            $actual->cita->estado = 'ATENDIDA';
            throw_unless($actual->save() && $actual->cita->save(), RuntimeException::class, 'No se pudo guardar el cierre clínico.');
            $this->auditar($actual, $actor, 'ATENCION_FINALIZADA', $fin);

            return 'Atención finalizada correctamente.';
        });
    }

    private function consumirRemision(AtencionMedica $atencion, User $actor, CarbonInterface $fin): void
    {
        $cita = $atencion->cita;
        $remision = $cita->remision;
        $fechaInicio = $atencion->fecha_hora_inicio->toImmutable()->timezone('America/Bogota')->toDateString();
        $anterior = $remision?->estadoActual($fin->toImmutable()->timezone('America/Bogota')->toDateString());
        if ($remision === null || $remision->paciente_id !== $cita->paciente_id || $cita->remision_reservada !== $remision->id
            || ! in_array($anterior, ['VIGENTE', 'VENCIDA'], true)
            || $fechaInicio < $remision->fecha_remision->toDateString() || $fechaInicio > $remision->vigencia_hasta->toDateString()) {
            throw ValidationException::withMessages(['remision' => 'La remisión debe corresponder a esta cita y haber sido válida al iniciar la atención.']);
        }

        $remision->registrarCambioEstado($actor->id, $anterior, 'USADA', 'Finalización de la atención #'.$atencion->id.'.', $fin);
        $remision->estado = 'USADA';
        throw_unless($remision->save(), RuntimeException::class, 'No se pudo guardar el consumo de la remisión.');
    }

    private function guardarTexto(GuardarAtencionClinicaRequest $request, AtencionMedica $atencion, string $campo, string $habilidad, string $accion): RedirectResponse
    {
        return $this->actualizar($request, $atencion, $habilidad, function (AtencionMedica $actual, User $actor) use ($request, $campo, $accion): string {
            $this->contexto->exigirEnCurso($actual);
            $texto = $request->validated($campo);
            if ($texto === $actual->$campo) {
                return 'El texto ya estaba guardado.';
            }
            $this->comprobarVersion($actual, $campo, $request->validated('version'));
            $actual->$campo = $texto;
            throw_unless($actual->save(), RuntimeException::class, 'No se pudo guardar el texto clínico.');
            $this->auditar($actual, $actor, $accion, now());

            return 'Texto clínico guardado.';
        });
    }

    /** @param Closure(AtencionMedica, User): string $operacion */
    private function actualizar(GuardarAtencionClinicaRequest $request, AtencionMedica $atencion, string $habilidad, Closure $operacion): RedirectResponse
    {
        $destino = route('atenciones.clinica.show', $atencion);
        try {
            $mensaje = $this->contexto->escribir($request->user(), $atencion, $habilidad, $operacion, bloquearRemision: $habilidad === 'finalizar');
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($destino);
        }

        return redirect($destino)->with('status', $mensaje);
    }

    private function comprobarVersion(AtencionMedica $atencion, string $campo, string $version): void
    {
        if (! hash_equals($atencion->versionClinica($campo), $version)) {
            throw ValidationException::withMessages(['version' => 'Los datos clínicos cambiaron. Revise la versión actual antes de confirmar.']);
        }
    }

    private function auditar(AtencionMedica $atencion, User $actor, string $accion, CarbonInterface $fecha): void
    {
        $registro = Auditoria::query()->create([
            'user_id' => $actor->id, 'accion' => $accion, 'entidad' => 'AtencionMedica', 'registro_id' => $atencion->id,
            'fecha_hora' => $fecha, 'resultado' => 'EXITO', 'descripcion' => 'Atención #'.$atencion->id.' de la cita #'.$atencion->cita_id.'.',
        ]);
        throw_unless($registro->exists, RuntimeException::class, 'No se pudo registrar la auditoría clínica.');
    }
}
