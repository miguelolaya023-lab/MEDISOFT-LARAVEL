<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class ConsultarHistorialConsultasRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'fechaInicial' => ['bail', 'required', 'date_format:Y-m-d'],
            'fechaFinal' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:fechaInicial'],
            'page' => ['bail', 'sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fechaInicial.required' => 'Ingrese la fecha inicial.',
            'fechaInicial.date_format' => 'Ingrese una fecha inicial válida en formato AAAA-MM-DD.',
            'fechaFinal.required' => 'Ingrese la fecha final.',
            'fechaFinal.date_format' => 'Ingrese una fecha final válida en formato AAAA-MM-DD.',
            'fechaFinal.after_or_equal' => 'La fecha final debe ser posterior o igual a la fecha inicial.',
            'page.*' => 'Seleccione una página válida de consultas.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules()))) {
                $validator->errors()->add('filtros', 'La consulta contiene parámetros no admitidos.');
            }
        }];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        throw new HttpResponseException(response()->view('pacientes.historial.index', [
            'paciente' => $this->route('paciente'),
            'filtros' => [],
            'atenciones' => null,
            'errores' => $validator->errors()->all(),
        ], 422, ['Cache-Control' => 'no-store, private']));
    }
}
