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
        Schema::table('jira_sync_histories', function (Blueprint $table) {
            $table->json('project_keys')->nullable()->after('sync_type');
            $table->json('metadata')->nullable()->after('error_details');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jira_sync_histories', function (Blueprint $table) {
            $table->dropColumn(['project_keys', 'metadata']);
        });
    }
};
