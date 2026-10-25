<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HistoriaClinica extends Model
{
    protected $table = 'historias_clinicas';

    protected $fillable = ['paciente_id', 'fecha_apertura'];

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    public function antecedentes(): HasMany
    {
        return $this->hasMany(AntecedenteClinico::class);
    }

    protected function casts(): array
    {
        return ['fecha_apertura' => 'datetime'];
    }
}
