<?php

namespace App\Services;

use App\Models\AtencionMedica;
use App\Models\Auditoria;
use App\Models\Liquidacion;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LiquidarConsultaParticular
{
    public function __construct(private readonly ContextoAtencionClinica $contexto) {}

    public function listar(): LengthAwarePaginator
    {
        return DB::table('atenciones_medicas as atencion')
            ->join('citas as cita', 'cita.id', '=', 'atencion.cita_id')
            ->join('historias_clinicas as historia', 'historia.id', '=', 'atencion.historia_clinica_id')
            ->join('pacientes as paciente', 'paciente.id', '=', 'cita.paciente_id')
            ->leftJoin('liquidaciones as liquidacion', 'liquidacion.atencion_medica_id', '=', 'atencion.id')
            ->whereColumn('cita.medico_id', 'atencion.medico_id')
            ->whereColumn('historia.paciente_id', 'cita.paciente_id')
            ->where('atencion.estado', 'FINALIZADA')
            ->whereNotNull('atencion.fecha_hora_fin')
            ->where('cita.estado', 'ATENDIDA')
            ->where('cita.modalidad', 'PARTICULAR')
            ->whereNull('cita.remision_id')
            ->where(function ($query): void {
                $query->where(function ($consulta): void {
                    $consulta->whereNull('liquidacion.id')->where('cita.valor_base', '>', '0.00');
                })->orWhere('liquidacion.modalidad', 'PARTICULAR');
            })
            ->orderByDesc('cita.fecha')->orderByDesc('atencion.id')
            ->paginate(20, ['atencion.id', 'cita.fecha', 'paciente.nombres', 'paciente.apellidos', 'liquidacion.id as liquidacion_id']);
    }

    /** @return array{atencion: AtencionMedica, liquidacion: ?Liquidacion, importe: string, version: ?string} */
    public function consultar(User $actor, string $atencionId): array
    {
        $atencion = $this->buscarAtencion($atencionId);
        $atencion->load('cita.paciente:id,nombres,apellidos');
        $this->contexto->comprobarVinculos($atencion, $atencion->cita);
        abort_if($error = $this->incompatibilidad($atencion), 422, $error);

        $liquidacion = Liquidacion::query()->where('atencion_medica_id', $atencion->id)->withExists('pagoVigente')->first();
        abort_if($liquidacion !== null && $liquidacion->modalidad !== 'PARTICULAR', 409, 'La atención tiene una liquidación de otra modalidad.');
        $importe = $liquidacion?->valor_total ?? $this->importePositivo($atencion->cita->getRawOriginal('valor_base'));
        abort_if($importe === null, 422, 'La cita debe tener un valor base positivo válido.');

        return [
            'atencion' => $atencion,
            'liquidacion' => $liquidacion,
            'importe' => $importe,
            'version' => $liquidacion === null ? $this->versionImporte($actor, $atencion, $importe) : null,
        ];
    }

    public function liquidar(User $actor, string $atencionId, string $version): void
    {
        $atencion = $this->buscarAtencion($atencionId);

        $this->contexto->escribir($actor, $atencion, 'liquidarParticular', function (AtencionMedica $actual, User $responsable) use ($version): string {
            if ($error = $this->incompatibilidad($actual)) {
                throw ValidationException::withMessages(['atencion' => $error]);
            }

            $existente = Liquidacion::query()->where('atencion_medica_id', $actual->id)->lockForUpdate()->first();
            if ($existente !== null) {
                abort_unless($existente->modalidad === 'PARTICULAR', 409, 'La atención tiene una liquidación de otra modalidad.');

                return (string) $existente->id;
            }

            $importe = $this->importePositivo($actual->cita->getRawOriginal('valor_base'));
            if ($importe === null) {
                throw ValidationException::withMessages(['version_importe' => 'La cita debe tener un valor base positivo válido.']);
            }
            if (! hash_equals($this->versionImporte($responsable, $actual, $importe), $version)) {
                throw ValidationException::withMessages(['version_importe' => 'El importe o la confirmación ya no están vigentes. Revise el importe y confirme nuevamente.']);
            }

            $fecha = now();
            $liquidacion = Liquidacion::query()->create([
                'atencion_medica_id' => $actual->id,
                'modalidad' => 'PARTICULAR',
                'valor_total' => $importe,
                'fecha_liquidacion' => $fecha,
                'creado_por' => $responsable->id,
            ]);
            if (! $liquidacion->exists) {
                throw new RuntimeException('No se pudo crear la liquidación.');
            }

            $auditoria = Auditoria::query()->create([
                'user_id' => $responsable->id,
                'accion' => 'LIQUIDACION_PARTICULAR_REGISTRADA',
                'entidad' => 'Liquidacion',
                'registro_id' => $liquidacion->id,
                'fecha_hora' => $fecha,
                'resultado' => 'EXITO',
                'descripcion' => 'Se creó la liquidación particular.',
            ]);
            if (! $auditoria->exists) {
                throw new RuntimeException('No se pudo registrar la auditoría de liquidación.');
            }

            return (string) $liquidacion->id;
        });
    }

    private function buscarAtencion(string $atencionId): AtencionMedica
    {
        $id = filter_var($atencionId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);

        return AtencionMedica::query()
            ->select('id', 'cita_id', 'medico_id', 'historia_clinica_id', 'estado', 'fecha_hora_fin')
            ->with(['cita:id,paciente_id,medico_id,fecha,estado,modalidad,remision_id,valor_base', 'historiaClinica:id,paciente_id'])
            ->findOrFail($id);
    }

    private function incompatibilidad(AtencionMedica $atencion): ?string
    {
        if ($atencion->cita->modalidad !== 'PARTICULAR' || $atencion->cita->remision_id !== null) {
            return 'Solo se pueden liquidar consultas particulares sin remisión EPS.';
        }

        if ($atencion->estado !== 'FINALIZADA' || $atencion->fecha_hora_fin === null || $atencion->cita->estado !== 'ATENDIDA') {
            return 'La atención debe estar finalizada y su cita debe estar atendida.';
        }

        return null;
    }

    private function importePositivo(mixed $valor): ?string
    {
        if (! preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/', (string) $valor, $partes)) {
            return null;
        }

        $importe = $partes[1].'.'.str_pad($partes[2] ?? '', 2, '0');

        return $importe === '0.00' ? null : $importe;
    }

    public function versionImporte(User $actor, AtencionMedica $atencion, string $importe): string
    {
        $contenido = ['RF-033', $actor->id, $atencion->id, $atencion->cita_id, $importe];

        return hash_hmac('sha256', json_encode($contenido, JSON_THROW_ON_ERROR), config('app.key'));
    }
}
