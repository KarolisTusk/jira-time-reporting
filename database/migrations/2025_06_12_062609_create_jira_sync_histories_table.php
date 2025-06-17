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
        Schema::create('jira_sync_histories', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed'])->default('pending');

            // Progress tracking fields
            $table->integer('total_projects')->default(0);
            $table->integer('processed_projects')->default(0);
            $table->integer('total_issues')->default(0);
            $table->integer('processed_issues')->default(0);
            $table->integer('total_worklogs')->default(0);
            $table->integer('processed_worklogs')->default(0);
            $table->integer('total_users')->default(0);
            $table->integer('processed_users')->default(0);

            // Error tracking
            $table->integer('error_count')->default(0);
            $table->json('error_details')->nullable();

            // Metadata
            $table->integer('duration_seconds')->nullable();
            $table->foreignId('triggered_by')->constrained('users')->onDelete('cascade');
            $table->enum('sync_type', ['manual', 'scheduled'])->default('manual');

            $table->timestamps();

            // Indexes for performance
            $table->index('status');
            $table->index('started_at');
            $table->index('triggered_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jira_sync_histories');
    }
};
