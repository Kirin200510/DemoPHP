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
        if (Schema::hasColumn('image_documents', 'original_path')) {
            return;
        }

        Schema::table('image_documents', function (Blueprint $table) {
            $table->string('original_path')->after('id');
            $table->string('status')->default('uploaded')->after('original_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('image_documents', 'original_path')) {
            return;
        }

        Schema::table('image_documents', function (Blueprint $table) {
            $table->dropColumn([
                'original_path',
                'status',
            ]);
        });
    }
};
