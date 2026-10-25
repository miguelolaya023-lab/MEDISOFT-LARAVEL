<?php

namespace App\Http\Requests;

use App\Models\Eps;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class QueryEpsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->routeIs('eps.search') ? 'search' : 'viewAny', Eps::class);
    }

    protected function prepareForValidation(): void
    {
        foreach (['buscar', 'ciudad'] as $campo) {
            if (is_string($this->input($campo))) {
                $this->merge([$campo => trim($this->input($campo))]);
            }
        }
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return $this->routeIs('eps.search') ? ['buscar' => ['nullable', 'string', 'max:200']] : [
            'ciudad' => ['nullable', 'string', 'max:100'], 'estado' => ['nullable', 'string', Rule::in(Eps::ESTADOS)],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $campo) {
                $validator->errors()->add($campo, 'Este filtro no forma parte de la consulta de EPS.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['*.string' => 'Ingrese texto válido para :attribute.', '*.max' => 'El campo :attribute no puede superar :max caracteres.', 'estado.in' => 'Seleccione ACTIVA o INACTIVA, o limpie el filtro.'];
    }

    protected function getRedirectUrl(): string
    {
        return route($this->routeIs('eps.search') ? 'eps.search' : 'eps.index');
    }
}
