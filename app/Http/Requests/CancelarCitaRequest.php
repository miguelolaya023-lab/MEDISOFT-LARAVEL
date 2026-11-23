<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CancelarCitaRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'motivo_cancelacion' => ['required', 'string', 'max:500'],
            'confirmacion' => ['required', 'accepted'],
            'version_horario' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token', '_method']) as $campo) {
                $validator->errors()->add($campo, 'Este campo no pertenece a la cancelación.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'motivo_cancelacion.required' => 'Ingrese el motivo de la cancelación.',
            'motivo_cancelacion.string' => 'El motivo de cancelación debe ser texto.',
            'motivo_cancelacion.max' => 'El motivo de cancelación admite hasta 500 caracteres.',
            'confirmacion.*' => 'Confirme la cancelación de la cita.',
            'version_horario.*' => 'Vuelva a consultar la cita antes de confirmar.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('citas.cancelar.show', $this->route('citaId'));
    }
}
