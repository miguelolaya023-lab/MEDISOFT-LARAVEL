<?php

namespace App\Http\Requests;

use App\Models\RemisionEps;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class QueryRemisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->routeIs('remisiones.search') ? 'search' : 'viewAny', RemisionEps::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('buscar'))) {
            $this->merge(['buscar' => trim($this->input('buscar'))]);
        }
    }

    public function rules(): array
    {
        return ['eps_id' => ['bail', 'nullable', 'integer', Rule::exists('eps', 'id')], ...($this->routeIs('remisiones.search')
            ? ['buscar' => ['nullable', 'string', 'max:100']]
            : ['estado' => ['nullable', 'string', Rule::in(RemisionEps::ESTADOS)],
                'vigencia_desde' => ['bail', 'nullable', 'string', 'date_format:Y-m-d'],
                'vigencia_hasta' => ['bail', 'nullable', 'string', 'date_format:Y-m-d']])];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->routeIs('remisiones.search') && ! $validator->errors()->has('vigencia_desde') && ! $validator->errors()->has('vigencia_hasta')) {
                $desde = $this->input('vigencia_desde');
                $hasta = $this->input('vigencia_hasta');
                if ($desde !== null && $desde !== '' && $hasta !== null && $hasta !== '' && $desde > $hasta) {
                    $validator->errors()->add('vigencia_hasta', 'La fecha final del rango no puede ser anterior a la fecha inicial.');
                }
            }
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $campo) {
                $validator->errors()->add($campo, 'Este filtro no forma parte de la consulta de remisiones.');
            }
        }];
    }

    public function messages(): array
    {
        return ['eps_id.exists' => 'La EPS seleccionada no existe.', 'eps_id.integer' => 'Seleccione una EPS válida.', 'estado.in' => 'Seleccione un estado válido.', '*.string' => 'Ingrese texto válido.', '*.max' => 'La búsqueda no puede superar 100 caracteres.', '*.date_format' => 'Ingrese una fecha válida (AAAA-MM-DD).'];
    }

    protected function getRedirectUrl(): string
    {
        return route($this->routeIs('remisiones.search') ? 'remisiones.search' : 'remisiones.index');
    }
}
