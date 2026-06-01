<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('livrables', function (Blueprint $table) {

            // Track when a livrable was soft-replaced (null = still active)
            if (!Schema::hasColumn('livrables', 'remplace_le')) {
                $table->timestamp('remplace_le')->nullable()->after('depose_le');
            }

            // Version counter — increments each time a new file replaces the old one
            if (!Schema::hasColumn('livrables', 'version')) {
                $table->unsignedSmallInteger('version')->default(1)->after('remplace_le');
            }

            // Original filename as uploaded (so we don't rely on parsing the stored path)
            if (!Schema::hasColumn('livrables', 'file_name')) {
                $table->string('file_name')->nullable()->after('fichier');
            }
        });

        // Back-fill version = 1 for all existing rows
        DB::table('livrables')->whereNull('version')->update(['version' => 1]);

        // Extend the statut enum to include 'remplace' and 'retire' if it is an ENUM column.
        // Safe to run even if the values already exist — MySQL ignores duplicate enum members
        // when using MODIFY COLUMN with the full list.
        $enumValues = DB::select("
            SELECT COLUMN_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'livrables'
              AND COLUMN_NAME  = 'statut'
        ");

        if (!empty($enumValues)) {
            $type = $enumValues[0]->COLUMN_TYPE ?? '';

            // Only patch if it's an ENUM and the new values are missing
            if (str_starts_with($type, 'enum') &&
                (!str_contains($type, "'remplace'") || !str_contains($type, "'retire'"))
            ) {
                DB::statement("
                    ALTER TABLE livrables
                    MODIFY COLUMN statut ENUM(
                        'en_attente',
                        'valide',
                        'rejete',
                        'remplace',
                        'retire'
                    ) NOT NULL DEFAULT 'en_attente'
                ");
            }
        }
    }

    public function down(): void
    {
        Schema::table('livrables', function (Blueprint $table) {
            $columns = ['remplace_le', 'version', 'file_name'];
            $existing = array_filter($columns, fn($c) => Schema::hasColumn('livrables', $c));
            if ($existing) {
                $table->dropColumn(array_values($existing));
            }
        });

        // Restore the original enum without the new values
        // Only run if the enum was actually modified
        DB::statement("
            ALTER TABLE livrables
            MODIFY COLUMN statut ENUM(
                'en_attente',
                'valide',
                'rejete'
            ) NOT NULL DEFAULT 'en_attente'
        ");
    }
};