<?php

namespace App\Models;

use Database\Factories\PacienteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Paciente extends Model
{
    public const TIPOS_DOCUMENTO = [
        'CC' => 'Cédula de ciudadanía',
        'TI' => 'Tarjeta de identidad',
        'RC' => 'Registro civil',
        'CE' => 'Cédula de extranjería',
        'PA' => 'Pasaporte',
        'PT' => 'Permiso por Protección Temporal',
    ];

    public const SEXOS = ['MASCULINO', 'FEMENINO', 'OTRO', 'NO_ESPECIFICA'];

    /** @use HasFactory<PacienteFactory> */
    use HasFactory;

    use HasGeographicLocation;

    protected $fillable = [
        'tipo_documento', 'numero_documento', 'nombres', 'apellidos',
        'fecha_nacimiento', 'sexo', 'telefono', 'correo_electronico',
        'direccion', 'ciudad', 'tipo_paciente', 'departamento_codigo', 'municipio_codigo',
    ];

    protected $attributes = ['estado' => 'ACTIVO'];

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
        return $this->hasMany(RemisionEps::class, 'paciente_id');
    }

    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class);
    }

    public function historiaClinica(): HasOne
    {
        return $this->hasOne(HistoriaClinica::class);
    }

    protected function casts(): array
    {
        return ['fecha_nacimiento' => 'date'];
    }
}
