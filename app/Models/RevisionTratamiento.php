<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevisionTratamiento extends Model
{
    public $timestamps = false;

    protected $table = 'revisiones_tratamientos';

    protected $fillable = ['user_id', 'descripcion_anterior', 'fecha_correccion', 'revision'];

    protected $hidden = ['descripcion_anterior'];

    protected function casts(): array
    {
        return ['fecha_correccion' => 'datetime', 'revision' => 'integer'];
    }

    public function tratamiento(): BelongsTo
    {
        return $this->belongsTo(Tratamiento::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
