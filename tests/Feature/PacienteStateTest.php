<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\PacientePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Estados administrativos: CA-009-03, parte de CA-012-01 y CA-012-04.
 * RF-012.1 y CA-012-02/03 requieren integrar citas y atenciones futuras.
 */
class PacienteStateTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! $this->app->environment('testing') || config('database.default') !== 'sqlite') {
            throw new LogicException('Pruebas canceladas: se requiere SQLite en memoria.');
        }
        foreach ($this->connectionsToTransact() as $name) {
            $config = $this->app['db']->connection($name)->getConfig();
            if ($config['driver'] !== 'sqlite' || $config['database'] !== ':memory:' || ! empty($config['url'])) {
                throw new LogicException('Pruebas canceladas: conexión no aislada.');
            }
        }
    }

    /** @param array<string> $permisos */
    private function operador(array $permisos = ['RF-009', 'RF-010', 'RF-012']): User
    {
        $this->seed(PacientePermissionsSeeder::class);
        $rol = Rol::query()->create(['nombre' => 'Estados '.uniqid()]);
        $rol->permisos()->sync(Permiso::query()->whereIn('codigo', $permisos)->pluck('id'));

        return User::factory()->create(['rol_id' => $rol->id])->refresh();
    }

    private function paciente(string $estado = 'ACTIVO'): Paciente
    {
        $paciente = Paciente::factory()->create([
            'created_at' => now()->subYear(), 'updated_at' => now()->subMonth(),
        ]);
        $paciente->forceFill(['estado' => $estado])->save();
        $paciente->historiaClinica()->create(['fecha_apertura' => now()->subYear()]);

        return $paciente->refresh();
    }

    /** @return array<string, mixed> */
    private function datos(array $cambios = []): array
    {
        return ['motivo' => 'Solicitud administrativa del paciente', 'confirmacion' => '1', ...$cambios];
    }

    public static function transiciones(): array
    {
        return [['inactivate', 'ACTIVO', 'INACTIVO', 'RF-012'], ['reactivate', 'INACTIVO', 'ACTIVO', 'RF-009']];
    }

    public function test_state_form_identifies_patient_and_requires_reason_and_confirmation_without_clinical_data(): void
    {
        $paciente = $this->paciente();
        $this->actingAs($this->operador())->get(route('pacientes.state', $paciente))->assertOk()
            ->assertSee($paciente->nombres)->assertSee('ACTIVO')->assertSee('Inactivar paciente')
            ->assertSee('name="motivo"', false)->assertSee('name="confirmacion"', false)
            ->assertSee('sin diagnósticos, tratamientos ni otros datos clínicos')
            ->assertSee('Cancelar / Volver a la ficha')->assertDontSee('name="estado"', false)
            ->assertDontSee('name="user_id"', false)->assertDontSee($paciente->telefono)
            ->assertDontSee($paciente->direccion)->assertDontSee('Reactivar paciente');
        $this->get(route('pacientes.show', $paciente))->assertSee('Inactivar paciente')->assertDontSee('Reactivar paciente');
        $this->assertDatabaseCount('auditorias', 0);
    }

    #[DataProvider('transiciones')]
    public function test_valid_transition_preserves_all_other_patient_history_and_user_data(string $accion, string $origen, string $destino, string $permiso): void
    {
        $paciente = $this->paciente($origen);
        if ($origen === 'INACTIVO') {
            $paciente->forceFill(['motivo_inactivacion' => 'Motivo anterior conocido'])->save();
        }
        $paciente->forceFill(['updated_at' => now()->subDay()])->save();
        $antes = $paciente->refresh()->getAttributes();
        $historia = $paciente->historiaClinica->getAttributes();
        $otro = $this->paciente();
        $otroAntes = $otro->getAttributes();
        $operador = $this->operador();
        $usuarioAntes = $operador->getAttributes();
        $this->actingAs($operador)->patch(route('pacientes.'.$accion, $paciente), $this->datos(['motivo' => '  Revisión administrativa  ']))
            ->assertSessionHasNoErrors()->assertRedirect(route('pacientes.show', $paciente))->assertSessionHas('status');
        $esperado = $antes;
        $esperado['estado'] = $destino;
        $esperado['motivo_inactivacion'] = $destino === 'INACTIVO' ? 'Revisión administrativa' : 'Motivo anterior conocido';
        $despues = $paciente->refresh()->getAttributes();
        $this->assertNotSame($antes['updated_at'], $despues['updated_at']);
        unset($esperado['updated_at'], $despues['updated_at']);
        $this->assertSame($esperado, $despues);
        $this->assertSame($historia, $paciente->historiaClinica->getAttributes());
        $this->assertSame($otroAntes, $otro->refresh()->getAttributes());
        $this->assertSame($usuarioAntes, $operador->refresh()->getAttributes());
        $this->assertDatabaseCount('pacientes', 2);
        $this->assertDatabaseCount('historias_clinicas', 2);
        $audit = Auditoria::query()->sole();
        $this->assertSame($destino === 'INACTIVO' ? 'PACIENTE_INACTIVADO' : 'PACIENTE_REACTIVADO', $audit->accion);
        $this->assertSame('Paciente', $audit->entidad);
        $this->assertSame($paciente->id, $audit->registro_id);
        $this->assertSame($operador->id, $audit->user_id);
        $this->assertSame('EXITO', $audit->resultado);
        $this->assertSame('Revisión administrativa', $audit->descripcion);
        $this->assertNotNull($audit->fecha_hora);
        $this->get(route('pacientes.show', $paciente))->assertSee($destino)
            ->assertSee($destino === 'INACTIVO' ? 'Reactivar paciente' : 'Inactivar paciente');
    }

    public function test_multiple_cycles_preserve_each_reason_and_legacy_inactive_patients_have_no_invented_reason(): void
    {
        $paciente = $this->paciente('INACTIVO');
        $this->assertNull($paciente->motivo_inactivacion);
        $this->assertDatabaseCount('auditorias', 0);
        $this->actingAs($this->operador());
        foreach ([['reactivate', 'Alta inicial autorizada'], ['inactivate', 'Primera baja'], ['reactivate', 'Primera reactivación'], ['inactivate', 'Segunda baja'], ['reactivate', 'Segunda reactivación']] as [$accion, $motivo]) {
            $this->patch(route('pacientes.'.$accion, $paciente), $this->datos(['motivo' => $motivo]))->assertSessionHasNoErrors();
        }
        $this->assertSame('ACTIVO', $paciente->refresh()->estado);
        $this->assertSame('Segunda baja', $paciente->motivo_inactivacion);
        $this->assertSame(['Alta inicial autorizada', 'Primera baja', 'Primera reactivación', 'Segunda baja', 'Segunda reactivación'], Auditoria::query()->orderBy('id')->pluck('descripcion')->all());
        $this->assertDatabaseCount('pacientes', 1);
        $this->assertDatabaseCount('historias_clinicas', 1);
    }

    #[DataProvider('transiciones')]
    public function test_operation_permission_alone_is_sufficient_without_granting_other_permissions(string $accion, string $origen, string $destino, string $permiso): void
    {
        $paciente = $this->paciente($origen);
        $this->actingAs($this->operador([$permiso]));
        $this->get(route('pacientes.state', $paciente))->assertOk()->assertSee('name="motivo"', false);
        $opuesta = $accion === 'inactivate' ? 'reactivate' : 'inactivate';
        $this->patch(route('pacientes.'.$opuesta, $paciente), $this->datos())->assertForbidden();
        $this->get(route('pacientes.show', $paciente))->assertForbidden();
        $this->patch(route('pacientes.'.$accion, $paciente), $this->datos())->assertSessionHasNoErrors()->assertRedirect(route('pacientes.state', $paciente));
        $this->assertSame($destino, $paciente->refresh()->estado);
        $this->get(route('pacientes.state', $paciente))->assertOk()->assertDontSee('name="motivo"', false);
    }

    public function test_read_permissions_and_administrator_cargo_do_not_allow_state_operations(): void
    {
        $paciente = $this->paciente();
        $user = $this->operador(['RF-008', 'RF-010', 'RF-011']);
        $user->update(['cargo' => 'Administrador']);
        $this->actingAs($user)->get(route('pacientes.state', $paciente))->assertForbidden();
        foreach (['inactivate', 'reactivate'] as $accion) {
            $this->patch(route('pacientes.'.$accion, $paciente), $this->datos())->assertForbidden();
        }
        $this->get(route('pacientes.show', $paciente))->assertOk()->assertDontSee('Inactivar paciente')->assertDontSee('Reactivar paciente');
        $this->assertSame('ACTIVO', $paciente->refresh()->estado);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_guests_cannot_get_confirmation_or_submit_any_transition(): void
    {
        $paciente = $this->paciente();
        $this->get(route('pacientes.state', $paciente))->assertRedirect('/login');
        foreach (['inactivate', 'reactivate'] as $accion) {
            $this->patch(route('pacientes.'.$accion, $paciente), $this->datos())->assertRedirect('/login');
        }
        $this->assertSame('ACTIVO', $paciente->refresh()->estado);
        $this->assertDatabaseCount('auditorias', 0);
    }

    #[DataProvider('transiciones')]
    public function test_inactive_user_account_cannot_submit_transition(string $accion, string $origen, string $destino, string $permiso): void
    {
        $paciente = $this->paciente($origen);
        $before = $paciente->getAttributes();
        $user = $this->operador();
        $user->update(['estado' => 'inactivo']);
        $this->actingAs($user)->get(route('pacientes.state', $paciente))->assertRedirect('/login');
        $this->assertGuest();
        $this->actingAs($user)->patch(route('pacientes.'.$accion, $paciente), $this->datos())->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame($before, $paciente->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function entradasInvalidas(): array
    {
        $valores = [
            ['motivo', null], ['motivo', ''], ['motivo', " \t\n "], ['motivo', []], ['motivo', ['Motivo']],
            ['motivo', 123], ['motivo', str_repeat('á', 501)],
            ['confirmacion', null], ['confirmacion', ''], ['confirmacion', false], ['confirmacion', '0'], ['confirmacion', ['1']],
        ];
        $casos = [];
        foreach (['inactivate', 'reactivate'] as $accion) {
            foreach ($valores as [$campo, $valor]) {
                $casos[] = [$accion, $campo, $valor];
            }
        }

        return $casos;
    }

    #[DataProvider('entradasInvalidas')]
    public function test_reason_and_confirmation_validation_prevents_partial_writes(string $accion, string $campo, mixed $valor): void
    {
        $paciente = $this->paciente($accion === 'inactivate' ? 'ACTIVO' : 'INACTIVO');
        $before = $paciente->getAttributes();
        $this->actingAs($this->operador())->patch(route('pacientes.'.$accion, $paciente), $this->datos([$campo => $valor]))
            ->assertRedirect(route('pacientes.state', $paciente))->assertInvalid($campo);
        $this->assertSame($before, $paciente->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function camposManipulados(): array
    {
        return [['estado', 'INACTIVO'], ['estado', null], ['estado', []], ['id', 999], ['numero_documento', '000099'], ['nombres', 'Cambio'], ['tipo_paciente', 'EPS'], ['historia_clinica_id', 999], ['user_id', 999], ['fecha_hora', '2000-01-01'], ['motivo_inactivacion', 'Motivo falso'], ['created_at', '2000-01-01'], ['updated_at', '2000-01-01']];
    }

    #[DataProvider('camposManipulados')]
    public function test_state_operation_rejects_all_fields_outside_reason_and_confirmation(string $campo, mixed $valor): void
    {
        $paciente = $this->paciente();
        $before = $paciente->getAttributes();
        $this->actingAs($this->operador());
        foreach (['inactivate', 'reactivate'] as $accion) {
            $this->patch(route('pacientes.'.$accion, $paciente), $this->datos([$campo => $valor]))->assertInvalid($campo);
        }
        $this->assertSame($before, $paciente->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    #[DataProvider('transiciones')]
    public function test_repeated_transition_is_rejected_without_overwriting_reason_or_adding_audit(string $accion, string $origen, string $destino, string $permiso): void
    {
        $paciente = $this->paciente($origen);
        $this->actingAs($this->operador());
        $this->patch(route('pacientes.'.$accion, $paciente), $this->datos())->assertSessionHasNoErrors();
        $before = $paciente->refresh()->getAttributes();
        $audit = Auditoria::query()->sole()->getAttributes();
        $this->patch(route('pacientes.'.$accion, $paciente), $this->datos(['motivo' => 'Repetición']))->assertInvalid('motivo');
        $this->assertSame($before, $paciente->refresh()->getAttributes());
        $this->assertSame($audit, Auditoria::query()->sole()->getAttributes());
    }

    #[DataProvider('transiciones')]
    public function test_transition_rechecks_persisted_state_after_authorization(string $accion, string $origen, string $destino, string $permiso): void
    {
        $paciente = $this->paciente($origen);
        $this->actingAs($this->operador());
        $cambiado = false;
        Gate::after(function (User $user, string $ability) use ($accion, $paciente, $destino, &$cambiado): void {
            if (! $cambiado && $ability === $accion) {
                $cambiado = true;
                Paciente::query()->whereKey($paciente->id)->update(['estado' => $destino]);
            }
        });
        $this->patch(route('pacientes.'.$accion, $paciente), $this->datos())->assertInvalid('motivo');
        $this->assertTrue($cambiado);
        $this->assertSame($destino, $paciente->refresh()->estado);
        $this->assertNull($paciente->motivo_inactivacion);
        $this->assertDatabaseCount('auditorias', 0);
    }

    #[DataProvider('transiciones')]
    public function test_audit_failure_rolls_back_entire_transition(string $accion, string $origen, string $destino, string $permiso): void
    {
        $paciente = $this->paciente($origen);
        $paciente->forceFill(['motivo_inactivacion' => 'Motivo anterior'])->save();
        $before = $paciente->refresh()->getAttributes();
        $historia = $paciente->historiaClinica->getAttributes();
        $this->actingAs($this->operador())->withoutExceptionHandling();
        Auditoria::creating(function (): void {
            throw new RuntimeException('Fallo simulado de auditoría');
        });
        try {
            $this->patch(route('pacientes.'.$accion, $paciente), $this->datos());
            $this->fail('Se esperaba fallo de auditoría.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo simulado de auditoría', $exception->getMessage());
            $this->assertSame($before, $paciente->refresh()->getAttributes());
            $this->assertSame($historia, $paciente->historiaClinica->getAttributes());
            $this->assertDatabaseCount('auditorias', 0);
        } finally {
            Auditoria::flushEventListeners();
        }
    }

    public function test_reason_accepts_exactly_five_hundred_characters_without_exceeding_audit_column(): void
    {
        $paciente = $this->paciente();
        $motivo = str_repeat('á', 500);
        $this->actingAs($this->operador())->patch(route('pacientes.inactivate', $paciente), $this->datos(['motivo' => $motivo]))->assertSessionHasNoErrors();
        $this->assertSame($motivo, $paciente->refresh()->motivo_inactivacion);
        $this->assertSame($motivo, Auditoria::query()->sole()->descripcion);
    }

    public function test_unknown_patient_returns_not_found_without_audit(): void
    {
        $this->actingAs($this->operador())->get('/pacientes/999999/estado')->assertNotFound();
        $this->patch('/pacientes/999999/inactivar', $this->datos())->assertNotFound();
        $this->patch('/pacientes/999999/reactivar', $this->datos())->assertNotFound();
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_v3_roles_have_both_permissions_and_execute_transitions_without_medical_profile(): void
    {
        $this->seed(PacientePermissionsSeeder::class);
        foreach (['Administrador', 'Médico', 'Administrativo'] as $nombre) {
            $user = User::factory()->create(['rol_id' => Rol::query()->where('nombre', $nombre)->value('id')]);
            $this->assertNull($user->medico);
            $paciente = $this->paciente();
            $this->actingAs($user)->patch(route('pacientes.inactivate', $paciente), $this->datos())->assertSessionHasNoErrors();
            $this->patch(route('pacientes.reactivate', $paciente), $this->datos())->assertSessionHasNoErrors();
            $this->assertSame('ACTIVO', $paciente->refresh()->estado);
        }
        $this->assertDatabaseCount('auditorias', 6);
    }

    public function test_cancelling_confirmation_does_not_change_patient_or_audit(): void
    {
        $paciente = $this->paciente();
        $before = $paciente->getAttributes();
        $this->actingAs($this->operador())->get(route('pacientes.state', $paciente))->assertOk();
        $this->get(route('pacientes.show', $paciente))->assertOk();
        $this->assertSame($before, $paciente->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_incremental_migration_preserves_existing_rows_without_inventing_reasons(): void
    {
        $original = config('database.default');
        config([
            'database.connections.patient_migration' => config('database.connections.sqlite'),
            'database.default' => 'patient_migration',
        ]);
        try {
            $this->assertSame(':memory:', DB::connection()->getConfig('database'));
            (require database_path('migrations/2026_10_05_025822_create_pacientes_table.php'))->up();
            (require database_path('migrations/2026_10_05_025823_create_historias_clinicas_table.php'))->up();
            $activo = $this->paciente();
            $inactivo = $this->paciente('INACTIVO');
            $antes = [$activo->getAttributes(), $inactivo->getAttributes()];
            $historias = DB::table('historias_clinicas')->orderBy('id')->get()->toArray();
            (require database_path('migrations/2026_10_24_033535_add_motivo_inactivacion_to_pacientes_table.php'))->up();
            foreach ([$activo, $inactivo] as $i => $paciente) {
                $this->assertSame([...$antes[$i], 'motivo_inactivacion' => null], $paciente->refresh()->getAttributes());
            }
            $this->assertEquals($historias, DB::table('historias_clinicas')->orderBy('id')->get()->toArray());
        } finally {
            config(['database.default' => $original]);
            DB::purge('patient_migration');
        }
    }

    public function test_reactivating_patient_does_not_reactivate_user_with_the_same_document(): void
    {
        $paciente = $this->paciente('INACTIVO');
        $cuenta = User::factory()->create(['numero_documento' => $paciente->numero_documento, 'estado' => 'inactivo'])->refresh();
        $antes = $cuenta->getAttributes();
        $this->actingAs($this->operador())->patch(route('pacientes.reactivate', $paciente), $this->datos())->assertSessionHasNoErrors();
        $this->assertSame('ACTIVO', $paciente->refresh()->estado);
        $this->assertSame($antes, $cuenta->refresh()->getAttributes());
    }
}
