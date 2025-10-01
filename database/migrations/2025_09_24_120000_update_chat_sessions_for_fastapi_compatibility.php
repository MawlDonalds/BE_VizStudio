<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Update chat_sessions table to use UUID session_id for FastAPI compatibility
     */
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            // Add UUID session_id column if not exists
            if (!Schema::hasColumn('chat_sessions', 'session_id')) {
                $table->uuid('session_id')->unique()->after('id_chat_session');
            }
            
            // Add datasource_id if not exists (for NL2SQL integration)
            if (!Schema::hasColumn('chat_sessions', 'datasource_id')) {
                $table->unsignedBigInteger('datasource_id')->nullable()->after('title');
                $table->foreign('datasource_id')->references('id_datasource')->on('datasources')->onDelete('set null');
            }
            
            // Add index on session_id for performance
            $table->index('session_id');
            // $table->index(['user_id', 'session_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropForeign(['datasource_id']);
            $table->dropIndex(['chat_sessions_session_id_index']);
            $table->dropIndex(['chat_sessions_user_id_session_id_index']);
            $table->dropColumn(['session_id', 'datasource_id']);
        });
    }
};