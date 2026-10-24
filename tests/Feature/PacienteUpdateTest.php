<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\PacientePermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * RF-009 administrativo: CA-009-02/04; CA-009-01 se revalidará con citas/pagos.
 * CA-009-03 (reactivación) se comprueba por separado en PacienteStateTest.
 */
class PacienteUpdateTest extends TestCase
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

    private function editor(array $permisos = ['RF-009', 'RF-010']): User
    {
        $this->seed(PacientePermissionsSeeder::class);
        $rol = Rol::query()->create(['nombre' => 'Edición '.uniqid()]);
        $rol->permisos()->sync(Permiso::query()->whereIn('codigo', $permisos)->pluck('id'));

        return User::factory()->create(['rol_id' => $rol->id])->refresh();
    }

    private function paciente(): Paciente
    {
        $paciente = Paciente::factory()->create();
        $paciente->historiaClinica()->create(['fecha_apertura' => now()->subMonth()]);

        return $paciente->refresh();
    }

    /** @return array<string, mixed> */
    private function datos(Paciente $paciente, array $cambios = []): array
    {
        return [
            'tipo_documento' => $paciente->tipo_documento, 'numero_documento' => $paciente->numero_documento,
            'nombres' => $paciente->nombres, 'apellidos' => $paciente->apellidos,
            'fecha_nacimiento' => $paciente->fecha_nacimiento->format('Y-m-d'), 'sexo' => $paciente->sexo,
            'telefono' => $paciente->telefono, 'correo_electronico' => $paciente->correo_electronico,
            'direccion' => $paciente->direccion, 'ciudad' => $paciente->ciudad,
            'tipo_paciente' => $paciente->tipo_paciente, ...$cambios,
        ];
    }

    public function test_authorized_edit_form_prefills_fields_and_reuses_catalogs_without_state_controls(): void
    {
        $paciente = $this->paciente();
        $this->actingAs($this->editor())->get(route('pacientes.edit', $paciente))->assertOk()
            ->assertSee('Editar paciente')->assertSee($paciente->numero_documento)->assertSee($paciente->direccion)
            ->assertSee('name="tipo_documento"', false)->assertSee('name="sexo"', false)
            ->assertSee('Permiso por Protección Temporal')->assertSee('NO_ESPECIFICA')
            ->assertDontSee('name="estado"', false)->assertDontSee('name="id"', false)
            ->assertDontSee('Reactivar')->assertDontSee('name="historia_clinica_id"', false);
        $this->get(route('pacientes.show', $paciente))->assertSee(route('pacientes.edit', $paciente), false);
    }

    public function test_valid_update_preserves_identity_state_history_and_other_accounts_and_audits(): void
    {
        $paciente = $this->paciente();
        $historia = $paciente->historiaClinica->getAttributes();
        $other = $this->paciente();
        $otherData = $other->getAttributes();
        $user = $this->editor();
        $userData = $user->getAttributes();
        $id = $paciente->id;
        $this->actingAs($user)->put(route('pacientes.update', $paciente), $this->datos($paciente, [
            'tipo_documento' => 'PT', 'numero_documento' => '0000123456', 'nombres' => 'Ana Maria', 'apellidos' => 'Prueba',
            'fecha_nacimiento' => '2000-01-02', 'sexo' => 'NO_ESPECIFICA', 'telefono' => '003001234567',
            'correo_electronico' => 'ANA.PRUEBA@example.com', 'direccion' => 'Dirección de prueba', 'ciudad' => 'Yumbo', 'tipo_paciente' => 'EPS',
        ]))->assertSessionHasNoErrors()->assertRedirect(route('pacientes.show', $paciente));
        $paciente->refresh();
        $this->assertSame($id, $paciente->id);
        $this->assertSame('ACTIVO', $paciente->estado);
        $this->assertSame('0000123456', $paciente->numero_documento);
        $this->assertSame('003001234567', $paciente->telefono);
        $this->assertSame('ana.prueba@example.com', $paciente->correo_electronico);
        $this->assertSame('EPS', $paciente->tipo_paciente);
        $this->assertSame('NO_ESPECIFICA', $paciente->sexo);
        $this->assertSame('Yumbo', $paciente->ciudad);
        $this->assertSame($historia, $paciente->historiaClinica->getAttributes());
        $this->assertSame($otherData, $other->refresh()->getAttributes());
        $this->assertSame($userData, $user->refresh()->getAttributes());
        $this->assertDatabaseCount('historias_clinicas', 2);
        $audit = Auditoria::query()->sole();
        $this->assertSame('PACIENTE_ACTUALIZADO', $audit->accion);
        $this->assertSame('Paciente', $audit->entidad);
        $this->assertSame($id, $audit->registro_id);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('EXITO', $audit->resultado);
        $this->assertNotNull($audit->fecha_hora);
        foreach ([$paciente->numero_documento, $paciente->correo_electronico, $user->password, $user->remember_token] as $dato) {
            $this->assertStringNotContainsString($dato, $audit->descripcion);
        }
    }

    public function test_own_document_is_excluded_from_composite_unique_check(): void
    {
        $paciente = $this->paciente();
        $this->actingAs($this->editor())->put(route('pacientes.update', $paciente), $this->datos($paciente, ['direccion' => 'Nueva dirección']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Nueva dirección', $paciente->refresh()->direccion);
    }

    public function test_other_patients_document_pair_is_rejected_even_with_forged_ignore_id(): void
    {
        $paciente = $this->paciente();
        $other = $this->paciente();
        $before = $paciente->getAttributes();
        $this->actingAs($this->editor())->put(route('pacientes.update', $paciente), $this->datos($paciente, [
            'id' => $other->id, 'tipo_documento' => $other->tipo_documento, 'numero_documento' => $other->numero_documento,
        ]))->assertInvalid(['numero_documento', 'id']);
        $this->assertSame($before, $paciente->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_same_number_with_different_document_type_is_allowed(): void
    {
        $paciente = $this->paciente();
        $other = Paciente::factory()->create(['tipo_documento' => 'TI']);
        $this->actingAs($this->editor())->put(route('pacientes.update', $paciente), $this->datos($paciente, [
            'tipo_documento' => 'CC', 'numero_documento' => $other->numero_documento,
        ]))->assertSessionHasNoErrors();
        $this->assertSame('CC', $paciente->refresh()->tipo_documento);
        $this->assertSame($other->numero_documento, $paciente->numero_documento);
    }

    public function test_document_type_change_cannot_collide_with_an_existing_pair(): void
    {
        $paciente = $this->paciente();
        $paciente->update(['tipo_documento' => 'CC', 'numero_documento' => '000123']);
        Paciente::factory()->create(['tipo_documento' => 'TI', 'numero_documento' => '000123']);
        $this->actingAs($this->editor())->put(route('pacientes.update', $paciente), $this->datos($paciente, ['tipo_documento' => 'ti']))
            ->assertInvalid('numero_documento');
        $this->assertSame('CC', $paciente->refresh()->tipo_documento);
    }

    public function test_edit_permission_is_independent_of_read_create_and_list_permissions(): void
    {
        $paciente = $this->paciente();
        $this->actingAs($this->editor(['RF-009']))->get(route('pacientes.edit', $paciente))->assertOk();
        $this->put(route('pacientes.update', $paciente), $this->datos($paciente, ['ciudad' => 'Palmira']))
            ->assertSessionHasNoErrors()->assertRedirect(route('pacientes.edit', $paciente))->assertSessionHas('status');
        $this->get(route('pacientes.show', $paciente))->assertForbidden();
        $this->get(route('pacientes.create'))->assertForbidden();
        $this->get(route('pacientes.index'))->assertForbidden();
    }

    public function test_read_permission_and_cargo_do_not_allow_editing(): void
    {
        $paciente = $this->paciente();
        $user = $this->editor(['RF-010']);
        $user->update(['cargo' => 'Administrador']);
        $this->actingAs($user)->get(route('pacientes.edit', $paciente))->assertForbidden();
        $this->put(route('pacientes.update', $paciente), $this->datos($paciente))->assertForbidden();
        $this->get(route('pacientes.show', $paciente))->assertDontSee('Editar paciente');
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_guests_cannot_open_or_submit_edit_form(): void
    {
        $paciente = $this->paciente();
        $this->get(route('pacientes.edit', $paciente))->assertRedirect('/login');
        $this->put(route('pacientes.update', $paciente), $this->datos($paciente))->assertRedirect('/login');
    }

    public function test_inactive_account_cannot_edit_even_with_permission(): void
    {
        $paciente = $this->paciente();
        $before = $paciente->getAttributes();
        $user = $this->editor();
        $user->update(['estado' => 'inactivo']);
        $this->actingAs($user)->get(route('pacientes.edit', $paciente))->assertRedirect('/login');
        $this->assertGuest();
        $this->actingAs($user)->put(route('pacientes.update', $paciente), $this->datos($paciente, ['ciudad' => 'Cambio']))->assertRedirect('/login');
        $this->assertSame($before, $paciente->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_inactive_patient_can_be_edited_without_reactivation_or_new_history(): void
    {
        $paciente = $this->paciente();
        $paciente->forceFill(['estado' => 'INACTIVO'])->save();
        $historia = $paciente->historiaClinica->getAttributes();
        $this->actingAs($this->editor())->put(route('pacientes.update', $paciente), $this->datos($paciente, ['ciudad' => 'Palmira']))->assertSessionHasNoErrors();
        $this->assertSame('INACTIVO', $paciente->refresh()->estado);
        $this->assertSame('Palmira', $paciente->ciudad);
        $this->assertSame($historia, $paciente->historiaClinica->getAttributes());
    }

    public static function requiredFields(): array
    {
        return array_map(fn (string $campo): array => [$campo], ['tipo_documento', 'numero_documento', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono', 'direccion', 'ciudad', 'tipo_paciente']);
    }

    #[DataProvider('requiredFields')]
    public function test_mandatory_fields_are_validated_without_partial_writes(string $campo): void
    {
        $paciente = $this->paciente();
        $before = $paciente->getAttributes();
        $data = $this->datos($paciente);
        unset($data[$campo]);
        $this->actingAs($this->editor())->put(route('pacientes.update', $paciente), $data)->assertInvalid($campo);
        $this->assertSame($before, $paciente->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function invalidFields(): array
    {
        return [
            ['tipo_documento', 'DNI'], ['tipo_documento', ['CC']], ['sexo', 'Femenino'], ['sexo', 'INVENTADO'],
            ['fecha_nacimiento', '2026-02-30'], ['fecha_nacimiento', '2099-01-01'],
            ['correo_electronico', 'invalido'], ['tipo_paciente', 'OTRO'], ['telefono', 123], ['numero_documento', ['123']],
            ['ciudad', str_repeat('a', 101)],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_values_are_rejected(string $campo, mixed $valor): void
    {
        $paciente = $this->paciente();
        $before = $paciente->getAttributes();
        $this->actingAs($this->editor())->put(route('pacientes.update', $paciente), $this->datos($paciente, [$campo => $valor]))->assertInvalid($campo);
        $this->assertSame($before, $paciente->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public static function manipulatedFields(): array
    {
        return [
            ['estado', 'INACTIVO'], ['estado', null], ['estado', []], ['id', 999], ['historia_clinica_id', 999],
            ['historiaClinica', ['id' => 999]], ['user_id', 999], ['responsable', 999], ['created_at', '2000-01-01'],
            ['updated_at', '2000-01-01'], ['motivo_inactivacion', 'Intento'], ['password', 'valor-ficticio'],
        ];
    }

    #[DataProvider('manipulatedFields')]
    public function test_fields_outside_administrative_edit_are_rejected(string $campo, mixed $valor): void
    {
        $paciente = $this->paciente();
        $before = $paciente->getAttributes();
        $this->actingAs($this->editor())->put(route('pacientes.update', $paciente), $this->datos($paciente, [$campo => $valor]))->assertInvalid($campo);
        $this->assertSame($before, $paciente->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_optional_email_can_be_removed_and_shared_email_does_not_block_update(): void
    {
        $paciente = $this->paciente();
        Paciente::factory()->create(['correo_electronico' => 'compartido@example.com']);
        $this->actingAs($this->editor())->put(route('pacientes.update', $paciente), $this->datos($paciente, ['correo_electronico' => 'compartido@example.com']))->assertSessionHasNoErrors();
        $this->put(route('pacientes.update', $paciente), $this->datos($paciente, ['correo_electronico' => '']))->assertSessionHasNoErrors();
        $this->assertNull($paciente->refresh()->correo_electronico);
    }

    public function test_unknown_patient_returns_not_found_on_edit_and_update(): void
    {
        $this->actingAs($this->editor())->get('/pacientes/999999/edit')->assertNotFound();
        $this->put('/pacientes/999999', [])->assertNotFound();
    }

    public function test_patch_uses_same_authorization_and_validation_as_put(): void
    {
        $paciente = $this->paciente();
        $this->actingAs($this->editor())->patch(route('pacientes.update', $paciente), $this->datos($paciente, ['direccion' => 'Nueva dirección']))->assertSessionHasNoErrors();
        $this->assertSame('Nueva dirección', $paciente->refresh()->direccion);
    }

    public function test_preference_change_only_writes_patient_and_audit_without_mutating_history(): void
    {
        $paciente = $this->paciente();
        $paciente->update(['tipo_paciente' => 'EPS']);
        $historia = $paciente->historiaClinica->getAttributes();
        $this->actingAs($this->editor());
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $this->put(route('pacientes.update', $paciente), $this->datos($paciente, ['tipo_paciente' => 'PARTICULAR']))->assertSessionHasNoErrors();
        $this->assertSame('PARTICULAR', $paciente->refresh()->tipo_paciente);
        $this->assertSame($historia, $paciente->historiaClinica->getAttributes());
        $this->assertCount(2, $writes);
        foreach ($writes as $sql) {
            $this->assertMatchesRegularExpression('/^(update "pacientes"|insert into "auditorias")/i', $sql);
        }
    }

    public function test_audit_failure_rolls_back_all_patient_changes(): void
    {
        $paciente = $this->paciente();
        $before = $paciente->getAttributes();
        $historia = $paciente->historiaClinica->getAttributes();
        $this->actingAs($this->editor())->withoutExceptionHandling();
        Auditoria::creating(function (): void {
            throw new RuntimeException('Fallo de auditoría simulado');
        });
        try {
            $this->put(route('pacientes.update', $paciente), $this->datos($paciente, ['ciudad' => 'Cambio', 'tipo_paciente' => 'EPS']));
            $this->fail('Se esperaba fallo de auditoría.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo de auditoría simulado', $exception->getMessage());
            $this->assertSame($before, $paciente->refresh()->getAttributes());
            $this->assertSame($historia, $paciente->historiaClinica->getAttributes());
            $this->assertDatabaseCount('auditorias', 0);
        } finally {
            Auditoria::flushEventListeners();
        }
    }

    public function test_document_collision_after_validation_is_reported_without_partial_update(): void
    {
        $paciente = $this->paciente();
        $before = $paciente->getAttributes();
        $this->actingAs($this->editor());
        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            if (! $injected && str_contains($query->sql, 'count(') && str_contains($query->sql, 'from "pacientes"') && in_array('0000998877', $query->bindings, true)) {
                $injected = true;
                Paciente::factory()->create(['tipo_documento' => 'CC', 'numero_documento' => '0000998877']);
            }
        });
        $this->put(route('pacientes.update', $paciente), $this->datos($paciente, ['tipo_documento' => 'CC', 'numero_documento' => '0000998877']))->assertInvalid('numero_documento');
        $this->assertTrue($injected);
        $this->assertSame($before, $paciente->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }
}
