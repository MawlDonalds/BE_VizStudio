<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Create complete chat_sessions table with FastAPI compatibility from the start
     */
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            // Primary key
            $table->id('id_chat_session');
            
            // UUID for FastAPI compatibility - required from the start
            $table->uuid('session_id')->unique();
            
            // Basic session information
            $table->unsignedBigInteger('user_id');
            $table->string('title', 255);
            
            // NL2SQL integration - link to datasources
            $table->unsignedBigInteger('datasource_id')->nullable();
            
            // Audit fields
            $table->string('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->string('modified_by')->nullable();
            $table->timestamp('modified_at')->nullable();

            // Foreign key constraints
            $table->foreign('user_id')
                  ->references('id_user')
                  ->on('users')
                  ->onDelete('cascade');
                  
            $table->foreign('datasource_id')
                  ->references('id_datasource')
                  ->on('datasources')
                  ->onDelete('set null');
            
            // Performance indexes
            $table->index('session_id');
            $table->index(['user_id', 'session_id']);
            $table->index(['user_id', 'datasource_id']);
            $table->index('created_at'); // For ordering sessions
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};