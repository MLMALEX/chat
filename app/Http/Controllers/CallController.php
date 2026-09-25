<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CallController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => ['required', 'uuid'],
            'type' => ['required', 'in:audio,video'],
        ]);

        $userId = (int) $request->user()->id;

        $conversation = DB::table('ch_conversations')
            ->where('id', $data['conversation_id'])
            ->where('type', 'direct')
            ->first();

        abort_unless($conversation, 422, 'Calls are currently available for direct conversations only.');

        $participants = DB::table('ch_conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);

        abort_unless($participants->contains($userId), 403);

        $calleeId = $participants->first(fn ($id) => $id !== $userId);
        abort_unless($calleeId, 422, 'The other participant was not found.');

        $callId = (string) Str::uuid();
        $roomName = 'call_'.Str::uuid()->toString();
        $now = now();

        DB::table('calls')->insert([
            'id' => $callId,
            'conversation_id' => $conversation->id,
            'caller_id' => $userId,
            'callee_id' => $calleeId,
            'room_name' => $roomName,
            'type' => $data['type'],
            'status' => 'ringing',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $callee = DB::table('users')->where('id', $calleeId)->first(['id', 'name']);

        $this->signal($calleeId, 'call.incoming', [
            'call_id' => $callId,
            'conversation_id' => $conversation->id,
            'type' => $data['type'],
            'caller' => [
                'id' => $userId,
                'name' => $request->user()->name,
            ],
        ]);

        return response()->json([
            'call_id' => $callId,
            'type' => $data['type'],
            'status' => 'ringing',
            'callee' => $callee,
        ], 201);
    }

    public function respond(Request $request, string $call): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:accept,reject'],
        ]);

        $row = DB::table('calls')->where('id', $call)->first();
        abort_unless($row, 404);
        abort_unless((int) $row->callee_id === (int) $request->user()->id, 403);
        abort_unless($row->status === 'ringing', 409, 'This call is no longer ringing.');

        if ($data['action'] === 'accept') {
            DB::table('calls')->where('id', $call)->update([
                'status' => 'active',
                'answered_at' => now(),
                'updated_at' => now(),
            ]);

            $this->signal((int) $row->caller_id, 'call.accepted', [
                'call_id' => $call,
                'type' => $row->type,
            ]);

            return response()->json(['status' => 'active']);
        }

        DB::table('calls')->where('id', $call)->update([
            'status' => 'rejected',
            'ended_at' => now(),
            'updated_at' => now(),
        ]);

        $this->signal((int) $row->caller_id, 'call.rejected', [
            'call_id' => $call,
        ]);

        return response()->json(['status' => 'rejected']);
    }

    public function end(Request $request, string $call): JsonResponse
    {
        $row = DB::table('calls')->where('id', $call)->first();
        abort_unless($row, 404);

        $userId = (int) $request->user()->id;
        abort_unless(in_array($userId, [(int) $row->caller_id, (int) $row->callee_id], true), 403);

        if (! in_array($row->status, ['ended', 'rejected', 'missed'], true)) {
            DB::table('calls')->where('id', $call)->update([
                'status' => 'ended',
                'ended_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $otherId = $userId === (int) $row->caller_id ? (int) $row->callee_id : (int) $row->caller_id;

        $this->signal($otherId, 'call.ended', [
            'call_id' => $call,
        ]);

        return response()->json(['status' => 'ended']);
    }

    public function token(Request $request, string $call): JsonResponse
    {
        $row = DB::table('calls')->where('id', $call)->first();
        abort_unless($row, 404);

        $user = $request->user();
        $userId = (int) $user->id;

        abort_unless(in_array($userId, [(int) $row->caller_id, (int) $row->callee_id], true), 403);
        abort_unless(in_array($row->status, ['ringing', 'active'], true), 409, 'This call has ended.');

        $apiKey = (string) config('services.livekit.api_key');
        $apiSecret = (string) config('services.livekit.api_secret');
        $serverUrl = (string) config('services.livekit.url');

        abort_if($apiKey === '' || $apiSecret === '' || $serverUrl === '', 500, 'LiveKit is not configured.');

        $now = time();
        $payload = [
            'iss' => $apiKey,
            'sub' => 'user-'.$userId,
            'name' => (string) $user->name,
            'nbf' => $now - 5,
            'exp' => $now + 600,
            'video' => [
                'roomJoin' => true,
                'room' => $row->room_name,
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => true,
            ],
        ];

        return response()->json([
            'server_url' => $serverUrl,
            'participant_token' => $this->jwt($payload, $apiSecret),
            'call' => [
                'id' => $row->id,
                'type' => $row->type,
                'status' => $row->status,
            ],
        ]);
    }

    private function signal(int $userId, string $event, array $payload): void
    {
        Broadcast::private('App.Models.User.'.$userId)
            ->as($event)
            ->with($payload)
            ->sendNow();
    }

    private function jwt(array $payload, string $secret): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];

        $segments = [
            $this->base64Url(json_encode($header, JSON_UNESCAPED_SLASHES)),
            $this->base64Url(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = $this->base64Url($signature);

        return implode('.', $segments);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
