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
        Schema::create('jira_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jira_sync_history_id')->constrained('jira_sync_histories')->onDelete('cascade');
            $table->timestamp('timestamp');
            $table->enum('level', ['info', 'warning', 'error'])->default('info');
            $table->text('message');
            $table->json('context')->nullable();
            $table->enum('entity_type', ['project', 'issue', 'worklog', 'user'])->nullable();
            $table->string('entity_id')->nullable();
            $table->enum('operation', ['fetch', 'create', 'update'])->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index('jira_sync_history_id');
            $table->index('level');
            $table->index('entity_type');
            $table->index('timestamp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jira_sync_logs');
    }
};
