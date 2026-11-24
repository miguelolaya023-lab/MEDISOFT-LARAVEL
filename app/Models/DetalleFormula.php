<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleFormula extends Model
{
    public $timestamps = false;

    protected $table = 'detalles_formula';

    protected $fillable = ['posicion', 'medicamento', 'dosis', 'frecuencia', 'duracion', 'indicaciones'];

    protected $hidden = ['medicamento', 'dosis', 'frecuencia', 'duracion', 'indicaciones'];

    public function formulaMedica(): BelongsTo
    {
        return $this->belongsTo(FormulaMedica::class);
    }
}
