<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReprogramarCitaRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'fecha' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'hora_inicio' => ['bail', 'required', 'string', 'date_format:H:i'],
            'version_horario' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'confirmacion' => ['required', 'accepted'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token', '_method']) as $campo) {
                $validator->errors()->add($campo, 'Este campo no pertenece a la reprogramación.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fecha.*' => 'Ingrese una fecha válida (AAAA-MM-DD).',
            'hora_inicio.*' => 'Seleccione una hora válida (HH:MM).',
            'version_horario.*' => 'Vuelva a consultar el horario antes de confirmar.',
            'confirmacion.*' => 'Confirme la reprogramación de la cita.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        $fecha = $this->validator->valid()['fecha'] ?? null;

        return route('citas.reprogramar.edit', ['citaId' => $this->route('citaId'), 'fecha' => $fecha]);
    }
}
