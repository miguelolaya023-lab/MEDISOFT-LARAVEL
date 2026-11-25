<?php

namespace App\Models;

use Database\Factories\PagoParticularFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PagoParticular extends Model
{
    /** @use HasFactory<PagoParticularFactory> */
    use HasFactory;

    public const METODOS = ['EFECTIVO', 'TRANSFERENCIA', 'TARJETA'];

    protected $table = 'pagos_particulares';

    public $timestamps = false;

    protected $fillable = [
        'numero_recibo', 'valor_pagado', 'fecha_pago', 'fecha_registro', 'metodo_pago',
        'observacion', 'estado', 'creado_por', 'clave_envio', 'huella_envio',
    ];

    protected $hidden = ['observacion', 'clave_envio', 'huella_envio'];

    protected function casts(): array
    {
        return [
            'valor_pagado' => 'decimal:2', 'fecha_pago' => 'date',
            'fecha_registro' => 'datetime', 'observacion' => 'encrypted',
        ];
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(Liquidacion::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }
}
