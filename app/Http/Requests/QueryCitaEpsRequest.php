<?php

namespace App\Http\Requests;

use App\Models\Cita;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class QueryCitaEpsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createEps', Cita::class);
    }

    public function rules(): array
    {
        return [
            'paciente_id' => ['bail', 'required_with:medico_id,fecha', 'integer', Rule::exists('pacientes', 'id')],
            'medico_id' => ['bail', 'required_with:paciente_id,fecha', 'integer', Rule::exists('medicos', 'id')],
            'fecha' => ['bail', 'required_with:paciente_id,medico_id', 'string', 'date_format:Y-m-d'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['paciente_id', 'medico_id'] as $campo) {
                if (is_bool($this->input($campo))) {
                    $validator->errors()->add($campo, 'Seleccione un identificador válido.');
                }
            }
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $campo) {
                $validator->errors()->add($campo, 'Este filtro no pertenece al agendamiento EPS.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            '*.required_with' => 'Seleccione paciente, médico y fecha.',
            '*.integer' => 'Seleccione un identificador válido.',
            '*.exists' => 'El registro seleccionado no existe.',
            'fecha.date_format' => 'Ingrese una fecha válida (AAAA-MM-DD).',
            'fecha.string' => 'Ingrese una fecha válida.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('citas.eps.create');
    }
}
