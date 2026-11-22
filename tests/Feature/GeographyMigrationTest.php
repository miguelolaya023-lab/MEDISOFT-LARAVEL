<?php

namespace Tests\Feature;

use App\Models\AntecedenteClinico;
use App\Models\Auditoria;
use App\Models\Cita;
use App\Models\Eps;
use App\Models\Paciente;
use App\Models\RemisionEps;
use App\Models\RevisionAntecedenteClinico;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Physical migration verification on an exclusive temporary SQLite database. */
class GeographyMigrationTest extends TestCase
{
    private ?string $temporaryDatabase = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->app->environment('testing') || $this->app->configurationIsCached() || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:' || ! empty(config('database.connections.sqlite.url'))) {
            throw new RuntimeException('Experiment requires isolated testing configuration.');
        }
        $path = tempnam(sys_get_temp_dir(), 'medisoft-rebuild-experiment-');
        if ($path === false) {
            throw new RuntimeException('Cannot allocate exclusive SQLite file.');
        }
        $this->temporaryDatabase = $path;
        config(['database.connections.sqlite.database' => $path, 'database.connections.sqlite.foreign_key_constraints' => true]);
        DB::purge('sqlite');
        $this->assertSame($path, DB::connection()->getConfig('database'));
        $this->assertSame($path, DB::selectOne('PRAGMA database_list')->file);
        // Frozen baseline: the experiment builds geographic objects itself.
        $historicalMigrations = [
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
        ];
        $paths = array_map(fn (string $file): string => database_path('migrations/'.$file), $historicalMigrations);
        $this->artisan('migrate', ['--database' => 'sqlite', '--path' => $paths, '--realpath' => true, '--no-interaction' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        try {
            DB::disconnect('sqlite');
        } finally {
            parent::tearDown();
            if ($this->temporaryDatabase !== null && is_file($this->temporaryDatabase)) {
                unlink($this->temporaryDatabase);
            }
        }
    }

    private function rows(PDO $pdo, string $table): array
    {
        return $pdo->query('SELECT * FROM "'.$table.'" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function schema(PDO $pdo): array
    {
        return $pdo->query("SELECT type, name, tbl_name, sql FROM sqlite_schema WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
    }

    private function snapshot(PDO $pdo): array
    {
        $rows = [];
        foreach ($pdo->query("SELECT name FROM sqlite_schema WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $rows[$table] = $this->rows($pdo, $table);
        }

        return $rows;
    }

    private function indexes(PDO $pdo, string $table): array
    {
        $indexes = [];
        foreach ($pdo->query('PRAGMA index_list("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC) as $index) {
            unset($index['seq']);
            $index['columns'] = $pdo->query('PRAGMA index_xinfo("'.$index['name'].'")')->fetchAll(PDO::FETCH_ASSOC);
            $indexes[$index['name']] = $index;
        }
        ksort($indexes);

        return $indexes;
    }

    private function reject(PDO $pdo, string $sql, string $reason): void
    {
        try {
            $pdo->exec($sql);
            $this->fail('Expected constraint rejection: '.$reason);
        } catch (PDOException $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }
    }

    private function referencesMigration(): object
    {
        return require database_path('migrations/2026_11_10_050515_add_geographic_references_to_patients_and_eps.php');
    }

    private function reconstruct(PDO $pdo, ?string $failAfter): void
    {
        $armed = $failAfter !== null;
        DB::connection()->beforeExecuting(static function (string $sql) use ($failAfter, &$armed): void {
            if ($armed && str_starts_with($sql, 'ALTER TABLE "__geo_'.$failAfter.'" RENAME')) {
                $armed = false;
                throw new RuntimeException('Induced DDL failure after dropping '.$failAfter);
            }
        });
        $this->referencesMigration()->up();
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
    }

    public static function temporaryObjects(): array
    {
        return [['trigger'], ['view'], ['unrelated-table']];
    }

    #[DataProvider('temporaryObjects')]
    public function test_temporary_objects_abort_before_reconstruction_without_losses(string $kind): void
    {
        $patient = Paciente::factory()->create();
        $pdo = DB::connection()->getPdo();
        match ($kind) {
            'trigger' => $pdo->exec('CREATE TEMP TRIGGER temporary_patient_trigger AFTER UPDATE ON main.pacientes BEGIN SELECT 1; END'),
            'view' => $pdo->exec('CREATE TEMP VIEW temporary_patient_view AS SELECT id, ciudad FROM main.pacientes'),
            default => $pdo->exec('CREATE TEMP TABLE temporary_unknown (id INTEGER)'),
        };
        $schema = $this->schema($pdo);
        $rows = $this->snapshot($pdo);
        $temporary = $pdo->query('SELECT type, name, sql FROM sqlite_temp_schema ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        try {
            $this->referencesMigration()->up();
            $this->fail('Expected temporary object rejection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Temporary SQLite objects', $exception->getMessage());
        }
        $this->assertSame($schema, $this->schema($pdo));
        $this->assertSame($rows, $this->snapshot($pdo));
        $this->assertSame($temporary, $pdo->query('SELECT type, name, sql FROM sqlite_temp_schema ORDER BY name')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame($patient->ciudad, $patient->refresh()->ciudad);
    }

    public function test_catalog_down_rejects_dependent_view_before_any_drop(): void
    {
        $catalog = require database_path('migrations/2026_11_10_050514_create_geographic_catalog_tables.php');
        $catalog->up();
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE VIEW geographic_dependency AS SELECT codigo FROM departamentos');
        $schema = $this->schema($pdo);
        try {
            $catalog->down();
            $this->fail('Expected dependent view rejection.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Dependent object prevents catalog reversal.', $exception->getMessage());
        }
        $this->assertSame($schema, $this->schema($pdo));
        $this->assertSame([], $pdo->query('SELECT * FROM geographic_dependency')->fetchAll());
        $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertFalse($pdo->inTransaction());
    }

    public function test_empty_catalog_and_references_can_be_reversed_without_losing_legacy_columns(): void
    {
        $catalog = require database_path('migrations/2026_11_10_050514_create_geographic_catalog_tables.php');
        $catalog->up();
        $migration = $this->referencesMigration();
        $migration->up();
        $migration->down();
        $catalog->down();
        $this->assertFalse(Schema::hasTable('departamentos'));
        $this->assertFalse(Schema::hasTable('municipios'));
        $this->assertTrue(Schema::hasColumn('pacientes', 'ciudad'));
    }

    public function test_schema_drift_is_rejected_before_any_table_changes(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('ALTER TABLE pacientes ADD COLUMN unexpected TEXT');
        $before = $this->schema($pdo);
        try {
            $this->referencesMigration()->up();
            $this->fail('Expected schema rejection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Unexpected schema', $exception->getMessage());
        }
        $this->assertSame($before, $this->schema($pdo));
        $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
    }

    public function test_active_transaction_is_rejected_without_toggling_foreign_keys(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            $this->referencesMigration()->up();
            $this->fail('Expected transaction rejection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no active transaction', $exception->getMessage());
            $this->assertTrue($pdo->inTransaction());
            $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        } finally {
            $pdo->rollBack();
        }
    }

    public function test_reconstruction_preserves_physical_schema_history_and_rolls_back_ddl_failures(): void
    {
        $paciente = Paciente::factory()->create(['ciudad' => 'Ciudad histórica ficticia']);
        $historia = $paciente->historiaClinica()->create(['fecha_apertura' => now()->subYear()]);
        $antecedente = AntecedenteClinico::factory()->create(['historia_clinica_id' => $historia->id]);
        RevisionAntecedenteClinico::factory()->create(['antecedente_clinico_id' => $antecedente->id]);
        $eps = Eps::factory()->create(['ciudad' => 'Otra ciudad histórica']);
        RemisionEps::factory()->create(['paciente_id' => $paciente->id, 'eps_id' => $eps->id]);
        $cita = Cita::factory()->create(['paciente_id' => $paciente->id]);
        Auditoria::query()->create(['user_id' => $cita->created_by, 'accion' => 'EXPERIMENTO', 'entidad' => 'Cita', 'registro_id' => $cita->id, 'fecha_hora' => now()->subDay(), 'resultado' => 'EXITO', 'descripcion' => 'Evento histórico ficticio']);
        $pdo = DB::connection()->getPdo();
        $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        // Synthetic schema objects exercise preservation beyond today's migrations.
        $this->assertSame([], $pdo->query("SELECT name FROM sqlite_schema WHERE type IN ('trigger','view')")->fetchAll());
        $pdo->exec('CREATE TABLE experiment_probe (value TEXT)');
        $pdo->exec('CREATE TRIGGER experiment_eps_update AFTER UPDATE ON eps BEGIN INSERT INTO experiment_probe VALUES (NEW.nombre); END');
        $pdo->exec('CREATE VIEW experiment_patient_view AS SELECT id, ciudad FROM pacientes');
        $catalog = require database_path('migrations/2026_11_10_050514_create_geographic_catalog_tables.php');
        $catalog->up();
        $pdo->exec("INSERT INTO departamentos VALUES ('05','Departamento ficticio A'),('76','Departamento ficticio B')");
        $pdo->exec("INSERT INTO municipios VALUES ('05001','05','Municipio ficticio A','Municipio'),('76001','76','Municipio ficticio B','Municipio')");
        // Preserve AUTOINCREMENT's high-water mark, not merely MAX(id).
        $pdo->exec("UPDATE sqlite_sequence SET seq=500 WHERE name IN ('pacientes','eps')");
        $before = $this->snapshot($pdo);
        $schema = $this->schema($pdo);
        $originalColumns = [];
        $originalIndexes = [];
        foreach (['pacientes', 'eps'] as $table) {
            $originalColumns[$table] = $pdo->query('PRAGMA table_info("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC);
            $originalIndexes[$table] = $this->indexes($pdo, $table);
        }
        $sequences = $this->rows($pdo, 'sqlite_sequence');
        foreach (['pacientes', 'eps'] as $stage) {
            try {
                $this->reconstruct($pdo, $stage);
                $this->fail('Expected induced rollback.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Induced DDL failure after dropping '.$stage, $exception->getMessage());
            }
            $this->assertSame($schema, $this->schema($pdo));
            $this->assertSame($before, $this->snapshot($pdo));
            $this->assertSame($sequences, $this->rows($pdo, 'sqlite_sequence'));
        }
        $this->reconstruct($pdo, null);
        foreach ($before as $table => $rows) {
            $after = $this->rows($pdo, $table);
            if (in_array($table, ['pacientes', 'eps'], true)) {
                foreach ($after as &$row) {
                    $this->assertNull($row['departamento_codigo']);
                    $this->assertNull($row['municipio_codigo']);
                    unset($row['departamento_codigo'], $row['municipio_codigo']);
                }
                unset($row);
            }
            $this->assertSame($rows, $after, $table);
        }
        foreach ($schema as $object) {
            if (! ($object['type'] === 'table' && in_array($object['name'], ['pacientes', 'eps'], true))) {
                $this->assertContains($object, $this->schema($pdo));
            } else {
                $updated = $pdo->query("SELECT sql FROM sqlite_schema WHERE name='".$object['name']."'")->fetchColumn();
                $this->assertStringContainsString(substr($object['sql'], strpos($object['sql'], '('), -1), $updated);
            }
        }
        foreach (['pacientes', 'eps'] as $table) {
            $columns = $pdo->query('PRAGMA table_info("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame($originalColumns[$table], array_slice($columns, 0, count($originalColumns[$table])));
            $this->assertSame($originalIndexes[$table], $this->indexes($pdo, $table));
            $this->assertCount(3, $pdo->query('PRAGMA foreign_key_list("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC));
            $copyColumns = array_filter(array_column($columns, 'name'), fn (string $column): bool => $column !== 'id');
            $projection = implode(',', array_map(fn (string $column): string => '"'.$column.'"', $copyColumns));
            $this->reject($pdo, 'INSERT INTO "'.$table.'" ('.$projection.') SELECT '.$projection.' FROM "'.$table.'"', 'UNIQUE');
            $this->assertSame('500', (string) $pdo->query("SELECT seq FROM sqlite_sequence WHERE name='$table'")->fetchColumn());
            $this->reject($pdo, "UPDATE $table SET estado='INVALIDO'", 'CHECK');
            $this->reject($pdo, "UPDATE $table SET departamento_codigo='05'", 'CHECK');
            $this->reject($pdo, "UPDATE $table SET municipio_codigo='05001'", 'CHECK');
            $this->reject($pdo, "UPDATE $table SET departamento_codigo='76',municipio_codigo='05001'", 'FOREIGN KEY');
            $this->reject($pdo, "UPDATE $table SET departamento_codigo='99',municipio_codigo='99001'", 'FOREIGN KEY');
            $pdo->exec("UPDATE $table SET departamento_codigo='05',municipio_codigo='05001'");
            $pdo->exec("UPDATE $table SET departamento_codigo=NULL,municipio_codigo=NULL");
        }
        $this->reject($pdo, "UPDATE pacientes SET tipo_paciente='INVALIDO'", 'CHECK');
        $this->reject($pdo, 'DELETE FROM pacientes', 'FOREIGN KEY');
        $this->reject($pdo, 'DELETE FROM eps', 'FOREIGN KEY');
        $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertGreaterThan(0, $pdo->query('SELECT COUNT(*) FROM experiment_probe')->fetchColumn());
        $this->assertSame($paciente->ciudad, $pdo->query('SELECT ciudad FROM experiment_patient_view')->fetchColumn());
        $this->assertSame(501, Paciente::factory()->create()->id);
        $this->assertSame(501, Eps::factory()->create()->id);
        $pdo->exec("UPDATE pacientes SET departamento_codigo='05',municipio_codigo='05001'");
        try {
            $this->referencesMigration()->down();
            $this->fail('Must retain geographic associations.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Refusing to remove geographic associations.', $exception->getMessage());
        }
        $pdo->exec('UPDATE pacientes SET departamento_codigo=NULL,municipio_codigo=NULL');
        $this->referencesMigration()->down();
        foreach (['pacientes', 'eps'] as $table) {
            $this->assertSame($originalColumns[$table], $pdo->query('PRAGMA table_info("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC));
            $this->assertSame($originalIndexes[$table], $this->indexes($pdo, $table));
        }
        $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        try {
            $catalog->down();
            $this->fail('Must retain catalog data.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Refusing to delete an imported geographic catalog.', $exception->getMessage());
        }
    }
}
