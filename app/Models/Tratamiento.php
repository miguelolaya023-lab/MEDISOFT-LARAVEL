<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tratamiento extends Model
{
    protected $table = 'tratamientos';

    protected $fillable = ['descripcion', 'fecha_registro', 'clave_envio', 'huella_envio'];

    protected $hidden = ['descripcion', 'clave_envio', 'huella_envio'];

    protected $attributes = ['revision' => 0];

    protected function casts(): array
    {
        return ['fecha_registro' => 'datetime', 'revision' => 'integer'];
    }

    public function atencionMedica(): BelongsTo
    {
        return $this->belongsTo(AtencionMedica::class);
    }

    public function revisiones(): HasMany
    {
        return $this->hasMany(RevisionTratamiento::class);
    }

    public function versionClinica(): string
    {
        return hash_hmac('sha256', 'tratamiento:'.$this->id.':'.$this->revision, config('app.key'));
    }
}
