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
        Schema::create('chat_history', function (Blueprint $table) {
            $table->id('id_history');
            $table->unsignedBigInteger('session_id');
            $table->jsonb('history');
            $table->string('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->string('modified_by')->nullable();
            $table->timestamp('modified_at')->nullable();

            $table->foreign('session_id')->references('id_chat_session')->on('chat_sessions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_history');
    }
};
