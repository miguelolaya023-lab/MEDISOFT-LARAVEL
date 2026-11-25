<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BuscarComprobanteParticularRequest extends FormRequest
{
    public function rules(): array
    {
        return ['numero_recibo' => ['required', 'string', 'size:29', 'regex:/\APP-[0-7][0-9A-HJKMNP-TV-Z]{25}\z/']];
    }

    public function messages(): array
    {
        return ['numero_recibo.*' => 'Ingrese un número de recibo completo y válido.'];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        throw new HttpResponseException(response()->view('pagos-particulares.comprobantes.index', [
            'error' => $validator->errors()->first('numero_recibo'),
        ], 422));
    }
}
