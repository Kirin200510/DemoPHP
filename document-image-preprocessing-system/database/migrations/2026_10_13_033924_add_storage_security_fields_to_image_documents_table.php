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
            $table->boolean('storage_encrypted')->default(false)->after('processed_path');
            $table->timestamp('expires_at')->nullable()->after('review_notes');
            $table->timestamp('purged_at')->nullable()->after('expires_at');
            $table->index(['expires_at', 'purged_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('image_documents', function (Blueprint $table): void {
            $table->dropIndex(['expires_at', 'purged_at']);
            $table->dropColumn(['storage_encrypted', 'expires_at', 'purged_at']);
        });
    }
};
