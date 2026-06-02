<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User
            && $user->tipo_usuario === User::TIPO_USUARIO_INTERNO;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'tipo_documento' => ['required', 'string', 'max:20'],
            'numero_documento' => [
                'required',
                'string',
                'max:30',
                Rule::unique(User::class, 'numero_documento')->ignore($user),
            ],
            'nombres' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique(User::class, 'email')->ignore($user),
            ],
            'telefono' => ['required', 'string', 'max:30'],
            'cargo' => ['required', 'string', 'max:100'],
        ];
    }
}
