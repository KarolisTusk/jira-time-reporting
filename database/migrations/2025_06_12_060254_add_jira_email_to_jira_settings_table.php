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
        Schema::table('jira_settings', function (Blueprint $table) {
            $table->string('jira_email')->nullable()->after('jira_host');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jira_settings', function (Blueprint $table) {
            $table->dropColumn('jira_email');
        });
    }
};
