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
        Schema::table('jira_sync_histories', function (Blueprint $table) {
            $table->integer('progress_percentage')->default(0)->after('status');
            $table->string('current_operation')->nullable()->after('progress_percentage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jira_sync_histories', function (Blueprint $table) {
            $table->dropColumn(['progress_percentage', 'current_operation']);
        });
    }
};
