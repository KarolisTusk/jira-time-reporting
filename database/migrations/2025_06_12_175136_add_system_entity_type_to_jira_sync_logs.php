<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For SQLite, we need to recreate the table to modify enum constraints
        if (Schema::hasTable('jira_sync_logs_backup')) {
            Schema::drop('jira_sync_logs_backup');
        }
        
        // Create backup of existing data
        DB::statement('CREATE TABLE jira_sync_logs_backup AS SELECT * FROM jira_sync_logs');
        
        // Drop the original table
        Schema::drop('jira_sync_logs');
        
        // Recreate with updated enum
        Schema::create('jira_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jira_sync_history_id')->constrained('jira_sync_histories')->onDelete('cascade');
            $table->timestamp('timestamp');
            $table->enum('level', ['info', 'warning', 'error'])->default('info');
            $table->text('message');
            $table->json('context')->nullable();
            $table->enum('entity_type', ['project', 'issue', 'worklog', 'user', 'system'])->nullable();
            $table->string('entity_id')->nullable();
            $table->enum('operation', ['fetch', 'create', 'update'])->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index('jira_sync_history_id');
            $table->index('level');
            $table->index('entity_type');
            $table->index('timestamp');
        });
        
        // Restore data
        DB::statement('INSERT INTO jira_sync_logs SELECT * FROM jira_sync_logs_backup');
        
        // Clean up backup
        Schema::drop('jira_sync_logs_backup');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jira_sync_logs', function (Blueprint $table) {
            $table->dropColumn('entity_type');
        });
        
        Schema::table('jira_sync_logs', function (Blueprint $table) {
            $table->enum('entity_type', ['project', 'issue', 'worklog', 'user'])->nullable()->after('context');
            $table->index('entity_type');
        });
    }
};
