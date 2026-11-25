<?php

namespace App\Http\Controllers;

use App\Http\Requests\BuscarComprobanteParticularRequest;
use App\Models\PagoParticular;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

class ComprobanteParticularController extends Controller
{
    public function index(): View
    {
        return view('pagos-particulares.comprobantes.index');
    }

    public function buscar(BuscarComprobanteParticularRequest $request): Response
    {
        $pago = PagoParticular::query()
            ->join('liquidaciones as liquidacion', 'liquidacion.id', '=', 'pagos_particulares.liquidacion_id')
            ->join('atenciones_medicas as atencion', 'atencion.id', '=', 'liquidacion.atencion_medica_id')
            ->join('citas as cita', 'cita.id', '=', 'atencion.cita_id')
            ->join('historias_clinicas as historia', 'historia.id', '=', 'atencion.historia_clinica_id')
            ->join('pacientes as paciente', 'paciente.id', '=', 'cita.paciente_id')
            ->where('pagos_particulares.numero_recibo', $request->validated('numero_recibo'))
            ->where('liquidacion.modalidad', 'PARTICULAR')
            ->where('cita.modalidad', 'PARTICULAR')->whereNull('cita.remision_id')
            ->whereColumn('atencion.medico_id', 'cita.medico_id')
            ->whereColumn('historia.paciente_id', 'cita.paciente_id')
            ->whereColumn('pagos_particulares.valor_pagado', 'liquidacion.valor_total')
            ->first([
                'pagos_particulares.numero_recibo', 'pagos_particulares.fecha_pago', 'pagos_particulares.metodo_pago',
                'pagos_particulares.valor_pagado', 'pagos_particulares.estado',
                'paciente.nombres', 'paciente.apellidos', 'paciente.tipo_documento', 'paciente.numero_documento',
            ]);

        if ($pago === null) {
            return response()->view('pagos-particulares.comprobantes.index', ['error' => 'No se encontró el recibo solicitado.'], 404);
        }

        return response()->view('pagos-particulares.comprobantes.show', ['pago' => $pago])
            ->header('Referrer-Policy', 'no-referrer');
    }
}
