<?php

namespace Database\Factories;

use App\Models\AntecedenteClinico;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AntecedenteClinico> */
class AntecedenteClinicoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'historia_clinica_id' => fn () => Paciente::factory()->create()->historiaClinica()->create(['fecha_apertura' => now()])->id,
            'medico_id' => fn () => User::factory()->create()->medico()->create(['registro_profesional' => 'DEMO-'.fake()->unique()->numerify('######'), 'especialidad' => 'Medicina general'])->id,
            'tipo' => null, 'descripcion' => 'Antecedente ficticio para pruebas.', 'fecha_registro' => now(),
        ];
    }
}
