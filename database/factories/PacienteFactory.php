<?php

namespace Database\Factories;

use App\Models\Paciente;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Paciente> */
class PacienteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tipo_documento' => 'CC', 'numero_documento' => fake()->unique()->numerify('##########'),
            'nombres' => fake()->firstName(), 'apellidos' => fake()->lastName(),
            'fecha_nacimiento' => fake()->dateTimeBetween('-80 years', '-1 year')->format('Y-m-d'),
            'sexo' => 'FEMENINO', 'telefono' => fake()->numerify('3#########'),
            'correo_electronico' => null, 'direccion' => fake()->streetAddress(),
            'ciudad' => 'Cali', 'tipo_paciente' => 'PARTICULAR',
        ];
    }
}
