<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Medico extends Model
{
    protected $fillable = ['user_id', 'registro_profesional', 'especialidad'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
