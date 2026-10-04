<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email'))), 'numero_documento' => trim((string) $this->input('numero_documento'))]);
    }

    public function rules(): array
    {
        return [
            'tipo_documento' => ['required', 'string', 'max:20'], 'numero_documento' => ['required', 'string', 'max:30', 'unique:users,numero_documento'],
            'nombres' => ['required', 'string', 'max:100'], 'apellidos' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'telefono' => ['required', 'string', 'max:30'], 'cargo' => ['required', 'string', 'max:100'],
            'estado' => ['required', 'in:activo,inactivo'], 'rol_id' => ['required', 'integer', 'exists:roles,id'],
            'permisos' => ['prohibited'], 'permissions' => ['prohibited'],
            'es_medico' => ['sometimes', 'boolean'],
            'registro_profesional' => ['required_if:es_medico,1', 'prohibited_unless:es_medico,1', 'nullable', 'string', 'max:100'],
            'especialidad' => ['required_if:es_medico,1', 'prohibited_unless:es_medico,1', 'nullable', 'string', 'max:100'],
        ];
    }
}
