<?php

namespace App\Http\Requests;

use App\Models\Eps;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\ViewErrorBag;
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
        foreach (['nit', 'nombre', 'telefono', 'correo_electronico', 'direccion', 'motivo'] as $campo) {
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
            'nit' => $eps instanceof Eps ? ['missing'] : ['bail', 'required', 'string', 'max:50', Rule::unique(Eps::class)],
            'nombre' => ['required', 'string', 'max:150'],
            'telefono' => ['required', 'string', 'max:30'],
            'correo_electronico' => ['required', 'string', 'email', 'max:255'],
            'direccion' => ['required', 'string', 'max:255'],
            'ciudad' => ['missing'],
            ...GeographicSelection::rules(! ($eps instanceof Eps) || $this->exists('departamento_codigo') || $this->exists('municipio_codigo'), $this->input('departamento_codigo')),
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
            'ciudad.missing' => 'La ciudad textual se conserva o se determina en el servidor; no envíe este campo.',
            '*.exists' => 'Seleccione un departamento y municipio oficiales compatibles.',
            '*.regex' => 'Seleccione un código geográfico válido.',
            '*.required' => 'El campo :attribute es obligatorio.',
            '*.string' => 'El campo :attribute debe ser texto.',
            '*.max' => 'El campo :attribute no puede superar :max caracteres.',
            'nit.unique' => self::DUPLICATE_NIT_MESSAGE,
            'nit.missing' => 'El NIT no se puede modificar ni enviar al actualizar una EPS.',
            'correo_electronico.email' => 'Ingrese un correo electrónico válido.',
            'estado.in' => 'Seleccione ACTIVA o INACTIVA.',
            'motivo.required' => 'Ingrese un motivo administrativo para cambiar el estado.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['departamento_codigo' => 'departamento', 'municipio_codigo' => 'municipio', 'nit' => 'NIT', 'telefono' => 'teléfono', 'correo_electronico' => 'correo electrónico', 'direccion' => 'dirección'];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($this->route('eps') instanceof Eps && $validator->errors()->has('nit') && ! $this->expectsJson()) {
            $this->flashOnly(['nombre', 'telefono', 'correo_electronico', 'direccion', 'estado', 'motivo', 'departamento_codigo', 'municipio_codigo']);
            throw new HttpResponseException(response()->view('eps.edit', [
                ...GeographicSelection::options(),
                'eps' => $this->route('eps'),
                'errors' => (new ViewErrorBag)->put('default', $validator->errors()),
            ], 422));
        }

        parent::failedValidation($validator);
    }

    protected function getRedirectUrl(): string
    {
        return $this->routeIs('eps.store') ? route('eps.create') : route('eps.edit', $this->route('eps'));
    }
}
