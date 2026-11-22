<?php

namespace App\Http\Requests;

use App\Models\Departamento;
use App\Models\Municipio;
use Illuminate\Validation\Rule;

class GeographicSelection
{
    /** @return array<string, mixed> */
    public static function options(): array
    {
        return [
            'departamentos' => Departamento::query()->orderBy('nombre')->orderBy('codigo')->get(['codigo', 'nombre']),
            'municipios' => Municipio::query()->orderBy('nombre')->orderBy('codigo')->get(['codigo', 'departamento_codigo', 'nombre', 'tipo_territorial']),
        ];
    }

    /** @return array<string, array<mixed>> */
    public static function rules(bool $required, mixed $department): array
    {
        return [
            'departamento_codigo' => ['bail', Rule::requiredIf($required), 'nullable', 'string', 'regex:/^[0-9]{2}$/D', Rule::exists('departamentos', 'codigo')],
            'municipio_codigo' => ['bail', Rule::requiredIf($required), 'nullable', 'string', 'regex:/^[0-9]{5}$/D', Rule::exists('municipios', 'codigo')->where('departamento_codigo', is_string($department) ? $department : null)],
        ];
    }
}
