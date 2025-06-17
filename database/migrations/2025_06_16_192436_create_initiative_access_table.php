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
        Schema::create('initiative_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('initiative_id')->constrained('initiatives')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('access_type', ['read', 'admin'])->default('read');
            $table->timestamps();
            
            // Add indexes for performance
            $table->index(['user_id', 'access_type']);
            $table->index('initiative_id');
            
            // Prevent duplicate access records
            $table->unique(['initiative_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('initiative_access');
    }
};
