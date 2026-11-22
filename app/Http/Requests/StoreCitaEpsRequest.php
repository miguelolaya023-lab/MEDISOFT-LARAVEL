<?php

namespace App\Http\Requests;

use App\Models\Cita;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCitaEpsRequest extends FormRequest
{
    public const REMISION_NO_DISPONIBLE = 'Seleccione una remisión disponible y vigente para el paciente y la fecha de la cita, con EPS activa.';

    public function authorize(): bool
    {
        return $this->user()->can('createEps', Cita::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('motivo_consulta'))) {
            $this->merge(['motivo_consulta' => trim($this->input('motivo_consulta'))]);
        }
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'paciente_id' => ['bail', 'required', 'integer', Rule::exists('pacientes', 'id')],
            'medico_id' => ['bail', 'required', 'integer', Rule::exists('medicos', 'id')],
            'remision_id' => ['bail', 'required', 'integer', Rule::exists('remisiones_eps', 'id')],
            'fecha' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'hora_inicio' => ['bail', 'required', 'string', 'date_format:H:i'],
            'motivo_consulta' => ['required', 'string', 'max:2000'],
            'confirmacion' => ['required', 'accepted'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['paciente_id', 'medico_id', 'remision_id'] as $campo) {
                if (is_bool($this->input($campo))) {
                    $validator->errors()->add($campo, 'Ingrese un valor válido.');
                }
            }
            if (! $validator->errors()->has('fecha') && ! $validator->errors()->has('hora_inicio') && ! Cita::inicioEsFuturo($this->input('fecha'), $this->input('hora_inicio'))) {
                $validator->errors()->add('hora_inicio', 'El inicio debe ser posterior al momento actual de Colombia.');
            }
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token']) as $campo) {
                $validator->errors()->add($campo, 'Este campo no pertenece al agendamiento EPS.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            '*.required' => 'El campo :attribute es obligatorio.', '*.string' => 'Ingrese texto válido en :attribute.',
            '*.integer' => 'Seleccione un identificador válido.', '*.exists' => 'El registro seleccionado no existe.',
            'remision_id.exists' => self::REMISION_NO_DISPONIBLE,
            '*.date_format' => 'Ingrese fecha (AAAA-MM-DD) y hora (HH:MM) válidas.',
            'motivo_consulta.max' => 'El motivo no puede superar 2000 caracteres.',
            'confirmacion.accepted' => 'Confirme el agendamiento de la cita.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        $validos = $this->validator->valid();
        $seleccion = isset($validos['paciente_id'], $validos['medico_id'], $validos['fecha'])
            ? array_intersect_key($validos, array_flip(['paciente_id', 'medico_id', 'fecha']))
            : [];

        return route('citas.eps.create', $seleccion);
    }
}
