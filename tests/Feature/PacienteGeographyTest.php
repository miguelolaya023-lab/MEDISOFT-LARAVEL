<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Departamento;
use App\Models\Municipio;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\PacientePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PacienteGeographyTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! $this->app->environment('testing') || $this->app->configurationIsCached() || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || ! empty(config('database.connections.sqlite.url'))) {
            throw new RuntimeException('Only isolated SQLite :memory: is permitted.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $rows = json_decode(file_get_contents(resource_path('data/divipola/2024-12-30/catalogo.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            if (in_array($row['cod_mpio'], ['76001', '76892', '05001'], true)) {
                Departamento::firstOrCreate(['codigo' => $row['cod_dpto']], ['nombre' => $row['dpto']]);
                Municipio::create(['codigo' => $row['cod_mpio'], 'departamento_codigo' => $row['cod_dpto'], 'nombre' => $row['nom_mpio'], 'tipo_territorial' => $row['tipo_municipio']]);
            }
        }
    }

    private function operator(array $codes = ['RF-008', 'RF-009']): User
    {
        $this->seed(PacientePermissionsSeeder::class);
        $role = Rol::create(['nombre' => 'QA geografía '.uniqid()]);
        $role->permisos()->sync(Permiso::whereIn('codigo', $codes)->pluck('id'));

        return User::factory()->create(['rol_id' => $role->id]);
    }

    private function data(?Paciente $patient = null): array
    {
        if ($patient) {
            return [...$patient->only(['tipo_documento', 'numero_documento', 'nombres', 'apellidos', 'sexo', 'telefono', 'direccion', 'tipo_paciente']), 'fecha_nacimiento' => $patient->fecha_nacimiento->format('Y-m-d')];
        }

        return ['tipo_documento' => 'CC', 'numero_documento' => '000QA123', 'nombres' => 'QA', 'apellidos' => 'Geografía', 'sexo' => 'NO_ESPECIFICA', 'telefono' => '0000000000', 'direccion' => 'Ficticia QA', 'tipo_paciente' => 'PARTICULAR', 'fecha_nacimiento' => '2000-01-01', 'departamento_codigo' => '76', 'municipio_codigo' => '76001'];
    }

    public function test_create_derives_city_from_official_municipality_and_creates_history(): void
    {
        $this->actingAs($this->operator())->postJson(route('pacientes.store'), $this->data())->assertRedirect();
        $patient = Paciente::sole();
        $this->assertSame('76', $patient->departamento_codigo);
        $this->assertSame('76001', $patient->municipio_codigo);
        $this->assertSame('SANTIAGO DE CALI', $patient->ciudad);
        $this->assertNotNull($patient->historiaClinica);
        $this->assertSame(1, Auditoria::where('accion', 'PACIENTE_CREADO')->count());
    }

    public static function badSelections(): array
    {
        return [
            'wrong department' => [['departamento_codigo' => '05', 'municipio_codigo' => '76001'], 'municipio_codigo'],
            'unknown municipality' => [['municipio_codigo' => '99999'], 'municipio_codigo'],
            'unknown department' => [['departamento_codigo' => '99'], 'departamento_codigo'],
            'missing municipality' => [['municipio_codigo' => null], 'municipio_codigo'],
            'missing department' => [['departamento_codigo' => null], 'departamento_codigo'],
            'numeric code' => [['departamento_codigo' => 76], 'departamento_codigo'],
            'array code' => [['departamento_codigo' => ['76']], 'departamento_codigo'],
            'client city' => [['ciudad' => 'Ciudad manipulada'], 'ciudad'],
        ];
    }

    public static function badCreateSelections(): array
    {
        return [
            ...self::badSelections(),
            'sent country' => [['pais' => 'Otro país'], 'pais'],
            'fixed country submitted' => [['pais' => 'Colombia'], 'pais'],
            'null country submitted' => [['pais' => null], 'pais'],
        ];
    }

    #[DataProvider('badCreateSelections')]
    public function test_invalid_create_is_rejected_atomically(array $extra, string $field): void
    {
        $this->actingAs($this->operator())->postJson(route('pacientes.store'), [...$this->data(), ...$extra])->assertUnprocessable()->assertInvalid($field);
        $this->assertDatabaseCount('pacientes', 0);
        $this->assertDatabaseCount('historias_clinicas', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    #[DataProvider('badSelections')]
    public function test_invalid_edit_preserves_all_original_data(array $extra, string $field): void
    {
        $patient = Paciente::factory()->create(['departamento_codigo' => '76', 'municipio_codigo' => '76001', 'ciudad' => 'Texto histórico QA']);
        $before = $patient->refresh()->getAttributes();
        $this->actingAs($this->operator())->putJson(route('pacientes.update', $patient), [...$this->data($patient), 'departamento_codigo' => '76', 'municipio_codigo' => '76001', ...$extra])->assertUnprocessable()->assertInvalid($field);
        $this->assertSame($before, $patient->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_historical_edit_can_omit_geography_and_explicit_regularization_preserves_city(): void
    {
        $patient = Paciente::factory()->create(['ciudad' => 'Cali, Valle Del Cauca, Colombia']);
        $history = $patient->historiaClinica()->create(['fecha_apertura' => now()]);
        $beforeHistory = $history->refresh()->getAttributes();
        $this->actingAs($this->operator())->putJson(route('pacientes.update', $patient), [...$this->data($patient), 'telefono' => '000123'])->assertRedirect();
        $this->assertNull($patient->refresh()->departamento_codigo);
        $this->assertNull($patient->municipio_codigo);
        $this->assertSame('000123', $patient->telefono);
        $this->putJson(route('pacientes.update', $patient), [...$this->data($patient), 'departamento_codigo' => '05', 'municipio_codigo' => '05001'])->assertRedirect();
        $this->assertSame('05', $patient->refresh()->departamento_codigo);
        $this->assertSame('05001', $patient->municipio_codigo);
        $this->assertSame('Cali, Valle Del Cauca, Colombia', $patient->ciudad);
        $this->assertSame($beforeHistory, $history->refresh()->getAttributes());
    }

    public function test_official_location_can_change_and_is_preserved_when_omitted(): void
    {
        $patient = Paciente::factory()->create(['ciudad' => 'CALI', 'departamento_codigo' => '76', 'municipio_codigo' => '76001']);
        $this->actingAs($this->operator())->patchJson(route('pacientes.update', $patient), [...$this->data($patient), 'departamento_codigo' => '76', 'municipio_codigo' => '76892'])->assertRedirect();
        $this->assertSame('76892', $patient->refresh()->municipio_codigo);
        $this->patchJson(route('pacientes.update', $patient), [...$this->data($patient), 'direccion' => 'Nueva QA'])->assertRedirect();
        $this->assertSame('76892', $patient->refresh()->municipio_codigo);
        $this->assertSame('CALI', $patient->ciudad);
        $this->assertSame('Nueva QA', $patient->direccion);
        $this->patchJson(route('pacientes.update', $patient), [...$this->data($patient), 'departamento_codigo' => null, 'municipio_codigo' => null])->assertUnprocessable()->assertInvalid(['departamento_codigo', 'municipio_codigo']);
        $this->assertSame('76892', $patient->refresh()->municipio_codigo);
    }

    public function test_permissions_and_inactive_account_still_block_writes(): void
    {
        $patient = Paciente::factory()->create();
        $before = $patient->refresh()->getAttributes();
        $this->actingAs($this->operator([]))->postJson(route('pacientes.store'), $this->data())->assertForbidden();
        $this->putJson(route('pacientes.update', $patient), $this->data($patient))->assertForbidden();
        $inactive = $this->operator();
        $inactive->update(['estado' => 'inactivo']);
        $this->actingAs($inactive)->postJson(route('pacientes.store'), $this->data())->assertRedirect('/login');
        $this->assertSame($before, $patient->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_geographic_regularization_rolls_back_when_audit_fails(): void
    {
        $patient = Paciente::factory()->create(['ciudad' => 'Texto original QA']);
        $before = $patient->refresh()->getAttributes();
        $this->actingAs($this->operator())->withoutExceptionHandling();
        Auditoria::creating(function (): void {
            throw new RuntimeException('QA auditoría fallida');
        });
        try {
            $this->putJson(route('pacientes.update', $patient), [...$this->data($patient), 'departamento_codigo' => '76', 'municipio_codigo' => '76001']);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('QA auditoría fallida', $exception->getMessage());
        }
        $this->assertSame($before, $patient->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_form_uses_dependent_local_catalog_without_editable_city(): void
    {
        $this->actingAs($this->operator())->get(route('pacientes.create'))->assertOk()->assertSee('Colombia')->assertSee('name="departamento_codigo"', false)->assertSee('name="municipio_codigo"', false)->assertDontSee('name="ciudad"', false)->assertSee("municipio = ''", false);
        $patient = Paciente::factory()->create(['ciudad' => 'Ciudad antigua QA']);
        $this->get(route('pacientes.edit', $patient))->assertOk()->assertSee('Ciudad antigua QA')->assertSee('Regularizar ubicación geográfica');
        $this->from(route('pacientes.edit', $patient))->put(route('pacientes.update', $patient), [...$this->data($patient), 'departamento_codigo' => '76', 'municipio_codigo' => '05001'])->assertInvalid('municipio_codigo')->assertSessionHasInput('departamento_codigo', '76');
        $this->get(route('pacientes.edit', $patient))->assertOk()->assertSee('value="76" selected', false);
    }
}
