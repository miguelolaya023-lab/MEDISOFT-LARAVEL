<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medico extends Model
{
    protected $fillable = ['user_id', 'registro_profesional', 'especialidad'];

    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class);
    }

    public function scopeHabilitado(Builder $query): void
    {
        $query->whereRaw("TRIM(registro_profesional) <> ''")->whereRaw("TRIM(especialidad) <> ''")
            ->whereHas('user', fn (Builder $users) => $users->where('estado', 'activo')->whereIn('tipo_usuario', [User::TIPO_USUARIO_INTERNO, User::TIPO_USUARIO_MEDICO]));
    }

    public function estaHabilitado(): bool
    {
        return trim($this->registro_profesional) !== '' && trim($this->especialidad) !== '' && $this->user->estaActivo() && $this->user->esInterno();
    }

    public function disponibilidades(): HasMany
    {
        return $this->hasMany(DisponibilidadMedica::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * La vista previa no reserva. Al confirmar, consultar bajo el mutex Médico.
     *
     * @return list<array{inicio: string, fin: string, bloque_id: int}>
     */
    public function intervalosLibres(string $fecha): array
    {
        $bloques = $this->disponibilidades()->whereDate('fecha', $fecha)->orderBy('hora_inicio')->get();
        $ocupadas = $this->citas()->ocupaHorario()->whereDate('fecha', $fecha)->get(['hora_inicio', 'hora_fin']);
        $intervalos = [];
        foreach ($bloques as $bloque) {
            foreach ($bloque->intervalos() as $intervalo) {
                if (Cita::inicioEsFuturo($fecha, $intervalo['inicio']) && ! $ocupadas->contains(fn (Cita $cita) => $cita->hora_inicio < $intervalo['fin'].':00' && $cita->hora_fin > $intervalo['inicio'].':00')) {
                    $intervalos[] = [...$intervalo, 'bloque_id' => $bloque->id];
                }
            }
        }

        return $intervalos;
    }
}
