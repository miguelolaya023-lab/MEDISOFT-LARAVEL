<?php

namespace Database\Factories;

use App\Models\AntecedenteClinico;
use App\Models\RevisionAntecedenteClinico;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RevisionAntecedenteClinico> */
class RevisionAntecedenteClinicoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'antecedente_clinico_id' => AntecedenteClinico::factory(),
            'medico_id' => fn (array $attributes) => AntecedenteClinico::query()->findOrFail($attributes['antecedente_clinico_id'])->medico_id,
            'tipo' => null, 'descripcion' => 'Texto anterior ficticio para pruebas.', 'fecha_correccion' => now(),
        ];
    }
}
