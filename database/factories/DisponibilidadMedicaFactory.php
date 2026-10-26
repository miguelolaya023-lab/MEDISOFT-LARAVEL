<?php

namespace Database\Factories;

use App\Models\DisponibilidadMedica;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DisponibilidadMedica> */
class DisponibilidadMedicaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'medico_id' => fn () => User::factory()->create()->medico()->create([
                'registro_profesional' => 'DEMO-'.fake()->unique()->numerify('########'), 'especialidad' => 'Medicina general',
            ])->id,
            'fecha' => now('America/Bogota')->toDateString(),
            'hora_inicio' => '08:00:00', 'hora_fin' => '10:00:00', 'duracion_minutos' => 30,
        ];
    }
}
