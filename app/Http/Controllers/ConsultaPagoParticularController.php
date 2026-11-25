<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarPagosParticularesRequest;
use App\Services\ConsultarPagosParticulares;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ConsultaPagoParticularController extends Controller
{
    public function __invoke(ConsultarPagosParticularesRequest $request, ConsultarPagosParticulares $consulta): Response
    {
        $filtros = array_filter($request->safe()->except('page'), static fn ($valor): bool => $valor !== null);

        try {
            $datos = $consulta->consultar($filtros);
        } catch (ValidationException $error) {
            if ($request->expectsJson()) {
                throw $error;
            }

            return response()->view('pagos-particulares.consultas.index', [
                'filtros' => $filtros, 'errores' => $error->validator->errors()->all(), 'pagos' => null, 'total' => null,
            ], 422, ['Referrer-Policy' => 'no-referrer']);
        }

        return response()->view('pagos-particulares.consultas.index', ['filtros' => $filtros] + $datos)
            ->header('Referrer-Policy', 'no-referrer');
    }
}
