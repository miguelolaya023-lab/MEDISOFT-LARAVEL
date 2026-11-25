<?php

namespace App\Services;

use App\Models\Paciente;
use App\Models\PagoParticular;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConsultarPagosParticulares
{
    /**
     * @param  array{fecha_inicial?: ?string, fecha_final?: ?string, tipo_documento?: ?string, documento?: ?string, estado?: ?string}  $filtros
     * @return array{pagos: LengthAwarePaginator, total: string}
     */
    public function consultar(array $filtros): array
    {
        return $this->paginar($filtros);
    }

    /** @return array{pagos: LengthAwarePaginator, total: string} */
    public function delPaciente(Paciente $paciente, array $filtros): array
    {
        return $this->paginar($filtros, $paciente);
    }

    /** @return array{pagos: LengthAwarePaginator, total: string} */
    private function paginar(array $filtros, ?Paciente $paciente = null): array
    {
        return DB::transaction(function () use ($filtros, $paciente): array {
            $pacienteId = $paciente?->id;
            if ($paciente === null && isset($filtros['documento'])) {
                $pacienteId = $this->resolverPaciente($filtros['documento'], $filtros['tipo_documento'] ?? null);
            }

            $consulta = $this->filtrada($filtros);
            if ($pacienteId !== null) {
                $consulta->where('cita.paciente_id', $pacienteId);
            } elseif ($paciente === null && isset($filtros['documento'])) {
                $consulta->whereRaw('1 = 0');
            } elseif ($paciente === null && isset($filtros['tipo_documento'])) {
                $consulta->where('paciente.tipo_documento', $filtros['tipo_documento']);
            }

            return [
                'total' => $this->totalVigente((clone $consulta)->where('pagos_particulares.estado', 'REGISTRADO')),
                'pagos' => $consulta->orderByDesc('pagos_particulares.fecha_pago')->orderByDesc('pagos_particulares.id')
                    ->paginate(20, [
                        'pagos_particulares.numero_recibo', 'pagos_particulares.fecha_pago', 'pagos_particulares.metodo_pago',
                        'pagos_particulares.valor_pagado', 'pagos_particulares.estado', 'paciente.nombres', 'paciente.apellidos',
                    ])->appends($filtros),
            ];
        });
    }

    private function resolverPaciente(string $documento, ?string $tipo): ?int
    {
        $coincidencias = Paciente::query()->where('numero_documento', $documento);
        if (DB::getDriverName() === 'mysql') {
            $coincidencias->whereRaw('CAST(numero_documento AS BINARY) = ?', [$documento]);
        }
        if ($tipo !== null) {
            $coincidencias->where('tipo_documento', $tipo);
        }

        $ids = $coincidencias->limit(2)->pluck('id');
        if ($ids->count() > 1) {
            throw ValidationException::withMessages(['tipo_documento' => 'Hay más de un paciente con ese número. Seleccione el tipo de documento.']);
        }

        return $ids->first();
    }

    /** @return Builder<PagoParticular> */
    private function filtrada(array $filtros): Builder
    {
        $consulta = PagoParticular::query()
            ->join('liquidaciones as liquidacion', 'liquidacion.id', '=', 'pagos_particulares.liquidacion_id')
            ->join('atenciones_medicas as atencion', 'atencion.id', '=', 'liquidacion.atencion_medica_id')
            ->join('citas as cita', 'cita.id', '=', 'atencion.cita_id')
            ->join('historias_clinicas as historia', 'historia.id', '=', 'atencion.historia_clinica_id')
            ->join('pacientes as paciente', 'paciente.id', '=', 'cita.paciente_id')
            ->where('liquidacion.modalidad', 'PARTICULAR')
            ->where('cita.modalidad', 'PARTICULAR')->whereNull('cita.remision_id')
            ->whereColumn('atencion.medico_id', 'cita.medico_id')
            ->whereColumn('historia.paciente_id', 'cita.paciente_id')
            ->whereColumn('pagos_particulares.valor_pagado', 'liquidacion.valor_total');

        if (isset($filtros['fecha_inicial'], $filtros['fecha_final'])) {
            if (DB::getDriverName() === 'sqlite') {
                $consulta->whereDate('pagos_particulares.fecha_pago', '>=', $filtros['fecha_inicial'])
                    ->whereDate('pagos_particulares.fecha_pago', '<=', $filtros['fecha_final']);
            } else {
                $consulta->whereBetween('pagos_particulares.fecha_pago', [$filtros['fecha_inicial'], $filtros['fecha_final']]);
            }
        }

        if (isset($filtros['estado'])) {
            $consulta->where('pagos_particulares.estado', $filtros['estado']);
        }

        return $consulta;
    }

    /** @param Builder<PagoParticular> $consulta */
    private function totalVigente(Builder $consulta): string
    {
        if (DB::getDriverName() !== 'sqlite') {
            return (string) BigDecimal::of($consulta->sum('pagos_particulares.valor_pagado'))->toScale(2);
        }

        $total = BigDecimal::zero();
        foreach ($consulta->toBase()->selectRaw('CAST(pagos_particulares.valor_pagado AS TEXT) AS importe')->cursor() as $fila) {
            $total = $total->plus($fila->importe);
        }

        return (string) $total->toScale(2);
    }
}
