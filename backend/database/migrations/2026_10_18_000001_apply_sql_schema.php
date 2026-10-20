<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applies the hand-written MySQL schema in database/sql (tables, triggers, views, seed data).
 * The SQL files are the source of truth: they use generated columns, composite FKs and
 * append-only triggers that the schema builder cannot express.
 */
return new class extends Migration
{
    public function up(): void
    {
        $files = glob(database_path('sql/*.sql'));
        sort($files);

        foreach ($files as $file) {
            foreach ($this->statements(file_get_contents($file)) as $statement) {
                DB::unprepared($statement);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('The base schema is not reversible. Use migrate:fresh on a development database.');
    }

    /**
     * @return list<string>
     */
    private function statements(string $sql): array
    {
        $sql = preg_replace('/--[^\r\n]*/', '', $sql);
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql);

        return array_values(array_filter(array_map('trim', $parts), fn (string $part): bool => $part !== ''));
    }
};
