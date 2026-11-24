<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class GuardarTratamientoRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $texto = $this->input('descripcion_tratamiento');
        if (is_string($texto) && mb_check_encoding($texto, 'UTF-8')) {
            $this->merge(['descripcion_tratamiento' => Str::trim(str_replace(["\r\n", "\r"], "\n", $texto))]);
        }
    }

    public function rules(): array
    {
        return [
            'descripcion_tratamiento' => ['bail', 'required', 'string', static function (string $campo, string $texto, Closure $fail): void {
                if (! mb_check_encoding($texto, 'UTF-8') || str_contains($texto, "\0") || strlen($texto) > 65535) {
                    $fail('Ingrese una descripción de tratamiento válida que no supere la capacidad del campo.');
                }
            }],
            ...($this->isMethod('PATCH')
                ? ['version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]
                : ['clave_envio' => ['required', 'uuid']]),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_method', '_token'])) {
                $validator->errors()->add('solicitud', 'La solicitud contiene campos ajenos al tratamiento.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'descripcion_tratamiento.required' => 'Ingrese la descripción del tratamiento.',
            'descripcion_tratamiento.string' => 'La descripción del tratamiento debe ser texto.',
            'version.*' => 'Vuelva a abrir la atención antes de confirmar la corrección.',
            'clave_envio.*' => 'Vuelva a abrir la atención antes de registrar el tratamiento.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('atenciones.clinica.tratamientos.index', $this->route('atencion'));
    }
}
