<?php

namespace App\Http\Requests;

use App\Models\Paciente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPacienteRequest extends FormRequest
{
    protected $redirectRoute = 'pacientes.index';

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Paciente::class);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'tipo_paciente' => ['nullable', Rule::in(['PARTICULAR', 'EPS'])],
            'ciudad' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(['ACTIVO', 'INACTIVO'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tipo_paciente.in' => 'Seleccione PARTICULAR o EPS, o limpie el filtro.',
            'estado.in' => 'Seleccione ACTIVO o INACTIVO, o limpie el filtro.',
            'ciudad.string' => 'Ingrese una ciudad válida.',
            'ciudad.max' => 'La ciudad no puede superar 100 caracteres.',
        ];
    }
}
