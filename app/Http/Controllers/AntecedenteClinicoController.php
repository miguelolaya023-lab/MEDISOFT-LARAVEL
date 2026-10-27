<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveAntecedenteClinicoRequest;
use App\Models\AntecedenteClinico;
use App\Models\Auditoria;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AntecedenteClinicoController extends Controller
{
    public function index(Paciente $paciente): View
    {
        $historia = $paciente->historiaClinica()->firstOrFail();

        return view('antecedentes.index', [
            'paciente' => $paciente, 'historia' => $historia,
            'antecedentes' => $historia->antecedentes()->orderByDesc('id')->get(),
            'puedeEscribir' => Gate::allows('create', [AntecedenteClinico::class, $paciente]),
        ]);
    }

    public function store(SaveAntecedenteClinicoRequest $request, Paciente $paciente): RedirectResponse
    {
        DB::transaction(function () use ($request, $paciente): void {
            $actor = $this->lockClinicalActor($request->user());
            $paciente = $this->lockActivePatient($paciente);
            Gate::forUser($actor)->authorize('create', [AntecedenteClinico::class, $paciente]);
            $historia = $paciente->historiaClinica()->firstOrFail();
            $antecedente = $historia->antecedentes()->make($request->validated());
            $antecedente->forceFill(['medico_id' => $actor->medico->getKey(), 'fecha_registro' => now()])->save();
            $this->audit($actor, $antecedente, 'ANTECEDENTE_REGISTRADO');
        });

        return redirect()->route('antecedentes.index', $paciente)->with('status', 'Antecedente clínico registrado correctamente.');
    }

    public function edit(Paciente $paciente, int $antecedente): View
    {
        $antecedente = $paciente->historiaClinica()->firstOrFail()->antecedentes()->findOrFail($antecedente);
        Gate::authorize('update', $antecedente);

        return view('antecedentes.edit', compact('paciente', 'antecedente'));
    }

    public function update(SaveAntecedenteClinicoRequest $request, Paciente $paciente, int $antecedente): RedirectResponse
    {
        DB::transaction(function () use ($request, $paciente, $antecedente): void {
            $actor = $this->lockClinicalActor($request->user());
            $paciente = $this->lockActivePatient($paciente);
            $antecedente = $paciente->historiaClinica()->firstOrFail()->antecedentes()->lockForUpdate()->findOrFail($antecedente);
            Gate::forUser($actor)->authorize('update', $antecedente);
            $antecedente->fill($request->validated());
            if (! $antecedente->isDirty(['descripcion', 'tipo'])) {
                return;
            }
            $revision = $antecedente->revisiones()->make([
                'tipo' => $antecedente->getRawOriginal('tipo'), 'descripcion' => $antecedente->getRawOriginal('descripcion'),
            ]);
            $revision->forceFill(['medico_id' => $actor->medico->getKey(), 'fecha_correccion' => now()])->save();
            $antecedente->save();
            $this->audit($actor, $antecedente, 'ANTECEDENTE_CORREGIDO');
        });

        return redirect()->route('antecedentes.index', $paciente)->with('status', 'Antecedente clínico guardado correctamente.');
    }

    /**
     * User S por PK antes de Paciente y de las FK Médico/User de escritura.
     * Impide el ciclo con RF-060 (User X -> Médico X) sin bloquear en exclusiva
     * al actor ni al autor histórico: las nuevas FK pertenecen al actor actual.
     */
    private function lockClinicalActor(User $user): User
    {
        return User::query()->sharedLock()->findOrFail($user->getKey());
    }

    /** Comparte el bloqueo de paciente con RF-012 para revalidar su estado. */
    private function lockActivePatient(Paciente $paciente): Paciente
    {
        $paciente = Paciente::query()->lockForUpdate()->findOrFail($paciente->getKey());
        if ($paciente->estado !== 'ACTIVO') {
            throw ValidationException::withMessages(['descripcion' => 'El paciente debe estar ACTIVO para registrar o corregir antecedentes.']);
        }

        return $paciente;
    }

    private function audit(User $user, AntecedenteClinico $antecedente, string $accion): void
    {
        Auditoria::query()->create([
            'user_id' => $user->getKey(), 'accion' => $accion, 'entidad' => 'AntecedenteClinico',
            'registro_id' => $antecedente->getKey(), 'fecha_hora' => now(), 'resultado' => 'EXITO',
            'descripcion' => 'Operación de antecedentes clínicos; historia '.$antecedente->historia_clinica_id.'.',
        ]);
    }
}
