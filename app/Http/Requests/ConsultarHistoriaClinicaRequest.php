<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class ConsultarHistoriaClinicaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fechaInicial' => ['bail', 'nullable', 'date_format:Y-m-d', ...($this->filled('fechaFinal') ? ['before_or_equal:fechaFinal'] : [])],
            'fechaFinal' => ['bail', 'nullable', 'date_format:Y-m-d'],
            'page' => ['bail', 'sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
            'antecedentes' => ['bail', 'sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
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

    public function messages(): array
    {
        return [
            'fechaInicial.date_format' => 'Ingrese una fecha inicial válida en formato AAAA-MM-DD.',
            'fechaFinal.date_format' => 'Ingrese una fecha final válida en formato AAAA-MM-DD.',
            'fechaInicial.before_or_equal' => 'La fecha inicial debe ser anterior o igual a la fecha final.',
            'page.*' => 'Seleccione una página válida de atenciones.',
            'antecedentes.*' => 'Seleccione una página válida de antecedentes.',
        ];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        throw new HttpResponseException(response()->view('pacientes.historia.filtros-invalidos', [
            'paciente' => $this->route('paciente'),
            'errores' => $validator->errors()->all(),
        ], 422, ['Cache-Control' => 'no-store, private']));
    }
}
