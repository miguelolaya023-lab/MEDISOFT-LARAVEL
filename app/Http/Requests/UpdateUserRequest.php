<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email'))), 'numero_documento' => trim((string) $this->input('numero_documento'))]);
    }

    public function rules(): array
    {
        return [
            'tipo_documento' => ['required', 'string', 'max:20'], 'numero_documento' => ['required', 'string', 'max:30', Rule::unique(User::class, 'numero_documento')->ignore($this->route('user'))],
            'nombres' => ['required', 'string', 'max:100'], 'apellidos' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($this->route('user'))], 'telefono' => ['required', 'string', 'max:30'], 'cargo' => ['required', 'string', 'max:100'],
            'registro_profesional' => ['sometimes', 'required', 'string', 'max:100', Rule::prohibitedIf(! $this->route('user')->medico()->exists())],
            'especialidad' => ['sometimes', 'required', 'string', 'max:100', Rule::prohibitedIf(! $this->route('user')->medico()->exists())],
            'estado' => ['prohibited'], 'rol_id' => ['prohibited'], 'permisos' => ['prohibited'], 'permissions' => ['prohibited'], 'password' => ['prohibited'],
        ];
    }
}
