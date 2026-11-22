<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported geographic catalog driver.');
        }
        Schema::create('departamentos', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $code = $table->char('codigo', 2)->primary();
            if (DB::getDriverName() === 'mysql') {
                $code->charset('ascii')->collation('ascii_bin');
            }
            $table->string('nombre');
        });
        Schema::create('municipios', function (Blueprint $table): void {
            $table->engine('InnoDB');
            foreach (['codigo' => 5, 'departamento_codigo' => 2] as $name => $length) {
                $code = $table->char($name, $length);
                if (DB::getDriverName() === 'mysql') {
                    $code->charset('ascii')->collation('ascii_bin');
                }
            }
            $table->primary('codigo');
            $table->string('nombre');
            $table->string('tipo_territorial', 50);
            $table->foreign('departamento_codigo')->references('codigo')->on('departamentos')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['departamento_codigo', 'codigo']);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            throw new RuntimeException('2026_11_10_050514_create_geographic_catalog_tables: MySQL is forward-only; reversal requires a controlled manual procedure.');
        } else {
            $temporary = DB::select("SELECT name FROM sqlite_temp_schema WHERE name NOT LIKE 'sqlite_%'");
            $objects = DB::select("SELECT type, tbl_name, sql FROM sqlite_schema WHERE type IN ('view', 'trigger')");
            if ($temporary !== []) {
                throw new RuntimeException('Temporary objects require review before catalog reversal.');
            }
            foreach ($objects as $object) {
                if (in_array($object->tbl_name, ['departamentos', 'municipios'], true) || preg_match('/\b(departamentos|municipios)\b/i', $object->sql ?? '')) {
                    throw new RuntimeException('Dependent object prevents catalog reversal.');
                }
            }
        }
        foreach (['pacientes', 'eps'] as $table) {
            if (Schema::hasColumn($table, 'departamento_codigo')) {
                throw new RuntimeException('Remove geographic references first.');
            }
        }
        foreach (['municipios', 'departamentos'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Refusing to delete an imported geographic catalog.');
            }
            foreach (Schema::getTables() as $existing) {
                foreach (Schema::getForeignKeys($existing['name']) as $foreign) {
                    if ($foreign['foreign_table'] === $table && $existing['name'] !== 'municipios') {
                        throw new RuntimeException('Unexpected geographic dependency.');
                    }
                }
            }
        }
        Schema::drop('municipios');
        Schema::drop('departamentos');
    }
};
