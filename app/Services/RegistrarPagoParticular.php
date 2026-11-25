<?php

namespace App\Services;

use App\Models\AtencionMedica;
use App\Models\Auditoria;
use App\Models\Liquidacion;
use App\Models\PagoParticular;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RegistrarPagoParticular
{
    public function __construct(private readonly ContextoAtencionClinica $contexto) {}

    public function listar(): LengthAwarePaginator
    {
        return Liquidacion::query()
            ->join('atenciones_medicas as atencion', 'atencion.id', '=', 'liquidaciones.atencion_medica_id')
            ->join('citas as cita', 'cita.id', '=', 'atencion.cita_id')
            ->join('historias_clinicas as historia', 'historia.id', '=', 'atencion.historia_clinica_id')
            ->join('pacientes as paciente', 'paciente.id', '=', 'cita.paciente_id')
            ->where('liquidaciones.modalidad', 'PARTICULAR')
            ->where('liquidaciones.valor_total', '>', '0.00')
            ->whereColumn('cita.medico_id', 'atencion.medico_id')
            ->whereColumn('historia.paciente_id', 'cita.paciente_id')
            ->where('atencion.estado', 'FINALIZADA')
            ->whereNotNull('atencion.fecha_hora_fin')
            ->where('cita.estado', 'ATENDIDA')
            ->where('cita.modalidad', 'PARTICULAR')
            ->whereNull('cita.remision_id')
            ->whereDoesntHave('pagoVigente')
            ->orderByDesc('liquidaciones.id')
            ->paginate(20, ['liquidaciones.id', 'liquidaciones.valor_total', 'paciente.nombres', 'paciente.apellidos']);
    }

    public function consultar(Liquidacion $liquidacion): AtencionMedica
    {
        $atencion = $this->buscarAtencion($liquidacion);
        $this->contexto->comprobarVinculos($atencion, $atencion->cita);
        $this->exigirParticular($liquidacion, $atencion);
        $atencion->load('cita.paciente:id,nombres,apellidos');

        return $atencion;
    }

    public function firmarIntencion(User $actor, Liquidacion $liquidacion, string $clave, ?PagoParticular $ultimo): string
    {
        $generacion = $this->generacion($ultimo);

        return $generacion.'.'.$this->firma($actor, $liquidacion, $clave, $generacion);
    }

    private function generacion(?PagoParticular $pago): string
    {
        return $pago === null ? '0-SIN_PAGO' : $pago->id.'-'.$pago->estado;
    }

    private function firma(User $actor, Liquidacion $liquidacion, string $clave, string $generacion): string
    {
        $contenido = ['RF-034-intencion-v1', $actor->id, $liquidacion->id, $clave, $generacion];

        return hash_hmac('sha256', json_encode($contenido, JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function comprobarFirma(User $actor, Liquidacion $liquidacion, string $clave, string $intencion): string
    {
        [$generacion, $firma] = array_pad(explode('.', $intencion, 2), 2, '');
        if (! hash_equals($this->firma($actor, $liquidacion, $clave, $generacion), $firma)) {
            throw ValidationException::withMessages(['intencion' => 'La confirmación no es válida. Vuelva a abrir el formulario de pago.']);
        }

        return $generacion;
    }

    /** @param array{valor_pagado: string, fecha_pago: string, metodo_pago: string, observacion?: ?string, clave_envio: string, intencion?: ?string} $datos */
    public function registrar(User $actor, Liquidacion $liquidacion, array $datos): string
    {
        $atencion = $this->buscarAtencion($liquidacion);

        return $this->contexto->escribir($actor, $atencion, 'registrarPagoParticular', function (AtencionMedica $actual, User $responsable) use ($liquidacion, $datos): string {
            $registro = Liquidacion::query()->lockForUpdate()->findOrFail($liquidacion->id);
            $this->exigirParticular($registro, $actual);

            [$entera, $decimal] = array_pad(explode('.', $datos['valor_pagado'], 2), 2, '');
            $importe = $entera.'.'.str_pad($decimal, 2, '0');
            if ($importe !== $registro->valor_total) {
                throw ValidationException::withMessages(['valor_pagado' => 'El valor pagado debe coincidir exactamente con el total liquidado.']);
            }

            $clave = strtolower($datos['clave_envio']);
            $generacion = isset($datos['intencion'])
                ? $this->comprobarFirma($responsable, $registro, $clave, $datos['intencion'])
                : null;
            $huella = hash_hmac('sha256', json_encode([
                $registro->id, $importe, $datos['fecha_pago'], $datos['metodo_pago'], $datos['observacion'] ?? null,
            ], JSON_THROW_ON_ERROR), config('app.key'));
            $anterior = $registro->pagosParticulares()->where('clave_envio', $clave)->lockForUpdate()->first();
            if ($anterior !== null) {
                if (! hash_equals($anterior->huella_envio, $huella)) {
                    throw ValidationException::withMessages(['solicitud' => 'La confirmación ya se usó con otros datos. Abra un formulario nuevo.']);
                }

                return (string) $anterior->id;
            }

            if ($generacion === null) {
                throw ValidationException::withMessages(['intencion' => 'Vuelva a abrir el formulario y confirme el pago nuevamente.']);
            }

            $fechaRegistro = now();
            $hoy = $fechaRegistro->copy()->timezone('America/Bogota')->toDateString();
            if ($datos['fecha_pago'] < $this->primeraFechaPago($actual) || $datos['fecha_pago'] > $hoy) {
                throw ValidationException::withMessages(['fecha_pago' => 'La fecha de pago debe estar entre la fecha local de finalización de la atención y hoy.']);
            }
            $vigente = $registro->pagoVigente()->lockForUpdate()->first();
            if ($vigente !== null) {
                return (string) $vigente->id;
            }

            $ultimo = $registro->pagosParticulares()->orderByDesc('id')->lockForUpdate()->first(['id', 'estado']);
            if ($generacion !== $this->generacion($ultimo)) {
                throw ValidationException::withMessages(['intencion' => 'El historial de pagos cambió. Vuelva a abrir el formulario y confirme nuevamente.']);
            }

            $pago = $registro->pagosParticulares()->create([
                'numero_recibo' => 'PP-'.Str::ulid(),
                'valor_pagado' => $registro->valor_total,
                'fecha_pago' => $datos['fecha_pago'],
                'fecha_registro' => $fechaRegistro,
                'metodo_pago' => $datos['metodo_pago'],
                'observacion' => $datos['observacion'] ?? null,
                'estado' => 'REGISTRADO',
                'creado_por' => $responsable->id,
                'clave_envio' => $clave,
                'huella_envio' => $huella,
            ]);
            throw_unless($pago->exists, RuntimeException::class, 'No se pudo registrar el pago particular.');

            $auditoria = Auditoria::query()->create([
                'user_id' => $responsable->id, 'accion' => 'PAGO_PARTICULAR_REGISTRADO',
                'entidad' => 'PagoParticular', 'registro_id' => $pago->id, 'fecha_hora' => $fechaRegistro,
                'resultado' => 'EXITO', 'descripcion' => 'Se registró el pago particular.',
            ]);
            throw_unless($auditoria->exists, RuntimeException::class, 'No se pudo auditar el pago particular.');

            return (string) $pago->id;
        });
    }

    public function primeraFechaPago(AtencionMedica $atencion): string
    {
        return $atencion->fecha_hora_fin->copy()->timezone('America/Bogota')->toDateString();
    }

    private function buscarAtencion(Liquidacion $liquidacion): AtencionMedica
    {
        return $liquidacion->atencion()
            ->select('id', 'cita_id', 'medico_id', 'historia_clinica_id', 'estado', 'fecha_hora_fin')
            ->with(['cita:id,paciente_id,medico_id,estado,modalidad,remision_id', 'historiaClinica:id,paciente_id'])
            ->firstOrFail();
    }

    private function exigirParticular(Liquidacion $liquidacion, AtencionMedica $atencion): void
    {
        abort_unless($liquidacion->atencion_medica_id === $atencion->id && $liquidacion->modalidad === 'PARTICULAR'
            && preg_match('/\A[0-9]{1,10}\.[0-9]{2}\z/', $liquidacion->valor_total) && $liquidacion->valor_total !== '0.00'
            && $atencion->estado === 'FINALIZADA' && $atencion->fecha_hora_fin !== null
            && $atencion->cita->estado === 'ATENDIDA' && $atencion->cita->modalidad === 'PARTICULAR'
            && $atencion->cita->remision_id === null, 422, 'La liquidación no corresponde a una atención particular finalizada y con total positivo.');
    }
}
