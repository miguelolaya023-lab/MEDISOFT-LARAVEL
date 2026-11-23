<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GuardarAtencionClinicaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['motivo_consulta', 'diagnostico_principal', 'observacion_final'] as $campo) {
            $texto = $this->input($campo);
            if (is_string($texto)) {
                $this->merge([$campo => str_replace(["\r\n", "\r"], "\n", $texto)]);
            }
        }
    }

    /** @return array<string, array<int, string|Closure>> */
    public function rules(): array
    {
        $limiteTexto = static function (string $attribute, mixed $value, Closure $fail): void {
            if (strlen($value) > 65535) {
                $fail('El texto supera la capacidad de almacenamiento del campo.');
            }
        };

        $formatoVersion = ['string', 'regex:/^[a-f0-9]{64}$/D'];
        if ($this->routeIs('atenciones.clinica.finalizar')) {
            return [
                'observacion_final' => ['bail', 'nullable', 'string', $limiteTexto],
                'confirmacion' => ['required', 'accepted'],
                'version' => ['sometimes', 'required', ...$formatoVersion],
            ];
        }
        $campo = $this->routeIs('atenciones.clinica.motivo') ? 'motivo_consulta' : 'diagnostico_principal';

        return [
            'version' => ['required', ...$formatoVersion],
            $campo => ['bail', 'required', 'string', $limiteTexto],
        ];
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_method', '_token']) as $campo) {
                $validator->errors()->add($campo, 'Este campo no pertenece a la operación clínica.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'version.*' => 'Vuelva a abrir la atención antes de confirmar.',
            'motivo_consulta.required' => 'Ingrese el motivo clínico de consulta.',
            'motivo_consulta.string' => 'El motivo clínico debe ser texto.',
            'diagnostico_principal.required' => 'Ingrese el diagnóstico principal.',
            'diagnostico_principal.string' => 'El diagnóstico principal debe ser texto.',
            'confirmacion.*' => 'Confirme que desea finalizar la atención.',
            'observacion_final.string' => 'La observación final debe ser texto.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('atenciones.clinica.show', $this->route('atencion'));
    }
}
