<?php

namespace App\Http\Requests;

use App\Models\PagoParticular;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegistrarPagoParticularRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'confirmacion' => ['required', 'accepted'],
            'clave_envio' => ['required', 'string', 'uuid'],
            'intencion' => ['nullable', 'string', 'max:100'],
            'valor_pagado' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/'],
            'fecha_pago' => ['required', 'date_format:Y-m-d'],
            'metodo_pago' => ['required', Rule::in(PagoParticular::METODOS)],
            'observacion' => ['nullable', 'string', 'encoding:UTF-8', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmacion.*' => 'Confirme el pago antes de registrarlo.',
            'clave_envio.*' => 'Vuelva a abrir el formulario de pago.',
            'intencion.*' => 'La confirmación no es válida. Vuelva a abrir el formulario de pago.',
            'valor_pagado.*' => 'Ingrese un importe válido con un máximo de dos decimales.',
            'fecha_pago.*' => 'Ingrese una fecha de pago válida.',
            'metodo_pago.*' => 'Seleccione un método de pago permitido.',
            'observacion.*' => 'Ingrese una observación de hasta 500 caracteres válidos.',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['_token', ...array_keys($this->rules())]) !== []) {
                $validator->errors()->add('solicitud', 'La solicitud contiene campos que no se pueden enviar.');
            }
        }];
    }
}
