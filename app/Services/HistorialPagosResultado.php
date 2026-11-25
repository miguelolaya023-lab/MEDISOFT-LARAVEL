<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class HistorialPagosResultado
{
    /** @param LengthAwarePaginator<int, array{fecha:string,tipo:string,comprobante:string,valor:string,estado:string}> $movimientos */
    public function __construct(
        public LengthAwarePaginator $movimientos,
        public string $totalParticular,
        public string $totalCuotaModeradora,
    ) {}
}
