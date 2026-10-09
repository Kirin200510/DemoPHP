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
            $table->json('ocr_raw_data')->nullable()->after('processed_path');
            $table->json('ocr_structured_data')->nullable()->after('ocr_raw_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('image_documents', function (Blueprint $table) {
            $table->dropColumn([
                'ocr_raw_data',
                'ocr_structured_data',
            ]);
        });
    }
};
