<?php

namespace Database\Factories;

use App\Models\AtencionMedica;
use App\Models\Cita;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AtencionMedica> */
class AtencionMedicaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cita_id' => Cita::factory()->state(['estado' => 'EN_ATENCION']),
            'medico_id' => fn (array $attributes) => Cita::query()->findOrFail($attributes['cita_id'])->medico_id,
            'historia_clinica_id' => fn (array $attributes) => Cita::query()->findOrFail($attributes['cita_id'])
                ->paciente->historiaClinica()->firstOrCreate([], ['fecha_apertura' => now()])->id,
            'fecha_hora_inicio' => now(),
            'estado' => 'EN_CURSO',
        ];
    }
}
