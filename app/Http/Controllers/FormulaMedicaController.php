<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistrarFormulaMedicaRequest;
use App\Models\AtencionMedica;
use App\Models\Auditoria;
use App\Models\FormulaMedica;
use App\Models\Paciente;
use App\Models\User;
use App\Services\ContextoAtencionClinica;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class FormulaMedicaController extends Controller
{
    public function __construct(private ContextoAtencionClinica $contexto) {}

    public function atenciones(Request $request): View
    {
        $medico = $request->user()->medico()->habilitado()->firstOrFail();
        $atenciones = $this->contexto->atencionesDelMedico($medico)->paginate(20);

        return view('atenciones.formulas.atenciones', compact('atenciones'));
    }

    public function index(AtencionMedica $atencion): View
    {
        $atencion->load(['cita.paciente', 'historiaClinica']);
        $this->contexto->comprobarVinculos($atencion, $atencion->cita);
        $formulas = $atencion->formulasMedicas()->with('detalles')->orderByDesc('id')->paginate(20);
        $editable = $this->contexto->estaEnCurso($atencion) && $atencion->cita->paciente->estado === 'ACTIVO';

        return view('atenciones.formulas.index', compact('atencion', 'formulas', 'editable'));
    }

    public function store(RegistrarFormulaMedicaRequest $request, AtencionMedica $atencion): RedirectResponse
    {
        $clave = strtolower($request->validated('clave_envio'));
        $observacion = $request->validated('observacion_formula');
        $detalles = array_map(static fn (array $detalle): array => [
            'medicamento' => $detalle['medicamento'],
            'dosis' => $detalle['dosis'],
            'frecuencia' => $detalle['frecuencia'] ?? null,
            'duracion' => $detalle['duracion'] ?? null,
            'indicaciones' => $detalle['indicaciones'] ?? null,
        ], $request->validated('detalles_formula'));

        $mensaje = $this->contexto->escribir($request->user(), $atencion, 'registrarFormulaMedica',
            fn (AtencionMedica $actual, User $actor, Paciente $paciente): string => $this->registrar($actual, $actor, $paciente, $clave, $observacion, $detalles));

        return redirect()->route('atenciones.clinica.formulas.index', $atencion)->with('status', $mensaje);
    }

    /** @param list<array{medicamento:string,dosis:string,frecuencia:?string,duracion:?string,indicaciones:?string}> $detalles */
    private function registrar(AtencionMedica $atencion, User $actor, Paciente $paciente, string $clave, ?string $observacion, array $detalles): string
    {
        $this->contexto->exigirEnCurso($atencion);
        if ($paciente->estado !== 'ACTIVO') {
            throw ValidationException::withMessages(['atencion' => 'El paciente debe estar ACTIVO para registrar una fórmula.']);
        }

        $contenido = json_encode([$atencion->id, $clave, $observacion, $detalles], JSON_THROW_ON_ERROR);
        $huella = hash_hmac('sha256', $contenido, config('app.key'));
        $existente = $atencion->formulasMedicas()->where('clave_envio', $clave)->lockForUpdate()->first();
        if ($existente !== null) {
            if (! hash_equals($existente->huella_envio, $huella)) {
                throw ValidationException::withMessages(['clave_envio' => 'Este envío ya se utilizó. Abra el formulario para registrar una fórmula nueva.']);
            }

            return 'La fórmula ya estaba registrada.';
        }

        $fecha = now();
        $formula = new FormulaMedica([
            'fecha_emision' => $fecha, 'observacion' => $observacion, 'clave_envio' => $clave, 'huella_envio' => $huella,
        ]);
        throw_unless($atencion->formulasMedicas()->save($formula), RuntimeException::class, 'No se pudo guardar la fórmula.');
        foreach ($detalles as $posicion => $detalle) {
            $registro = $formula->detalles()->make([...$detalle, 'posicion' => $posicion + 1]);
            throw_unless($formula->detalles()->save($registro), RuntimeException::class, 'No se pudo guardar un detalle de la fórmula.');
        }
        $auditoria = Auditoria::query()->create([
            'user_id' => $actor->id, 'accion' => 'FORMULA_MEDICA_REGISTRADA',
            'entidad' => 'FormulaMedica', 'registro_id' => $formula->id,
            'fecha_hora' => $fecha, 'resultado' => 'EXITO', 'descripcion' => 'Atención #'.$atencion->id.'.',
        ]);
        throw_unless($auditoria->exists, RuntimeException::class, 'No se pudo registrar la auditoría clínica.');

        return 'Fórmula médica registrada.';
    }
}
