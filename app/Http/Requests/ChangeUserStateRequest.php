<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangeUserStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('changeState', $this->route('user'));
    }

    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'max:500']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['motivo' => trim((string) $this->input('motivo'))]);
    }
}
