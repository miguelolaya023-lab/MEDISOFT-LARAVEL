<?php

namespace App\Http\Requests;

use App\Models\Eps;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveEpsRequest extends FormRequest
{
    public const DUPLICATE_NIT_MESSAGE = 'Ya existe una EPS con este NIT.';

    public function authorize(): bool
    {
        return $this->routeIs('eps.store')
            ? $this->user()->can('create', Eps::class)
            : $this->user()->can('update', $this->route('eps'));
    }

    protected function prepareForValidation(): void
    {
        foreach (['nit', 'nombre', 'telefono', 'correo_electronico', 'direccion', 'ciudad', 'motivo'] as $campo) {
            $valor = $this->input($campo);
            if (is_string($valor)) {
                $this->merge([$campo => $campo === 'nit' ? Eps::normalizarNit($valor) : trim($valor)]);
            }
        }
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $eps = $this->route('eps');
        $reglas = [
            'nit' => ['bail', 'required', 'string', 'max:50', Rule::unique(Eps::class)->ignore($eps)],
            'nombre' => ['required', 'string', 'max:150'],
            'telefono' => ['required', 'string', 'max:30'],
            'correo_electronico' => ['required', 'string', 'email', 'max:255'],
            'direccion' => ['required', 'string', 'max:255'],
            'ciudad' => ['required', 'string', 'max:100'],
            'estado' => ['required', 'string', Rule::in(Eps::ESTADOS)],
        ];
        if ($eps instanceof Eps) {
            $reglas['motivo'] = [Rule::requiredIf($eps->estado !== $this->input('estado')), 'nullable', 'string', 'max:400'];
        }

        return $reglas;
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token', '_method']) as $campo) {
                $validator->errors()->add($campo, 'Este campo no forma parte de la operación de EPS.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            '*.required' => 'El campo :attribute es obligatorio.',
            '*.string' => 'El campo :attribute debe ser texto.',
            '*.max' => 'El campo :attribute no puede superar :max caracteres.',
            'nit.unique' => self::DUPLICATE_NIT_MESSAGE,
            'correo_electronico.email' => 'Ingrese un correo electrónico válido.',
            'estado.in' => 'Seleccione ACTIVA o INACTIVA.',
            'motivo.required' => 'Ingrese un motivo administrativo para cambiar el estado.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['nit' => 'NIT', 'telefono' => 'teléfono', 'correo_electronico' => 'correo electrónico', 'direccion' => 'dirección'];
    }

    protected function getRedirectUrl(): string
    {
        return $this->routeIs('eps.store') ? route('eps.create') : route('eps.edit', $this->route('eps'));
    }
}
