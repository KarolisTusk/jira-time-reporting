<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only apply PostgreSQL-specific constraints in production
        if (DB::getDriverName() === 'pgsql') {
            // Drop and recreate the constraint to include the new value
            DB::statement('ALTER TABLE jira_sync_histories DROP CONSTRAINT IF EXISTS jira_sync_histories_sync_type_check');
            DB::statement("ALTER TABLE jira_sync_histories ADD CONSTRAINT jira_sync_histories_sync_type_check CHECK (sync_type IN ('manual', 'scheduled', 'worklog_incremental'))");
        }
        // SQLite doesn't support named CHECK constraints, but Laravel validation handles this
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only apply PostgreSQL-specific constraints in production
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE jira_sync_histories DROP CONSTRAINT IF EXISTS jira_sync_histories_sync_type_check');
            DB::statement("ALTER TABLE jira_sync_histories ADD CONSTRAINT jira_sync_histories_sync_type_check CHECK (sync_type IN ('manual', 'scheduled'))");
        }
        // SQLite doesn't support named CHECK constraints, but Laravel validation handles this
    }
};
