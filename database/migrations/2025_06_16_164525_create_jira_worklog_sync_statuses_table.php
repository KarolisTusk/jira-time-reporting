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
        Schema::create('jira_worklog_sync_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('project_key', 50)->unique();
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_status', 50)->default('pending');
            $table->integer('worklogs_processed')->default(0);
            $table->integer('worklogs_added')->default(0);
            $table->integer('worklogs_updated')->default(0);
            $table->text('last_error')->nullable();
            $table->json('sync_metadata')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index('project_key');
            $table->index('last_sync_at');
            $table->index('last_sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jira_worklog_sync_statuses');
    }
};
