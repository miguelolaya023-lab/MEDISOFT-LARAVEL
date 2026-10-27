<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CitaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cita extends Model
{
    /** @use HasFactory<CitaFactory> */
    use HasFactory;

    protected $fillable = ['motivo_consulta', 'valor_base'];

    protected $hidden = ['inicio_ocupado'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'valor_base' => 'decimal:2'];
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class);
    }

    public function disponibilidad(): BelongsTo
    {
        return $this->belongsTo(DisponibilidadMedica::class, 'disponibilidad_medica_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** RN-06: también conserva la ocupación de citas en atención o atendidas. */
    public function scopeOcupaHorario(Builder $query): void
    {
        $query->where('estado', '!=', 'CANCELADA');
    }

    public static function inicioEsFuturo(string $fecha, string $hora): bool
    {
        return CarbonImmutable::createFromFormat('!Y-m-d H:i', $fecha.' '.substr($hora, 0, 5), 'America/Bogota')->greaterThan(now('America/Bogota'));
    }
}
