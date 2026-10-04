<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'tipo_documento', 'numero_documento', 'nombres', 'apellidos', 'telefono', 'cargo', 'estado', 'tipo_usuario', 'rol_id', 'motivo_inactivacion', 'ultimo_acceso'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const TIPO_USUARIO_MEDICO = 'medico';

    public const TIPO_USUARIO_INTERNO = 'interno';

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }

    public function medico(): HasOne
    {
        return $this->hasOne(Medico::class);
    }

    public function estaActivo(): bool
    {
        return $this->estado === 'activo';
    }

    public function esInterno(): bool
    {
        return in_array($this->tipo_usuario, [self::TIPO_USUARIO_INTERNO, self::TIPO_USUARIO_MEDICO], true);
    }

    public function tienePermiso(string $codigo): bool
    {
        return $this->estaActivo() && $this->esInterno() && $this->rol()->whereHas('permisos', fn (Builder $query) => $query->where('codigo', $codigo))->exists();
    }

    public function puedeAdministrarUsuarios(): bool
    {
        foreach (['RF-004', 'RF-007', 'RF-054', 'RF-055'] as $codigo) {
            if (! $this->tienePermiso($codigo)) {
                return false;
            }
        }

        return true;
    }

    public static function consultarUsuarioInterno(?string $criterio): Builder
    {
        $consulta = self::query()->whereIn('tipo_usuario', [self::TIPO_USUARIO_INTERNO, self::TIPO_USUARIO_MEDICO]);
        $criterio = trim((string) $criterio);
        if ($criterio === '') {
            return $consulta;
        }

        return $consulta->where(function (Builder $query) use ($criterio): void {
            $valor = '%'.$criterio.'%';
            $query->where('numero_documento', 'like', $valor)->orWhere('nombres', 'like', $valor)->orWhere('apellidos', 'like', $valor)->orWhere('name', 'like', $valor);
        });
    }

    public static function crearUsuarioInterno(array $datos): self
    {
        return self::query()->create([...$datos, 'name' => trim($datos['nombres'].' '.$datos['apellidos']), 'email' => Str::lower($datos['email']), 'tipo_usuario' => self::TIPO_USUARIO_INTERNO, 'password' => Str::password(16)]);
    }

    public function actualizarUsuarioInterno(array $datos): bool
    {
        return $this->update([...$datos, 'name' => trim($datos['nombres'].' '.$datos['apellidos']), 'email' => Str::lower($datos['email'])]);
    }

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'ultimo_acceso' => 'datetime', 'password' => 'hashed'];
    }
}
