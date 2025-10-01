<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            // Add UUID session_id for FastAPI compatibility
            $table->uuid('session_id')->nullable()->unique()->after('id_chat_session');
            
            // Add datasource_id for NL2SQL integration
            $table->unsignedBigInteger('datasource_id')->nullable()->after('title');
            
            // Add foreign key constraint
            $table->foreign('datasource_id')
                  ->references('id_datasource')
                  ->on('datasources')
                  ->onDelete('set null');
            
            // Add index for better performance
            $table->index(['user_id', 'session_id']);
            $table->index(['user_id', 'datasource_id']);
        });
        
        // Update existing records to have UUID session_id
        DB::table('chat_sessions')->whereNull('session_id')->update([
            'session_id' => DB::raw('gen_random_uuid()')
        ]);
        
        // Make session_id not nullable after populating existing records
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->uuid('session_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            // Drop foreign key constraint
            $table->dropForeign(['datasource_id']);
            
            // Drop indexes
            $table->dropIndex(['user_id', 'session_id']);
            $table->dropIndex(['user_id', 'datasource_id']);
            
            // Drop columns
            $table->dropColumn(['session_id', 'datasource_id']);
        });
    }
};