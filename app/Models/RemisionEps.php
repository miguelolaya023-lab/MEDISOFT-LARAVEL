<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\RemisionEpsFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class RemisionEps extends Model
{
    /** @use HasFactory<RemisionEpsFactory> */
    use HasFactory;

    public const ESTADOS = ['VIGENTE', 'VENCIDA', 'USADA', 'ANULADA'];

    protected $table = 'remisiones_eps';

    protected $fillable = ['paciente_id', 'eps_id', 'numero_remision', 'fecha_remision', 'servicio_autorizado', 'vigencia_hasta', 'observacion'];

    protected function casts(): array
    {
        return ['fecha_remision' => 'date', 'vigencia_hasta' => 'date'];
    }

    public static function hoy(): string
    {
        return now('America/Bogota')->toDateString();
    }

    public function estadoActual(?string $fecha = null): string
    {
        return $this->estado === 'VIGENTE' && $this->vigencia_hasta->toDateString() < ($fecha ?? self::hoy()) ? 'VENCIDA' : $this->estado;
    }

    public function registrarCambioEstado(int $responsable, string $anterior, string $nuevo, string $motivo, CarbonInterface $fecha): void
    {
        $this->motivo_cambio_estado = $motivo;
        $cambio = $this->cambiosEstado()->create([
            'user_id' => $responsable, 'estado_anterior' => $anterior, 'estado_nuevo' => $nuevo,
            'motivo' => $motivo, 'fecha_cambio' => $fecha,
        ]);
        throw_unless($cambio->exists, RuntimeException::class, 'No se pudo registrar la trazabilidad de la remisión.');
    }

    public function scopeConEstado(Builder $query, string $estado): void
    {
        if ($estado === 'VENCIDA') {
            $query->where(fn (Builder $q) => $q->where('estado', 'VENCIDA')->orWhere(fn (Builder $q) => $q->where('estado', 'VIGENTE')->where('vigencia_hasta', '<', self::hoy())));
        } elseif ($estado === 'VIGENTE') {
            $query->where('estado', 'VIGENTE')->where('vigencia_hasta', '>=', self::hoy());
        } else {
            $query->where('estado', $estado);
        }
    }

    public function scopeAgendablePara(Builder $query, string $fecha, ?int $exceptoCita = null): void
    {
        $query->where('estado', 'VIGENTE')
            ->whereDate('fecha_remision', '<=', $fecha)->whereDate('vigencia_hasta', '>=', $fecha)
            ->whereDoesntHave('citas', fn (Builder $citas) => $citas->ocupaHorario()
                ->when($exceptoCita !== null, fn (Builder $ocupadas) => $ocupadas->whereKeyNot($exceptoCita)));
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    public function eps(): BelongsTo
    {
        return $this->belongsTo(Eps::class);
    }

    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class, 'remision_id');
    }

    public function cambiosEstado(): HasMany
    {
        return $this->hasMany(CambioEstadoRemision::class, 'remision_id')->orderBy('id');
    }
}
