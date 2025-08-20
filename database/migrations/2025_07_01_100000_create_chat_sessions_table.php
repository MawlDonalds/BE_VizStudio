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
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id('id_chat_session');
            $table->unsignedBigInteger('user_id');
            $table->string('title', 255);
            $table->string('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->string('modified_by')->nullable();
            $table->timestamp('modified_at')->nullable();

            $table->foreign('user_id')->references('id_user')->on('users');
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
