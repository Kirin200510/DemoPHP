<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $missingColumns = [];

        if (! Schema::hasColumn('image_documents', 'storage_encrypted')) {
            $missingColumns[] = static function (Blueprint $table): void {
                $table->boolean('storage_encrypted')->default(false);
            };
        }

        if (! Schema::hasColumn('image_documents', 'expires_at')) {
            $missingColumns[] = static function (Blueprint $table): void {
                $table->timestamp('expires_at')->nullable();
            };
        }

        if (! Schema::hasColumn('image_documents', 'purged_at')) {
            $missingColumns[] = static function (Blueprint $table): void {
                $table->timestamp('purged_at')->nullable();
            };
        }

        if ($missingColumns !== []) {
            Schema::table('image_documents', function (Blueprint $table) use ($missingColumns): void {
                foreach ($missingColumns as $addColumn) {
                    $addColumn($table);
                }
            });
        }
    }

    /**
     * This repair migration intentionally has no rollback. The repaired
     * columns may have been created by an earlier migration.
     */
    public function down(): void {}
};
