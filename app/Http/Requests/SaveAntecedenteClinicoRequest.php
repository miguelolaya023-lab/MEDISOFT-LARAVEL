<?php

namespace App\Http\Requests;

use App\Models\AntecedenteClinico;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveAntecedenteClinicoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [AntecedenteClinico::class, $this->route('paciente')]);
    }

    protected function prepareForValidation(): void
    {
        foreach (['descripcion', 'tipo'] as $campo) {
            $valor = $this->input($campo);
            if (is_string($valor)) {
                $this->merge([$campo => trim($valor)]);
            }
        }
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return ['descripcion' => ['required', 'string', 'max:10000'], 'tipo' => ['nullable', 'string', 'max:100']];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['descripcion', 'tipo', '_token', '_method']) as $campo) {
                $validator->errors()->add($campo, 'Este campo no se puede enviar en el formulario de antecedentes.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'descripcion.required' => 'Ingrese la descripción del antecedente clínico.',
            'descripcion.string' => 'La descripción debe ser texto.',
            'descripcion.max' => 'La descripción no puede superar 10000 caracteres.',
            'tipo.string' => 'El tipo debe ser texto.',
            'tipo.max' => 'El tipo no puede superar 100 caracteres.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        if ($this->routeIs('antecedentes.update')) {
            return route('antecedentes.edit', ['paciente' => $this->route('paciente'), 'antecedente' => $this->route('antecedente')]);
        }

        return route('antecedentes.index', $this->route('paciente'));
    }
}
