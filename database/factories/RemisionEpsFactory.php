<?php

namespace Database\Factories;

use App\Models\Eps;
use App\Models\Paciente;
use App\Models\RemisionEps;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RemisionEps> */
class RemisionEpsFactory extends Factory
{
    public function definition(): array
    {
        return [
            'paciente_id' => Paciente::factory(), 'eps_id' => Eps::factory(),
            'numero_remision' => 'DEMO-'.fake()->unique()->numerify('########'),
            'fecha_remision' => RemisionEps::hoy(), 'vigencia_hasta' => now('America/Bogota')->addMonth()->toDateString(),
            'servicio_autorizado' => 'Servicio ficticio académico', 'observacion' => null, 'estado' => 'VIGENTE',
        ];
    }
}
