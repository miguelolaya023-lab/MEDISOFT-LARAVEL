<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class RegistrarFormulaMedicaRequest extends FormRequest
{
    public const MAXIMO_DETALLES = 50;

    protected function prepareForValidation(): void
    {
        if ($this->has('observacion_formula')) {
            $this->merge(['observacion_formula' => $this->normalizar($this->input('observacion_formula'))]);
        }

        $detalles = $this->input('detalles_formula');
        if (is_array($detalles) && array_is_list($detalles) && count($detalles) <= self::MAXIMO_DETALLES) {
            $this->merge(['detalles_formula' => array_map(
                fn (mixed $detalle): mixed => is_array($detalle) ? array_map($this->normalizar(...), $detalle) : $detalle,
                $detalles,
            )]);
        }
    }

    public function rules(): array
    {
        $reglas = [
            'clave_envio' => ['required', 'uuid'],
            'observacion_formula' => ['bail', 'nullable', 'string', $this->textoValido()],
            'detalles_formula' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.self::MAXIMO_DETALLES],
        ];
        $detalles = $this->input('detalles_formula');
        if (! is_array($detalles) || ! array_is_list($detalles) || count($detalles) > self::MAXIMO_DETALLES) {
            return $reglas;
        }

        return [
            ...$reglas,
            'detalles_formula.*' => ['required', 'array:medicamento,dosis,frecuencia,duracion,indicaciones'],
            'detalles_formula.*.medicamento' => ['bail', 'required', 'string', $this->textoValido(), 'max:255'],
            'detalles_formula.*.dosis' => ['bail', 'required', 'string', $this->textoValido(), 'max:255'],
            'detalles_formula.*.frecuencia' => ['bail', 'nullable', 'string', $this->textoValido(), 'max:255'],
            'detalles_formula.*.duracion' => ['bail', 'nullable', 'string', $this->textoValido(), 'max:255'],
            'detalles_formula.*.indicaciones' => ['bail', 'nullable', 'string', $this->textoValido()],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['clave_envio', 'observacion_formula', 'detalles_formula', '_token'])) {
                $validator->errors()->add('solicitud', 'La solicitud contiene campos ajenos a la fórmula.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'clave_envio.*' => 'Vuelva a abrir el formulario antes de confirmar el registro.',
            'observacion_formula.string' => 'La observación debe ser texto.',
            'detalles_formula.required' => 'Agregue al menos un medicamento.',
            'detalles_formula.min' => 'Agregue al menos un medicamento.',
            'detalles_formula.list' => 'Los medicamentos deben enviarse como una lista ordenada.',
            'detalles_formula.array' => 'Envíe una lista de medicamentos.',
            'detalles_formula.max' => 'Se admiten hasta :max medicamentos por fórmula.',
            'detalles_formula.*.required' => 'Complete los datos del medicamento en la fila :position.',
            'detalles_formula.*.array' => 'La fila :position contiene campos inválidos.',
            'detalles_formula.*.*.string' => 'Ingrese texto válido en la fila :position.',
            'detalles_formula.*.*.max' => 'Un campo de la fila :position supera :max caracteres.',
            'detalles_formula.*.medicamento.required' => 'Ingrese el medicamento en la fila :position.',
            'detalles_formula.*.dosis.required' => 'Ingrese la dosis en la fila :position.',
        ];
    }

    private function normalizar(mixed $valor): mixed
    {
        if (! is_string($valor) || ! mb_check_encoding($valor, 'UTF-8')) {
            return $valor;
        }

        $texto = Str::trim(str_replace(["\r\n", "\r"], "\n", $valor));

        return $texto === '' ? null : $texto;
    }

    private function textoValido(): Closure
    {
        return static function (string $campo, string $valor, Closure $error): void {
            if (! mb_check_encoding($valor, 'UTF-8') || str_contains($valor, "\0") || strlen($valor) > 65535) {
                $error('Ingrese texto válido dentro de la capacidad del campo.');
            }
        };
    }
}
