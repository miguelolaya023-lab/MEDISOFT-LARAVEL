<?php

namespace App\Http\Requests;

use App\Models\Paciente;
use Illuminate\Contracts\Validation\Validator as ContractValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ConsultarPagosParticularesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fecha_inicial' => ['bail', 'required_with:fecha_final', 'nullable', 'date_format:Y-m-d'],
            'fecha_final' => ['bail', 'required_with:fecha_inicial', 'nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_inicial'],
            'tipo_documento' => ['nullable', 'string', Rule::in(array_keys(Paciente::TIPOS_DOCUMENTO))],
            'documento' => ['nullable', 'string', 'max:30', 'encoding:UTF-8'],
            'estado' => ['nullable', 'string', Rule::in(['REGISTRADO', 'ANULADO'])],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
        ];
    }

    public function messages(): array
    {
        return [
            'fecha_inicial.*' => 'Ingrese una fecha inicial válida junto con la fecha final.',
            'fecha_final.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.',
            'fecha_final.*' => 'Ingrese una fecha final válida junto con la fecha inicial.',
            'documento.*' => 'Ingrese un documento válido de hasta 30 caracteres.',
            'tipo_documento.*' => 'Seleccione un tipo de documento permitido.',
            'estado.*' => 'Seleccione un estado permitido.',
            'page.*' => 'Seleccione una página válida.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->query()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('filtros', 'La consulta contiene filtros no admitidos.');
            }
        }];
    }

    public function validationData(): array
    {
        return $this->query();
    }

    protected function failedValidation(ContractValidator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        throw new HttpResponseException(response()->view('pagos-particulares.consultas.index', [
            'errores' => $validator->errors()->all(), 'filtros' => [], 'pagos' => null, 'total' => null,
        ], 422, ['Referrer-Policy' => 'no-referrer']));
    }
}
