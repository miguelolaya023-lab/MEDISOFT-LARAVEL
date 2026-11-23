<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IniciarAtencionMedicaRequest extends FormRequest
{
    public function rules(): array
    {
        return [];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['_token']) as $campo) {
                $validator->errors()->add($campo, 'Este campo no pertenece al inicio de la atención.');
            }
        }];
    }

    protected function getRedirectUrl(): string
    {
        return route('atenciones.show', $this->route('citaId'));
    }
}
