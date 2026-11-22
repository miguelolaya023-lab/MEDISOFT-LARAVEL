<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Departamento;
use App\Models\Eps;
use App\Models\Municipio;
use App\Models\Permiso;
use App\Models\RemisionEps;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\EpsPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EpsGeographyTest extends TestCase
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

    private function operator(array $codes = ['RF-015', 'RF-016']): User
    {
        $this->seed(EpsPermissionsSeeder::class);
        $role = Rol::create(['nombre' => 'QA geografía EPS '.uniqid()]);
        $role->permisos()->sync(Permiso::whereIn('codigo', $codes)->pluck('id'));

        return User::factory()->create(['rol_id' => $role->id]);
    }

    private function data(?Eps $eps = null): array
    {
        return $eps ? $eps->only(['nombre', 'telefono', 'correo_electronico', 'direccion', 'estado']) : [
            'nit' => ' 00qa-001 ', 'nombre' => 'EPS ficticia QA', 'telefono' => '0000000000',
            'correo_electronico' => 'qa@example.invalid', 'direccion' => 'Ficticia QA', 'estado' => 'ACTIVA',
            'departamento_codigo' => '76', 'municipio_codigo' => '76001',
        ];
    }

    public function test_registration_derives_city_and_normalizes_nit(): void
    {
        $this->actingAs($this->operator())->postJson(route('eps.store'), $this->data())->assertRedirect();
        $eps = Eps::sole();
        $this->assertSame('00QA-001', $eps->nit);
        $this->assertSame('76', $eps->departamento_codigo);
        $this->assertSame('76001', $eps->municipio_codigo);
        $this->assertSame('SANTIAGO DE CALI', $eps->ciudad);
        $this->assertSame(1, Auditoria::where('accion', 'EPS_CREADA')->count());
    }

    public static function invalidSelections(): array
    {
        return [
            'unknown department' => [['departamento_codigo' => '99'], 'departamento_codigo'],
            'unknown municipality' => [['municipio_codigo' => '99999'], 'municipio_codigo'],
            'wrong pair' => [['municipio_codigo' => '05001'], 'municipio_codigo'],
            'null department' => [['departamento_codigo' => null], 'departamento_codigo'],
            'empty municipality' => [['municipio_codigo' => ''], 'municipio_codigo'],
            'numeric department' => [['departamento_codigo' => 76], 'departamento_codigo'],
            'array municipality' => [['municipio_codigo' => ['76001']], 'municipio_codigo'],
            'client city' => [['ciudad' => 'Manipulada QA'], 'ciudad'],
        ];
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_create_has_no_partial_writes(array $extra, string $field): void
    {
        $this->actingAs($this->operator())->postJson(route('eps.store'), [...$this->data(), ...$extra])->assertUnprocessable()->assertInvalid($field);
        $this->assertDatabaseCount('eps', 0);
        $this->assertDatabaseCount('auditorias', 0);
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_update_keeps_all_fields_and_nit(array $extra, string $field): void
    {
        $eps = Eps::factory()->create(['departamento_codigo' => '76', 'municipio_codigo' => '76001'])->refresh();
        $before = $eps->getAttributes();
        $this->actingAs($this->operator())->putJson(route('eps.update', $eps), [...$this->data($eps), 'departamento_codigo' => '76', 'municipio_codigo' => '76001', ...$extra])->assertUnprocessable()->assertInvalid($field);
        $this->assertSame($before, $eps->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_historical_edit_and_regularization_preserve_city_nit_and_remissions(): void
    {
        $eps = Eps::factory()->create(['ciudad' => 'Cali, Valle Del Cauca, Colombia'])->refresh();
        $nit = $eps->nit;
        $created = $eps->getRawOriginal('created_at');
        $remission = RemisionEps::factory()->create(['eps_id' => $eps->id])->refresh();
        $before = $remission->getAttributes();
        $this->actingAs($this->operator())->putJson(route('eps.update', $eps), [...$this->data($eps), 'telefono' => '000123'])->assertRedirect();
        $this->assertNull($eps->refresh()->municipio_codigo);
        $this->putJson(route('eps.update', $eps), [...$this->data($eps), 'departamento_codigo' => '05', 'municipio_codigo' => '05001'])->assertRedirect();
        $this->assertSame('05', $eps->refresh()->departamento_codigo);
        $this->assertSame('05001', $eps->municipio_codigo);
        $this->assertSame('Cali, Valle Del Cauca, Colombia', $eps->ciudad);
        $this->assertSame($nit, $eps->nit);
        $this->assertSame($created, $eps->getRawOriginal('created_at'));
        $this->assertSame($before, $remission->refresh()->getAttributes());
    }

    public function test_official_location_can_change_be_omitted_and_cannot_be_cleared(): void
    {
        $eps = Eps::factory()->create(['ciudad' => 'Texto original QA', 'departamento_codigo' => '76', 'municipio_codigo' => '76001'])->refresh();
        $nit = $eps->nit;
        $this->actingAs($this->operator())->patchJson(route('eps.update', $eps), [...$this->data($eps), 'departamento_codigo' => '76', 'municipio_codigo' => '76892'])->assertRedirect();
        $this->patchJson(route('eps.update', $eps), [...$this->data($eps), 'direccion' => 'Nueva QA'])->assertRedirect();
        $this->assertSame('76892', $eps->refresh()->municipio_codigo);
        $this->assertSame('Texto original QA', $eps->ciudad);
        $this->assertSame($nit, $eps->nit);
        $this->patchJson(route('eps.update', $eps), [...$this->data($eps), 'departamento_codigo' => null, 'municipio_codigo' => null])->assertUnprocessable()->assertInvalid(['departamento_codigo', 'municipio_codigo']);
        $this->assertSame('76892', $eps->refresh()->municipio_codigo);
    }

    public function test_forged_nit_html_422_renders_geography_with_attempted_safe_values(): void
    {
        $eps = Eps::factory()->create(['ciudad' => 'Ciudad histórica QA'])->refresh();
        $before = $eps->getAttributes();
        $this->actingAs($this->operator())->put(route('eps.update', $eps), [...$this->data($eps), 'nit' => $eps->nit, 'departamento_codigo' => '05', 'municipio_codigo' => '05001', 'nombre' => 'Nombre intentado QA'])
            ->assertUnprocessable()->assertViewIs('eps.edit')->assertSee('Nombre intentado QA')->assertSee('value="05" selected', false)
            ->assertSee('Colombia')->assertDontSee('name="nit"', false)->assertDontSee('name="ciudad"', false);
        $this->assertSame($before, $eps->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_regular_validation_restores_location_after_redirect(): void
    {
        $eps = Eps::factory()->create();
        $this->actingAs($this->operator())->from(route('eps.edit', $eps))->put(route('eps.update', $eps), [...$this->data($eps), 'departamento_codigo' => '76', 'municipio_codigo' => '05001'])->assertInvalid('municipio_codigo')->assertSessionHasInput('departamento_codigo', '76');
        $this->get(route('eps.edit', $eps))->assertOk()->assertSee('value="76" selected', false)->assertSee('Regularizar ubicación geográfica');
    }

    public function test_permission_and_state_validation_still_apply(): void
    {
        $eps = Eps::factory()->create()->refresh();
        $before = $eps->getAttributes();
        $this->actingAs($this->operator([]))->postJson(route('eps.store'), $this->data())->assertForbidden();
        $this->putJson(route('eps.update', $eps), $this->data($eps))->assertForbidden();
        $this->actingAs($this->operator())->putJson(route('eps.update', $eps), [...$this->data($eps), 'estado' => $eps->estado === 'ACTIVA' ? 'INACTIVA' : 'ACTIVA'])->assertUnprocessable()->assertInvalid('motivo');
        $this->assertSame($before, $eps->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }

    public function test_geographic_update_rolls_back_on_audit_failure(): void
    {
        $eps = Eps::factory()->create()->refresh();
        $before = $eps->getAttributes();
        $this->actingAs($this->operator())->withoutExceptionHandling();
        Auditoria::creating(function (): void {
            throw new RuntimeException('QA audit failure');
        });
        try {
            $this->putJson(route('eps.update', $eps), [...$this->data($eps), 'departamento_codigo' => '76', 'municipio_codigo' => '76001']);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('QA audit failure', $exception->getMessage());
        }
        $this->assertSame($before, $eps->refresh()->getAttributes());
        $this->assertDatabaseCount('auditorias', 0);
    }
}
