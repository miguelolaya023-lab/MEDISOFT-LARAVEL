<?php

namespace App\Models;

use Database\Factories\AntecedenteClinicoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AntecedenteClinico extends Model
{
    /** @use HasFactory<AntecedenteClinicoFactory> */
    use HasFactory;

    protected $table = 'antecedentes_clinicos';

    protected $fillable = ['tipo', 'descripcion'];

    public function historiaClinica(): BelongsTo
    {
        return $this->belongsTo(HistoriaClinica::class);
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class);
    }

    public function revisiones(): HasMany
    {
        return $this->hasMany(RevisionAntecedenteClinico::class);
    }

    protected function casts(): array
    {
        return ['fecha_registro' => 'datetime'];
    }
}
