<?php

namespace App\Services;

use App\Models\AtencionMedica;
use App\Models\HistoriaClinica;
use App\Models\Medico;
use App\Models\Paciente;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class ConsultarAtencionesClinicas
{
    public const ZONA_CONSULTORIO = 'America/Bogota';

    /**
     * @param  array{fechaInicial?: ?string, fechaFinal?: ?string}  $filtros
     * @return Builder<AtencionMedica>
     */
    public function consulta(Paciente $paciente, Medico $medico, ?HistoriaClinica $historia, array $filtros = []): Builder
    {
        $atenciones = AtencionMedica::query()
            ->where('historia_clinica_id', $historia?->id)
            ->where('medico_id', $medico->id)
            ->whereHas('cita', fn (Builder $citas) => $citas->where('paciente_id', $paciente->id)->where('medico_id', $medico->id))
            ->with(['cita:id,modalidad', 'medico:id,user_id', 'medico.user:id,name']);

        if (isset($filtros['fechaInicial'])) {
            $inicio = CarbonImmutable::parse($filtros['fechaInicial'], self::ZONA_CONSULTORIO);
            $atenciones->where('fecha_hora_inicio', '>=', $inicio->utc());
        }
        if (isset($filtros['fechaFinal'])) {
            $finExclusivo = CarbonImmutable::parse($filtros['fechaFinal'], self::ZONA_CONSULTORIO)->addDay();
            $atenciones->where('fecha_hora_inicio', '<', $finExclusivo->utc());
        }

        return $atenciones->orderByDesc('fecha_hora_inicio')->orderByDesc('id');
    }

    /**
     * @param  Builder<AtencionMedica>  $atenciones
     * @return Builder<AtencionMedica>
     */
    public function conDetalle(Builder $atenciones): Builder
    {
        return $atenciones
            ->select(['id', 'historia_clinica_id', 'cita_id', 'medico_id', 'fecha_hora_inicio', 'estado', 'motivo_consulta', 'diagnostico_principal'])
            ->with([
                'tratamientos' => fn ($tratamientos) => $tratamientos
                    ->select(['id', 'atencion_medica_id', 'descripcion', 'fecha_registro'])->orderBy('id'),
                'tratamientos.revisiones' => fn ($revisiones) => $revisiones->orderBy('revision'),
                'tratamientos.revisiones.autor:id,name',
                'formulasMedicas' => fn ($formulas) => $formulas
                    ->select(['id', 'atencion_medica_id', 'fecha_emision', 'observacion'])->orderBy('id'),
                'formulasMedicas.detalles',
            ]);
    }
}
