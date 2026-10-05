<?php

namespace Tests\Feature;

use App\Http\Requests\StorePacienteRequest;
use App\Models\Auditoria;
use App\Models\HistoriaClinica;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\PacientePermissionsSeeder;
use Database\Seeders\SecuritySeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PacienteManagementTest extends TestCase
{
    use RefreshDatabase;

    private function autorizado(array $permisos = ['RF-008', 'RF-010', 'RF-011']): User
    {
        $this->seed(PacientePermissionsSeeder::class);
        $rol = Rol::query()->create(['nombre' => 'Prueba '.uniqid()]);
        $rol->permisos()->sync(Permiso::query()->whereIn('codigo', $permisos)->pluck('id'));

        return User::factory()->create(['rol_id' => $rol->id]);
    }

    /** @return array<string, string> */
    private function datos(): array
    {
        return [
            'tipo_documento' => 'CC', 'numero_documento' => '0012345678',
            'nombres' => 'Ana Maria', 'apellidos' => 'Gomez Rios',
            'fecha_nacimiento' => '1996-05-20', 'sexo' => 'FEMENINO', 'telefono' => '003001234567',
            'direccion' => 'Calle 10 # 20-30', 'ciudad' => 'Cali', 'tipo_paciente' => 'PARTICULAR',
        ];
    }

    public function test_guests_cannot_access_any_patient_operation(): void
    {
        $paciente = Paciente::factory()->create();
        foreach (['/pacientes', '/pacientes/buscar', '/pacientes/create', '/pacientes/'.$paciente->id] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->post('/pacientes', $this->datos())->assertRedirect('/login');
        $this->assertDatabaseCount('pacientes', 1);
    }

    public function test_user_without_permissions_cannot_access_any_patient_operation(): void
    {
        $user = User::factory()->create(['cargo' => 'Administrador']);
        $paciente = Paciente::factory()->create();
        $this->actingAs($user);
        foreach (['/pacientes', '/pacientes/buscar', '/pacientes/create', '/pacientes/'.$paciente->id] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/pacientes', $this->datos())->assertForbidden();
        $this->assertDatabaseCount('pacientes', 1);
    }

    public function test_inactive_session_cannot_read_or_register_patients(): void
    {
        $user = $this->autorizado();
        $user->update(['estado' => 'inactivo']);
        $paciente = Paciente::factory()->create();
        foreach (['/pacientes', '/pacientes/buscar', '/pacientes/create', '/pacientes/'.$paciente->id] as $url) {
            $this->actingAs($user)->get($url)->assertRedirect('/login');
            $this->assertGuest();
        }
        $this->actingAs($user)->post('/pacientes', $this->datos())->assertRedirect('/login');
        $this->assertDatabaseCount('pacientes', 1);
    }

    public function test_authorized_user_can_open_registration_form(): void
    {
        $this->actingAs($this->autorizado(['RF-008']))->get('/pacientes/create')->assertOk()
            ->assertSee('Registrar paciente')->assertSee('¿Confirmar el registro del paciente?')
            ->assertSee('Correo electrónico (opcional)')->assertDontSee('name="password"', false);
    }

    public function test_registration_creates_active_patient_unique_empty_history_and_audit_without_account(): void
    {
        $user = $this->autorizado();
        $cantidadUsuarios = User::query()->count();
        $response = $this->actingAs($user)->post('/pacientes', $this->datos());
        $paciente = Paciente::query()->sole();
        $response->assertSessionHasNoErrors()->assertRedirect(route('pacientes.show', $paciente));
        $this->assertSame('ACTIVO', $paciente->estado);
        $this->assertSame('0012345678', $paciente->numero_documento);
        $this->assertSame('003001234567', $paciente->telefono);
        $this->assertNull($paciente->correo_electronico);
        $this->assertSame('PARTICULAR', $paciente->tipo_paciente);
        $this->assertDatabaseCount('historias_clinicas', 1);
        $this->assertNotNull($paciente->historiaClinica->fecha_apertura);
        $this->assertSame($paciente->id, $paciente->historiaClinica->paciente->id);
        $this->assertDatabaseCount('users', $cantidadUsuarios);
        $auditoria = Auditoria::query()->sole();
        $this->assertSame($user->id, $auditoria->user_id);
        $this->assertSame('PACIENTE_CREADO', $auditoria->accion);
        $this->assertSame('Paciente', $auditoria->entidad);
        $this->assertSame($paciente->id, $auditoria->registro_id);
        $this->assertSame('EXITO', $auditoria->resultado);
        $this->assertNotNull($auditoria->fecha_hora);
        $this->assertStringNotContainsString($paciente->numero_documento, $auditoria->descripcion);
        $this->assertStringNotContainsString($user->password, $auditoria->descripcion);
    }

    public function test_registration_permission_does_not_implicitly_grant_detail_or_listing(): void
    {
        $user = $this->autorizado(['RF-008']);
        $this->actingAs($user)->post('/pacientes', $this->datos())->assertSessionHasNoErrors()
            ->assertRedirect(route('pacientes.create'))->assertSessionHas('status');
        $this->get(route('pacientes.show', Paciente::query()->sole()))->assertForbidden();
        $this->get('/pacientes')->assertForbidden();
        $this->get('/pacientes/buscar')->assertForbidden();
    }

    public function test_optional_email_is_validated_but_not_unique(): void
    {
        $this->actingAs($this->autorizado());
        $this->post('/pacientes', [...$this->datos(), 'correo_electronico' => 'ANA@example.com'])->assertSessionHasNoErrors();
        $this->post('/pacientes', [...$this->datos(), 'numero_documento' => '0098765432', 'correo_electronico' => 'ana@example.com'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('pacientes', 2);
        $this->assertSame('ana@example.com', Paciente::query()->first()->correo_electronico);
        $this->post('/pacientes', [...$this->datos(), 'numero_documento' => '0000000001', 'correo_electronico' => 'invalido'])->assertInvalid('correo_electronico');
    }

    public static function requiredFields(): array
    {
        return array_map(fn (string $campo): array => [$campo], ['tipo_documento', 'numero_documento', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono', 'direccion', 'ciudad', 'tipo_paciente']);
    }

    public static function validDocumentTypes(): array
    {
        return [['CC'], ['TI'], ['RC'], ['CE'], ['PA'], ['PT']];
    }

    #[DataProvider('validDocumentTypes')]
    public function test_registration_accepts_and_stores_only_document_code(string $codigo): void
    {
        $this->actingAs($this->autorizado())->post('/pacientes', [...$this->datos(), 'tipo_documento' => $codigo])->assertSessionHasNoErrors();
        $this->assertSame($codigo, Paciente::query()->sole()->tipo_documento);
    }

    public static function validSexValues(): array
    {
        return [['MASCULINO'], ['FEMENINO'], ['OTRO'], ['NO_ESPECIFICA']];
    }

    #[DataProvider('validSexValues')]
    public function test_registration_accepts_and_stores_each_declared_sex_value(string $sexo): void
    {
        $this->actingAs($this->autorizado())->post('/pacientes', [...$this->datos(), 'sexo' => $sexo])->assertSessionHasNoErrors();
        $this->assertSame($sexo, Paciente::query()->sole()->sexo);
    }

    public static function invalidCatalogValues(): array
    {
        return [
            ['tipo_documento', 'DNI'],
            ['tipo_documento', 'Cédula de ciudadanía'],
            ['tipo_documento', 'PASAPORTE'],
            ['sexo', 'Femenino'],
            ['sexo', 'F'],
            ['sexo', 'DESCONOCIDO'],
        ];
    }

    #[DataProvider('invalidCatalogValues')]
    public function test_registration_rejects_manually_submitted_values_outside_catalogs(string $campo, string $valor): void
    {
        $this->actingAs($this->autorizado())->post('/pacientes', [...$this->datos(), $campo => $valor])->assertInvalid($campo);
        $this->assertDatabaseCount('pacientes', 0);
        $this->assertDatabaseCount('historias_clinicas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_catalog_fields_reject_arrays_instead_of_codes(): void
    {
        $this->actingAs($this->autorizado())->post('/pacientes', [...$this->datos(), 'tipo_documento' => ['CC'], 'sexo' => ['FEMENINO']])
            ->assertInvalid(['tipo_documento', 'sexo']);
        $this->assertDatabaseCount('pacientes', 0);
    }

    public function test_registration_form_shows_controlled_selects_and_retains_valid_previous_selection(): void
    {
        $response = $this->actingAs($this->autorizado(['RF-008']))
            ->withSession(['_old_input' => ['tipo_documento' => 'PT', 'sexo' => 'NO_ESPECIFICA']])
            ->get('/pacientes/create')->assertOk();
        $response->assertSee('<select id="tipo_documento" name="tipo_documento" required', false)
            ->assertSee('<select id="sexo" name="sexo" required', false)
            ->assertSee('value="PT" selected', false)->assertSee('value="NO_ESPECIFICA" selected', false);
        foreach (['CC' => 'Cédula de ciudadanía', 'TI' => 'Tarjeta de identidad', 'RC' => 'Registro civil', 'CE' => 'Cédula de extranjería', 'PA' => 'Pasaporte', 'PT' => 'Permiso por Protección Temporal'] as $codigo => $etiqueta) {
            $response->assertSee('value="'.$codigo.'"', false)->assertSee($etiqueta);
        }
        foreach (['MASCULINO', 'FEMENINO', 'OTRO', 'NO_ESPECIFICA'] as $sexo) {
            $response->assertSee('value="'.$sexo.'"', false);
        }
    }

    public function test_new_catalogs_do_not_change_existing_patient_values_or_column_types(): void
    {
        $existente = Paciente::factory()->create(['tipo_documento' => 'ANTERIOR', 'sexo' => 'Femenino']);
        $this->actingAs($this->autorizado())->post('/pacientes', $this->datos())->assertSessionHasNoErrors();
        $this->assertSame('ANTERIOR', $existente->refresh()->tipo_documento);
        $this->assertSame('Femenino', $existente->sexo);
        $this->get(route('pacientes.show', $existente))->assertOk()->assertSee('ANTERIOR')->assertSee('Femenino');
        $this->assertSame('varchar', Schema::getColumnType('pacientes', 'tipo_documento'));
        $this->assertSame('varchar', Schema::getColumnType('pacientes', 'sexo'));
    }

    #[DataProvider('requiredFields')]
    public function test_registration_requires_each_v3_mandatory_field(string $campo): void
    {
        $datos = $this->datos();
        unset($datos[$campo]);
        $this->actingAs($this->autorizado())->post('/pacientes', $datos)->assertInvalid($campo);
        $this->assertDatabaseCount('pacientes', 0);
        $this->assertDatabaseCount('historias_clinicas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_future_and_invalid_birthdates_are_rejected_but_today_is_allowed(): void
    {
        $this->actingAs($this->autorizado());
        foreach ([now()->addDay()->toDateString(), '2026-02-30', 'texto'] as $fecha) {
            $this->post('/pacientes', [...$this->datos(), 'fecha_nacimiento' => $fecha])->assertInvalid('fecha_nacimiento');
        }
        $this->post('/pacientes', [...$this->datos(), 'fecha_nacimiento' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('pacientes', 1);
    }

    public function test_registration_rejects_invalid_preference_and_account_state_inputs(): void
    {
        $this->actingAs($this->autorizado())->post('/pacientes', [...$this->datos(), 'tipo_paciente' => 'OTRO', 'estado' => 'INACTIVO', 'password' => 'secreto', 'user_id' => 1])
            ->assertInvalid(['tipo_paciente', 'estado', 'password', 'user_id']);
        $this->assertDatabaseCount('pacientes', 0);
    }

    public function test_document_and_phone_remain_text_and_oversized_values_are_rejected(): void
    {
        $this->actingAs($this->autorizado())->post('/pacientes', [...$this->datos(), 'numero_documento' => ['123'], 'telefono' => 123, 'nombres' => str_repeat('a', 101)])
            ->assertInvalid(['numero_documento', 'telefono', 'nombres']);
        $this->assertDatabaseCount('pacientes', 0);
    }

    public function test_duplicate_document_pair_is_rejected_and_direct_persistence_has_same_constraint(): void
    {
        $this->actingAs($this->autorizado());
        $this->post('/pacientes', $this->datos())->assertSessionHasNoErrors();
        $this->post('/pacientes', [...$this->datos(), 'tipo_documento' => ' cc '])->assertInvalid(['numero_documento' => StorePacienteRequest::DUPLICATE_DOCUMENT_MESSAGE]);
        $this->assertDatabaseCount('pacientes', 1);
        $this->assertDatabaseCount('historias_clinicas', 1);
        $this->assertDatabaseCount('auditorias', 1);
        $this->expectException(UniqueConstraintViolationException::class);
        Paciente::factory()->create($this->datos());
    }

    public function test_same_document_number_with_different_type_is_allowed(): void
    {
        $this->actingAs($this->autorizado());
        $this->post('/pacientes', $this->datos())->assertSessionHasNoErrors();
        $this->post('/pacientes', [...$this->datos(), 'tipo_documento' => 'TI'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('pacientes', 2);
    }

    public function test_history_creation_failure_rolls_back_patient_and_audit(): void
    {
        $this->actingAs($this->autorizado())->withoutExceptionHandling();
        HistoriaClinica::creating(function (): void {
            throw new RuntimeException('Fallo de historia simulado');
        });
        try {
            $this->post('/pacientes', $this->datos());
            $this->fail('Se esperaba el fallo de historia.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo de historia simulado', $exception->getMessage());
            $this->assertDatabaseCount('pacientes', 0);
            $this->assertDatabaseCount('historias_clinicas', 0);
            $this->assertDatabaseCount('auditorias', 0);
        } finally {
            HistoriaClinica::flushEventListeners();
        }
    }

    public function test_audit_failure_rolls_back_patient_and_history(): void
    {
        $this->actingAs($this->autorizado())->withoutExceptionHandling();
        Auditoria::creating(function (): void {
            throw new RuntimeException('Fallo de auditoría simulado');
        });
        try {
            $this->post('/pacientes', $this->datos());
            $this->fail('Se esperaba el fallo de auditoría.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo de auditoría simulado', $exception->getMessage());
            $this->assertDatabaseCount('pacientes', 0);
            $this->assertDatabaseCount('historias_clinicas', 0);
            $this->assertDatabaseCount('auditorias', 0);
        } finally {
            Auditoria::flushEventListeners();
        }
    }

    public function test_one_history_per_patient_is_enforced_by_database(): void
    {
        $paciente = Paciente::factory()->create();
        $paciente->historiaClinica()->create(['fecha_apertura' => now()]);
        $this->expectException(UniqueConstraintViolationException::class);
        $paciente->historiaClinica()->create(['fecha_apertura' => now()]);
    }

    public function test_detail_only_exposes_administrative_fields_including_inactive_state(): void
    {
        $paciente = Paciente::factory()->create(['estado' => 'INACTIVO', 'correo_electronico' => 'ficha@example.com']);
        $historia = $paciente->historiaClinica()->create(['fecha_apertura' => now()]);
        $this->actingAs($this->autorizado(['RF-010']))->get(route('pacientes.show', $paciente))->assertOk()
            ->assertSee($paciente->numero_documento)->assertSee('INACTIVO')->assertSee('ficha@example.com')
            ->assertSee($paciente->fecha_nacimiento->format('Y-m-d'))
            ->assertDontSee('fecha_apertura')->assertDontSee('diagnostico')->assertDontSee('Contraseña')
            ->assertDontSee('/historias', false)->assertDontSee('Editar paciente')->assertDontSee('Inactivar paciente');
        $this->assertSame($historia->id, $paciente->historiaClinica->id);
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_missing_patient_returns_404(): void
    {
        $this->actingAs($this->autorizado(['RF-010']))->get('/pacientes/999999')->assertNotFound();
    }

    public function test_document_and_full_name_search_select_correct_homonym_by_id(): void
    {
        $ana = Paciente::factory()->create(['nombres' => 'Ana Maria', 'apellidos' => 'Gomez Rios', 'numero_documento' => '00112233']);
        $otra = Paciente::factory()->create(['nombres' => 'Ana Maria', 'apellidos' => 'Gomez Rios', 'numero_documento' => '00998877', 'ciudad' => 'Bogota']);
        $this->actingAs($this->autorizado(['RF-010']));
        $this->get('/pacientes/buscar?buscar=001122')->assertOk()->assertSee('00112233')->assertDontSee('00998877')
            ->assertSee(route('pacientes.show', $ana), false);
        $this->get('/pacientes/buscar?buscar=Ana%20Maria%20Gomez')->assertOk()->assertSee('00112233')->assertSee('00998877');
        $this->get(route('pacientes.show', $otra))->assertSee('00998877')->assertSee('Bogota')->assertDontSee('00112233');
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_search_without_matches_and_invalid_input_are_controlled(): void
    {
        $this->actingAs($this->autorizado(['RF-010']));
        $this->get('/pacientes/buscar?buscar=inexistente')->assertOk()->assertSee('No se encontraron pacientes.')->assertSee('Total de registros: 0');
        $this->get('/pacientes/buscar')->assertOk()->assertSee('Ingrese un documento o nombre');
        $this->get('/pacientes/buscar?buscar[]=texto')->assertRedirect(route('pacientes.search'))->assertInvalid('buscar');
    }

    public function test_listing_without_filters_includes_both_states_and_shows_total(): void
    {
        Paciente::factory()->create(['numero_documento' => '0000000001']);
        Paciente::factory()->create(['numero_documento' => '0000000002', 'estado' => 'INACTIVO']);
        $this->actingAs($this->autorizado(['RF-011']))->get('/pacientes')->assertOk()->assertSee('0000000001')->assertSee('0000000002')
            ->assertSee('Total de registros: 2')->assertDontSee('Ver ficha');
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_listing_combines_city_state_and_current_preference(): void
    {
        $coincidencia = Paciente::factory()->create(['numero_documento' => '0000000001', 'ciudad' => 'Cali', 'estado' => 'ACTIVO', 'tipo_paciente' => 'EPS']);
        Paciente::factory()->create(['numero_documento' => '0000000002', 'ciudad' => 'Bogota', 'tipo_paciente' => 'EPS']);
        Paciente::factory()->create(['numero_documento' => '0000000003', 'ciudad' => 'Cali', 'estado' => 'INACTIVO', 'tipo_paciente' => 'EPS']);
        Paciente::factory()->create(['numero_documento' => '0000000004', 'ciudad' => 'Cali', 'tipo_paciente' => 'PARTICULAR']);
        $this->actingAs($this->autorizado())->get('/pacientes?ciudad=Cali&estado=ACTIVO&tipo_paciente=EPS')->assertOk()
            ->assertSee('0000000001')->assertDontSee('0000000002')->assertDontSee('0000000003')->assertDontSee('0000000004')->assertSee('Total de registros: 1');
        $coincidencia->update(['tipo_paciente' => 'PARTICULAR']);
        $this->get('/pacientes?ciudad=Cali&estado=ACTIVO&tipo_paciente=EPS')->assertSee('Total de registros: 0');
    }

    public function test_listing_no_results_and_invalid_filters_are_controlled(): void
    {
        $this->actingAs($this->autorizado(['RF-011']));
        $this->get('/pacientes?ciudad=Inexistente')->assertOk()->assertSee('Total de registros: 0')->assertSee('No se encontraron pacientes.');
        $this->get('/pacientes?tipo_paciente=OTRO&estado=ELIMINADO&ciudad[]=Cali')->assertRedirect(route('pacientes.index'))->assertInvalid(['tipo_paciente', 'estado', 'ciudad']);
        $this->followingRedirects()->get('/pacientes?tipo_paciente=OTRO')->assertOk()->assertSee('Seleccione PARTICULAR o EPS, o limpie el filtro.');
    }

    public function test_city_filter_matches_partial_names_without_case_sensitivity(): void
    {
        Paciente::factory()->create(['numero_documento' => '0000000001', 'ciudad' => 'Cali, Valle Del Cauca, Colombia']);
        Paciente::factory()->create(['numero_documento' => '0000000002', 'ciudad' => 'YUMBO']);
        Paciente::factory()->create(['numero_documento' => '0000000003', 'ciudad' => 'Bogota']);
        $this->actingAs($this->autorizado(['RF-011']));

        foreach (['cali', 'valle'] as $ciudad) {
            $this->get('/pacientes?ciudad='.$ciudad)->assertOk()->assertSee('0000000001')
                ->assertDontSee('0000000002')->assertDontSee('0000000003')->assertSee('Total de registros: 1');
        }
        $this->get('/pacientes?ciudad=yumbo')->assertOk()->assertSee('0000000002')
            ->assertDontSee('0000000001')->assertDontSee('0000000003')->assertSee('Total de registros: 1');
    }

    public function test_partial_city_filter_remains_combined_with_state_and_preference(): void
    {
        Paciente::factory()->create(['numero_documento' => '0000000001', 'ciudad' => 'Cali, Valle Del Cauca, Colombia', 'estado' => 'ACTIVO', 'tipo_paciente' => 'EPS']);
        Paciente::factory()->create(['numero_documento' => '0000000002', 'ciudad' => 'Cali, Valle Del Cauca, Colombia', 'estado' => 'INACTIVO', 'tipo_paciente' => 'EPS']);
        Paciente::factory()->create(['numero_documento' => '0000000003', 'ciudad' => 'Cali, Valle Del Cauca, Colombia', 'estado' => 'ACTIVO', 'tipo_paciente' => 'PARTICULAR']);
        Paciente::factory()->create(['numero_documento' => '0000000004', 'ciudad' => 'Bogota', 'estado' => 'ACTIVO', 'tipo_paciente' => 'EPS']);

        $this->actingAs($this->autorizado(['RF-011']))->get('/pacientes?ciudad=valle&estado=ACTIVO&tipo_paciente=EPS')
            ->assertOk()->assertSee('0000000001')->assertDontSee('0000000002')->assertDontSee('0000000003')
            ->assertDontSee('0000000004')->assertSee('Total de registros: 1');
    }

    public function test_listing_permission_does_not_grant_query_or_registration_and_query_does_not_grant_listing(): void
    {
        $paciente = Paciente::factory()->create();
        $this->actingAs($this->autorizado(['RF-011']))->get('/pacientes/buscar')->assertForbidden();
        $this->get(route('pacientes.show', $paciente))->assertForbidden();
        $this->get('/pacientes/create')->assertForbidden();
        $this->post('/pacientes', $this->datos())->assertForbidden();
        $this->actingAs($this->autorizado(['RF-010']))->get('/pacientes')->assertForbidden();
    }

    public function test_patient_is_not_authenticatable_and_cannot_login_without_an_internal_account(): void
    {
        $paciente = Paciente::factory()->create(['correo_electronico' => 'paciente@example.com', 'numero_documento' => '0012345678']);
        $this->assertNotInstanceOf(User::class, $paciente);
        $this->assertNotInstanceOf(Authenticatable::class, $paciente);
        $this->assertFalse(Schema::hasColumn('pacientes', 'password'));
        $this->assertFalse(Schema::hasColumn('pacientes', 'user_id'));
        foreach ([$paciente->correo_electronico, $paciente->numero_documento] as $identificador) {
            $this->post('/login', ['identificador' => $identificador, 'password' => 'password'])->assertInvalid('identificador');
            $this->assertGuest();
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_permissions_seeder_is_idempotent_and_preserves_security_permissions(): void
    {
        $this->seed(SecuritySeeder::class);
        $admin = Rol::query()->where('nombre', 'Administrador')->firstOrFail();
        $anteriores = $admin->permisos()->pluck('codigo')->all();
        $this->seed(PacientePermissionsSeeder::class);
        $this->seed(PacientePermissionsSeeder::class);
        foreach (['Administrador', 'Médico', 'Administrativo'] as $nombre) {
            $rol = Rol::query()->where('nombre', $nombre)->firstOrFail();
            foreach (['RF-008', 'RF-010', 'RF-011'] as $codigo) {
                $this->assertTrue($rol->permisos()->where('codigo', $codigo)->exists());
            }
        }
        foreach ($anteriores as $codigo) {
            $this->assertTrue($admin->permisos()->where('codigo', $codigo)->exists());
        }
        $this->assertDatabaseCount('permisos', 9);
        $this->assertFalse(Rol::query()->where('nombre', 'Administrativo')->first()->permisos()->where('codigo', 'RF-004')->exists());
    }

    public function test_all_v3_internal_roles_can_execute_patient_operations_without_clinical_profile(): void
    {
        $this->seed(SecuritySeeder::class);
        $this->seed(PacientePermissionsSeeder::class);
        $paciente = Paciente::factory()->create();
        foreach (['Administrador', 'Médico', 'Administrativo'] as $nombre) {
            $user = User::factory()->create(['rol_id' => Rol::query()->where('nombre', $nombre)->value('id')]);
            $this->actingAs($user)->get('/pacientes/create')->assertOk();
            $this->get('/pacientes')->assertOk();
            $this->get('/pacientes/buscar?buscar='.$paciente->numero_documento)->assertOk();
            $this->get(route('pacientes.show', $paciente))->assertOk();
            $this->post('/pacientes', [...$this->datos(), 'numero_documento' => '00'.$user->id])->assertSessionHasNoErrors();
        }
    }

    public function test_patient_edit_state_delete_and_clinical_flows_are_not_available(): void
    {
        $paciente = Paciente::factory()->create();
        $this->actingAs($this->autorizado());
        $this->get('/pacientes/'.$paciente->id.'/edit')->assertNotFound();
        $this->put(route('pacientes.show', $paciente), $this->datos())->assertMethodNotAllowed();
        $this->delete(route('pacientes.show', $paciente))->assertMethodNotAllowed();
        $this->patch('/pacientes/'.$paciente->id.'/inactivar')->assertNotFound();
        $this->get('/historias-clinicas/'.$paciente->id)->assertNotFound();
        $this->assertDatabaseCount('pacientes', 1);
    }
}
