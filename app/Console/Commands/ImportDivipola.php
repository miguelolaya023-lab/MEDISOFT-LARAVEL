<?php

namespace App\Console\Commands;

use ErrorException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;

class ImportDivipola extends Command
{
    protected $signature = 'divipola:import';

    protected $description = 'Importa explícitamente la copia local DIVIPOLA 2024-12-30 sin sobrescribir registros';

    private const COLUMNS = ['cod_dpto', 'dpto', 'cod_mpio', 'nom_mpio', 'tipo_municipio', 'longitud', 'latitud'];

    private const TYPES = ['Municipio' => 1103, 'Isla' => 1, 'Área no municipalizada' => 18];

    public function handle(): int
    {
        $discrepancies = 0;
        try {
            [$departments, $municipalities] = $this->catalog();
            if (! Schema::hasTable('departamentos') || ! Schema::hasTable('municipios')) {
                throw new RuntimeException('Faltan tablas geográficas; requiere aplicar las migraciones previamente revisadas. No se ejecutaron migraciones.');
            }
            $counts = DB::transaction(function () use ($departments, $municipalities, &$discrepancies): array {
                $counts = [];
                foreach (['departamentos' => $departments, 'municipios' => $municipalities] as $table => $records) {
                    $existing = DB::table($table)->get()->keyBy('codigo');
                    $inserted = 0;
                    $matched = 0;
                    foreach ($records as $record) {
                        $current = $existing->get($record['codigo']);
                        if ($current !== null) {
                            foreach ($record as $column => $value) {
                                if ($current->{$column} !== $value) {
                                    $discrepancies++;
                                    throw new RuntimeException('Discrepancia en '.$table.' código '.$record['codigo'].': revisión manual requerida; no se sobrescribe.');
                                }
                            }
                            $matched++;
                        } else {
                            DB::table($table)->insert($record);
                            $inserted++;
                        }
                    }
                    $counts[$table] = ['insertados' => $inserted, 'existentes' => $matched, 'total' => DB::table($table)->count()];
                }

                return $counts;
            });
            foreach ($counts as $table => $count) {
                $this->info($table.': insertados='.$count['insertados'].' existentes='.$count['existentes'].' total='.$count['total']);
            }
            $this->info('Discrepancias=0');

            return self::SUCCESS;
        } catch (QueryException) {
            $this->error('Error de base de datos; importación revertida. Sin inserciones confirmadas ni sobrescrituras.');
        } catch (JsonException) {
            $this->error('JSON DIVIPOLA inválido; importación rechazada sin escrituras.');
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
        }
        $this->error('Inserciones confirmadas=0; discrepancias detectadas='.$discrepancies);

        return self::FAILURE;
    }

