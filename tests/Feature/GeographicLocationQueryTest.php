<?php

namespace Tests\Feature;

use App\Models\Departamento;
use App\Models\Eps;
use App\Models\Municipio;
use App\Models\Paciente;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\EpsPermissionsSeeder;
use Database\Seeders\PacientePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GeographicLocationQueryTest extends TestCase
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
        Departamento::create(['codigo' => '76', 'nombre' => 'VALLE DEL CAUCA']);
        Municipio::create(['codigo' => '76001', 'departamento_codigo' => '76', 'nombre' => 'SANTIAGO DE CALI', 'tipo_territorial' => 'Municipio']);
        Municipio::create(['codigo' => '76892', 'departamento_codigo' => '76', 'nombre' => 'YUMBO', 'tipo_territorial' => 'Municipio']);
        $this->seed([PacientePermissionsSeeder::class, EpsPermissionsSeeder::class]);
        $role = Rol::create(['nombre' => 'QA ubicación']);
        $role->permisos()->sync(Permiso::whereIn('codigo', ['RF-008', 'RF-009', 'RF-010', 'RF-011', 'RF-015', 'RF-016', 'RF-017', 'RF-018'])->pluck('id'));
        $this->actingAs(User::factory()->create(['rol_id' => $role->id]));
    }

    public static function modules(): array
    {
        return [['pacientes'], ['eps']];
    }

    private function factory(string $module): mixed
    {
        return $module === 'pacientes' ? Paciente::factory() : Eps::factory();
    }

    private function attributes(string $module, object $record): array
    {
        return $module === 'eps' ? $record->only(['nombre', 'telefono', 'correo_electronico', 'direccion', 'estado']) : [
            ...$record->only(['tipo_documento', 'numero_documento', 'nombres', 'apellidos', 'sexo', 'telefono', 'direccion', 'tipo_paciente']),
            'fecha_nacimiento' => $record->fecha_nacimiento->format('Y-m-d'),
        ];
    }

    private function records(string $module, array $filters): array
    {
        $response = $this->get(route($module.'.index', $filters))->assertOk();

        return $response->viewData($module === 'eps' ? 'entidades' : 'pacientes')->modelKeys();
    }

    #[DataProvider('modules')]
    public function test_created_in_cali_then_moved_to_yumbo_matches_only_current_location(string $module): void
    {
        $data = $module === 'eps' ? ['nit' => '00QA-LOC', 'nombre' => 'EPS QA', 'telefono' => '000', 'correo_electronico' => 'qa@example.invalid', 'direccion' => 'QA', 'estado' => 'ACTIVA'] : [
            'tipo_documento' => 'CC', 'numero_documento' => '00QA-LOC', 'nombres' => 'Paciente QA', 'apellidos' => 'Ubicación', 'sexo' => 'NO_ESPECIFICA', 'telefono' => '000', 'direccion' => 'QA', 'fecha_nacimiento' => '2000-01-01', 'tipo_paciente' => 'PARTICULAR',
        ];
        $this->post(route($module.'.store'), [...$data, 'departamento_codigo' => '76', 'municipio_codigo' => '76001'])->assertSessionHasNoErrors();
        $record = $module === 'eps' ? Eps::sole() : Paciente::sole();
        $this->patch(route($module.'.update', $record), [...$this->attributes($module, $record), 'departamento_codigo' => '76', 'municipio_codigo' => '76892'])->assertSessionHasNoErrors();
        $this->assertSame('SANTIAGO DE CALI', $record->refresh()->ciudad);
        $this->assertSame([$record->id], $this->records($module, ['ciudad' => 'yumb']));
        $this->assertSame([], $this->records($module, ['ciudad' => 'Cali']));
        $this->get(route($module.'.index'))->assertSee('YUMBO')->assertSee('VALLE DEL CAUCA')->assertSee('Ubicación vigente oficial');
        $this->get(route($module.'.show', $record))->assertOk()->assertSee('YUMBO')->assertSee('Ciudad registrada (histórica): SANTIAGO DE CALI');
    }

    #[DataProvider('modules')]
    public function test_historical_fallback_then_explicit_regularization_preserves_original_text(string $module): void
    {
        $record = $this->factory($module)->create(['ciudad' => 'Cali, Valle Del Cauca, Colombia'])->refresh();
        $this->assertSame([$record->id], $this->records($module, ['ciudad' => 'cali']));
        $this->get(route($module.'.show', $record))->assertSee('Ubicación histórica sin regularizar');
        $this->patch(route($module.'.update', $record), [...$this->attributes($module, $record), 'departamento_codigo' => '76', 'municipio_codigo' => '76892'])->assertSessionHasNoErrors();
        $this->assertSame('Cali, Valle Del Cauca, Colombia', $record->refresh()->ciudad);
        $this->assertSame([], $this->records($module, ['ciudad' => 'cali']));
        $this->assertSame([$record->id], $this->records($module, ['ciudad' => 'YUMBO']));
        $this->get(route($module.'.show', $record))->assertSee('YUMBO')->assertSee('Cali, Valle Del Cauca, Colombia');
    }

    #[DataProvider('modules')]
    public function test_location_or_groups_do_not_bypass_other_filters_or_permissions(string $module): void
    {
        $active = $module === 'eps' ? 'ACTIVA' : 'ACTIVO';
        $inactive = $module === 'eps' ? 'INACTIVA' : 'INACTIVO';
        $official = $this->factory($module)->create(['ciudad' => 'Cali', 'departamento_codigo' => '76', 'municipio_codigo' => '76892', 'estado' => $inactive]);
        $historical = $this->factory($module)->create(['ciudad' => 'Yumbo', 'estado' => $active]);
        $this->factory($module)->create(['ciudad' => 'Cali', 'estado' => $active]);
        $this->assertSame([$historical->id], $this->records($module, ['ciudad' => 'Yumbo', 'estado' => $active]));
        $this->assertSame([$official->id], $this->records($module, ['ciudad' => 'Yumbo', 'estado' => $inactive]));
        if ($module === 'pacientes') {
            $historical->update(['tipo_paciente' => 'PARTICULAR']);
            $this->assertSame([], $this->records($module, ['ciudad' => 'Yumbo', 'estado' => $active, 'tipo_paciente' => 'EPS']));
        }
        $this->assertSame([], $this->records($module, ['ciudad' => '%']));
        $this->actingAs(User::factory()->create())->get(route($module.'.index', ['ciudad' => 'Yumbo']))->assertForbidden();
    }

    #[DataProvider('modules')]
    public function test_detail_identifies_matching_historical_city_without_duplicating_it(string $module): void
    {
        $record = $this->factory($module)->create(['ciudad' => 'YUMBO', 'departamento_codigo' => '76', 'municipio_codigo' => '76892']);
        $before = $record->refresh()->getAttributes();
        $response = $this->get(route($module.'.show', $record))->assertOk()
            ->assertSee('Ciudad registrada (histórica): coincide con el municipio.')
            ->assertDontSee('Ciudad registrada (histórica): YUMBO');
        $this->assertSame(1, substr_count($response->getContent(), 'YUMBO'));
        $this->assertSame($before, $record->refresh()->getAttributes());
    }

    #[DataProvider('modules')]
    public function test_list_and_search_eager_loading_has_constant_geographic_query_count(string $module): void
    {
        $this->factory($module)->create($module === 'eps'
            ? ['nombre' => 'QA ubicación', 'departamento_codigo' => '76', 'municipio_codigo' => '76892']
            : ['nombres' => 'QA ubicación', 'departamento_codigo' => '76', 'municipio_codigo' => '76892']);
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            if (preg_match('/from ["`](municipios|departamentos)["`]/i', $event->sql)) {
                $queries[] = $event->sql;
            }
        });
        foreach (['index', 'search'] as $page) {
            $queries = [];
            $this->get(route($module.'.'.$page, $page === 'search' ? ['buscar' => 'QA'] : []))->assertOk();
            $this->assertCount(2, $queries);
        }
        $this->factory($module)->count(8)->create($module === 'eps'
            ? ['nombre' => 'QA ubicación', 'departamento_codigo' => '76', 'municipio_codigo' => '76892']
            : ['nombres' => 'QA ubicación', 'departamento_codigo' => '76', 'municipio_codigo' => '76892']);
        foreach (['index', 'search'] as $page) {
            $queries = [];
            $response = $this->get(route($module.'.'.$page, $page === 'search' ? ['buscar' => 'QA'] : []))->assertOk();
            $this->assertCount(2, $queries);
            foreach ($response->viewData($module === 'eps' ? 'entidades' : 'pacientes') as $record) {
                $this->assertTrue($record->relationLoaded('municipio'));
                $this->assertTrue($record->relationLoaded('departamento'));
            }
        }
    }
}
