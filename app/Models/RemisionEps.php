<?php

namespace App\Models;

use Database\Factories\RemisionEpsFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function estadoActual(): string
    {
        return $this->estado === 'VIGENTE' && $this->vigencia_hasta->toDateString() < self::hoy() ? 'VENCIDA' : $this->estado;
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

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    public function eps(): BelongsTo
    {
        return $this->belongsTo(Eps::class);
    }

    public function cambiosEstado(): HasMany
    {
        return $this->hasMany(CambioEstadoRemision::class, 'remision_id')->orderBy('id');
    }
}
