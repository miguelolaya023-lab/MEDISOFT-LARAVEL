<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ContractValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ConsultarHistorialPagosRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fecha_inicial' => ['bail', 'required', 'date_format:Y-m-d'],
            'fecha_final' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicial'],
            'tipo_cobro' => ['nullable', 'string', Rule::in(['PARTICULAR', 'CUOTA_MODERADORA'])],
            'estado' => ['nullable', 'string', Rule::in(['REGISTRADO', 'ANULADO'])],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
        ];
    }

    public function messages(): array
    {
        return [
            'fecha_inicial.*' => 'Ingrese una fecha inicial válida.',
            'fecha_final.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.',
            'fecha_final.*' => 'Ingrese una fecha final válida.',
            'tipo_cobro.*' => 'Seleccione un tipo de cobro permitido.',
            'estado.*' => 'Seleccione un estado permitido.',
            'page.*' => 'Seleccione una página válida.',
        ];
    }

    public function validationData(): array
    {
        return $this->query();
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->query()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('filtros', 'La consulta contiene filtros no admitidos.');
            }
        }];
    }

    protected function failedValidation(ContractValidator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        throw new HttpResponseException(response()->view('pacientes.historial-pagos.index', [
            'pacienteId' => $this->route('pacienteId'), 'paciente' => null, 'filtros' => [],
            'historial' => null, 'errores' => $validator->errors()->all(),
        ], 422, ['Referrer-Policy' => 'no-referrer']));
    }
}
