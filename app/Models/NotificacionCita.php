<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificacionCita extends Model
{
    protected $table = 'notificaciones_cita';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'contacto_destino', 'asunto', 'mensaje', 'canal', 'estado',
        'fecha_intento', 'motivo_fallo', 'clave_confirmacion',
    ];

    protected $hidden = ['contacto_destino', 'asunto', 'mensaje', 'clave_confirmacion'];

    protected function casts(): array
    {
        return [
            'contacto_destino' => 'encrypted',
            'asunto' => 'encrypted',
            'mensaje' => 'encrypted',
            'fecha_intento' => 'datetime',
        ];
    }

    public function cita(): BelongsTo
    {
        return $this->belongsTo(Cita::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
