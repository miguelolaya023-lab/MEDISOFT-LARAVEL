<?php

namespace App\Models;

use Database\Factories\PacienteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    protected $fillable = [
        'tipo_documento', 'numero_documento', 'nombres', 'apellidos',
        'fecha_nacimiento', 'sexo', 'telefono', 'correo_electronico',
        'direccion', 'ciudad', 'tipo_paciente',
    ];

    protected $attributes = ['estado' => 'ACTIVO'];

    public function historiaClinica(): HasOne
    {
        return $this->hasOne(HistoriaClinica::class);
    }

    protected function casts(): array
    {
        return ['fecha_nacimiento' => 'date'];
    }
}