    /** @return array{0: list<array{codigo: string, nombre: string}>, 1: list<array{codigo: string, departamento_codigo: string, nombre: string, tipo_territorial: string}>} */
    private function catalog(): array
    {
        $directory = resource_path('data/divipola/2024-12-30/');
        $manifest = json_decode($this->read($directory.'manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ($manifest['dataset'] ?? null) !== 'gdxc-w37w' || ($manifest['edition'] ?? null) !== '2024-12-30' || ($manifest['data_cutoff'] ?? null) !== '2024-12-30' || ($manifest['license_id'] ?? null) !== 'CC_40_BY_SA' || ($manifest['records'] ?? null) !== 1122 || ($manifest['department_codes'] ?? null) !== 33 || ! is_array($manifest['sha256'] ?? null) || ($manifest['columns'] ?? null) !== self::COLUMNS || ($manifest['territorial_types'] ?? null) !== self::TYPES) {
            throw new RuntimeException('Manifiesto DIVIPOLA incompatible con la edición fijada.');
        }
        $files = [];
        foreach (['source.json', 'metadata.json', 'catalogo.json'] as $file) {
            $bytes = $this->read($directory.$file);
            $checksum = $manifest['sha256'][$file] ?? null;
            if (! is_string($checksum) || ! hash_equals($checksum, hash('sha256', $bytes))) {
                throw new RuntimeException('SHA-256 incorrecto: '.$file.'.');
            }
            $files[$file] = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        }
        if (($files['metadata.json']['id'] ?? null) !== 'gdxc-w37w' || ($files['metadata.json']['licenseId'] ?? null) !== 'CC_40_BY_SA' || ! is_array($files['catalogo.json']) || ! array_is_list($files['catalogo.json']) || count($files['catalogo.json']) !== 1122) {
            throw new RuntimeException('Estructura o procedencia DIVIPOLA incompatible.');
        }
        $departments = [];
        $municipalities = [];
        $types = [];
        foreach ($files['catalogo.json'] as $row) {
            if (! is_array($row) || array_keys($row) !== self::COLUMNS) {
                throw new RuntimeException('Columnas DIVIPOLA inválidas.');
            }
            foreach ($row as $value) {
                if (! is_string($value) || trim($value) === '' || ! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\u{FFFD}")) {
                    throw new RuntimeException('Valores DIVIPOLA inválidos.');
                }
            }
            if (! preg_match('/^[0-9]{2}$/D', $row['cod_dpto']) || ! preg_match('/^[0-9]{5}$/D', $row['cod_mpio']) || substr($row['cod_mpio'], 0, 2) !== $row['cod_dpto'] || isset($municipalities[$row['cod_mpio']]) || ! isset(self::TYPES[$row['tipo_municipio']]) || (isset($departments[$row['cod_dpto']]) && $departments[$row['cod_dpto']]['nombre'] !== $row['dpto']) || mb_strlen($row['dpto']) > 255 || mb_strlen($row['nom_mpio']) > 255) {
                throw new RuntimeException('Códigos, pertenencia o tipos DIVIPOLA inválidos.');
            }
            $departments[$row['cod_dpto']] = ['codigo' => $row['cod_dpto'], 'nombre' => $row['dpto']];
            $municipalities[$row['cod_mpio']] = ['codigo' => $row['cod_mpio'], 'departamento_codigo' => $row['cod_dpto'], 'nombre' => $row['nom_mpio'], 'tipo_territorial' => $row['tipo_municipio']];
            $types[$row['tipo_municipio']] = ($types[$row['tipo_municipio']] ?? 0) + 1;
        }
        if (count($departments) !== 33 || $types != self::TYPES) {
            throw new RuntimeException('Conteos DIVIPOLA inválidos.');
        }
        $source = $files['source.json'];
        if (! is_array($source) || ! array_is_list($source) || count($source) !== 1122) {
            throw new RuntimeException('Fuente DIVIPOLA incompleta.');
        }
        foreach ($source as $row) {
            if (! is_array($row) || array_diff(self::COLUMNS, array_keys($row)) !== [] || ! is_string($row['cod_mpio'] ?? null)) {
                throw new RuntimeException('Fuente DIVIPOLA inválida.');
            }
        }
        usort($source, fn (array $a, array $b): int => strcmp($a['cod_mpio'], $b['cod_mpio']));
        $normalized = array_map(fn (array $row): array => array_replace(array_fill_keys(self::COLUMNS, null), array_intersect_key($row, array_flip(self::COLUMNS))), $source);
        if ($normalized !== $files['catalogo.json']) {
            throw new RuntimeException('La copia normalizada difiere de la fuente DIVIPOLA.');
        }

        return [array_values($departments), array_values($municipalities)];
    }

    private function read(string $path): string
    {
        if (! File::isFile($path) || ! File::isReadable($path)) {
            throw new RuntimeException('Archivo DIVIPOLA local ausente o ilegible: '.basename($path).'.');
        }
        try {
            $contents = File::get($path);
        } catch (FileNotFoundException|ErrorException $exception) {
            throw new RuntimeException('No se pudo leer la copia DIVIPOLA local.', previous: $exception);
        }
        if ($contents === false) {
            throw new RuntimeException('No se pudo leer la copia DIVIPOLA local.');
        }

        return $contents;
    }
}
