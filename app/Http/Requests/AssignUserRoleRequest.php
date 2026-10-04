<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assignRole', $this->route('user'));
    }

    public function rules(): array
    {
        return ['rol_id' => ['required', 'integer', 'exists:roles,id'], 'permisos' => ['prohibited'], 'permissions' => ['prohibited']];
    }
}
