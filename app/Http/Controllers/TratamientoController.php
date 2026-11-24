<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuardarTratamientoRequest;
use App\Models\AtencionMedica;
use App\Models\Auditoria;
use App\Models\Paciente;
use App\Models\Tratamiento;
use App\Models\User;
use App\Services\ContextoAtencionClinica;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class TratamientoController extends Controller
{
    public function __construct(private ContextoAtencionClinica $contexto) {}

    public function atenciones(Request $request): View
    {
        $medico = $request->user()->medico()->habilitado()->firstOrFail();
        $atenciones = $this->contexto->atencionesDelMedico($medico)->paginate(20);

        return view('atenciones.tratamientos.atenciones', compact('atenciones'));
    }

    public function index(AtencionMedica $atencion): View
    {
        $atencion->load(['cita.paciente', 'historiaClinica']);
        $this->contexto->comprobarVinculos($atencion, $atencion->cita);
        $tratamientos = $atencion->tratamientos()->orderByDesc('id')->paginate(20);
        $editable = $this->contexto->estaEnCurso($atencion) && $atencion->cita->paciente->estado === 'ACTIVO';

        return view('atenciones.tratamientos.index', compact('atencion', 'tratamientos', 'editable'));
    }

    public function store(GuardarTratamientoRequest $request, AtencionMedica $atencion): RedirectResponse
    {
        return $this->escribir($request, $atencion, function (AtencionMedica $actual, User $actor) use ($request): string {
            $clave = strtolower($request->validated('clave_envio'));
            $texto = $request->validated('descripcion_tratamiento');
            $huella = hash_hmac('sha256', $actual->id.':'.$clave.':'.$texto, config('app.key'));
            $existente = $actual->tratamientos()->where('clave_envio', $clave)->lockForUpdate()->first();
            if ($existente !== null) {
                if (! hash_equals($existente->huella_envio, $huella)) {
                    throw ValidationException::withMessages(['clave_envio' => 'Este envío ya se utilizó. Abra el formulario para registrar un tratamiento nuevo.']);
                }

                return 'El tratamiento ya estaba registrado.';
            }

            $tratamiento = new Tratamiento([
                'descripcion' => $texto, 'fecha_registro' => now(), 'clave_envio' => $clave, 'huella_envio' => $huella,
            ]);
            throw_unless($actual->tratamientos()->save($tratamiento), RuntimeException::class, 'No se pudo guardar el tratamiento.');
            $this->auditar($tratamiento, $actor, 'TRATAMIENTO_REGISTRADO');

            return 'Tratamiento registrado.';
        });
    }

    public function update(GuardarTratamientoRequest $request, AtencionMedica $atencion, int $tratamiento): RedirectResponse
    {
        return $this->escribir($request, $atencion, function (AtencionMedica $actual, User $actor) use ($request, $tratamiento): string {
            $registro = $actual->tratamientos()->lockForUpdate()->findOrFail($tratamiento);
            if (! hash_equals($registro->versionClinica(), $request->validated('version'))) {
                throw ValidationException::withMessages(['version' => 'El tratamiento cambió. Revise la versión actual antes de corregirlo.']);
            }

            $texto = $request->validated('descripcion_tratamiento');
            if ($texto === $registro->descripcion) {
                return 'El tratamiento no cambió.';
            }

            $revision = $registro->revisiones()->make([
                'user_id' => $actor->id, 'descripcion_anterior' => $registro->descripcion,
                'fecha_correccion' => now(), 'revision' => $registro->revision,
            ]);
            throw_unless($registro->revisiones()->save($revision), RuntimeException::class, 'No se pudo guardar la revisión.');
            $registro->descripcion = $texto;
            $registro->revision++;
            throw_unless($registro->save(), RuntimeException::class, 'No se pudo corregir el tratamiento.');
            $this->auditar($registro, $actor, 'TRATAMIENTO_CORREGIDO');

            return 'Tratamiento corregido.';
        });
    }

    /** @param Closure(AtencionMedica, User): string $operacion */
    private function escribir(GuardarTratamientoRequest $request, AtencionMedica $atencion, Closure $operacion): RedirectResponse
    {
        $destino = route('atenciones.clinica.tratamientos.index', $atencion);
        try {
            $mensaje = $this->contexto->escribir($request->user(), $atencion, 'registrarTratamiento', function (AtencionMedica $actual, User $actor, Paciente $paciente) use ($operacion): string {
                $this->contexto->exigirEnCurso($actual);
                if ($paciente->estado !== 'ACTIVO') {
                    throw ValidationException::withMessages(['atencion' => 'El paciente debe estar ACTIVO para registrar o corregir tratamientos.']);
                }

                return $operacion($actual, $actor);
            });
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($destino);
        }

        return redirect($destino)->with('status', $mensaje);
    }

    private function auditar(Tratamiento $tratamiento, User $actor, string $accion): void
    {
        $registro = Auditoria::query()->create([
            'user_id' => $actor->id, 'accion' => $accion, 'entidad' => 'Tratamiento', 'registro_id' => $tratamiento->id,
            'fecha_hora' => now(), 'resultado' => 'EXITO', 'descripcion' => 'Atención #'.$tratamiento->atencion_medica_id.'.',
        ]);
        throw_unless($registro->exists, RuntimeException::class, 'No se pudo registrar la auditoría clínica.');
    }
}
