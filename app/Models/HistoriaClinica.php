<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoriaClinica extends Model
{
    protected $table = 'historias_clinicas';

    protected $fillable = ['paciente_id', 'fecha_apertura'];

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    protected function casts(): array
    {
        return ['fecha_apertura' => 'datetime'];
    }
}
