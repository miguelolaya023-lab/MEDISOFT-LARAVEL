<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarHistorialConsultasRequest;
use App\Models\Paciente;
use App\Services\ConsultarHistorialConsultas;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HistorialConsultasController extends Controller
{
    public function formulario(Paciente $paciente): View
    {
        return view('pacientes.historial.index', ['paciente' => $paciente, 'filtros' => [], 'atenciones' => null]);
    }

    public function consultar(ConsultarHistorialConsultasRequest $request, Paciente $paciente, ConsultarHistorialConsultas $consulta): View
    {
        $filtros = $request->validated();
        $medico = $request->user()->medico()->habilitado()->firstOrFail();

        return view('pacientes.historial.index', [
            'paciente' => $paciente,
            'filtros' => $filtros,
            'atenciones' => $consulta->consultar($paciente, $medico, $filtros),
        ]);
    }

    public function detalle(Request $request, Paciente $paciente, string $atencionId, ConsultarHistorialConsultas $consulta): View
    {
        $medico = $request->user()->medico()->habilitado()->firstOrFail();

        return view('pacientes.historial.detalle', [
            'paciente' => $paciente,
            'atencion' => $consulta->detalle($paciente, $medico, $atencionId),
        ]);
    }
}
