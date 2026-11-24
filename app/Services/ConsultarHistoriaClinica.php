<?php

namespace App\Services;

use App\Models\AntecedenteClinico;
use App\Models\AtencionMedica;
use App\Models\HistoriaClinica;
use App\Models\Medico;
use App\Models\Paciente;
use Illuminate\Pagination\LengthAwarePaginator;

class ConsultarHistoriaClinica
{
    public const ZONA_CONSULTORIO = ConsultarAtencionesClinicas::ZONA_CONSULTORIO;

    public function __construct(private ConsultarAtencionesClinicas $consultaAtenciones) {}

    /**
     * @param  array{fechaInicial?: ?string, fechaFinal?: ?string, page?: int|string, antecedentes?: int|string}  $filtros
     * @return array{historia: HistoriaClinica, antecedentes: LengthAwarePaginator<int, AntecedenteClinico>, atenciones: LengthAwarePaginator<int, AtencionMedica>}
     */
    public function consultar(Paciente $paciente, Medico $medico, array $filtros): array
    {
        $historia = $paciente->historiaClinica()->first();
        abort_if($historia === null, 404, 'Este paciente no tiene una historia clínica vinculada.');

        $antecedentes = $historia->antecedentes()
            ->with(['medico:id,user_id', 'medico.user:id,name'])
            ->orderByDesc('fecha_registro')->orderByDesc('id')
            ->paginate(10, pageName: 'antecedentes')->appends($filtros);

        $atenciones = $this->consultaAtenciones
            ->conDetalle($this->consultaAtenciones->consulta($paciente, $medico, $historia, $filtros))
            ->paginate(10)->appends($filtros);

        return compact('historia', 'antecedentes', 'atenciones');
    }
}
