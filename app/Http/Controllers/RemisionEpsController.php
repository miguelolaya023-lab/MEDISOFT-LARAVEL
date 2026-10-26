<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeRemisionStateRequest;
use App\Http\Requests\QueryRemisionRequest;
use App\Http\Requests\SaveRemisionRequest;
use App\Models\Auditoria;
use App\Models\CambioEstadoRemision;
use App\Models\Eps;
use App\Models\Paciente;
use App\Models\RemisionEps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RemisionEpsController extends Controller
{
    public function index(QueryRemisionRequest $request): View
    {
        $filters = $request->validated();
        $query = RemisionEps::query()->with(['paciente', 'eps']);
        if (! empty($filters['eps_id'])) {
            $query->where('eps_id', $filters['eps_id']);
        }
        if (! empty($filters['estado'])) {
            $query->conEstado($filters['estado']);
        }
        if (! empty($filters['vigencia_desde'])) {
            $query->whereDate('vigencia_hasta', '>=', $filters['vigencia_desde']);
        }
        if (! empty($filters['vigencia_hasta'])) {
            $query->whereDate('vigencia_hasta', '<=', $filters['vigencia_hasta']);
        }

        return view('remisiones.index', ['remisiones' => $query->orderBy('id')->get(), 'filtros' => $filters,
            'epsOptions' => Eps::query()->orderBy('id')->get(['id', 'nit', 'nombre']),
            'puedeConsultar' => $request->user()->can('search', RemisionEps::class)]);
    }

    public function search(QueryRemisionRequest $request): View
    {
        $filters = $request->validated();
        $buscar = $filters['buscar'] ?? '';
        $query = RemisionEps::query()->with(['paciente', 'eps']);
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $buscar).'%';
        $query->where(fn (Builder $q) => $q->whereRaw("LOWER(numero_remision) LIKE LOWER(?) ESCAPE '!'", [$pattern])
            ->orWhereHas('paciente', fn (Builder $q) => $q->whereRaw("numero_documento LIKE ? ESCAPE '!'", [$pattern])));
        if (! empty($filters['eps_id'])) {
            $query->where('eps_id', $filters['eps_id']);
        }

        return view('remisiones.search', ['remisiones' => $buscar === '' ? collect() : $query->orderBy('id')->get(), 'filtros' => $filters,
            'epsOptions' => Eps::query()->orderBy('id')->get(['id', 'nit', 'nombre']), 'puedeConsultar' => true]);
    }

    public function create(): View
    {
        return view('remisiones.create', [
            'pacientes' => Paciente::query()->where('estado', 'ACTIVO')->orderBy('id')->get(['id', 'tipo_documento', 'numero_documento', 'nombres', 'apellidos']),
            'epsOptions' => Eps::query()->where('estado', 'ACTIVA')->orderBy('id')->get(['id', 'nit', 'nombre']),
        ]);
    }

    public function store(SaveRemisionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        try {
            $remision = DB::transaction(function () use ($data, $request): RemisionEps {
                $paciente = Paciente::query()->lockForUpdate()->findOrFail($data['paciente_id']);
                $eps = Eps::query()->lockForUpdate()->findOrFail($data['eps_id']);
                if ($paciente->estado !== 'ACTIVO') {
                    throw ValidationException::withMessages(['paciente_id' => 'El paciente debe estar ACTIVO para registrar una remisión.']);
                }
                if ($eps->estado !== 'ACTIVA') {
                    throw ValidationException::withMessages(['eps_id' => 'La EPS debe estar ACTIVA para registrar una remisión.']);
                }
                $remision = new RemisionEps($data);
                $remision->estado = $data['vigencia_hasta'] < RemisionEps::hoy() ? 'VENCIDA' : 'VIGENTE';
                $remision->save();
                $this->audit($request->user()->id, $remision, 'REMISION_CREADA', 'Remisión registrada; estado '.$remision->estado.'.');

                return $remision;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! RemisionEps::query()->where('eps_id', $data['eps_id'])->where('numero_remision', $data['numero_remision'])->exists()) {
                throw $exception;
            }
            throw ValidationException::withMessages(['numero_remision' => SaveRemisionRequest::DUPLICATE_MESSAGE]);
        }

        return redirect($request->user()->can('view', $remision) ? route('remisiones.show', $remision) : route('remisiones.create'))->with('status', 'Remisión registrada correctamente.');
    }

    public function show(RemisionEps $remision): View
    {
        return view('remisiones.show', ['remision' => $remision->load(['paciente', 'eps', 'cambiosEstado'])]);
    }

    public function edit(RemisionEps $remision): View
    {
        return view('remisiones.edit', ['remision' => $remision->load(['paciente', 'eps'])]);
    }

    /**
     * RN-07: al integrar CitaEPS/AtencionMedica, bloquear la remisión también
     * desde agenda/atención y validar fechas de citas antes de reducir vigencia.
     * La identidad se conserva; una remisión USADA solo admite observación.
     */
    public function update(SaveRemisionRequest $request, RemisionEps $remision): RedirectResponse
    {
        DB::transaction(function () use ($request, $remision): void {
            $remision = RemisionEps::query()->lockForUpdate()->findOrFail($remision->id);
            $data = $request->validated();
            if ($data['vigencia_hasta'] < $remision->fecha_remision->toDateString()) {
                throw ValidationException::withMessages(['vigencia_hasta' => 'La vigencia no puede ser anterior a la emisión.']);
            }
            if ($remision->estado === 'USADA') {
                $errores = [];
                if ($data['servicio_autorizado'] !== $remision->servicio_autorizado) {
                    $errores['servicio_autorizado'] = 'No se puede modificar el servicio de una remisión USADA; solo se permite corregir la observación.';
                }
                if ($data['vigencia_hasta'] !== $remision->vigencia_hasta->toDateString()) {
                    $errores['vigencia_hasta'] = 'No se puede modificar la vigencia de una remisión USADA; solo se permite corregir la observación.';
                }
                if ($errores !== []) {
                    throw ValidationException::withMessages($errores);
                }
            }
            $anterior = $remision->estadoActual();
            $nuevo = in_array($remision->estado, ['USADA', 'ANULADA'], true) ? $remision->estado : ($data['vigencia_hasta'] < RemisionEps::hoy() ? 'VENCIDA' : 'VIGENTE');
            if ($anterior === 'VENCIDA' && $nuevo === 'VIGENTE' && ($data['motivo'] ?? '') === '') {
                throw ValidationException::withMessages(['motivo' => 'Justifique la corrección de vigencia que vuelve a habilitar la remisión.']);
            }
            $remision->fill(Arr::except($data, 'motivo'));
            $remision->estado = $nuevo;
            if ($anterior === 'VENCIDA' && $nuevo === 'VIGENTE') {
                $this->recordState($remision, $request->user()->id, $anterior, $nuevo, $data['motivo']);
            }
            $remision->save();
            $this->audit($request->user()->id, $remision, 'REMISION_ACTUALIZADA', 'Datos permitidos actualizados; estado '.$nuevo.'.');
        });

        return redirect($request->user()->can('view', $remision) ? route('remisiones.show', $remision) : route('remisiones.edit', $remision))->with('status', 'Remisión actualizada correctamente.');
    }

    public function state(RemisionEps $remision): View
    {
        return view('remisiones.state', ['remision' => $remision->load(['paciente', 'eps'])]);
    }

    /** RN-07: agenda/atención futuras deben validar asociaciones bajo este mismo bloqueo. */
    public function changeState(ChangeRemisionStateRequest $request, RemisionEps $remision): RedirectResponse
    {
        DB::transaction(function () use ($request, $remision): void {
            $remision = RemisionEps::query()->lockForUpdate()->findOrFail($remision->id);
            $anterior = $remision->estadoActual();
            if (! in_array($anterior, ['VIGENTE', 'VENCIDA'], true)) {
                throw ValidationException::withMessages(['estado' => 'El estado actual no permite anular esta remisión.']);
            }
            $this->recordState($remision, $request->user()->id, $anterior, 'ANULADA', $request->validated('motivo'));
            $remision->estado = 'ANULADA';
            $remision->save();
            $this->audit($request->user()->id, $remision, 'REMISION_ANULADA', 'Cambio de estado '.$anterior.' -> ANULADA.');
        });

        return redirect($request->user()->can('view', $remision) ? route('remisiones.show', $remision) : route('remisiones.state', $remision))->with('status', 'Remisión anulada correctamente.');
    }

    private function recordState(RemisionEps $remision, int $responsable, string $anterior, string $nuevo, string $motivo): void
    {
        $remision->motivo_cambio_estado = $motivo;
        CambioEstadoRemision::query()->create(['remision_id' => $remision->id, 'user_id' => $responsable, 'estado_anterior' => $anterior, 'estado_nuevo' => $nuevo, 'motivo' => $motivo, 'fecha_cambio' => now()]);
    }

    private function audit(int $responsable, RemisionEps $remision, string $accion, string $resumen): void
    {
        Auditoria::query()->create(['user_id' => $responsable, 'accion' => $accion, 'entidad' => 'RemisionEPS', 'registro_id' => $remision->id, 'fecha_hora' => now(), 'resultado' => 'EXITO', 'descripcion' => $resumen]);
    }
}
