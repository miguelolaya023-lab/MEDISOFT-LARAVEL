<?php

namespace App\Http\Requests;

use App\Models\Paciente;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class BuscarPacienteHistorialPagosRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tipo_documento' => ['required', 'string', Rule::in(array_keys(Paciente::TIPOS_DOCUMENTO))],
            'documento' => ['required', 'string', 'max:30', 'encoding:UTF-8'],
        ];
    }

    public function validationData(): array
    {
        return $this->query();
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        throw new HttpResponseException(response()->view('pacientes.historial-pagos.buscar', [
            'error' => 'Ingrese un tipo y un número de documento válidos.',
        ], 422, ['Referrer-Policy' => 'no-referrer']));
    }
}
