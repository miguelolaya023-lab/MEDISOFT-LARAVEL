<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DivipolaCatalogTest extends TestCase
{
    public function test_official_catalog_integrity_and_deterministic_normalization(): void
    {
        $directory = dirname(__DIR__, 2).'/resources/data/divipola/2024-12-30/';
        $manifest = json_decode(file_get_contents($directory.'manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $metadata = json_decode(file_get_contents($directory.'metadata.json'), true, flags: JSON_THROW_ON_ERROR);
        $rows = json_decode(file_get_contents($directory.'catalogo.json'), true, flags: JSON_THROW_ON_ERROR);
        $source = json_decode(file_get_contents($directory.'source.json'), true, flags: JSON_THROW_ON_ERROR);
        $columns = ['cod_dpto', 'dpto', 'cod_mpio', 'nom_mpio', 'tipo_municipio', 'longitud', 'latitud'];
        $this->assertSame('gdxc-w37w', $metadata['id']);
        $this->assertSame('CC_40_BY_SA', $metadata['licenseId']);
        $this->assertSame('2024-12-30', $manifest['data_cutoff']);
        $this->assertStringContainsString('30 diciembre 2024', $metadata['description']);
        $this->assertSame(1737751472, $metadata['rowsUpdatedAt']);
        $this->assertSame($columns, $manifest['columns']);
        $this->assertSame(1122, $manifest['remote_count']);
        $this->assertCount(1122, $source);
        $this->assertCount(1122, $rows);
        foreach ($manifest['sha256'] as $file => $checksum) {
            $this->assertSame($checksum, hash_file('sha256', $directory.$file), $file);
        }
        $departments = [];
        $codes = [];
        $types = [];
        foreach ($rows as $row) {
            $this->assertSame($columns, array_keys($row));
            foreach ($row as $value) {
                $this->assertIsString($value);
                $this->assertTrue(mb_check_encoding($value, 'UTF-8'));
                $this->assertStringNotContainsString("\u{FFFD}", $value);
            }
            $this->assertMatchesRegularExpression('/^[0-9]{2}$/D', $row['cod_dpto']);
            $this->assertMatchesRegularExpression('/^[0-9]{5}$/D', $row['cod_mpio']);
            $this->assertSame($row['cod_dpto'], substr($row['cod_mpio'], 0, 2));
            $this->assertNotSame('', trim($row['nom_mpio']));
            $this->assertNotSame('', trim($row['dpto']));
            $this->assertArrayNotHasKey($row['cod_mpio'], $codes);
            $codes[$row['cod_mpio']] = $row;
            if (isset($departments[$row['cod_dpto']])) {
                $this->assertSame($departments[$row['cod_dpto']], $row['dpto']);
            }
            $departments[$row['cod_dpto']] = $row['dpto'];
            $types[$row['tipo_municipio']] = ($types[$row['tipo_municipio']] ?? 0) + 1;
        }
        $this->assertCount(33, $departments);
        $expectedTypes = ['Municipio' => 1103, 'Isla' => 1, 'Área no municipalizada' => 18];
        $this->assertEquals($expectedTypes, $types);
        $this->assertEquals($expectedTypes, $manifest['territorial_types']);
        $this->assertSame('BOGOTÁ, D.C.', $codes['11001']['nom_mpio']);
        usort($source, fn (array $a, array $b): int => strcmp($a['cod_mpio'], $b['cod_mpio']));
        $normalized = array_map(function (array $row) use ($columns): array {
            $ordered = [];
            foreach ($columns as $column) {
                $ordered[$column] = $row[$column];
            }

            return $ordered;
        }, $source);
        $this->assertSame($rows, $normalized);
        $bytes = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $this->assertSame($manifest['sha256']['catalogo.json'], hash('sha256', $bytes));
        $this->assertSame(file_get_contents($directory.'catalogo.json'), $bytes);
    }
}
