<?php

namespace Database\Factories;

use App\Models\Rol;
use App\Models\User;
use Database\Seeders\SecuritySeeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'estado' => 'activo',
            'tipo_usuario' => User::TIPO_USUARIO_INTERNO,
            'numero_documento' => fake()->unique()->numerify('##########'),
            'rol_id' => fn () => Rol::query()->firstOrCreate(['nombre' => 'Usuario sin permisos'])->id,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function administrator(): static
    {
        return $this->state(function (): array {
            (new SecuritySeeder)->run();

            return ['rol_id' => Rol::query()->where('nombre', 'Administrador')->value('id')];
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
