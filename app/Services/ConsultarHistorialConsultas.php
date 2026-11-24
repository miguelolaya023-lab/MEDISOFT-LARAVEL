<?php

namespace App\Services;

use App\Models\AtencionMedica;
use App\Models\Medico;
use App\Models\Paciente;
use Illuminate\Pagination\LengthAwarePaginator;

class ConsultarHistorialConsultas
{
    public function __construct(private ConsultarAtencionesClinicas $consultaAtenciones) {}

    /**
     * @param  array{fechaInicial: string, fechaFinal: string, page?: int|string}  $filtros
     * @return LengthAwarePaginator<int, AtencionMedica>
     */
    public function consultar(Paciente $paciente, Medico $medico, array $filtros): LengthAwarePaginator
    {
        return $this->consultaAtenciones->consulta($paciente, $medico, $paciente->historiaClinica()->first(), $filtros)
            ->select(['id', 'cita_id', 'medico_id', 'fecha_hora_inicio', 'estado'])
            ->paginate(10)->appends($filtros);
    }

    public function detalle(Paciente $paciente, Medico $medico, string $atencionId): AtencionMedica
    {
        return $this->consultaAtenciones
            ->conDetalle($this->consultaAtenciones->consulta($paciente, $medico, $paciente->historiaClinica()->first()))
            ->findOrFail($atencionId);
    }
}
