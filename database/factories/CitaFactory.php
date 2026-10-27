<?php

namespace Database\Factories;

use App\Models\Cita;
use App\Models\DisponibilidadMedica;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Cita> */
class CitaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'disponibilidad_medica_id' => DisponibilidadMedica::factory(),
            'paciente_id' => Paciente::factory(),
            'medico_id' => fn (array $attributes) => DisponibilidadMedica::findOrFail($attributes['disponibilidad_medica_id'])->medico_id,
            'fecha' => fn (array $attributes) => DisponibilidadMedica::findOrFail($attributes['disponibilidad_medica_id'])->fecha->toDateString(),
            'hora_inicio' => fn (array $attributes) => DisponibilidadMedica::findOrFail($attributes['disponibilidad_medica_id'])->intervalos()[0]['inicio'].':00',
            'hora_fin' => fn (array $attributes) => DisponibilidadMedica::findOrFail($attributes['disponibilidad_medica_id'])->intervalos()[0]['fin'].':00',
            'modalidad' => 'PARTICULAR',
            'tipo_paciente_al_agendar' => fn (array $attributes) => Paciente::findOrFail($attributes['paciente_id'])->tipo_paciente,
            'motivo_consulta' => 'Motivo administrativo ficticio', 'valor_base' => '50000.00',
            'estado' => 'PROGRAMADA', 'created_by' => User::factory(),
        ];
    }
}
