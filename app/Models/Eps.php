<?php

namespace App\Models;

use Database\Factories\EpsFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Eps extends Model
{
    /** @use HasFactory<EpsFactory> */
    use HasFactory;

    public const ESTADOS = ['ACTIVA', 'INACTIVA'];

    protected $table = 'eps';

    protected $fillable = ['nit', 'nombre', 'telefono', 'correo_electronico', 'direccion', 'ciudad', 'estado'];

    public static function normalizarNit(string $nit): string
    {
        return Str::upper(trim($nit));
    }

    /** @return Attribute<string, string> */
    protected function nit(): Attribute
    {
        return Attribute::make(set: fn (string $valor): string => self::normalizarNit($valor));
    }
}
