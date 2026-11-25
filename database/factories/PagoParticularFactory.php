<?php

namespace Database\Factories;

use App\Models\PagoParticular;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PagoParticular> */
class PagoParticularFactory extends Factory
{
    public function definition(): array
    {
        return [
            'numero_recibo' => 'PP-'.Str::ulid(),
            'valor_pagado' => '80000.00',
            'fecha_pago' => now('America/Bogota')->toDateString(),
            'fecha_registro' => now(),
            'metodo_pago' => 'EFECTIVO',
            'estado' => 'REGISTRADO',
            'creado_por' => User::factory(),
            'clave_envio' => (string) Str::uuid(),
            'huella_envio' => str_repeat('0', 64),
        ];
    }
}
