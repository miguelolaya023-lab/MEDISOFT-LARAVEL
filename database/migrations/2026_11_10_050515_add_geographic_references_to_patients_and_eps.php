<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const COLUMNS = [
        'pacientes' => ['id', 'tipo_documento', 'numero_documento', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono', 'correo_electronico', 'direccion', 'ciudad', 'tipo_paciente', 'estado', 'created_at', 'updated_at', 'motivo_inactivacion'],
        'eps' => ['id', 'nit', 'nombre', 'telefono', 'correo_electronico', 'direccion', 'ciudad', 'estado', 'created_at', 'updated_at'],
    ];

    private function additions(string $table): string
    {
        return ', departamento_codigo CHAR(2) NULL, municipio_codigo CHAR(5) NULL, CONSTRAINT '.$table.'_departamento_geo_fk FOREIGN KEY (departamento_codigo) REFERENCES departamentos(codigo) ON DELETE RESTRICT ON UPDATE RESTRICT, CONSTRAINT '.$table.'_municipio_geo_fk FOREIGN KEY (departamento_codigo, municipio_codigo) REFERENCES municipios(departamento_codigo, codigo) ON DELETE RESTRICT ON UPDATE RESTRICT, CONSTRAINT '.$table.'_geo_pair_check CHECK ((departamento_codigo IS NULL AND municipio_codigo IS NULL) OR (departamento_codigo IS NOT NULL AND municipio_codigo IS NOT NULL))';
    }

    public function up(): void
    {
        $this->change(false);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            throw new RuntimeException('2026_11_10_050515_add_geographic_references_to_patients_and_eps: MySQL is forward-only; reversal requires a controlled manual procedure.');
        }
        $this->change(true);
    }

    private function change(bool $reverse): void
    {
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw new RuntimeException('Geographic reconstruction requires no active transaction.');
        }
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Unsupported geographic driver.');
        }
        foreach (self::COLUMNS as $table => $columns) {
            $expected = $reverse ? [...$columns, 'departamento_codigo', 'municipio_codigo'] : $columns;
            if (Schema::getColumnListing($table) !== $expected) {
                throw new RuntimeException('Unexpected schema for '.$table.'; inspect before retrying.');
            }
            if ($reverse && DB::table($table)->whereNotNull('departamento_codigo')->orWhereNotNull('municipio_codigo')->exists()) {
                throw new RuntimeException('Refusing to remove geographic associations.');
            }
        }
        if (DB::getDriverName() === 'sqlite') {
            $this->sqlite($reverse);
        } else {
            $this->mysql();
        }
    }

    private function mysql(): void
    {
        $version = DB::selectOne('SELECT VERSION() AS version')->version;
        if (! preg_match('/^8\./', $version) || version_compare(preg_replace('/[^0-9.].*$/', '', $version), '8.0.16', '<')) {
            throw new RuntimeException('MySQL 8.0.16 or later is required for enforced CHECK constraints.');
        }
        foreach (['pacientes', 'eps', 'departamentos', 'municipios'] as $table) {
            $status = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            if (strtolower($status->engine ?? '') !== 'innodb') {
                throw new RuntimeException('InnoDB required for '.$table);
            }
        }
        foreach (['departamentos' => ['codigo' => 'char(2)'], 'municipios' => ['codigo' => 'char(5)', 'departamento_codigo' => 'char(2)']] as $table => $columns) {
            foreach ($columns as $column => $type) {
                $definition = DB::selectOne('SELECT COLUMN_TYPE AS type, CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
                if (($definition->type ?? '') !== $type || $definition->charset !== 'ascii' || $definition->collation !== 'ascii_bin') {
                    throw new RuntimeException('Incompatible geographic key type or collation.');
                }
            }
        }
        // MySQL DDL is not transactionally reversible across both tables.
        foreach (array_keys(self::COLUMNS) as $table) {
            $sql = str_replace('CHAR(2) NULL', 'CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NULL', $this->additions($table));
            $sql = str_replace('CHAR(5) NULL', 'CHAR(5) CHARACTER SET ascii COLLATE ascii_bin NULL', $sql);
            $clauses = preg_split('/, (?=(?:departamento_codigo|municipio_codigo|CONSTRAINT) )/', ltrim($sql, ', '));
            DB::statement('ALTER TABLE '.$table.' ADD '.implode(', ADD ', $clauses));
        }
    }

    private function sqlite(bool $reverse): void
    {
        if (DB::select("SELECT name FROM sqlite_temp_schema WHERE name NOT LIKE 'sqlite_%'") !== []) {
            throw new RuntimeException('Temporary SQLite objects require explicit preservation review.');
        }
        $pdo = DB::connection()->getPdo();
        if ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new RuntimeException('SQLite foreign keys must be enabled before reconstruction.');
        }
        $objects = $pdo->query("SELECT type, name, tbl_name, sql FROM sqlite_schema WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
        $sequences = $pdo->query('SELECT name, seq FROM sqlite_sequence')->fetchAll(PDO::FETCH_KEY_PAIR);
        $definitions = [];
        foreach ($objects as $object) {
            if ($reverse && ! ($object['type'] === 'table' && isset(self::COLUMNS[$object['name']])) && preg_match('/\b(departamento_codigo|municipio_codigo)\b/i', $object['sql'] ?? '') && preg_match('/\b(pacientes|eps)\b/i', $object['sql'] ?? '')) {
                throw new RuntimeException('Dependent object uses geographic columns.');
            }
            if ($object['type'] === 'trigger' && ! isset(self::COLUMNS[$object['tbl_name']])) {
                throw new RuntimeException('External triggers require an explicit preservation review.');
            }
            if ($object['type'] === 'table' && isset(self::COLUMNS[$object['name']])) {
                $table = $object['name'];
                $sql = $object['sql'];
                $physical = $pdo->query('PRAGMA table_info("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC);
                foreach ($physical as $column) {
                    $type = match ($column['name']) {
                        'id' => 'integer',
                        'fecha_nacimiento' => 'date',
                        'created_at', 'updated_at' => 'datetime',
                        'departamento_codigo' => 'char(2)',
                        'municipio_codigo' => 'char(5)',
                        default => 'varchar',
                    };
                    $nullable = in_array($column['name'], ['correo_electronico', 'motivo_inactivacion', 'created_at', 'updated_at', 'departamento_codigo', 'municipio_codigo'], true);
                    if ($table === 'eps' && $column['name'] === 'correo_electronico') {
                        $nullable = false;
                    }
                    $default = $table === 'pacientes' && $column['name'] === 'estado' ? "'ACTIVO'" : null;
                    if ($column['dflt_value'] !== $default) {
                        throw new RuntimeException('Unexpected physical default for '.$table.'.'.$column['name']);
                    }
                    if (strtolower($column['type']) !== $type || (bool) $column['notnull'] === $nullable || (int) $column['pk'] !== ($column['name'] === 'id' ? 1 : 0)) {
                        throw new RuntimeException('Unexpected physical column for '.$table.'.'.$column['name']);
                    }
                }
                $checks = $table === 'pacientes' ? 2 : 1;
                if (preg_match_all('/\bCHECK\s*\(/i', $sql) !== $checks + ($reverse ? 1 : 0) || ! str_contains(strtolower($sql), 'autoincrement') || ! str_ends_with($sql, ')')) {
                    throw new RuntimeException('Unexpected physical constraints for '.$table);
                }
                if ($reverse) {
                    $suffix = $this->additions($table).')';
                    if (! str_ends_with($sql, $suffix)) {
                        throw new RuntimeException('Unrecognized geographic definition.');
                    }
                    $sql = substr($sql, 0, -strlen($suffix)).')';
                } else {
                    $sql = substr($sql, 0, -1).$this->additions($table).')';
                }
                $sql = preg_replace('/^CREATE TABLE ["`]?'.$table.'["`]?/i', 'CREATE TABLE "__geo_'.$table.'"', $sql, 1, $count);
                if ($count !== 1 || Schema::hasTable('__geo_'.$table)) {
                    throw new RuntimeException('Cannot safely create geographic temporary table.');
                }
                $definitions[$table] = $sql;
            }
        }
        if (count($definitions) !== 2 || $pdo->query('PRAGMA foreign_key_check')->fetchAll() !== [] || $pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('Original SQLite schema failed integrity checks.');
        }
        $pdo->exec('PRAGMA foreign_keys=OFF');
        try {
            if ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 0) {
                throw new RuntimeException('Could not disable SQLite foreign keys outside transaction.');
            }
            DB::transaction(function () use ($objects, $sequences, $definitions, $pdo): void {
                foreach ($objects as $object) {
                    if ($object['type'] === 'view') {
                        DB::statement('DROP VIEW '.$this->quote($object['name']));
                    }
                }
                foreach ($definitions as $table => $sql) {
                    DB::statement($sql);
                    $columns = self::COLUMNS[$table];
                    $projection = implode(',', array_map($this->quote(...), $columns));
                    DB::statement('INSERT INTO "__geo_'.$table.'" ('.$projection.') SELECT '.$projection.' FROM "'.$table.'"');
                    DB::statement('DROP TABLE "'.$table.'"');
                    DB::statement('ALTER TABLE "__geo_'.$table.'" RENAME TO "'.$table.'"');
                    foreach ($objects as $object) {
                        if ($object['tbl_name'] === $table && in_array($object['type'], ['index', 'trigger'], true) && $object['sql'] !== null) {
                            DB::statement($object['sql']);
                        }
                    }
                    if (isset($sequences[$table])) {
                        DB::update('UPDATE sqlite_sequence SET seq=? WHERE name=?', [$sequences[$table], $table]);
                    }
                }
                foreach ($objects as $object) {
                    if ($object['type'] === 'view') {
                        DB::statement($object['sql']);
                        DB::select('SELECT * FROM '.$this->quote($object['name']).' LIMIT 0');
                    }
                }
                if ($pdo->query('PRAGMA foreign_key_check')->fetchAll() !== [] || $pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                    throw new RuntimeException('Rebuilt SQLite database failed integrity checks.');
                }
            });
        } finally {
            $pdo->exec('PRAGMA foreign_keys=ON');
            if ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
                throw new RuntimeException('Could not restore SQLite foreign keys.');
            }
        }
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
