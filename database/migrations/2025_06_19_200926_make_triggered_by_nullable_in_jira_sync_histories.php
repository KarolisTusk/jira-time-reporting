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
            // Drop the foreign key constraint first
            $table->dropForeign(['triggered_by']);
            
            // Modify the column to be nullable
            $table->foreignId('triggered_by')->nullable()->change();
            
            // Re-add the foreign key constraint with nullable support
            $table->foreign('triggered_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jira_sync_histories', function (Blueprint $table) {
            // Drop the nullable foreign key
            $table->dropForeign(['triggered_by']);
            
            // Restore the non-nullable constraint
            $table->foreignId('triggered_by')->change();
            
            // Re-add the original foreign key constraint
            $table->foreign('triggered_by')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
