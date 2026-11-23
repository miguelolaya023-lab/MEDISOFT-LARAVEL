<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarAgendaDiariaRequest;
use App\Models\Cita;
use App\Models\Medico;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AgendaDiariaController extends Controller
{
    public function __invoke(ConsultarAgendaDiariaRequest $request): View
    {
        $usuario = $request->user();
        $puedeSeleccionarMedico = $usuario->can('consultarAgendaAjena', Cita::class);
        $medicos = Medico::query()
            ->when(! $puedeSeleccionarMedico, fn ($query) => $query->where('user_id', $usuario->id))
            ->with('user:id,name,nombres,apellidos')
            ->orderBy('id')->get(['id', 'user_id']);
        $medicoId = $request->validated('medico_id');
        $medico = $puedeSeleccionarMedico
            ? $medicos->firstWhere('id', (int) $medicoId)
            : $medicos->first();

        if (! $puedeSeleccionarMedico) {
            abort_if($medico === null || ($medicoId !== null && $medico->id !== (int) $medicoId), 403);
        } elseif ($medicoId !== null && $medico === null) {
            throw ValidationException::withMessages(['medico_id' => 'Seleccione un médico existente.'])
                ->redirectTo(route('agenda.diaria.index'));
        }

        $hoy = CarbonImmutable::now('America/Bogota');
        $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $request->validated('fecha', $hoy->toDateString()), $hoy->timezone);
        $citas = $medico?->citas()
            ->whereDate('fecha', $fecha->toDateString())
            ->with('paciente:id,nombres,apellidos')
            ->orderBy('hora_inicio')->orderBy('id')
            ->get(['id', 'paciente_id', 'fecha', 'hora_inicio', 'hora_fin', 'modalidad', 'estado']) ?? collect();

        return view('agenda.diaria', compact('fecha', 'medicos', 'medico', 'citas', 'puedeSeleccionarMedico'));
    }
}
