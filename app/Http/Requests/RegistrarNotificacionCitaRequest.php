<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegistrarNotificacionCitaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        abort_if(array_diff(array_keys($this->all()), ['_token', 'confirmacion', 'vista_previa']), 422,
            'La solicitud contiene campos ajenos a la simulación.');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'confirmacion' => ['required', 'accepted'],
            'vista_previa' => ['required', 'string', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirmacion.*' => 'Confirme el contacto registrado y la simulación para continuar.',
            'vista_previa.*' => 'Vuelva a consultar la cita y revise la vista previa.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('notificaciones.citas.index', ['cita_id' => $this->route('cita')->id]);
    }
}
