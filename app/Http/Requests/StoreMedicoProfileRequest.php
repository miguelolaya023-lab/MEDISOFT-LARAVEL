<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreMedicoProfileRequest extends FormRequest
{
    public const DUPLICATE_PROFILE_MESSAGE = 'El usuario ya tiene un perfil profesional Médico. Utilice la edición del perfil existente.';

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    protected function prepareForValidation(): void
    {
        foreach (['registro_profesional', 'especialidad'] as $campo) {
            $valor = $this->input($campo);
            if (is_string($valor)) {
                $this->merge([$campo => trim($valor)]);
            }
        }
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'registrar_perfil_medico' => ['required', 'accepted'],
            'registro_profesional' => ['required', 'string', 'max:100'],
            'especialidad' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['registrar_perfil_medico', 'registro_profesional', 'especialidad', '_token']) as $campo) {
                $validator->errors()->add($campo, 'Este campo no se puede modificar al registrar el perfil profesional.');
            }
            if ($this->route('user')->medico()->exists()) {
                $validator->errors()->add('registrar_perfil_medico', self::DUPLICATE_PROFILE_MESSAGE);
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'registrar_perfil_medico.required' => 'Debe solicitar explícitamente el registro del perfil profesional Médico.',
            'registrar_perfil_medico.accepted' => 'Debe solicitar explícitamente el registro del perfil profesional Médico.',
            'registro_profesional.required' => 'Ingrese el registro profesional.',
            'especialidad.required' => 'Ingrese la especialidad.',
            '*.string' => 'El campo :attribute debe ser texto.',
            '*.max' => 'El campo :attribute no puede superar :max caracteres.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['registro_profesional' => 'registro profesional'];
    }

    protected function getRedirectUrl(): string
    {
        return route('usuarios.edit', $this->route('user'));
    }
}
