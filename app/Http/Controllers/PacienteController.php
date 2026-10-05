<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListPacienteRequest;
use App\Http\Requests\SearchPacienteRequest;
use App\Http\Requests\StorePacienteRequest;
use App\Models\Auditoria;
use App\Models\Paciente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PacienteController extends Controller
{
    public function index(ListPacienteRequest $request): View
    {
        $filtros = $request->validated();
        $query = Paciente::query();
        foreach (['tipo_paciente', 'ciudad', 'estado'] as $campo) {
            if (isset($filtros[$campo]) && $filtros[$campo] !== '') {
                if ($campo === 'ciudad') {
                    $query->whereLike($campo, '%'.$filtros[$campo].'%', caseSensitive: false);
                } else {
                    $query->where($campo, $filtros[$campo]);
                }
            }
        }

        return view('pacientes.index', [
            'pacientes' => $query->orderBy('id')->get(), 'filtros' => $filtros,
            'puedeConsultar' => $request->user()->can('search', Paciente::class),
        ]);
    }

    public function search(SearchPacienteRequest $request): View
    {
        $buscar = trim((string) ($request->validated('buscar') ?? ''));
        $pacientes = $buscar === '' ? collect() : Paciente::query()->where(function (Builder $query) use ($buscar): void {
            $query->where('numero_documento', 'like', '%'.$buscar.'%')
                ->orWhere(function (Builder $query) use ($buscar): void {
                    foreach (preg_split('/\s+/u', $buscar) as $palabra) {
                        $query->where(function (Builder $query) use ($palabra): void {
                            $query->where('nombres', 'like', '%'.$palabra.'%')->orWhere('apellidos', 'like', '%'.$palabra.'%');
                        });
                    }
                });
        })->orderBy('id')->get();

        return view('pacientes.search', ['pacientes' => $pacientes, 'buscar' => $buscar, 'puedeConsultar' => true]);
    }

    public function create(): View
    {
        return view('pacientes.create');
    }

    public function store(StorePacienteRequest $request): RedirectResponse
    {
        try {
            $paciente = DB::transaction(function () use ($request): Paciente {
                $paciente = Paciente::query()->create($request->validated());
                $paciente->historiaClinica()->create(['fecha_apertura' => now()]);
                Auditoria::query()->create([
                    'user_id' => $request->user()->getKey(), 'accion' => 'PACIENTE_CREADO',
                    'entidad' => 'Paciente', 'registro_id' => $paciente->getKey(), 'fecha_hora' => now(),
                    'resultado' => 'EXITO', 'descripcion' => 'Ficha administrativa e historia vacía creadas.',
                ]);

                return $paciente;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! Paciente::query()->where('tipo_documento', $request->validated('tipo_documento'))->where('numero_documento', $request->validated('numero_documento'))->exists()) {
                throw $exception;
            }
            throw ValidationException::withMessages(['numero_documento' => StorePacienteRequest::DUPLICATE_DOCUMENT_MESSAGE]);
        }

        $destino = $request->user()->can('view', $paciente) ? route('pacientes.show', $paciente) : route('pacientes.create');

        return redirect($destino)->with('status', 'Paciente registrado correctamente. Código: '.$paciente->getKey().'.');
    }

    public function show(Paciente $paciente): View
    {
        return view('pacientes.show', compact('paciente'));
    }
}
