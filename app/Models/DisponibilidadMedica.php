<?php

namespace App\Models;

use Database\Factories\DisponibilidadMedicaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisponibilidadMedica extends Model
{
    /** @use HasFactory<DisponibilidadMedicaFactory> */
    use HasFactory;

    protected $table = 'disponibilidades_medicas';

    protected $fillable = ['fecha', 'hora_inicio', 'hora_fin', 'duracion_minutos'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'duracion_minutos' => 'integer'];
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class);
    }

    public static function minutos(string $hora): int
    {
        return (int) substr($hora, 0, 2) * 60 + (int) substr($hora, 3, 2);
    }

    /**
     * D-06 aprobada: solo intervalos completos; el sobrante no genera una cita.
     * Son intervalos de configuración, no reservas RF-024 ni agenda RF-032.
     *
     * @return list<array{inicio: string, fin: string}>
     */
    public function intervalos(): array
    {
        $intervalos = [];
        $fin = self::minutos($this->hora_fin);
        $duracion = $this->duracion_minutos;
        if ($duracion <= 0) {
            return [];
        }
        for ($inicio = self::minutos($this->hora_inicio); $inicio + $duracion <= $fin; $inicio += $duracion) {
            $intervalos[] = ['inicio' => sprintf('%02d:%02d', intdiv($inicio, 60), $inicio % 60),
                'fin' => sprintf('%02d:%02d', intdiv($inicio + $duracion, 60), ($inicio + $duracion) % 60)];
        }

        return $intervalos;
    }
}
