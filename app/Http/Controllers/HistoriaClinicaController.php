<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarHistoriaClinicaRequest;
use App\Models\Paciente;
use App\Services\ConsultarHistoriaClinica;
use Illuminate\View\View;

class HistoriaClinicaController extends Controller
{
    public function __invoke(ConsultarHistoriaClinicaRequest $request, Paciente $paciente, ConsultarHistoriaClinica $consulta): View
    {
        $filtros = $request->validated();
        $medico = $request->user()->medico()->habilitado()->firstOrFail();

        return view('pacientes.historia.show', [
            'paciente' => $paciente,
            'filtros' => $filtros,
            ...$consulta->consultar($paciente, $medico, $filtros),
        ]);
    }
}
