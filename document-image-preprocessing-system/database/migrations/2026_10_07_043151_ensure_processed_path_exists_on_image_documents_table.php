<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('image_documents', 'processed_path')) {
            return;
        }

        Schema::table('image_documents', function (Blueprint $table) {
            $table->string('processed_path')->nullable()->after('original_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This repair migration must not remove a column that may predate it.
    }
};
