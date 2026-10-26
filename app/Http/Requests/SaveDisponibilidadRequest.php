<?php

namespace App\Http\Requests;

use App\Models\DisponibilidadMedica;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveDisponibilidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->routeIs('disponibilidad.store')
            ? $this->user()->can('create', DisponibilidadMedica::class)
            : $this->user()->can('update', $this->route('disponibilidad'));
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            ...($this->routeIs('disponibilidad.store') ? [
                'fechas' => ['required', 'array', 'list', 'min:1'],
                'fechas.*' => ['bail', 'required', 'string', 'date_format:Y-m-d', 'distinct:strict'],
            ] : ['fecha' => ['bail', 'required', 'string', 'date_format:Y-m-d']]),
            'hora_inicio' => ['bail', 'required', 'string', 'date_format:H:i'],
            'hora_fin' => ['bail', 'required', 'string', 'date_format:H:i'],
            'duracion_minutos' => ['bail', 'required', 'integer', 'min:1'],
            'confirmacion' => ['required', 'accepted'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (is_bool($this->input('duracion_minutos'))) {
                $validator->errors()->add('duracion_minutos', 'La duración debe ser un número entero de minutos.');
            }
            if (! $validator->errors()->has('hora_inicio') && ! $validator->errors()->has('hora_fin')) {
                $minutos = DisponibilidadMedica::minutos($this->input('hora_fin')) - DisponibilidadMedica::minutos($this->input('hora_inicio'));
                if ($minutos <= 0) {
                    $validator->errors()->add('hora_fin', 'La hora final debe ser posterior a la hora inicial del mismo día.');
                } elseif (! $validator->errors()->has('duracion_minutos') && (int) $this->input('duracion_minutos') > $minutos) {
                    $validator->errors()->add('duracion_minutos', 'La duración debe permitir al menos un intervalo completo dentro del bloque.');
                }
            }
            $permitidos = $this->routeIs('disponibilidad.store') ? ['fechas'] : ['fecha'];
            foreach (array_diff(array_keys($this->all()), [...$permitidos, 'hora_inicio', 'hora_fin', 'duracion_minutos', 'confirmacion', '_token', '_method']) as $campo) {
                $validator->errors()->add($campo, 'Este campo no pertenece a la configuración de disponibilidad.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            '*.required' => 'El campo :attribute es obligatorio.', '*.string' => 'Ingrese texto válido en :attribute.',
            'fechas.array' => 'Seleccione una o varias fechas.', 'fechas.list' => 'Envíe una lista de fechas.',
            'fechas.min' => 'Seleccione al menos una fecha.', 'fechas.*.distinct' => 'No repita fechas en la misma solicitud.',
            'fechas.*.date_format' => 'Ingrese fechas válidas (AAAA-MM-DD).', 'fecha.date_format' => 'Ingrese una fecha válida (AAAA-MM-DD).',
            'hora_inicio.date_format' => 'Ingrese una hora inicial válida (HH:MM).', 'hora_fin.date_format' => 'Ingrese una hora final válida (HH:MM).',
            'duracion_minutos.integer' => 'La duración debe ser un número entero de minutos.', 'duracion_minutos.min' => 'La duración debe ser positiva.',
            'confirmacion.accepted' => 'Confirme la configuración de disponibilidad.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->routeIs('disponibilidad.store') ? route('disponibilidad.index') : route('disponibilidad.edit', $this->route('disponibilidad'));
    }
}
