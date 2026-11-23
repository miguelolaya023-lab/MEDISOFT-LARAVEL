<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\NotificacionCita;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\RemisionEps;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\NotificacionCitaPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificacionCitaTest extends TestCase
{
    use RefreshDatabase;

    private const CONTACTO = 'paciente-notificacion@example.test';

    protected function beforeRefreshingDatabase(): void
    {
        $db = DB::connection();
        if (! $this->app->environment('testing') || $this->app->configurationIsCached()
            || $db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:' || $db->getConfig('url')) {
            throw new LogicException('La prueba requiere SQLite en memoria.');
        }
    }

    private function operador(string $nombreRol = 'Administrativo', string $codigo = 'RF-061'): User
    {
        $rol = Rol::query()->firstOrCreate(['nombre' => $nombreRol]);
        $permiso = Permiso::query()->firstOrCreate(['codigo' => $codigo], ['descripcion' => 'Permiso de prueba']);
        $rol->permisos()->syncWithoutDetaching([$permiso->id]);

        return User::factory()->for($rol)->create();
    }

    private function cita(?string $contacto = self::CONTACTO, string $modalidad = 'PARTICULAR', string $estado = 'PROGRAMADA'): Cita
    {
        $paciente = Paciente::factory()->create(['correo_electronico' => $contacto]);
        $atributos = $modalidad === 'EPS'
            ? ['modalidad' => 'EPS', 'valor_base' => null, 'remision_id' => RemisionEps::factory()->for($paciente)->create()->id]
            : [];

        return Cita::factory()->for($paciente)->create([
            ...$atributos, 'estado' => $estado, 'motivo_consulta' => 'Motivo privado ficticio',
        ])->refresh();
    }

    private function vista(Cita $cita): string
    {
        return route('notificaciones.citas.index', ['cita_id' => $cita->id]);
    }

    private function confirmar(Cita $cita): array
    {
        $preview = $this->get($this->vista($cita))->assertOk()->viewData('vistaPrevia');

        return ['confirmacion' => '1', 'vista_previa' => $preview['token']];
    }

    public function test_ca06101_registra_evidencia_cifrada_y_auditoria_sin_trasmitir_ni_mutar(): void
    {
        $operador = $this->operador();
        $cita = $this->cita();
        $citaAntes = $cita->getAttributes();
        $pacienteAntes = $cita->paciente->getAttributes();
        Mail::fake();
        Notification::fake();
        Queue::fake();

        $this->actingAs($operador)->get(route('notificaciones.citas.index'))
            ->assertOk()->assertSee('Consultar cita')->assertSee('Notificaciones de citas');
        $datos = $this->confirmar($cita);
        $this->assertDatabaseCount('notificaciones_cita', 0);
        $resultado = $this->post(route('notificaciones.citas.store', $cita), $datos)->assertRedirect();
        $intento = $cita->notificaciones()->sole();
        $this->assertSame([$operador->id, 'SIMULADO', 'SIMULADA', self::CONTACTO], [
            $intento->user_id, $intento->canal, $intento->estado, $intento->contacto_destino,
        ]);
        foreach (['contacto_destino', 'asunto', 'mensaje'] as $campo) {
            $this->assertNotSame($intento->$campo, $intento->getRawOriginal($campo));
            $this->assertArrayNotHasKey($campo, $intento->toArray());
        }
        $auditoria = Auditoria::query()->sole();
        $this->assertSame([$operador->id, $intento->id, 'SIMULADA'], [$auditoria->user_id, $auditoria->registro_id, $auditoria->resultado]);
        $this->assertStringNotContainsString(self::CONTACTO, $auditoria->descripcion);
        $this->assertStringNotContainsString($intento->mensaje, $auditoria->descripcion);
        $respuesta = $this->get($resultado->headers->get('Location'))->assertOk()->assertSee('SIMULADA')
            ->assertSee(self::CONTACTO)->assertSee($cita->horario())->assertDontSee('Motivo privado ficticio')
            ->assertSee('Simulación interna. No se envió ningún correo ni mensaje al paciente.');
        $this->assertTrue($respuesta->headers->hasCacheControlDirective('no-store'));
        $this->get($this->vista($cita))->assertOk()->assertSee('Ver evidencia #'.$intento->id);
        $this->assertSame($citaAntes, $cita->refresh()->getAttributes());
        $this->assertSame($pacienteAntes, $cita->paciente->refresh()->getAttributes());
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function contactosInvalidos(): array
    {
        return [[null, 'El paciente no tiene correo registrado.'], ['contacto-sin-arroba', 'El correo registrado no es válido.']];
    }

    #[DataProvider('contactosInvalidos')]
    public function test_ca06102_registra_fallo_por_contacto_ausente_o_invalido(?string $contacto, string $motivo): void
    {
        $cita = $this->cita($contacto);
        $this->actingAs($this->operador());
        $resultado = $this->post(route('notificaciones.citas.store', $cita), $this->confirmar($cita))->assertRedirect();
        $intento = $cita->notificaciones()->sole();
        $this->assertSame('NO_ENVIADA', $intento->estado);
        $this->assertSame($motivo, $intento->motivo_fallo);
        $this->assertNull($intento->contacto_destino);
        $this->assertSame('NO_ENVIADA', Auditoria::query()->sole()->resultado);
        $this->get($resultado->headers->get('Location'))->assertOk()->assertSee('NO_ENVIADA')->assertSee($motivo);
    }

    public static function avisos(): array
    {
        return [
            ['PROGRAMADA', 'PARTICULAR', 'está programada para'],
            ['CANCELADA', 'EPS', 'fue cancelada'],
            ['EN_ATENCION', 'PARTICULAR', 'figura en atención'],
            ['ATENDIDA', 'EPS', 'figura como atendida'],
        ];
    }

    #[DataProvider('avisos')]
    public function test_compone_mensaje_segun_estado_y_modalidad_con_ca06103(string $estado, string $modalidad, string $texto): void
    {
        $cita = $this->cita(modalidad: $modalidad, estado: $estado);
        $this->actingAs($this->operador('Administrador'));
        $this->post(route('notificaciones.citas.store', $cita), $this->confirmar($cita))->assertRedirect();
        $mensaje = $cita->notificaciones()->sole()->mensaje;

        $this->assertStringContainsString($modalidad, $mensaje);
        $this->assertStringContainsString($texto, $mensaje);
        $this->assertStringNotContainsString('Motivo privado ficticio', $mensaje);
        if ($estado !== 'PROGRAMADA') {
            $this->assertStringNotContainsString('programada', $mensaje);
            $this->assertStringNotContainsString('acuda', $mensaje);
        }
    }

    public function test_ca06104_deniega_acceso_invalido_a_vista_post_e_historial(): void
    {
        $cita = $this->cita();
        $titular = $cita->medico->user;
        $titular->update(['rol_id' => $this->operador('Médico')->rol_id]);
        $ajeno = $this->operador('Médico');
        $ajeno->medico()->create(['registro_profesional' => 'DEMO-AJENO', 'especialidad' => 'Medicina general']);
        $url = route('notificaciones.citas.store', $cita);
        $this->get($this->vista($cita))->assertRedirect(route('login'));
        $this->actingAs($titular);
        $datos = $this->confirmar($cita);
        $this->postJson($url, ['vista_previa' => $datos['vista_previa']])->assertUnprocessable()->assertJsonValidationErrors('confirmacion');
        $this->postJson($url, [...$datos, 'contacto_destino' => 'externo@example.test'])->assertUnprocessable();
        $this->postJson($url, [...$datos, 'vista_previa' => 'alterada'])->assertUnprocessable()->assertJsonValidationErrors('vista_previa');
        $this->assertDatabaseCount('notificaciones_cita', 0);
        $this->assertDatabaseCount('auditorias', 0);

        $detalle = $this->post($url, $datos)->assertRedirect()->headers->get('Location');
        $otraCita = $this->cita();
        $intento = NotificacionCita::query()->sole();
        $this->actingAs($ajeno)->get($this->vista($cita))->assertNotFound()->assertDontSee(self::CONTACTO);
        $this->postJson($url, $datos)->assertNotFound();
        $this->get($detalle)->assertNotFound()->assertDontSee(self::CONTACTO);
        $this->actingAs($this->operador('Solo agenda', 'RF-032'))->get($detalle)->assertForbidden();
        $this->postJson($url, $datos)->assertForbidden();
        $this->get($this->vista($cita))->assertForbidden()->assertDontSee(self::CONTACTO);
        $this->actingAs($this->operador('Administrador'))->get(route('notificaciones.citas.show', [$otraCita, $intento]))->assertNotFound();
        $inactivo = $this->operador();
        $inactivo->update(['estado' => 'inactivo']);
        $this->actingAs($inactivo)->get($detalle)->assertRedirect(route('login'));
        $this->assertDatabaseCount('notificaciones_cita', 1);
        $this->assertDatabaseCount('auditorias', 1);
    }

    public static function cambios(): array
    {
        return [['contacto'], ['horario'], ['cancelacion']];
    }

    #[DataProvider('cambios')]
    public function test_rechaza_confirmacion_si_cambio_contacto_horario_o_estado(string $cambio): void
    {
        $cita = $this->cita();
        $this->actingAs($this->operador());
        $datos = $this->confirmar($cita);
        match ($cambio) {
            'contacto' => $cita->paciente->update(['correo_electronico' => 'nuevo-contacto@example.test']),
            'horario' => $cita->forceFill(['hora_inicio' => '08:30:00', 'hora_fin' => '09:00:00'])->save(),
            'cancelacion' => $cita->forceFill(['estado' => 'CANCELADA'])->save(),
        };

        $this->postJson(route('notificaciones.citas.store', $cita), $datos)
            ->assertUnprocessable()->assertJsonValidationErrors('vista_previa')->assertDontSee(self::CONTACTO);
        $this->assertDatabaseCount('notificaciones_cita', 0);
        $this->assertDatabaseCount('auditorias', 0);
        $this->post(route('notificaciones.citas.store', $cita), $this->confirmar($cita))->assertRedirect();
        $this->assertDatabaseCount('notificaciones_cita', 1);
    }

    public function test_repeticion_devuelve_la_misma_evidencia_y_nueva_confirmacion_crea_otro_intento(): void
    {
        $cita = $this->cita();
        $this->actingAs($this->operador());
        $datos = $this->confirmar($cita);
        $url = route('notificaciones.citas.store', $cita);
        $primera = $this->post($url, $datos)->assertRedirect()->headers->get('Location');
        $this->post($url, $datos)->assertRedirect($primera);
        $this->assertDatabaseCount('auditorias', 1);
        $siguiente = $this->post($url, $this->confirmar($cita))->assertRedirect()->headers->get('Location');
        $this->assertNotSame($primera, $siguiente);
        $this->assertDatabaseCount('notificaciones_cita', 2);
        $this->assertDatabaseCount('auditorias', 2);
    }

    public function test_un_fallo_de_auditoria_revierte_el_intento(): void
    {
        $cita = $this->cita();
        $this->actingAs($this->operador());
        $datos = $this->confirmar($cita);
        Auditoria::creating(fn () => false);
        $this->post(route('notificaciones.citas.store', $cita), $datos)->assertStatus(500);
        $this->assertDatabaseCount('notificaciones_cita', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_seeder_es_idempotente_y_conserva_permisos_anteriores(): void
    {
        $usuarios = array_map(fn ($rol) => $this->operador($rol, 'RF-032'), ['Administrador', 'Administrativo', 'Médico']);
        $ajeno = $this->operador('Otro rol', 'RF-032');
        $this->seed(NotificacionCitaPermissionsSeeder::class);
        $this->seed(NotificacionCitaPermissionsSeeder::class);
        foreach ($usuarios as $usuario) {
            $this->assertTrue($usuario->tienePermiso('RF-061'));
            $this->assertTrue($usuario->tienePermiso('RF-032'));
        }
        $this->assertFalse($ajeno->tienePermiso('RF-061'));
        $this->assertDatabaseCount('permiso_rol', 7);
    }
}
