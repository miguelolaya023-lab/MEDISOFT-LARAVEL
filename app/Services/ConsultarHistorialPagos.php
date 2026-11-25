<?php

namespace App\Services;

use App\Models\Paciente;
use App\Models\PagoParticular;
use Illuminate\Pagination\LengthAwarePaginator;

class ConsultarHistorialPagos
{
    public function __construct(private readonly ConsultarPagosParticulares $particulares) {}

    /** @param array{fecha_inicial:string,fecha_final:string,estado?:string,tipo_cobro?:string} $filtros */
    public function consultar(Paciente $paciente, array $filtros): HistorialPagosResultado
    {
        if (($filtros['tipo_cobro'] ?? null) === 'CUOTA_MODERADORA') {
            $vacio = new LengthAwarePaginator([], 0, 20, LengthAwarePaginator::resolveCurrentPage(), [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
            ]);

            return new HistorialPagosResultado($vacio->appends($filtros), '0.00', '0.00');
        }

        $resultado = $this->particulares->delPaciente($paciente, $filtros);
        $movimientos = $resultado['pagos']->through(static fn (PagoParticular $pago): array => [
            'fecha' => $pago->fecha_pago->format('d/m/Y'),
            'tipo' => 'PARTICULAR',
            'comprobante' => $pago->numero_recibo,
            'valor' => $pago->valor_pagado,
            'estado' => $pago->estado,
        ])->appends($filtros);

        return new HistorialPagosResultado($movimientos, $resultado['total'], '0.00');
    }
}
