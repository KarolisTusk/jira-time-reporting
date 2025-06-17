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
        Schema::create('jira_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jira_project_id')->constrained('jira_projects')->cascadeOnDelete();
            $table->string('jira_id')->unique(); // JIRA's internal ID for the issue
            $table->string('issue_key')->unique(); // e.g., PROJ-123
            $table->text('summary');
            $table->string('status');
            $table->foreignId('assignee_jira_app_user_id')->nullable()->constrained('jira_app_users')->nullOnDelete();
            $table->integer('original_estimate_seconds')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jira_issues');
    }
};
