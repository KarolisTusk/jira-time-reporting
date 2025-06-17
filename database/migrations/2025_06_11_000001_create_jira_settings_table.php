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
        Schema::create('jira_settings', function (Blueprint $table) {
            $table->id();
            $table->string('jira_host'); // JIRA instance host (e.g., yourcompany.atlassian.net)
            $table->text('api_token'); // Encrypted JIRA API Token
            $table->json('project_keys')->nullable(); // Array of JIRA project keys
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jira_settings');
    }
};
