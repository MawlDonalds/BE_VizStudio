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
        Schema::create('charts', function (Blueprint $table) {
            $table->id('id_chart');
            $table->unsignedBigInteger('id_canvas');
            $table->unsignedBigInteger('id_datasources');
            $table->string('name');
            $table->string('chart_type');
            $table->string('query');
            $table->json('config');
            $table->integer('width');
            $table->integer('height');
            $table->integer('position_x');
            $table->integer('position_y');
            $table->string('created_by')->nullable();
            $table->timestamp('created_time')->nullable();
            $table->string('modified_by')->nullable();
            $table->timestamp('modified_time')->nullable();
            $table->boolean('is_deleted')->default(false);

            $table->foreign('id_canvas')->references('id_canvas')->on('canvas');
            $table->foreign('id_datasources')->references('id_datasources')->on('datasources');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charts');
    }
};
