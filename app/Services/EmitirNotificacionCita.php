<?php

namespace App\Services;

use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\NotificacionCita;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EmitirNotificacionCita
{
    /** @return array{asunto: string, mensaje: string, motivo_fallo: ?string, contacto: ?string, token: string} */
    public function vistaPrevia(Cita $cita, User $actor): array
    {
        $paciente = $cita->paciente;

        return [
            ...$this->componer($cita, $paciente),
            'token' => Crypt::encryptString(json_encode([
                'clave' => (string) Str::uuid(),
                'actor' => $actor->id,
                'cita' => $cita->id,
                'version' => $this->version($cita, $paciente),
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    public function registrar(Cita $referencia, User $usuario, string $token): NotificacionCita
    {
        try {
            $confirmacion = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            $confirmacion = null;
        }
        if (($confirmacion['actor'] ?? null) !== $usuario->id || ($confirmacion['cita'] ?? null) !== $referencia->id
            || ! is_string($confirmacion['clave'] ?? null) || ! Str::isUuid($confirmacion['clave'])
            || ! is_string($confirmacion['version'] ?? null)) {
            throw ValidationException::withMessages(['vista_previa' => 'Vuelva a consultar la cita y revise la vista previa.']);
        }

        return DB::transaction(function () use ($referencia, $usuario, $confirmacion): NotificacionCita {
            $actor = User::query()->sharedLock()->findOrFail($usuario->id);
            $paciente = Paciente::query()->sharedLock()->findOrFail($referencia->paciente_id);
            $cita = Cita::query()->lockForUpdate()->findOrFail($referencia->id);
            Gate::forUser($actor)->authorize('notificarCita', $cita);

            $existente = $cita->notificaciones()->where('user_id', $actor->id)
                ->where('clave_confirmacion', $confirmacion['clave'])->lockForUpdate()->first();
            if ($existente !== null) {
                return $existente;
            }
            if ($paciente->id !== $cita->paciente_id || ! hash_equals($this->version($cita, $paciente), $confirmacion['version'])) {
                throw ValidationException::withMessages(['vista_previa' => 'La cita o el contacto cambiaron. Revise la nueva vista previa antes de confirmar.']);
            }

            $contenido = $this->componer($cita, $paciente);
            $intento = $cita->notificaciones()->create([
                'user_id' => $actor->id,
                'contacto_destino' => $contenido['motivo_fallo'] === null ? $contenido['contacto'] : null,
                'asunto' => $contenido['asunto'],
                'mensaje' => $contenido['mensaje'],
                'canal' => 'SIMULADO',
                'estado' => $contenido['motivo_fallo'] === null ? 'SIMULADA' : 'NO_ENVIADA',
                'fecha_intento' => now(),
                'motivo_fallo' => $contenido['motivo_fallo'],
                'clave_confirmacion' => $confirmacion['clave'],
            ]);
            throw_unless($intento->exists, RuntimeException::class, 'No fue posible registrar el intento de notificación.');
            $auditoria = Auditoria::query()->create([
                'user_id' => $actor->id,
                'accion' => 'NOTIFICACION_CITA_REGISTRADA',
                'entidad' => 'NotificacionCita',
                'registro_id' => $intento->id,
                'fecha_hora' => $intento->fecha_intento,
                'resultado' => $intento->estado,
                'descripcion' => 'Intento de simulación interna para la cita #'.$cita->id.'.',
            ]);
            throw_unless($auditoria->exists, RuntimeException::class, 'No fue posible registrar la auditoría del intento.');

            return $intento;
        }, attempts: 3);
    }

    /** @return array{asunto: string, mensaje: string, motivo_fallo: ?string, contacto: ?string} */
    private function componer(Cita $cita, Paciente $paciente): array
    {
        $horario = $cita->horario();
        [$asunto, $mensaje] = match ($cita->estado) {
            'PROGRAMADA' => ['Horario de cita', "Su cita {$cita->modalidad} está programada para {$horario} (hora de Colombia)."],
            'CANCELADA' => ['Cancelación de cita', "Su cita {$cita->modalidad} del {$horario} (hora de Colombia) fue cancelada."],
            'EN_ATENCION' => ['Estado de cita', "Su cita {$cita->modalidad} del {$horario} (hora de Colombia) figura en atención."],
            'ATENDIDA' => ['Estado de cita', "Su cita {$cita->modalidad} del {$horario} (hora de Colombia) figura como atendida."],
        };
        $contacto = trim((string) $paciente->correo_electronico);
        $motivo = match (true) {
            $contacto === '' => 'El paciente no tiene correo registrado.',
            strlen($contacto) > 255 || ! filter_var($contacto, FILTER_VALIDATE_EMAIL) => 'El correo registrado no es válido.',
            default => null,
        };

        return ['asunto' => $asunto, 'mensaje' => $mensaje, 'motivo_fallo' => $motivo, 'contacto' => $contacto !== '' ? $contacto : null];
    }

    private function version(Cita $cita, Paciente $paciente): string
    {
        return hash('sha256', serialize([
            $cita->versionHorario(), $cita->modalidad, $cita->medico_id, $cita->paciente_id, $paciente->correo_electronico,
        ]));
    }
}
