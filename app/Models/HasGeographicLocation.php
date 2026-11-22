<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

trait HasGeographicLocation
{
    public function scopeAtLocation(Builder $query, string $term): void
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
        $query->where(function (Builder $locations) use ($pattern): void {
            $locations->where(function (Builder $official) use ($pattern): void {
                $official->whereNotNull($official->qualifyColumn('departamento_codigo'))
                    ->whereNotNull($official->qualifyColumn('municipio_codigo'))
                    ->whereHas('municipio', fn (Builder $municipality) => $municipality->whereRaw("LOWER(nombre) LIKE LOWER(?) ESCAPE '!'", [$pattern]));
            })->orWhere(function (Builder $historical) use ($pattern): void {
                $historical->whereNull($historical->qualifyColumn('departamento_codigo'))
                    ->whereNull($historical->qualifyColumn('municipio_codigo'))
                    ->whereRaw('LOWER('.$historical->qualifyColumn('ciudad').") LIKE LOWER(?) ESCAPE '!'", [$pattern]);
            });
        });
    }
}
