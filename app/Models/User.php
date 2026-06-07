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
     * Actualiza un UsuarioInterno segun el metodo definido en el diagrama de clases.
     *
     * Aunque Laravel conserva el nombre tecnico User por Breeze, en el UML este
     * modelo representa conceptualmente a UsuarioInterno.
     *
     * @param  array{tipo_documento: string, numero_documento: string, nombres: string, apellidos: string, email: string, telefono: string, cargo: string}  $datos
     */
    public function actualizarUsuarioInterno(array $datos): bool
    {
        // UpdateUserRequest ya valido los datos antes de que el controlador llame al modelo.
        $nombreCompleto = trim($datos['nombres'].' '.$datos['apellidos']);

        return $this->update([
            ...$datos,
            // Breeze requiere name, por eso se regenera desde nombres y apellidos.
            'name' => $nombreCompleto,
            // El correo se normaliza a minusculas para mantener el comportamiento existente.
            'email' => Str::lower($datos['email']),
            // Este metodo solo actualiza datos propios del usuario interno.
            // No cambia password ni tipo_usuario; esas responsabilidades pertenecen a otros flujos.
        ]);
    }

    /**
     * Inactiva un UsuarioInterno segun el metodo definido en el diagrama de clases.
     *
     * User conserva su nombre tecnico por Breeze, pero conceptualmente representa
     * a UsuarioInterno en el UML. Este metodo no elimina el registro: solo cambia
     * el campo estado a inactivo.
     */
    public function inactivarUsuarioInterno(): bool
    {
        return $this->update([
            'estado' => 'inactivo',
        ]);
    }

    /**
     * Activa un UsuarioInterno segun el metodo definido en el diagrama de clases.
     *
     * User conserva su nombre tecnico por Breeze, pero conceptualmente representa
     * a UsuarioInterno en el UML. Este metodo no elimina ni recrea el registro:
     * solo cambia el campo estado a activo.
     */
    public function activarUsuarioInterno(): bool
    {
        return $this->update([
            'estado' => 'activo',
        ]);
    }

    /**
     * Obtiene los atributos que deben convertirse automaticamente.
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
