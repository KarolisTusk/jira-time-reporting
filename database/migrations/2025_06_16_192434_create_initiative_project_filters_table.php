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
        Schema::create('initiative_project_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('initiative_id')->constrained('initiatives')->cascadeOnDelete();
            $table->foreignId('jira_project_id')->constrained('jira_projects')->cascadeOnDelete();
            $table->json('required_labels')->nullable(); // Labels that must be present
            $table->string('epic_key')->nullable(); // Specific epic key
            $table->timestamps();
            
            // Add indexes for performance
            $table->index(['initiative_id', 'jira_project_id']);
            $table->index('epic_key');
            
            // Prevent duplicate filter combinations
            $table->unique(['initiative_id', 'jira_project_id', 'epic_key'], 'unique_initiative_project_epic');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('initiative_project_filters');
    }
};
