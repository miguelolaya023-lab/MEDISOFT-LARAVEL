<?php

namespace App\Models;

use Database\Factories\RevisionAntecedenteClinicoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Texto anterior a una corrección; permanece en el ámbito clínico. */
class RevisionAntecedenteClinico extends Model
{
    /** @use HasFactory<RevisionAntecedenteClinicoFactory> */
    use HasFactory;

    protected $table = 'antecedente_clinico_revisiones';

    protected $fillable = ['tipo', 'descripcion'];

    public function antecedenteClinico(): BelongsTo
    {
        return $this->belongsTo(AntecedenteClinico::class);
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class);
    }

    protected function casts(): array
    {
        return ['fecha_correccion' => 'datetime'];
    }
}
