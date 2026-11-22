<?php

namespace App\Models;

use Database\Factories\EpsFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Eps extends Model
{
    /** @use HasFactory<EpsFactory> */
    use HasFactory;

    use HasGeographicLocation;

    public const ESTADOS = ['ACTIVA', 'INACTIVA'];

    protected $table = 'eps';

    protected $fillable = ['nit', 'nombre', 'telefono', 'correo_electronico', 'direccion', 'ciudad', 'estado', 'departamento_codigo', 'municipio_codigo'];

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'departamento_codigo', 'codigo');
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class, 'municipio_codigo', 'codigo');
    }

    public function remisiones(): HasMany
    {
        return $this->hasMany(RemisionEps::class, 'eps_id');
    }

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
