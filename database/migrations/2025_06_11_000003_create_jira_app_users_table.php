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
        Schema::create('jira_app_users', function (Blueprint $table) {
            $table->id();
            $table->string('jira_account_id')->unique(); // JIRA's account ID for the user
            $table->string('display_name');
            $table->string('email_address')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jira_app_users');
    }
};
