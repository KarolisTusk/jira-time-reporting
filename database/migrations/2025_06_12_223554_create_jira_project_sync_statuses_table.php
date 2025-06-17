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
        Schema::create('jira_project_sync_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('project_key')->unique();
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_status')->default('pending'); // pending, in_progress, completed, failed
            $table->integer('issues_count')->default(0);
            $table->text('last_error')->nullable();
            $table->json('sync_metadata')->nullable(); // Additional metadata like date ranges, filters used
            $table->timestamps();

            // Indexes for performance
            $table->index('project_key');
            $table->index(['last_sync_status', 'last_sync_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jira_project_sync_statuses');
    }
};
