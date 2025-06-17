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
        Schema::table('jira_worklogs', function (Blueprint $table) {
            $table->string('resource_type')->default('development')->after('started_at');
            
            // Add index for resource type filtering
            $table->index('resource_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jira_worklogs', function (Blueprint $table) {
            $table->dropIndex(['resource_type']);
            $table->dropColumn('resource_type');
        });
    }
};
