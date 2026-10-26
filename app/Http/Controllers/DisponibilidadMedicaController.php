<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveDisponibilidadRequest;
use App\Models\Auditoria;
use App\Models\DisponibilidadMedica;
use App\Models\Medico;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DisponibilidadMedicaController extends Controller
{
    public function index(Request $request): View
    {
        return view('disponibilidad.index', ['bloques' => $request->user()->medico()->firstOrFail()->disponibilidades()->orderBy('fecha')->orderBy('hora_inicio')->get()]);
    }

    public function store(SaveDisponibilidadRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $medico = $this->lockOwnMedico($request->user());
            $data = $request->validated();
            $data['hora_inicio'] .= ':00';
            $data['hora_fin'] .= ':00';
            foreach ($data['fechas'] as $fecha) {
                $this->validateOverlap($medico, $fecha, $data['hora_inicio'], $data['hora_fin']);
                $bloque = $medico->disponibilidades()->create(['fecha' => $fecha, ...Arr::only($data, ['hora_inicio', 'hora_fin', 'duracion_minutos'])]);
                $this->audit($request->user(), $bloque, 'DISPONIBILIDAD_REGISTRADA');
            }
        });

        return redirect()->route('disponibilidad.index')->with('status', 'Disponibilidad registrada correctamente.');
    }

    public function edit(DisponibilidadMedica $disponibilidad): View
    {
        return view('disponibilidad.edit', compact('disponibilidad'));
    }

    /**
     * CA-060-03 pendiente de Citas: RF-024/026 deben compartir el bloqueo Médico
     * y la edición deberá comprobar sus intervalos no cancelados antes de guardar.
     */
    public function update(SaveDisponibilidadRequest $request, DisponibilidadMedica $disponibilidad): RedirectResponse
    {
        DB::transaction(function () use ($request, $disponibilidad): void {
            $medico = $this->lockOwnMedico($request->user());
            $bloque = $medico->disponibilidades()->lockForUpdate()->findOrFail($disponibilidad->id);
            Gate::authorize('update', $bloque);
            $data = Arr::only($request->validated(), ['fecha', 'hora_inicio', 'hora_fin', 'duracion_minutos']);
            $data['hora_inicio'] .= ':00';
            $data['hora_fin'] .= ':00';
            $this->validateOverlap($medico, $data['fecha'], $data['hora_inicio'], $data['hora_fin'], $bloque->id);
            $bloque->fill($data);
            if ($bloque->isDirty()) {
                $bloque->save();
                $this->audit($request->user(), $bloque, 'DISPONIBILIDAD_ACTUALIZADA');
            }
        });

        return redirect()->route('disponibilidad.index')->with('status', 'Disponibilidad guardada correctamente.');
    }

    /**
     * Protocolo común de registro/edición: serializa incluso el primer bloque.
     * En REPEATABLE READ, estas dos lecturas bloqueantes deben preceder a
     * cualquier lectura consistente de la transacción. La reautorización abre
     * el snapshot después de adquirir el mutex, incluso si hubo que esperar.
     * Autenticación, binding y validación previos no forman parte de esta transacción.
     */
    private function lockOwnMedico(User $user): Medico
    {
        $actor = User::query()->lockForUpdate()->findOrFail($user->id);
        $medico = $actor->medico()->lockForUpdate()->first();
        Gate::forUser($actor)->authorize('create', DisponibilidadMedica::class);

        return $medico;
    }

    /**
     * El mutex Médico ya excluye otras escrituras propias. La lectura MVCC ve
     * los commits anteriores al mutex y las inserciones propias multifecha;
     * bloquear este rango introduciría gap locks compartidos entre médicos.
     */
    private function validateOverlap(Medico $medico, string $fecha, string $inicio, string $fin, ?int $excepto = null): void
    {
        $query = $medico->disponibilidades()->whereDate('fecha', $fecha)->where('hora_inicio', '<', $fin)->where('hora_fin', '>', $inicio);
        if ($excepto !== null) {
            $query->whereKeyNot($excepto);
        }
        if ($query->first() !== null) {
            throw ValidationException::withMessages(['hora_inicio' => 'El bloque se solapa con otra disponibilidad de esa fecha.']);
        }
    }

    private function audit(User $actor, DisponibilidadMedica $bloque, string $accion): void
    {
        Auditoria::query()->create([
            'user_id' => $actor->id, 'accion' => $accion, 'entidad' => 'DisponibilidadMedica', 'registro_id' => $bloque->id,
            'fecha_hora' => now(), 'resultado' => 'EXITO',
            'descripcion' => 'Médico #'.$bloque->medico_id.'; bloque '.$bloque->fecha->toDateString().' '.$bloque->hora_inicio.'-'.$bloque->hora_fin.'; duración '.$bloque->duracion_minutos.' minutos.',
        ]);
    }
}
