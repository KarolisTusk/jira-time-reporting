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
        Schema::table('jira_issues', function (Blueprint $table) {
            $table->json('labels')->nullable()->after('status');
            $table->string('epic_key')->nullable()->after('labels');
            
            // Add indexes for performance
            $table->index('epic_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jira_issues', function (Blueprint $table) {
            $table->dropIndex(['epic_key']);
            $table->dropColumn(['labels', 'epic_key']);
        });
    }
};
