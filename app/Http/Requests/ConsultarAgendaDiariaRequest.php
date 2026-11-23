<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConsultarAgendaDiariaRequest extends FormRequest
{
    public const FECHA_MINIMA = '1000-01-01';

    public const FECHA_MAXIMA = '9999-12-31';

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'fecha' => ['sometimes', 'bail', 'required', 'date_format:Y-m-d', 'after_or_equal:'.self::FECHA_MINIMA, 'before_or_equal:'.self::FECHA_MAXIMA],
            'medico_id' => ['sometimes', 'bail', 'required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fecha.*' => 'Ingrese una fecha válida en formato AAAA-MM-DD.',
            'medico_id.*' => 'Seleccione un identificador de médico válido.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('agenda.diaria.index');
    }
}
