<?php

namespace Tests\Feature;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\MySqlConnection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/** MySQL control-flow and DDL contracts with doubles; no MySQL server is used. */
class GeographyForwardOnlyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->app->environment('testing') || $this->app->configurationIsCached() || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || ! empty(config('database.connections.sqlite.url'))) {
            throw new RuntimeException('Requires uncached isolated SQLite testing configuration.');
        }
        Schema::swap(Schema::getFacadeRoot());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame('', DB::selectOne('PRAGMA database_list')->file);
    }

    public static function migrations(): array
    {
        return [
            'catalog' => ['2026_11_10_050514_create_geographic_catalog_tables'],
            'references' => ['2026_11_10_050515_add_geographic_references_to_patients_and_eps'],
        ];
    }

    private function mysqlDouble(): MySqlConnection
    {
        return new MySqlConnection(function (): never {
            throw new RuntimeException('A real MySQL connection is forbidden in this test.');
        }, 'geography_qa_double', '', ['name' => 'mysql_double', 'version' => '8.0.36']);
    }

    #[DataProvider('migrations')]
    public function test_mysql_down_rejects_before_any_sql_or_schema_inspection(string $name): void
    {
        DB::shouldReceive('getDriverName')->once()->andReturn('mysql');
        Schema::shouldReceive('getColumnListing')->never();
        $migration = require database_path('migrations/'.$name.'.php');
        try {
            $migration->down();
            $this->fail('MySQL down must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame($name.': MySQL is forward-only; reversal requires a controlled manual procedure.', $exception->getMessage());
        }
    }

    #[DataProvider('migrations')]
    public function test_real_migrator_keeps_sqlite_repository_entry_when_mysql_down_throws(string $name): void
    {
        $manager = DB::getFacadeRoot();
        $repository = new DatabaseMigrationRepository($manager, 'migrations');
        $repository->createRepository();
        $repository->log($name, 1);
        $before = $manager->table('migrations')->get()->map(fn (object $row): array => (array) $row)->all();
        $connection = $this->mysqlDouble();
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with(null)->once()->andReturn($connection);
        $resolver->shouldReceive('getDefaultConnection')->once()->andReturn('sqlite');
        $resolver->shouldReceive('setDefaultConnection')->with('mysql_double')->once()->ordered();
        $resolver->shouldReceive('setDefaultConnection')->with('sqlite')->once()->ordered();
        DB::shouldReceive('getDriverName')->once()->andReturn('mysql');
        $migrator = new Migrator($repository, $resolver, new Filesystem);
        try {
            (new ReflectionMethod($migrator, 'runDown'))->invoke($migrator, database_path('migrations/'.$name.'.php'), (object) ['migration' => $name, 'batch' => 1], false);
            $this->fail('The Migrator must propagate the refusal.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($name.': MySQL is forward-only', $exception->getMessage());
        }
        $this->assertSame($before, $manager->table('migrations')->get()->map(fn (object $row): array => (array) $row)->all());
        $this->assertSame([$name], $repository->getRan());
    }

    public function test_catalog_up_generates_the_original_mysql_ddl_without_connecting(): void
    {
        $connection = $this->mysqlDouble();
        $builder = $connection->getSchemaBuilder();
        DB::shouldReceive('getDriverName')->andReturn('mysql');
        Schema::swap($builder);
        $migration = require database_path('migrations/2026_11_10_050514_create_geographic_catalog_tables.php');
        $queries = $connection->pretend(fn () => $migration->up());
        $this->assertSame([
            'create table `departamentos` (`codigo` char(2) character set ascii collate \'ascii_bin\' not null, `nombre` varchar(255) not null, primary key (`codigo`)) engine = InnoDB',
            'create table `municipios` (`codigo` char(5) character set ascii collate \'ascii_bin\' not null, `departamento_codigo` char(2) character set ascii collate \'ascii_bin\' not null, `nombre` varchar(255) not null, `tipo_territorial` varchar(50) not null, primary key (`codigo`)) engine = InnoDB',
            'alter table `municipios` add constraint `municipios_departamento_codigo_foreign` foreign key (`departamento_codigo`) references `departamentos` (`codigo`) on delete restrict on update restrict',
            'alter table `municipios` add unique `municipios_departamento_codigo_codigo_unique`(`departamento_codigo`, `codigo`)',
        ], array_column($queries, 'query'));
        $this->assertSame([[], [], [], []], array_column($queries, 'bindings'));
    }

    public function test_reference_up_preserves_mysql_checks_and_exact_incremental_statements(): void
    {
        DB::shouldReceive('transactionLevel')->once()->andReturn(0);
        $pdo = Mockery::mock(\PDO::class);
        $pdo->shouldReceive('inTransaction')->once()->andReturn(false);
        DB::shouldReceive('connection->getPdo')->once()->andReturn($pdo);
        DB::shouldReceive('getDriverName')->andReturn('mysql');
        foreach (['pacientes', 'eps'] as $table) {
            $columns = $table === 'pacientes'
                ? ['id', 'tipo_documento', 'numero_documento', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono', 'correo_electronico', 'direccion', 'ciudad', 'tipo_paciente', 'estado', 'created_at', 'updated_at', 'motivo_inactivacion']
                : ['id', 'nit', 'nombre', 'telefono', 'correo_electronico', 'direccion', 'ciudad', 'estado', 'created_at', 'updated_at'];
            Schema::shouldReceive('getColumnListing')->with($table)->once()->andReturn($columns);
        }
        DB::shouldReceive('selectOne')->with('SELECT VERSION() AS version')->once()->andReturn((object) ['version' => '8.0.36']);
        foreach (['pacientes', 'eps', 'departamentos', 'municipios'] as $table) {
            DB::shouldReceive('selectOne')->with('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->once()->andReturn((object) ['engine' => 'InnoDB']);
        }
        foreach (['departamentos' => ['codigo' => 'char(2)'], 'municipios' => ['codigo' => 'char(5)', 'departamento_codigo' => 'char(2)']] as $table => $columns) {
            foreach ($columns as $column => $type) {
                DB::shouldReceive('selectOne')->with('SELECT COLUMN_TYPE AS type, CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column])->once()->andReturn((object) ['type' => $type, 'charset' => 'ascii', 'collation' => 'ascii_bin']);
            }
        }
        foreach (['pacientes', 'eps'] as $table) {
            $sql = 'ALTER TABLE '.$table.' ADD departamento_codigo CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD municipio_codigo CHAR(5) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD CONSTRAINT '.$table.'_departamento_geo_fk FOREIGN KEY (departamento_codigo) REFERENCES departamentos(codigo) ON DELETE RESTRICT ON UPDATE RESTRICT, ADD CONSTRAINT '.$table.'_municipio_geo_fk FOREIGN KEY (departamento_codigo, municipio_codigo) REFERENCES municipios(departamento_codigo, codigo) ON DELETE RESTRICT ON UPDATE RESTRICT, ADD CONSTRAINT '.$table.'_geo_pair_check CHECK ((departamento_codigo IS NULL AND municipio_codigo IS NULL) OR (departamento_codigo IS NOT NULL AND municipio_codigo IS NOT NULL))';
            DB::shouldReceive('statement')->with($sql)->once()->ordered()->andReturn(true);
        }
        $migration = require database_path('migrations/2026_11_10_050515_add_geographic_references_to_patients_and_eps.php');
        $migration->up();
    }
}
