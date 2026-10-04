<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Auditoria extends Model
{
    protected $table = 'auditorias';

    protected $fillable = ['user_id', 'accion', 'entidad', 'registro_id', 'fecha_hora', 'resultado', 'descripcion'];

    protected function casts(): array
    {
        return ['fecha_hora' => 'datetime'];
    }

    public static function registrar(string $accion, User $usuario, string $descripcion, ?int $responsable = null): self
    {
        return self::query()->create([
            'user_id' => $responsable ?? Auth::id(), 'accion' => $accion,
            'entidad' => 'User', 'registro_id' => $usuario->getKey(), 'fecha_hora' => now(),
            'resultado' => 'EXITO', 'descripcion' => $descripcion,
        ]);
    }
}
