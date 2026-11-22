<?php

namespace Tests\Feature;

use App\Models\Departamento;
use App\Models\Eps;
use App\Models\Municipio;
use App\Models\Paciente;
use ErrorException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DivipolaImportTest extends TestCase
{
    private ?string $resourceCopy = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->app->environment('testing') || $this->app->configurationIsCached() || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || ! empty(config('database.connections.sqlite.url'))) {
            throw new RuntimeException('Tests require uncached SQLite :memory: with no DB URL.');
        }
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame('', DB::selectOne('PRAGMA database_list')->file);
        $files = [
            '0001_01_01_000000_create_users_table.php',
            '0001_01_01_000001_create_cache_table.php',
            '0001_01_01_000002_create_jobs_table.php',
            '2026_06_02_032336_add_internal_user_fields_to_users_table.php',
            '2026_06_02_035813_add_tipo_usuario_to_users_table.php',
            '2026_10_04_135340_add_security_and_audit_foundation.php',
            '2026_10_05_025822_create_pacientes_table.php',
            '2026_10_05_025823_create_historias_clinicas_table.php',
            '2026_10_24_033535_add_motivo_inactivacion_to_pacientes_table.php',
            '2026_10_25_164147_create_antecedentes_clinicos_and_revisiones_tables.php',
            '2026_10_25_190725_create_eps_table.php',
            '2026_10_25_224612_create_remisiones_eps_and_estado_cambios_tables.php',
            '2026_10_26_011632_create_disponibilidades_medicas_table.php',
            '2026_10_26_033704_create_citas_table.php',
            '2026_11_10_050514_create_geographic_catalog_tables.php',
            '2026_11_10_050515_add_geographic_references_to_patients_and_eps.php',
        ];
        $this->artisan('migrate', ['--database' => 'sqlite', '--path' => array_map(fn (string $file): string => database_path('migrations/'.$file), $files), '--realpath' => true, '--no-interaction' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->resourceCopy !== null) {
                $directory = $this->resourceCopy.'/data/divipola/2024-12-30';
                foreach (['source.json', 'metadata.json', 'catalogo.json', 'manifest.json'] as $file) {
                    unlink($directory.'/'.$file);
                }
                rmdir($directory);
                rmdir(dirname($directory));
                rmdir(dirname($directory, 2));
                rmdir($this->resourceCopy);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function snapshot(string $table): array
    {
        return DB::table($table)->orderBy($table === 'departamentos' || $table === 'municipios' ? 'codigo' : 'id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    private function copyCatalog(): string
    {
        $this->resourceCopy = sys_get_temp_dir().'/medisoft-divipola-'.bin2hex(random_bytes(10));
        $directory = $this->resourceCopy.'/data/divipola/2024-12-30';
        mkdir($directory, 0700, true);
        foreach (['source.json', 'metadata.json', 'catalogo.json', 'manifest.json'] as $file) {
            copy(resource_path('data/divipola/2024-12-30/'.$file), $directory.'/'.$file);
        }
        File::shouldReceive('isFile')->andReturn(true);
        File::shouldReceive('isReadable')->andReturn(true);
        File::shouldReceive('get')->andReturnUsing(fn (string $path): string => file_get_contents($directory.'/'.basename($path)));

        return $directory;
    }

    public function test_complete_import_is_idempotent_and_preserves_historical_data_and_extra_rows(): void
    {
        $patient = Paciente::factory()->create(['ciudad' => 'Ciudad histórica QA']);
        $eps = Eps::factory()->create(['ciudad' => 'Texto histórico EPS QA']);
        Departamento::create(['codigo' => '00', 'nombre' => 'Extra QA']);
        Municipio::create(['codigo' => '00001', 'departamento_codigo' => '00', 'nombre' => 'Extra QA', 'tipo_territorial' => 'Extra QA']);
        $patients = $this->snapshot('pacientes');
        $companies = $this->snapshot('eps');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->artisan('divipola:import')->expectsOutput('departamentos: insertados=33 existentes=0 total=34')->expectsOutput('municipios: insertados=1122 existentes=0 total=1123')->expectsOutput('Discrepancias=0')->assertExitCode(0);
        $departments = $this->snapshot('departamentos');
        $municipalities = $this->snapshot('municipios');
        $this->artisan('divipola:import')->expectsOutput('departamentos: insertados=0 existentes=33 total=34')->expectsOutput('municipios: insertados=0 existentes=1122 total=1123')->assertExitCode(0);
        $this->assertSame($departments, $this->snapshot('departamentos'));
        $this->assertSame($municipalities, $this->snapshot('municipios'));
        $this->assertSame($patients, $this->snapshot('pacientes'));
        $this->assertSame($companies, $this->snapshot('eps'));
        $this->assertNull($patient->refresh()->municipio_codigo);
        $this->assertNull($eps->refresh()->departamento_codigo);
        $types = Municipio::where('codigo', '!=', '00001')->selectRaw('tipo_territorial, count(*) as total')->groupBy('tipo_territorial')->pluck('total', 'tipo_territorial')->all();
        $this->assertEquals(['Municipio' => 1103, 'Isla' => 1, 'Área no municipalizada' => 18], $types);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(select|insert)\b/i', $sql);
        }
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_string_keys_and_all_relationships_resolve_without_timestamps(): void
    {
        $this->artisan('divipola:import')->assertExitCode(0);
        $department = Departamento::findOrFail('05');
        $municipality = Municipio::findOrFail('05001');
        $this->assertSame('05', $department->getKey());
        $this->assertSame('05001', $municipality->getKey());
        foreach ([$department, $municipality] as $model) {
            $this->assertFalse($model->getIncrementing());
            $this->assertFalse($model->usesTimestamps());
            $this->assertSame('string', $model->getKeyType());
            $this->assertArrayNotHasKey('created_at', $model->getAttributes());
        }
        $this->assertTrue($municipality->departamento->is($department));
        $this->assertTrue($department->municipios->contains(fn (Municipio $row): bool => $row->is($municipality)));
        $patient = Paciente::factory()->create();
        $eps = Eps::factory()->create();
        foreach ([$patient, $eps] as $model) {
            $this->assertNull($model->departamento);
            $this->assertNull($model->municipio);
            $model->forceFill(['departamento_codigo' => '05', 'municipio_codigo' => '05001'])->save();
        }
        foreach ([Paciente::with(['departamento', 'municipio'])->findOrFail($patient->id), Eps::with(['departamento', 'municipio'])->findOrFail($eps->id)] as $model) {
            $this->assertTrue($model->departamento->is($department));
            $this->assertTrue($model->municipio->is($municipality));
        }
    }

    public function test_divergence_rolls_back_missing_records_inserted_before_it(): void
    {
        Departamento::create(['codigo' => '05', 'nombre' => 'ANTIOQUIA']);
        Municipio::create(['codigo' => '05002', 'departamento_codigo' => '05', 'nombre' => 'Divergencia QA', 'tipo_territorial' => 'Municipio']);
        $departments = $this->snapshot('departamentos');
        $municipalities = $this->snapshot('municipios');
        $this->artisan('divipola:import')->expectsOutputToContain('Discrepancia en municipios código 05002')->expectsOutput('Inserciones confirmadas=0; discrepancias detectadas=1')->assertExitCode(1);
        $this->assertSame($departments, $this->snapshot('departamentos'));
        $this->assertSame($municipalities, $this->snapshot('municipios'));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_real_sql_failure_after_partial_inserts_rolls_back_everything(): void
    {
        DB::unprepared("CREATE TRIGGER fail_qa BEFORE INSERT ON municipios WHEN NEW.codigo = '05002' BEGIN SELECT RAISE(ABORT, 'FALLO_FICTICIO_QA'); END");
        $attempted = [];
        DB::listen(function ($query) use (&$attempted): void {
            if (str_starts_with($query->sql, 'insert')) {
                $attempted[] = $query->bindings;
            }
        });
        $this->artisan('divipola:import')->expectsOutput('Error de base de datos; importación revertida. Sin inserciones confirmadas ni sobrescrituras.')->assertExitCode(1);
        $this->assertGreaterThanOrEqual(34, count($attempted));
        $this->assertSame(0, Departamento::count());
        $this->assertSame(0, Municipio::count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
    }

    public function test_missing_tables_require_migration_without_running_it(): void
    {
        $this->app['db']->disconnect('sqlite');
        $this->app['db']->purge('sqlite');
        $this->assertSame('', DB::selectOne('PRAGMA database_list')->file);
        $this->artisan('divipola:import')->expectsOutputToContain('Faltan tablas geográficas')->assertExitCode(1);
        $this->assertSame([], DB::select("SELECT name FROM sqlite_schema WHERE type='table'"));
    }

    public function test_incompatible_manifest_prevents_import(): void
    {
        $directory = $this->copyCatalog();
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['department_codes'] = 32;
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->artisan('divipola:import')->expectsOutput('Manifiesto DIVIPOLA incompatible con la edición fijada.')->assertExitCode(1);
        $this->assertSame(0, Departamento::count());
        $this->assertSame(0, Municipio::count());
    }

    public function test_source_must_match_normalized_catalog_even_with_matching_hash(): void
    {
        $directory = $this->copyCatalog();
        $source = json_decode(file_get_contents($directory.'/source.json'), true, flags: JSON_THROW_ON_ERROR);
        $source[0]['nom_mpio'] = 'Cambio ficticio QA';
        file_put_contents($directory.'/source.json', json_encode($source, JSON_THROW_ON_ERROR));
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['sha256']['source.json'] = hash_file('sha256', $directory.'/source.json');
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->artisan('divipola:import')->expectsOutput('La copia normalizada difiere de la fuente DIVIPOLA.')->assertExitCode(1);
        $this->assertSame(0, Departamento::count());
        $this->assertSame(0, Municipio::count());
    }

    public static function corruptFiles(): array
    {
        return [['source.json'], ['metadata.json'], ['catalogo.json']];
    }

    #[DataProvider('corruptFiles')]
    public function test_checksum_failure_prevents_any_import(string $file): void
    {
        $directory = $this->copyCatalog();
        file_put_contents($directory.'/'.$file, 'CORRUPCION_FICTICIA_QA');
        $this->artisan('divipola:import')->expectsOutput('SHA-256 incorrecto: '.$file.'.')->assertExitCode(1);
        $this->assertSame(0, Departamento::count());
        $this->assertSame(0, Municipio::count());
    }

    public static function unavailableCatalogs(): array
    {
        return [
            'missing' => ['missing'],
            'unreadable' => ['unreadable'],
            'disappears during read' => ['not-found'],
            'read warning' => ['warning'],
            'read returns false' => ['false'],
        ];
    }

    #[DataProvider('unavailableCatalogs')]
    public function test_unavailable_catalog_fails_with_controlled_output_and_no_writes(string $variant): void
    {
        $catalog = resource_path('data/divipola/2024-12-30/catalogo.json');
        File::shouldReceive('isFile')->andReturnUsing(fn (string $path): bool => $path !== $catalog || $variant !== 'missing');
        File::shouldReceive('isReadable')->andReturnUsing(fn (string $path): bool => $path !== $catalog || $variant !== 'unreadable');
        File::shouldReceive('get')->andReturnUsing(function (string $path) use ($catalog, $variant): string|false {
            if ($path !== $catalog) {
                return file_get_contents($path);
            }

            return match ($variant) {
                'not-found' => throw new FileNotFoundException('Detalle privado QA'),
                'warning' => throw new ErrorException('Detalle privado QA'),
                'false' => false,
                default => throw new RuntimeException('El precheck debía impedir la lectura'),
            };
        });

        $message = in_array($variant, ['missing', 'unreadable'], true)
            ? 'Archivo DIVIPOLA local ausente o ilegible: catalogo.json.'
            : 'No se pudo leer la copia DIVIPOLA local.';
        $this->artisan('divipola:import')->expectsOutput($message)->doesntExpectOutputToContain('Detalle privado QA')->assertExitCode(1);
        $this->assertDatabaseCount('departamentos', 0);
        $this->assertDatabaseCount('municipios', 0);
    }

    public function test_malformed_json_is_rejected_even_with_matching_checksum(): void
    {
        $directory = $this->copyCatalog();
        file_put_contents($directory.'/catalogo.json', '[{"cod_dpto":');
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['sha256']['catalogo.json'] = hash_file('sha256', $directory.'/catalogo.json');
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->artisan('divipola:import')->expectsOutput('JSON DIVIPOLA inválido; importación rechazada sin escrituras.')->assertExitCode(1);
        $this->assertDatabaseCount('departamentos', 0);
        $this->assertDatabaseCount('municipios', 0);
    }

    public static function invalidCatalogs(): array
    {
        return [['numeric-code'], ['mismatched-pair'], ['duplicate'], ['department-name'], ['type'], ['columns']];
    }

    #[DataProvider('invalidCatalogs')]
    public function test_invalid_structure_is_rejected_even_with_matching_checksum(string $variant): void
    {
        $directory = $this->copyCatalog();
        $rows = json_decode(file_get_contents($directory.'/catalogo.json'), true, flags: JSON_THROW_ON_ERROR);
        match ($variant) {
            'numeric-code' => $rows[0]['cod_dpto'] = 5,
            'mismatched-pair' => $rows[0]['cod_mpio'] = '08001',
            'duplicate' => $rows[1] = $rows[0],
            'department-name' => $rows[1]['dpto'] = 'OTRO QA',
            'type' => $rows[0]['tipo_municipio'] = 'Inventado QA',
            default => $rows[0]['extra'] = 'No permitido',
        };
        file_put_contents($directory.'/catalogo.json', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['sha256']['catalogo.json'] = hash_file('sha256', $directory.'/catalogo.json');
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->artisan('divipola:import')->assertExitCode(1);
        $this->assertSame(0, Departamento::count());
        $this->assertSame(0, Municipio::count());
    }
}
