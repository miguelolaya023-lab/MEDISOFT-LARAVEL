<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ConfirmarLiquidacionParticularRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'confirmacion' => ['required', 'accepted'],
            'version_importe' => ['required', 'string', 'size:64', 'regex:/\A[0-9a-f]{64}\z/'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmacion.required' => 'Confirme la liquidación para continuar.',
            'confirmacion.accepted' => 'Confirme la liquidación para continuar.',
            'version_importe.*' => 'Vuelva a consultar el importe antes de confirmar.',
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['_token', 'confirmacion', 'version_importe']) !== []) {
                $validator->errors()->add('solicitud', 'La solicitud contiene campos que no se pueden enviar.');
            }
        }];
    }
}
