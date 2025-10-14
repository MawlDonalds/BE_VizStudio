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
        // Pastikan pgvector extension sudah diinstall
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        
        Schema::create('knowledge_base', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_datasource');
            $table->unsignedBigInteger('id_user');
            $table->string('entry_type', 50);
            $table->string('term', 255);
            $table->text('content');
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('id_datasource')->references('id_datasource')->on('datasources')->onDelete('cascade');
            $table->foreign('id_user')->references('id_user')->on('users')->onDelete('cascade');
            
            // Indexes
            $table->index(['id_datasource', 'entry_type']);
            $table->index('term');
        });
        
        // Tambahkan kolom vector setelah table dibuat (768 dimensions untuk paraphrase-mpnet-base-v2)
        DB::statement('ALTER TABLE knowledge_base ADD COLUMN embedding vector(768)');
        
        // Tambahkan index untuk similarity search
        DB::statement('CREATE INDEX ON knowledge_base USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_base');
    }
};