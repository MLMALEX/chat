<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->unsignedBigInteger('caller_id');
            $table->unsignedBigInteger('callee_id');
            $table->string('room_name', 64);
            $table->string('type', 10)->default('audio');
            $table->string('status', 20)->default('ringing');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['caller_id', 'status']);
            $table->index(['callee_id', 'status']);
            $table->foreign('caller_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('callee_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
