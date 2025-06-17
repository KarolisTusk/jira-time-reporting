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
        Schema::create('jira_worklogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jira_issue_id')->constrained('jira_issues')->cascadeOnDelete();
            $table->foreignId('jira_app_user_id')->constrained('jira_app_users')->cascadeOnDelete(); // Author of the worklog
            $table->string('jira_id')->unique(); // JIRA's internal ID for the worklog
            $table->integer('time_spent_seconds');
            $table->timestamp('started_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jira_worklogs');
    }
};
