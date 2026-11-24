<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormulaMedica extends Model
{
    protected $table = 'formulas_medicas';

    protected $fillable = ['fecha_emision', 'observacion', 'clave_envio', 'huella_envio'];

    protected $hidden = ['observacion', 'clave_envio', 'huella_envio', 'detalles'];

    protected function casts(): array
    {
        return ['fecha_emision' => 'datetime'];
    }

    public function atencionMedica(): BelongsTo
    {
        return $this->belongsTo(AtencionMedica::class);
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleFormula::class)->orderBy('posicion');
    }
}
