<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ChangeRemisionStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('changeState', $this->route('remision'));
    }

    public function rules(): array
    {
        return ['estado' => ['required', Rule::in(['ANULADA'])], 'motivo' => ['required', 'string', 'max:500'], 'confirmacion' => ['required', 'accepted']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['estado', 'motivo', 'confirmacion', '_token', '_method']) as $campo) {
                $validator->errors()->add($campo, 'Este campo no pertenece al cambio de estado.');
            }
        }];
    }

    public function messages(): array
    {
        return ['estado.in' => 'Solo se permite la anulación manual; USADA corresponde a la finalización de atención (RF-031).', 'motivo.required' => 'Ingrese el motivo de anulación.', 'motivo.max' => 'El motivo no puede superar 500 caracteres.', 'confirmacion.accepted' => 'Confirme la anulación.', 'confirmacion.required' => 'Confirme la anulación.'];
    }

    protected function getRedirectUrl(): string
    {
        return route('remisiones.state', $this->route('remision'));
    }
}
