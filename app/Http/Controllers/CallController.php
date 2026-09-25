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

        $user = $request->user();
        $userId = (int) $user->id;

        $conversation = DB::table('ch_conversations')
            ->where('id', $data['conversation_id'])
            ->whereIn('type', ['direct', 'group'])
            ->first();

        abort_unless($conversation, 422, 'Conversation not found.');

        $participantIds = DB::table('ch_conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);

        abort_unless($participantIds->contains($userId), 403);

        $inviteeIds = $participantIds->reject(fn ($id) => $id === $userId)->values();
        abort_if($inviteeIds->isEmpty(), 422, 'There is nobody else to call.');

        $callId = (string) Str::uuid();
        $roomName = 'call_'.Str::uuid()->toString();
        $now = now();

        DB::transaction(function () use ($callId, $conversation, $userId, $inviteeIds, $roomName, $data, $now) {
            DB::table('calls')->insert([
                'id' => $callId,
                'conversation_id' => $conversation->id,
                'scope' => $conversation->type,
                'caller_id' => $userId,
                'callee_id' => $conversation->type === 'direct' ? $inviteeIds->first() : null,
                'room_name' => $roomName,
                'type' => $data['type'],
                'status' => 'ringing',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('call_participants')->insert([
                'id' => (string) Str::uuid(),
                'call_id' => $callId,
                'user_id' => $userId,
                'status' => 'joined',
                'answered_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($inviteeIds as $inviteeId) {
                DB::table('call_participants')->insert([
                    'id' => (string) Str::uuid(),
                    'call_id' => $callId,
                    'user_id' => $inviteeId,
                    'status' => 'ringing',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        $callTitle = $conversation->type === 'group'
            ? ($conversation->name ?: 'Group call')
            : (DB::table('users')->where('id', $inviteeIds->first())->value('name') ?: 'User');

        foreach ($inviteeIds as $inviteeId) {
            $this->signal($inviteeId, 'call.incoming', [
                'call_id' => $callId,
                'conversation_id' => $conversation->id,
                'scope' => $conversation->type,
                'type' => $data['type'],
                'title' => $conversation->type === 'group' ? ($conversation->name ?: 'Group call') : null,
                'caller' => [
                    'id' => $userId,
                    'name' => $user->name,
                ],
            ]);
        }

        return response()->json([
            'call_id' => $callId,
            'scope' => $conversation->type,
            'type' => $data['type'],
            'status' => 'ringing',
            'title' => $callTitle,
            'callee' => $conversation->type === 'direct'
                ? DB::table('users')->where('id', $inviteeIds->first())->first(['id', 'name'])
                : null,
        ], 201);
    }

    public function respond(Request $request, string $call): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:accept,reject']]);
        $row = DB::table('calls')->where('id', $call)->first();
        abort_unless($row, 404);

        $userId = (int) $request->user()->id;
        $participant = DB::table('call_participants')
            ->where('call_id', $call)
            ->where('user_id', $userId)
            ->first();

        abort_unless($participant, 403);

        if ($data['action'] === 'accept' && $participant->status === 'joined') {
            return response()->json(['status' => 'active']);
        }

        abort_unless($participant->status === 'ringing', 409, 'This invitation is no longer ringing.');

        if ($data['action'] === 'accept') {
            DB::transaction(function () use ($call, $participant, $row) {
                DB::table('call_participants')->where('id', $participant->id)->update([
                    'status' => 'joined',
                    'answered_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($row->status === 'ringing') {
                    DB::table('calls')->where('id', $call)->update([
                        'status' => 'active',
                        'answered_at' => $row->answered_at ?: now(),
                        'updated_at' => now(),
                    ]);
                }
            });

            $this->signalParticipants($call, 'call.participant_joined', [
                'call_id' => $call,
                'user_id' => (int) $request->user()->id,
                'name' => $request->user()->name,
            ], $userId);

            if ((int) $row->caller_id !== $userId) {
                $this->signal((int) $row->caller_id, 'call.accepted', [
                    'call_id' => $call,
                    'type' => $row->type,
                ]);
            }

            return response()->json(['status' => 'active']);
        }

        DB::table('call_participants')->where('id', $participant->id)->update([
            'status' => 'rejected',
            'left_at' => now(),
            'updated_at' => now(),
        ]);

        if ($row->scope === 'direct') {
            DB::table('calls')->where('id', $call)->update([
                'status' => 'rejected',
                'ended_at' => now(),
                'updated_at' => now(),
            ]);
            $this->signal((int) $row->caller_id, 'call.rejected', ['call_id' => $call]);
        }

        return response()->json(['status' => 'rejected']);
    }

    public function end(Request $request, string $call): JsonResponse
    {
        $row = DB::table('calls')->where('id', $call)->first();
        abort_unless($row, 404);

        $userId = (int) $request->user()->id;
        $participant = DB::table('call_participants')
            ->where('call_id', $call)
            ->where('user_id', $userId)
            ->first();
        abort_unless($participant, 403);

        DB::table('call_participants')->where('id', $participant->id)->update([
            'status' => 'left',
            'left_at' => now(),
            'updated_at' => now(),
        ]);

        $remaining = DB::table('call_participants')
            ->where('call_id', $call)
            ->where('user_id', '!=', $userId)
            ->whereIn('status', ['joined', 'ringing'])
            ->exists();

        if ($row->scope === 'direct' || ! $remaining) {
            DB::table('calls')->where('id', $call)->update([
                'status' => 'ended',
                'ended_at' => now(),
                'updated_at' => now(),
            ]);
            $this->signalParticipants($call, 'call.ended', ['call_id' => $call], $userId);
        } else {
            $this->signalParticipants($call, 'call.participant_left', [
                'call_id' => $call,
                'user_id' => $userId,
            ], $userId);
        }

        return response()->json(['status' => 'ended']);
    }

    public function token(Request $request, string $call): JsonResponse
    {
        $row = DB::table('calls')->where('id', $call)->first();
        abort_unless($row, 404);

        $user = $request->user();
        $userId = (int) $user->id;

        $participant = DB::table('call_participants')
            ->where('call_id', $call)
            ->where('user_id', $userId)
            ->first();

        abort_unless($participant, 403);
        abort_unless(in_array($participant->status, ['ringing', 'joined'], true), 409, 'You are no longer in this call.');
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
            'call' => ['id' => $row->id, 'scope' => $row->scope, 'type' => $row->type, 'status' => $row->status],
        ]);
    }

    private function signalParticipants(string $callId, string $event, array $payload, ?int $exceptUserId = null): void
    {
        $ids = DB::table('call_participants')->where('call_id', $callId)->pluck('user_id');
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($exceptUserId !== null && $id === $exceptUserId) continue;
            $this->signal($id, $event, $payload);
        }
    }

    private function signal(int $userId, string $event, array $payload): void
    {
        Broadcast::private('App.Models.User.'.$userId)->as($event)->with($payload)->sendNow();
    }

    private function jwt(array $payload, string $secret): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [
            $this->base64Url(json_encode($header, JSON_UNESCAPED_SLASHES)),
            $this->base64Url(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $segments[] = $this->base64Url(hash_hmac('sha256', implode('.', $segments), $secret, true));
        return implode('.', $segments);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
