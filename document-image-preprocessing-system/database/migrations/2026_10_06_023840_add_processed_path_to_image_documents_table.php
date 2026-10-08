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
        Schema::table('image_documents', function (Blueprint $table) {
            $table->string('processed_path')->nullable()->after('original_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('image_documents', function (Blueprint $table) {
            $table->dropColumn('processed_path');
        });
    }
};
