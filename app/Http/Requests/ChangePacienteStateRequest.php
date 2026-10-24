<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ChangePacienteStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $accion = $this->routeIs('pacientes.inactivate') ? 'inactivate' : 'reactivate';

        return $this->user()->can($accion, $this->route('paciente'));
    }

    protected function prepareForValidation(): void
    {
        $motivo = $this->input('motivo');
        $this->merge(['motivo' => is_string($motivo) ? trim($motivo) : $motivo]);
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:500'],
            'confirmacion' => ['required', 'accepted'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['motivo', 'confirmacion', '_token', '_method']) as $campo) {
                $validator->errors()->add($campo, 'Este campo no se puede modificar desde el cambio de estado.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Ingrese un motivo administrativo para el cambio de estado.',
            'motivo.string' => 'El motivo debe ser texto.',
            'motivo.max' => 'El motivo no puede superar 500 caracteres.',
            'confirmacion.required' => 'Debe confirmar el cambio de estado.',
            'confirmacion.accepted' => 'Debe confirmar el cambio de estado.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('pacientes.state', $this->route('paciente'));
    }
}
