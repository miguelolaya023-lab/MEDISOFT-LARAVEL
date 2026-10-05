<?php

namespace App\Http\Requests;

use App\Models\Paciente;
use Illuminate\Foundation\Http\FormRequest;

class SearchPacienteRequest extends FormRequest
{
    protected $redirectRoute = 'pacientes.search';

    public function authorize(): bool
    {
        return $this->user()->can('search', Paciente::class);
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return ['buscar' => ['nullable', 'string', 'max:200']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['buscar.string' => 'Ingrese un documento o nombre válido.', 'buscar.max' => 'La búsqueda no puede superar 200 caracteres.'];
    }
}
