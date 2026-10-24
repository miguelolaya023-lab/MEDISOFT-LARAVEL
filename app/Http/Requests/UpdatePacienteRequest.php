<?php

namespace App\Http\Requests;

use App\Models\Paciente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * RF-009: edición administrativa; el estado se gestiona en su flujo separado.
 */
class UpdatePacienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('paciente'));
    }

    protected function prepareForValidation(): void
    {
        $documento = $this->input('tipo_documento');
        $numero = $this->input('numero_documento');
        $correo = $this->input('correo_electronico');
        $this->merge([
            'tipo_documento' => is_string($documento) ? Str::upper(trim($documento)) : $documento,
            'numero_documento' => is_string($numero) ? trim($numero) : $numero,
            'correo_electronico' => is_string($correo) ? Str::lower(trim($correo)) : $correo,
        ]);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'tipo_documento' => ['required', 'string', 'max:20', Rule::in(array_keys(Paciente::TIPOS_DOCUMENTO))],
            'numero_documento' => ['required', 'string', 'max:30', Rule::unique(Paciente::class)
                ->where('tipo_documento', is_string($this->input('tipo_documento')) ? $this->input('tipo_documento') : null)
                ->ignore($this->route('paciente'))],
            'nombres' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'fecha_nacimiento' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sexo' => ['required', 'string', 'max:50', Rule::in(Paciente::SEXOS)],
            'telefono' => ['required', 'string', 'max:30'],
            'correo_electronico' => ['nullable', 'email', 'max:255'],
            'direccion' => ['required', 'string', 'max:255'],
            'ciudad' => ['required', 'string', 'max:100'],
            'tipo_paciente' => ['required', Rule::in(['PARTICULAR', 'EPS'])],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $permitidos = [...array_keys($this->rules()), '_token', '_method'];
            foreach (array_diff(array_keys($this->all()), $permitidos) as $campo) {
                $validator->errors()->add($campo, 'Este campo no se puede modificar desde la edición administrativa.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            '*.required' => 'El campo :attribute es obligatorio.',
            '*.string' => 'El campo :attribute debe ser texto.',
            '*.max' => 'El campo :attribute no puede superar :max caracteres.',
            'numero_documento.unique' => StorePacienteRequest::DUPLICATE_DOCUMENT_MESSAGE,
            'tipo_documento.in' => 'Seleccione un tipo de documento válido: CC, TI, RC, CE, PA o PT.',
            'sexo.in' => 'Seleccione un sexo válido: MASCULINO, FEMENINO, OTRO o NO_ESPECIFICA.',
            'fecha_nacimiento.date_format' => 'Ingrese una fecha de nacimiento válida.',
            'fecha_nacimiento.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
            'correo_electronico.email' => 'Ingrese un correo electrónico válido o deje el campo vacío.',
            'tipo_paciente.in' => 'Seleccione PARTICULAR o EPS como preferencia del paciente.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'tipo_documento' => 'tipo de documento', 'numero_documento' => 'número de documento',
            'fecha_nacimiento' => 'fecha de nacimiento', 'correo_electronico' => 'correo electrónico',
            'tipo_paciente' => 'preferencia del paciente', 'telefono' => 'teléfono', 'direccion' => 'dirección',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('pacientes.edit', $this->route('paciente'));
    }
}
