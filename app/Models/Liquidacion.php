<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Liquidacion extends Model
{
    protected $table = 'liquidaciones';

    public $timestamps = false;

    protected $fillable = ['atencion_medica_id', 'modalidad', 'valor_total', 'fecha_liquidacion', 'creado_por'];

    protected function casts(): array
    {
        return ['valor_total' => 'decimal:2', 'fecha_liquidacion' => 'datetime'];
    }

    public function atencion(): BelongsTo
    {
        return $this->belongsTo(AtencionMedica::class, 'atencion_medica_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function estadoParticular(): string
    {
        return ($this->getAttribute('pago_vigente_exists') ?? $this->pagoVigente()->exists()) ? 'PAGADA' : 'PENDIENTE_PAGO';
    }

    public function pagosParticulares(): HasMany
    {
        return $this->hasMany(PagoParticular::class);
    }

    public function pagoVigente(): HasOne
    {
        return $this->hasOne(PagoParticular::class, 'liquidacion_vigente_id');
    }
}
