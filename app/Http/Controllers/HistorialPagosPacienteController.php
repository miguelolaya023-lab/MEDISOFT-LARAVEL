<?php

namespace App\Http\Controllers;

use App\Http\Requests\BuscarPacienteHistorialPagosRequest;
use App\Http\Requests\ConsultarHistorialPagosRequest;
use App\Models\Paciente;
use App\Services\ConsultarHistorialPagos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class HistorialPagosPacienteController extends Controller
{
    public function buscar(): Response
    {
        return response()->view('pacientes.historial-pagos.buscar')->header('Referrer-Policy', 'no-referrer');
    }

    public function localizar(BuscarPacienteHistorialPagosRequest $request): RedirectResponse|Response
    {
        $documento = $request->validated('documento');
        $paciente = Paciente::query()->where('tipo_documento', $request->validated('tipo_documento'))
            ->where('numero_documento', $documento);
        if (DB::getDriverName() === 'mysql') {
            $paciente->whereRaw('CAST(numero_documento AS BINARY) = ?', [$documento]);
        }
        $id = $paciente->value('id');
        if ($id === null) {
            return response()->view('pacientes.historial-pagos.buscar', [
                'error' => 'No se encontró un paciente con ese documento.',
            ], 404)->header('Referrer-Policy', 'no-referrer');
        }

        return redirect()->route('pacientes.historial-pagos.formulario', $id)->header('Referrer-Policy', 'no-referrer');
    }

    public function formulario(string $pacienteId): Response
    {
        $paciente = $this->paciente($pacienteId);

        return response()->view('pacientes.historial-pagos.index', [
            'paciente' => $paciente, 'pacienteId' => $paciente->id, 'filtros' => [], 'historial' => null,
        ])->header('Referrer-Policy', 'no-referrer');
    }

    public function consultar(ConsultarHistorialPagosRequest $request, string $pacienteId, ConsultarHistorialPagos $consulta): Response
    {
        $paciente = $this->paciente($pacienteId);
        $filtros = array_filter($request->safe()->except('page'), static fn ($valor): bool => $valor !== null);

        return response()->view('pacientes.historial-pagos.index', [
            'paciente' => $paciente, 'pacienteId' => $paciente->id, 'filtros' => $filtros,
            'historial' => $consulta->consultar($paciente, $filtros),
        ])->header('Referrer-Policy', 'no-referrer');
    }

    private function paciente(string $pacienteId): Paciente
    {
        $id = filter_var($pacienteId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);

        return Paciente::query()->select('id', 'nombres', 'apellidos', 'tipo_documento', 'numero_documento')->findOrFail($id);
    }
}
