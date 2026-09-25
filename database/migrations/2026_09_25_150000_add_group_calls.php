<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->string('scope', 10)->default('direct')->after('conversation_id');
            $table->unsignedBigInteger('callee_id')->nullable()->change();
        });

        Schema::create('call_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('call_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status', 20)->default('ringing');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['call_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->foreign('call_id')->references('id')->on('calls')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $calls = DB::table('calls')->get();
        foreach ($calls as $call) {
            DB::table('call_participants')->insertOrIgnore([
                [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'call_id' => $call->id,
                    'user_id' => $call->caller_id,
                    'status' => in_array($call->status, ['active', 'ended'], true) ? 'joined' : 'ringing',
                    'answered_at' => $call->answered_at,
                    'left_at' => $call->ended_at,
                    'created_at' => $call->created_at,
                    'updated_at' => $call->updated_at,
                ],
                [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'call_id' => $call->id,
                    'user_id' => $call->callee_id,
                    'status' => $call->status === 'rejected' ? 'rejected' : (in_array($call->status, ['active', 'ended'], true) ? 'joined' : 'ringing'),
                    'answered_at' => $call->answered_at,
                    'left_at' => $call->ended_at,
                    'created_at' => $call->created_at,
                    'updated_at' => $call->updated_at,
                ],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('call_participants');

        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
