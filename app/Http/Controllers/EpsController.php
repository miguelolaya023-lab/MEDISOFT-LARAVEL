<?php

namespace App\Http\Controllers;

use App\Http\Requests\QueryEpsRequest;
use App\Http\Requests\SaveEpsRequest;
use App\Models\Auditoria;
use App\Models\Eps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EpsController extends Controller
{
    public function index(QueryEpsRequest $request): View
    {
        $filtros = $request->validated();
        $query = Eps::query();
        if (($filtros['ciudad'] ?? '') !== '') {
            $patron = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filtros['ciudad']).'%';
            $query->whereRaw("LOWER(ciudad) LIKE LOWER(?) ESCAPE '!'", [$patron]);
        }
        if (($filtros['estado'] ?? '') !== '') {
            $query->where('estado', $filtros['estado']);
        }

        return view('eps.index', [
            'entidades' => $query->orderBy('id')->get(), 'filtros' => $filtros,
            'puedeConsultar' => $request->user()->can('search', Eps::class),
        ]);
    }

    public function search(QueryEpsRequest $request): View
    {
        $buscar = $request->validated('buscar') ?? '';
        $entidades = $buscar === '' ? collect() : Eps::query()->where(function (Builder $query) use ($buscar): void {
            $query->whereLike('nit', '%'.Eps::normalizarNit($buscar).'%')->orWhereLike('nombre', '%'.$buscar.'%');
        })->orderBy('id')->get();

        return view('eps.search', ['entidades' => $entidades, 'buscar' => $buscar, 'puedeConsultar' => true]);
    }

    public function create(): View
    {
        return view('eps.create');
    }

    public function show(Eps $eps): View
    {
        return view('eps.show', compact('eps'));
    }

    public function edit(Eps $eps): View
    {
        return view('eps.edit', compact('eps'));
    }

    public function store(SaveEpsRequest $request): RedirectResponse
    {
        return $this->save($request);
    }

    public function update(SaveEpsRequest $request, Eps $eps): RedirectResponse
    {
        return $this->save($request, $eps);
    }

    /**
     * RF-016: al integrar citas/remisiones, advertir citas futuras sin borrarlas.
     * Las nuevas remisiones/citas EPS deberán revalidar ACTIVA al escribir.
     */
    private function save(SaveEpsRequest $request, ?Eps $eps = null): RedirectResponse
    {
        $creacion = $eps === null;
        try {
            $eps = DB::transaction(function () use ($request, $eps, $creacion): Eps {
                $datos = $request->validated();
                $entidad = $creacion ? new Eps : Eps::query()->lockForUpdate()->findOrFail($eps->getKey());
                $cambiaEstado = ! $creacion && $entidad->estado !== $datos['estado'];
                if ($cambiaEstado && ($datos['motivo'] ?? '') === '') {
                    throw ValidationException::withMessages(['motivo' => 'Ingrese un motivo administrativo para cambiar el estado.']);
                }
                $estadoAnterior = $entidad->estado;
                $entidad->fill(Arr::except($datos, 'motivo'))->save();
                Auditoria::query()->create([
                    'user_id' => $request->user()->getKey(), 'accion' => $creacion ? 'EPS_CREADA' : 'EPS_ACTUALIZADA',
                    'entidad' => 'EPS', 'registro_id' => $entidad->getKey(), 'fecha_hora' => now(), 'resultado' => 'EXITO',
                    'descripcion' => $cambiaEstado
                        ? 'Estado '.$estadoAnterior.' -> '.$entidad->estado.'. Motivo: '.$datos['motivo']
                        : ($creacion ? 'Entidad del catálogo académico creada.' : 'Datos del catálogo actualizados; estado conservado.'),
                ]);

                return $entidad;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $duplicados = Eps::query()->where('nit', $request->validated('nit'));
            if (! $creacion) {
                $duplicados->whereKeyNot($eps->getKey());
            }
            if (! $duplicados->exists()) {
                throw $exception;
            }
            throw ValidationException::withMessages(['nit' => SaveEpsRequest::DUPLICATE_NIT_MESSAGE]);
        }

        $destino = $request->user()->can('view', $eps) ? route('eps.show', $eps) : ($creacion ? route('eps.create') : route('eps.edit', $eps));

        return redirect($destino)->with('status', $creacion ? 'EPS registrada correctamente.' : 'EPS actualizada correctamente.');
    }
}
