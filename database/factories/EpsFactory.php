<?php

namespace Database\Factories;

use App\Models\Eps;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Eps> */
class EpsFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nit' => 'DEMO-'.fake()->unique()->numerify('########'), 'nombre' => 'EPS ficticia '.fake()->unique()->numerify('#####'),
            'telefono' => '003001234567', 'correo_electronico' => fake()->safeEmail(),
            'direccion' => 'Dirección ficticia de demostración', 'ciudad' => 'Cali', 'estado' => 'ACTIVA',
        ];
    }
}
