<?php

namespace App\Http\Requests;

use App\Models\Cita;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCitaParticularRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Cita::class);
    }

    protected function prepareForValidation(): void
    {
        foreach (['motivo_consulta', 'valor_base'] as $campo) {
            if (is_string($this->input($campo))) {
                $this->merge([$campo => trim($this->input($campo))]);
            }
        }
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'paciente_id' => ['bail', 'required', 'integer', Rule::exists('pacientes', 'id')],
            'medico_id' => ['bail', 'required', 'integer', Rule::exists('medicos', 'id')],
            'fecha' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'hora_inicio' => ['bail', 'required', 'string', 'date_format:H:i'],
            'motivo_consulta' => ['required', 'string', 'max:2000'],
            'valor_base' => ['bail', 'required', 'numeric', 'gt:0', 'regex:/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D'],
            'confirmacion' => ['required', 'accepted'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['paciente_id', 'medico_id', 'valor_base'] as $campo) {
                if (is_bool($this->input($campo))) {
                    $validator->errors()->add($campo, 'Ingrese un valor válido.');
                }
            }
            if (! $validator->errors()->has('fecha') && ! $validator->errors()->has('hora_inicio') && ! Cita::inicioEsFuturo($this->input('fecha'), $this->input('hora_inicio'))) {
                $validator->errors()->add('hora_inicio', 'El inicio debe ser posterior al momento actual de Colombia.');
            }
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token']) as $campo) {
                $validator->errors()->add($campo, 'Este campo no pertenece al agendamiento particular.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            '*.required' => 'El campo :attribute es obligatorio.', '*.string' => 'Ingrese texto válido en :attribute.',
            '*.integer' => 'Seleccione un identificador válido.', '*.exists' => 'El registro seleccionado no existe.',
            '*.date_format' => 'Ingrese fecha (AAAA-MM-DD) y hora (HH:MM) válidas.',
            'motivo_consulta.max' => 'El motivo no puede superar 2000 caracteres.',
            'valor_base.numeric' => 'Ingrese un importe válido en COP.', 'valor_base.gt' => 'El valor base debe ser positivo.',
            'valor_base.regex' => 'Use un importe de hasta diez enteros y dos decimales, con punto decimal.',
            'confirmacion.accepted' => 'Confirme el agendamiento de la cita.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        $validos = $this->validator->valid();
        $seleccion = isset($validos['medico_id'], $validos['fecha']) ? ['medico_id' => $validos['medico_id'], 'fecha' => $validos['fecha']] : [];

        return route('citas.create', $seleccion);
    }
}
