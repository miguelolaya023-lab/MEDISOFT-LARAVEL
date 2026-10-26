<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CambioEstadoRemision extends Model
{
    protected $table = 'cambios_estado_remision';

    protected $fillable = ['remision_id', 'user_id', 'estado_anterior', 'estado_nuevo', 'motivo', 'fecha_cambio'];

    protected function casts(): array
    {
        return ['fecha_cambio' => 'datetime'];
    }

    public function remision(): BelongsTo
    {
        return $this->belongsTo(RemisionEps::class, 'remision_id');
    }
}
