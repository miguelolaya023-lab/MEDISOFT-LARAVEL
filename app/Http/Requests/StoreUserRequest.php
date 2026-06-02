<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tipo_documento' => ['required', 'string', 'max:20'],
            'numero_documento' => ['required', 'string', 'max:30', 'unique:users,numero_documento'],
            'nombres' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'telefono' => ['required', 'string', 'max:30'],
            'cargo' => ['required', 'string', 'max:100'],
            'estado' => ['required', 'in:activo,inactivo'],
        ];
    }
}
