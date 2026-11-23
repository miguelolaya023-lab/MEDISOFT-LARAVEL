<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistrarNotificacionCitaRequest;
use App\Models\Cita;
use App\Services\EmitirNotificacionCita;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NotificacionCitaController extends Controller
{
    public function index(Request $request, EmitirNotificacionCita $emision): View
    {
        try {
            $filtros = Validator::make($request->query(), ['cita_id' => ['sometimes', 'required', 'integer', 'min:1']])->validate();
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('notificaciones.citas.index'));
        }

        if (! isset($filtros['cita_id'])) {
            return view('notificaciones.citas.index', ['cita' => null]);
        }

        $cita = Cita::query()->findOrFail($filtros['cita_id']);
        Gate::authorize('notificarCita', $cita);
        $cita->load('paciente:id,correo_electronico');
        $vistaPrevia = $emision->vistaPrevia($cita, $request->user());
        $historial = $cita->notificaciones()->orderByDesc('fecha_intento')->orderByDesc('id')
            ->paginate(15, ['id', 'estado', 'fecha_intento'])->appends(['cita_id' => $cita->id]);

        return view('notificaciones.citas.index', compact('cita', 'vistaPrevia', 'historial'));
    }

    public function store(RegistrarNotificacionCitaRequest $request, Cita $cita, EmitirNotificacionCita $emision): RedirectResponse
    {
        try {
            $intento = $emision->registrar($cita, $request->user(), $request->validated('vista_previa'));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('notificaciones.citas.index', ['cita_id' => $cita->id]));
        }

        return redirect()->route('notificaciones.citas.show', [$cita, $intento]);
    }

    public function show(Cita $cita, string $intento): View
    {
        $evidencia = $cita->notificaciones()->findOrFail($intento);

        return view('notificaciones.citas.show', compact('cita', 'evidencia'));
    }
}
