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
}
