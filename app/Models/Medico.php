<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medico extends Model
{
    protected $fillable = ['user_id', 'registro_profesional', 'especialidad'];

    public function disponibilidades(): HasMany
    {
        return $this->hasMany(DisponibilidadMedica::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
