<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable([
    'name',
    'email',
    'password',
    'tipo_documento',
    'numero_documento',
    'nombres',
    'apellidos',
    'telefono',
    'cargo',
    'estado',
    'tipo_usuario',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const TIPO_USUARIO_MEDICO = 'medico';

    public const TIPO_USUARIO_INTERNO = 'interno';

    /**
     * Crea un UsuarioInterno segun el metodo definido en el diagrama de clases.
     *
     * En el codigo Laravel este modelo se llama User por la integracion con Breeze,
     * pero conceptualmente representa a UsuarioInterno dentro del UML de MEDISOFT.
     *
     * @param  array{tipo_documento: string, numero_documento: string, nombres: string, apellidos: string, email: string, telefono: string, cargo: string, estado: string}  $datos
     */
    public static function crearUsuarioInterno(array $datos): self
    {
        // StoreUserRequest ya valido los datos antes de que el controlador llame al modelo.
        $nombreCompleto = trim($datos['nombres'].' '.$datos['apellidos']);

        return self::query()->create([
            ...$datos,
            // Breeze usa el campo name; se genera desde nombres y apellidos para conservar compatibilidad.
            'name' => $nombreCompleto,
            'email' => Str::lower($datos['email']),
            // tipo_usuario distingue al UsuarioInterno de otros tipos de usuarios del sistema.
            'tipo_usuario' => self::TIPO_USUARIO_INTERNO,
            // El requisito no pide contrasena explicita, por eso se genera una temporal en servidor.
            // En una fase futura puede enviarse o restablecerse por correo.
            'password' => Str::password(16),
        ]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
