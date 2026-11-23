<?php

namespace App\Models;

use Database\Factories\AtencionMedicaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtencionMedica extends Model
{
    /** @use HasFactory<AtencionMedicaFactory> */
    use HasFactory;

    protected $table = 'atenciones_medicas';

    protected $guarded = ['*'];

    protected $attributes = ['estado' => 'EN_CURSO'];

    protected function casts(): array
    {
        return ['fecha_hora_inicio' => 'datetime'];
    }

    public function cita(): BelongsTo
    {
        return $this->belongsTo(Cita::class);
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class);
    }

    public function historiaClinica(): BelongsTo
    {
        return $this->belongsTo(HistoriaClinica::class);
    }
}
