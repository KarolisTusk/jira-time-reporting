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
        Schema::create('jira_sync_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jira_sync_history_id')->constrained('jira_sync_histories')->onDelete('cascade');
            $table->string('project_key');
            $table->string('checkpoint_type')->default('project_sync'); // project_sync, recovery, etc.
            $table->json('checkpoint_data')->nullable();
            $table->string('status')->default('active'); // active, completed, failed
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index(['jira_sync_history_id', 'project_key']);
            $table->index(['status', 'created_at']);
            $table->index('checkpoint_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jira_sync_checkpoints');
    }
};
