<?php

namespace App\Http\Requests;

use App\Models\RemisionEps;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveRemisionRequest extends FormRequest
{
    public const DUPLICATE_MESSAGE = 'Ya existe una remisión con este número para la EPS seleccionada.';

    public function authorize(): bool
    {
        return $this->routeIs('remisiones.store') ? $this->user()->can('create', RemisionEps::class) : $this->user()->can('update', $this->route('remision'));
    }

    protected function prepareForValidation(): void
    {
        foreach (['numero_remision', 'servicio_autorizado', 'observacion', 'motivo'] as $campo) {
            if (is_string($this->input($campo))) {
                $this->merge([$campo => trim($this->input($campo))]);
            }
        }
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $rules = [
            'servicio_autorizado' => ['required', 'string', 'max:1000'],
            'vigencia_hasta' => ['bail', 'required', 'date_format:Y-m-d'],
            'observacion' => ['nullable', 'string', 'max:2000'],
        ];
        if ($this->routeIs('remisiones.store')) {
            return [...$rules,
                'paciente_id' => ['bail', 'required', 'integer', Rule::exists('pacientes', 'id')],
                'eps_id' => ['bail', 'required', 'integer', Rule::exists('eps', 'id')],
                'numero_remision' => ['bail', 'required', 'string', 'max:100', Rule::unique('remisiones_eps')->where('eps_id', is_scalar($this->input('eps_id')) ? $this->input('eps_id') : null)],
                'fecha_remision' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:'.RemisionEps::hoy()],
                'vigencia_hasta' => ['bail', 'required', 'date_format:Y-m-d'],
            ];
        }

        return [...$rules, 'motivo' => ['nullable', 'string', 'max:500']];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->routeIs('remisiones.store') && ! $validator->errors()->has('fecha_remision') && ! $validator->errors()->has('vigencia_hasta') && $this->input('vigencia_hasta') < $this->input('fecha_remision')) {
                $validator->errors()->add('vigencia_hasta', 'La vigencia no puede ser anterior a la emisión.');
            }
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token', '_method']) as $campo) {
                $validator->errors()->add($campo, 'Este campo no pertenece a esta operación de remisiones.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['*.required' => 'El campo :attribute es obligatorio.', '*.string' => 'El campo :attribute debe ser texto.', '*.integer' => 'Seleccione un identificador válido.', '*.exists' => 'El registro seleccionado no existe.', '*.max' => 'El campo :attribute no puede superar :max caracteres.', '*.date_format' => 'Ingrese una fecha válida (AAAA-MM-DD).', 'fecha_remision.before_or_equal' => 'La emisión no puede ser futura.', 'vigencia_hasta.after_or_equal' => 'La vigencia no puede ser anterior a la emisión.', 'numero_remision.unique' => self::DUPLICATE_MESSAGE];
    }

    protected function getRedirectUrl(): string
    {
        return $this->routeIs('remisiones.store') ? route('remisiones.create') : route('remisiones.edit', $this->route('remision'));
    }
}
