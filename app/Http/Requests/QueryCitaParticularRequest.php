<?php

namespace App\Http\Requests;

use App\Models\Cita;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class QueryCitaParticularRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Cita::class);
    }

    public function rules(): array
    {
        return [
            'medico_id' => ['bail', 'nullable', 'required_with:fecha', 'integer', Rule::exists('medicos', 'id')],
            'fecha' => ['bail', 'nullable', 'required_with:medico_id', 'string', 'date_format:Y-m-d'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (is_bool($this->input('medico_id'))) {
                $validator->errors()->add('medico_id', 'Seleccione un identificador válido.');
            }
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $campo) {
                $validator->errors()->add($campo, 'Este filtro no pertenece a la selección de intervalos.');
            }
        }];
    }

    public function messages(): array
    {
        return ['*.required_with' => 'Seleccione médico y fecha.', '*.integer' => 'Seleccione un identificador válido.', '*.exists' => 'El médico no existe.', 'fecha.date_format' => 'Ingrese una fecha válida (AAAA-MM-DD).', 'fecha.string' => 'Ingrese una fecha válida.'];
    }

    protected function getRedirectUrl(): string
    {
        return route('citas.create');
    }
}
