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
        Schema::table('image_documents', function (Blueprint $table): void {
            $table->foreignId('merged_into_document_id')
                ->nullable()
                ->after('user_id')
                ->constrained('image_documents')
                ->nullOnDelete();
            $table->index('merged_into_document_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('image_documents', function (Blueprint $table): void {
            $table->dropForeign(['merged_into_document_id']);
            $table->dropIndex(['merged_into_document_id']);
            $table->dropColumn('merged_into_document_id');
        });
    }
};
